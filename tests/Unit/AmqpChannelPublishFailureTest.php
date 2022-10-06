<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelPublishFailureTest extends BaseTest
{
    protected $master;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test_publish_failure',
            'connect_options' => ['heartbeat' => 1],
            'bindings' => [
                [
                    'queue'    => 'test_publish_failure',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
            'reconnect_attempts' => 0,
        ]);

        $this->master = AmqpFactory::create($this->properties);
    }

    public function testPublishToDisconnectedChannelWithoutRetries()
    {
        $message = new AMQPMessage('Test empty.target message');

        sleep(4);
        $this->expectException(AMQPHeartbeatMissedException::class);
        $result = $this->master->publish('empty.target', $message);

        $this->assertInstanceOf(AmqpChannel::class, $result);
    }
}
