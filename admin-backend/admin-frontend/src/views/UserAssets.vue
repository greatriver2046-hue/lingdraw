<template>
  <div class="user-assets">
    <div class="page-header">
      <div class="header-actions">
        <el-input v-model="filters.keyword" placeholder="关键字(客户端过滤)" clearable style="width: 220px" />
        <el-select v-model="filters.category" placeholder="类型" clearable style="width: 140px">
          <el-option label="全部" value="" />
          <el-option label="图片" value="image" />
          <el-option label="音频" value="audio" />
          <el-option label="视频" value="video" />
        </el-select>
        <el-date-picker v-model="filters.range" type="daterange" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" style="width: 320px" />
        <el-button type="primary" @click="fetchData">查询</el-button>
        <el-button @click="resetFilters">重置</el-button>
      </div>
    </div>

    <el-card class="list-card" shadow="never" v-loading="loading">
      <el-table
        :data="filteredData"
        style="width: 100%"
        :header-cell-style="{ background: '#f5f7fa', color: '#606266' }"
        @row-click="openDetail"
      >
        <el-table-column label="预览" width="120">
          <template #default="{ row }">
            <template v-if="isImage(row) && getUrl(row)">
              <el-image
                :src="getThumbUrl(getUrl(row))"
                fit="cover"
                style="width: 90px; height: 60px; border-radius: 6px; overflow: hidden"
              >
                <template #error>
                  <div class="preview-placeholder">
                    <el-icon><IconPicture /></el-icon>
                  </div>
                </template>
              </el-image>
            </template>
            <template v-else>
              <div class="preview-placeholder">
                <el-icon v-if="isVideo(row)"><VideoCamera /></el-icon>
                <el-icon v-else-if="isAudio(row)"><Headset /></el-icon>
                <el-icon v-else><Link /></el-icon>
              </div>
            </template>
          </template>
        </el-table-column>

        <el-table-column prop="id" label="ID" width="90" />
        <el-table-column label="租户ID" width="120">
          <template #default="{ row }">
            {{ formatId(getTenantId(row)) }}
          </template>
        </el-table-column>
        <el-table-column label="用户ID" width="120">
          <template #default="{ row }">
            {{ formatId(getUserId(row)) }}
          </template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            {{ getTypeText(row) }}
          </template>
        </el-table-column>
        <el-table-column label="创建时间" min-width="180">
          <template #default="{ row }">
            {{ formatTime(getCreatedAt(row)) }}
          </template>
        </el-table-column>
        <el-table-column label="链接" min-width="360" show-overflow-tooltip>
          <template #default="{ row }">
            {{ getUrl(row) || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button size="small" type="danger" plain @click.stop="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-container" v-if="total > 0">
        <el-pagination
          background
          layout="prev, pager, next"
          :total="total"
          :current-page="currentPage"
          :page-size="pageSize"
          @current-change="handleCurrentChange"
        />
      </div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      title="资源详情"
      width="900px"
      class="asset-detail-dialog"
      align-center
    >
      <div class="detail-container" v-if="currentAsset">
        <div class="detail-left">
          <div class="large-preview-wrapper">
            <template v-if="isImage(currentAsset)">
              <el-image
                :src="getUrl(currentAsset)"
                :preview-src-list="[getUrl(currentAsset)]"
                preview-teleported
                fit="contain"
                style="width: 100%; height: 100%;"
              />
            </template>
            <template v-else-if="isVideo(currentAsset)">
              <video :src="getUrl(currentAsset)" controls style="width: 100%; height: 100%; object-fit: contain;" />
            </template>
            <template v-else-if="isAudio(currentAsset)">
              <audio :src="getUrl(currentAsset)" controls style="width: 100%;" />
            </template>
            <template v-else>
              <a :href="getUrl(currentAsset)" target="_blank" rel="noreferrer">打开链接</a>
            </template>
          </div>
        </div>
        <div class="detail-right">
          <div class="info-group">
            <label>资源ID</label>
            <div class="info-value">{{ currentAsset.id }}</div>
          </div>
          <div class="info-group">
            <label>租户/用户</label>
            <div class="info-value">T{{ formatId(getTenantId(currentAsset)) }} / U{{ formatId(getUserId(currentAsset)) }}</div>
          </div>
          <div class="info-group">
            <label>创建时间</label>
            <div class="info-value">{{ formatTime(getCreatedAt(currentAsset)) }}</div>
          </div>
          <div class="info-group full-height">
            <label>链接</label>
            <div class="info-value link-text">{{ getUrl(currentAsset) }}</div>
          </div>
          <div class="actions">
            <el-button type="danger" plain @click="handleDelete(currentAsset)">删除资源</el-button>
          </div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getAssets, deleteAsset } from '@/api/assets'
import { Headset, Link, Picture as IconPicture, VideoCamera } from '@element-plus/icons-vue'

const tableData = ref([])
const loading = ref(false)
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

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
    if (filters.category) params.type = filters.category
    if (filters.range?.length === 2) {
      params.start_time = filters.range[0]
      params.end_time = filters.range[1]
    }
    const res = await getAssets(params)
    tableData.value = res.data.data
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

const handleCurrentChange = (val) => {
  currentPage.value = val
  fetchData()
}

const filteredData = computed(() => {
  if (!filters.keyword) return tableData.value
  const kw = filters.keyword.toLowerCase()
  return tableData.value.filter((row) => {
    return Object.keys(row).some((k) => String(row[k] ?? '').toLowerCase().includes(kw))
  })
})

const getUrl = (row) => {
  const val = row.url || row.link || row.path || row.thumb || row.cover || ''
  return String(val)
}

const getThumbUrl = (url) => {
  if (!url) return ''
  const u = String(url)
  if (u.includes('x-oss-process=style/w200')) return u
  return u.includes('?') ? `${u}&x-oss-process=style/w200` : `${u}?x-oss-process=style/w200`
}

const getCreatedAt = (row) => {
  // Prefer 'created_at', fallback to 'create_time'
  if (row.created_at) return normalizeTimestamp(row.created_at)
  if (row.create_time) return normalizeTimestamp(row.create_time)
  return ''
}

const normalizeTimestamp = (v) => {
  // Accept both seconds and ISO strings
  if (typeof v === 'number') return v
  const d = Date.parse(v)
  return isNaN(d) ? '' : Math.floor(d / 1000)
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

const formatId = (v) => {
  if (v === null || v === undefined) return '-'
  if (typeof v === 'object') {
    const id = v?.id
    if (id === null || id === undefined || id === '') return '-'
    return String(id)
  }
  if (v === '') return '-'
  return String(v)
}

const getTenantId = (row) => {
  if (!row) return ''
  return (
    row.tenant_id ??
    row.tenantId ??
    row.instance_id ??
    row.instanceId ??
    row.saas_instance_id ??
    row.saasInstanceId ??
    row.tenant ??
    ''
  )
}

const getUserId = (row) => {
  if (!row) return ''
  return (
    row.user_id ??
    row.userId ??
    row.uid ??
    row.user ??
    ''
  )
}

const getTypeText = (row) => {
  const cat = (row.category || row.type || '').toString().toLowerCase()
  if (cat) return cat
  const mime = (row.mime || '').toString().toLowerCase()
  if (mime) return mime
  return '-'
}

const isImage = (row) => {
  const cat = (row.category || row.type || '').toString().toLowerCase()
  const mime = (row.mime || '').toString().toLowerCase()
  const url = getUrl(row).toLowerCase()
  return (
    cat.includes('image') ||
    mime.startsWith('image/') ||
    /(\.jpg|\.jpeg|\.png|\.gif|\.webp)(\?.*)?$/.test(url)
  )
}
const isVideo = (row) => {
  const cat = (row.category || row.type || '').toString().toLowerCase()
  const mime = (row.mime || '').toString().toLowerCase()
  const url = getUrl(row).toLowerCase()
  return (
    cat.includes('video') ||
    mime.startsWith('video/') ||
    /(\.mp4|\.webm|\.mov|\.mkv)(\?.*)?$/.test(url)
  )
}
const isAudio = (row) => {
  const cat = (row.category || row.type || '').toString().toLowerCase()
  const mime = (row.mime || '').toString().toLowerCase()
  const url = getUrl(row).toLowerCase()
  return (
    cat.includes('audio') ||
    mime.startsWith('audio/') ||
    /(\.mp3|\.wav|\.ogg|\.flac)(\?.*)?$/.test(url)
  )
}

const dialogVisible = ref(false)
const currentAsset = ref(null)

const openDetail = (row) => {
  currentAsset.value = row
  dialogVisible.value = true
}

const handleDelete = (row) => {
  ElMessageBox.confirm('确定删除该资源吗？', '提示', { type: 'warning' })
    .then(async () => {
      await deleteAsset(row.id)
      ElMessage.success('删除成功')
      if (currentAsset.value?.id === row.id) {
        dialogVisible.value = false
        currentAsset.value = null
      }
      fetchData()
    })
    .catch(() => {})
}

onMounted(fetchData)
</script>

<style scoped lang="scss">
.user-assets {
  .page-header {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 24px;
    .header-actions { display: flex; gap: 12px; flex-wrap: wrap; }
  }
  .list-card { border: none; }
  .pagination-container { margin-top: 20px; display: flex; justify-content: flex-end; }
}

.preview-placeholder {
  width: 90px;
  height: 60px;
  border-radius: 6px;
  background: #f5f7fa;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  font-size: 20px;
}

.detail-container {
  display: flex;
  gap: 24px;
  height: 650px;
}

.detail-left {
  flex: 1.5;
  background-color: #f5f7fa;
  border-radius: 4px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}

.large-preview-wrapper {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.detail-right {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.info-group {
  label {
    display: block;
    font-size: 13px;
    color: #909399;
    margin-bottom: 8px;
  }

  .info-value {
    font-size: 15px;
    color: #303133;
    display: flex;
    align-items: center;
    gap: 8px;

    &.link-text {
      line-height: 1.6;
      background: #f5f7fa;
      padding: 12px;
      border-radius: 4px;
      font-size: 13px;
      max-height: 220px;
      overflow-y: auto;
      color: #606266;
      word-break: break-all;
    }
  }
}

.actions {
  margin-top: auto;
  padding-top: 20px;
  border-top: 1px solid #ebeef5;
  display: flex;
  justify-content: flex-end;
}
</style>
