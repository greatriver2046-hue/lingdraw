<?php
namespace app\model;

use think\Model;

class LlmLog extends Model
{
    protected $name = 'llm_logs';
    protected $autoWriteTimestamp = true;
}
