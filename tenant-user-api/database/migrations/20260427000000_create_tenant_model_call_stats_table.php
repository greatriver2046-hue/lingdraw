<?php

use think\migration\Migrator;

class CreateTenantModelCallStatsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('tenant_model_call_stats', [
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'id' => false,
            'primary_key' => 'id'
        ]);

        $table->addColumn('id', 'biginteger', ['signed' => false, 'identity' => true])
              ->addColumn('tenant_id', 'biginteger', ['signed' => false, 'comment' => '租户ID'])
              ->addColumn('model_id', 'biginteger', ['signed' => false, 'comment' => '模型配置ID'])
              ->addColumn('call_count', 'biginteger', ['default' => 0, 'comment' => '累计调用次数'])
              ->addColumn('create_time', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('update_time', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
              ->addIndex(['tenant_id', 'model_id'], ['unique' => true])
              ->addIndex(['tenant_id'])
              ->create();
    }
}
