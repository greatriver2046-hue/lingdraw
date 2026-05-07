<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\SystemErrorLog;

class CheckErrorLog extends Command
{
    protected function configure()
    {
        $this->setName('check:error_log')
            ->setDescription('Check latest system error log');
    }

    protected function execute(Input $input, Output $output)
    {
        $log = SystemErrorLog::order('id', 'desc')->find();
        if ($log) {
            $output->writeln("ID: " . $log->id);
            $output->writeln("Message: " . $log->message);
            $output->writeln("Context: " . $log->context);
            $output->writeln("Payload: " . $log->payload);
            $output->writeln("Created At: " . $log->created_at);
        } else {
            $output->writeln("No error logs found.");
        }
    }
}
