<?php
namespace app\admin\controller;

use app\BaseController;
use app\admin\model\SystemConfig as SystemConfigModel;
use think\exception\ValidateException;

class SystemConfig extends BaseController
{
    public function get($category)
    {
        $record = SystemConfigModel::where('category', $category)->find();
        if (!$record) {
            return $this->success([]);
        }
        $config = $record->config ? json_decode($record->config, true) : [];
        if (!is_array($config)) {
            $config = [];
        }
        return $this->success($config);
    }

    public function save($category)
    {
        $data = $this->request->put();
        try {
            if ($category === 'oss') {
                $this->validate($data, [
                    'access_key_id' => 'require|max:100',
                    'access_key_secret' => 'require|max:200',
                    'bucket' => 'require|max:100',
                    'endpoint' => 'require|max:200'
                ]);
            } elseif ($category === 'sms') {
                $this->validate($data, [
                    'access_key_id' => 'require|max:100',
                    'access_key_secret' => 'require|max:200',
                    'sign_name' => 'require|max:100',
                    'template_code' => 'require|max:100',
                    'region_id' => 'require|max:50',
                    'endpoint' => 'require|max:200'
                ]);
            } elseif ($category === 'web_search') {
                $toggle = (string)($data['search_provider_toggle'] ?? '');
                if ($toggle === '' || $toggle === 'current') {
                    $data['search_provider_toggle'] = 'zhipu';
                }
                $this->validate($data, [
                    'api_key' => 'max:200',
                    'endpoint' => 'max:300',
                    'search_engine' => 'max:50',
                    'count' => 'integer',
                    'search_domain_filter' => 'max:200',
                    'search_recency_filter' => 'max:20',
                    'content_size' => 'max:20',
                    'search_provider_toggle' => 'in:zhipu,searxng,volcengine',
                    'searxng_endpoint' => 'max:300',
                    'searxng_api_key' => 'max:200',
                    'searxng_engines' => 'max:200',
                    'searxng_result_count' => 'integer',
                    'volc_api_key' => 'max:200',
                    'volc_endpoint' => 'max:300',
                    'volc_model' => 'max:120'
                ]);
                if (($data['search_provider_toggle'] ?? '') === 'searxng') {
                    if (trim((string)($data['searxng_endpoint'] ?? '')) === '') {
                        throw new ValidateException('SearXNG 接口地址不能为空');
                    }
                }
                if (($data['search_provider_toggle'] ?? '') === 'volcengine') {
                    if (trim((string)($data['volc_api_key'] ?? '')) === '') {
                        throw new ValidateException('火山引擎 API Key 不能为空');
                    }
                    if (trim((string)($data['volc_model'] ?? '')) === '') {
                        throw new ValidateException('火山引擎 Model 不能为空');
                    }
                }
            }

            $record = SystemConfigModel::where('category', $category)->find();
            $payload = [
                'category' => $category,
                'config' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => 'active'
            ];

            if ($record) {
                $record->save($payload);
            } else {
                $record = SystemConfigModel::create($payload);
            }

            return $this->success(json_decode($record->config, true), '保存成功');
        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function getOss()
    {
        $record = SystemConfigModel::where('category', 'oss')->find();
        if (!$record) {
            return $this->success([
                'access_key_id' => '',
                'access_key_secret' => '',
                'bucket' => '',
                'endpoint' => ''
            ]);
        }
        $config = $record->config ? json_decode($record->config, true) : [];
        return $this->success([
            'access_key_id' => $config['access_key_id'] ?? '',
            'access_key_secret' => $config['access_key_secret'] ?? '',
            'bucket' => $config['bucket'] ?? '',
            'endpoint' => $config['endpoint'] ?? ''
        ]);
    }

    public function saveOss()
    {
        $data = $this->request->put();
        try {
            $this->validate($data, [
                'access_key_id' => 'require|max:100',
                'access_key_secret' => 'require|max:200',
                'bucket' => 'require|max:100',
                'endpoint' => 'require|max:200'
            ], [
                'access_key_id.require' => 'AccessKeyId不能为空',
                'access_key_secret.require' => 'AccessKeySecret不能为空',
                'bucket.require' => 'Bucket不能为空',
                'endpoint.require' => 'Endpoint不能为空'
            ]);

            $record = SystemConfigModel::where('category', 'oss')->find();
            $payload = [
                'category' => 'oss',
                'config' => json_encode([
                    'access_key_id' => $data['access_key_id'],
                    'access_key_secret' => $data['access_key_secret'],
                    'bucket' => $data['bucket'],
                    'endpoint' => $data['endpoint']
                ], JSON_UNESCAPED_UNICODE),
                'status' => 'active'
            ];

            if ($record) {
                $record->save($payload);
            } else {
                $record = SystemConfigModel::create($payload);
            }

            return $this->success(json_decode($record->config, true), '保存成功');
        } catch (ValidateException $e) {
            return $this->error($e->getError());
        } catch (\Exception $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }
    }

    public function uploadCustomerServiceWechatQr()
    {
        try {
            $file = $this->request->file('file');
            if (!$file) {
                return $this->error('请上传图片文件');
            }

            $size = (int)$file->getSize();
            if ($size <= 0) {
                return $this->error('文件为空');
            }
            if ($size > 512 * 1024) {
                return $this->error('图片大小不能超过 512KB');
            }

            $ext = strtolower((string)$file->getOriginalExtension());
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
                return $this->error('仅支持 png/jpg/jpeg/webp 格式');
            }

            $mime = (string)$file->getMime();
            if (stripos($mime, 'image/') !== 0) {
                return $this->error('文件类型不正确');
            }

            $contents = @file_get_contents($file->getPathname());
            if (!is_string($contents) || $contents === '') {
                return $this->error('读取文件失败');
            }

            $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($contents);
            $data = ['wechat_qr' => $dataUrl];

            $record = SystemConfigModel::where('category', 'customer_service')->find();
            $payload = [
                'category' => 'customer_service',
                'config' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'status' => 'active'
            ];
            if ($record) {
                $record->save($payload);
            } else {
                $record = SystemConfigModel::create($payload);
            }

            $config = $record->config ? json_decode($record->config, true) : [];
            if (!is_array($config)) {
                $config = $data;
            }
            return $this->success($config, '上传成功');
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }
}
