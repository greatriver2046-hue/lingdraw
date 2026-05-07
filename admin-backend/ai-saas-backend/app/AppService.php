<?php
declare (strict_types = 1);

namespace app;

use think\Service;
use thans\jwt\contract\Storage;
use thans\jwt\provider\storage\Tp6;

/**
 * 应用服务类
 */
class AppService extends Service
{
    public function register()
    {
        $this->app->bind(Storage::class, function () {
            $cls = null;
            try {
                $cfg = $this->app->config->get('jwt.blacklist_storage');
                $cfg = is_string($cfg) ? trim($cfg) : '';
                if ($cfg !== '' && class_exists($cfg)) $cls = $cfg;
            } catch (\Throwable $e) {
            }

            if (!$cls || !class_exists($cls)) $cls = Tp6::class;
            return new $cls();
        });
    }

    public function boot()
    {
        // 服务启动
    }
}
