<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreatePaymentConfigsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('payment_configs', ['engine' => 'InnoDB']);
        $table->addColumn('tenant_id', 'integer', ['comment' => 'Tenant ID'])
              ->addColumn('type', 'string', ['limit' => 20, 'comment' => 'Payment Type: wechat/alipay'])
              ->addColumn('config', 'text', ['comment' => 'Encrypted Configuration JSON'])
              ->addColumn('is_enabled', 'boolean', ['default' => 0, 'comment' => 'Is Enabled'])
              ->addColumn('created_at', 'datetime', ['null' => true])
              ->addColumn('updated_at', 'datetime', ['null' => true])
              ->addIndex(['tenant_id', 'type'], ['unique' => true])
              ->create();
    }
}
