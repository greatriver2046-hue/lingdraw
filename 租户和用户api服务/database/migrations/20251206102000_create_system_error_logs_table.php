<?php

use think\migration\Migrator;

class CreateSystemErrorLogsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('system_error_logs');
        $table
            ->addColumn('tenant_id', 'biginteger', ['null' => true, 'comment' => 'Tenant ID'])
            ->addColumn('user_id', 'biginteger', ['null' => true, 'comment' => 'User ID'])
            ->addColumn('category', 'string', ['limit' => 50, 'comment' => 'oss|model|upload|general'])
            ->addColumn('message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('context', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('payload', 'text', ['null' => true, 'comment' => 'JSON payload'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['tenant_id'])
            ->addIndex(['user_id'])
            ->addIndex(['category'])
            ->create();
    }
}

