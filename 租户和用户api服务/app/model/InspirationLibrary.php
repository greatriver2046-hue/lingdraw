<?php
namespace app\model;

use think\Model;

class InspirationLibrary extends Model
{
    // Auto timestamp
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // Type conversion
    protected $type = [
        'images' => 'json',
    ];

    public function category()
    {
        return $this->belongsTo(InspirationCategory::class, 'category_id', 'id');
    }
}
