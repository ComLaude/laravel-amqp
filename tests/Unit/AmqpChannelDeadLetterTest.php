<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelDeadLetterTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;
    protected $deadLetterProperties;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test_dead_letter_exchange',
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
                'x-dead-letter-exchange' => ['S', 'test-dlx'],
                'x-delivery-limit' => ['I', 2],
            ],
            'bindings' => [
                [
                    'queue'    => 'test_dead_letter_exchange',
                    'routing'  => 'example.route.dlx',
                ],
            ],
            'timeout' => 1,
        ]);

        $this->deadLetterProperties = array_merge($this->properties, [
            'queue' => 'test_dead_letter_exchange-dlx',
            'exchange' => 'test-dlx',
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'quorum'],
            ],
            'bindings' => [
                [
                    'queue'    => 'test_dead_letter_exchange',
                    'routing'  => 'example.route.dlx',
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
        $this->deleteEverything($this->deadLetterProperties);
        parent::tearDown();
    }

    public function testDeadLetterExchangeFallback()
    {
        $this->createQueue($this->properties);
        $messages = [
            new AMQPMessage('Test poison message handling'),
            new AMQPMessage('Test message 2'),
        ];

        $this->master->publish('example.route.dlx', $messages[0]);
        $this->master->publish('example.route.dlx', $messages[1]);

        $counter = 0;

        // This feature does not work for PHP7, at least not on travis CI
        if (phpversion() < '8.0.0') {
            $this->markTestSkipped('Feature not supported in PHP version ' . phpversion());
        }

        // This will see the first message delivered 3 times then the second message
        $this->master->consume(function ($consumedMessage) use ($messages, &$counter) {
            if ($counter++ > 2) {
                $this->assertEquals($consumedMessage->body, $messages[1]->body);
            } else {
                $this->assertEquals($consumedMessage->body, $messages[0]->body);
            }
            // Reject and requeue the message
            $this->master->reject($consumedMessage, true);
        });

        // Both messages were requeued 3 times and thus the queue emptied
        $this->assertEquals(6, $counter);

        $deadLetterMaster = AmqpFactory::create($this->deadLetterProperties);
        $deadLetterCounter = 0;
        $deadLetterMaster->consume(function ($consumedMessage) use ($messages, $deadLetterMaster, &$deadLetterCounter) {
            $this->assertEquals($consumedMessage->body, $messages[$deadLetterCounter++]->body);
            $deadLetterMaster->acknowledge($consumedMessage);
        });

        // Both messages were delivered to the dead letter exchange
        $this->assertEquals(2, $deadLetterCounter);
    }
}
