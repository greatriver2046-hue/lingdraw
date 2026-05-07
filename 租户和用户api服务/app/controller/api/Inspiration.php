<?php
namespace app\controller\api;

use app\BaseController;
use app\model\InspirationLibrary;
use app\model\InspirationCategory;
use app\model\SaasInstance;
use think\facade\Request;

class Inspiration extends BaseController
{
    private function getTenantId()
    {
        // Try to get from request (middleware) or param
        $tenantId = $this->request->tenantId ?? $this->request->param('tenant_id');
        
        // If not found, try to get default active tenant
        if (!$tenantId) {
             $instance = SaasInstance::where('status', 1)->order('id', 'asc')->find();
             if ($instance) $tenantId = $instance->id;
        }
        
        return $tenantId;
    }

    // 获取灵感列表
    public function index()
    {
        $tenantId = $this->getTenantId();
        
        if (!$tenantId) {
            return json(['code' => 404, 'msg' => 'Tenant not found']);
        }

        $categoryId = $this->request->param('category_id');
        $keyword = $this->request->param('keyword');
        $sort = $this->request->param('sort', 'recommend'); // recommend, new, hot
        
        // 筛选
        $where = [['tenant_id', '=', $tenantId]];
        
        if ($categoryId && $categoryId !== 'all') {
            $where[] = ['category_id', '=', $categoryId];
        }

        if ($keyword) {
            $where[] = ['title|description', 'like', "%{$keyword}%"];
        }

        $query = InspirationLibrary::where($where);

        // 排序
        switch ($sort) {
            case 'new':
                $query->order('created_at', 'desc');
                break;
            case 'hot':
                $query->order('likes', 'desc')->order('views', 'desc');
                break;
            case 'recommend':
            default:
                $query->order('sort_order', 'desc')->order('created_at', 'desc');
                break;
        }

        $list = $query->with(['category'])
            ->paginate([
                'list_rows' => $this->request->param('page_size', 20),
                'page' => $this->request->param('page', 1)
            ]);
            
        // 处理图片路径，确保是完整的 URL
        $items = $list->items();
        foreach ($items as &$item) {
            $images = $item['images'];
            // 兼容可能存储为字符串的情况
            if (is_string($images)) {
                $images = json_decode($images, true);
            }

            $firstImage = '';
            $aspectRatio = 1;
            $processedImages = [];

            if (is_array($images) && !empty($images)) {
                // Check if it's a single object (associative array) instead of array of objects
                if (isset($images['url'])) {
                    $images = [$images];
                }

                foreach ($images as $img) {
                    $imgUrl = '';
                    $imgRatio = 1;
                    if (is_array($img) && isset($img['url'])) {
                        $imgUrl = $img['url'];
                        $imgRatio = $img['aspectRatio'] ?? 1;
                    } elseif (is_string($img)) {
                        $imgUrl = $img;
                    }

                    if ($imgUrl) {
                        if (strpos($imgUrl, 'http') !== 0) {
                            $imgUrl = $this->request->domain() . $imgUrl;
                        }
                        $processedImages[] = [
                            'url' => $imgUrl,
                            'aspectRatio' => $imgRatio
                        ];
                    }
                }
            }

            if (!empty($processedImages)) {
                $firstImage = $processedImages[0]['url'];
                $aspectRatio = $processedImages[0]['aspectRatio'];
            }

            $item['image'] = $firstImage;
            $item['aspectRatio'] = $aspectRatio;
            $item['images'] = $processedImages; // Return full processed list
            
            // Is video check (simple logic based on ext or type field if exists, currently mocked)
            $item['isVideo'] = false; // TODO: Check file extension
        }

        return json([
            'code' => 200, 
            'msg' => 'success', 
            'data' => [
                'total' => $list->total(),
                'per_page' => $list->listRows(),
                'current_page' => $list->currentPage(),
                'last_page' => $list->lastPage(),
                'data' => $items
            ]
        ]);
    }

    // 获取分类列表
    public function categories()
    {
        $tenantId = $this->getTenantId();
        
        if (!$tenantId) {
             return json(['code' => 404, 'msg' => 'Tenant not found']);
        }

        $list = InspirationCategory::where('tenant_id', $tenantId)
            ->order('sort_order', 'desc')
            ->select();
        
        return json(['code' => 200, 'msg' => 'success', 'data' => $list]);
    }
}
