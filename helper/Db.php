<?php
/**
 * Created by PhpStorm.
 * User: QIN
 * Date: 2020/3/29
 * Time: 12:38
 */

namespace helper;

use Swoole\Database\PDOPool;
use Swoole\Database\PDOConfig;
class Db
{
    /**
     * @var PDOPool[]
     */
    protected static $pool;

    public static function load()
    {
        $redisConfig = Config::get('db');
        foreach ($redisConfig as $index => $item) {
            static::$pool[$index] = new PDOPool((new PDOConfig())
                ->withHost($item['host'] ?? '127.0.0.1')
                ->withPort($item['port'] ?? 3306)
                ->withDbName($item['dbname'])
                ->withCharset($item['charset'] ?? 'utf8mb4')
                ->withUsername($item['username'] ?? 'root')
                ->withPassword($item['password'] ?? 'root')
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