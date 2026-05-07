<template>
  <div class="user-management">
    <div class="page-header-actions">
      <el-button type="primary" @click="handleAdd">添加用户</el-button>
    </div>
    
    <el-card class="box-card" shadow="never">
      <el-table :data="tableData" style="width: 100%" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="username" label="用户名" min-width="150" />
        <el-table-column prop="email" label="邮箱" min-width="180" />
        <el-table-column prop="phone" label="手机号" min-width="120" />
        <el-table-column label="积分" min-width="140">
          <template #default="scope">
             <div style="font-size: 12px;">
               <div>套餐: {{ scope.row.period_points }}</div>
               <div>额外: {{ scope.row.extra_points }}</div>
             </div>
          </template>
        </el-table-column>
        <el-table-column prop="membership_expire" label="会员有效期" min-width="160">
          <template #default="scope">
            {{ formatDateTime(scope.row.membership_expire) }}
          </template>
        </el-table-column>
        <el-table-column prop="register_time" label="注册时间" min-width="160" />
        <el-table-column prop="last_login_time" label="最后登录" min-width="160">
          <template #default="scope">
            {{ formatDateTime(scope.row.last_login_time) }}
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="100">
          <template #default="scope">
            <el-switch v-model="scope.row.status" :active-value="1" :inactive-value="0" @change="handleStatusChange(scope.row)" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280">
          <template #default="scope">
            <el-button size="small" @click="handleEdit(scope.row)">编辑</el-button>
            <el-button v-if="scope.row.login_locked" size="small" type="warning" @click="handleUnlock(scope.row)">解封登录</el-button>
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
      :title="isEdit ? '编辑用户' : '添加用户'"
      width="500px"
    >
      <el-form :model="form" label-width="100px">
        <el-form-item label="用户名">
          <el-input v-model="form.username" :disabled="isEdit" />
        </el-form-item>

        <el-form-item label="邮箱">
          <el-input v-model="form.email" />
        </el-form-item>
        
        <el-form-item label="手机号">
          <el-input v-model="form.phone" />
        </el-form-item>
        
        <el-form-item label="密码" v-if="!isEdit">
           <el-input v-model="form.password" type="password" show-password placeholder="新用户必填" />
        </el-form-item>

        <el-form-item label="套餐积分">
          <el-input-number v-model="form.period_points" :min="0" />
        </el-form-item>
        
        <el-form-item label="额外积分">
          <el-input-number v-model="form.extra_points" :min="0" />
        </el-form-item>
        
        <el-form-item label="状态">
           <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" @click="saveUser">
            保存
          </el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import request from '@/utils/request'

const tableData = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

const dialogVisible = ref(false)
const isEdit = ref(false)
const form = ref({
  id: null,
  username: '',
  email: '',
  level: 'Free',
  period_points: 0,
  extra_points: 0,
  password: '',
  status: 1
})

const fetchUsers = async () => {
  try {
    const res = await request.get('/admin/users', {
      params: {
        page: currentPage.value,
        limit: pageSize.value
      }
    })
    tableData.value = (res.data.data || []).map(normalizeUserRow)
    total.value = res.data.total
  } catch (error) {
    console.error(error)
  }
}

const normalizeUserRow = (row) => ({
  ...row,
  membership_expire: row.membership_expire || row.package_expire_time || row.package_end_time || '',
  last_login_time: row.last_login_time || row.last_login_at || ''
})

const formatDateTime = (value) => {
  if (!value) return '-'
  if (typeof value === 'string') return value

  const numericValue = Number(value)
  const timestamp = Number.isFinite(numericValue) && numericValue < 1e12
    ? numericValue * 1000
    : numericValue
  const date = new Date(timestamp)
  if (Number.isNaN(date.getTime())) return String(value)

  const yyyy = date.getFullYear()
  const mm = String(date.getMonth() + 1).padStart(2, '0')
  const dd = String(date.getDate()).padStart(2, '0')
  const hh = String(date.getHours()).padStart(2, '0')
  const mi = String(date.getMinutes()).padStart(2, '0')
  const ss = String(date.getSeconds()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchUsers()
}

const handleAdd = () => {
  isEdit.value = false
  form.value = { id: null, username: '', email: '', phone: '', period_points: 0, extra_points: 0, password: '', status: 1 }
  dialogVisible.value = true
}

const handleEdit = (row) => {
  isEdit.value = true
  form.value = { ...row, password: '' } // Clear password field for security
  dialogVisible.value = true
}

const handleDelete = (row) => {
  ElMessageBox.confirm(
    '确定要删除该用户吗?',
    '警告',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      await request.post('/admin/users/delete', { id: row.id })
      ElMessage.success('删除成功')
      fetchUsers()
    } catch (error) {
      // handled
    }
  })
}

const handleUnlock = (row) => {
  ElMessageBox.confirm(
    '确定要解封该用户的登录限制吗?',
    '提示',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      await request.post('/admin/users/unlock_login', { id: row.id })
      ElMessage.success('已解封')
      fetchUsers()
    } catch (error) {
      // handled
    }
  })
}

const saveUser = async () => {
  try {
    await request.post('/admin/users/save', form.value)
    ElMessage.success(isEdit.value ? '更新成功' : '添加成功')
    dialogVisible.value = false
    fetchUsers()
  } catch (error) {
    // handled
  }
}

const handleStatusChange = async (row) => {
  try {
    await request.post('/admin/users/save', { id: row.id, status: row.status, username: row.username })
    ElMessage.success('状态更新成功')
  } catch (error) {
    row.status = row.status === 1 ? 0 : 1 // Revert
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<style scoped>
.user-management {
  padding: 20px;
}
.page-header-actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 20px;
}
.box-card {
  border: none;
}

.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
