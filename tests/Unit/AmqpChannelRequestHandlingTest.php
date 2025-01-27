<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpFactory;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelRequestHandlingTest extends BaseTest
{
    protected $master;
    protected $requestor;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            // Request defaults here, if they match we will be able to pre-populate the queue with test responses
            'queue' => 'test_request_handling',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test_request_handling',
                    'routing'  => 'example.route.key',
                ],
            ],
            'timeout' => 1,
        ]);

        $this->master = AmqpFactory::create($this->properties)->declareQueue();
        $this->requestor = AmqpFactory::create(array_merge($this->properties, [
            'queue' => 'test_request_handling_response',
            'bindings' => [
                [
                    'queue'    => 'test_request_handling_response',
                    'routing'  => 'not_relevant',
                ],
            ],
        ]))->declareQueue();
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        $this->requestor->getChannel()->queue_delete('test_request_handling_response');
        $this->requestor->disconnect();
        parent::tearDown();
    }

    public function testRequestFromServerSideRespondsCorrectly()
    {
        $correlationId = 'some_random_string';
        $message = new AMQPMessage('request from client', [
            'reply_to' => $this->requestor->getQueue()[0],
            'correlation_id' => $correlationId,
        ]);

        // Publish the job from requestor
        $this->requestor->publish('example.route.key', $message);

        // Handle the job from the server
        $this->master->consume(function ($consumedMessage) use ($message) {
            $this->assertEquals($message->body, $consumedMessage->body);
            $this->assertEquals($message->get('reply_to'), $consumedMessage->get('reply_to'));
            $this->assertEquals($message->get('correlation_id'), $consumedMessage->get('correlation_id'));
            $this->master->acknowledge($consumedMessage);
            return 'server responded for ' . $consumedMessage->body;
        });

        // Check the response to the requestor is as expected
        $counter = 0;
        $this->requestor->consume(function ($consumedMessage) use ($message, &$counter) {
            if ($counter++ == 0) {
                $this->assertEquals('', $consumedMessage->body);
                $this->assertEquals($message->get('correlation_id') . '_accepted', $consumedMessage->get('correlation_id'));
            } else {
                $this->assertEquals('server responded for request from client', $consumedMessage->body);
                $this->assertEquals($message->get('correlation_id') . '_handled', $consumedMessage->get('correlation_id'));
            }
            $consumedMessage->ack();
        });

        $this->assertEquals(2, $counter);
    }

    public function testRequestFromServerSideRespondsCorrectlyWhenConsumerReturnsArray()
    {
        $correlationId = 'some_random_string';
        $message = new AMQPMessage('request from client', [
            'reply_to' => $this->requestor->getQueue()[0],
            'correlation_id' => $correlationId,
        ]);

        // Publish the job from requestor
        $this->requestor->publish('example.route.key', $message);

        // Handle the job from the server
        $this->master->consume(function ($consumedMessage) use ($message) {
            $this->assertEquals($message->body, $consumedMessage->body);
            $this->assertEquals($message->get('reply_to'), $consumedMessage->get('reply_to'));
            $this->assertEquals($message->get('correlation_id'), $consumedMessage->get('correlation_id'));
            $this->master->acknowledge($consumedMessage);
            return ['data' => 'server responded for ' . $consumedMessage->body];
        });

        // Check the response to the requestor is as expected
        $counter = 0;
        $this->requestor->consume(function ($consumedMessage) use ($message, &$counter) {
            if ($counter++ == 0) {
                $this->assertEquals('', $consumedMessage->body);
                $this->assertEquals($message->get('correlation_id') . '_accepted', $consumedMessage->get('correlation_id'));
            } else {
                $this->assertEquals('{"data":"server responded for request from client"}', $consumedMessage->body);
                $this->assertEquals($message->get('correlation_id') . '_handled', $consumedMessage->get('correlation_id'));
            }
            $consumedMessage->ack();
        });

        $this->assertEquals(2, $counter);
    }
}
