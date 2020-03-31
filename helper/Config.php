<?php
/**
 * Created by PhpStorm.
 * User: QIN
 * Date: 2020/3/28
 * Time: 22:46
 */

namespace helper;

class Config
{
    private static $config = [];


    public static function load(string $path): array
    {
        $files = [];
        self::$config = [];
        if (is_dir($path)) {
            $files = glob($path . '/*' . '.php');
        }

        foreach ($files as $file) {
            self::$config[pathinfo($file, PATHINFO_FILENAME)] = include_once $file;
        }

        return self::$config;
    }

    public static function get($name = '', $default = '')
    {
        if (empty($name)) {
            return self::$config;
        }
        if (false === strpos($name, '.')) {
            return self::$config[$name] ?? [];
        }

        $name = explode('.', $name);
        $name[0] = strtolower($name[0]);
        $config = self::$config;

        // 按.拆分成多维数组进行判断
        foreach ($name as $val) {
            if (isset($config[$val])) {
                $config = $config[$val];
            } else {
                return $default;
            }
        }

        return $config;
    }
}