<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\Order as OrderModel;
use think\Request;

class Order extends BaseController
{
    public function index(Request $request)
    {
        $tenantId = $request->tenantId;
        $limit = (int)$request->param('limit', 10);
        $page = (int)$request->param('page', 1);

        $orderNo = trim((string)$request->param('order_no', ''));
        $userId = $request->param('user_id', null);
        $paymentMethod = trim((string)$request->param('payment_method', ''));
        $statusParam = $request->param('status', null);

        $query = OrderModel::alias('o')
            ->leftJoin('users u', 'u.id = o.user_id')
            ->leftJoin('packages p', 'p.id = o.package_id')
            ->where('o.tenant_id', $tenantId)
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
                'u.username' => 'user_username',
                'u.email' => 'user_email',
                'u.phone' => 'user_phone',
                'p.name' => 'package_name',
            ]);

        if ($orderNo !== '') {
            $query->whereLike('o.order_no', "%{$orderNo}%");
        }

        if ($userId !== null && $userId !== '') {
            $query->where('o.user_id', (int)$userId);
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
}
