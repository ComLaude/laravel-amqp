<?php
namespace ComLaude\Amqp;

use Closure;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
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
    private $connection;
    private $channel;
    private $callbacks;
    private $queue;
    private $acknowledgeFailures;
    private $rejectFailures;

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
        $this->callbacks = [];
        $this->acknowledgeFailures = [];
        $this->rejectFailures = [];
        $this->connect();
        $this->declareExchange();
        $this->declareQueue();
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
     * Compares this channel's settings with provided properties
     *
     * @param string $queue
     * @param string $exchange
     * @return bool
     */
    public function match($queue, $exchange)
    {
        return $this->properties['queue'] == $queue && $this->properties['exchange'] == $exchange;
    }

    /**
     * Runs a closure on the channel and retries on failure
     *
     * @param Closure $callback
     */
    public function publish($route, $message)
    {
        while ($this->retry >= 0) {
            // If a connection-level issue occurs, atempt to recconnect $this->retry times
            try {
                // Fire the basic command and reset the retry count if it worked
                $result = $this->channel->basic_publish($message, $this->properties['exchange'], $route);
                $this->retry = $this->properties['reconnect_attempts'] ?? 3;

                // Return the result to the caller
                return $result;
            } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
                $this->reconnect();
            }
        }
    }

    /**
     * @param Closure $callback
     * @param string $tag
     * @return bool
     * @throws \Exception
     */
    public function consume(Closure $callback, $tag = null)
    {
        $this->queue = $this->declareQueue();
        if ((! isset($this->properties['persistent']) || $this->properties['persistent'] == false) && is_array($this->queue) && $this->queue[1] == 0) {
            return true;
        }
        if (empty($tag)) {
            $tag = uniqid();
        }

        if (isset($this->properties['qos']) && $this->properties['qos'] === true) {
            $this->channel->basic_qos(
                $this->properties['qos_prefetch_size'] ?? 0,
                $this->properties['qos_prefetch_count'] ?? 1,
                $this->properties['qos_a_global'] ?? false
            );
        }

        $object = $this;
        $channelCallback = function ($message) use ($object, $callback) {
            if ($message->get("redelivered") === true && $object->redeliveryCheck($message)) {
                $object->redeliverySkip($message);
            } else {
                $callback($message);
            }
        };

        $this->channel->basic_consume(
            $this->properties['queue'],
            ($this->properties['consumer_tag'] ?? 'laravel-amqp-' . config('app.name')) . $tag,
            $this->properties['consumer_no_local'] ?? false,
            $this->properties['consumer_no_ack'] ?? false,
            $this->properties['consumer_exclusive'] ?? false,
            $this->properties['consumer_nowait'] ?? false,
            $channelCallback,
        );

        
        // Add this callback to the stack if reconnection will occur
        if ($this->properties['persistent'] ?? false) {
            $this->callbacks[] = $channelCallback;
        }
        
        do {
            try {
                $this->channel->wait(null, false, $this->properties['timeout'] ?? 0);
            } catch (AMQPTimeoutException $e) {
                return true;
            }
        } while (count($this->channel->callbacks) || $this->properties['persistent'] ?? false);

        return true;
    }

    /**
     * Checks if the message is in any of the failed acknowledgement caches
     *
     * @param AMQPMessage $message
     */
    public function redeliveryCheck(AMQPMessage $message)
    {
        if (! empty($this->acknowledgeFailures) || ! empty($this->rejectFailures)) {
            foreach ($this->acknowledgeFailures as $item) {
                if ($item->body === $message->body && $item->get('routing_key') === $message->get('routing_key')) {
                    return true;
                }
            }
            foreach ($this->rejectFailures as $item) {
                if ($item[0]->body === $message->body && $item[0]->get('routing_key') === $message->get('routing_key')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Skips a message by acknowledging it according to cached state
     *
     * @param AMQPMessage $message
     */
    public function redeliverySkip(AMQPMessage $message)
    {
        foreach ($this->acknowledgeFailures as $index => $item) {
            if ($item->body === $message->body && $item->get('routing_key') === $message->get('routing_key')) {
                unset($this->acknowledgeFailures[$index]);
                return $this->acknowledge($message);
            }
        }
        foreach ($this->rejectFailures as $index => $item) {
            if ($item[0]->body === $message->body && $item[0]->get('routing_key') === $message->get('routing_key')) {
                unset($this->rejectFailures[$index]);
                return $this->reject($message, $item[1]);
            }
        }
        return true;
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
            $this->acknowledgeFailures[] = $message;
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
            $this->rejectFailures[] = array( $message, $requeue );
            $this->reconnect();
        }
    }

    /**
     * Establishes a connection to a broker and creates a channel
     */
    private function connect()
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
    }

    private function disconnect()
    {
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
     * Declares an exchange on the connection
     */
    private function declareExchange()
    {
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

    /**
     * Declares a queue on the channel and adds configured bindings
     */
    private function declareQueue()
    {
        $this->queue = $this->channel->queue_declare(
            $this->properties['queue'],
            $this->properties['queue_passive'] ?? false,
            $this->properties['queue_durable'] ?? true,
            $this->properties['queue_exclusive'] ?? false,
            $this->properties['queue_auto_delete'] ?? false,
            $this->properties['queue_nowait'] ?? false,
            $this->properties['queue_properties'] ?? ['x-ha-policy' => ['S', 'all']]
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
    }

    /**
     * Closes the connection and reestablishes a valid channel
     * Also re-initiates any consumer callbacks
     */
    private function reconnect()
    {
        $this->retry--;
        $this->disconnect();
        $this->connect();
        $this->declareExchange();
        $this->declareQueue();

        // Re-attach the original callbacks if any were lost
        if (! empty($this->callbacks)) {
            foreach ($this->callbacks as $consumerCallback) {
                $this->channel->basic_consume(
                    $this->properties['queue'],
                    ($this->properties['consumer_tag'] ?? 'laravel-amqp-' . config('app.name')) . '-retry' . $this->retry,
                    $this->properties['consumer_no_local'] ?? false,
                    $this->properties['consumer_no_ack'] ?? false,
                    $this->properties['consumer_exclusive'] ?? false,
                    $this->properties['consumer_nowait'] ?? false,
                    $consumerCallback,
                );
            }
        }
    }
}
