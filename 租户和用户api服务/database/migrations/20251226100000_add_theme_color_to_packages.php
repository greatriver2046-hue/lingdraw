<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddThemeColorToPackages extends Migrator
{
    public function change()
    {
        $table = $this->table('packages');
        $table->addColumn('theme_color', 'string', ['limit' => 20, 'null' => true, 'comment' => 'Theme Color (Hex)'])
              ->update();
    }
}
