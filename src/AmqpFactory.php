<?php
namespace ComLaude\Amqp;

/**
 * @author David Krizanic <david.krizanic@comlaude.com>
 */
class AmqpFactory
{
    /**
     * Reference to all open channels, defaults to opening the
     * config based channel and route non-overriden requests through it
     */
    private static $channels = [];

    /**
     * Creates a channel instance or returns an already open channel
     *
     * @param array $properties
     * @return AmqpChannel
     */
    public static function create(array $properties = [], ?array $base = null): AmqpChannel
    {
        // Merge properties with config
        if (empty($base)) {
            $base = config('amqp.properties.' . config('amqp.use'));
        }
        $final = array_merge($base, $properties);
        // Try to find a matching channel first
        $cacheKey = self::cacheKey($final);
        if (isset(self::$channels[$cacheKey])) {
            return self::$channels[$cacheKey];
        }
        return self::$channels[$cacheKey] = new AmqpChannel($final);
    }

    /**
     * Creates a temporary channel instance
     *
     * @param array $properties
     * @return AmqpChannel
     */
    public static function createTemporary(array $properties = [], ?array $base = null): AmqpChannel
    {
        // Merge properties with config
        if (empty($base)) {
            $base = config('amqp.properties.' . config('amqp.use'));
        }
        return new AmqpChannel(array_merge($base, $properties));
    }

    public static function clear(array $properties): void
    {
        if (! empty($properties['exchange']) && ! empty($properties['queue'])) {
            unset(self::$channels[self::cacheKey($properties)]);
        }
    }

    public static function cacheKey(array $properties): string
    {
        return $properties['exchange']
            . '.' . $properties['queue']
            . '.' . $properties['vhost']
            . '.' . $properties['host']
            . '.' . $properties['port'];
    }
}
