<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;

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

        $this->master = AmqpChannel::create( (array) $this->properties, Array( 'queue' => 'testing' ) );
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
        $message = 'Test message';
        
        $result = $this->master->publish('empty.target', $message);

        $this->assertNull($result);
    }

    public function testPublishToChannelAndConsume()
    {
        $message = 'Test message';
        
        $this->master->publish('empty.target', $message);

        $object = $this;

        $this->master->consume(function() use ($consumedMessage, $object) {
            $object->assertEquals($consumedMessage, $message);
        });

        $this->master->acknowledge($consumedMessage);
    }
}
