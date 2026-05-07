<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\PaymentConfig as PaymentConfigModel;
use think\facade\Request;

class PaymentConfig extends BaseController
{
    public function get()
    {
        $tenantId = $this->request->tenantAdminId;
        $configs = PaymentConfigModel::where('tenant_id', $tenantId)->select();
        
        $data = [
            'wechat' => ['is_enabled' => 0, 'app_id' => '', 'mch_id' => '', 'api_key' => '', 'notify_url' => '', 'cert_content' => ''],
            'alipay' => ['is_enabled' => 0, 'app_id' => '', 'private_key' => '', 'public_key' => '', 'notify_url' => '']
        ];

        foreach ($configs as $config) {
            $type = $config->type;
            if (in_array($type, ['wechat', 'alipay'])) {
                $confData = $config->config;
                if (is_object($confData)) {
                    $confData = (array)$confData;
                }
                // Mask secrets
                if ($type == 'wechat') {
                    if (!empty($confData['api_key'])) $confData['api_key'] = '******';
                    if (!empty($confData['cert_content'])) $confData['cert_content'] = 'Has Certificate'; // Don't send cert content
                } elseif ($type == 'alipay') {
                    if (!empty($confData['private_key'])) $confData['private_key'] = '******';
                }
                
                $data[$type] = array_merge($confData, ['is_enabled' => $config->is_enabled]);
            }
        }

        return json(['code' => 200, 'msg' => 'success', 'data' => $data]);
    }

    public function save()
    {
        $tenantId = $this->request->tenantAdminId;
        $params = Request::post();
        
        // Handle WeChat
        if (isset($params['wechat'])) {
            $this->saveConfig($tenantId, 'wechat', $params['wechat']);
        }

        // Handle Alipay
        if (isset($params['alipay'])) {
            $this->saveConfig($tenantId, 'alipay', $params['alipay']);
        }

        return json(['code' => 200, 'msg' => 'Saved successfully']);
    }
    
    private function saveConfig($tenantId, $type, $data)
    {
        $config = PaymentConfigModel::where('tenant_id', $tenantId)
            ->where('type', $type)
            ->find();
            
        if (!$config) {
            $config = new PaymentConfigModel();
            $config->tenant_id = $tenantId;
            $config->type = $type;
        }

        $isEnabled = isset($data['is_enabled']) ? $data['is_enabled'] : 0;
        
        // Merge with existing config to preserve secrets if not updated
        $existingConfig = $config->config ?? [];
        if (is_object($existingConfig)) {
            $existingConfig = (array)$existingConfig;
        }
        
        // If password fields are '******', ignore them (keep existing)
        if ($type == 'wechat') {
             if (isset($data['api_key']) && $data['api_key'] === '******') {
                 $data['api_key'] = $existingConfig['api_key'] ?? '';
             }
             if (isset($data['cert_content']) && $data['cert_content'] === 'Has Certificate') {
                 $data['cert_content'] = $existingConfig['cert_content'] ?? '';
             }
        } elseif ($type == 'alipay') {
             if (isset($data['private_key']) && $data['private_key'] === '******') {
                 $data['private_key'] = $existingConfig['private_key'] ?? '';
             }
        }
        
        $config->config = $data; // Model handles JSON
        $config->is_enabled = $isEnabled;
        $config->save();
    }
    
    // Upload cert file
    public function uploadCert()
    {
        $file = request()->file('file');
        if (!$file) {
            return json(['code' => 400, 'msg' => 'No file uploaded']);
        }
        
        // Read content directly
        $content = file_get_contents($file->getPathname());
        
        return json(['code' => 200, 'msg' => 'Uploaded', 'data' => ['content' => $content]]);
    }
}
