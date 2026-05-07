<template>
  <div class="home-settings">
    <el-card class="box-card" shadow="never">
      <el-form label-width="120px" class="settings-form" v-loading="loading">
        <el-divider content-position="left">基础信息</el-divider>
        
        <el-form-item label="网站标题">
          <el-input v-model="form.site_title" placeholder="请输入网站标题" />
        </el-form-item>

        <el-form-item label="站点名称">
          <el-input v-model="form.site_name" placeholder="请输入站点名称" />
        </el-form-item>
        
        <el-form-item label="Logo 设置">
          <el-upload
            class="avatar-uploader"
            action="#"
            :show-file-list="false"
            :http-request="uploadLogo"
            accept="image/*"
          >
            <img v-if="form.logo" :src="form.logo" class="avatar" />
            <el-icon v-else class="avatar-uploader-icon"><Plus /></el-icon>
          </el-upload>
          <div class="form-tip">建议尺寸 120x120px，支持 PNG, JPG</div>
        </el-form-item>

        <el-form-item label="备案号">
          <el-input v-model="form.icp_number" placeholder="请输入网站备案号 (例如: 京ICP备12345678号)" />
        </el-form-item>

        <el-divider content-position="left">页脚设置</el-divider>

        <el-form-item label="页脚信息">
          <el-input 
            v-model="form.footer_text" 
            type="textarea" 
            :rows="4" 
            placeholder="请输入其他需要在页脚显示的信息，如版权声明、联系方式等" 
          />
        </el-form-item>

        <el-divider content-position="left">幻灯片设置 (Hero Slides)</el-divider>
        
        <div v-for="(slide, index) in form.slides" :key="index" class="slide-item">
          <div class="slide-header">
            <span>幻灯片 #{{ index + 1 }}</span>
            <el-button type="danger" link @click="removeSlide(index)" v-if="form.slides.length > 1">删除</el-button>
          </div>
          
          <el-row :gutter="20">
            <el-col :span="12">
              <el-form-item label="Tab 标题" label-width="80px">
                <el-input v-model="slide.tab_text" placeholder="Tab显示的短标题" />
              </el-form-item>
              <el-form-item label="主标题" label-width="80px">
                <el-input v-model="slide.title" placeholder="幻灯片大标题" />
              </el-form-item>
              <el-form-item label="副标题" label-width="80px">
                <el-input v-model="slide.description" type="textarea" :rows="2" placeholder="幻灯片描述文字" />
              </el-form-item>
            </el-col>
            <el-col :span="12">
              <el-form-item label="背景图" label-width="80px">
                <el-upload
                  class="slide-uploader"
                  action="#"
                  :show-file-list="false"
                  :http-request="(opt) => uploadSlideBg(opt, index)"
                  accept="image/*"
                >
                  <img v-if="slide.image" :src="slide.image" class="slide-img" />
                  <el-icon v-else class="slide-uploader-icon"><Plus /></el-icon>
                </el-upload>
                <div class="form-tip">建议尺寸 1920x1080px，支持 PNG, JPG</div>
              </el-form-item>
            </el-col>
          </el-row>
        </div>

        <el-form-item>
          <el-button type="info" plain :icon="Plus" @click="addSlide" :disabled="form.slides.length >= 4" style="width: 100%">
            添加幻灯片 ({{ form.slides.length }}/4)
          </el-button>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" @click="saveConfig" :loading="saving">保存更改</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Plus } from '@element-plus/icons-vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  site_title: '',
  site_name: '',
  logo: '',
  icp_number: '',
  footer_text: '',
  slides: []
})

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/home/config')
    if (res.data) {
      Object.assign(form, res.data)
      // Ensure slides is an array
      if (!Array.isArray(form.slides) || form.slides.length === 0) {
        form.slides = [
          { tab_text: '01 默认幻灯片', title: 'Welcome', description: 'Default Description', image: '' }
        ]
      }
    }
  } catch (error) {
    console.error('Failed to load config', error)
  } finally {
    loading.value = false
  }
}

const addSlide = () => {
  if (form.slides.length >= 4) return
  form.slides.push({
    tab_text: `0${form.slides.length + 1} 新幻灯片`,
    title: '',
    description: '',
    image: ''
  })
}

const removeSlide = (index) => {
  form.slides.splice(index, 1)
}

const compressImage = (file, maxSize = 100 * 1024) => {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.readAsDataURL(file)
    reader.onload = (event) => {
      const img = new Image()
      img.src = event.target.result
      img.onload = () => {
        const canvas = document.createElement('canvas')
        let width = img.width
        let height = img.height
        
        // Initial dimension reduction if too large (optional, but helps performance)
        const maxDimension = 1920
        if (width > maxDimension || height > maxDimension) {
          if (width > height) {
            height = (height / width) * maxDimension
            width = maxDimension
          } else {
            width = (width / height) * maxDimension
            height = maxDimension
          }
        }
        
        canvas.width = width
        canvas.height = height
        const ctx = canvas.getContext('2d')
        ctx.drawImage(img, 0, 0, width, height)
        
        // Start with high quality
        let quality = 0.9
        
        const tryCompress = () => {
          canvas.toBlob((blob) => {
            if (!blob) {
              reject(new Error('Canvas to Blob failed'))
              return
            }
            
            if (blob.size <= maxSize || quality <= 0.1) {
              // Create a new File object from the blob
              const compressedFile = new File([blob], file.name, {
                type: 'image/jpeg',
                lastModified: Date.now()
              })
              resolve(compressedFile)
            } else {
              // Reduce quality and try again
              quality -= 0.1
              tryCompress()
            }
          }, 'image/jpeg', quality)
        }
        
        tryCompress()
      }
      img.onerror = (error) => reject(error)
    }
    reader.onerror = (error) => reject(error)
  })
}

const uploadSlideBg = async (options, index) => {
  let file = options.file
  
  // Compress if image is larger than 100KB
  if (file.size > 100 * 1024) {
    try {
      ElMessage.info('正在压缩图片...')
      file = await compressImage(file)
    } catch (error) {
      console.error('Compression failed', error)
      ElMessage.warning('图片压缩失败，将尝试原图上传')
    }
  }

  const formData = new FormData()
  formData.append('file', file)
  
  try {
    const res = await request.post('/admin/home/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    if (res.data && res.data.url) {
      form.slides[index].image = res.data.url
      ElMessage.success('背景图上传成功')
    }
  } catch (error) {
    ElMessage.error('背景图上传失败')
  }
}

const saveConfig = async () => {
  saving.value = true
  try {
    await request.post('/admin/home/config', form)
    ElMessage.success('设置已保存')
  } catch (error) {
    console.error('Failed to save config', error)
  } finally {
    saving.value = false
  }
}

const uploadLogo = async (options) => {
  const formData = new FormData()
  formData.append('file', options.file)
  
  try {
    const res = await request.post('/admin/home/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    if (res.data && res.data.url) {
      form.logo = res.data.url
      ElMessage.success('Logo上传成功')
    }
  } catch (error) {
    ElMessage.error('Logo上传失败')
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped>
.home-settings {
  padding: 20px;
}
.box-card {
  border: none;
  background-color: #fff;
  border-radius: 8px;
}

.settings-form {
  max-width: 800px;
  padding-top: 20px;
}

.avatar-uploader .el-upload {
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: var(--el-transition-duration-fast);
}
.avatar-uploader .el-upload:hover {
  border-color: #409EFF;
}
.avatar-uploader-icon {
  font-size: 28px;
  color: #8c939d;
  width: 100px;
  height: 100px;
  text-align: center;
  line-height: 100px;
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
}
.avatar {
  width: 100px;
  height: 100px;
  display: block;
  object-fit: contain;
  border-radius: 6px;
}
.form-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 8px;
  line-height: 1.5;
}

.slide-item {
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 20px;
  margin-bottom: 20px;
  background-color: #fcfcfc;
}

.slide-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
  font-weight: 600;
  color: #606266;
}

.slide-uploader .el-upload {
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  transition: var(--el-transition-duration-fast);
}
.slide-uploader .el-upload:hover {
  border-color: #409EFF;
}
.slide-uploader-icon {
  font-size: 28px;
  color: #8c939d;
  width: 100%;
  height: 120px;
  text-align: center;
  line-height: 120px;
  border: 1px dashed #d9d9d9;
  border-radius: 6px;
  background-color: #fff;
}
.slide-img {
  width: 100%;
  height: 120px;
  display: block;
  object-fit: cover;
  border-radius: 6px;
}
</style>
