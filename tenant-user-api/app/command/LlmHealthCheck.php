<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use app\model\AiModel;
use app\service\LlmService;
use think\facade\Log;

class LlmHealthCheck extends Command
{
    protected function configure()
    {
        // Command name and description
        $this->setName('llm:health')
            ->setDescription('Check health of all active LLM models');
    }

    protected function execute(Input $input, Output $output)
    {
        $llmService = new LlmService();
        
        // Get all active LLM models
        $models = AiModel::where('model_type', 'llm')
            ->where('status', 1)
            ->select();

        if ($models->isEmpty()) {
            $output->writeln('<info>No active LLM models found.</info>');
            return;
        }

        $output->writeln('<info>Starting LLM Health Check...</info>');
        
        foreach ($models as $model) {
            $output->write("Checking {$model->name} ({$model->model_identity})... ");
            
            try {
                // Simple prompt
                $messages = [['role' => 'user', 'content' => 'Hello']];
                $options = [
                    'model_identity' => $model->model_identity,
                    'temperature' => 0.1,
                    'max_tokens' => 5
                ];

                // We just want to see if it throws an exception
                $response = $llmService->chat($messages, $options);
                
                // If we get here, it's a success (connectivity wise)
                $output->writeln('<info>[OK]</info>');
                
            } catch (\Exception $e) {
                $output->writeln('<error>[FAILED]</error>');
                $output->writeln("  Error: " . $e->getMessage());
                Log::error("Health Check Failed for {$model->name}: " . $e->getMessage());
            }
        }

        $output->writeln('<info>Health Check Completed.</info>');
    }
}
