<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSaasInstancesTable extends Migrator
{
    public function change()
    {
        $table = $this->table('saas_instances', ['engine' => 'InnoDB', 'comment' => 'SaaS Instances Table']);
        $table->addColumn('name', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Instance Name'])
              ->addColumn('domain', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Domain Name'])
              ->addColumn('admin_email', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Admin Email'])
              ->addColumn('phone', 'string', ['limit' => 20, 'default' => '', 'comment' => 'Contact Phone'])
              ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => 'Status: 1=Active, 0=Suspended'])
              ->addColumn('quota', 'integer', ['default' => 0, 'comment' => 'Resource Quota'])
              ->addColumn('used', 'integer', ['default' => 0, 'comment' => 'Used Resources'])
              ->addColumn('expiry_date', 'date', ['null' => true, 'comment' => 'Expiry Date'])
              ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => 'Create Time'])
              ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => 'Update Time'])
              ->addIndex(['domain'], ['unique' => true])
              ->addIndex(['name'])
              ->create();
    }
}
