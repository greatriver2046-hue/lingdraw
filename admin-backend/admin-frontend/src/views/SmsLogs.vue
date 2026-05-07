<template>
  <div class="sms-logs">
    <div class="page-header">
      <div class="header-actions">
        <el-input v-model="filters.phone" placeholder="手机号" clearable style="width: 180px" @keyup.enter="fetchData" />
        <el-input v-model="filters.keyword" placeholder="短信内容/IP" clearable style="width: 220px" @keyup.enter="fetchData" />
        <el-select v-model="filters.type" placeholder="类型" clearable style="width: 160px">
          <el-option label="全部" value="" />
          <el-option label="注册账号" value="register" />
          <el-option label="找回密码" value="forgot_password" />
          <el-option label="手机登录" value="phone_login" />
          <el-option label="绑定手机号" value="bind_phone" />
        </el-select>
        <el-date-picker v-model="filters.range" type="daterange" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" style="width: 320px" />
        <el-button type="primary" @click="fetchData">查询</el-button>
        <el-button @click="resetFilters">重置</el-button>
      </div>
    </div>

    <el-card class="table-card" shadow="never">
      <el-table :data="tableData" style="width: 100%" v-loading="loading" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="create_time" label="时间" min-width="170">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
        <el-table-column prop="phone" label="手机号" min-width="140" />
        <el-table-column prop="content" label="短信内容" min-width="280" show-overflow-tooltip />
        <el-table-column prop="user_ip" label="用户IP" min-width="140" />
        <el-table-column prop="user_id" label="用户ID" min-width="100">
          <template #default="{ row }">{{ row.user_id || '-' }}</template>
        </el-table-column>
        <el-table-column prop="type" label="类型" min-width="120">
          <template #default="{ row }">{{ typeLabelMap[row.type] || row.type || '-' }}</template>
        </el-table-column>
        <el-table-column prop="status" label="状态" min-width="100">
          <template #default="{ row }">
            <el-tag :type="row.status === 'success' ? 'success' : 'danger'">
              {{ row.status === 'success' ? '成功' : '失败' }}
            </el-tag>
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
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { getSmsLogs } from '@/api/smsLogs'

const tableData = ref([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

const filters = reactive({
  phone: '',
  keyword: '',
  type: '',
  range: []
})

const typeLabelMap = {
  register: '注册账号',
  forgot_password: '找回密码',
  phone_login: '手机登录',
  bind_phone: '绑定手机号'
}

const fetchData = async () => {
  loading.value = true
  try {
    const params = {
      page: currentPage.value,
      limit: pageSize.value
    }
    if (filters.phone) params.phone = filters.phone
    if (filters.keyword) params.keyword = filters.keyword
    if (filters.type) params.type = filters.type
    if (filters.range?.length === 2) {
      params.start_time = filters.range[0]
      params.end_time = filters.range[1]
    }
    const res = await getSmsLogs(params)
    tableData.value = res.data.data
    total.value = res.data.total
  } finally {
    loading.value = false
  }
}

const resetFilters = () => {
  filters.phone = ''
  filters.keyword = ''
  filters.type = ''
  filters.range = []
  currentPage.value = 1
  fetchData()
}

const handleSizeChange = (val) => {
  pageSize.value = val
  fetchData()
}

const handleCurrentChange = (val) => {
  currentPage.value = val
  fetchData()
}

const formatTime = (ts) => {
  if (!ts) return ''
  try {
    const d = new Date(ts * 1000)
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const da = String(d.getDate()).padStart(2, '0')
    const hh = String(d.getHours()).padStart(2, '0')
    const mm = String(d.getMinutes()).padStart(2, '0')
    const ss = String(d.getSeconds()).padStart(2, '0')
    return `${y}-${m}-${da} ${hh}:${mm}:${ss}`
  } catch {
    return String(ts)
  }
}

onMounted(fetchData)
</script>

<style scoped lang="scss">
.sms-logs {
  .page-header {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-bottom: 24px;

    .header-actions {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
  }

  .table-card {
    border: none;
  }

  .pagination-container {
    margin-top: 20px;
    display: flex;
    justify-content: flex-end;
  }
}
</style>
