<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
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

        $this->properties = array_merge($this->properties, [
            'queue' => 'test_amqp_channel_persistent_restart',
            'connect_options' => ['heartbeat' => 60],
            'bindings' => [
                [
                    'queue'    => 'test_amqp_channel_persistent_restart',
                    'routing'  => 'example.route.restart',
                ],
            ],
            'timeout' => 1,
            'persistent_restart_period' => 1,
        ]);
        $this->master = AmqpFactory::create($this->properties);
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        parent::tearDown();
    }

    public function testPublishToChannelAndConsumeGetsConnectionRestarted()
    {
        $this->createQueue($this->properties);

        $messages = [
            new AMQPMessage('Test message consume delayed1'),
            new AMQPMessage('Test message 2'),
        ];

        $this->master->publish('example.route.restart', $messages[0]);
        $this->master->publish('example.route.restart', $messages[1]);

        $counter = 0;

        // This will trigger the restart, but should process both messages just fine
        $this->master->consume(function ($consumedMessage) use ($messages, &$counter) {
            $this->assertEquals($consumedMessage->body, $messages[$counter]->body);
            sleep(3);
            $this->master->acknowledge($consumedMessage);
            $counter++;
        });

        $this->assertEquals(2, $counter);

        // After above is all done, let's do 2 more messages, each of them restarting the consumer
        $this->master->publish('example.route.restart', $messages[0]);
        $this->master->publish('example.route.restart', $messages[1]);

        $counter = 0;

        $this->master->getChannel()->wait(null, false);
        $this->master->getChannel()->wait(null, false);

        $this->assertEquals(2, $counter);
    }
}
