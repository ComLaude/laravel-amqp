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

        $this->master = AmqpChannel::create( (array) $this->properties, [
            'queue' => 'testing',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'testing',
                    'routing'  => 'example.route.key',
                ],
            ],
        ] );
        $this->channel = $this->master->getChannel();
        $this->connection = $this->master->getConnection();
    }

    public function testCreateAmqpChannel()
    {
        $this->assertInstanceOf(AmqpChannel::class, $this->master);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->channel);
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->connection);
    }

    public function testPublishToChannel()
    {
        $message = new AMQPMessage('Test message');
        
        $result = $this->master->publish('empty.target', $message);

        $this->assertNull($result);
    }

    public function testPublishToChannelAndConsume()
    {
        $message = new AMQPMessage('Test message');
        
        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        $this->master->consume(function($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            $master->acknowledge($consumedMessage);
        });
    }

    public function testPublishToChannelAndConsumeDelayed()
    {
        $message = new AMQPMessage('Test message');
        
        $this->master->publish('example.route.key', $message);

        $object = $this;
        $master = $this->master;

        // This will hit a heartbeat missed exception but recover from it
        $this->master->consume(function($consumedMessage) use ($message, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message->body);
            sleep(5);
            $master->acknowledge($consumedMessage);
        });

        $message2 = new AMQPMessage('Test message 2');
        $this->master->publish('example.route.key', $message2);

        // This should receive the previously crashed message auto-acknowledge it and proceed to the next one
        // Because we did not explicitly acknowledge the consumed message here this is a good test of the
        // automatic acknowledgement of the duplicated message we expect to receive
        $this->master->consume(function($consumedMessage){});
        // The second consume should contain the expected second message
        $this->master->consume(function($consumedMessage) use ($message2, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message2->body);
            $master->acknowledge($consumedMessage);
        });
    }
}
