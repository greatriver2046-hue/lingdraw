<?php
use think\facade\Route;

Route::get('think', function () {
    return 'hello,ThinkPHP6!';
});

Route::get('hello/:name', 'index/hello');


// Allow OPTIONS for all routes (for CORS preflight)
Route::options('(:rule)', function() {
    return response()->code(204);
});


// API Route Group
Route::group('api', function () {
    
    // Public utility routes (no auth)
    Route::get('proxy/image', 'api.Proxy/image');
    Route::get('public/home_config', 'api.PublicConfig/getHomeConfig');
    Route::get('public/legal', 'api.PublicConfig/getLegal');
    Route::get('public/customer_service', 'api.PublicConfig/getCustomerService');
    Route::get('public/system_prompts', 'api.PublicConfig/getSystemPrompts');
    Route::get('public/packages', 'api.PublicConfig/getPackages');

    // Auth Routes (No Middleware)
    Route::group('auth', function() {
        Route::post('login', 'api.Auth/login');
        Route::post('send_code', 'api.Auth/sendCode');
        Route::post('send_login_code', 'api.Auth/sendLoginCode');
        Route::post('phone_login', 'api.Auth/phoneLogin');
        Route::post('register', 'api.Auth/register');
    });

    // Tenant Admin Routes (No Middleware)
    Route::group('admin', function() {
        Route::group('auth', function() {
            Route::post('login', 'admin.Auth/login');
            Route::post('sso', 'admin.Auth/sso');
        });
        
        // Tenant Admin Protected Routes
        Route::group('', function() {
            // Add tenant admin routes here (e.g., user management, stats)
            Route::get('users', 'api.User/index');
            Route::post('users/save', 'api.User/save');
            Route::post('users/delete', 'api.User/delete');
            Route::post('users/unlock_login', 'api.User/unlockLogin');
            
            // Home Settings
            Route::get('home/config', 'admin.HomeConfig/get');
            Route::post('home/config', 'admin.HomeConfig/save');
            Route::post('home/upload', 'admin.HomeConfig/upload');
            
            // System Settings (separate from home settings)
            Route::get('system/config', 'admin.HomeConfig/systemGet');
            Route::post('system/config', 'admin.HomeConfig/systemSave');
            
            // Legal Settings
            Route::get('legal/config', 'admin.HomeConfig/legalGet');
            Route::post('legal/config', 'admin.HomeConfig/legalSave');

            // Packages
            Route::get('packages', 'admin.Package/index');
            Route::post('packages', 'admin.Package/save');
            Route::put('packages/:id', 'admin.Package/update');
            Route::delete('packages/:id', 'admin.Package/delete');
            Route::put('packages/:id/status', 'admin.Package/status');

            // Orders
            Route::get('orders', 'admin.Order/index');

            // Finance
            Route::get('finance/stats', 'admin.Finance/stats');
            Route::get('finance/points_consumptions', 'admin.Finance/pointsConsumptions');

            // Inspiration Categories
            Route::get('inspiration/categories/all', 'admin.InspirationCategory/all');
            Route::get('inspiration/categories', 'admin.InspirationCategory/index');
            Route::post('inspiration/categories', 'admin.InspirationCategory/save');
            Route::put('inspiration/categories/:id', 'admin.InspirationCategory/update');
            Route::delete('inspiration/categories/:id', 'admin.InspirationCategory/delete');

            // Inspiration Library
            Route::get('inspiration', 'admin.InspirationLibrary/index');
            Route::post('inspiration/upload', 'admin.InspirationLibrary/upload');
            Route::post('inspiration', 'admin.InspirationLibrary/save');
            Route::put('inspiration/:id', 'admin.InspirationLibrary/update');
            Route::delete('inspiration/:id', 'admin.InspirationLibrary/delete');

            // AI Models
            Route::get('ai_model/list_all', 'admin.AiModel/listAll');
            Route::get('models/all', 'admin.AiModel/listAllModels');
            
            // Payment Config
            Route::get('payment/config', 'admin.PaymentConfig/get');
            Route::post('payment/config', 'admin.PaymentConfig/save');
            Route::post('payment/upload_cert', 'admin.PaymentConfig/uploadCert');

            // Works
            Route::get('works', 'admin.ImageGeneration/index');

        })->middleware([\app\middleware\TenantAdminAuth::class]);
    });

    // Public V1 Routes (Tenant Isolation only)
    Route::group('v1', function() {
        Route::get('inspiration/categories', 'api.Inspiration/categories');
        Route::get('inspiration/list', 'api.Inspiration/index');
    });

    // Protected Routes
    Route::group('v1', function () {
        // Example route
        Route::get('test', function () {
            return json(['message' => 'API v1 working', 'tenant' => request()->tenantId]);
        });
        
        // LLM Routes
        Route::post('llm/chat', 'api.Llm/chat');
        Route::post('llm/agent', 'api.Llm/agent');
        Route::post('llm/ocr', 'api.Llm/ocr');
        // Image Generation Routes
        Route::post('image/generate', 'api.Image/generate');
        Route::post('image/recognize-markers', 'api.Image/recognizeMarkers');
        Route::post('image/matting', 'api.Image/matting');
        Route::post('image/reverse-prompt', 'api.Image/reversePrompt');
        Route::get('image/task/:id', 'api.Image/task');
        Route::get('image/history', 'api.Image/history');
        Route::post('image/upload', 'api.Image/upload');
        // Video Generation Routes
        Route::post('video/generate', 'api.Video/generate');
        Route::get('video/task/:id', 'api.Video/task');
        // Agent Tools
        Route::post('tools/generate_video_sora_duomi', 'api.Tools/generate_video_sora_duomi');
        Route::post('tools/generate_image_seedream_v4_5', 'api.Tools/generate_image_seedream_v4_5');
        Route::post('tools/generate_image_seedream_v4_0', 'api.Tools/generate_image_seedream_v4_0');
        Route::post('tools/generate_image_nanobananapro_antigravity', 'api.Tools/generate_image_nanobananapro_antigravity');
        // Models list
        Route::get('models', 'api.Model/list');
        // Conversations
        Route::post('conversation/create', 'api.Conversation/create');
        Route::get('conversation/list', 'api.Conversation/list');
        Route::get('conversation/messages', 'api.Conversation/messages');
        Route::post('conversation/append', 'api.Conversation/append');
        Route::post('conversation/thinking/save', 'api.Conversation/saveThinking');
        Route::get('conversation/thinking', 'api.Conversation/getThinking');
        Route::post('conversation/thinking/delete', 'api.Conversation/deleteThinking');
        Route::post('conversation/save_canvas', 'api.Conversation/saveCanvas');
        Route::post('conversation/update_cover_thumb', 'api.Conversation/updateCoverThumb');
        Route::post('conversation/delete', 'api.Conversation/delete');
        Route::post('conversation/delete_message', 'api.Conversation/deleteMessage');
        Route::post('conversation/clear_messages', 'api.Conversation/clearMessages');

        // Pose History
        Route::post('pose-history/save', 'api.PoseHistory/save');
        Route::get('pose-history/list', 'api.PoseHistory/list');
        Route::post('pose-history/update-last-used', 'api.PoseHistory/updateLastUsed');

        // Resources
        Route::get('resources/list', 'api.Resource/list');
        Route::get('resources/detail', 'api.Resource/detail');
        Route::post('resources/update', 'api.Resource/update');
        Route::post('resources/work', 'api.Resource/createWork');
        Route::post('resources/note', 'api.Resource/createNote');
        Route::post('resources/link', 'api.Resource/createLink');
        Route::post('resources/group', 'api.Resource/createGroup');
        Route::post('resources/file', 'api.Resource/uploadFile');

        // Styles
        Route::get('styles/list', 'api.Style/list');
        Route::post('styles/create', 'api.Style/create');
        Route::post('styles/rename', 'api.Style/rename');
        Route::post('styles/delete', 'api.Style/delete');

        Route::post('style_profile/generate', 'api.StyleProfile/generate');
        Route::get('style_profile/task', 'api.StyleProfile/task');
        Route::get('style_profile/latest', 'api.StyleProfile/latest');
        Route::get('style_profile/list', 'api.StyleProfile/list');
        Route::get('style_profile/detail', 'api.StyleProfile/detail');

        // Writing
        Route::post('writing/create', 'api.Writing/create');
        Route::post('writing/apply_style', 'api.Writing/applyStyle');
        Route::post('writing/skip_style', 'api.Writing/skipStyle');
        Route::get('writing/task', 'api.Writing/task');
        Route::post('writing/cancel', 'api.Writing/cancel');
        Route::get('writing/result', 'api.Writing/result');

        // User Management Routes
        Route::get('users', 'api.User/index');
        Route::post('users/save', 'api.User/save');
        Route::post('users/delete', 'api.User/delete');

        // Current User Routes
        Route::get('user/info', 'api.User/info');
        Route::get('user/points/log', 'api.User/pointsLog');
        Route::post('user/change_password', 'api.User/changePassword');
        Route::post('user/bind_phone', 'api.User/bindPhone');

        Route::get('customer_service', 'api.PublicConfig/getCustomerServiceV1');

        // Order & Payment
        Route::get('payment/methods', 'api.Payment/getMethods');
        Route::get('order/list', 'api.Order/myList');
        Route::post('order/create', 'api.Order/create');
        Route::get('order/status', 'api.Order/checkStatus');
        Route::post('payment/pay', 'api.Payment/pay');
        Route::post('payment/mock_pay', 'api.Payment/mockPay'); // Dev only
    })->middleware([\app\middleware\JwtAuth::class, \app\middleware\TenantIsolation::class]);

    // Public Payment Callbacks (No Auth)
    Route::any('callback/payment/notify/wechat', '\app\controller\api\PaymentCallback@notifyWechat');
    Route::any('callback/payment/notify/alipay', '\app\controller\api\PaymentCallback@notifyAlipay');

})->middleware(\app\middleware\Cors::class); // Global CORS for API
