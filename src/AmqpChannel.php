<?php
namespace ComLaude\Amqp;

use Closure;
use ComLaude\Amqp\Exceptions\AmqpChannelSilentlyRestartedException;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannel
{
    /**
     * Reference to all open channels, defaults to opening the
     * config based channel and route non-overriden requests through it
     */
    private static $channels = [];

    private $properties;
    private $tag;
    private $connection;
    private $channel;
    private $queue;
    private $lastAcknowledge;
    private $lastReject;

    /**
     * Number of times the connection will be retried
     */
    private $retry;

    /**
     * Creates a channel instance
     *
     * @param array $properties
     */
    private function __construct(array $properties = [])
    {
        $this->properties = $properties;
        $this->retry = $properties['reconnect_attempts'] ?? 3;
        $this->lastAcknowledge = [];
        $this->lastReject = [];
        $this->tag = ($this->properties['consumer_tag'] ?? 'laravel-amqp-' . config('app.name')) . uniqid();

        $this->connect();
        $this->declareExchange();
    }

    /**
     * Creates a channel instance or returns an already open channel
     *
     * @param array $properties
     * @return AmqpChannel
     */
    public static function create(array $properties = [], array $base = null)
    {
        // Merge properties with config
        if (empty($base)) {
            $base = config('amqp.properties.' . config('amqp.use'));
        }
        $final = array_merge($base, $properties);
        // Try to find a matching channel first
        if (isset(self::$channels[$final['exchange'] . '.' . $final['queue']])) {
            return self::$channels[$final['exchange'] . '.' . $final['queue']];
        }
        return self::$channels[$final['exchange'] . '.' . $final['queue']] = new AmqpChannel($final);
    }

    /**
     * Returns current connection
     *
     * @return AMQPConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Returns current channel
     *
     * @return AMQPChannel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * Returns current queue
     *
     * @return array
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Runs a closure on the channel and retries on failure
     *
     * @param Closure $callback
     */
    public function publish($route, $message)
    {
        // Before publishing the retry counter should be re-set
        $this->retry = $this->properties['reconnect_attempts'] ?? 3;

        // We will re-attempt the publish method after reconnecting if necessary, up to this->retry times
        while ($this->retry >= 0) {
            // If a connection-level issue occurs, atempt to recconnect $this->retry times
            try {
                // Fire the basic command and return the result to the caller
                $this->channel->basic_publish($message, $this->properties['exchange'], $route);
                break;
            } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
                if (--$this->retry < 0) {
                    throw $e;
                }
                $this->reconnect();
            }
        }

        return $this;
    }

    /**
     * @param Closure $callback
     * @param string $tag
     * @param boolean $addCallbacks
     * @return bool
     * @throws \Exception
     */
    public function consume(Closure $callback)
    {
        $this->declareQueue();

        if (! isset($this->properties['qos']) || $this->properties['qos']) {
            $this->channel->basic_qos(
                $this->properties['qos_prefetch_size'] ?? 0,
                $this->properties['qos_prefetch_count'] ?? 1,
                $this->properties['qos_a_global'] ?? false
            );
        }

        $channelCallback = function ($message) use ($callback) {
            if ($message->get('redelivered') === true && $this->redeliveryCheckAndSkip($message)) {
                return;
            }
            if ($message->has('reply_to') && $message->has('correlation_id')) {
                // Publish job is accepted message, to inform the requestor that it's being worked on
                $responseChannel = self::create(['exchange' => '']);
                $responseChannel->publish($message->get('reply_to'), new AMQPMessage('', [
                    'correlation_id' => $message->get('correlation_id') . '_accepted',
                ]));
                // Publish response to the original job, using return value from handler
                return $responseChannel->publish($message->get('reply_to'), new AMQPMessage($callback($message), [
                    'correlation_id' => $message->get('correlation_id') . '_handled',
                ]));
            }
            // Handle callback for the message, processing the job normally
            $callback($message);
        };

        try {
            $this->channel->basic_consume(
                $this->properties['queue'],
                $this->tag,
                $this->properties['consumer_no_local'] ?? false,
                $this->properties['consumer_no_ack'] ?? false,
                $this->properties['consumer_exclusive'] ?? false,
                $this->properties['consumer_nowait'] ?? false,
                $channelCallback,
            );

            $restart = false;
            $startTime = time();
            do {
                $this->channel->wait(null, false, $this->properties['timeout'] ?? 0);
                if ($this->properties['persistent_restart_period'] > 0
                    && $this->properties['persistent_restart_period'] < time() - $startTime
                ) {
                    $restart = true;
                    break;
                }
            } while (count($this->channel->callbacks) || $this->properties['persistent'] ?? false);
        } catch (AMQPTimeoutException $e) {
            $restart = false;
        } catch (AMQPProtocolChannelException | AmqpChannelSilentlyRestartedException $e) {
            $restart = true;
        }

        if ($restart) {
            try {
                $this->reconnect();
            } catch (AmqpChannelSilentlyRestartedException $e) {
                // This is expected but does not need special handling in this case
            }
            return $this->consume($callback);
        }

        return true;
    }

    /**
     * Publishes a message to the channel then waits for a response on a dedicated queue
     *
     * @param string $route
     * @param array $message
     * @param Closure $callback
     * @return bool
     */
    public function request($route, $messages, $callback, $properties = [])
    {
        // If this queue is already consuming something we have to reset it to remove the existing callback
        if ($this->channel->is_consuming()) {
            $this->channel->basic_cancel('request-exclusive-listener');
        }

        // Set up the queue we're going to listen to responses on
        $this->declareQueue();

        // Publish all the messages
        $requestId = $properties['correlation_id'] ?? uniqid() . '_' . count($messages);
        foreach ($messages as $index => $message) {
            // Tweak message to include reply-to to our exclusive queue
            // we only need one correlation id for this entire request,
            // together with index of each message we should be good
            self::create($properties)->declareQueue()->publish($route, new AMQPMessage($message, [
                'reply_to' => $this->queue[0],
                'correlation_id' => $requestId,
            ]));
        }

        // Expect somebody is listening response within configured timeout
        // Expect a handled job within configured timout
        $startTime = microtime(true);
        $startHandleTime = microtime(true);
        $jobAccepted = false;
        $jobsHandled = 0;

        // We check the exclusive queue for messages, either confirming or handling the job
        $this->channel->basic_consume(
            $this->queue[0],
            'request-exclusive-listener',
            false,
            true,
            false,
            false,
            function ($message) use (&$jobAccepted, &$jobsHandled, $requestId, $callback) {
                if ($message->get('correlation_id') === $requestId . '_accepted') {
                    $jobAccepted = true;
                }
                if ($message->get('correlation_id') === $requestId . '_handled') {
                    $jobsHandled++;
                    $callback($message);
                }
            },
        );

        while (
            ($this->properties['request_accepted_timeout'] > microtime(true) - $startTime || $jobAccepted)
            && $this->properties['request_handled_timeout'] > microtime(true) - $startHandleTime && $jobsHandled < count($messages)
        ) {
            usleep(10);
            $this->channel->wait(null, true, $this->properties['request_accepted_timeout']);
        }

        return $jobsHandled == count($messages);
    }

    /**
     * Checks if the message is in any of the failed acknowledgement caches
     * and acknowledges the message, then unsets it from cache
     *
     * @param AMQPMessage $message
     */
    public function redeliveryCheckAndSkip(AMQPMessage $message)
    {
        if (! empty($this->lastAcknowledge)) {
            foreach ($this->lastAcknowledge as $index => $item) {
                if ($item->body === $message->body && $item->get('routing_key') === $message->get('routing_key')) {
                    unset($this->lastAcknowledge[$index]);
                    $this->acknowledge($message);
                    return true;
                }
            }
        }
        if (! empty($this->lastReject)) {
            foreach ($this->lastReject as $index =>$item) {
                if ($item[0]->body === $message->body && $item[0]->get('routing_key') === $message->get('routing_key')) {
                    unset($this->lastReject[$index]);
                    $this->reject($message, $item[1]);
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Acknowledges the message, or pushes the failure to a cached stack
     *
     * @param AMQPMessage $message
     */
    public function acknowledge(AMQPMessage $message)
    {
        try {
            $this->channel->basic_ack($message->delivery_info['delivery_tag']);

            if ($message->body === 'quit') {
                $this->channel->basic_cancel($message->delivery_info['consumer_tag']);
            }
        } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            // We cache the acknowledge just in case it is redelivered
            $this->lastAcknowledge[] = $message;
            $this->reconnect();
        }
    }

    /**
     * Rejects the message, or pushes the failure to a cached stack
     *
     * @param AMQPMessage $message
     */
    public function reject(AMQPMessage $message, $requeue = false)
    {
        try {
            $this->channel->basic_reject($message->delivery_info['delivery_tag'], $requeue);
        } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            // che the reject just in case it is redelivered
            $this->lastReject[] = [$message, $requeue];
            $this->reconnect();
        }
    }

    public function disconnect()
    {
        if (! empty($this->properties['exchange']) && ! empty($this->properties['queue'])) {
            unset(self::$channels[$this->properties['exchange'] . '.' . $this->properties['queue']]);
        }
        try {
            if (! empty($this->channel)) {
                $this->channel->close();
            }
            if (! empty($this->connection)) {
                $this->connection->close();
            }
        } catch (AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            $this->channel = null;
            $this->connection = null;
        }
    }

    /**
     * Establishes a connection to a broker and creates a channel
     */
    public function connect()
    {
        if (! empty($this->properties['ssl_options'])) {
            $this->connection = new AMQPSSLConnection(
                $this->properties['host'],
                $this->properties['port'],
                $this->properties['username'],
                $this->properties['password'],
                $this->properties['vhost'],
                $this->properties['ssl_options'],
                $this->properties['connect_options']
            );
        } else {
            $this->connection = new AMQPStreamConnection(
                $this->properties['host'],
                $this->properties['port'],
                $this->properties['username'],
                $this->properties['password'],
                $this->properties['vhost'],
                $this->properties['connect_options']['insist'] ?? false,
                $this->properties['connect_options']['login_method'] ?? 'AMQPLAIN',
                $this->properties['connect_options']['login_response'] ?? null,
                $this->properties['connect_options']['locale'] ?? 3,
                $this->properties['connect_options']['connection_timeout'] ?? 3.0,
                $this->properties['connect_options']['read_write_timeout'] ?? 130,
                $this->properties['connect_options']['context'] ?? null,
                $this->properties['connect_options']['keepalive'] ?? false,
                $this->properties['connect_options']['heartbeat'] ?? 60,
                $this->properties['connect_options']['channel_rpc_timeout'] ?? 0.0,
                $this->properties['connect_options']['ssl_protocol'] ?? null
            );
        }
        $this->connection->set_close_on_destruct(true);
        $this->channel = $this->connection->channel();

        return $this;
    }

    /**
     * Declares an exchange on the connection
     */
    private function declareExchange()
    {
        if (! empty($this->properties['exchange'])) {
            $this->channel->exchange_declare(
                $this->properties['exchange'],
                $this->properties['exchange_type'] ?? 'topic',
                $this->properties['exchange_passive'] ?? false,
                $this->properties['exchange_durable'] ?? true,
                $this->properties['exchange_auto_delete'] ?? false,
                $this->properties['exchange_internal'] ?? false,
                $this->properties['exchange_nowait'] ?? false,
                $this->properties['exchange_properties'] ?? []
            );
        }

        return $this;
    }

    /**
     * Declares a queue on the channel and adds configured bindings
     */
    public function declareQueue()
    {
        $this->queue = $this->channel->queue_declare(
            $this->properties['queue'],
            $this->properties['queue_passive'] ?? false,
            $this->properties['queue_durable'] ?? true,
            $this->properties['queue_exclusive'] ?? false,
            $this->properties['queue_auto_delete'] ?? false,
            $this->properties['queue_nowait'] ?? false,
            $this->properties['queue_properties'] ?? [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
            ]
        );

        if (! empty($this->properties['bindings'])) {
            foreach ((array) $this->properties['bindings'] as $binding) {
                if ($binding['queue'] === $this->properties['queue']) {
                    $this->channel->queue_bind(
                        $this->properties['queue'] ?: $this->queue[0],
                        $this->properties['exchange'],
                        $binding['routing']
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Closes the connection and reestablishes a valid channel
     */
    public function reconnect()
    {
        try {
            if ($this->channel->is_consuming()) {
                $this->channel->close();
            }
            $this->disconnect();
        } catch (AMQPProtocolChannelException | AMQPRuntimeException $e) {
            // just continue with reconnect
        }

        $this->connect();
        $this->declareExchange();
        throw new AmqpChannelSilentlyRestartedException;
    }
}
