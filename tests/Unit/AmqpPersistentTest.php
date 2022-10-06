<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\Amqp;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpPersistentTest extends BaseTest
{
    protected static $usedProperties;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'connect_options' => ['heartbeat' => 2],
            'queue' => 'test_amqp_facade_persistent',
            'bindings' => [
                [
                    'queue'    => 'test_amqp_facade_persistent',
                    'routing'  => 'example.route.facade.persistent',
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

    public function testPublishPersistentAndConsume()
    {
        $this->createQueue($this->properties);
        $messageBody = 'Test message publish persistent and consume';

        $mockedFacade = new Amqp;
        $messages = 5;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->publishPersistent('example.route.facade.persistent', $messageBody);
        }

        $counter = 0;

        $mockedFacade->consume(function ($message) use ($messageBody, $mockedFacade, &$counter) {
            $this->assertEquals(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
            $this->assertEquals($messageBody, $message->getBody());
            $mockedFacade->acknowledge($message);
            $counter++;
        });

        for ($i = 0; $i < $messages - 1; $i++) {
            $this->consumeNextMessage($this->properties);
        }
        $this->assertEquals($messages, $counter);
    }

    public function testPublishPersistentWhenDisabledAndConsume()
    {
        $this->createQueue($this->properties);
        $messageBody = 'Test message publish persistent and consume';

        $mockedFacade = new Amqp;
        $mockedFacade->disable();
        $messages = 5;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->publishPersistent('example.route.facade.persistent', $messageBody);
        }
        $mockedFacade->enable();
        $mockedFacade->publishPersistent('example.route.facade.persistent', $messageBody);

        $counter = 0;

        $mockedFacade->consume(function ($message) use ($messageBody, $mockedFacade, &$counter) {
            $this->assertEquals(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
            $this->assertEquals($messageBody, $message->getBody());
            $mockedFacade->acknowledge($message);
            $counter++;
        });

        $this->consumeNextMessage($this->properties);
        $this->assertEquals(1, $counter);
    }
}
