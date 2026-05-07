<?php

use think\migration\Migrator;

class CreateImageAssetsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('image_assets', ['id' => 'id']);
        $table
            ->addColumn('tenant_id', 'biginteger', ['null' => true, 'comment' => 'Tenant ID'])
            ->addColumn('user_id', 'biginteger', ['null' => true, 'comment' => 'User ID'])
            ->addColumn('type', 'string', ['limit' => 50, 'comment' => 'Image type: ai_generated, user_upload'])
            ->addColumn('url', 'text', ['null' => false, 'comment' => 'Image URL'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'])
            ->addIndex(['user_id'])
            ->addIndex(['type'])
            ->create();
    }
}

