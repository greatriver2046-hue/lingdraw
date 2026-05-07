<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateUserPointLogs extends Migrator
{
    public function change()
    {
        $table = $this->table('user_point_logs', ['engine' => 'InnoDB', 'comment' => '用户积分变动记录表']);
        $table->addColumn('tenant_id', 'integer', ['default' => 0, 'comment' => '租户ID'])
              ->addColumn('user_id', 'integer', ['null' => false, 'comment' => '用户ID'])
              ->addColumn('type', 'string', ['limit' => 50, 'null' => false, 'comment' => '变动类型'])
              ->addColumn('amount', 'integer', ['null' => false, 'comment' => '变动数量'])
              ->addColumn('balance_after', 'integer', ['null' => false, 'comment' => '变动后余额'])
              ->addColumn('description', 'string', ['limit' => 255, 'default' => '', 'comment' => '描述'])
              ->addColumn('ref_id', 'string', ['limit' => 100, 'default' => null, 'null' => true, 'comment' => '关联ID'])
              ->addColumn('create_time', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '创建时间'])
              ->addColumn('update_time', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'comment' => '更新时间'])
              ->addIndex(['user_id'])
              ->addIndex(['tenant_id'])
              ->addIndex(['create_time'])
              ->create();
    }
}
