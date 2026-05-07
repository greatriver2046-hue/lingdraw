<?php

use think\migration\Migrator;

class CreateInspirationLibraryTable extends Migrator
{
    public function change()
    {
        $table = $this->table('inspiration_library');
        $table->addColumn('tenant_id', 'biginteger', ['comment' => 'Tenant ID'])
            ->addColumn('author_name', 'string', ['limit' => 100, 'comment' => 'Author Name'])
            ->addColumn('author_url', 'string', ['limit' => 255, 'null' => true, 'comment' => 'Author Homepage URL'])
            ->addColumn('sort_order', 'integer', ['default' => 0, 'comment' => 'Sort Order (Higher is better)'])
            ->addColumn('images', 'text', ['null' => true, 'comment' => 'Images JSON'])
            ->addColumn('description', 'text', ['null' => true, 'comment' => 'Prompt Introduction/Description'])
            ->addColumn('prompt_content', 'text', ['null' => true, 'comment' => 'Prompt Content'])
            ->addColumn('remark', 'string', ['limit' => 255, 'null' => true, 'comment' => 'Remark'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'])
            ->addIndex(['sort_order'])
            ->create();
    }
}
