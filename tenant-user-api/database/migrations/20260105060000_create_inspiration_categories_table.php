<?php

use think\migration\Migrator;

class CreateInspirationCategoriesTable extends Migrator
{
    public function change()
    {
        // Create inspiration_categories table
        $table = $this->table('inspiration_categories');
        $table->addColumn('tenant_id', 'biginteger', ['comment' => 'Tenant ID'])
            ->addColumn('name', 'string', ['limit' => 100, 'comment' => 'Category Name'])
            ->addColumn('sort_order', 'integer', ['default' => 0, 'comment' => 'Sort Order (Higher is better)'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'])
            ->create();

        // Add category_id to inspiration_library table
        $tableLib = $this->table('inspiration_library');
        $tableLib->addColumn('category_id', 'integer', ['null' => true, 'comment' => 'Category ID'])
            ->addIndex(['category_id'])
            ->save();
    }
}
