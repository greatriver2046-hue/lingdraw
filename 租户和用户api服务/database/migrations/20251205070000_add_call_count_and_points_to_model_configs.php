<?php

use think\migration\Migrator;

class AddCallCountAndPointsToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        if (!$table->hasColumn('call_count')) {
            $table->addColumn('call_count', 'biginteger', ['default' => 0, 'comment' => 'Total Calls']);
        }
        if (!$table->hasColumn('points')) {
            $table->addColumn('points', 'integer', ['default' => 1, 'comment' => 'Points cost per call']);
        }
        $table->save();
    }
}
