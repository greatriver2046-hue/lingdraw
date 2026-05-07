<?php
require 'vendor/autoload.php';
$app = new \think\App();
$app->initialize();
use think\facade\Db;

$res = Db::query('SHOW TABLES');
echo json_encode($res, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . "\n";
