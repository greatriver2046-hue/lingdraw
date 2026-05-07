<?php
namespace app\controller\api;

use app\BaseController;
use app\model\Order as OrderModel;
use app\model\PaymentConfig;
use app\service\PaymentService;
use think\facade\Request;
use think\facade\Log;

class Payment extends BaseController
{
    public function getMethods()
    {
        $tenantId = $this->request->tenantId;
        $configs = PaymentConfig::where('tenant_id', $tenantId)
            ->where('is_enabled', 1)
            ->column('type');
        
        return json(['code' => 200, 'msg' => 'success', 'data' => $configs]);
    }

    public function pay()
    {
        $orderNo = Request::param('order_no');
        $order = OrderModel::where('order_no', $orderNo)->find();
        
        if (!$order) {
            return json(['code' => 404, 'msg' => 'Order not found']);
        }
        
        if ($order->status == 1) {
             return json(['code' => 400, 'msg' => 'Order already paid']);
        }
        
        // Get Tenant Payment Config
        $configModel = PaymentConfig::where('tenant_id', $order->tenant_id)
            ->where('type', $order->payment_method)
            ->where('is_enabled', 1)
            ->find();
            
        if (!$configModel) {
            return json(['code' => 400, 'msg' => 'Payment method not configured or disabled']);
        }
        
        $config = $configModel->config;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        // Ensure config is array (ThinkPHP might return stdClass for JSON fields)
        if (is_object($config)) {
            $config = (array) $config;
        }
        
        $service = new PaymentService();
        $payParams = [];
        
        try {
            if ($order->payment_method == 'wechat') {
                 $payParams = $service->wechatNative($order, $config);
            } elseif ($order->payment_method == 'alipay') {
                 $payParams = $service->alipayPage($order, $config);
            }
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Payment Error: ' . $e->getMessage()]);
        }
        
        return json(['code' => 200, 'msg' => 'Payment initiated', 'data' => $payParams]);
    }
    
    // Mock Pay for testing
    public function mockPay()
    {
        $orderNo = Request::param('order_no');
        $order = OrderModel::where('order_no', $orderNo)->find();
        
        if (!$order) return json(['code' => 404]);
        
        $order->status = 1;
        $order->pay_time = date('Y-m-d H:i:s');
        $order->transaction_id = 'mock_' . uniqid();
        $order->save();
        
        // TODO: Grant package to user (Update user points/plan)
        $this->grantPackage($order);
        
        return json(['code' => 200, 'msg' => 'Mock Payment Success']);
    }
    
    private function grantPackage($order)
    {
        $user = \app\model\User::find($order->user_id);
        if ($user) {
            $package = \app\model\Package::find($order->package_id);
            if ($package) {
                $user->grantPackage($package, $order->order_no);
            }
        }
    }
}
