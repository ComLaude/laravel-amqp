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
class AMQPChannelDelayedTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    function setUp(): void
    {
        parent::setUp();

        if(empty($this->master)) {
            $this->master = AmqpChannel::create( array_merge( $this->properties, [

                // Travis defaults here
                'host'                  => 'localhost',
                'port'                  =>  5672,
                'username'              => 'guest',
                'password'              => 'guest',

                'queue' => 'delaytest',
                'queue_auto_delete' => true,
                'exchange' => 'test',
                'consumer_tag' => 'test',
                'connect_options' => ['heartbeat' => 2],
                'bindings' => [
                    [
                        'queue'    => 'delaytest',
                        'routing'  => 'example.route.delay',
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

    public function testPublishToChannelAndConsumeDelayed()
    {
        $message1 = new AMQPMessage('Test message consume delayed');
        
        $this->master->publish('example.route.delay', $message1);
        
        $object = $this;
        $master = $this->master;

        // This will hit a heartbeat missed exception but recover from it
        $this->master->consume(function($consumedMessage) use ($message1, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message1->body);
            sleep(5);
            $master->acknowledge($consumedMessage);
        });

        $message2 = new AMQPMessage('Test message 2');
        $this->master->publish('example.route.delay', $message2);

        // The second consume should contain the expected second message
        $this->master->consume(function($consumedMessage) use ($message2, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message2->body);
            $master->acknowledge($consumedMessage);
        });
    }
}
