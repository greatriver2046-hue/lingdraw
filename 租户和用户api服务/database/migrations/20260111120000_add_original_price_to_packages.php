<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddOriginalPriceToPackages extends Migrator
{
    public function change()
    {
        $table = $this->table('packages');
        $table->addColumn('original_price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0, 'comment' => 'Original price for display'])
              ->save();
    }
}
