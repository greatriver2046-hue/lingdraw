<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreatePackagesTable extends Migrator
{
    public function change()
    {
        $table = $this->table('packages', ['engine' => 'InnoDB']);
        $table->addColumn('tenant_id', 'integer', ['comment' => 'Tenant ID'])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => 'Package Name'])
            ->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => 'Price'])
            ->addColumn('duration_days', 'integer', ['default' => 30, 'comment' => 'Duration in days'])
            ->addColumn('points', 'integer', ['default' => 0, 'comment' => 'Included points'])
            ->addColumn('description', 'text', ['null' => true, 'comment' => 'Description'])
            ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => 'Status: 0=Disabled, 1=Enabled'])
            ->addColumn('create_time', 'datetime', ['null' => true, 'comment' => 'Create Time'])
            ->addColumn('update_time', 'datetime', ['null' => true, 'comment' => 'Update Time'])
            ->addColumn('delete_time', 'datetime', ['null' => true, 'comment' => 'Delete Time'])
            ->addIndex('tenant_id')
            ->create();
    }
}
