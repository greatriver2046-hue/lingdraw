<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\SaasInstance;
use app\admin\model\User as UserModel;
use app\admin\model\ImageAsset as ImageAssetModel;
use think\facade\Db;
use think\facade\Log;

class Instance extends BaseController
{
    /**
     * 获取实例列表
     */
    public function index()
    {
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 10);
        $keyword = $this->request->param('keyword', '');

        $query = SaasInstance::order('id', 'desc');

        if (!empty($keyword)) {
            $query->where('name|domain', 'like', "%{$keyword}%");
        }

        $list = $query->paginate([
            'list_rows' => $limit,
            'page' => $page
        ]);

        return $this->success($list);
    }

    /**
     * 创建实例
     */
    public function save()
    {
        $data = $this->request->post();
        
        try {
            unset($data['id'], $data['create_time'], $data['update_time']);

            if (!isset($data['name']) || trim((string)$data['name']) === '') {
                $domain = isset($data['domain']) ? trim((string)$data['domain']) : '';
                if ($domain !== '') {
                    $data['name'] = $domain;
                }
            }

            $this->validate($data, [
                'name' => 'require|max:100',
                'domain' => 'require|max:100|unique:saas_instances',
                'admin_email' => 'require|email',
                'phone' => 'require|mobile',
                'expiry_date' => 'require|date',
                'password' => 'require|min:6'
            ], [
                'name.require' => '实例名称不能为空',
                'domain.unique' => '域名已存在',
                'admin_email' => '管理员邮箱格式不正确',
                'phone' => '手机号格式不正确',
                'password.require' => '登录密码不能为空',
                'password.min' => '密码长度至少6位'
            ]);

            $instance = SaasInstance::create($data);
            return $this->success($instance, '创建成功');

        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('创建失败: ' . $e->getMessage());
        }
    }

    /**
     * 更新实例
     */
    public function update($id)
    {
        $data = $this->request->put();
        
        try {
            $instance = SaasInstance::find($id);
            if (!$instance) {
                return $this->error('实例不存在', 404);
            }

            unset($data['id'], $data['create_time'], $data['update_time']);

            $this->validate($data, [
                'name' => 'max:100',
                'domain' => 'max:100|unique:saas_instances,domain,' . $id,
                'admin_email' => 'email',
                'phone' => 'mobile'
            ]);

            // 如果密码为空，则不修改
            if (empty($data['password'])) {
                unset($data['password']);
            } else {
                if (strlen($data['password']) < 6) {
                    return $this->error('密码长度至少6位');
                }
            }

            $instance->save($data);
            return $this->success($instance, '更新成功');

        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('更新失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除实例
     */
    public function delete($id)
    {
        $instance = SaasInstance::find($id);
        if (!$instance) {
            return $this->error('实例不存在', 404);
        }

        $instance->delete();
        return $this->success([], '删除成功');
    }

    /**
     * 切换状态
     */
    public function status($id)
    {
        $instance = SaasInstance::find($id);
        if (!$instance) {
            return $this->error('实例不存在', 404);
        }

        $instance->status = $instance->status == 1 ? 0 : 1;
        $instance->save();

        return $this->success(['status' => $instance->status], '状态更新成功');
    }

    public function sso($id)
    {
        $instance = SaasInstance::find($id);
        if (!$instance) {
            return $this->error('实例不存在', 404);
        }

        if ((int)$instance->status !== 1) {
            return $this->error('实例已停用', 403);
        }

        $secret = (string)env('SSO_SECRET', '');
        if ($secret === '') {
            try {
                app()->loadEnv();
            } catch (\Throwable $e) {
            }
            $secret = (string)env('SSO_SECRET', '');
        }
        if ($secret === '') {
            return $this->error('SSO_SECRET未配置', 500);
        }

        $tenantApiBase = (string)env('TENANT_API_BASE');
        if ($tenantApiBase === '') {
            return $this->error('TENANT_API_BASE未配置', 500);
        }
        $tenantApiBase = rtrim($tenantApiBase, '/');
        $url = $tenantApiBase . '/api/admin/auth/sso';

        $payload = json_encode(['tenant_id' => (int)$instance->id], JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json',
            'X-SSO-SECRET: ' . $secret,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $payload === false ? '' : $payload,
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false || trim((string)$raw) === '') {
            return $this->error('获取租户登录令牌失败', 500);
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return $this->error('获取租户登录令牌失败', 500);
        }

        if (($json['code'] ?? 500) != 200) {
            $msg = (string)($json['msg'] ?? '获取租户登录令牌失败');
            return $this->error($msg, (int)($json['code'] ?? 500));
        }

        $token = $json['data']['token'] ?? '';
        $userInfo = $json['data']['userInfo'] ?? null;
        if (!is_string($token) || trim($token) === '') {
            return $this->error('获取租户登录令牌失败', 500);
        }

        $tenantAdminUrl = (string)env('TENANT_ADMIN_URL', '');
        if ($tenantAdminUrl === '') {
            return $this->error('TENANT_ADMIN_URL未配置', 500);
        }
        $tenantAdminUrl = rtrim($tenantAdminUrl, '/');

        $userInfoJson = is_array($userInfo) ? json_encode($userInfo, JSON_UNESCAPED_UNICODE) : '';

        return $this->success([
            'url' => $tenantAdminUrl . '/login?sso_token=' . rawurlencode($token) . '&user=' . rawurlencode((string)$userInfoJson),
        ]);
    }

    public function stats()
    {
        $days = (int)$this->request->param('days', 30);
        if ($days <= 0) $days = 30;
        if ($days > 90) $days = 90;

        $orderPage = (int)$this->request->param('order_page', 1);
        $orderLimit = (int)$this->request->param('order_limit', 10);
        if ($orderPage <= 0) $orderPage = 1;
        if ($orderLimit <= 0) $orderLimit = 10;
        if ($orderLimit > 100) $orderLimit = 100;

        $todayStartTs = strtotime(date('Y-m-d 00:00:00'));
        $todayEndTs = strtotime(date('Y-m-d 23:59:59'));
        $monthStart = date('Y-m-01 00:00:00');
        $monthEnd = date('Y-m-t 23:59:59');
        $monthStartTs = strtotime($monthStart);
        $monthEndTs = strtotime($monthEnd);

        $totalInstances = 0;
        try {
            $totalInstances = (int)SaasInstance::count();
        } catch (\Throwable $e) {
        }

        $activeUsers = 0;
        try {
            $activeUsers = (int)UserModel::where('status', 1)->count();
        } catch (\Throwable $e) {
        }

        $todayImageCount = 0;
        try {
            $todayImageCount = (int)ImageAssetModel::where('type', 'image')
                ->whereBetween('create_time', [$todayStartTs, $todayEndTs])
                ->count();
        } catch (\Throwable $e) {
        }

        $trendStartTs = strtotime(date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')));
        $trendDates = [];
        $trendValues = [];
        $trendMap = [];

        try {
            $trendRows = Db::name('image_assets')
                ->where('type', 'image')
                ->whereBetween('create_time', [$trendStartTs, $todayEndTs])
                ->fieldRaw("FROM_UNIXTIME(create_time,'%Y-%m-%d') AS d, COUNT(*) AS total")
                ->group('d')
                ->order('d', 'asc')
                ->select()
                ->toArray();

            foreach ($trendRows as $row) {
                if (!is_array($row)) continue;
                $d = isset($row['d']) ? trim((string)$row['d']) : '';
                if ($d === '') continue;
                $trendMap[$d] = (int)($row['total'] ?? 0);
            }
        } catch (\Throwable $e) {
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $trendDates[] = $d;
            $trendValues[] = $trendMap[$d] ?? 0;
        }

        $usageDistribution = [];
        try {
            $usageDistribution = Db::name('image_assets')
                ->whereBetween('create_time', [$trendStartTs, $todayEndTs])
                ->fieldRaw("IFNULL(type,'unknown') AS name, COUNT(*) AS value")
                ->group('name')
                ->order('value', 'desc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
        }

        $monthIncome = 0.0;
        $recentTransactions = [
            'total' => 0,
            'per_page' => $orderLimit,
            'current_page' => $orderPage,
            'data' => []
        ];
        $orderCols = $this->getTableColumns('orders');
        if ($orderCols) {
            $amountField = null;
            foreach (['amount', 'total_amount', 'pay_amount'] as $f) {
                if (isset($orderCols[$f])) {
                    $amountField = $f;
                    break;
                }
            }

            if ($amountField) {
                try {
                    $incomeQuery = Db::name('orders');
                    if (isset($orderCols['status'])) $incomeQuery->where('status', 1);
                    if (isset($orderCols['pay_time'])) {
                        $incomeQuery->whereBetweenTime('pay_time', $monthStart, $monthEnd);
                    } elseif (isset($orderCols['paid_at'])) {
                        $incomeQuery->whereBetweenTime('paid_at', $monthStart, $monthEnd);
                    } elseif (isset($orderCols['created_at'])) {
                        $incomeQuery->whereBetweenTime('created_at', $monthStart, $monthEnd);
                    } elseif (isset($orderCols['create_time'])) {
                        $incomeQuery->whereBetween('create_time', [$monthStartTs, $monthEndTs]);
                    }
                    $monthIncome = (float)$incomeQuery->sum($amountField);
                } catch (\Throwable $e) {
                    $monthIncome = 0.0;
                }
            }

            try {
                $recentQuery = Db::name('orders');
                if (isset($orderCols['status'])) $recentQuery->where('status', 1);

                $fields = [];
                foreach ([
                    'id',
                    'order_no',
                    'tenant_id',
                    'user_id',
                    'package_id',
                    'status',
                    'payment_method',
                    'amount',
                    'total_amount',
                    'pay_amount',
                    'pay_time',
                    'paid_at',
                    'created_at',
                    'create_time',
                ] as $f) {
                    if (isset($orderCols[$f])) $fields[] = $f;
                }
                if ($fields) $recentQuery->field($fields);

                $orderBy = isset($orderCols['id']) ? 'id' : (isset($orderCols['create_time']) ? 'create_time' : (isset($orderCols['created_at']) ? 'created_at' : null));
                if ($orderBy) {
                    $recentQuery->order($orderBy, 'desc');
                }
                $list = $recentQuery->paginate(['list_rows' => $orderLimit, 'page' => $orderPage]);
                $recentTransactions = [
                    'total' => $list->total(),
                    'per_page' => $list->listRows(),
                    'current_page' => $list->currentPage(),
                    'data' => $list->items()
                ];
            } catch (\Throwable $e) {
                $recentTransactions = [
                    'total' => 0,
                    'per_page' => $orderLimit,
                    'current_page' => $orderPage,
                    'data' => []
                ];
            }
        }

        return $this->success([
            'total_instances' => $totalInstances,
            'active_users' => $activeUsers,
            'today_image_count' => $todayImageCount,
            'month_income' => $monthIncome,
            'usage_trend' => [
                'dates' => $trendDates,
                'values' => $trendValues,
            ],
            'usage_distribution' => $usageDistribution,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    protected function getTableColumns(string $table): array
    {
        $map = [];
        try {
            $rows = Db::query("SHOW COLUMNS FROM `{$table}`");
            if (is_array($rows)) {
                foreach ($rows as $r) {
                    if (!is_array($r)) continue;
                    $field = $r['Field'] ?? $r['field'] ?? null;
                    $field = is_string($field) ? trim($field) : '';
                    if ($field === '') continue;
                    $map[$field] = 1;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $map;
    }
}
