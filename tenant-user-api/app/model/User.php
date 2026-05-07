<?php
namespace app\model;

use think\Model;
use app\model\UserPointLog;
use app\model\Package;

class User extends Model
{
    protected $name = 'users';
    protected $autoWriteTimestamp = false; 

    // Append custom attributes to JSON output
    protected $append = ['package_expire_time', 'membership_expire', 'package_id', 'package_name'];

    // Hide confidential fields
    protected $hidden = ['password', 'salt']; 

    public function package()
    {
        return $this->belongsTo(Package::class, 'current_package_id');
    }

    public function getPackageExpireTimeAttr($value, $data)
    {
        return $data['package_end_time'] ?? null;
    }

    public function getMembershipExpireAttr($value, $data)
    {
        return $data['membership_expire'] ?? ($data['package_end_time'] ?? null);
    }

    public function getPackageIdAttr($value, $data)
    {
        return $data['current_package_id'] ?? null;
    }

    public function getPackageNameAttr($value, $data)
    {
        return $this->package ? $this->package->name : null;
    }

    /**
     * Deduct points from user
     * Prioritize period_points, then extra_points
     * @param int $amount
     * @param string $type
     * @param string $description
     * @param string|null $refId
     * @return bool
     */
    public function deductPoints($amount, $type = 'deduct', $description = '', $refId = null, &$breakdown = null)
    {
        $totalAvailable = $this->period_points + $this->extra_points;
        
        // Debug logging
        if ($totalAvailable < $amount) {
             file_put_contents(
                 runtime_path() . 'point_deduction_fail.log',
                 date('Y-m-d H:i:s') . " - User ID: {$this->id} - Insufficient: Period: {$this->period_points}, Extra: {$this->extra_points}, Total: {$totalAvailable}, Need: {$amount}\n",
                 FILE_APPEND
             );
            return false;
        }
        
        $this->startTrans();
        try {
            $deductedFromPeriod = 0;
            $deductedFromExtra = 0;

            if ($this->period_points >= $amount) {
                $this->period_points -= $amount;
                $deductedFromPeriod = $amount;
            } else {
                $deductedFromPeriod = $this->period_points;
                $remainingToDeduct = $amount - $this->period_points;
                $this->period_points = 0;
                
                $this->extra_points -= $remainingToDeduct;
                $deductedFromExtra = $remainingToDeduct;
            }
            
            if ($breakdown !== null) {
                $breakdown = ['period' => $deductedFromPeriod, 'extra' => $deductedFromExtra];
            }
            
            $this->save();

            UserPointLog::create([
                'tenant_id' => $this->tenant_id ?? 0,
                'user_id' => $this->id,
                'type' => $type,
                'amount' => -$amount,
                'balance_after' => $this->period_points + $this->extra_points,
                'description' => $description . " (Period: -{$deductedFromPeriod}, Extra: -{$deductedFromExtra})",
                'ref_id' => $refId
            ]);

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            // Log the error for debugging
            file_put_contents(
                runtime_path() . 'point_deduction_error.log',
                date('Y-m-d H:i:s') . " - User ID: {$this->id} - Error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
                FILE_APPEND
            );
            return false;
        }
    }

    /**
     * Refund points to user (specifically reversing a deduction)
     * @param int|float $periodAmount Amount to return to period_points
     * @param int|float $extraAmount Amount to return to extra_points
     * @param string $type
     * @param string $description
     * @param string|null $refId
     * @return bool
     */
    public function refundPoints($periodAmount, $extraAmount, $type = 'refund', $description = '', $refId = null)
    {
        $amount = $periodAmount + $extraAmount;
        if ($amount <= 0) return true;

        $this->startTrans();
        try {
            if ($periodAmount > 0) {
                $this->period_points += $periodAmount;
            }
            if ($extraAmount > 0) {
                $this->extra_points += $extraAmount;
            }
            
            $this->save();

            UserPointLog::create([
                'tenant_id' => $this->tenant_id ?? 0,
                'user_id' => $this->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $this->period_points + $this->extra_points,
                'description' => $description . " (Refund Period: +{$periodAmount}, Extra: +{$extraAmount})",
                'ref_id' => $refId
            ]);

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            // Log error
            file_put_contents(
                runtime_path() . 'point_refund_error.log',
                date('Y-m-d H:i:s') . " - User ID: {$this->id} - Error: " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            return false;
        }
    }

    /**
     * Grant package to user
     */
    public function grantPackage($package, $orderNo)
    {
        $this->startTrans();
        try {
            // Overwrite Logic:
            $oldPeriodPoints = $this->period_points;
            
            // 1. Overwrite period points directly (User requested not to log the clearance of old points)
            $this->period_points = $package->points;
            $this->save();
            
            // 2. Log the purchase with full amount
            UserPointLog::create([
                'tenant_id' => $this->tenant_id ?? 0,
                'user_id' => $this->id,
                'type' => 'purchase',
                'amount' => $package->points,
                'balance_after' => $this->period_points + $this->extra_points,
                'description' => "Purchased package: {$package->name}",
                'ref_id' => $orderNo
            ]);
            
            // Update user package and expiration (Overwrite: Always from now)
            $now = time();
            $durationDays = $package->duration_days ?? 30; 
            $durationSeconds = $durationDays * 86400;
            $newExpire = $now + $durationSeconds;

            $this->current_package_id = $package->id;
            $this->package_end_time = date('Y-m-d H:i:s', $newExpire);
            
            $this->save();
            $this->commit();
            
            // Debug Log
            file_put_contents(runtime_path() . 'grant_package.log', date('Y-m-d H:i:s') . " - User: {$this->id} - Package: {$package->id} - Expire: {$this->package_end_time} - Overwrite\n", FILE_APPEND);
        } catch (\Exception $e) {
            $this->rollback();
            file_put_contents(runtime_path() . 'grant_package_error.log', date('Y-m-d H:i:s') . " - Error: " . $e->getMessage() . "\n", FILE_APPEND);
        }
    }

    /**
     * Change points (add or subtract)
     * For addition: Adds to extra_points by default unless specified
     * For subtraction: Uses deductPoints logic
     * @param int $amount (positive to add, negative to subtract)
     * @param string $type
     * @param string $description
     * @param string|null $refId
     * @param bool $isPeriodPoints Whether to add to period_points (only for package grants)
     * @return bool
     */
    public function changePoints($amount, $type = 'system', $description = '', $refId = null, $isPeriodPoints = false)
    {
        if ($amount < 0) {
            return $this->deductPoints(abs($amount), $type, $description, $refId);
        }

        $this->startTrans();
        try {
            if ($isPeriodPoints) {
                $this->period_points += $amount;
            } else {
                $this->extra_points += $amount;
            }
            
            $this->save();

            UserPointLog::create([
                'tenant_id' => $this->tenant_id ?? 0,
                'user_id' => $this->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $this->period_points + $this->extra_points,
                'description' => $description,
                'ref_id' => $refId
            ]);

            $this->commit();
            return true;
        } catch (\Exception $e) {
            $this->rollback();
            return false;
        }
    }
}
