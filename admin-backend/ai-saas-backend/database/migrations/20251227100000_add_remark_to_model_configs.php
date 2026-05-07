<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddRemarkToModelConfigs extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs');
        $table->addColumn('remark', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Remark/Note', 'after' => 'status'])
              ->update();
    }
}
