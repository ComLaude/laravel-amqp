<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelTest extends BaseTest
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

    public function testCreateAmqpChannelAcrossClusters()
    {
        $this->master = AmqpFactory::create($this->properties);

        $this->channel = $this->master->getChannel();
        $this->connection = $this->master->getConnection();

        $this->assertInstanceOf(AmqpChannel::class, $this->master);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->channel);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->master->getChannel());
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->connection);
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->master->getConnection());

        // This should error because the additional cluster does not exist, but the point is
        // that the previously opened channel is NOT re-used here, it tries to establish a
        // new connection and channel with the new cluster properties
        $this->expectException(AMQPIOException::class);
        AmqpFactory::create(array_merge($this->properties, [
            'port' => 5674,
        ]));

        // This should error because the vhost does not exist
        $this->expectException(AMQPConnectionClosedException::class);
        AmqpFactory::create(array_merge($this->properties, [
            'vhost' => '1234',
        ]));

        // This should error because the host does not exist
        $this->expectException(AMQPIOException::class);
        AmqpFactory::create(array_merge($this->properties, [
            'host' => 'invalid-path',
        ]));
    }

    public function testPublishToChannel()
    {
        $message = new AMQPMessage('Test empty.target message');

        $result = $this->master->publish('empty.target', $message);

        $this->assertInstanceOf(AmqpChannel::class, $result);
    }

    public function testPublishToDisconnectedChannel()
    {
        $message = new AMQPMessage('Test empty.target message');

        sleep(6);
        $result = $this->master->publish('empty.target', $message);

        $this->assertInstanceOf(AmqpChannel::class, $result);
    }

    public function testPublishToChannelAndConsumeThenAcknowledge()
    {
        $this->createQueue($this->properties);
        $message = new AMQPMessage('Test message publish and consume');

        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        $this->master->consume(function ($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            $master->acknowledge($consumedMessage);
        });
    }

    public function testPublishToChannelAndConsumeThenReject()
    {
        $this->createQueue($this->properties);
        $message = new AMQPMessage('Test message publish and consume');

        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        $this->master->consume(function ($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            $master->reject($consumedMessage);
        });
    }

    public function testConsumeOnEmptyQueue()
    {
        $this->createQueue($this->properties);
        $count = 0;
        $return = $this->master->consume(function ($consumedMessage) use (&$count) {
            $count++;
        });

        $this->assertTrue($return);
        $this->assertEquals(0, $count);
    }

    public function testDisconnectOnDisconnectedChannel()
    {
        $this->createQueue($this->properties);

        sleep(6);
        $this->master->disconnect();

        $this->assertNull($this->master->getChannel());
    }
}
