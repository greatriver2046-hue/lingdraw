<template>
  <div class="inspiration-library">
    <div class="page-header-actions" style="display: flex; justify-content: space-between; align-items: center;">
      <div class="left-actions">
          <el-button type="primary" @click="handleAdd">新增灵感</el-button>
          <el-button @click="openCategoryManager">分类管理</el-button>
      </div>
      <div class="right-actions" style="display: flex; gap: 10px;">
          <el-select v-model="searchCategory" placeholder="全部分类" clearable @change="handleSearch" style="width: 150px">
            <el-option v-for="item in categoryList" :key="item.id" :label="item.name" :value="item.id" />
          </el-select>
          <el-input v-model="searchKeyword" placeholder="搜索关键词" clearable @clear="handleSearch" @change="handleSearch" style="width: 200px">
             <template #append>
                <el-button @click="handleSearch"><el-icon><Search /></el-icon></el-button>
             </template>
          </el-input>
      </div>
    </div>
    
    <el-card class="box-card" shadow="never">
      <el-table :data="tableData" style="width: 100%" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column label="案例图片" width="120">
          <template #default="scope">
            <el-image 
              v-if="scope.row.images && scope.row.images.length > 0"
              :src="scope.row.images[0]" 
              :preview-src-list="scope.row.images"
              style="width: 50px; height: 50px; border-radius: 4px;"
              fit="cover"
              preview-teleported
            />
          </template>
        </el-table-column>
        <el-table-column prop="title" label="灵感标题" min-width="150" show-overflow-tooltip />
        <el-table-column prop="category.name" label="分类" width="120">
             <template #default="scope">
                <el-tag v-if="scope.row.category" size="small" type="info">{{ scope.row.category.name }}</el-tag>
                <span v-else>-</span>
             </template>
        </el-table-column>
        <el-table-column prop="author_name" label="作者名称" min-width="120" />
        <el-table-column prop="description" label="介绍" min-width="200" show-overflow-tooltip />
        <el-table-column prop="created_at" label="添加时间" width="180" />
        <el-table-column prop="sort_order" label="排序" width="100" sortable />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="scope">
            <el-button size="small" @click="handleEdit(scope.row)">编辑</el-button>
            <el-button size="small" type="danger" @click="handleDelete(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      
      <div class="pagination-container">
        <el-pagination 
          background 
          layout="prev, pager, next" 
          :total="total" 
          :current-page="currentPage"
          :page-size="pageSize"
          @current-change="handlePageChange"
        />
      </div>
    </el-card>

    <!-- Edit/Add Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑灵感' : '新增灵感'"
      width="600px"
    >
      <el-form :model="form" label-width="100px" :rules="rules" ref="formRef">
        <el-form-item label="灵感标题" prop="title">
          <el-input v-model="form.title" placeholder="请输入灵感标题" />
        </el-form-item>

        <el-form-item label="所属分类" prop="category_id">
            <el-select v-model="form.category_id" placeholder="请选择分类" style="width: 100%">
                <el-option v-for="item in categoryList" :key="item.id" :label="item.name" :value="item.id" />
            </el-select>
        </el-form-item>

        <el-form-item label="使用模型" prop="model">
            <el-select v-model="form.model" placeholder="请选择模型" style="width: 100%" clearable>
                <el-option v-for="item in modelList" :key="item.value" :label="item.label" :value="item.value" />
            </el-select>
        </el-form-item>

        <el-form-item label="作者名称" prop="author_name">
          <el-input v-model="form.author_name" placeholder="请输入作者名称" />
        </el-form-item>

        <el-form-item label="作者主页" prop="author_url">
          <el-input v-model="form.author_url" placeholder="请输入作者主页链接" />
        </el-form-item>

        <el-form-item label="排序权重" prop="sort_order">
          <el-input-number v-model="form.sort_order" :min="0" :step="1" style="width: 100%" />
          <div class="form-tip">数字越大越靠前</div>
        </el-form-item>

        <el-form-item label="案例图片" prop="images">
          <el-upload
            v-model:file-list="fileList"
            action="#"
            list-type="picture-card"
            :http-request="uploadImage"
            :on-remove="handleRemove"
            :on-preview="handlePreview"
          >
            <el-icon><Plus /></el-icon>
          </el-upload>
          <el-dialog v-model="previewVisible">
            <img w-full :src="previewImage" alt="Preview Image" style="width: 100%" />
          </el-dialog>
        </el-form-item>
        
        <el-form-item label="提示词介绍" prop="description">
          <el-input type="textarea" v-model="form.description" rows="3" placeholder="请输入提示词介绍" />
        </el-form-item>

        <el-form-item label="提示词正文" prop="prompt_content">
          <el-input type="textarea" v-model="form.prompt_content" rows="6" placeholder="请输入提示词正文" />
        </el-form-item>
        
        <el-form-item label="备注">
           <el-input v-model="form.remark" placeholder="备注信息" />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" @click="saveItem">
            保存
          </el-button>
        </span>
      </template>
    </el-dialog>

    <!-- Category Manager Dialog -->
    <el-dialog v-model="categoryDialogVisible" title="分类管理" width="600px" custom-class="category-dialog" :show-close="true">
        <div class="category-header">
            <el-button type="primary" size="small" :icon="Plus" @click="handleAddCategory">新增分类</el-button>
        </div>
        <el-table :data="categoryData" size="default" :header-cell-style="{ background: '#f5f7fa', color: '#606266', fontWeight: 'bold' }" row-class-name="category-row">
            <el-table-column prop="name" label="分类名称" min-width="150">
                <template #default="scope">
                    <span style="font-weight: 500;">{{ scope.row.name }}</span>
                </template>
            </el-table-column>
            <el-table-column prop="sort_order" label="排序" width="100" align="center">
                <template #default="scope">
                    <el-tag size="small" type="info" effect="plain">{{ scope.row.sort_order }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="150" align="right">
                <template #default="scope">
                    <el-button link type="primary" size="small" @click="handleEditCategory(scope.row)">编辑</el-button>
                    <el-button link type="danger" size="small" @click="handleDeleteCategory(scope.row)">删除</el-button>
                </template>
            </el-table-column>
            <template #empty>
                <el-empty description="暂无分类数据" :image-size="80"></el-empty>
            </template>
        </el-table>
    </el-dialog>

    <!-- Category Edit/Add Dialog (Moved outside) -->
    <el-dialog v-model="categoryEditVisible" :title="isCategoryEdit ? '编辑分类' : '新增分类'" width="400px">
        <el-form :model="categoryForm" label-width="80px">
            <el-form-item label="名称">
                <el-input v-model="categoryForm.name" />
            </el-form-item>
            <el-form-item label="排序">
                <el-input-number v-model="categoryForm.sort_order" :min="0" />
            </el-form-item>
        </el-form>
        <template #footer>
            <el-button @click="categoryEditVisible = false">取消</el-button>
            <el-button type="primary" @click="saveCategory">保存</el-button>
        </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import request from '@/utils/request'

const tableData = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref(null)

const fileList = ref([])
const previewVisible = ref(false)
const previewImage = ref('')

const form = reactive({
  id: null,
  title: '',
  category_id: null,
  author_name: '',
  author_url: '',
  sort_order: 0,
  images: [],
  description: '',
  prompt_content: '',
  remark: '',
  model: ''
})

const modelList = ref([])

const fetchModels = async () => {
    // Ideally this should fetch from an API, but for now we might need to mock or check if there is an endpoint.
    // The user said "selectable data from available image/video models in backend".
    // I need to find how to get these models.
    // For now I will leave it empty and try to find the API endpoint for models.
    try {
        const res = await request.get('/admin/ai_model/list_all')
        if (res.code === 200) {
             modelList.value = res.data.map(m => ({ label: m.label, value: m.value }))
        }
    } catch (e) {
        // console.error(e)
        // Fallback or just empty
    }
}

const rules = {
  title: [{ required: true, message: '请输入灵感标题', trigger: 'blur' }],
  category_id: [{ required: true, message: '请选择分类', trigger: 'change' }],
  author_name: [{ required: true, message: '请输入作者名称', trigger: 'blur' }],
  description: [{ required: true, message: '请输入提示词介绍', trigger: 'blur' }],
  prompt_content: [{ required: true, message: '请输入提示词正文', trigger: 'blur' }]
}

const fetchData = async () => {
  try {
    const res = await request.get('/admin/inspiration', {
      params: {
        page: currentPage.value,
        page_size: pageSize.value,
        category_id: searchCategory.value || undefined,
        keyword: searchKeyword.value || undefined
      }
    })
    if (res.code === 200) {
      tableData.value = res.data.list || []
      total.value = res.data.total || 0
    }
  } catch (error) {
    console.error('Failed to fetch data:', error)
  }
}

const handleSearch = () => {
  currentPage.value = 1
  fetchData()
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchData()
}

const handleAdd = () => {
  isEdit.value = false
  form.id = null
  form.title = ''
  form.category_id = null
  form.author_name = ''
  form.author_url = ''
  form.sort_order = 0
  form.images = []
  form.description = ''
  form.prompt_content = ''
  form.remark = ''
  form.model = ''
  fileList.value = []
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  Object.assign(form, row)
  // Ensure images is array
  if (!Array.isArray(form.images)) {
      form.images = []
  }
  // Populate fileList for el-upload
  fileList.value = form.images.map((url, index) => ({
      name: `Image ${index + 1}`,
      url: url
  }))
  dialogVisible.value = true
}

const uploadImage = async (options) => {
  const formData = new FormData()
  formData.append('file', options.file)
  
  try {
    const res = await request.post('/admin/inspiration/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    if (res.code === 200 && res.data && res.data.url) {
      // Update the file in fileList with the returned URL
      // Note: el-upload adds the file to fileList automatically before request.
      // We need to find it and update it or just trust form.images sync.
      // But actually, we should manage form.images based on fileList.
      // Or just push to form.images.
      
      // Since we rely on fileList for display, we should update the file item status to success.
      // But simpler is to rely on fileList changes to update form.images?
      // No, fileList contains File objects for new files.
      // Let's just update the specific file item.
      
      options.onSuccess(res) // Mark as success in UI
      
      // We can't easily map back to the fileList item here without index, 
      // but we can assume we rebuild form.images from fileList before save.
      // However, we need the remote URL.
      // So we should attach the url to the file object in fileList.
      options.file.url = res.data.url
      
    } else {
       options.onError(new Error(res.msg || 'Upload failed'))
       ElMessage.error('图片上传失败')
    }
  } catch (error) {
    options.onError(error)
    ElMessage.error('图片上传失败')
  }
}

const handleRemove = (uploadFile, uploadFiles) => {
  // fileList is automatically updated
}

const handlePreview = (file) => {
  previewImage.value = file.url || file.preview
  previewVisible.value = true
}

const saveItem = async () => {
  if (!formRef.value) return
  
  await formRef.value.validate(async (valid) => {
    if (valid) {
      // Collect images from fileList
      form.images = fileList.value.map(f => {
          // Priority 1: Response from upload
          if (f.response && f.response.data && f.response.data.url) {
              return f.response.data.url
          }
          // Priority 2: URL attached to raw file (custom logic in uploadImage)
          if (f.raw && f.raw.url) {
              return f.raw.url
          }
          // Priority 3: Existing URL (not blob)
          if (f.url && !f.url.startsWith('blob:')) {
              return f.url
          }
          return null
      }).filter(url => !!url)
      
      try {
        const url = isEdit.value ? `/admin/inspiration/${form.id}` : '/admin/inspiration'
        const method = isEdit.value ? 'put' : 'post'
        
        const res = await request[method](url, form)
        
        if (res.code === 200) {
          ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
          dialogVisible.value = false
          fetchData()
        } else {
          ElMessage.error(res.msg || '操作失败')
        }
      } catch (error) {
        console.error(error)
        ElMessage.error('操作失败')
      }
    }
  })
}

const handleDelete = (row) => {
    ElMessageBox.confirm(
    '确定要删除该灵感案例吗？',
    '警告',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
        const res = await request.delete(`/admin/inspiration/${row.id}`)
        if (res.code === 200) {
            ElMessage.success('删除成功')
            fetchData()
        } else {
            ElMessage.error(res.msg || '删除失败')
        }
    } catch (error) {
        ElMessage.error('删除失败')
    }
  })
}

// Category Management Variables
const searchCategory = ref('')
const searchKeyword = ref('')
const categoryList = ref([])

const categoryDialogVisible = ref(false)
const categoryData = ref([])
const categoryEditVisible = ref(false)
const isCategoryEdit = ref(false)
const categoryForm = reactive({
    id: null,
    name: '',
    sort_order: 0
})

// Category Management Functions
const fetchCategories = async () => {
    try {
        const res = await request.get('/admin/inspiration/categories/all')
        if (res.code === 200) {
            categoryList.value = res.data || []
        }
    } catch (error) {
        console.error('Failed to fetch categories:', error)
    }
}

const fetchCategoryData = async () => {
    try {
        const res = await request.get('/admin/inspiration/categories')
        if (res.code === 200) {
            categoryData.value = res.data.list || []
        }
    } catch (error) {
        console.error('Failed to fetch category data:', error)
    }
}

const openCategoryManager = () => {
    categoryDialogVisible.value = true
    fetchCategoryData()
}

const handleAddCategory = () => {
    isCategoryEdit.value = false
    categoryForm.id = null
    categoryForm.name = ''
    categoryForm.sort_order = 0
    categoryEditVisible.value = true
}

const handleEditCategory = (row) => {
    isCategoryEdit.value = true
    categoryForm.id = row.id
    categoryForm.name = row.name
    categoryForm.sort_order = row.sort_order
    categoryEditVisible.value = true
}

const saveCategory = async () => {
    if (!categoryForm.name) {
        ElMessage.warning('请输入分类名称')
        return
    }
    try {
        const url = isCategoryEdit.value ? `/admin/inspiration/categories/${categoryForm.id}` : '/admin/inspiration/categories'
        const method = isCategoryEdit.value ? 'put' : 'post'
        const res = await request[method](url, categoryForm)
        
        if (res.code === 200) {
            ElMessage.success(isCategoryEdit.value ? '更新成功' : '创建成功')
            categoryEditVisible.value = false
            fetchCategoryData()
            fetchCategories() // Refresh dropdown list
        } else {
            ElMessage.error(res.msg || '操作失败')
        }
    } catch (error) {
        ElMessage.error('操作失败')
    }
}

const handleDeleteCategory = (row) => {
    ElMessageBox.confirm('确定要删除该分类吗？', '警告', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
    }).then(async () => {
        try {
            const res = await request.delete(`/admin/inspiration/categories/${row.id}`)
            if (res.code === 200) {
                ElMessage.success('删除成功')
                fetchCategoryData()
                fetchCategories()
            } else {
                ElMessage.error(res.msg || '删除失败')
            }
        } catch (error) {
            ElMessage.error('删除失败')
        }
    })
}

onMounted(() => {
  fetchCategories()
  fetchData()
  fetchModels()
})
</script>

<style scoped>
.inspiration-library {
  padding: 20px;
}
.page-header-actions {
  margin-bottom: 20px;
}
.box-card {
  border: none;
  background-color: #fff;
  border-radius: 8px;
}
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
.form-tip {
  font-size: 12px;
  color: #909399;
  line-height: 1.5;
  margin-top: 5px;
}

.category-header {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 0;
    border-bottom: none;
}

.category-title {
    font-size: 16px;
    font-weight: 600;
    color: #303133;
}

:deep(.category-dialog .el-dialog__body) {
    padding-top: 10px;
    padding-bottom: 30px;
}

:deep(.category-row) {
    transition: all 0.3s;
}

:deep(.category-row:hover > td) {
    background-color: #f9fafc !important;
}
</style>
