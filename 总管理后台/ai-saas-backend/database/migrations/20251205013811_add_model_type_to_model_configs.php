<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddModelTypeToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('model_type', 'string', ['limit' => 20, 'default' => 'llm', 'comment' => '模型类型: llm, image, video, audio'])
              ->save();
    }
}
