<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        'llm:health' => \app\command\LlmHealthCheck::class,
        'check:error_log' => \app\command\CheckErrorLog::class,
        'clear:redis-queues' => \app\command\ClearRedisQueues::class,
        'package:reset' => \app\command\PackageReset::class,
    ],
];
