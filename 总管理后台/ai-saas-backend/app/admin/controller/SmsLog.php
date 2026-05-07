<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\SmsLog as SmsLogModel;

class SmsLog extends BaseController
{
    public function index()
    {
        $page = (int)$this->request->param('page', 1);
        $limit = (int)$this->request->param('limit', 10);
        $phone = trim((string)$this->request->param('phone', ''));
        $type = trim((string)$this->request->param('type', ''));
        $keyword = trim((string)$this->request->param('keyword', ''));
        $start = $this->request->param('start_time');
        $end = $this->request->param('end_time');

        $query = SmsLogModel::order('id', 'desc');

        if ($phone !== '') {
            $query->where('phone', 'like', "%{$phone}%");
        }
        if ($type !== '') {
            $query->where('type', $type);
        }
        if ($keyword !== '') {
            $query->where('content|user_ip', 'like', "%{$keyword}%");
        }
        if (!empty($start)) {
            $ts = strtotime((string)$start);
            if ($ts) {
                $query->where('create_time', '>=', $ts);
            }
        }
        if (!empty($end)) {
            $ts = strtotime((string)$end);
            if ($ts) {
                $query->where('create_time', '<=', $ts);
            }
        }

        $list = $query->paginate([
            'list_rows' => max($limit, 1),
            'page' => max($page, 1),
        ]);

        return $this->success($list);
    }
}
