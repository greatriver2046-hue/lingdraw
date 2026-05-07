<template>
  <div class="package-management">
    <div class="page-header-actions">
      <el-button type="primary" @click="handleAdd">新增套餐</el-button>
    </div>
    
    <el-card class="box-card" shadow="never">
      <el-table :data="tableData" style="width: 100%" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="套餐名称" min-width="150">
          <template #default="scope">
            <span :style="{ color: scope.row.theme_color || '#333', fontWeight: 'bold' }">{{ scope.row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="price" label="价格(元)" width="120">
          <template #default="scope">
            ¥{{ scope.row.price }}
          </template>
        </el-table-column>
        <el-table-column prop="original_price" label="原价(元)" width="120">
          <template #default="scope">
            ¥{{ scope.row.original_price || 0 }}
          </template>
        </el-table-column>
        <el-table-column prop="duration_days" label="有效期(天)" width="120" />
        <el-table-column prop="points" label="包含点数" width="120" />
        <el-table-column prop="description" label="描述" min-width="200" show-overflow-tooltip />
        <el-table-column prop="create_time" label="创建时间" min-width="160" />
        <el-table-column prop="status" label="状态" width="100">
          <template #default="scope">
            <el-switch v-model="scope.row.status" :active-value="1" :inactive-value="0" @change="handleStatusChange(scope.row)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
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
      :title="isEdit ? '编辑套餐' : '新增套餐'"
      width="500px"
    >
      <el-form :model="form" label-width="100px" :rules="rules" ref="formRef">
        <el-form-item label="套餐名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入套餐名称" />
        </el-form-item>

        <el-form-item label="价格" prop="price">
          <el-input-number v-model="form.price" :precision="2" :step="0.1" :min="0" style="width: 100%" />
        </el-form-item>

        <el-form-item label="原价" prop="original_price">
          <el-input-number v-model="form.original_price" :precision="2" :step="0.1" :min="0" style="width: 100%" />
          <div class="form-tip">仅用于前端展示划线价格，不影响实际支付金额</div>
        </el-form-item>

        <el-form-item label="主题颜色" prop="theme_color">
          <div style="display: flex; align-items: center; gap: 10px;">
            <el-color-picker v-model="form.theme_color" />
            <span style="font-size: 12px; color: #666;">{{ form.theme_color }}</span>
          </div>
        </el-form-item>
        
        <el-form-item label="有效期(天)" prop="duration_days">
          <el-input-number v-model="form.duration_days" :min="1" :step="1" style="width: 100%" />
        </el-form-item>
        
        <el-form-item label="包含点数" prop="points">
          <el-input-number v-model="form.points" :min="0" :step="100" style="width: 100%" />
          <div class="form-tip">注：若设置了重置周期，则为每个周期发放的点数</div>
        </el-form-item>

        <el-form-item label="重置周期(天)" prop="reset_cycle_days">
          <el-input-number v-model="form.reset_cycle_days" :min="0" :step="1" style="width: 100%" />
          <div class="form-tip">注：0表示不重置；30表示每30天重置点数</div>
        </el-form-item>
        
        <el-form-item label="套餐描述">
          <el-input type="textarea" v-model="form.description" rows="3" placeholder="请输入套餐描述" />
        </el-form-item>
        
        <el-form-item label="状态">
           <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" @click="savePackage">
            保存
          </el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'

const tableData = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref(null)

const form = reactive({
  id: null,
  name: '',
  price: 0,
  original_price: 0,
  duration_days: 30,
  points: 0,
  reset_cycle_days: 0,
  description: '',
  status: 1,
  theme_color: '#111111'
})

const rules = {
  name: [{ required: true, message: '请输入套餐名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入价格', trigger: 'blur' }],
  duration_days: [{ required: true, message: '请输入有效期', trigger: 'blur' }],
  points: [{ required: true, message: '请输入包含点数', trigger: 'blur' }]
}

const fetchData = async () => {
  try {
    const res = await request.get('/admin/packages', {
      params: {
        page: currentPage.value,
        page_size: pageSize.value
      }
    })
    if (res.code === 200) {
      tableData.value = res.data.list || []
      total.value = res.data.total || 0
    }
  } catch (error) {
    console.error('Failed to fetch packages:', error)
  }
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchData()
}

const handleAdd = () => {
  isEdit.value = false
  form.id = null
  form.name = ''
  form.price = 0
  form.original_price = 0
  form.duration_days = 30
  form.points = 1000
  form.reset_cycle_days = 0
  form.description = ''
  form.status = 1
  form.theme_color = '#111111'
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  Object.assign(form, row)
  dialogVisible.value = true
}

const savePackage = async () => {
  if (!formRef.value) return
  
  await formRef.value.validate(async (valid) => {
    if (valid) {
      try {
        const url = isEdit.value ? `/admin/packages/${form.id}` : '/admin/packages'
        const method = isEdit.value ? 'put' : 'post'
        
        const res = await request[method](url, form)
        
        if (res.code === 200) {
          ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
          dialogVisible.value = false
          fetchData()
        } else {
          ElMessage.error(res.message || '操作失败')
        }
      } catch (error) {
        console.error(error)
        ElMessage.error('操作失败')
      }
    }
  })
}

const handleStatusChange = async (row) => {
  try {
    const res = await request.put(`/admin/packages/${row.id}/status`, { status: row.status })
    if (res.code === 200) {
      ElMessage.success('状态更新成功')
    } else {
      // Revert change if failed
      row.status = row.status === 1 ? 0 : 1
      ElMessage.error(res.message || '更新失败')
    }
  } catch (error) {
    row.status = row.status === 1 ? 0 : 1
    ElMessage.error('更新失败')
  }
}

const handleDelete = (row) => {
  ElMessageBox.confirm(
    '确定要删除该套餐吗？',
    '警告',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      const res = await request.delete(`/admin/packages/${row.id}`)
      if (res.code === 200) {
        ElMessage.success('删除成功')
        fetchData()
      } else {
        ElMessage.error(res.message || '删除失败')
      }
    } catch (error) {
      ElMessage.error('删除失败')
    }
  })
}

onMounted(() => {
  fetchData()
})
</script>

<style scoped>
.package-management {
  padding: 20px;
}
.page-header-actions {
  margin-bottom: 20px;
  display: flex;
  justify-content: flex-end;
}
.box-card {
  border: none;
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
</style>
