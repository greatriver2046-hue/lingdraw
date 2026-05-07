<?php

use think\migration\Migrator;
use think\migration\db\Column;

class AddImageMarkerPromptToSystemPrompts extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts');
        if (!$table->hasColumn('image_marker_prompt')) {
            $table->addColumn('image_marker_prompt', 'text', ['null' => true, 'comment' => 'Image Marker Recognition System Prompt'])
                  ->save();
        }
    }
}
