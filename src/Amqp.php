<?php

namespace Comlaude\Amqp;

use Closure;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class Amqp
{
    /**
     * Publishes a message to the queue
     * 
     * @param string $routing
     * @param mixed $message
     * @param array $properties
     */
    public function publish($routing, $message, array $properties = [])
    {
        // TODO
    }

    /**
     * Adds a message received handler to a queue
     * 
     * @param string $queue
     * @param Closure $callback
     * @param array $properties
     * @throws Exception\Configuration
     */
    public function consume($queue, Closure $callback, $properties = [])
    {
        // TODO
    }

    /**
     * Acknowledges a message
     *
     * @param AMQPMessage $message
     */
    public function acknowledge(AMQPMessage $message)
    {
        // TODO
    }

    /**
     * Rejects a message and requeues it if wanted (default: false)
     *
     * @param AMQPMessage $message
     * @param bool $requeue
     */
    public function reject(AMQPMessage $message, $requeue = false)
    {
        // TODO
    }

    /**
     * Declares an exchange and queue
     *
     * @param string $exchange
     * @param string $queue
     * @return AMQPChannel $requeue
     */
    public function declare($exchange, $queue)
    {
        // TODO
    }
}
