<?php
namespace app\controller;

use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        return $this->success([
            'version' => \think\facade\App::version(),
            'project' => 'AI SaaS Backend'
        ], 'Welcome to AI SaaS Backend');
    }

    public function hello($name = 'ThinkPHP6')
    {
        return $this->success(['name' => $name], 'Hello');
    }
}
