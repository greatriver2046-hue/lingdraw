<template>
  <div class="customer-service-settings">
    <div class="page-header-actions">
      <el-button type="primary" :loading="loading" @click="handleSave">保存设置</el-button>
    </div>

    <el-card class="box-card" shadow="never">
      <el-form :model="form" label-width="120px" class="settings-form">
        <el-form-item label="客服二维码">
          <div class="upload-container">
            <el-upload
              class="avatar-uploader"
              action="#"
              :show-file-list="false"
              :http-request="uploadQrcode"
              accept="image/*"
            >
              <img v-if="form.qrcode" :src="form.qrcode" class="avatar" />
              <el-icon v-else class="avatar-uploader-icon"><Plus /></el-icon>
            </el-upload>
            <div class="upload-tip">建议上传正方形图片，支持 JPG/PNG 格式，大小不超过 2MB</div>
          </div>
        </el-form-item>

        <el-form-item label="客服说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="4"
            placeholder="请输入客服说明文字，例如：扫码添加客服微信，获取更多帮助"
            maxlength="200"
            show-word-limit
          />
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const loading = ref(false)
const form = reactive({
  qrcode: '',
  description: ''
})

const uploadQrcode = async (options) => {
  const formData = new FormData()
  formData.append('file', options.file)
  
  try {
    const res = await request.post('/admin/home/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    if (res.data && res.data.url) {
      form.qrcode = res.data.url
      ElMessage.success('上传成功')
    }
  } catch (error) {
    ElMessage.error('上传失败')
  }
}

const fetchConfig = async () => {
  try {
    const res = await request.get('/admin/system/config')
    if (res.code === 200 && res.data) {
      const cs = res.data.customer_service || {}
      form.qrcode = cs.qrcode || ''
      form.description = cs.description || ''
    }
  } catch (error) {
    console.error('Failed to fetch config:', error)
  }
}

const handleSave = async () => {
  loading.value = true
  try {
    const payload = {
      customer_service: {
        qrcode: form.qrcode,
        description: form.description
      }
    }
    
    const res = await request.post('/admin/system/config', payload)
    if (res.code === 200) {
      ElMessage.success('保存成功')
    } else {
      ElMessage.error(res.msg || '保存失败')
    }
  } catch (error) {
    ElMessage.error('保存失败')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped>
.customer-service-settings {
  padding: 20px;
}

.page-header-actions {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 20px;
}

.box-card {
  border: none;
}

.upload-container {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.avatar-uploader {
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  width: 178px;
  height: 178px;
  display: flex;
  justify-content: center;
  align-items: center;
  transition: border-color 0.3s;
}

.avatar-uploader:hover {
  border-color: #409eff;
}

.avatar-uploader-icon {
  font-size: 28px;
  color: #8c939d;
  width: 178px;
  height: 178px;
  text-align: center;
  display: flex;
  justify-content: center;
  align-items: center;
}

.avatar {
  width: 178px;
  height: 178px;
  display: block;
  object-fit: contain;
}

.upload-tip {
  font-size: 12px;
  color: #909399;
}
</style>
