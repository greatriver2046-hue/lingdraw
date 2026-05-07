<?php
namespace app\controller\admin;

use app\BaseController;
use app\model\InspirationLibrary as LibraryModel;
use think\Request;
use think\exception\ValidateException;
use app\service\ImageService;
use think\facade\Filesystem;
use think\facade\Log;

class InspirationLibrary extends BaseController
{
    /**
     * List inspiration library items
     */
    public function index(Request $request)
    {
        $tenantId = $request->tenantId;
        $page = $request->param('page', 1);
        $pageSize = $request->param('page_size', 10);
        $keyword = $request->param('keyword', '');
        $categoryId = $request->param('category_id');
        
        $query = LibraryModel::with(['category'])->where('tenant_id', $tenantId);

        if (!empty($categoryId)) {
            $query->where('category_id', $categoryId);
        }

        if (!empty($keyword)) {
            $query->where('title|description|prompt_content|author_name', 'like', "%{$keyword}%");
        }
        
        $list = $query->order('sort_order', 'desc')
            ->order('created_at', 'desc')
            ->paginate(['list_rows' => $pageSize, 'page' => $page]);
            
        return json([
            'code' => 200,
            'msg' => 'success',
            'data' => [
                'list' => $list->items(),
                'total' => $list->total()
            ]
        ]);
    }

    /**
     * Create item
     */
    public function save(Request $request)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['title', 'category_id', 'author_name', 'author_url', 'sort_order', 'images', 'description', 'prompt_content', 'remark', 'model']);
        
        try {
            $this->validate($data, [
                'title' => 'require|max:255',
                'category_id' => 'integer',
                'author_name' => 'require|max:100',
                'sort_order' => 'integer',
                'description' => 'require',
                'prompt_content' => 'require',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        $data['tenant_id'] = $tenantId;
        
        // Ensure images is array if passed as json string or array
        if (isset($data['images']) && is_string($data['images'])) {
            $decoded = json_decode($data['images'], true);
            if (is_array($decoded)) {
                $data['images'] = $decoded;
            } else {
                 // If not valid json, maybe just a string, wrap in array? Or keep as is? 
                 // Model expects json type, so array is best.
                 // If frontend sends array, thinkphp handles it if type is set to json?
                 // Actually thinkphp model type casting handles array <-> json string conversion.
                 // If input is array, it's fine. If input is json string, we might need to decode it first if we want to manipulate it, 
                 // but for saving, if it's already array, model will json_encode it.
            }
        }
        
        try {
            $item = LibraryModel::create($data);
            return json(['code' => 200, 'msg' => 'Created successfully', 'data' => $item]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to create: ' . $e->getMessage()]);
        }
    }

    /**
     * Update item
     */
    public function update(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        $data = $request->only(['title', 'category_id', 'author_name', 'author_url', 'sort_order', 'images', 'description', 'prompt_content', 'remark', 'model']);
        
        $item = LibraryModel::where('tenant_id', $tenantId)->find($id);
        if (!$item) {
            return json(['code' => 404, 'msg' => 'Item not found']);
        }
        
        try {
            $this->validate($data, [
                'title' => 'max:255',
                'author_name' => 'max:100',
                'sort_order' => 'integer',
            ]);
        } catch (ValidateException $e) {
            return json(['code' => 400, 'msg' => $e->getError()]);
        }
        
        try {
            $item->save($data);
            return json(['code' => 200, 'msg' => 'Updated successfully', 'data' => $item]);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    /**
     * Delete item
     */
    public function delete(Request $request, $id)
    {
        $tenantId = $request->tenantId;
        
        $item = LibraryModel::where('tenant_id', $tenantId)->find($id);
        if (!$item) {
            return json(['code' => 404, 'msg' => 'Item not found']);
        }
        
        try {
            $item->delete();
            return json(['code' => 200, 'msg' => 'Deleted successfully']);
        } catch (\Exception $e) {
            return json(['code' => 500, 'msg' => 'Failed to delete: ' . $e->getMessage()]);
        }
    }

    /**
     * Upload image
     */
    public function upload(Request $request, ImageService $imageService)
    {
        try {
            $files = $request->file();
            $urls = [];

            if (empty($files)) {
                return json(['code' => 400, 'msg' => 'No files uploaded'], 400);
            }

            $handleOne = function($f) use (&$urls, $imageService, $request) {
                $ext = 'png';
                if (method_exists($f, 'getOriginalName')) {
                    $name = $f->getOriginalName();
                    $ext = pathinfo($name, PATHINFO_EXTENSION) ?: $ext;
                }
                
                $path = $f->getPathname();
                
                if ($path && is_readable($path)) {
                    $binary = file_get_contents($path);
                    
                    // Compress image to < 100KB
                    $binary = $imageService->compressImage($binary, 100 * 1024);
                    // Use jpg extension for compressed image as we force convert to jpeg in compressImage
                    // Check if binary header is JPEG or keep original ext if compression failed/skipped?
                    // compressImage returns jpeg stream if success, or original if failed/small enough.
                    // Let's detect signature or just check if it was compressed.
                    // Actually compressImage always returns binary. If it converted, it's JPEG.
                    // Let's assume if size changed significantly it might be JPEG.
                    // But simpler: just use 'jpg' if we compressed?
                    // The function returns original binary if small enough.
                    // We can check if binary starts with FF D8.
                    if (substr($binary, 0, 2) === "\xFF\xD8") {
                        $ext = 'jpg';
                    }

                    $url = $imageService->storeBinary($binary, $ext, 'inspiration_assets');
                    
                    if ($url) {
                        $urls[] = $url;
                    } else {
                        // Fallback to public disk
                        try {
                            $p = Filesystem::disk('public')->putFile('inspiration_assets', $f);
                            if ($p) {
                                $url = rtrim($request->domain(), '/') . config('filesystem.disks.public.url') . '/' . str_replace('\\', '/', $p);
                                $urls[] = $url;
                            }
                        } catch (\Throwable $e) {
                            Log::error("Fallback upload error: " . $e->getMessage());
                        }
                    }
                }
            };

            foreach ($files as $file) {
                if (is_array($file)) {
                    foreach ($file as $f) $handleOne($f);
                } else {
                    $handleOne($file);
                }
            }

            if (empty($urls)) {
                return json(['code' => 400, 'msg' => 'Upload failed'], 400);
            }

            return json(['code' => 200, 'msg' => 'Success', 'data' => ['url' => $urls[0]]]);

        } catch (\Exception $e) {
            Log::error('Upload Error: ' . $e->getMessage());
            return json(['code' => 500, 'msg' => $e->getMessage()], 500);
        }
    }
}
