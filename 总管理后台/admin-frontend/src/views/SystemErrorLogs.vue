<template>
  <div class="system-error-logs">
    <div class="page-header">
      <div class="header-actions">
        <el-input v-model="filters.keyword" placeholder="关键字" clearable style="width: 220px" @keyup.enter="fetchData" />
        <el-select v-model="filters.category" placeholder="类别" clearable style="width: 160px">
          <el-option label="全部" value="" />
          <el-option label="OSS" value="oss" />
          <el-option label="大模型" value="llm" />
        </el-select>
        <el-date-picker v-model="filters.range" type="daterange" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" style="width: 320px" />
        <el-button type="primary" @click="fetchData">查询</el-button>
        <el-button @click="resetFilters">重置</el-button>
      </div>
    </div>

    <el-card class="table-card" shadow="never">
      <el-table 
        :data="tableData" 
        style="width: 100%" 
        v-loading="loading" 
        row-key="id"
        @row-click="openDetail"
        ref="tableRef"
      >
        <el-table-column type="expand">
          <template #default="{ row }">
            <div class="expand-box">
              <div class="expand-item">
                <div class="label">请求</div>
                <pre class="code">{{ formatJson(row.request) }}</pre>
              </div>
              <div class="expand-item">
                <div class="label">错误详情</div>
                <pre class="code">{{ formatJson(row.response) || row.message }}</pre>
              </div>
            </div>
          </template>
        </el-table-column>

        <template v-for="col in dynamicColumns" :key="col">
          <el-table-column 
            :prop="col" 
            :label="col" 
            :min-width="getMinWidth(col)" 
            :width="getWidth(col)"
            :show-overflow-tooltip="true"
          >
            <template #default="{ row }">
              <span v-if="isTimeField(col)">{{ formatTime(row[col]) }}</span>
              <span v-else>{{ row[col] }}</span>
            </template>
          </el-table-column>
        </template>
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
    <el-drawer v-model="detailVisible" title="错误详情" size="50%">
      <div class="detail-grid">
        <div>
          <div class="label">基础信息</div>
          <pre class="code">{{ formatJson(basicInfo) }}</pre>
        </div>
        <div>
          <div class="label">请求</div>
          <pre class="code">{{ formatJson(detail.request) }}</pre>
        </div>
        <div>
          <div class="label">响应/错误</div>
          <pre class="code">{{ formatJson(detail.response) || detail.message }}</pre>
        </div>
      </div>
    </el-drawer>
  </div>
  </template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getErrorLogs } from '@/api/systemErrors'

const tableData = ref([])
const tableRef = ref(null)
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const dynamicColumns = ref([])

const filters = reactive({
  keyword: '',
  category: '',
  range: []
})

const fetchData = async () => {
  loading.value = true
  try {
    const params = {
      page: currentPage.value,
      limit: pageSize.value
    }
    if (filters.keyword) params.keyword = filters.keyword
    if (filters.category) params.category = filters.category
    if (filters.range?.length === 2) {
      params.start_time = filters.range[0]
      params.end_time = filters.range[1]
    }
    const res = await getErrorLogs(params)
    tableData.value = res.data.data
    dynamicColumns.value = buildColumns(tableData.value)
    total.value = res.data.total
  } catch (e) {
  } finally {
    loading.value = false
  }
}

const resetFilters = () => {
  filters.keyword = ''
  filters.category = ''
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
  } catch { return String(ts) }
}

const formatJson = (val) => {
  if (!val) return ''
  if (typeof val === 'string') {
    try { return JSON.stringify(JSON.parse(val), null, 2) } catch { return val }
  }
  try { return JSON.stringify(val, null, 2) } catch { return String(val) }
}

onMounted(fetchData)

const excludedCols = ['request', 'response']
const buildColumns = (rows) => {
  if (!rows || rows.length === 0) return []
  const keys = Object.keys(rows[0])
  return keys.filter(k => !excludedCols.includes(k))
}

const isTimeField = (key) => /time/i.test(key)
const getMinWidth = (key) => (key.length > 8 ? 140 : 100)
const getWidth = (key) => (key === 'id' ? 80 : undefined)

const detailVisible = ref(false)
const detail = reactive({})
const basicInfo = ref('')
const openDetail = (row) => {
  Object.assign(detail, row)
  const basic = { ...row }
  delete basic.request
  delete basic.response
  basicInfo.value = basic
  detailVisible.value = true
}

</script>

<style scoped lang="scss">
.system-error-logs {
  .page-header {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-bottom: 24px;
    .header-actions { display: flex; gap: 12px; flex-wrap: wrap; }
  }
  .table-card { border: none; }
  .expand-box { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .expand-item .label { font-size: 13px; color: #666; margin-bottom: 6px; }
  .code { background: #f7f7f8; border-radius: 6px; padding: 12px; max-height: 300px; overflow: auto; }
  .pagination-container { margin-top: 20px; display: flex; justify-content: flex-end; }
}
</style>
