<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelRequestTest extends BaseTest
{
    protected static $mocks;
    protected static $usedProperties;

    protected $master;
    protected $queue;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            // Request defaults here, if they match we will be able to pre-populate the queue with test responses
            'queue' => 'amq-gen-fixed',
            'queue_passive' => false,
            'queue_durable' => false,
            'queue_exclusive' => true,
            'queue_auto_delete' => true,
            'queue_nowait' => false,
            'queue_properties'      => [
                'x-ha-policy' => ['S', 'all'],
                'x-queue-type' => ['S', 'classic'],
            ],

            'request_accepted_timeout'  => 0.5,      // seconds
            'request_handled_timeout'   => 1,       // seconds

            'exchange' => '',
            'consumer_tag' => 'request-exclusive-listener',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test_requests',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
        ]);

        $this->master = AmqpFactory::create($this->properties)->declareQueue();
        $this->queue = $this->master->getQueue();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function testRequestOneMessage()
    {
        $expectedResponse = 'hello world, this is a RPC pattern over AMQP; test1';
        $requestId = 'my-unique-id-1';
        $this->master->publish($this->master->getQueue()[0], new AMQPMessage($expectedResponse, [
            'correlation_id' => $requestId . '_handled',
        ]));
        $responseArray = [];
        $this->master->request(
            'example.route',
            ['message1'],
            function ($message) use (&$responseArray) {
                $responseArray[] = $message->getBody();
            },
            ['correlation_id' => $requestId]
        );
        $this->assertEquals(
            [$expectedResponse],
            $responseArray
        );
    }

    public function testRequestTwoMessages()
    {
        $expectedResponse = 'hello world, this is a RPC pattern over AMQP; test2';
        $requestId = 'my-unique-id-2';
        $this->master->publish($this->master->getQueue()[0], new AMQPMessage($expectedResponse . '_1', [
            'correlation_id' => $requestId . '_handled',
        ]));
        $this->master->publish($this->master->getQueue()[0], new AMQPMessage($expectedResponse . '_2', [
            'correlation_id' => $requestId . '_handled',
        ]));
        $responseArray = [];
        $this->master->request(
            'example.route',
            ['message1', 'message2'],
            function ($message) use (&$responseArray) {
                $responseArray[] = $message->getBody();
            },
            ['correlation_id' => $requestId]
        );
        $this->assertEquals(
            [$expectedResponse . '_1', $expectedResponse . '_2'],
            $responseArray
        );
    }

    public function testRequestNotAcceptedTimeout()
    {
        $requestId = 'my-unique-id-2';
        $startTime = microtime(true);
        $this->master->request(
            'example.route',
            ['message1', 'message2'],
            function ($message) {
                return null;
            },
            ['correlation_id' => $requestId]
        );
        $this->assertLessThan(0.9, microtime(true) - $startTime);
    }

    public function testRequestNotHandledTimeout()
    {
        $expectedResponse = 'hello world, this is a RPC pattern over AMQP; test3';
        $requestId = 'my-unique-id-3';
        $this->master->publish($this->master->getQueue()[0], new AMQPMessage($expectedResponse . '_1', [
            'correlation_id' => $requestId . '_accepted',
        ]));
        $responseArray = [];
        $startTime = microtime(true);
        $this->master->request(
            'example.route',
            ['message1', 'message2'],
            function ($message) {
                return null;
            },
            ['correlation_id' => $requestId]
        );
        $doneTime = microtime(true) - $startTime;
        $this->assertGreaterThan(1, $doneTime);
        $this->assertLessThan(1.1, $doneTime);
    }
}
