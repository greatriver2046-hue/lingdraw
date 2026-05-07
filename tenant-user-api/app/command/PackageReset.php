<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\User;
use app\model\Package;
use app\model\UserPointLog;

class PackageReset extends Command
{
    protected function configure()
    {
        // Command name and description
        $this->setName('package:reset')
            ->setDescription('Reset package points for active subscriptions');
    }

    protected function execute(Input $input, Output $output)
    {
        $output->writeln('[' . date('Y-m-d H:i:s') . '] Starting PackageReset task...');
        
        $now = time();
        $today = date('Y-m-d H:i:s', $now);
        
        // 1. Handle Expired Packages
        // Find users whose package_end_time <= now and current_package_id is not null
        $expiredUsers = User::whereNotNull('current_package_id')
            ->where('package_end_time', '<=', $today)
            ->select();
            
        foreach ($expiredUsers as $user) {
            $user->startTrans();
            try {
                $user->current_package_id = null;
                $user->package_end_time = null;
                $user->next_reset_time = null;
                
                // Clear remaining period points
                $user->period_points = 0;
                // $user->remaining_points = $user->extra_points; // Column deleted
                
                $user->save();
                
                UserPointLog::create([
                    'tenant_id' => $user->tenant_id ?? 0,
                    'user_id' => $user->id,
                    'type' => 'expire',
                    'amount' => 0,
                    'balance_after' => $user->period_points + $user->extra_points,
                    'description' => 'Package expired, period points cleared',
                ]);
                
                $user->commit();
                $output->writeln("User {$user->id}: Package expired.");
            } catch (\Exception $e) {
                $user->rollback();
                $output->writeln("User {$user->id}: Failed to expire package - " . $e->getMessage());
            }
        }
        
        // 2. Handle Cycle Resets
        // Find users whose next_reset_time <= now and package_end_time > now and current_package_id is not null
        $resetUsers = User::whereNotNull('current_package_id')
            ->where('next_reset_time', '<=', $today)
            ->where('package_end_time', '>', $today)
            ->select();
            
        foreach ($resetUsers as $user) {
            $package = Package::find($user->current_package_id);
            
            if (!$package) {
                // Package deleted? Just detach it to be safe
                $user->current_package_id = null;
                $user->save();
                continue;
            }
            
            if ($package->reset_cycle_days <= 0) {
                // Should not happen if logic is correct, but just in case clear next_reset_time
                $user->next_reset_time = null;
                $user->save();
                continue;
            }
            
            $user->startTrans();
            try {
                // Reset points to package limit
                // Logic: reset period_points to package.points. Discard old period_points.
                $oldPeriodPoints = $user->period_points;
                $user->period_points = $package->points;
                // $user->remaining_points = $user->period_points + $user->extra_points; // Column deleted
                
                // Calculate next reset time
                $nextReset = strtotime($user->next_reset_time) + ($package->reset_cycle_days * 86400);
                
                // If calculated next reset is still in past (missed runs), catch up or set to future?
                // For simplicity, just add cycle days until it's in future, or just once.
                // Better: if nextReset > package_end_time, set to null (last cycle)
                
                if ($nextReset >= strtotime($user->package_end_time)) {
                    $user->next_reset_time = null; // No more resets
                } else {
                    $user->next_reset_time = date('Y-m-d H:i:s', $nextReset);
                }
                
                $user->save();
                
                UserPointLog::create([
                    'tenant_id' => $user->tenant_id ?? 0,
                    'user_id' => $user->id,
                    'type' => 'reset',
                    'amount' => $package->points - $oldPeriodPoints, // Net change
                    'balance_after' => $user->period_points + $user->extra_points,
                    'description' => "Package cycle reset. Reset to {$package->points} points.",
                    'ref_id' => $package->id
                ]);
                
                $user->commit();
                $output->writeln("User {$user->id}: Points reset.");
            } catch (\Exception $e) {
                $user->rollback();
                $output->writeln("User {$user->id}: Failed to reset points - " . $e->getMessage());
            }
        }

        $output->writeln('[' . date('Y-m-d H:i:s') . '] PackageReset task completed.');
    }
}
