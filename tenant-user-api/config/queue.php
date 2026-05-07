<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: yunwuxin <448901948@qq.com>
// +----------------------------------------------------------------------

return [
    'default'     => 'redis',
    'connections' => [
        'sync'     => [
            'type' => 'sync',
        ],
        'database' => [
            'type'       => 'database',
            'queue'      => 'default',
            'table'      => 'jobs',
            'connection' => null,
        ],
        'redis'    => [
            'type'       => 'redis',
            'queue'      => 'default',
            'host'       => env('REDIS.REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS.REDIS_PORT', 6379),
            'password'   => env('REDIS.REDIS_PASSWORD', ''),
            'select'     => env('REDIS.REDIS_SELECT', 0),
            'timeout'    => env('REDIS.REDIS_TIMEOUT', 0),
            'persistent' => env('REDIS.REDIS_PERSISTENT', false),
            'retry_after' => 3600,
        ],
    ],
    'failed'      => [
        'type'  => 'none',
        'table' => 'failed_jobs',
    ],
];
