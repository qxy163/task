<?php
/**
 * Created by PhpStorm.
 * User: QIN
 * Date: 2020/3/28
 * Time: 21:21
 */

return [
    [
        'key' => 'Hello',
        'name' => '测试',
        'rule' => '*/2 * * * * *',
        'cmd' => [\task\Hello::class, 'world'],
        'unique' => 1,
        'again' => 1,
        'log' => 1
    ],
];