<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddCallCountToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('call_count', 'integer', ['default' => 0, 'comment' => '累计调用次数'])
              ->save();
    }
}
