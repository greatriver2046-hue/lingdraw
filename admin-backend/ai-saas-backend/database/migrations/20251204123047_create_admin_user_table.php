<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateAdminUserTable extends Migrator
{
    public function change()
    {
        $table = $this->table('admin_users', ['engine' => 'InnoDB', 'comment' => 'Admin Users Table']);
        $table->addColumn('username', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Username'])
              ->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Password Hash'])
              ->addColumn('salt', 'string', ['limit' => 32, 'default' => '', 'comment' => 'Salt (optional)'])
              ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => 'Status: 1=Active, 0=Disabled'])
              ->addColumn('login_failure', 'integer', ['limit' => 2, 'default' => 0, 'comment' => 'Login Failures'])
              ->addColumn('last_login_time', 'integer', ['limit' => 11, 'default' => 0, 'comment' => 'Last Login Timestamp'])
              ->addColumn('last_login_ip', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Last Login IP'])
              ->addTimestamps()
              ->addIndex(['username'], ['unique' => true])
              ->create();

        // Seed initial admin user
        // Password: admin123 (hash using password_hash)
        // $hash = password_hash('admin123', PASSWORD_BCRYPT);
        // But migration class change method is for structure. Data seeding should be in seeder or separate query.
        // However, for convenience, we can insert here if needed, but cleaner to use Seeder.
        // I'll skip seeding here to keep it clean, or just execute a raw query if user requested immediate login availability.
        // The user asked to "Create complete user auth system", so I should probably provide a way to log in.
    }
}
