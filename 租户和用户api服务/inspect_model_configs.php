<?php
namespace app\controller;

use think\facade\Db;

require __DIR__ . '/public/index.php';

try {
    echo "Connected to database: " . env('DATABASE.DATABASE') . "\n";
    
    echo "\nChecking model_configs content:\n";
    $rows = Db::table('model_configs')->select();
    if ($rows->isEmpty()) {
        echo "model_configs table is empty.\n";
    } else {
        foreach ($rows as $row) {
            print_r($row);
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}