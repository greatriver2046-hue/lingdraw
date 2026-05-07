<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateOrdersTable extends Migrator
{
    public function change()
    {
        $table = $this->table('orders', ['engine' => 'InnoDB']);
        $table->addColumn('order_no', 'string', ['limit' => 64, 'comment' => 'Order Number'])
              ->addColumn('tenant_id', 'integer', ['comment' => 'Tenant ID'])
              ->addColumn('user_id', 'integer', ['comment' => 'User ID'])
              ->addColumn('package_id', 'integer', ['comment' => 'Package ID'])
              ->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2, 'comment' => 'Order Amount'])
              ->addColumn('payment_method', 'string', ['limit' => 20, 'comment' => 'Payment Method: wechat/alipay'])
              ->addColumn('status', 'integer', ['default' => 0, 'comment' => 'Status: 0-Pending, 1-Paid, 2-Cancelled, 3-Refunded'])
              ->addColumn('transaction_id', 'string', ['limit' => 100, 'null' => true, 'comment' => 'Third-party Transaction ID'])
              ->addColumn('pay_time', 'datetime', ['null' => true, 'comment' => 'Payment Time'])
              ->addColumn('created_at', 'datetime', ['null' => true])
              ->addColumn('updated_at', 'datetime', ['null' => true])
              ->addIndex(['order_no'], ['unique' => true])
              ->addIndex(['tenant_id', 'user_id'])
              ->create();
    }
}
