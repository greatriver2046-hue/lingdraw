<?php
namespace app\model;

use think\Model;

class PaymentConfig extends Model
{
    protected $name = 'payment_configs';
    protected $autoWriteTimestamp = true;
    protected $json = ['config'];
}
