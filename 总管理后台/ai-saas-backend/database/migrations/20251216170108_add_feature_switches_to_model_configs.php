<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddFeatureSwitchesToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('enable_first_frame', 'boolean', ['default' => 0, 'comment' => '是否支持首帧'])
              ->addColumn('enable_first_last_frame', 'boolean', ['default' => 0, 'comment' => '是否支持首尾帧'])
              ->addColumn('enable_multi_image_ref', 'boolean', ['default' => 0, 'comment' => '是否支持多图参考'])
              ->addColumn('enable_video_ref', 'boolean', ['default' => 0, 'comment' => '是否支持视频参考'])
              ->save();
    }
}
