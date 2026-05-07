<?php
namespace app\model;

use think\Model;

class UserPointLog extends Model
{
    protected $name = 'user_point_logs';
    protected $autoWriteTimestamp = true;

    // Set auto update time to false if we don't need updated_at, but usually good to have
    protected $updateTime = false; 

    // schema definition helps IDE and some ORM features
    protected $schema = [
        'id'          => 'int',
        'tenant_id'   => 'int',
        'user_id'     => 'int',
        'type'        => 'string', // e.g. 'deduct', 'refund', 'recharge'
        'amount'      => 'int',    // Changed amount
        'balance_after' => 'int',  // Balance after change
        'description' => 'string',
        'ref_id'      => 'string', // Reference ID (e.g. order_id, task_id)
        'create_time' => 'datetime',
    ];
}
