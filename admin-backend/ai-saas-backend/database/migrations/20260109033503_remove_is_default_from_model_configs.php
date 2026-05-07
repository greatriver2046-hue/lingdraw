<?php

use think\migration\Migrator;
use think\migration\db\Column;

class RemoveIsDefaultFromModelConfigs extends Migrator
{
    public function change()
    {
        $this->table('model_configs')
             ->removeColumn('is_default')
             ->save();
    }
}
