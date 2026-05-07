<?php

use think\migration\Migrator;

class AddPasswordToSaasInstances extends Migrator
{
    /**
     * Change Method.
     *
     * Write your reversible migrations using this method.
     *
     * More information on writing migrations is available here:
     * http://docs.phinx.org/en/latest/migrations.html#the-abstractmigration-class
     *
     * The following commands can be used in this method and Phinx will
     * automatically reverse them when rolling back:
     *
     *    createTable
     *    renameTable
     *    addColumn
     *    renameColumn
     *    addIndex
     *    addForeignKey
     *
     * Remember to call "create()" or "update()" and NOT "save()" when working
     * with the Table class.
     */
    public function change()
    {
        $table = $this->table('saas_instances');
        $table->addColumn('password', 'string', ['limit' => 255, 'default' => '', 'comment' => 'Login Password Hash', 'after' => 'admin_email'])
              ->update();
    }
}
