<?php
use think\facade\Route;

Route::group('', function () {
    Route::post('auth/login', 'app\admin\controller\Auth@login');
    
    Route::group('', function () {
        Route::post('auth/logout', 'app\admin\controller\Auth@logout');
        Route::get('auth/info', 'app\admin\controller\Auth@info');

        Route::get('dashboard/stats', 'app\admin\controller\Instance@stats');
        
        // Instance Management
        Route::get('instances', 'app\admin\controller\Instance@index');
        Route::post('instances/:id/sso', 'app\admin\controller\Instance@sso');
        Route::post('instances', 'app\admin\controller\Instance@save');
        Route::put('instances/:id', 'app\admin\controller\Instance@update');
        Route::delete('instances/:id', 'app\admin\controller\Instance@delete');
        Route::patch('instances/:id/status', 'app\admin\controller\Instance@status');

        // Admin User Management
        Route::get('users', 'app\admin\controller\AdminUser@index');
        Route::post('users', 'app\admin\controller\AdminUser@save');
        Route::put('users/:id', 'app\admin\controller\AdminUser@update');
        Route::delete('users/:id', 'app\admin\controller\AdminUser@delete');
        Route::put('users/:id/status', 'app\admin\controller\AdminUser@status');

        // Application Users Management
        Route::get('app-users', 'app\admin\controller\User@index');
        Route::put('app-users/:id', 'app\admin\controller\User@update');
        Route::put('app-users/:id/status', 'app\admin\controller\User@status');
        Route::delete('app-users/:id', 'app\admin\controller\User@delete');

        // Model Config Management
        Route::get('models/all', 'app\admin\controller\ModelConfig@getAll');
        Route::get('models', 'app\admin\controller\ModelConfig@index');
        Route::post('models', 'app\admin\controller\ModelConfig@save');
        Route::put('models/:id', 'app\admin\controller\ModelConfig@update');
        Route::delete('models/:id', 'app\admin\controller\ModelConfig@delete');
        Route::patch('models/:id/status', 'app\admin\controller\ModelConfig@status');
        Route::patch('models/:id/default', 'app\admin\controller\ModelConfig@setDefault');

        // System Configurations
        Route::get('system/configs/oss', 'app\admin\controller\SystemConfig@getOss');
        Route::put('system/configs/oss', 'app\admin\controller\SystemConfig@saveOss');
        Route::get('system/configs/:category', 'app\admin\controller\SystemConfig@get');
        Route::put('system/configs/:category', 'app\admin\controller\SystemConfig@save');
        Route::post('system/customer_service/wechat_qr', 'app\admin\controller\SystemConfig@uploadCustomerServiceWechatQr');

        Route::get('system-errors', 'app\admin\controller\SystemErrorLog@index');
        Route::get('sms-logs', 'app\admin\controller\SmsLog@index');

        // User Assets Management
        Route::get('assets', 'app\admin\controller\ImageAsset@index');
        Route::delete('assets/:id', 'app\admin\controller\ImageAsset@delete');

        // System Prompts
        Route::get('system/prompts', 'app\admin\controller\SystemPrompt@index');
        Route::put('system/prompts', 'app\admin\controller\SystemPrompt@save');

    })->middleware(\app\admin\middleware\AuthToken::class);
});
