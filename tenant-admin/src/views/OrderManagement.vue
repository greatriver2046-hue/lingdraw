<template>
  <div class="order-management">
    <div class="page-header-actions">
      <el-button @click="resetFilters">重置</el-button>
      <el-button type="primary" @click="fetchOrders">查询</el-button>
    </div>

    <el-card class="box-card" shadow="never">
      <el-form :inline="true" :model="filters" class="filter-form">
        <el-form-item label="订单号">
          <el-input v-model="filters.order_no" placeholder="输入订单号" clearable style="width: 220px" />
        </el-form-item>
        <el-form-item label="用户ID">
          <el-input v-model="filters.user_id" placeholder="输入用户ID" clearable style="width: 140px" />
        </el-form-item>
        <el-form-item label="支付方式">
          <el-select v-model="filters.payment_method" placeholder="全部" clearable style="width: 140px">
            <el-option label="微信" value="wechat" />
            <el-option label="支付宝" value="alipay" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filters.status" placeholder="全部" clearable style="width: 140px">
            <el-option label="待支付" :value="0" />
            <el-option label="已支付" :value="1" />
            <el-option label="已取消" :value="-1" />
            <el-option label="已退款" :value="3" />
          </el-select>
        </el-form-item>
      </el-form>

      <el-table
        :data="tableData"
        style="width: 100%"
        :header-cell-style="{ background: '#f5f7fa', color: '#606266' }"
        v-loading="loading"
      >
        <el-table-column prop="order_no" label="订单号" min-width="190" />
        <el-table-column label="用户" min-width="180">
          <template #default="scope">
            <div style="font-size: 12px; line-height: 18px;">
              <div>{{ scope.row.user_username || '-' }} ({{ scope.row.user_id }})</div>
              <div style="color: #909399;">{{ scope.row.user_email || scope.row.user_phone || '' }}</div>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="package_name" label="套餐" min-width="150" />
        <el-table-column prop="amount" label="金额" width="110" />
        <el-table-column label="支付方式" width="110">
          <template #default="scope">
            {{ scope.row.payment_method === 'wechat' ? '微信' : scope.row.payment_method === 'alipay' ? '支付宝' : scope.row.payment_method || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="scope">
            <el-tag :type="statusTagType(scope.row.status)" effect="plain">
              {{ statusLabel(scope.row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="transaction_id" label="流水号" min-width="180" />
        <el-table-column label="支付时间" min-width="160">
          <template #default="scope">
            {{ scope.row.pay_time || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="创建时间" min-width="160">
          <template #default="scope">
            {{ scope.row.create_time || scope.row.created_at || '-' }}
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import request from '@/utils/request'

const loading = ref(false)
const tableData = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

const filters = ref({
  order_no: '',
  user_id: '',
  payment_method: '',
  status: ''
})

const statusLabel = (status) => {
  const s = Number(status)
  if (s === 0) return '待支付'
  if (s === 1) return '已支付'
  if (s === 2) return '已取消'
  if (s === 3) return '已退款'
  if (s === -1) return '已取消'
  return String(status ?? '')
}

const statusTagType = (status) => {
  const s = Number(status)
  if (s === 1) return 'success'
  if (s === 0) return 'warning'
  if (s === 2 || s === -1) return 'info'
  if (s === 3) return 'danger'
  return ''
}

const fetchOrders = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/orders', {
      params: {
        page: currentPage.value,
        limit: pageSize.value,
        order_no: filters.value.order_no || undefined,
        user_id: filters.value.user_id || undefined,
        payment_method: filters.value.payment_method || undefined,
        status: filters.value.status === '' ? undefined : filters.value.status
      }
    })
    tableData.value = res.data.data
    total.value = res.data.total
  } finally {
    loading.value = false
  }
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchOrders()
}

const resetFilters = () => {
  filters.value = { order_no: '', user_id: '', payment_method: '', status: '' }
  currentPage.value = 1
  fetchOrders()
}

onMounted(() => {
  fetchOrders()
})
</script>

<style scoped>
.order-management {
  padding: 20px;
}
.page-header-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-bottom: 20px;
}
.box-card {
  border: none;
}
.filter-form {
  margin-bottom: 12px;
}
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}
</style>

