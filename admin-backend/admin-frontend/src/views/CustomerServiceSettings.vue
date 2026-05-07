<template>
  <div class="customer-service-settings">
    <el-card class="config-card" shadow="never" v-loading="loading">
      <h3>客服设置</h3>

      <div class="qr-block">
        <div class="qr-preview">
          <el-image
            v-if="wechatQr"
            :src="wechatQr"
            :preview-src-list="[wechatQr]"
            preview-teleported
            fit="contain"
            style="width: 260px; height: 260px; border-radius: 10px; overflow: hidden; background: #f5f7fa;"
          />
          <div v-else class="qr-empty">未上传</div>
        </div>

        <div class="qr-actions">
          <el-upload
            :show-file-list="false"
            :before-upload="beforeUpload"
            :http-request="handleUpload"
          >
            <el-button type="primary" :loading="uploading">上传微信二维码</el-button>
          </el-upload>
          <div class="qr-tip">仅支持 png/jpg/jpeg/webp，大小不超过 512KB</div>
        </div>
      </div>
    </el-card>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getConfig, uploadCustomerServiceWechatQr } from '@/api/systemConfig'

const loading = ref(false)
const uploading = ref(false)
const wechatQr = ref('')

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await getConfig('customer_service')
    const cfg = res.data || {}
    wechatQr.value = cfg.wechat_qr || ''
  } finally {
    loading.value = false
  }
}

const beforeUpload = (rawFile) => {
  const okType = ['image/png', 'image/jpeg', 'image/webp'].includes(rawFile.type)
  if (!okType) {
    ElMessage.error('仅支持 png/jpg/jpeg/webp 格式')
    return false
  }
  const okSize = rawFile.size <= 512 * 1024
  if (!okSize) {
    ElMessage.error('图片大小不能超过 512KB')
    return false
  }
  return true
}

const handleUpload = async (options) => {
  uploading.value = true
  try {
    const res = await uploadCustomerServiceWechatQr(options.file)
    const cfg = res.data || {}
    wechatQr.value = cfg.wechat_qr || ''
    ElMessage.success('上传成功')
  } finally {
    uploading.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped lang="scss">
.customer-service-settings {
  .config-card {
    border: none;
    max-width: 900px;
    h3 { margin: 0 0 16px; font-size: 18px; font-weight: 500; }
  }
}

.qr-block {
  display: flex;
  gap: 20px;
  align-items: flex-start;
}

.qr-preview {
  width: 260px;
  height: 260px;
  border-radius: 10px;
  overflow: hidden;
  background: #f5f7fa;
  display: flex;
  align-items: center;
  justify-content: center;
}

.qr-empty {
  color: #909399;
}

.qr-actions {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-top: 6px;
}

.qr-tip {
  font-size: 12px;
  color: #909399;
}
</style>
