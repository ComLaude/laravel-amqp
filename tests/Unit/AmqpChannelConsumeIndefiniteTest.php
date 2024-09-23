<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelConsumeIndefiniteTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
            'persistent' => true,
        ]);

        $this->master = AmqpFactory::create($this->properties);
        $this->channel = $this->master->getChannel();
        $this->connection = $this->master->getConnection();
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        parent::tearDown();
    }

    public function testCreateAmqpChannel()
    {
        $this->master = AmqpFactory::create($this->properties);

        $this->channel = $this->master->getChannel();
        $this->connection = $this->master->getConnection();

        $this->assertInstanceOf(AmqpChannel::class, $this->master);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->channel);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->master->getChannel());
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->connection);
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->master->getConnection());
    }

    public function testConsumeIndefinitelyWithIntentionalStop()
    {
        $this->createQueue($this->properties);
        $this->channel->stopConsume();
        $count = 0;
        $return = $this->master->consume(function ($consumedMessage) use (&$count) {
            $count++;
        });

        $this->assertTrue($return);
        $this->assertEquals(0, $count);
    }

}
