<template>
  <div class="instance-management">
    <div class="actions-bar">
      <el-button type="primary" @click="openCreateDialog">
        <el-icon><Plus /></el-icon> 新建实例
      </el-button>
      <el-input
        v-model="queryParams.keyword"
        placeholder="搜索实例名称/域名"
        style="width: 260px"
        clearable
        @clear="loadData"
        @keyup.enter="loadData"
      >
        <template #append>
          <el-button @click="loadData"><el-icon><Search /></el-icon></el-button>
        </template>
      </el-input>
    </div>

    <el-card class="table-card" shadow="never">
      <el-table :data="tableData" style="width: 100%" v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="name" label="实例名称" min-width="120" />
        <el-table-column prop="domain" label="域名" min-width="150" />
        <el-table-column prop="admin_email" label="登录账号" min-width="150" />
        <el-table-column prop="phone" label="手机号" width="120" />
        <el-table-column prop="quota" label="生图配额" width="100" />
        <el-table-column prop="sms_quota" label="短信额度" width="100" />
        <el-table-column prop="used" label="已用" width="80" />
        <el-table-column prop="expiry_date" label="到期时间" width="110" />
        <el-table-column prop="status" label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'">
              {{ row.status === 1 ? '运行中' : '已停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="420" fixed="right">
          <template #default="{ row }">
            <el-button size="small" @click="openEditDialog(row)">配置</el-button>
            <el-button size="small" type="warning" @click="openQuotaDialog(row)">配额/续费</el-button>
            <el-button size="small" type="primary" @click="enterTenantAdmin(row)">进入后台</el-button>
            <el-button 
              size="small" 
              :type="row.status === 1 ? 'danger' : 'success'"
              @click="toggleStatus(row)"
            >
              {{ row.status === 1 ? '停用' : '启用' }}
            </el-button>
            <el-button size="small" type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-container" v-if="total > 0">
        <el-pagination
          v-model:current-page="queryParams.page"
          v-model:page-size="queryParams.limit"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          @size-change="loadData"
          @current-change="loadData"
        />
      </div>
    </el-card>

    <!-- Create/Edit Dialog -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '配置实例' : '新建实例'" width="500px">
      <el-form :model="form" label-width="100px" :rules="rules" ref="formRef">
        <el-form-item label="实例名称" prop="name">
          <el-input v-model="form.name" placeholder="例如：Alpha Corp" />
        </el-form-item>
        <el-form-item label="绑定域名" prop="domain">
          <el-input v-model="form.domain" placeholder="例如：alpha.aisaas.com" />
        </el-form-item>
        <el-form-item label="登录账号" prop="admin_email">
          <el-input v-model="form.admin_email" placeholder="请输入管理员邮箱" />
        </el-form-item>
        <el-form-item label="登录密码" prop="password">
          <el-input 
            v-model="form.password" 
            type="password" 
            show-password 
            :placeholder="isEdit ? '留空则不修改密码' : '请输入登录密码'" 
          />
        </el-form-item>
        <el-form-item label="手机号" prop="phone">
          <el-input v-model="form.phone" placeholder="请输入管理员手机号" />
        </el-form-item>
        <el-form-item v-if="!isEdit" label="有效期至" prop="expiry_date">
           <el-date-picker v-model="form.expiry_date" type="date" value-format="YYYY-MM-DD" placeholder="选择到期时间" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSave" :loading="submitting">保存</el-button>
      </template>
    </el-dialog>

    <!-- Quota/Validity Dialog -->
    <el-dialog v-model="quotaDialogVisible" title="配额与有效期管理" width="400px">
      <el-form :model="quotaForm" label-width="100px">
        <el-form-item label="当前配额">
          <el-input-number v-model="quotaForm.quota" :step="1000" />
        </el-form-item>
        <el-form-item label="短信额度">
          <el-input-number v-model="quotaForm.sms_quota" :step="100" />
        </el-form-item>
        <el-form-item label="有效期至">
          <el-date-picker v-model="quotaForm.expiry_date" type="date" value-format="YYYY-MM-DD" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="quotaDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSaveQuota" :loading="submitting">更新</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Plus, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getInstanceList, createInstance, updateInstance, updateInstanceStatus, getInstanceSsoUrl, deleteInstance } from '@/api/instance'

const loading = ref(false)
const submitting = ref(false)
const tableData = ref([])
const total = ref(0)
const queryParams = reactive({
  page: 1,
  limit: 10,
  keyword: ''
})

const dialogVisible = ref(false)
const quotaDialogVisible = ref(false)
const isEdit = ref(false)
const currentId = ref(null)
const formRef = ref(null)

const form = ref({
  name: '',
  domain: '',
  admin_email: '',
  password: '',
  phone: '',
  expiry_date: ''
})

const quotaForm = ref({
  quota: 0,
  sms_quota: 0,
  expiry_date: ''
})

const rules = reactive({
  name: [{ required: true, message: '请输入实例名称', trigger: 'blur' }],
  domain: [{ required: true, message: '请输入域名', trigger: 'blur' }],
  admin_email: [
    { required: true, message: '请输入登录邮箱', trigger: 'blur' },
    { type: 'email', message: '请输入正确的邮箱格式', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入登录密码', trigger: 'blur' },
    { min: 6, message: '密码长度至少6位', trigger: 'blur' }
  ],
  phone: [{ required: true, message: '请输入手机号', trigger: 'blur' }],
  expiry_date: [{ required: true, message: '请选择有效期', trigger: 'change' }]
})

const loadData = async () => {
  loading.value = true
  try {
    const res = await getInstanceList(queryParams)
    if (res.data) {
      tableData.value = res.data.data
      total.value = res.data.total
    }
  } catch (error) {
    console.error('Failed to load instances:', error)
  } finally {
    loading.value = false
  }
}

const openCreateDialog = () => {
  isEdit.value = false
  form.value = { 
    name: '', 
    domain: '', 
    admin_email: '', 
    password: '',
    phone: '',
    quota: 1000,
    sms_quota: 0,
    expiry_date: new Date(Date.now() + 30*24*60*60*1000).toISOString().split('T')[0] 
  }
  rules.password[0].required = true
  dialogVisible.value = true
}

const openEditDialog = (row) => {
  isEdit.value = true
  currentId.value = row.id
  form.value = { 
    ...row,
    password: ''
  }
  rules.password[0].required = false
  dialogVisible.value = true
}

const handleSave = async () => {
  if (!formRef.value) return
  
  await formRef.value.validate(async (valid) => {
    if (valid) {
      submitting.value = true
      try {
        if (isEdit.value) {
          const updates = { ...form.value }
          delete updates.create_time
          delete updates.update_time
          if (!updates.password) delete updates.password
          await updateInstance(currentId.value, updates)
          ElMessage.success('更新成功')
        } else {
          await createInstance({
            ...form.value,
            quota: 1000 // default init
          })
          ElMessage.success('创建成功')
        }
        dialogVisible.value = false
        loadData()
      } catch (error) {
        // Error handled by interceptor
      } finally {
        submitting.value = false
      }
    }
  })
}

const openQuotaDialog = (row) => {
  currentId.value = row.id
  quotaForm.value = { quota: row.quota, sms_quota: row.sms_quota, expiry_date: row.expiry_date }
  quotaDialogVisible.value = true
}

const handleSaveQuota = async () => {
  submitting.value = true
  try {
    const updates = { ...quotaForm.value }
    delete updates.create_time
    delete updates.update_time
    await updateInstance(currentId.value, updates)
    ElMessage.success('配额/有效期已更新')
    quotaDialogVisible.value = false
    loadData()
  } catch (error) {
    // Error handled by interceptor
  } finally {
    submitting.value = false
  }
}

const toggleStatus = (row) => {
  const action = row.status === 1 ? '停用' : '启用'
  ElMessageBox.confirm(`确定要${action}该实例吗？`, '提示', {
    type: 'warning'
  }).then(async () => {
    try {
      await updateInstanceStatus(row.id, row.status === 1 ? 0 : 1)
      ElMessage.success(`已${action}`)
      loadData()
    } catch (error) {
      // Error handled
    }
  })
}

const handleDelete = (row) => {
  ElMessageBox.confirm('确定要删除该实例吗？删除后将无法恢复。', '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning'
  }).then(async () => {
    try {
      await deleteInstance(row.id)
      ElMessage.success('删除成功')
      loadData()
    } catch (error) {
      // Error handled by interceptor
    }
  })
}

const enterTenantAdmin = async (row) => {
  if (!row?.id) return
  try {
    const res = await getInstanceSsoUrl(row.id)
    const url = res.data?.url
    if (url) {
      window.open(url, '_blank')
    }
  } catch {
  }
}

onMounted(() => {
  loadData()
})
</script>

<style scoped lang="scss">
.actions-bar {
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.table-card { border: none; }
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>
