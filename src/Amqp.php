<?php

namespace ComLaude\Amqp;

use Closure;
use ComLaude\Amqp\AmqpChannel;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class Amqp
{

    /**
     * Flag for globally disabling message publishing
     */
    private static $publishingEnabled = true;

    /**
     * Globally disables publishing messages
     */
    public function disable()
    {
        self::$publishingEnabled = false;
    }

    /**
     * Globally enables publishing messages
     */
    public function enable()
    {
        self::$publishingEnabled = true;
    }

    /**
     * Check if globally enabled
     */
    public function isEnabled()
    {
        return self::$publishingEnabled;
    }

    /**
     * Publishes a message to the queue
     *
     * @param string $routing
     * @param mixed $message
     * @param array $properties
     */
    public function publish($route, $message, array $properties = [])
    {
        if (! $this->isEnabled()) {
            return;
        }
        $message = new AMQPMessage($message);
        AmqpChannel::create($properties)->publish($route, $message);
    }

    /**
     * Adds a message received handler to a queue
     *
     * @param Closure $callback
     * @param array $properties
     * @throws Exception\Configuration
     */
    public function consume(Closure $callback, $properties = [])
    {
        AmqpChannel::create($properties)->consume($callback);
    }

    /**
     * Acknowledges a message
     *
     * @param AMQPMessage $message
     * @param array $properties
     */
    public function acknowledge(AMQPMessage $message, $properties = [])
    {
        AmqpChannel::create($properties)->acknowledge($message);
    }

    /**
     * Rejects a message and requeues it if wanted (default: false)
     *
     * @param AMQPMessage $message
     * @param bool $requeue
     * @param array $properties
     */
    public function reject(AMQPMessage $message, $requeue = false, $properties = [])
    {
        AmqpChannel::create($properties)->reject($message);
    }
}
