<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

class CheckSchema extends Command
{
    protected function configure()
    {
        $this->setName('check:schema');
    }

    protected function execute(Input $input, Output $output)
    {
        $columns = Db::query("SHOW FULL COLUMNS FROM users");
        foreach ($columns as $col) {
            $output->writeln(json_encode($col));
        }
    }
}
