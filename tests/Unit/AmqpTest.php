<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\Amqp;
use ComLaude\Amqp\Tests\BaseTest;
use phpmock\MockBuilder;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpTest extends BaseTest
{
    protected static $mocks;
    protected static $usedProperties;

    public function setUp(): void
    {
        parent::setUp();

        $usedProperties = array_merge($this->properties, [
            'host'                  => 'localhost',
            'port'                  =>  5672,
            'username'              => 'guest',
            'password'              => 'guest',

            'exchange' => 'test',
            'consumer_tag' => 'test',
            'connect_options' => ['heartbeat' => 2],
            'queue' => 'test_amqp_facade',
            'bindings' => [
                [
                    'queue'    => 'test_amqp_facade',
                    'routing'  => 'example.route.facade',
                ],
            ],
            'timeout' => 1,
        ]);
        self::$usedProperties = $usedProperties;

        if (empty(self::$mocks)) {
            $builder = new MockBuilder();
            $builder->setNamespace('ComLaude\\Amqp')
                ->setName('config')
                ->setFunction(
                    function ($string) use ($usedProperties) {
                        if ($string === 'amqp.use') {
                            return '';
                        }
                        return $usedProperties;
                    }
                );
            self::$mocks = $builder->build();
            self::$mocks->enable();
        }
    }

    public function tearDown(): void
    {
        $this->deleteQueue(self::$usedProperties);
        if (! empty(self::$mocks)) {
            self::$mocks->disable();
            self::$mocks = null;
        }
    }

    public function testPublishAndConsume()
    {
        $messageBody = 'Test message publish and consume';

        $mockedFacade = new Amqp;
        $messages = 5;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->publish('example.route.facade', $messageBody);
        }

        $counter = 0;
        for ($i = 0; $i < $messages; $i++) {
            $mockedFacade->consume(function ($message) use ($messageBody, &$counter) {
                $this->assertEquals($messageBody, $message->getBody());
                $counter++;
            });
        }

        $this->assertEquals($messages, $counter);
    }

    public function testPublishConsumeAndAcknowledge()
    {
        self::$usedProperties = array_merge(
            self::$usedProperties,
            [
                'queue' => 'test_amqp_facade_acknowledge',
                'bindings' => [
                    [
                        'queue'    => 'test_amqp_facade_acknowledge',
                        'routing'  => 'example.route.facade_acknowledge',
                    ],
                ],
            ]
        );
        $messageBody = 'Test message publish and consume, acknowledge - first';
        $messageBody2 = 'Test message publish and consume, acknowledge - second';

        $mockedFacade = new Amqp;

        $counter = 0;

        $mockedFacade->publish('example.route.facade_acknowledge', $messageBody, self::$usedProperties);
        $mockedFacade->consume(function ($message) use ($messageBody, $messageBody2, &$counter, $mockedFacade) {
            if ($counter == 1) {
                $this->assertEquals($messageBody2, $message->getBody());
            } else {
                $this->assertEquals($messageBody, $message->getBody());
            }
            $mockedFacade->acknowledge($message, self::$usedProperties);
            $counter++;
        }, self::$usedProperties);

        $mockedFacade->publish('example.route.facade_acknowledge', $messageBody2, self::$usedProperties);
        // The original consumer is still attached so just force it to get triggered again
        $mockedFacade->consume(function () {
        }, self::$usedProperties);

        $this->assertEquals(2, $counter);
    }

    public function testPublishConsumeAndReject()
    {
        self::$usedProperties = array_merge(
            self::$usedProperties,
            [
                'queue' => 'test_amqp_facade_reject',
                'bindings' => [
                    [
                        'queue'    => 'test_amqp_facade_reject',
                        'routing'  => 'example.route.facade_reject',
                    ],
                ],
            ]
        );
        $messageBody = 'Test message publish and consume, reject - first';
        $messageBody2 = 'Test message publish and consume, reject - second';

        $mockedFacade = new Amqp;

        $counter = 0;

        $mockedFacade->publish('example.route.facade_reject', $messageBody, self::$usedProperties);
        $mockedFacade->consume(function ($message) use ($messageBody, $messageBody2, &$counter, $mockedFacade) {
            if ($counter % 2 == 1) {
                $this->assertEquals($messageBody2, $message->getBody());
            } else {
                $this->assertEquals($messageBody, $message->getBody());
            }
            $mockedFacade->reject($message, false, self::$usedProperties);
            $counter++;
        }, self::$usedProperties);

        $mockedFacade->publish('example.route.facade_reject', $messageBody2, self::$usedProperties);
        // The original consumer is still attached so just force it to get triggered again
        $mockedFacade->consume(function () {
        }, self::$usedProperties);

        $this->assertEquals(2, $counter);
    }

    public function testEnableDisablePublishing()
    {
        self::$usedProperties = array_merge(
            self::$usedProperties,
            [
                'queue' => 'test_amqp_facade_disable',
                'bindings' => [
                    [
                        'queue'    => 'test_amqp_facade_disable',
                        'routing'  => 'example.route.facade_disable',
                    ],
                ],
            ]
        );
        $messageBody = 'Test message publish, disable and consume - first';
        $messageBody2 = 'Test message publish, disable and consume - second';
        $messageBody3 = 'Test message publish, disable and consume - third';

        $mockedFacade = new Amqp;

        $counter = 0;

        $mockedFacade->publish('example.route.facade_disable', $messageBody, self::$usedProperties);
        $mockedFacade->consume(function ($message) use ($messageBody, $messageBody3, &$counter, $mockedFacade) {
            if ($counter % 2 == 1) {
                $this->assertEquals($messageBody3, $message->getBody());
            } else {
                $this->assertEquals($messageBody, $message->getBody());
            }
            $mockedFacade->reject($message, false, self::$usedProperties);
            $counter++;
        }, self::$usedProperties);

        $mockedFacade->disable();
        $mockedFacade->publish('example.route.facade_disable', $messageBody2, self::$usedProperties);
        $mockedFacade->enable();

        $mockedFacade->publish('example.route.facade_disable', $messageBody3, self::$usedProperties);
        // The original consumer is still attached so just force it to get triggered again
        $mockedFacade->consume(function () {
        }, self::$usedProperties);

        $this->assertEquals(2, $counter);
    }

    public function testRequestNotAccepted()
    {
        $mockedFacade = new Amqp;
        $startTime = microtime(true);
        $mockedFacade->request(
            'example.route',
            ['message1', 'message2'],
            fn ($message) => null
        );
        $doneTime = microtime(true) - $startTime;
        $this->assertGreaterThan(0.5, $doneTime);
        $this->assertLessThan(1, $doneTime);
    }
}
