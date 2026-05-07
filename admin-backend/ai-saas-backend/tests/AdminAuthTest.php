<?php

namespace tests;

use think\App;
use think\facade\Config;
use think\facade\Route;
use PHPUnit\Framework\TestCase;
use think\facade\Db;
use think\facade\Cache;
use app\admin\model\AdminUser;

class AdminAuthTest extends TestCase
{
    protected $app;

    protected function setUp(): void
    {
        $this->app = new App();
        $this->app->initialize();

        Config::set(['jwt.secret' => 'test_secret']);

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Cache::delete('login_fail:127.0.0.1:test_admin');
        Cache::delete('login_fail:127.0.0.1:non_existent');
        
        // Use test database or mock
        // For simplicity in this environment, we assume the dev database is safe or separate
        // Ideally, switch to sqlite in memory or test DB
        
        // Create a test user
        $this->createTestUser();
    }

    protected function tearDown(): void
    {
        // Cleanup
        if (class_exists(AdminUser::class)) {
             AdminUser::where('username', 'test_admin')->delete();
        }
    }

    protected function createTestUser()
    {
        AdminUser::where('username', 'test_admin')->delete();
        AdminUser::create([
            'username' => 'test_admin',
            'password' => 'password123',
            'status' => 1
        ]);
    }

    public function testLoginSuccess()
    {
        $response = $this->post('/admin/auth/login', [
            'username' => 'test_admin',
            'password' => 'password123'
        ]);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(200, $data['code']);
        $this->assertArrayHasKey('token', $data['data']);
    }

    public function testLoginFailWrongPassword()
    {
        $response = $this->post('/admin/auth/login', [
            'username' => 'test_admin',
            'password' => 'wrong_password'
        ]);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(401, $data['code']);
    }

    public function testLoginFailWrongUsername()
    {
        $response = $this->post('/admin/auth/login', [
            'username' => 'non_existent',
            'password' => 'password123'
        ]);

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(401, $data['code']);
    }

    // Helper to simulate POST request
    protected function post($url, $data)
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = $url;
        $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $_POST = $data;

        if ($url === '/admin/auth/login') {
            (new \thans\jwt\provider\JWT($this->app->request))->init();
            $controller = new \app\admin\controller\Auth($this->app);
            return $controller->login();
        }

        throw new \RuntimeException('Unsupported route: ' . $url);
    }
}
