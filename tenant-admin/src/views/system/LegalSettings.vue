<template>
  <div class="legal-settings">
    <div class="page-header">
      <div class="header-left">
        <div class="page-title">用户协议与隐私政策</div>
        <div class="page-subtitle">用于用户端弹窗与相关页面展示</div>
      </div>
      <div class="header-right">
        <el-button @click="fetchConfig" :loading="loading">刷新</el-button>
        <el-button type="primary" @click="saveConfig" :loading="saving">保存</el-button>
      </div>
    </div>

    <el-card class="box-card" shadow="never">
      <el-form class="settings-form" v-loading="loading">
        <el-tabs v-model="activeTab" class="legal-tabs">
          <el-tab-pane label="用户协议" name="user" />
          <el-tab-pane label="隐私政策" name="privacy" />
        </el-tabs>

        <div class="panel">
          <div class="panel-title">内容</div>
          <el-input
            v-model="activeContent"
            type="textarea"
            :autosize="{ minRows: 18 }"
            placeholder="请输入内容"
          />
          <div class="meta-row">
            <div class="meta-item">字数：{{ contentLength }}</div>
            <div class="meta-item" v-if="lockHint">{{ lockHint }}</div>
          </div>
        </div>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed } from 'vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

const loading = ref(false)
const saving = ref(false)
const activeTab = ref('user')
const lastLoadedAt = ref(0)

const form = reactive({
  user_agreement: '',
  privacy_policy: ''
})

const activeContent = computed({
  get() {
    return activeTab.value === 'privacy' ? form.privacy_policy : form.user_agreement
  },
  set(val) {
    if (activeTab.value === 'privacy') {
      form.privacy_policy = val
    } else {
      form.user_agreement = val
    }
  }
})

const contentLength = computed(() => {
  return (activeContent.value || '').length
})

const lockHint = computed(() => {
  if (!lastLoadedAt.value) return ''
  if (saving.value) return '保存中...'
  if (loading.value) return '加载中...'
  return ''
})

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/legal/config')
    const data = res.data || {}
    form.user_agreement = typeof data.user_agreement === 'string' ? data.user_agreement : ''
    form.privacy_policy = typeof data.privacy_policy === 'string' ? data.privacy_policy : ''
    lastLoadedAt.value = Date.now()
  } finally {
    loading.value = false
  }
}

const saveConfig = async () => {
  saving.value = true
  try {
    await request.post('/admin/legal/config', {
      user_agreement: form.user_agreement,
      privacy_policy: form.privacy_policy
    })
    ElMessage.success('设置已保存')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped lang="scss">
.legal-settings {
  padding: 20px;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  margin-bottom: 16px;
}

.page-title {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
  line-height: 1.2;
}

.page-subtitle {
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}

.header-right {
  display: flex;
  gap: 10px;
}

.box-card {
  border: none;
  background-color: #fff;
  border-radius: 8px;
}

.settings-form {
  width: 100%;
}

.panel {
  border: 1px solid #ebeef5;
  border-radius: 8px;
  padding: 12px;
  background: #fff;
}

.panel-title {
  font-size: 13px;
  color: #606266;
  margin-bottom: 10px;
}

.meta-row {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  margin-top: 10px;
  font-size: 12px;
  color: #909399;
}

.meta-item {
  white-space: nowrap;
}
</style>
