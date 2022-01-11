<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AMQPChannelTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    function setUp(): void
    {
        parent::setUp();

        if (empty($this->master)) {
            $this->master = AmqpChannel::create( array_merge( $this->properties, [

                // Travis defaults here
                'host'                  => 'localhost',
                'port'                  =>  5672,
                'username'              => 'guest',
                'password'              => 'guest',

                'queue' => 'test',
                'queue_auto_delete' => true,
                'exchange' => 'test',
                'consumer_tag' => 'test',
                'connect_options' => ['heartbeat' => 2],
                'bindings' => [
                    [
                        'queue'    => 'test',
                        'routing'  => 'example.route.key',
                    ],
                ],
                'timeout' => 1,
            ]), [ "mock-base" => true, "persistent" => false ] );
            
            $this->channel = $this->master->getChannel();
            $this->connection = $this->master->getConnection();
        }
    }

    public function testCreateAmqpChannel()
    {
        $this->assertInstanceOf(AmqpChannel::class, $this->master);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->channel);
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->connection);
    }

    public function testPublishToChannel()
    {
        $message = new AMQPMessage('Test empty.target message');
        
        $result = $this->master->publish('empty.target', $message);

        $this->assertNull($result);
    }

    public function testPublishToChannelAndConsumeThenAcknowledge()
    {
        $message = new AMQPMessage('Test message publish and consume');
        
        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        $this->master->consume(function($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            $master->acknowledge($consumedMessage);
        });
    }

    public function testPublishToChannelAndConsumeThenReject()
    {
        $message = new AMQPMessage('Test message publish and consume');
        
        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        $this->master->consume(function($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            $master->reject($consumedMessage);
        });
    }
}
