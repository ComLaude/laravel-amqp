<?php

namespace ComLaude\Amqp\Tests\Unit;

use ComLaude\Amqp\Amqp;
use ComLaude\Amqp\Tests\BaseTest;
use PhpAmqpLib\Message\AMQPMessage;
use phpmock\MockBuilder;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpPersistentTest extends BaseTest
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

            'queue_auto_delete' => true,
            'exchange' => 'test',
            'consumer_tag' => 'test',
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
        if (! empty(self::$mocks)) {
            self::$mocks->disable();
            self::$mocks = null;
        }
    }

    public function testPublishPersistentAndConsume()
    {
        $messageBody = 'Test message publish persistent and consume';

        $mockedFacade = new Amqp;
        $mockedFacade->publishPersistent('example.route.facade.persistent', $messageBody);
        $mockedFacade->publishPersistent('example.route.facade.persistent', $messageBody);

        $counter = 0;

        $mockedFacade->consume(function ($message) use ($messageBody, &$counter) {
            $this->assertEquals(AMQPMessage::DELIVERY_MODE_PERSISTENT, $message->get('delivery_mode'));
            $this->assertEquals($messageBody, $message->getBody());
            $counter++;
        });

        $this->assertEquals(2, $counter);
    }
}
