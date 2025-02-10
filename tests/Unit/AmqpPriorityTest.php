<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\Amqp;
use ComLaude\Amqp\Tests\BaseTest;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpPriorityTest extends BaseTest
{
    protected static $usedProperties;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'connect_options' => ['heartbeat' => 2],
            'queue' => 'test_amqp_facade_priority',
            'bindings' => [
                [
                    'queue'    => 'test_amqp_facade_priority',
                    'routing'  => 'example.route.facade.priority',
                ],
            ],
            'timeout' => 1,
        ]);
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        parent::tearDown();
    }

    public function testPublishPriorityAndConsume()
    {
        $this->createQueue($this->properties);
        $messageBody = 'Test message publish priority and consume';

        $mockedFacade = new Amqp;
        $messages = 5;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->publishPriority('example.route.facade.priority', $messageBody);
        }

        $counter = 0;

        $mockedFacade->consume(function ($message) use ($messageBody, $mockedFacade, &$counter) {
            $this->assertEquals(5, $message->get('priority'));
            $this->assertEquals($messageBody, $message->getBody());
            $mockedFacade->acknowledge($message);
            $counter++;
        });

        for ($i = 0; $i < $messages - 1; $i++) {
            $this->consumeNextMessage($this->properties);
        }
        $this->assertEquals($messages, $counter);
    }

    public function testPublishPriorityWhenDisabledAndConsume()
    {
        $this->createQueue($this->properties);
        $messageBody = 'Test message publish priority and consume';

        $mockedFacade = new Amqp;
        $mockedFacade->disable();
        $messages = 5;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->publishPriority('example.route.facade.priority', $messageBody);
        }
        $mockedFacade->enable();
        $mockedFacade->publishPriority('example.route.facade.priority', $messageBody);

        $counter = 0;

        $mockedFacade->consume(function ($message) use ($messageBody, $mockedFacade, &$counter) {
            $this->assertEquals(5, $message->get('priority'));
            $this->assertEquals($messageBody, $message->getBody());
            $mockedFacade->acknowledge($message);
            $counter++;
        });

        $this->consumeNextMessage($this->properties);
        $this->assertEquals(1, $counter);
    }
}
