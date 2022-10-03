<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\AmqpChannel;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Channel\AMQPChannel as AMQPChannelBase;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannelDelayedTest extends BaseTest
{
    protected $master;
    protected $channel;
    protected $connection;

    public function setUp(): void
    {
        parent::setUp();

        $this->properties = array_merge($this->properties, [
            'queue' => 'test_delay',
            'connect_options' => ['heartbeat' => 2],
            'bindings' => [
                [
                    'queue'    => 'test_delay',
                    'routing'  => 'example.route.delay',
                ],
            ],
            'timeout' => 1,
        ]);

        $this->master = AmqpChannel::create($this->properties);
        $this->channel = $this->master->getChannel();
        $this->connection = $this->master->getConnection();
    }

    public function tearDown(): void
    {
        $this->deleteEverything($this->properties);
        parent::tearDown();
    }

    public function testCreateAmqpChannel()
    {
        $this->assertInstanceOf(AmqpChannel::class, $this->master);
        $this->assertInstanceOf(AMQPChannelBase::class, $this->channel);
        $this->assertInstanceOf(AMQPStreamConnection::class, $this->connection);
    }

    public function testPublishToChannelAndConsumeDelayed()
    {
        $this->createQueue($this->properties);
        $messages = [
            new AMQPMessage('Test message consume delayed1'),
            new AMQPMessage('Test message 2'),
        ];

        $this->master->publish('example.route.delay', $messages[0]);
        $this->master->publish('example.route.delay', $messages[1]);

        $counter = 0;

        // This will hit a heartbeat missed exception but recover from it
        $this->master->consume(function ($consumedMessage) use ($messages, &$counter) {
            $this->assertEquals($consumedMessage->body, $messages[$counter++]->body);
            sleep(6);
            $this->master->acknowledge($consumedMessage);
        });

        // The second consume should contain the expected second message
        $this->consumeNextMessage($this->properties);
        $this->master->getChannel()->wait(null, true);
        $this->assertEquals(2, $counter);
    }
}
