<?php

namespace app\model;

use think\Model;
use think\facade\Log;
use think\facade\Db;

class SaasInstance extends Model
{
    // Table name
    protected $name = 'saas_instances';
    
    // Auto timestamp
    protected $autoWriteTimestamp = true;
    
    // Fields type conversion
    protected $type = [
        'expiry_date' => 'date',
    ];

    // JSON fields
    protected $json = ['home_config', 'system_config'];

    public function setGraphicCreationEnabledAttr($value)
    {
        return (int)$value ? 1 : 0;
    }

    public function getGraphicCreationEnabledAttr($value)
    {
        if ($value === null || $value === '') {
            return 1;
        }
        return (int)$value;
    }
    
    /**
     * Update tenant quota as balance.
     *
     * @param float $cost The cost to deduct
     * @return bool Success or failure
     */
    public function updateQuota($cost)
    {
        if ($cost === null) {
            return true;
        }

        $cost = (int)$cost;
        if ($cost === 0) {
            return true;
        }

        try {
            if ($cost > 0) {
                $updated = Db::table('saas_instances')
                    ->where('id', $this->id)
                    ->where('quota', '>=', $cost)
                    ->dec('quota', $cost)
                    ->update();

                if ($updated) {
                    Log::info("Updated tenant {$this->id} quota: -{$cost}");
                    return true;
                }

                return false;
            }

            $refund = (int)abs($cost);
            $updated = Db::table('saas_instances')
                ->where('id', $this->id)
                ->inc('quota', $refund)
                ->update();

            if ($updated !== false) {
                Log::info("Updated tenant {$this->id} quota: +{$refund}");
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("Failed to update tenant quota: " . $e->getMessage());
            return false;
        }
    }

    public function updateSmsQuota($count)
    {
        if ($count === null) {
            return true;
        }

        $count = (int)$count;
        if ($count === 0) {
            return true;
        }

        try {
            if ($count > 0) {
                $updated = Db::table('saas_instances')
                    ->where('id', $this->id)
                    ->where('sms_quota', '>=', $count)
                    ->dec('sms_quota', $count)
                    ->update();

                if ($updated) {
                    Log::info("Updated tenant {$this->id} sms_quota: -{$count}");
                    return true;
                }

                return false;
            }

            $refund = (int)abs($count);
            $updated = Db::table('saas_instances')
                ->where('id', $this->id)
                ->inc('sms_quota', $refund)
                ->update();

            if ($updated !== false) {
                Log::info("Updated tenant {$this->id} sms_quota: +{$refund}");
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error("Failed to update tenant sms_quota: " . $e->getMessage());
            return false;
        }
    }
}
