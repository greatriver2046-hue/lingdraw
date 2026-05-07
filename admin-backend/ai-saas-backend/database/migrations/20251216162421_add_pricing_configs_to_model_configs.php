<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddPricingConfigsToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('size_config', 'text', ['null' => true, 'comment' => '尺寸配置及价格(JSON)'])
              ->addColumn('duration_config', 'text', ['null' => true, 'comment' => '时长配置及价格(JSON)'])
              ->addColumn('quality_config', 'text', ['null' => true, 'comment' => '清晰度配置及价格(JSON)'])
              ->save();
    }
}
