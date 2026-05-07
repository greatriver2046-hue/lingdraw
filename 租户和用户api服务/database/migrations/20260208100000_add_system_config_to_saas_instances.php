<?php

use think\migration\Migrator;
use think\facade\Db;

class AddSystemConfigToSaasInstances extends Migrator
{
    public function change()
    {
        $table = $this->table('saas_instances');
        if (!$table->hasColumn('system_config')) {
            $table->addColumn('system_config', 'text', ['null' => true, 'comment' => '系统设置配置JSON'])
                  ->save();
        }

        try {
            $rows = Db::table('saas_instances')->field('id,home_config,system_config')->select();
            foreach ($rows as $row) {
                $homeConfig = $this->decodeJson($row['home_config'] ?? null);
                $systemConfig = $this->decodeJson($row['system_config'] ?? null);
                $homeChanged = false;
                $systemChanged = false;

                if (array_key_exists('login_methods', $homeConfig)) {
                    if (!array_key_exists('login_methods', $systemConfig)) {
                        $systemConfig['login_methods'] = $homeConfig['login_methods'];
                        $systemChanged = true;
                    }
                    unset($homeConfig['login_methods']);
                    $homeChanged = true;
                }

                if (array_key_exists('graphic_creation_enabled', $homeConfig)) {
                    if (!array_key_exists('graphic_creation_enabled', $systemConfig)) {
                        $systemConfig['graphic_creation_enabled'] = $homeConfig['graphic_creation_enabled'];
                        $systemChanged = true;
                    }
                    unset($homeConfig['graphic_creation_enabled']);
                    $homeChanged = true;
                }

                if ($homeChanged || $systemChanged) {
                    $payload = [];
                    if ($homeChanged) {
                        $payload['home_config'] = json_encode($homeConfig, JSON_UNESCAPED_UNICODE);
                    }
                    if ($systemChanged || $homeChanged) {
                        $payload['system_config'] = json_encode($systemConfig, JSON_UNESCAPED_UNICODE);
                    }
                    if (!empty($payload)) {
                        Db::table('saas_instances')->where('id', $row['id'])->update($payload);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return [];
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : [];
    }
}
