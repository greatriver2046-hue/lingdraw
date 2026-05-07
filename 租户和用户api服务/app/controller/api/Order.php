<?php
namespace app\controller\api;

use app\BaseController;
use app\model\Order as OrderModel;
use app\model\Package;
use think\facade\Request;

class Order extends BaseController
{
    public function myList()
    {
        $userId = $this->request->userId;
        $tenantId = (int)$this->request->tenantId;

        if (!$userId) {
            return json(['code' => 401, 'msg' => 'Unauthorized']);
        }

        $limit = (int)Request::param('limit', 10);
        $page = (int)Request::param('page', 1);
        if ($limit <= 0) $limit = 10;
        if ($page <= 0) $page = 1;

        $orderNo = trim((string)Request::param('order_no', ''));
        $paymentMethod = trim((string)Request::param('payment_method', ''));
        $statusParam = Request::param('status', null);

        $query = OrderModel::alias('o')
            ->leftJoin('packages p', 'p.id = o.package_id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.user_id', (int)$userId)
            ->field([
                'o.id',
                'o.order_no',
                'o.tenant_id',
                'o.user_id',
                'o.package_id',
                'o.amount',
                'o.payment_method',
                'o.status',
                'o.transaction_id',
                'o.pay_time',
                'o.created_at',
                'o.updated_at',
                'p.name' => 'package_name',
                'p.points' => 'package_points',
                'p.duration_days' => 'package_duration_days',
            ]);

        if ($orderNo !== '') {
            $query->whereLike('o.order_no', "%{$orderNo}%");
        }
        if ($paymentMethod !== '') {
            $query->where('o.payment_method', $paymentMethod);
        }
        if ($statusParam !== null && $statusParam !== '') {
            $query->where('o.status', (int)$statusParam);
        }

        $list = $query->order('o.id', 'desc')->paginate(['list_rows' => $limit, 'page' => $page]);

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'total' => $list->total(),
                'per_page' => $list->listRows(),
                'current_page' => $list->currentPage(),
                'data' => $list->items()
            ]
        ]);
    }

    public function create()
    {
        $userId = $this->request->userId; // From Auth middleware
        $tenantId = $this->request->tenantId; // From middleware
        
        $packageId = Request::param('package_id');
        $paymentMethod = Request::param('payment_method');
        
        $package = Package::find($packageId);
        if (!$package) {
            return json(['code' => 404, 'msg' => 'Package not found']);
        }
        
        $order = new OrderModel();
        $order->order_no = date('YmdHis') . mt_rand(1000, 9999);
        $order->tenant_id = $tenantId;
        $order->user_id = $userId;
        $order->package_id = $packageId;
        $order->amount = $package->price;
        $order->payment_method = $paymentMethod;
        $order->status = 0; // Pending
        $order->save();
        
        return json(['code' => 200, 'msg' => 'Order created', 'data' => $order]);
    }
    
    public function checkStatus()
    {
        $orderNo = Request::param('order_no');
        $order = OrderModel::where('order_no', $orderNo)->find();
        
        if (!$order) {
            return json(['code' => 404, 'msg' => 'Order not found']);
        }

        // Check timeout (15 mins = 900 seconds)
        $createdAt = $order->created_at ?? null;
        if ($order->status == 0 && $createdAt && (time() - strtotime($createdAt) > 900)) {
            $order->status = -1; // Cancelled
            $order->save();
        }
        
        return json(['code' => 200, 'msg' => 'success', 'data' => ['status' => $order->status]]);
    }
}
