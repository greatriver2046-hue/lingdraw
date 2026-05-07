<?php

use think\migration\Migrator;

class CreateUsersTable extends Migrator
{
    public function change()
    {
        if (!$this->hasTable('users')) {
            $table = $this->table('users', ['engine' => 'InnoDB', 'comment' => 'Application Users']);
            $table->addColumn('username', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Username'])
                  ->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Password Hash'])
                  ->addColumn('salt', 'string', ['limit' => 32, 'default' => '', 'comment' => 'Salt'])
                  ->addColumn('email', 'string', ['limit' => 100, 'default' => '', 'comment' => 'Email'])
                  ->addColumn('phone', 'string', ['limit' => 20, 'default' => '', 'comment' => 'Phone'])
                  ->addColumn('status', 'integer', ['limit' => 1, 'default' => 1, 'comment' => 'Status: 1=Active,0=Disabled'])
                  ->addColumn('last_login_time', 'integer', ['default' => 0, 'comment' => 'Last Login Timestamp'])
                  ->addColumn('last_login_ip', 'string', ['limit' => 50, 'default' => '', 'comment' => 'Last Login IP'])
                  ->addColumn('create_time', 'integer', ['default' => 0])
                  ->addColumn('update_time', 'integer', ['default' => 0])
                  ->addColumn('delete_time', 'integer', ['null' => true])
                  ->addIndex(['username'], ['unique' => true])
                  ->create();
        }
    }
}

