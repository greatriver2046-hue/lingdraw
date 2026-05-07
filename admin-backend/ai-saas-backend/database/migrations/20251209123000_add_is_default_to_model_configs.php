<?php

use think\migration\Migrator;

class AddIsDefaultToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        if (!$table->hasColumn('is_default')) {
            $table->addColumn('is_default', 'integer', ['default' => 0, 'comment' => '是否默认: 0/1'])
                  ->save();
        }
    }
}

