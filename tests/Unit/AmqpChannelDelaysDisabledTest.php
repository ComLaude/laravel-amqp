<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelDelaysDisabledTest extends BaseTest
{
    protected $master;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test_delay_disabled',
            'connect_options' => ['heartbeat' => 2],
            'queue_acknowledge_is_final' => false,
            'queue_reject_is_final'      => false,
            'bindings' => [
                [
                    'queue'    => 'test_delay_disabled',
                    'routing'  => 'example.route.delay_disabled',
                ],
            ],
            'timeout' => 1,
        ]);

        $this->master = AmqpFactory::create($this->properties);
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        parent::tearDown();
    }

    public function testPublishToChannelAndConsumeDelaysAcknowledgeWithoutCaching()
    {
        $this->createQueue($this->properties);
        $messages = [
            new AMQPMessage('Test message consume delayed1'),
            new AMQPMessage('Test message 2'),
        ];

        $this->master->publish('example.route.delay_disabled', $messages[0]);
        $this->master->publish('example.route.delay_disabled', $messages[1]);

        $counter = 0;
        $actuallyCompleted = 0;

        // This will hit a heartbeat missed exception but recover from it,
        // however it will not assume the message is completed and continue reprocessing it
        // until successful
        $this->master->consume(function ($consumedMessage) use ($messages, &$counter, &$actuallyCompleted) {
            $counter++;
            $this->assertEquals($consumedMessage->body, $messages[$actuallyCompleted]->body);
            if ($counter < 2) {
                sleep(6);
                $this->master->acknowledge($consumedMessage);
            }
            $actuallyCompleted++;
            $this->master->acknowledge($consumedMessage);
        });

        $this->consumeNextMessage($this->properties);

        // If we processed the first message at least twice it means the same message looped through the consumer
        $this->assertEquals(2, $actuallyCompleted);
        $this->assertEquals(3, $counter);
    }
}
