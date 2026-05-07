<?php

use think\migration\Migrator;

class AddResolutionConfigToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        if (!$table->hasColumn('resolution_config')) {
            $table->addColumn('resolution_config', 'text', ['null' => true, 'comment' => '分辨率配置及价格(JSON)'])
                  ->save();
        }
    }
}
