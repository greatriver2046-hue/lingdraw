<?php
namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class ClearRedisQueues extends Command
{
    protected function configure()
    {
        $this->setName('clear:redis-queues')
            ->setDescription('Clear all Redis queue-related keys (queues, locks, semaphores, task statuses)');
    }

    protected function execute(Input $input, Output $output)
    {
        $cfg = config('queue.connections.redis');
        $redis = class_exists('Redis') ? new \Redis() : null;

        if (!$redis) {
            $output->writeln('<error>PHP Redis extension not available.</error>');
            return;
        }

        try {
            $redis->connect($cfg['host'] ?? '127.0.0.1', $cfg['port'] ?? 6379, $cfg['timeout'] ?? 0);
            if (!empty($cfg['password'])) { $redis->auth($cfg['password']); }
            
            // Explicitly select database, default to 0 if not set
            $db = isset($cfg['select']) ? (int)$cfg['select'] : 0;
            $redis->select($db);
            
            $output->writeln("<info>Connected to Redis at {$cfg['host']}:{$cfg['port']} (DB: {$db})</info>");
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to connect to Redis: ' . $e->getMessage() . '</error>');
            return;
        }

        $patterns = [
            'queues:*',           // queue lists
            'queue:*',            // alternative queue lists
            'reserved:*',         // reserved sets
            'delayed:*',          // delayed sets
            'resque:*',           // resque-style keys (compat)
            'image_task:*',       // image task status
            'video_task:*',       // video task status
            'image:gen:lock:*',   // image generation locks
            'video:gen:lock:*',   // video generation locks
            'image:gen:semaphore',
            'video:gen:semaphore',
        ];

        $totalDeleted = 0;
        foreach ($patterns as $pattern) {
            $it = null;
            $toDelete = [];
            $foundInPattern = 0;
            $output->writeln("<comment>Scanning for pattern: {$pattern}</comment>");
            
            do {
                // Scan returns an array where the first element is the next iterator and the second is the array of keys
                // However, phpredis implementation of scan takes iterator by reference and returns keys or false
                $keys = $redis->scan($it, $pattern, 1000);
                
                if ($keys !== false && !empty($keys)) {
                    foreach ($keys as $k) {
                        $toDelete[] = $k;
                        $foundInPattern++;
                        if ($foundInPattern <= 3) {
                             $output->writeln("  - Found key: {$k}");
                        }
                    }
                    
                    // delete in chunks
                    while (count($toDelete) >= 500) {
                        $chunk = array_splice($toDelete, 0, 500);
                        try {
                            $deleted = $redis->del($chunk);
                            $totalDeleted += (int)$deleted;
                        } catch (\Throwable $e) {
                            $output->writeln("<error>Error deleting chunk: " . $e->getMessage() . "</error>");
                        }
                    }
                }
            } while ($it > 0); // phpredis scan updates $it, returns keys. $it becomes 0 when done.

            if (!empty($toDelete)) {
                try {
                    $deleted = $redis->del($toDelete);
                    $totalDeleted += (int)$deleted;
                } catch (\Throwable $e) {
                    $output->writeln("<error>Error deleting remaining keys: " . $e->getMessage() . "</error>");
                }
            }
            
            if ($foundInPattern > 0) {
                $output->writeln("<info>  Matched {$foundInPattern} keys for {$pattern}.</info>");
            }
        }

        $output->writeln("<info>Done. Total deleted keys: {$totalDeleted}</info>");
    }
}
