<?php
/**
 * Created by PhpStorm.
 * User: QIN
 * Date: 2020/3/28
 * Time: 23:41
 */

namespace helper;

use Swoole\Database\RedisPool;
use Swoole\Database\RedisConfig;

class Redis
{

    /**
     * @var RedisPool[]
     */
    protected static $pool;


    public static function load()
    {
        $redisConfig = Config::get('redis');
        foreach ($redisConfig as $index => $item) {
            static::$pool[$index] = new RedisPool((new RedisConfig)
                ->withHost($item['host'] ?? '127.0.0.1')
                ->withPort($item['port'] ?? 6379)
                ->withAuth($item['auth'] ?? '')
                ->withDbIndex($item['select'] ?? 0)
                ->withTimeout($item['timeout'] ?? 1)
            );
        }
    }

    public static function get($type = 'default')
    {
        return static::$pool[$type]->get();
    }

    public static function put($connection, $type = 'default')
    {
        static::$pool[$type]->put($connection);
    }
}