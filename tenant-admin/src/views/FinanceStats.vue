<template>
  <div class="finance-stats">
    
    <el-row :gutter="20" style="margin-bottom: 20px;">
      <el-col :span="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-orange"></span>
                <span class="stat-label">今日收益</span>
              </div>
            </div>
            <div class="stat-value">¥ {{ formatNumber(todayIncome) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-blue"></span>
                <span class="stat-label">本月收益</span>
              </div>
            </div>
            <div class="stat-value">¥ {{ formatNumber(monthIncome) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-green"></span>
                <span class="stat-label">剩余短信条数</span>
              </div>
              <el-button link type="primary" size="small" @click="handleRechargeSMS">充值</el-button>
            </div>
            <div class="stat-value">{{ formatInteger(smsCount) }}</div>
          </div>
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="never" class="box-card stat-card">
          <div class="stat-content">
            <div class="stat-header">
              <div class="label-wrapper">
                <span class="dot dot-purple"></span>
                <span class="stat-label">剩余可用点数</span>
              </div>
              <el-button link type="primary" size="small" @click="handleRechargePoints">充值</el-button>
            </div>
            <div class="stat-value">{{ formatInteger(pointsCount) }}</div>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="20">
      <el-col :span="12">
        <el-card header="收入趋势" shadow="never" class="box-card">
          <div ref="incomeChart" style="width: 100%; height: 300px;"></div>
        </el-card>
      </el-col>
      <el-col :span="12">
        <el-card header="用户消费分布" shadow="never" class="box-card">
          <div ref="usageChart" style="width: 100%; height: 300px;"></div>
        </el-card>
      </el-col>
    </el-row>
    
    <el-card header="近期交易" style="margin-top: 20px;" shadow="never" class="box-card">
      <el-table :data="transactions" style="width: 100%" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="date" label="日期" width="180" />
        <el-table-column prop="user" label="用户" min-width="150" />
        <el-table-column prop="type" label="类型" min-width="120" />
        <el-table-column prop="amount" label="金额" min-width="100" />
      </el-table>
    </el-card>

    <el-card header="积分消耗记录" style="margin-top: 20px;" shadow="never" class="box-card">
      <el-table
        :data="pointsConsumptions"
        style="width: 100%"
        :header-cell-style="{ background: '#f5f7fa', color: '#606266' }"
        v-loading="pointsConsumptionsLoading"
      >
        <el-table-column label="时间" min-width="170">
          <template #default="scope">
            {{ scope.row.create_time || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="用户" min-width="170">
          <template #default="scope">
            {{ scope.row.user_username ? `${scope.row.user_username} (${scope.row.user_id || '-'})` : (scope.row.user_id ? `用户#${scope.row.user_id}` : '-') }}
          </template>
        </el-table-column>
        <el-table-column label="消耗点数" width="120">
          <template #default="scope">
            {{ formatInteger(Math.abs(Number(scope.row.amount || 0))) }}
          </template>
        </el-table-column>
        <el-table-column label="余额" width="120">
          <template #default="scope">
            {{ formatInteger(Number(scope.row.balance_after || 0)) }}
          </template>
        </el-table-column>
        <el-table-column prop="type" label="类型" min-width="140" />
        <el-table-column prop="description" label="描述" min-width="220" />
        <el-table-column prop="ref_id" label="关联ID" min-width="160" />
      </el-table>

      <div class="pagination-container">
        <el-pagination
          background
          layout="prev, pager, next"
          :total="pointsConsumptionsTotal"
          :current-page="pointsConsumptionsPage"
          :page-size="pointsConsumptionsPageSize"
          @current-change="handlePointsConsumptionsPageChange"
        />
      </div>
    </el-card>

    <el-dialog v-model="customerServiceDialogVisible" title="联系客服" width="420px" align-center>
      <div v-loading="customerServiceLoading" style="display: flex; flex-direction: column; align-items: center; gap: 12px; padding: 10px 0;">
        <el-image
          v-if="customerServiceQr"
          :src="customerServiceQr"
          :preview-src-list="[customerServiceQr]"
          preview-teleported
          fit="contain"
          style="width: 260px; height: 260px; border-radius: 8px; overflow: hidden;"
        />
        <div v-else style="color: #909399;">未配置客服二维码</div>
        <div style="color: #606266; font-size: 12px;">微信扫码联系客服</div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import * as echarts from 'echarts'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const incomeChart = ref(null)
const usageChart = ref(null)

const smsCount = ref(0)
const pointsCount = ref(0)
const todayIncome = ref(0)
const monthIncome = ref(0)
const incomeTrend = ref({ dates: [], values: [] })
const usageDistribution = ref([])

const transactions = ref([])

const pointsConsumptionsLoading = ref(false)
const pointsConsumptions = ref([])
const pointsConsumptionsTotal = ref(0)
const pointsConsumptionsPage = ref(1)
const pointsConsumptionsPageSize = ref(10)

const customerServiceDialogVisible = ref(false)
const customerServiceLoading = ref(false)
const customerServiceQr = ref('')

const fetchCustomerService = async () => {
  customerServiceLoading.value = true
  try {
    const res = await request.get('/public/customer_service')
    const data = res.data || {}
    customerServiceQr.value = data.wechat_qr || ''
  } finally {
    customerServiceLoading.value = false
  }
}

const openCustomerServicePanel = async () => {
  customerServiceDialogVisible.value = true
  await fetchCustomerService()
}

const handleRechargeSMS = () => {
  openCustomerServicePanel()
}

const handleRechargePoints = () => {
  openCustomerServicePanel()
}

const formatNumber = (value) => {
  if (value === undefined || value === null) return '0'
  return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const formatInteger = (value) => {
   if (value === undefined || value === null) return '0'
   return value.toLocaleString('en-US')
}

let incomeInstance = null
let usageInstance = null

const fetchStats = async () => {
  const res = await request.get('/admin/finance/stats', { params: { days: 7 } })
  const data = res.data || {}

  todayIncome.value = Number(data.today_income || 0)
  monthIncome.value = Number(data.month_income || 0)
  smsCount.value = Number(data.sms_count || 0)
  pointsCount.value = Number(data.points_count || 0)

  incomeTrend.value = data.income_trend || { dates: [], values: [] }
  usageDistribution.value = Array.isArray(data.usage_distribution) ? data.usage_distribution : []

  const recent = Array.isArray(data.recent_transactions) ? data.recent_transactions : []
  transactions.value = recent.map((row) => {
    const userLabel = row.user_username ? `${row.user_username} (${row.user_id || '-'})` : (row.user_id ? `用户#${row.user_id}` : '-')
    const typeLabel = row.package_name || '订单'
    const dateLabel = row.pay_time || row.created_at || '-'
    const amountLabel = `¥ ${formatNumber(Number(row.amount || 0))}`
    return { date: dateLabel, user: userLabel, type: typeLabel, amount: amountLabel }
  })
}

const fetchPointsConsumptions = async () => {
  pointsConsumptionsLoading.value = true
  try {
    const res = await request.get('/admin/finance/points_consumptions', {
      params: {
        page: pointsConsumptionsPage.value,
        page_size: pointsConsumptionsPageSize.value
      }
    })
    const data = res.data || {}
    pointsConsumptions.value = Array.isArray(data.list) ? data.list : []
    pointsConsumptionsTotal.value = Number(data.total || 0)
  } finally {
    pointsConsumptionsLoading.value = false
  }
}

const handlePointsConsumptionsPageChange = (page) => {
  pointsConsumptionsPage.value = page
  fetchPointsConsumptions()
}

const renderCharts = () => {
  if (incomeChart.value && !incomeInstance) {
    incomeInstance = echarts.init(incomeChart.value)
  }
  if (usageChart.value && !usageInstance) {
    usageInstance = echarts.init(usageChart.value)
  }

  if (incomeInstance) {
    incomeInstance.setOption({
      tooltip: { trigger: 'axis' },
      xAxis: { type: 'category', data: incomeTrend.value?.dates || [] },
      yAxis: { type: 'value' },
      series: [{ data: incomeTrend.value?.values || [], type: 'line', smooth: true }]
    })
  }

  if (usageInstance) {
    const pieData = (usageDistribution.value || []).map((d) => ({
      name: d.name ?? '未知',
      value: Number(d.value || 0)
    }))
    usageInstance.setOption({
      tooltip: { trigger: 'item' },
      legend: { top: '5%', left: 'center' },
      series: [
        {
          name: '套餐收入',
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
  incomeInstance?.resize()
  usageInstance?.resize()
}

onMounted(async () => {
  try {
    await fetchStats()
    await fetchPointsConsumptions()
    await nextTick()
    renderCharts()
    window.addEventListener('resize', handleResize)
  } catch (e) {
    ElMessage.error(e?.message || '加载财务数据失败')
  }
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  incomeInstance?.dispose()
  usageInstance?.dispose()
  incomeInstance = null
  usageInstance = null
})
</script>

<style scoped>
.finance-stats {
  padding: 20px;
}
.box-card {
  border: none;
}
.pagination-container {
  margin-top: 20px;
  display: flex;
  justify-content: flex-end;
}

.stat-card {
  background-color: #f5f7fa; /* Light grey background */
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
  padding-left: 14px; /* 6px dot + 8px margin */
}

</style>
