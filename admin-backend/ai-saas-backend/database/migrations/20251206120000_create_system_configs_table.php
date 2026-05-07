<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSystemConfigsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('system_configs', ['engine' => 'InnoDB', 'comment' => 'System Configurations']);
        $table->addColumn('category', 'string', ['limit' => 50, 'default' => ''])
              ->addColumn('config', 'text', ['null' => true])
              ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active'])
              ->addColumn('create_time', 'integer', ['default' => 0])
              ->addColumn('update_time', 'integer', ['default' => 0])
              ->addColumn('delete_time', 'integer', ['null' => true])
              ->addIndex(['category'], ['unique' => true])
              ->create();
    }
}

