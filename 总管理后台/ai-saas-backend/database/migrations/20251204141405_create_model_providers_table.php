<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateModelProvidersTable extends Migrator
{
    public function change()
    {
        $table = $this->table('model_providers', ['engine' => 'InnoDB', 'comment' => 'Model Providers']);
        $table->addColumn('name', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Provider Name'])
              ->addColumn('code', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Provider Code (unique identifier)'])
              ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active', 'comment' => 'Status: active/inactive'])
              ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => 'Create Time'])
              ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => 'Update Time'])
              ->addColumn('delete_time', 'integer', ['null' => true, 'comment' => 'Delete Time'])
              ->addIndex(['code'], ['unique' => true])
              ->create();
    }
}
