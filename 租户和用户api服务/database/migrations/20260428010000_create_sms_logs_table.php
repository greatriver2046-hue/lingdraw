<?php

use think\migration\Migrator;

class CreateSmsLogsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('sms_logs', ['engine' => 'InnoDB', 'comment' => 'SMS send logs']);
        $table
            ->addColumn('tenant_id', 'biginteger', ['null' => true, 'comment' => 'Tenant ID'])
            ->addColumn('user_id', 'biginteger', ['null' => true, 'comment' => 'User ID'])
            ->addColumn('phone', 'string', ['limit' => 20, 'comment' => 'Phone number'])
            ->addColumn('content', 'text', ['null' => true, 'comment' => 'SMS content'])
            ->addColumn('user_ip', 'string', ['limit' => 64, 'null' => true, 'comment' => 'User IP'])
            ->addColumn('type', 'string', ['limit' => 50, 'default' => 'register', 'comment' => 'register|forgot_password|phone_login|bind_phone'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'success', 'comment' => 'success|failed'])
            ->addColumn('request_payload', 'text', ['null' => true, 'comment' => 'JSON payload'])
            ->addColumn('create_time', 'integer', ['default' => 0, 'comment' => 'Create timestamp'])
            ->addColumn('update_time', 'integer', ['default' => 0, 'comment' => 'Update timestamp'])
            ->addIndex(['tenant_id'])
            ->addIndex(['user_id'])
            ->addIndex(['phone'])
            ->addIndex(['type'])
            ->addIndex(['create_time'])
            ->create();
    }
}
