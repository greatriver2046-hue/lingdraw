<?php

use think\migration\Migrator;

class CreateImageGenerationsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('image_generations', ['id' => 'id']);
        $table
            ->addColumn('tenant_id', 'biginteger', ['null' => true, 'comment' => 'Tenant ID'])
            ->addColumn('user_id', 'biginteger', ['null' => true, 'comment' => 'User ID'])
            ->addColumn('prompt', 'text', ['null' => true, 'comment' => 'Prompt text'])
            ->addColumn('model_identity', 'string', ['limit' => 100, 'null' => true, 'comment' => 'Model identity'])
            ->addColumn('model_id', 'string', ['limit' => 200, 'null' => true, 'comment' => 'Provider model id'])
            ->addColumn('width', 'integer', ['null' => true, 'comment' => 'Requested width'])
            ->addColumn('height', 'integer', ['null' => true, 'comment' => 'Requested height'])
            ->addColumn('size', 'string', ['limit' => 50, 'null' => true, 'comment' => 'Size string WxH'])
            ->addColumn('options', 'text', ['null' => true, 'comment' => 'Serialized options JSON'])
            ->addColumn('image_url', 'text', ['null' => true, 'comment' => 'First image URL'])
            ->addColumn('image_b64', 'text', ['null' => true, 'comment' => 'First image Base64'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'success', 'comment' => 'Generation status'])
            ->addColumn('error_msg', 'text', ['null' => true, 'comment' => 'Error message if failed'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['tenant_id'])
            ->addIndex(['user_id'])
            ->addIndex(['model_identity'])
            ->create();
    }
}

