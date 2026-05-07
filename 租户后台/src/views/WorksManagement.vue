<template>
  <div class="works-management">
    <el-card class="box-card" shadow="never">
      <el-table
        :data="tableData"
        style="width: 100%"
        v-loading="loading"
        :header-cell-style="{ background: '#f5f7fa', color: '#606266' }"
        @row-click="openDetail"
      >
        <el-table-column label="预览" width="120">
          <template #default="{ row }">
            <el-image
              :src="getThumbUrl(row.image_url)"
              fit="cover"
              style="width: 90px; height: 60px; border-radius: 6px; overflow: hidden"
            />
          </template>
        </el-table-column>

        <el-table-column prop="id" label="ID" width="90" />
        <el-table-column label="用户" min-width="160">
          <template #default="{ row }">
            {{ row.user_username || row.user_email || row.user_id || '-' }}
          </template>
        </el-table-column>
        <el-table-column prop="model_identity" label="模型" min-width="180" />
        <el-table-column label="分辨率" width="120">
          <template #default="{ row }">
            {{ row.width && row.height ? `${row.width}x${row.height}` : (row.size || '-') }}
          </template>
        </el-table-column>
        <el-table-column prop="status" label="状态" width="120" />
        <el-table-column prop="created_at" label="生成时间" min-width="180" />
        <el-table-column prop="prompt" label="提示词" min-width="320" show-overflow-tooltip />
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

    <!-- Detail Dialog -->
    <el-dialog
      v-model="dialogVisible"
      title="作品详情"
      width="900px"
      class="work-detail-dialog"
      align-center
    >
      <div class="detail-container" v-if="currentWork">
        <div class="detail-left">
          <div class="large-image-wrapper">
            <el-image
              :src="currentWork.image_url"
              :alt="'AI Image ' + currentWork.id"
              :preview-src-list="currentWork.image_url ? [currentWork.image_url] : []"
              preview-teleported
              fit="contain"
              class="detail-image"
            />
          </div>
        </div>
        <div class="detail-right">
          <div class="info-group">
            <label>作者</label>
            <div class="info-value">
              <el-avatar :size="32" class="author-avatar">{{ getAuthorInitial(currentWork) }}</el-avatar>
              <span class="author-name">{{ getAuthorText(currentWork) }}</span>
            </div>
          </div>

          <div class="info-group">
            <label>创建时间</label>
            <div class="info-value">{{ currentWork.created_at || '-' }}</div>
          </div>

          <div class="info-group full-height">
            <label>提示词 (Prompt)</label>
            <div class="info-value prompt-text">
              {{ currentWork.prompt || '-' }}
            </div>
          </div>

          <div class="info-group">
            <label>模型</label>
            <div class="info-value">{{ currentWork.model_identity || '-' }}</div>
          </div>

          <div class="info-group">
            <label>分辨率</label>
            <div class="info-value">
              {{ currentWork.width && currentWork.height ? `${currentWork.width}x${currentWork.height}` : (currentWork.size || '-') }}
            </div>
          </div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import request from '@/utils/request'

const loading = ref(false)
const tableData = ref([])
const total = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)

// Dialog Logic
const dialogVisible = ref(false)
const currentWork = ref(null)

const openDetail = (row) => {
  currentWork.value = row
  dialogVisible.value = true
}

const fetchWorks = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/works', {
      params: {
        page: currentPage.value,
        limit: pageSize.value
      }
    })
    tableData.value = res.data.data || []
    total.value = res.data.total || 0
  } catch {
    tableData.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchWorks()
}

const getAuthorText = (row) => {
  return row.user_username || row.user_email || String(row.user_id || '-')
}

const getAuthorInitial = (row) => {
  const text = getAuthorText(row)
  return text && text !== '-' ? String(text).charAt(0).toUpperCase() : 'U'
}

const getThumbUrl = (url) => {
  if (!url) return ''
  const u = String(url)
  if (u.includes('x-oss-process=style/w200')) return u
  return u.includes('?') ? `${u}&x-oss-process=style/w200` : `${u}?x-oss-process=style/w200`
}

onMounted(() => {
  fetchWorks()
})
</script>

<style scoped lang="scss">
.works-management {
  padding: 20px;
}

.box-card {
  border: none;
}

.pagination-container {
  display: flex;
  justify-content: flex-end;
  margin-top: 20px;
}

/* Detail Dialog Styles */
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

.large-image-wrapper {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.detail-image {
  width: 100%;
  height: 100%;
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
    
    &.prompt-text {
      line-height: 1.6;
      background: #f5f7fa;
      padding: 12px;
      border-radius: 4px;
      font-size: 14px;
      max-height: 200px;
      overflow-y: auto;
      color: #606266;
    }
  }

  .author-avatar {
    background-color: #409eff;
  }
  
  .author-name {
    font-weight: 500;
  }
}
</style>
