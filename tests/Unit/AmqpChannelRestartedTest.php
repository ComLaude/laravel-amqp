<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelRestartedTest extends BaseTest
{
    protected $master;

    public function setUp(): void
    {
        parent::setUp();

        $this->master = AmqpChannel::create(array_merge($this->properties, [

            // Travis defaults here
            'host'                  => 'localhost',
            'port'                  =>  5672,
            'username'              => 'guest',
            'password'              => 'guest',

            'queue' => 'restarttest',
            'queue_auto_delete' => true,
            'exchange' => 'test',
            'consumer_tag' => 'test',
            'connect_options' => ['heartbeat' => 60],
            'bindings' => [
                [
                    'queue'    => 'restarttest',
                    'routing'  => 'example.route.restart',
                ],
            ],
            'timeout' => 1,
            'persistent_restart_period' => 1,
            'qos' => true,
            'qos_prefetch_count' => 5,
        ]), ['mock-base' => true, 'persistent' => false]);
    }

    public function testPublishToChannelAndConsumeGetsConnectionRestarted()
    {
        $message1 = new AMQPMessage('Test message consume delayed1');

        $this->master->publish('example.route.restart', $message1);

        $object = $this;
        $master = $this->master;

        // This will trigger the restart
        $this->master->consume(function ($consumedMessage) use ($message1, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message1->body);
            sleep(3);
            $master->acknowledge($consumedMessage);
        });

        $message2 = new AMQPMessage('Test message 3');
        $this->master->publish('example.route.restart', $message2);

        // The second consume should contain the expected second message
        $this->master->consume(function ($consumedMessage) use ($message2, $object, $master) {
            $object->assertEquals($consumedMessage->body, $message2->body);
            $master->acknowledge($consumedMessage);
        });
    }
}
