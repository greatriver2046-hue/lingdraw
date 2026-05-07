<?php

use think\migration\Migrator;

class AddSubscriptionFields extends Migrator
{
    public function change()
    {
        // Update packages table
        $this->table('packages')
            ->addColumn('reset_cycle_days', 'integer', ['default' => 0, 'comment' => 'Points reset cycle in days (0=no reset)'])
            ->save();

        // Update users table
        $this->table('users')
            ->addColumn('current_package_id', 'integer', ['null' => true, 'comment' => 'Current active package ID'])
            ->addColumn('package_end_time', 'datetime', ['null' => true, 'comment' => 'Package expiration time'])
            ->addColumn('next_reset_time', 'datetime', ['null' => true, 'comment' => 'Next points reset time'])
            ->addColumn('period_points', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'comment' => 'Points from package (reset periodically)'])
            ->addColumn('extra_points', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'comment' => 'Extra purchased points (never expire)'])
            ->save();
            
        // Initialize extra_points from remaining_points for existing users
        // This is a rough migration assuming all current points are 'extra' to be safe
        $this->execute('UPDATE users SET extra_points = remaining_points');
    }
}
