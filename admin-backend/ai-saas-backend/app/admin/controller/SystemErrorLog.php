<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\SystemErrorLog as SystemErrorLogModel;
use think\facade\Db;

class SystemErrorLog extends BaseController
{
    protected function getSystemErrorLogColumns(): array
    {
        static $cols = null;
        if (is_array($cols)) return $cols;

        $map = [];
        try {
            $rows = Db::query("SHOW COLUMNS FROM `system_error_logs`");
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
        }

        $cols = $map;
        return $cols;
    }

    public function index()
    {
        $page = $this->request->param('page', 1);
        $limit = $this->request->param('limit', 10);
        $category = $this->request->param('category', '');
        $keyword = $this->request->param('keyword', '');
        $start = $this->request->param('start_time');
        $end = $this->request->param('end_time');

        $cols = $this->getSystemErrorLogColumns();
        $query = SystemErrorLogModel::order('id', 'desc');

        if (!empty($category)) {
            $query->where('category', $category);
        }
        if (!empty($keyword)) {
            $searchable = [];
            foreach (['message', 'endpoint', 'code', 'source', 'context', 'payload'] as $f) {
                if (isset($cols[$f])) $searchable[] = $f;
            }
            if ($searchable) {
                $query->where(implode('|', $searchable), 'like', "%{$keyword}%");
            }
        }
        if (!empty($start)) {
            $ts = strtotime((string)$start);
            if (isset($cols['create_time']) && $ts) {
                $query->where('create_time', '>=', $ts);
            } elseif (isset($cols['created_at']) && $ts) {
                $query->where('created_at', '>=', date('Y-m-d H:i:s', $ts));
            }
        }
        if (!empty($end)) {
            $ts = strtotime((string)$end);
            if (isset($cols['create_time']) && $ts) {
                $query->where('create_time', '<=', $ts);
            } elseif (isset($cols['created_at']) && $ts) {
                $query->where('created_at', '<=', date('Y-m-d H:i:s', $ts));
            }
        }

        $list = $query->paginate([
            'list_rows' => $limit,
            'page' => $page
        ]);

        return $this->success($list);
    }
}
