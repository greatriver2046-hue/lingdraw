<?php

use think\migration\Migrator;

class AlterImageAssetsAddCategory extends Migrator
{
    public function change()
    {
        $table = $this->table('image_assets');
        $table
            ->addColumn('category', 'string', ['limit' => 50, 'default' => 'image', 'comment' => 'Asset category: image,audio,video'])
            ->addIndex(['category'])
            ->update();
    }
}

