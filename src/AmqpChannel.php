<?php
namespace ComLaude\Amqp;

use Closure;
use ComLaude\Amqp\Exceptions\AmqpChannelSilentlyRestartedException;
use ComLaude\Api\Console\Commands\Amqp\AmqpLog;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPConnectionException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannel
{
    private $properties;
    private $tag;
    private $connection;
    private $signaller;
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
    public function __construct(array $properties = [])
    {
        $this->properties = $properties;
        $this->retry = $properties['reconnect_attempts'] ?? 3;
        $this->lastAcknowledge = [];
        $this->lastReject = [];
        $this->tag = ($this->properties['consumer_tag'] ?? 'laravel-amqp-' . config('app.name')) . uniqid();

        $this->preConnectionEstablished();
        $this->connect();
        $this->declareExchange();
        $this->postConnectionEstablished();
    }

    /**
     * Returns current connection
     *
     * @return AbstractConnection
     */
    public function getConnection(): ?\PhpAmqpLib\Connection\AbstractConnection
    {
        return $this->connection;
    }

    /**
     * Returns current channel
     *
     * @return AMQPChannel
     */
    public function getChannel(): ?\PhpAmqpLib\Channel\AMQPChannel
    {
        return $this->channel;
    }

    /**
     * Returns current queue
     *
     * @return array
     */
    public function getQueue(): ?array
    {
        return $this->queue;
    }

    /**
     * Runs a closure on the channel and retries on failure
     *
     * @param string $route
     * @param AMQPMessage $message
     */
    public function publish(string $route, AMQPMessage $message): AmqpChannel
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
                $this->reconnect(true);
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
    public function consume(Closure $callback): bool
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
            $redeliveryCheck = $this->redeliveryCheckAndSkip($message);
            AmqpLog::info('Redelivery vars: ' . json_encode([$message->get('redelivered'), $redeliveryCheck]));
            if ($message->get('redelivered') === true && $redeliveryCheck) {
                AmqpLog::info('redelivered, nothing to process');
                return;
            }
            if ($message->has('reply_to') && $message->has('correlation_id')) {
                // Publish job is accepted message, to inform the requestor that it's being worked on
                $responseChannel = AmqpFactory::create(['exchange' => '']);
                $responseChannel->publish($message->get('reply_to'), new AMQPMessage('', [
                    'correlation_id' => $message->get('correlation_id') . '_accepted',
                ]));
                // Publish response to the original job, using return value from handler
                $callbackResult = $callback($message);
                if (! is_string($callbackResult)) {
                    $callbackResult = json_encode($callbackResult);
                }
                return $responseChannel->publish($message->get('reply_to'), new AMQPMessage($callbackResult, [
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

            // Consume queue indefinitely
            if ($this->properties['persistent'] ?? false) {
                $this->channel->consume($this->properties['timeout'] ?? 5);
            }

            // Consume queue until empty, then stop
            $restart = false;
            $startTime = time();
            do {
                $this->channel->wait(null, false, $this->properties['timeout'] ?? 5);
                if ($this->properties['persistent_restart_period'] > 0
                    && $this->properties['persistent_restart_period'] < time() - $startTime
                ) {
                    $restart = true;
                    break;
                }
            } while (count($this->channel->callbacks));
        } catch (AMQPTimeoutException $e) {
            $restart = false;
        } catch (AMQPProtocolChannelException | AmqpChannelSilentlyRestartedException $e) {
            $restart = true;
        }

        if ($restart) {
            $this->reconnect(true);
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
    public function request(string $route, array $messages, Closure $callback, array $properties = []): bool
    {
        // Set up the queue we're going to listen to responses on
        $this->declareQueue();

        // Publish all the messages
        $requestId = $properties['correlation_id'] ?? uniqid() . '_' . count($messages);
        $requestSender = AmqpFactory::createTemporary($properties);
        foreach ($messages as $index => $message) {
            // Tweak message to include reply-to to our exclusive queue
            // we only need one correlation id for this entire request,
            // together with index of each message we should be good
            $requestSender->publish($route, new AMQPMessage($message, [
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

        $this->channel->basic_cancel('request-exclusive-listener');
        $this->channel->queue_delete($this->queue[0]);

        return $jobsHandled == count($messages);
    }

    /**
     * Checks if the message is in any of the failed acknowledgement caches
     * and acknowledges the message, then unsets it from cache
     *
     * @param AMQPMessage $message
     */
    public function redeliveryCheckAndSkip(AMQPMessage $message): bool
    {
        if (! empty($this->lastAcknowledge)) {
            foreach ($this->lastAcknowledge as $index => $item) {
                AmqpLog::info('Redelivery Loop started');
                AmqpLog::info(json_encode([$item->body, $message->body]));
                AmqpLog::info(json_encode([$item->body === $message->body, $item->get('routing_key') === $message->get('routing_key')]));
                if ($item->body === $message->body && $item->get('routing_key') === $message->get('routing_key')) {
                    unset($this->lastAcknowledge[$index]);
                    AmqpLog::info('acknowledged in redelivery check');
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
    public function acknowledge(AMQPMessage $message): void
    {
        try {
            AmqpLog::info('In Acknowledging try');
            $message->getChannel()->basic_ack($message->get('delivery_tag'));
            AmqpLog::info('In Acknowledging try finished');
            if ($message->body === 'quit') {
                $message->getChannel()->basic_cancel($this->tag);
            }
        } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            AmqpLog::info(get_class($e));
            AmqpLog::info('properties: ' . ($this->properties['queue_acknowledge_is_final'] ?? true));
            if ($this->properties['queue_acknowledge_is_final'] ?? true) {
                // We cache the acknowledge just in case it is redelivered
                AmqpLog::info('added to ack cache');
                $this->lastAcknowledge[] = $message;
                AmqpLog::info('Ack array now: ' . json_encode($this->lastAcknowledge));
                AmqpLog::info('Ack array count: ' . count($this->lastAcknowledge));
            }
            AmqpLog::info('reconnecting');
            $this->reconnect();
        }
    }

    /**
     * Rejects the message, or pushes the failure to a cached stack
     *
     * @param AMQPMessage $message
     */
    public function reject(AMQPMessage $message, bool $requeue = false): void
    {
        try {
            $message->getChannel()->basic_reject($message->get('delivery_tag'), $requeue);
        } catch (AMQPConnectionException | AMQPHeartbeatMissedException | AMQPChannelClosedException | AMQPConnectionClosedException $e) {
            if ($this->properties['queue_reject_is_final'] ?? true) {
                // We cache the reject just in case it is redelivered
                $this->lastReject[] = [$message, $requeue];
            }
            $this->reconnect();
        }
    }

    public function disconnect(): void
    {
        AmqpFactory::clear($this->properties);
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
    public function connect(): AmqpChannel
    {
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

        $this->channel = $this->connection->channel();

        return $this;
    }

    /**
     * Processes connection configuration before a connection has opened fully
     */
    protected function preConnectionEstablished(): void
    {
        if ($this->signaller) {
            $this->signaller->unregister();
        }
    }

    /**
     * Processes connection configuration after a connection has opened fully
     */
    protected function postConnectionEstablished(): void
    {
        $this->connection->set_close_on_destruct(true);
        if ($this->properties['register_pcntl_heartbeat_sender'] ?? false) {
            $this->signaller = new PCNTLHeartbeatSender($this->connection);
            $this->signaller->register();
        }
    }

    /**
     * Declares an exchange on the connection
     */
    protected function declareExchange(): AmqpChannel
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

        if (! empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
            $this->channel->exchange_declare(
                $this->properties['queue_properties']['x-dead-letter-exchange'][1],
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
    public function declareQueue(): AmqpChannel
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

        $queueName = $this->properties['queue'] ?: $this->queue[0];
        $queueNameDeadLetterExchange = $queueName . '-dlx';

        if (! empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
            $this->queue = $this->channel->queue_declare(
                $queueNameDeadLetterExchange,
                $this->properties['queue_passive'] ?? false,
                $this->properties['queue_durable'] ?? true,
                $this->properties['queue_exclusive'] ?? false,
                $this->properties['queue_auto_delete'] ?? false,
                $this->properties['queue_nowait'] ?? false,
                array_diff_key($this->properties['queue_properties'] ?? [
                    'x-ha-policy' => ['S', 'all'],
                    'x-queue-type' => ['S', 'quorum'],
                ], [
                    'x-dead-letter-exchange' => 'not_set_on_dlx_queues',
                    'x-delivery-limit' => 'not_set_on_dlx_queues',
                ])
            );
        }

        if (! empty($this->properties['bindings'])) {
            foreach ((array) $this->properties['bindings'] as $binding) {
                if ($binding['queue'] === $this->properties['queue']) {
                    $this->channel->queue_bind(
                        $queueName,
                        $this->properties['exchange'],
                        $binding['routing']
                    );

                    if (! empty($this->properties['queue_properties']['x-dead-letter-exchange'][1])) {
                        $this->channel->queue_bind(
                            $queueNameDeadLetterExchange,
                            $this->properties['queue_properties']['x-dead-letter-exchange'][1],
                            $binding['routing']
                        );
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Closes the connection and reestablishes a valid channel
     * @param boolean $intentionalReconnection
     */
    public function reconnect(bool $intentionalReconnection = false): void
    {
        try {
            if ($this->channel->is_consuming()) {
                $this->channel->close();
            }
            $this->disconnect();
        } catch (AMQPProtocolChannelException | AMQPRuntimeException $e) {
            // just continue with reconnect
        }

        $this->preConnectionEstablished();
        $this->connect();
        $this->declareExchange();
        $this->postConnectionEstablished();
        if (! $intentionalReconnection) {
            throw new AmqpChannelSilentlyRestartedException;
        }
    }
}
