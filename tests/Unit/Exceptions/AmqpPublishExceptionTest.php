<?php
namespace ComLaude\Amqp\Tests\Unit\Exceptions;

use ComLaude\Amqp\Exceptions\AmqpPublishException;
use PHPUnit\Framework\TestCase;

class AmqpPublishExceptionTest extends TestCase
{
    public function testSetsAndGets()
    {
        $exception = new AmqpPublishException('Test exception');
        $exception->setRoutingKey('test.route');
        $exception->setPayload('Test payload');

        $this->assertEquals('test.route', $exception->getRoutingKey());
        $this->assertEquals('Test payload', $exception->getPayload());
    }
}
