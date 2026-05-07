<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddTitleToInspirationLibrary extends Migrator
{
    public function change()
    {
        $table = $this->table('inspiration_library');
        $table->addColumn('title', 'string', ['limit' => 255, 'default' => '', 'comment' => '灵感标题', 'after' => 'tenant_id'])
              ->update();
    }
}
