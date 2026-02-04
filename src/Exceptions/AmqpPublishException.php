<?php

namespace ComLaude\Amqp\Exceptions;

use Exception;

class AmqpPublishException extends Exception
{
    protected ?string $routingKey = null;
    protected ?string $payload = null;

    public function getRoutingKey(): ?string
    {
        return $this->routingKey;
    }

    public function setRoutingKey(string $routingKey): void
    {
        $this->routingKey = $routingKey;
    }

    public function getPayload(): ?string
    {
        return $this->payload;
    }

    public function setPayload(string $payload): void
    {
        $this->payload = $payload;
    }
}
