<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\SaasInstance;
use think\Request;
use think\facade\Log;
use app\service\ImageService;
use think\facade\Filesystem;
use think\facade\Db;

class HomeConfig extends BaseController
{
    private function normalizeConfig($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode(json_encode($value), true) ?: [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function readBodyParams(Request $request): array
    {
        $params = $request->post();
        if (is_array($params) && !empty($params)) {
            return $params;
        }

        $raw = '';
        if (method_exists($request, 'getContent')) {
            $raw = (string)$request->getContent();
        } else if (method_exists($request, 'getInput')) {
            $raw = (string)$request->getInput();
        }

        $raw = trim($raw);
        if ($raw === '') {
            return is_array($params) ? $params : [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return is_array($params) ? $params : [];
    }

    public function legalGet(Request $request)
    {
        try {
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'user_agreement' => (string)($instance->user_agreement ?? ''),
                    'privacy_policy' => (string)($instance->privacy_policy ?? ''),
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Get Legal Config Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function legalSave(Request $request)
    {
        try {
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            $params = $this->readBodyParams($request);
            if (empty($params)) {
                return json(['code' => 422, 'msg' => '未提交内容'], 422);
            }
            if (array_key_exists('user_agreement', $params)) {
                $instance->user_agreement = (string)$params['user_agreement'];
            }
            if (array_key_exists('privacy_policy', $params)) {
                $instance->privacy_policy = (string)$params['privacy_policy'];
            }
            $instance->save();

            return json([
                'code' => 200,
                'msg' => 'Settings saved successfully',
                'data' => [
                    'user_agreement' => (string)($instance->user_agreement ?? ''),
                    'privacy_policy' => (string)($instance->privacy_policy ?? ''),
                ]
            ]);
        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            Log::error('Save Legal Config Error: ' . $rawMsg);

            $msgLower = strtolower((string)$rawMsg);
            if (strpos($msgLower, 'unknown column') !== false) {
                return json(['code' => 500, 'msg' => '数据库缺少协议字段，请先执行迁移'], 500);
            }
            if (strpos($msgLower, 'data too long') !== false || strpos($msgLower, '1406') !== false) {
                return json(['code' => 422, 'msg' => '内容过长，超出存储限制，请联系管理员扩容'], 422);
            }
            if (strpos($msgLower, 'incorrect string value') !== false || strpos($msgLower, '1366') !== false) {
                return json(['code' => 422, 'msg' => '内容包含数据库不支持的字符，请移除特殊字符后再试'], 422);
            }

            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function get(Request $request)
    {
        try {
            // tenantId is injected by TenantAdminAuth middleware
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            $config = $this->normalizeConfig($instance->home_config ?? []);
            
            return json([
                'code' => 200, 
                'msg' => 'Success', 
                'data' => $config
            ]);

        } catch (\Exception $e) {
            Log::error('Get Home Config Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function save(Request $request)
    {
        try {
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            $params = $this->readBodyParams($request);
            if (is_array($params)) {
                unset($params['user_agreement'], $params['privacy_policy']);
            }
            if (empty($params)) {
                return json(['code' => 422, 'msg' => '未提交内容'], 422);
            }
            
            // Merge existing config with new params
            $currentConfig = $this->normalizeConfig($instance->home_config ?? []);
            
            $newConfig = array_merge($currentConfig, $params);

            $instance->home_config = $newConfig;
            $instance->save();

            return json([
                'code' => 200, 
                'msg' => 'Settings saved successfully', 
                'data' => $instance->home_config
            ]);

        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            Log::error('Save Home Config Error: ' . $rawMsg);

            $msgLower = strtolower((string)$rawMsg);
            if (strpos($msgLower, 'data too long') !== false || strpos($msgLower, '1406') !== false) {
                return json(['code' => 422, 'msg' => '内容过长，超出存储限制，请联系管理员扩容'], 422);
            }
            if (strpos($msgLower, 'incorrect string value') !== false || strpos($msgLower, '1366') !== false) {
                return json(['code' => 422, 'msg' => '内容包含数据库不支持的字符，请移除特殊字符后再试'], 422);
            }

            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function systemGet(Request $request)
    {
        try {
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            $config = $this->normalizeConfig($instance->system_config ?? []);

            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => $config
            ]);
        } catch (\Throwable $e) {
            Log::error('Get System Config Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function systemSave(Request $request)
    {
        try {
            $tenantId = $request->tenantId;
            $instance = SaasInstance::find($tenantId);

            if (!$instance) {
                return json(['code' => 404, 'msg' => 'Tenant not found'], 404);
            }

            $params = $this->readBodyParams($request);
            if (empty($params)) {
                return json(['code' => 422, 'msg' => '未提交内容'], 422);
            }

            $currentConfig = $this->normalizeConfig($instance->system_config ?? []);

            $newConfig = array_merge($currentConfig, $params);
            $instance->system_config = $newConfig;
            $instance->save();

            try {
                if (array_key_exists('customer_service', $params) && is_array($params['customer_service'])) {
                    $cs = $params['customer_service'];
                    $payload = [
                        'qrcode' => (string)($cs['qrcode'] ?? ($cs['wechat_qr'] ?? '')),
                        'description' => (string)($cs['description'] ?? ''),
                    ];
                    $cat = 'customer_service_tenant_' . (int)$tenantId;
                    $now = time();
                    $exists = Db::table('system_configs')->where('category', $cat)->find();
                    if ($exists) {
                        Db::table('system_configs')->where('category', $cat)->update([
                            'config' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'status' => 'active',
                            'update_time' => $now,
                        ]);
                    } else {
                        Db::table('system_configs')->insert([
                            'category' => $cat,
                            'config' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'status' => 'active',
                            'create_time' => $now,
                            'update_time' => $now,
                            'delete_time' => null,
                        ]);
                    }
                }
            } catch (\Throwable $e) {
            }

            return json([
                'code' => 200,
                'msg' => 'Settings saved successfully',
                'data' => $instance->system_config
            ]);
        } catch (\Throwable $e) {
            $rawMsg = $e->getMessage();
            Log::error('Save System Config Error: ' . $rawMsg);

            $msgLower = strtolower((string)$rawMsg);
            if (strpos($msgLower, 'data too long') !== false || strpos($msgLower, '1406') !== false) {
                return json(['code' => 422, 'msg' => '内容过长，超出存储限制，请联系管理员扩容'], 422);
            }
            if (strpos($msgLower, 'incorrect string value') !== false || strpos($msgLower, '1366') !== false) {
                return json(['code' => 422, 'msg' => '内容包含数据库不支持的字符，请移除特殊字符后再试'], 422);
            }

            return json(['code' => 500, 'msg' => 'Internal Server Error'], 500);
        }
    }

    public function upload(Request $request, ImageService $imageService)
    {
        try {
            $files = $request->file();
            $urls = [];

            if (empty($files)) {
                return json(['code' => 400, 'msg' => 'No files uploaded'], 400);
            }

            $handleOne = function($f) use (&$urls, $imageService, $request) {
                $ext = 'png';
                if (method_exists($f, 'getOriginalName')) {
                    $name = $f->getOriginalName();
                    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: $ext;
                }
                
                $path = $f->getPathname();
                
                if ($path && is_readable($path)) {
                    $binary = file_get_contents($path);
                    $url = $imageService->storeBinary($binary, $ext, 'tenant_assets');
                    
                    if ($url) {
                        $urls[] = $url;
                    } else {
                        // Fallback to public disk
                        try {
                            $p = Filesystem::disk('public')->putFile('tenant_assets', $f);
                            if ($p) {
                                $url = rtrim($request->domain(), '/') . config('filesystem.disks.public.url') . '/' . str_replace('\\', '/', $p);
                                $urls[] = $url;
                            }
                        } catch (\Throwable $e) {
                            Log::error("Fallback upload error: " . $e->getMessage());
                        }
                    }
                }
            };

            foreach ($files as $file) {
                if (is_array($file)) {
                    foreach ($file as $f) $handleOne($f);
                } else {
                    $handleOne($file);
                }
            }

            if (empty($urls)) {
                return json(['code' => 400, 'msg' => 'Upload failed'], 400);
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['url' => $urls[0]]]); // Return single URL for simplicity

        } catch (\Exception $e) {
            Log::error('Upload Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage()], 500);
        }
    }
}
