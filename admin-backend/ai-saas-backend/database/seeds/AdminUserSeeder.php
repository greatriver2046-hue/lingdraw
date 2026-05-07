<?php

use think\migration\Seeder;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'username'    => 'admin',
                'password'    => password_hash('admin', PASSWORD_BCRYPT),
                'status'      => 1,
                'create_time' => date('Y-m-d H:i:s'),
                'update_time' => date('Y-m-d H:i:s'),
            ]
        ];

        // Use replace strategy or truncate if needed, but for seeder usually we insert
        // If ID or Unique key conflict, this might fail or ignore depending on adapter
        // For simplicity, let's assume we want this data. 
        // But since we already ran it, we might need to clear table or update.
        // Phinx/Think-migration insert doesn't do ON DUPLICATE KEY UPDATE by default.
        
        // Let's try to find if exists using model logic in seeder? No, Seeder uses adapter.
        // We will stick to updating the file for reference.
        $this->table('admin_users')->insert($data)->save();
    }
}
