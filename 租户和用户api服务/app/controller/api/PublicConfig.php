<?php
namespace app\controller\api;

use app\BaseController;
use app\model\SaasInstance;
use app\model\Package;
use think\Request;
use think\facade\Db;
use thans\jwt\facade\JWTAuth as JwtFacade;
use thans\jwt\exception\JWTException;

class PublicConfig extends BaseController
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

    private function extractHostFromRequest(Request $request)
    {
        $candidates = [
            $request->header('x-forwarded-host'),
            $request->header('origin'),
            $request->header('referer'),
            $request->header('host'),
        ];

        foreach ($candidates as $value) {
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }

            if (strpos($value, ',') !== false) {
                $value = trim(explode(',', $value)[0]);
            }

            if (stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0) {
                $parsed = parse_url($value);
                $host = $parsed['host'] ?? '';
                $port = $parsed['port'] ?? null;
                
                if (is_string($host) && $host !== '') {
                    return $port ? "$host:$port" : $host;
                }
                continue;
            }

            // Don't strip port blindly
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveInstance(Request $request)
    {
        $tenantId = $request->param('tenant_id');
        if ($tenantId) {
            return SaasInstance::find($tenantId);
        }

        $jwtTenantId = $this->tryResolveTenantIdFromJwt($request);
        if ($jwtTenantId) {
            $inst = SaasInstance::find($jwtTenantId);
            if ($inst) {
                return $inst;
            }
        }

        $host = strtolower($this->extractHostFromRequest($request));
        $host = trim($host, " \t\n\r\0\x0B.");
        
        // Prepare variations for matching
        $variations = [$host]; // localhost:5174
        
        // host without port
        $hostNoPort = preg_replace('/:\\d+$/', '', $host);
        if ($hostNoPort !== $host) {
            $variations[] = $hostNoPort; // localhost
        }
        
        // host without www
        $hostNoWww = preg_replace('/^www\\./', '', $hostNoPort);
        if ($hostNoWww !== $hostNoPort) {
             $variations[] = $hostNoWww;
        }

        // Try exact match with variations including protocol prefixes that might be in DB
        $instance = SaasInstance::where('status', 1)
            ->where(function ($query) use ($variations) {
                foreach ($variations as $v) {
                    $query->whereOr('domain', $v)
                          ->whereOr('domain', 'http://' . $v)
                          ->whereOr('domain', 'https://' . $v)
                          ->whereOr('domain', 'http://' . $v . '/')
                          ->whereOr('domain', 'https://' . $v . '/');
                }
            })
            ->order('id', 'asc')
            ->find();

        if ($instance) {
            return $instance;
        }
        
        // Fallback: Fuzzy search
        $instance = SaasInstance::where('status', 1)
            ->whereLike('domain', '%' . $hostNoWww . '%')
            ->order('id', 'asc')
            ->find();
            
        if ($instance) {
            return $instance;
        }

        // Fallback for localhost development only if nothing matched
        if ($hostNoWww === '' || $hostNoWww === 'localhost' || $hostNoWww === '127.0.0.1') {
            return SaasInstance::where('status', 1)->order('id', 'asc')->find();
        }

        return null;
    }

    private function tryResolveTenantIdFromJwt(Request $request)
    {
        try {
            $payload = JwtFacade::auth();

            if (is_array($payload)) {
                $tenantId = $this->extractClaim($payload, 'tenant_id');
                if ($tenantId) return $tenantId;
                $tenantId = $this->extractClaim($payload, 'tenantId');
                if ($tenantId) return $tenantId;
                return null;
            }

            if (is_object($payload)) {
                $tenantId = $payload->tenant_id ?? null;
                if (is_object($tenantId) && method_exists($tenantId, 'getValue')) $tenantId = $tenantId->getValue();
                if ($tenantId) return $tenantId;

                $tenantId = $payload->tenantId ?? null;
                if (is_object($tenantId) && method_exists($tenantId, 'getValue')) $tenantId = $tenantId->getValue();
                if ($tenantId) return $tenantId;

                return null;
            }
        } catch (JWTException $e) {
        } catch (\Throwable $e) {
        }

        return null;
    }

    private function extractClaim($payload, $key)
    {
        $claim = $payload[$key] ?? null;
        if (is_object($claim) && method_exists($claim, 'getValue')) {
            return $claim->getValue();
        } elseif (is_array($claim) && isset($claim['value'])) {
            return $claim['value'];
        }
        return $claim;
    }

    public function getHomeConfig(Request $request)
    {
        $instance = $this->resolveInstance($request);

        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null]);
        }
        
        $config = $instance->home_config;
        
        // Handle potential double-encoding or string format
        // Try to decode up to 3 times to handle multiple encodings
        for ($i = 0; $i < 3; $i++) {
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $config = $decoded;
                } else {
                    break; // Not a valid JSON string anymore
                }
            } else {
                break; // Already an array or object
            }
        }
        
        // Final check: if still not array, default to empty
        if (is_object($config)) {
            $config = json_decode(json_encode($config), true);
        }
        
        if (!is_array($config)) {
            $config = [];
        }

        // Ensure slides is present for frontend
        if (!isset($config['slides'])) {
            $config['slides'] = [];
        }

        $tenantHomeConfig = $config;

        $defaultSiteTitle = $instance->name ?: 'AI生成平台';
        if (!array_key_exists('site_title', $config) || $config['site_title'] === null) {
            $config['site_title'] = '';
        }
        if (!array_key_exists('site_name', $config) || $config['site_name'] === null) {
            $config['site_name'] = $defaultSiteTitle;
        }

        // Merge defaults
        $config = array_merge([
            'site_title' => '',
            'site_name' => '',
            'logo' => '',
            'footer_text' => '',
            'graphic_creation_enabled' => 1,
            'default_llm_model' => 'glm-4.7',
            'upscale_model' => 'doubao-seedream-4-5-251128',
            'ocr_model' => 'qwen3-vl-flash',
            'text_edit_model' => 'doubao-seedream-4-5-251128',
            'pose_edit_model' => '',
            'erase_tool_model' => '',
            'remove_bg_model' => 'imageHDseg',
            'reverse_prompt_model' => 'qwen3-vl-flash',
            'remove_watermark_model' => 'doubao-seedream-4-5-251128',
            'object_detection_model' => 'glm-4.6v',
        ], $config);

        $systemConfig = $this->normalizeConfig($instance->system_config ?? []);
        if (array_key_exists('graphic_creation_enabled', $systemConfig)) {
            $config['graphic_creation_enabled'] = (int)$systemConfig['graphic_creation_enabled'] ? 1 : 0;
        }
        if (array_key_exists('login_methods', $systemConfig)) {
            $config['login_methods'] = $this->normalizeConfig($systemConfig['login_methods']);
        } else if (array_key_exists('login_methods', $tenantHomeConfig)) {
            $config['login_methods'] = $this->normalizeConfig($tenantHomeConfig['login_methods']);
        }

        $dmConfig = [];
        try {
            $defaultModels = Db::table('system_configs')->where('category', 'default_models')->value('config');
            if ($defaultModels) {
                $parsed = json_decode($defaultModels, true);
                if (is_array($parsed)) {
                    $dmConfig = $parsed;
                }
            }
        } catch (\Throwable $e) {
        }

        $cfgPoseEditModel = trim((string)($dmConfig['pose_edit_model'] ?? ''));
        $currentPoseEditModel = trim((string)($tenantHomeConfig['pose_edit_model'] ?? ''));
        if ($currentPoseEditModel === '' && $cfgPoseEditModel !== '') {
            $config['pose_edit_model'] = $cfgPoseEditModel;
        }

        $cfgEraseToolModel = trim((string)($dmConfig['erase_tool_model'] ?? ''));
        if ($cfgEraseToolModel !== '') {
            $config['erase_tool_model'] = $cfgEraseToolModel;
        }

        $tenantTextEditModel = trim((string)($tenantHomeConfig['text_edit_model'] ?? ''));
        $cfgTextEditModel = trim((string)($dmConfig['text_edit_model'] ?? ''));
        if ($cfgTextEditModel !== '') {
            $config['text_edit_model'] = $cfgTextEditModel;
        } else if ($tenantTextEditModel !== '') {
            $config['text_edit_model'] = $tenantTextEditModel;
        }

        $config['tenant_id'] = (int)$instance->id;

        return json(['code' => 200, 'msg' => 'Success', 'data' => $config]);
    }

    public function getLegal(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => null]);
        }

        return json([
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'user_agreement' => (string)($instance->user_agreement ?? ''),
                'privacy_policy' => (string)($instance->privacy_policy ?? ''),
            ]
        ]);
    }

    public function getCustomerService(Request $request)
    {
        $qrcode = '';
        $description = '';

        $instance = $this->resolveInstance($request);
        if ($instance) {
            try {
                $cat = 'customer_service_tenant_' . (int)$instance->id;
                $cfg = Db::table('system_configs')->where('category', $cat)->value('config');
                if (is_string($cfg) && $cfg !== '') {
                    $parsed = json_decode($cfg, true);
                    if (is_array($parsed)) {
                        $qrcode = (string)($parsed['qrcode'] ?? ($parsed['wechat_qr'] ?? ''));
                        $description = (string)($parsed['description'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
            }

            $systemConfig = $instance->system_config;
            for ($i = 0; $i < 3; $i++) {
                if (is_string($systemConfig)) {
                    $decoded = json_decode($systemConfig, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $systemConfig = $decoded;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }

            if (is_object($systemConfig)) {
                $systemConfig = json_decode(json_encode($systemConfig), true);
            }

            if (is_array($systemConfig)) {
                $cs = $systemConfig['customer_service'] ?? [];
                if (is_array($cs)) {
                    if ($qrcode === '' && $description === '') {
                        $qrcode = (string)($cs['qrcode'] ?? ($cs['wechat_qr'] ?? ''));
                        $description = (string)($cs['description'] ?? '');
                    }
                }
            }
        }

        if (!$instance) {
            try {
                $cfg = Db::table('system_configs')->where('category', 'customer_service')->value('config');
                if (is_string($cfg) && $cfg !== '') {
                    $parsed = json_decode($cfg, true);
                    if (is_array($parsed)) {
                        $qrcode = (string)($parsed['qrcode'] ?? ($parsed['wechat_qr'] ?? ''));
                        $description = (string)($parsed['description'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return json([
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'qrcode' => $qrcode,
                'description' => $description,
                'wechat_qr' => $qrcode,
            ],
        ]);
    }

    public function getCustomerServiceV1(Request $request)
    {
        $tenantId = (int)($request->tenantId ?? 0);
        if ($tenantId <= 0) {
            return json([
                'code' => 403,
                'msg' => 'Tenant required',
                'data' => [
                    'qrcode' => '',
                    'description' => '',
                    'wechat_qr' => '',
                ],
            ], 403);
        }

        $qrcode = '';
        $description = '';
        $instance = SaasInstance::find($tenantId);

        if ($instance) {
            try {
                $cat = 'customer_service_tenant_' . (int)$tenantId;
                $cfg = Db::table('system_configs')->where('category', $cat)->value('config');
                if (is_string($cfg) && $cfg !== '') {
                    $parsed = json_decode($cfg, true);
                    if (is_array($parsed)) {
                        $qrcode = (string)($parsed['qrcode'] ?? ($parsed['wechat_qr'] ?? ''));
                        $description = (string)($parsed['description'] ?? '');
                    }
                }
            } catch (\Throwable $e) {
            }

            $systemConfig = $instance->system_config;
            for ($i = 0; $i < 3; $i++) {
                if (is_string($systemConfig)) {
                    $decoded = json_decode($systemConfig, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $systemConfig = $decoded;
                    } else {
                        break;
                    }
                } else {
                    break;
                }
            }

            if (is_object($systemConfig)) {
                $systemConfig = json_decode(json_encode($systemConfig), true);
            }

            if (is_array($systemConfig)) {
                $cs = $systemConfig['customer_service'] ?? [];
                if (is_array($cs)) {
                    if ($qrcode === '' && $description === '') {
                        $qrcode = (string)($cs['qrcode'] ?? ($cs['wechat_qr'] ?? ''));
                        $description = (string)($cs['description'] ?? '');
                    }
                }
            }
        }

        return json([
            'code' => 200,
            'msg' => 'Success',
            'data' => [
                'qrcode' => $qrcode,
                'description' => $description,
                'wechat_qr' => $qrcode,
            ],
        ]);
    }

    public function getSystemPrompts(Request $request)
    {
        try {
            $row = Db::table('system_prompts')->order('id', 'desc')->find();
            $poseEditPrompt = '';
            if ($row && isset($row['pose_edit_prompt'])) {
                $poseEditPrompt = (string)($row['pose_edit_prompt'] ?? '');
            }
            $eraseToolPrompt = '';
            if ($row && isset($row['erase_tool_prompt'])) {
                $eraseToolPrompt = (string)($row['erase_tool_prompt'] ?? '');
            }
            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'pose_edit_prompt' => $poseEditPrompt,
                    'erase_tool_prompt' => $eraseToolPrompt,
                ]
            ]);
        } catch (\Throwable $e) {
            return json([
                'code' => 200,
                'msg' => 'Success',
                'data' => [
                    'pose_edit_prompt' => '',
                    'erase_tool_prompt' => '',
                ]
            ]);
        }
    }

    public function getPackages(Request $request)
    {
        $instance = $this->resolveInstance($request);
        if (!$instance) {
            return json(['code' => 404, 'msg' => 'Tenant not found', 'data' => []]);
        }

        $packages = Package::where('tenant_id', $instance->id)
            ->where('status', 1)
            ->order('price', 'asc')
            ->select();
            
        return json([
            'code' => 200,
            'msg' => 'Success',
            'data' => $packages
        ]);
    }
}
