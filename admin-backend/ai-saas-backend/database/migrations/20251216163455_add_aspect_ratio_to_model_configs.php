<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddAspectRatioToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('aspect_ratio_config', 'text', ['null' => true, 'comment' => '视频比例配置及价格(JSON)'])
              ->save();
    }
}
