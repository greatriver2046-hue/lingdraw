<template>
  <div class="dashboard-view">
    <el-row :gutter="20" class="stats-row" v-loading="loading">
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-blue"></span>
                <span class="stat-label">总实例数</span>
              </div>
            </div>
            <div class="stat-value">{{ formatInteger(totalInstances) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-green"></span>
                <span class="stat-label">活跃用户</span>
              </div>
            </div>
            <div class="stat-value">{{ formatInteger(activeUsers) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-orange"></span>
                <span class="stat-label">今日生图量</span>
              </div>
            </div>
            <div class="stat-value">{{ formatInteger(todayImageCount) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-purple"></span>
                <span class="stat-label">总收入 (本月)</span>
              </div>
            </div>
            <div class="stat-value">¥ {{ formatNumber(monthIncome) }}</div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20">
      <el-col :xs="24" :md="12">
        <el-card header="近30天使用趋势" shadow="never" class="box-card">
          <div ref="trendChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
      <el-col :xs="24" :md="12">
        <el-card header="资源类型分布" shadow="never" class="box-card">
          <div ref="distChartRef" class="chart-container"></div>
        </el-card>
      </el-col>
    </el-row>

    <el-card header="近期交易" shadow="never" class="box-card">
      <el-table :data="transactions" style="width: 100%" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="date" label="日期" width="180" />
        <el-table-column prop="user" label="用户" min-width="150" />
        <el-table-column prop="type" label="类型" min-width="120" />
        <el-table-column label="状态" width="110">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" effect="plain">
              {{ statusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="amount" label="金额" min-width="100" />
      </el-table>
      <div class="pagination-container" v-if="transactionTotal > transactionPageSize">
        <el-pagination
          background
          layout="prev, pager, next"
          :total="transactionTotal"
          :current-page="transactionPage"
          :page-size="transactionPageSize"
          @current-change="handleTransactionPageChange"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import * as echarts from 'echarts'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const loading = ref(false)

const totalInstances = ref(0)
const activeUsers = ref(0)
const todayImageCount = ref(0)
const monthIncome = ref(0)
const usageTrend = ref({ dates: [], values: [] })
const usageDistribution = ref([])
const transactions = ref([])
const transactionTotal = ref(0)
const transactionPage = ref(1)
const transactionPageSize = ref(10)

const trendChartRef = ref(null)
const distChartRef = ref(null)
let trendInstance = null
let distInstance = null

const formatInteger = (value) => {
  if (value === undefined || value === null) return '0'
  const n = Number(value)
  if (!Number.isFinite(n)) return '0'
  return Math.trunc(n).toLocaleString('en-US')
}

const formatNumber = (value) => {
  const n = Number(value || 0)
  if (!Number.isFinite(n)) return '0.00'
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatTimeFromSeconds = (ts) => {
  if (!ts) return '-'
  const n = Number(ts)
  if (!Number.isFinite(n)) return '-'
  const d = new Date(n * 1000)
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const da = String(d.getDate()).padStart(2, '0')
  const hh = String(d.getHours()).padStart(2, '0')
  const mm = String(d.getMinutes()).padStart(2, '0')
  const ss = String(d.getSeconds()).padStart(2, '0')
  return `${y}-${m}-${da} ${hh}:${mm}:${ss}`
}

const statusLabel = (status) => {
  const s = Number(status)
  if (s === 0) return '待支付'
  if (s === 1) return '已支付'
  if (s === 2 || s === -1) return '已取消'
  if (s === 3) return '已退款'
  if (status === undefined || status === null || status === '') return '-'
  return String(status)
}

const statusTagType = (status) => {
  const s = Number(status)
  if (s === 1) return 'success'
  if (s === 0) return 'warning'
  if (s === 2 || s === -1) return 'info'
  if (s === 3) return 'danger'
  return ''
}

const fetchStats = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/dashboard/stats', {
      params: {
        days: 30,
        order_page: transactionPage.value,
        order_limit: transactionPageSize.value
      }
    })
    const data = res.data || {}

    totalInstances.value = Number(data.total_instances || 0)
    activeUsers.value = Number(data.active_users || 0)
    todayImageCount.value = Number(data.today_image_count || 0)
    monthIncome.value = Number(data.month_income || 0)

    usageTrend.value = data.usage_trend || { dates: [], values: [] }
    usageDistribution.value = Array.isArray(data.usage_distribution) ? data.usage_distribution : []

    const pager = data.recent_transactions || {}
    const recent = Array.isArray(pager.data) ? pager.data : []
    transactionTotal.value = Number(pager.total || 0)

    transactions.value = recent.map((row) => {
      const tenantId = row?.tenant_id ?? '-'
      const userId = row?.user_id ?? '-'
      const userLabel = tenantId !== '-' || userId !== '-' ? `T${tenantId} / U${userId}` : '-'

      const dateVal = row?.pay_time || row?.paid_at || row?.created_at || row?.create_time || '-'
      const dateLabel = typeof dateVal === 'number' ? formatTimeFromSeconds(dateVal) : String(dateVal || '-')

      const typeLabel = row?.order_no ? `订单 ${row.order_no}` : (row?.payment_method ? String(row.payment_method) : '订单')

      const amountVal = row?.amount ?? row?.total_amount ?? row?.pay_amount
      const amountLabel = amountVal === undefined || amountVal === null ? '-' : `¥ ${formatNumber(Number(amountVal || 0))}`

      return { date: dateLabel, user: userLabel, type: typeLabel, status: row?.status, amount: amountLabel }
    })
  } finally {
    loading.value = false
  }
}

const handleTransactionPageChange = (page) => {
  transactionPage.value = page
  fetchStats()
}

const renderCharts = () => {
  if (trendChartRef.value && !trendInstance) {
    trendInstance = echarts.init(trendChartRef.value)
  }
  if (distChartRef.value && !distInstance) {
    distInstance = echarts.init(distChartRef.value)
  }

  if (trendInstance) {
    trendInstance.setOption({
      tooltip: { trigger: 'axis' },
      grid: { left: '3%', right: '4%', bottom: '3%', containLabel: true },
      xAxis: { type: 'category', boundaryGap: false, data: usageTrend.value?.dates || [] },
      yAxis: { type: 'value' },
      series: [{ name: '生图量', type: 'line', smooth: true, areaStyle: {}, data: usageTrend.value?.values || [] }]
    })
  }

  if (distInstance) {
    const pieData = (usageDistribution.value || []).map((d) => ({
      name: d?.name ?? '未知',
      value: Number(d?.value || 0)
    }))
    distInstance.setOption({
      tooltip: { trigger: 'item' },
      legend: { top: '5%', left: 'center' },
      series: [
        {
          name: '资源类型',
          type: 'pie',
          radius: ['40%', '70%'],
          avoidLabelOverlap: false,
          itemStyle: { borderRadius: 10, borderColor: '#fff', borderWidth: 2 },
          label: { show: false, position: 'center' },
          emphasis: { label: { show: true, fontSize: 28, fontWeight: 'bold' } },
          labelLine: { show: false },
          data: pieData
        }
      ]
    })
  }
}

const handleResize = () => {
  trendInstance?.resize()
  distInstance?.resize()
}

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  trendInstance?.dispose()
  distInstance?.dispose()
  trendInstance = null
  distInstance = null
})

onMounted(async () => {
  try {
    await fetchStats()
    await nextTick()
    renderCharts()
    window.addEventListener('resize', handleResize)
  } catch (e) {
    ElMessage.error(e?.message || '加载看板数据失败')
  }
})
</script>

<style scoped lang="scss">
.dashboard-view {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.box-card {
  border: none;
}

.stats-row {
  margin-bottom: 0;
}

.stat-card {
  background-color: #f5f7fa;
}

.stat-content {
  display: flex;
  flex-direction: column;
}

.stat-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 10px;
}

.label-wrapper {
  display: flex;
  align-items: center;
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 8px;
  display: inline-block;
  border: 2px solid;
  background-color: transparent;
}

.dot-orange { border-color: #e6a23c; }
.dot-blue { border-color: #409eff; }
.dot-green { border-color: #67c23a; }
.dot-purple { border-color: #722ed1; }

.stat-label {
  font-size: 12px;
  color: #909399;
}

.stat-value {
  font-size: 24px;
  font-weight: bold;
  color: #303133;
  padding-left: 14px;
}

.chart-container {
  width: 100%;
  height: 320px;
}

.pagination-container {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
</style>
