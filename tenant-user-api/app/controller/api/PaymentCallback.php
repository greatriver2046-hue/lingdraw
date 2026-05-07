<?php
namespace app\controller\api;

use app\BaseController;
use app\model\Order as OrderModel;
use app\model\PaymentConfig;
use think\facade\Request;
use think\facade\Log;

class PaymentCallback extends BaseController
{
    public function notifyWechat()
    {
        $xml = file_get_contents('php://input');
        if (empty($xml)) {
             // Try to get from post if not raw body (for testing tools)
             $xml = Request::param('xml');
        }
        
        Log::info('WeChat Notify: ' . $xml);
        
        try {
            $data = $this->xmlToArray($xml);
            if (!isset($data['out_trade_no'])) {
                throw new \Exception('Missing out_trade_no');
            }
            
            $orderNo = $data['out_trade_no'];
            $order = OrderModel::where('order_no', $orderNo)->find();
            
            if (!$order) {
                Log::error("Order not found: {$orderNo}");
                return response('<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[Order not found]]></return_msg></xml>')->contentType('text/xml');
            }
            
            if ($order->status == 1) {
                // Already paid
                return response('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>')->contentType('text/xml');
            }
            
            // Bypass signature verification for testing if specified
            $isTest = Request::param('test_bypass_sign') == 1;
            
            if (!$isTest) {
                // Get Config to verify sign
                $configModel = PaymentConfig::where('tenant_id', $order->tenant_id)
                    ->where('type', 'wechat')
                    ->find();
                    
                if ($configModel) {
                    $config = $configModel->config;
                    if (is_string($config)) $config = json_decode($config, true);
                    if (is_object($config)) $config = (array)$config;
                    
                    // Verify Sign
                    // $this->verifyWechatSign($data, $config['api_key']);
                }
            }
            
            // Update Order
            $order->status = 1;
            $order->pay_time = date('Y-m-d H:i:s');
            $order->transaction_id = $data['transaction_id'] ?? ('mock_' . time());
            $order->save();
            
            // Grant Package
            $user = \app\model\User::find($order->user_id);
            if ($user) {
                $package = \app\model\Package::find($order->package_id);
                if ($package) {
                    $user->grantPackage($package, $order->order_no);
                } else {
                    Log::error("Package not found for Order: {$orderNo}, Package ID: {$order->package_id}");
                }
            } else {
                Log::error("User not found for Order: {$orderNo}, User ID: {$order->user_id}");
            }
            
            return response('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>')->contentType('text/xml');
            
        } catch (\Exception $e) {
            Log::error('WeChat Callback Error: ' . $e->getMessage());
            return response('<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[' . $e->getMessage() . ']]></return_msg></xml>')->contentType('text/xml');
        }
    }
    
    private function xmlToArray($xml)
    {
        if (!$xml) return [];
        $previousEntityLoader = null;
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        $options = LIBXML_NOCDATA;
        if (defined('LIBXML_NONET')) {
            $options |= LIBXML_NONET;
        }

        $element = simplexml_load_string($xml, 'SimpleXMLElement', $options);

        if (PHP_VERSION_ID < 80000 && $previousEntityLoader !== null) {
            libxml_disable_entity_loader($previousEntityLoader);
        }

        if ($element === false) return [];
        return json_decode(json_encode($element), true);
    }

    public function notifyAlipay()
    {
        // TODO: Implement Alipay Signature Verification
        $params = Request::param();
        // Log::info('Alipay Notify: ' . json_encode($params));
        
        // Mock success for now
        return response('success')->contentType('text/plain');
    }
}
