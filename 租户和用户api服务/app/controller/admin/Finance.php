<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\SaasInstance;
use think\Request;
use think\facade\Db;

class Finance extends BaseController
{
    public function stats(Request $request)
    {
        $tenantId = $request->tenantId;
        $days = (int)$request->param('days', 7);
        if ($days <= 0) $days = 7;
        if ($days > 90) $days = 90;

        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd = date('Y-m-d 23:59:59');
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd = date('Y-m-t 23:59:59');

        $todayIncome = (float)Db::name('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->whereBetweenTime('pay_time', $todayStart, $todayEnd)
            ->sum('amount');

        $monthIncome = (float)Db::name('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->whereBetweenTime('pay_time', $monthStart, $monthEnd)
            ->sum('amount');

        $trendStart = date('Y-m-d 00:00:00', strtotime("-" . ($days - 1) . " days"));
        $trendRows = Db::name('orders')
            ->where('tenant_id', $tenantId)
            ->where('status', 1)
            ->whereBetweenTime('pay_time', $trendStart, $todayEnd)
            ->fieldRaw("DATE(pay_time) AS d, SUM(amount) AS total")
            ->group('d')
            ->order('d', 'asc')
            ->select()
            ->toArray();

        $trendMap = [];
        foreach ($trendRows as $row) {
            if (!empty($row['d'])) {
                $trendMap[$row['d']] = (float)($row['total'] ?? 0);
            }
        }

        $trendDates = [];
        $trendValues = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trendDates[] = $d;
            $trendValues[] = $trendMap[$d] ?? 0.0;
        }

        $distributionRows = Db::name('orders')->alias('o')
            ->leftJoin('packages p', 'p.id = o.package_id')
            ->where('o.tenant_id', $tenantId)
            ->where('o.status', 1)
            ->whereBetweenTime('o.pay_time', $monthStart, $monthEnd)
            ->fieldRaw("IFNULL(p.name, '未知套餐') AS name, SUM(o.amount) AS value")
            ->group('name')
            ->order('value', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        $recentRows = Db::name('orders')->alias('o')
            ->leftJoin('users u', 'u.id = o.user_id')
            ->leftJoin('packages p', 'p.id = o.package_id')
            ->where('o.tenant_id', $tenantId)
            ->field([
                'o.order_no',
                'o.amount',
                'o.status',
                'o.payment_method',
                'o.pay_time',
                'o.created_at',
                'u.username' => 'user_username',
                'p.name' => 'package_name',
            ])
            ->order('o.id', 'desc')
            ->limit(10)
            ->select()
            ->toArray();

        $smsCount = 0;
        $pointsCount = 0;
        $tenant = SaasInstance::find($tenantId);
        if ($tenant) {
            $smsCount = max((int)($tenant->sms_quota ?? 0), 0);
            $pointsCount = max((int)($tenant->quota ?? 0), 0);
        }

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'today_income' => $todayIncome,
                'month_income' => $monthIncome,
                'sms_count' => $smsCount,
                'points_count' => $pointsCount,
                'income_trend' => [
                    'dates' => $trendDates,
                    'values' => $trendValues,
                ],
                'usage_distribution' => $distributionRows,
                'recent_transactions' => $recentRows,
            ]
        ]);
    }

    public function pointsConsumptions(Request $request)
    {
        $tenantId = $request->tenantId;
        $page = (int)$request->param('page', 1);
        $pageSize = (int)$request->param('page_size', 10);

        if ($page <= 0) $page = 1;
        if ($pageSize <= 0) $pageSize = 10;
        if ($pageSize > 100) $pageSize = 100;

        $baseQuery = Db::name('user_point_logs')->alias('l')
            ->leftJoin('users u', 'u.id = l.user_id')
            ->where('l.tenant_id', $tenantId)
            ->where('l.amount', '<', 0);

        $total = (int)(clone $baseQuery)->count('l.id');

        $rows = $baseQuery
            ->field([
                'l.id',
                'l.user_id',
                'u.username' => 'user_username',
                'l.type',
                'l.amount',
                'l.balance_after',
                'l.description',
                'l.ref_id',
                'l.create_time',
            ])
            ->order('l.id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $rows,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize,
            ],
        ]);
    }
}

