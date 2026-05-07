<?php

use think\migration\Migrator;

class AddLikesToInspirationLibrary extends Migrator
{
    public function change()
    {
        $table = $this->table('inspiration_library');
        $table->addColumn('likes', 'integer', ['default' => 0, 'comment' => 'Like Count'])
            ->addColumn('views', 'integer', ['default' => 0, 'comment' => 'View Count'])
            ->save();
    }
}
