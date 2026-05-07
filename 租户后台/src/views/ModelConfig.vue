<template>
  <div class="model-config">

    <el-card shadow="never" class="box-card">
      <el-table :data="models" style="width: 100%" v-loading="loading" :header-cell-style="{ background: '#f5f7fa', color: '#606266' }">
        <el-table-column prop="name" label="显示名称" min-width="150" />
        <el-table-column prop="model_id" label="模型ID" min-width="160">
          <template #default="{ row }">
            <el-tag size="small" type="info">{{ row.model_id }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="model_type" label="模型类型" width="130">
          <template #default="{ row }">
            <el-tag size="small" :type="getModelTypeTag(row.model_type)">
              {{ getModelTypeName(row.model_type) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="cost_per_request" label="单次消耗 (点数)" width="140" />
        <el-table-column prop="total_points" label="累计消耗点数" width="130" />
      </el-table>

      <el-empty v-if="!loading && models.length === 0" description="暂无已启用的模型" />
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const models = ref([])
const loading = ref(false)

const getModelTypeName = (type) => {
  const map = {
    'llm': '大语言模型',
    'image': '生图模型',
    'video': '视频模型',
    'audio': '音频模型',
    'vision': '视觉理解',
    'imageseg': '通用抠图',
    'imageseg_hd': '高清抠图',
    'v2v': '视频转视频'
  }
  return map[type] || type
}

const getModelTypeTag = (type) => {
  const map = {
    'llm': '',
    'image': 'success',
    'video': 'warning',
    'audio': 'info',
    'vision': 'success',
    'imageseg': 'danger',
    'imageseg_hd': 'danger',
    'v2v': 'warning'
  }
  return map[type] || ''
}

const fetchModels = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/models/all')
    if (res.code === 200) {
      models.value = res.data || []
    }
  } catch (error) {
    console.error('获取模型列表失败:', error)
    ElMessage.error('获取模型列表失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchModels()
})
</script>

<style scoped lang="scss">
.model-config {
  padding: 20px;
}

.box-card {
  border: none;
}
</style>
