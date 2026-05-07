<template>
  <el-dialog
    v-model="visible"
    title="积分记录"
    width="1000px"
    class="points-history-dialog"
    destroy-on-close
    align-center
  >
    <div class="dialog-content">
      <div class="header-summary">
        <div class="summary-item">
          <div class="summary-main">
            <span class="label">当前积分</span>
            <span class="value">{{ formatPoints(totalPoints) }}</span>
          </div>
          <div class="summary-sub">
            <span class="sub-value">套餐: {{ formatPoints(periodPoints) }}</span>
            <span class="sub-value">额外: {{ formatPoints(extraPoints) }}</span>
          </div>
        </div>
      </div>

      <el-tabs v-model="activeTab" class="points-tabs" stretch>
        <el-tab-pane label="积分记录" name="points">
          <el-table
            v-loading="loading"
            :data="logs"
            style="width: 100%"
            height="400"
            :header-cell-style="{ background: '#f5f7fa', color: '#606266', fontWeight: '600' }"
          >
            <el-table-column prop="create_time" label="时间" width="180">
              <template #default="{ row }">
                <span class="time-text">{{ row.create_time }}</span>
              </template>
            </el-table-column>

            <el-table-column prop="type" label="类型" min-width="160">
              <template #default="{ row }">
                <div class="type-tag" :class="row.type">
                  {{ formatType(row.type) }}
                </div>
              </template>
            </el-table-column>

            <el-table-column prop="amount" label="变动" width="120">
              <template #default="{ row }">
                <span :class="['amount', row.amount > 0 ? 'plus' : 'minus']">
                  {{ row.amount > 0 ? '+' : '' }}{{ formatPoints(row.amount) }}
                </span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-container">
            <el-pagination
              v-model:current-page="currentPage"
              v-model:page-size="pageSize"
              :total="total"
              layout="total, prev, pager, next"
              :page-size="pageSize"
              @current-change="fetchLogs"
              small
              background
            />
          </div>
        </el-tab-pane>

        <el-tab-pane label="订单列表" name="orders">
          <el-table
            v-loading="ordersLoading"
            :data="orders"
            style="width: 100%"
            height="400"
            :header-cell-style="{ background: '#f5f7fa', color: '#606266', fontWeight: '600' }"
          >
            <el-table-column type="expand">
              <template #default="{ row }">
                <el-descriptions :column="2" border class="order-desc">
                  <el-descriptions-item label="订单号">{{ row.order_no || '-' }}</el-descriptions-item>
                  <el-descriptions-item label="状态">{{ formatOrderStatus(row.status) }}</el-descriptions-item>
                  <el-descriptions-item label="套餐">{{ row.package_name || '-' }}</el-descriptions-item>
                  <el-descriptions-item label="套餐积分">{{ formatPointsOrDash(row.package_points) }}</el-descriptions-item>
                  <el-descriptions-item label="时长(天)">{{ row.package_duration_days ?? '-' }}</el-descriptions-item>
                  <el-descriptions-item label="金额">{{ formatAmount(row.amount) }}</el-descriptions-item>
                  <el-descriptions-item label="支付方式">{{ formatPaymentMethod(row.payment_method) }}</el-descriptions-item>
                  <el-descriptions-item label="交易号">{{ row.transaction_id || '-' }}</el-descriptions-item>
                  <el-descriptions-item label="下单时间">{{ row.created_at || '-' }}</el-descriptions-item>
                  <el-descriptions-item label="支付时间">{{ row.pay_time || '-' }}</el-descriptions-item>
                </el-descriptions>
              </template>
            </el-table-column>

            <el-table-column prop="created_at" label="下单时间" width="180">
              <template #default="{ row }">
                <span class="time-text">{{ row.created_at }}</span>
              </template>
            </el-table-column>

            <el-table-column prop="order_no" label="订单号" min-width="220" show-overflow-tooltip />

            <el-table-column prop="package_name" label="套餐" min-width="140" show-overflow-tooltip />

            <el-table-column prop="amount" label="金额" width="110">
              <template #default="{ row }">
                <span class="money-text">{{ formatAmount(row.amount) }}</span>
              </template>
            </el-table-column>

            <el-table-column prop="status" label="状态" width="110">
              <template #default="{ row }">
                <span class="status-tag" :class="getStatusClass(row.status)">
                  {{ formatOrderStatus(row.status) }}
                </span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pagination-container">
            <el-pagination
              v-model:current-page="ordersCurrentPage"
              v-model:page-size="ordersPageSize"
              :total="ordersTotal"
              layout="total, prev, pager, next"
              :page-size="ordersPageSize"
              @current-change="fetchOrders"
              small
              background
            />
          </div>
        </el-tab-pane>
      </el-tabs>
    </div>
  </el-dialog>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import request from '@/utils/request'
import { useUserStore } from '@/stores/user'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])

const userStore = useUserStore()
const toPointsNumber = (val) => {
  const n = Number(val)
  return Number.isFinite(n) ? n : 0
}
const formatPoints = (val) => String(Math.trunc(toPointsNumber(val)))
const formatPointsOrDash = (val) => {
  if (val === null || val === undefined || val === '') return '-'
  return formatPoints(val)
}
const periodPoints = computed(() => toPointsNumber(userStore.userInfo.period_points))
const extraPoints = computed(() => toPointsNumber(userStore.userInfo.extra_points))
const totalPoints = computed(() => periodPoints.value + extraPoints.value)
const logs = ref([])
const loading = ref(false)
const activeTab = ref('points')
const currentPage = ref(1)
const pageSize = ref(10) // Smaller page size for modal
const total = ref(0)

const orders = ref([])
const ordersLoading = ref(false)
const ordersCurrentPage = ref(1)
const ordersPageSize = ref(10)
const ordersTotal = ref(0)

const visible = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})

const typeMap = {
  'llm': 'AI 对话',
  'image': 'AI 绘画',
  'video': 'AI 视频',
  'purchase': '购买套餐',
  'recharge': '充值',
  'refund': '退款',
  'system': '系统调整'
}

const formatType = (type) => {
  return typeMap[type] || type
}

const fetchLogs = async () => {
  loading.value = true
  try {
    const res = await request.get('/api/v1/user/points/log', {
      params: {
        page: currentPage.value,
        limit: pageSize.value
      }
    })
    
    if (res.data.code === 200) {
      const data = res.data.data
      logs.value = data.data
      total.value = data.total
    }
  } catch (error) {
    console.error('Failed to fetch logs', error)
  } finally {
    loading.value = false
  }
}

const fetchOrders = async () => {
  ordersLoading.value = true
  try {
    const res = await request.get('/api/v1/order/list', {
      params: {
        page: ordersCurrentPage.value,
        limit: ordersPageSize.value
      }
    })

    if (res.data.code === 200) {
      const data = res.data.data
      orders.value = data.data
      ordersTotal.value = data.total
    }
  } catch (error) {
    console.error('Failed to fetch orders', error)
  } finally {
    ordersLoading.value = false
  }
}

const formatAmount = (amount) => {
  if (amount === null || amount === undefined || amount === '') return '-'
  const n = Number(amount)
  if (Number.isNaN(n)) return String(amount)
  return `¥${n.toFixed(2)}`
}

const formatPaymentMethod = (method) => {
  const m = String(method || '')
  if (m === 'wechat') return '微信'
  if (m === 'alipay') return '支付宝'
  if (!m) return '-'
  return m
}

const formatOrderStatus = (status) => {
  const s = Number(status)
  if (s === 1) return '已支付'
  if (s === 0) return '待支付'
  if (s === -1) return '已取消'
  if (Number.isNaN(s)) return String(status || '-')
  return `状态${s}`
}

const getStatusClass = (status) => {
  const s = Number(status)
  if (s === 1) return 'paid'
  if (s === 0) return 'pending'
  if (s === -1) return 'cancelled'
  return 'unknown'
}

watch(visible, (val) => {
  if (val) {
    activeTab.value = 'points'
    currentPage.value = 1
    ordersCurrentPage.value = 1
    fetchLogs()
    fetchOrders()
    userStore.getUserInfo() // Refresh points when opening
  }
})
</script>

<style scoped lang="scss">
.dialog-content {
  padding: 0 4px;
}

.header-summary {
  margin-bottom: 20px;
  padding: 16px;
  background-color: #f8f9fa;
  border-radius: 8px;
  display: flex;
  align-items: center;
  
  .summary-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
    
    .summary-main {
      display: flex;
      align-items: baseline;
      gap: 12px;
    }
    
    .summary-sub {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .label {
      font-size: 14px;
      color: #606266;
    }
    
    .value {
      font-size: 28px;
      font-weight: 600;
      color: #409eff;
      font-family: 'Product Sans', sans-serif;
    }
    
    .sub-value {
      font-size: 13px;
      color: #909399;
    }
  }
}

.time-text {
  color: #606266;
  font-size: 13px;
}

.type-tag {
  display: inline-flex;
  align-items: center;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  background-color: #f0f2f5;
  color: #909399;
  
  &.recharge {
    background-color: #f0f9eb;
    color: #67c23a;
  }
  
  &.refund {
    background-color: #fdf6ec;
    color: #e6a23c;
  }
  
  &.image, &.video {
    background-color: #ecf5ff;
    color: #409eff;
  }

  &.llm {
    background-color: #f4f4f5;
    color: #909399;
  }
}

.amount {
  font-weight: 600;
  font-family: 'Product Sans', sans-serif;
  font-size: 14px;
  
  &.plus {
    color: #67c23a;
  }
  
  &.minus {
    color: #f56c6c;
  }
}

.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}

.points-tabs {
  margin-top: 4px;
}

.money-text {
  font-weight: 600;
  font-family: 'Product Sans', sans-serif;
  color: #1f1f1f;
}

.status-tag {
  display: inline-flex;
  align-items: center;
  padding: 4px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  background-color: #f0f2f5;
  color: #909399;
}

.status-tag.paid {
  background-color: #f0f9eb;
  color: #67c23a;
}

.status-tag.pending {
  background-color: #ecf5ff;
  color: #409eff;
}

.status-tag.cancelled {
  background-color: #fef0f0;
  color: #f56c6c;
}

.order-desc {
  width: 100%;
}
</style>

<style lang="scss">
.points-history-dialog {
  border-radius: 12px !important;
  overflow: hidden;
  
  .el-dialog__header {
    margin: 0;
    padding: 20px 24px;
    border-bottom: 1px solid #f0f0f0;
    
    .el-dialog__title {
      font-weight: 600;
      font-size: 18px;
    }
  }
  
  .el-dialog__body {
    padding: 24px;
  }
}
</style>
