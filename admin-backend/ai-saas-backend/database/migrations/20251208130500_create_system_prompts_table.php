<?php

use think\migration\Migrator;
use think\migration\db\Column;

class CreateSystemPromptsTable extends Migrator
{
    public function change()
    {
        $table = $this->table('system_prompts', ['engine' => 'InnoDB', 'comment' => 'System Built-in Prompts']);
        $table->addColumn('input_hint_prompt', 'text', ['null' => true])
              ->addColumn('image_builtin_prompt', 'text', ['null' => true])
              ->addColumn('llm_sidebar_system_prompt', 'text', ['null' => true])
              ->addColumn('create_time', 'integer', ['default' => 0])
              ->addColumn('update_time', 'integer', ['default' => 0])
              ->addColumn('delete_time', 'integer', ['null' => true])
              ->create();
    }
}

