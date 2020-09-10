<?php
namespace Comlaude\Amqp;

use Closure;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;

/**
 * @author David krizanic <david.krizanic@comlaude.com>
 */
class AmqpChannel
{

    /**
     * Reference to all open channels, defaults to opening the
     * config based channel and route non-overriden requests through it
     */
    private static $channels = [];

    private $properties;
    private $connection;
    private $channel;
    private $queue;

    /**
     * Number of times the connection will be retried
     */
    private static $retry = 1;

    /**
     * Creates a channel instance
     * 
     * @param array $properties
     */
    private function __construct(array $properties = []) {
        // TODO
        $this->properties = $properties;
        $this->connect();
        $this->declareExchange();
        $this->declareQueue();
    }

    /**
     * Creates a channel instance or returns an already open channel
     * 
     * @param array $properties
     * @return AmqpChannel
     */
    public static function create(array $properties = []) {
      // Merge properties with config
      $config = config('amqp.properties.' . config('amqp.use'));
      $final = array_merge($config, $properties);
      // Try to find a matching channel first
      if(isset(self::$channels[$final['exchange'].'.'.$final['queue']])) {
        return self::$channels[$final['exchange'].'.'.$final['queue']];
      }
      return self::$channels[$final['exchange'].'.'.$final['queue']] = new AmqpChannel($final);
    }

    /**
     * Establishes a connection to a broker and creates a channel
     */
    private function connect(){
      
      if (!empty($this->properties['ssl_options'])) {
          $this->connection = new AMQPSSLConnection(
              $this->properties['host'],
              $this->properties['port'],
              $this->properties['username'],
              $this->properties['password'],
              $this->properties['vhost'],
              $this->properties['ssl_options'],
              $this->properties['connect_options']
          );
      } else {
          $this->connection = new AMQPStreamConnection(
              $this->properties['host'],
              $this->properties['port'],
              $this->properties['username'],
              $this->properties['password'],
              $this->properties['vhost'],
              $this->properties['connect_options']['insist'] ?? false,
              $this->properties['connect_options']['login_method'] ?? 'AMQPLAIN',
              $this->properties['connect_options']['login_response'] ?? null,
              $this->properties['connect_options']['locale'] ?? 3,
              $this->properties['connect_options']['connection_timeout'] ?? 3.0,
              $this->properties['connect_options']['read_write_timeout'] ?? 130,
              $this->properties['connect_options']['context'] ?? null,
              $this->properties['connect_options']['keepalive'] ?? false,
              $this->properties['connect_options']['heartbeat'] ?? 60,
              $this->properties['connect_options']['channel_rpc_timeout'] ?? 0.0,
              $this->properties['connect_options']['ssl_protocol'] ?? null
          );
      }
      $this->connection->set_close_on_destruct(true);
      $this->channel = $this->connection->channel();
    }

    /**
     * Declares an exchange on the connection
     */
    private function declareExchange() {      
      $this->channel->exchange_declare(
          $this->properties['exchange'],
          $this->properties['exchange_type'],
          $this->properties['exchange_passive'],
          $this->properties['exchange_durable'],
          $this->properties['exchange_auto_delete'],
          $this->properties['exchange_internal'],
          $this->properties['exchange_nowait'],
          $this->properties['exchange_properties']
      );
    }

    /**
     * Declares a queue on the channel and adds configured bindings
     */
    private function declareQueue() {      
      $this->queue = $this->channel->queue_declare(
          $this->properties['queue'],
          $this->properties['queue_passive'],
          $this->properties['queue_durable'],
          $this->properties['queue_exclusive'],
          $this->properties['queue_auto_delete'],
          $this->properties['queue_nowait'],
          $this->properties['queue_properties']
      );
      
      foreach ((array) $this->properties['bindings'] as $routingKey) {
        $this->channel->queue_bind(
            $this->properties['queue'] ?: $this->queueInfo[0],
            $this->properties['exchange'],
            $routingKey
        );
    }
    }
    
    /**
     * Compares this channel's settings with provided properties
     * 
     * @param string $queue
     * @param string $exchange
     * @return bool
     */
    public function match($queue, $exchange) {
      return $this->properties['queue'] == $queue && $this->properties['exchange'] == $exchange;
    }

    /**
     * @param Closure $closure
     * @return bool
     * @throws \Exception
     */
    public function consume(Closure $closure)
    {
        if (!$this->properties['persistent'] && is_array($this->queue) && $this->queue[1] == 0) {
          return true;
        }

        if ($this->properties['qos']) {
            $this->channel->basic_qos(
                $this->properties['qos_prefetch_size'],
                $this->properties['qos_prefetch_count'],
                $this->properties['qos_a_global']
            );
        }

        $this->channel->basic_consume(
            $this->properties['queue'],
            $this->properties['consumer_tag'],
            $this->properties['consumer_no_local'],
            $this->properties['consumer_no_ack'],
            $this->properties['consumer_exclusive'],
            $this->properties['consumer_nowait'],
            $closure,
        );

        // consume
        while (count($this->channel->callbacks)) {
            $this->channel->wait(null, false, $this->properties['timeout'] ?? 0 );
        }

        return true;
    }

    /**
     * Runs a closure on the channel and retries on failure
     * 
     * @param Closure $callback
     */
    public function run(Closure $callback) {
        $retryCount = $this->retry;
        while($retryCount-- > 0) {
          try {
            return $callback($this->channel, $this->properties['exchange']);
          } catch(AMQPTimeoutException $e) {
            self::$channels[$this->properties['exchange'] . '.' . $this->properties['queue']] = self::create($this->properties);
          } catch(AMQPConnectionException $e) {
            self::$channels[$this->properties['exchange'] . '.' . $this->properties['queue']] = self::create($this->properties);
          } catch(AMQPHeartbeatMissedException $e) {
            self::$channels[$this->properties['exchange'] . '.' . $this->properties['queue']] = self::create($this->properties);
          } catch(AMQPChannelException $e) {
            self::$channels[$this->properties['exchange'] . '.' . $this->properties['queue']] = self::create($this->properties);
          }
        }
        throw \Exception("Interaction with AMQP channel failed after $this->retry reconnection attempts.", 500);
    }
}