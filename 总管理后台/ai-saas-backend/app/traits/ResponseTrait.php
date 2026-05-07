<?php
namespace app\traits;

trait ResponseTrait
{
    protected function success($data = [], $msg = 'success', $code = 200)
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    protected function error($msg = 'error', $code = 400, $data = [])
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }
}
