<?php

use think\migration\Seeder;

class SaasInstanceSeeder extends Seeder
{
    public function run(): void
    {
        $password = '123456';
        $salt = md5(uniqid(microtime(true), true));
        $password_hash = password_hash($password . $salt, PASSWORD_BCRYPT);

        $data = [
            [
                'username'    => 'admin',
                'password_hash' => $password_hash,
                'salt' => $salt,
                'name' => 'Default Tenant',
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        ];

        $this->table('saas_instances')->insert($data)->save();
    }
}
