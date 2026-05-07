<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddCostPerRequestToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('cost_per_request', 'integer', ['default' => 1, 'comment' => '单次请求消耗点数'])
              ->save();
    }
}
