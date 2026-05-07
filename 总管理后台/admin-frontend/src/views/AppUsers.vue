<template>
  <div class="app-users">
    <el-card class="table-card" shadow="never">
      <div class="table-tools">
        <el-input v-model="keyword" placeholder="搜索用户名" clearable style="max-width: 320px" @keyup.enter.native="fetchData" />
        <el-button type="primary" @click="fetchData" :loading="loading" style="margin-left: 8px">搜索</el-button>
      </div>
      <el-table :data="tableData" style="width: 100%" v-loading="loading">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="tenant_id" label="租户ID" min-width="120" />
        <el-table-column prop="username" label="用户名" min-width="140" />
        <el-table-column prop="register_time" label="注册时间" min-width="160" />
        <el-table-column prop="status" label="状态" width="140">
          <template #default="{ row }">
            <el-switch
              v-model="row.status"
              :active-value="1"
              :inactive-value="0"
              active-text="启用"
              inactive-text="停用"
              @change="handleStatusChange(row)"
            />
          </template>
        </el-table-column>
        <el-table-column prop="last_login_time" label="上次登录" min-width="160" />
        <el-table-column label="积分" min-width="140">
          <template #default="{ row }">
             <div style="font-size: 12px;">
               <div>套餐: {{ row.period_points }}</div>
               <div>额外: {{ row.extra_points }}</div>
             </div>
          </template>
        </el-table-column>
        <el-table-column prop="membership_expire" label="会员到期" min-width="160">
          <template #default="{ row }">
            {{ formatTime(row.membership_expire) }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-container" v-if="total > 0">
        <el-pagination
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <el-dialog v-model="dialogVisible" title="编辑用户" width="520px">
      <el-form :model="form" label-width="120px">
        <el-form-item label="ID">
          <el-input v-model="form.id" disabled />
        </el-form-item>
        <el-form-item label="租户ID">
          <el-input-number v-model="form.tenant_id" :min="0" disabled />
        </el-form-item>
        <el-form-item label="用户名">
          <el-input v-model="form.username" />
        </el-form-item>
        <el-form-item label="注册时间(timestamp)">
          <el-input-number v-model="form.register_time" :min="0" disabled />
        </el-form-item>
        <el-form-item label="套餐积分">
          <el-input-number v-model="form.period_points" :min="0" />
        </el-form-item>
        <el-form-item label="额外积分">
          <el-input-number v-model="form.extra_points" :min="0" />
        </el-form-item>
        <el-form-item label="会员到期">
          <el-date-picker v-model="form.membership_expire" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="上次登录(timestamp)">
          <el-input-number v-model="form.last_login_time" disabled />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSave" :loading="submitting">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getUserList, deleteUser, updateUserStatus, updateUser } from '@/api/appUsers'

const tableData = ref([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const keyword = ref('')
const dialogVisible = ref(false)
const submitting = ref(false)
const form = ref({
  id: null,
  tenant_id: 0,
  username: '',
  register_time: 0,
  period_points: 0,
  extra_points: 0,
  membership_expire: 0,
  status: 1,
  last_login_time: 0
})

const fetchData = async () => {
  loading.value = true
  try {
    const res = await getUserList({
      page: currentPage.value,
      limit: pageSize.value,
      keyword: keyword.value
    })
    tableData.value = res.data.data
    total.value = res.data.total
  } catch (error) {
    console.error(error)
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

const handleSizeChange = (val) => {
  pageSize.value = val
  fetchData()
}

const handleCurrentChange = (val) => {
  currentPage.value = val
  fetchData()
}

const handleDelete = (row) => {
  ElMessageBox.confirm(
    `确定要删除用户 "${row.username}" 吗？`,
    '警告',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  ).then(async () => {
    try {
      await deleteUser(row.id)
      ElMessage.success('已删除')
      fetchData()
    } catch (error) {
      console.error(error)
    }
  }).catch(() => {})
}

const handleStatusChange = async (row) => {
  try {
    await updateUserStatus(row.id, row.status)
    ElMessage.success('状态已更新')
  } catch (error) {
    row.status = row.status === 1 ? 0 : 1
    console.error(error)
  }
}

const handleEdit = (row) => {
  dialogVisible.value = true
  form.value = {
    id: row.id,
    tenant_id: row.tenant_id ?? 0,
    username: row.username ?? '',
    register_time: row.register_time ?? 0,
    period_points: row.period_points ?? 0,
    extra_points: row.extra_points ?? 0,
    membership_expire: row.membership_expire ? toDateTimeString(row.membership_expire) : '',
    status: row.status ?? 1,
    last_login_time: row.last_login_time ?? 0
  }
}

const handleSave = async () => {
  submitting.value = true
  try {
    const payload = { ...form.value }
    delete payload.last_login_time
    delete payload.tenant_id
    delete payload.register_time
    await updateUser(form.value.id, payload)
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchData()
  } catch (error) {
    console.error(error)
  } finally {
    submitting.value = false
  }
}

const formatTime = (ts) => {
  if (!ts) return '-'
  try {
    const d = new Date(ts * 1000)
    const yyyy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    const hh = String(d.getHours()).padStart(2, '0')
    const mi = String(d.getMinutes()).padStart(2, '0')
    const ss = String(d.getSeconds()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`
  } catch {
    return String(ts)
  }
}

const toDateTimeString = (ts) => {
  if (!ts) return ''
  try {
    const d = new Date(ts * 1000)
    const yyyy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    const hh = String(d.getHours()).padStart(2, '0')
    const mi = String(d.getMinutes()).padStart(2, '0')
    const ss = String(d.getSeconds()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd} ${hh}:${mi}:${ss}`
  } catch {
    return ''
  }
}
</script>

<style scoped lang="scss">
.app-users {
  .table-card { border: none; }
  .table-tools { display: flex; gap: 8px; margin-bottom: 12px; }
  .pagination-container { margin-top: 20px; display: flex; justify-content: flex-end; }
}
</style>
