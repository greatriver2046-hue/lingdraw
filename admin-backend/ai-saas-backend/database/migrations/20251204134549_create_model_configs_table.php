<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateModelConfigsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('model_configs', ['engine' => 'InnoDB', 'comment' => 'Model Configurations']);
        $table->addColumn('name', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Display Name'])
              ->addColumn('model_id', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Model Identifier'])
              ->addColumn('provider', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Provider Name'])
              ->addColumn('api_key', 'string', ['limit' => 255, 'default' => '', 'comment' => 'API Key'])
              ->addColumn('endpoint', 'string', ['limit' => 255, 'default' => '', 'comment' => 'API Endpoint'])
              ->addColumn('status', 'string', ['limit' => 20, 'default' => 'active', 'comment' => 'Status: active/inactive'])
              ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => 'Create Time'])
              ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => 'Update Time'])
              ->addColumn('delete_time', 'integer', ['null' => true, 'comment' => 'Delete Time'])
              ->addIndex(['model_id'])
              ->create();
    }
}
