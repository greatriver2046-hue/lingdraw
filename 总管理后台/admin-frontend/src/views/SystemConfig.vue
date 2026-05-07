<template>
  <div class="system-config">
    <el-card class="config-card" shadow="never">
      <h3>阿里云 OSS</h3>
      <el-form :model="form" :rules="rules" label-width="140px" ref="formRef">
        <el-form-item label="AccessKeyId" prop="access_key_id">
          <el-input v-model="form.access_key_id" placeholder="请输入AccessKeyId" />
        </el-form-item>
        <el-form-item label="AccessKeySecret" prop="access_key_secret">
          <el-input v-model="form.access_key_secret" type="password" show-password placeholder="请输入AccessKeySecret" />
        </el-form-item>
        <el-form-item label="Bucket" prop="bucket">
          <el-input v-model="form.bucket" placeholder="请输入Bucket名称" />
        </el-form-item>
        <el-form-item label="Endpoint" prop="endpoint">
          <el-input v-model="form.endpoint" placeholder="例如：oss-cn-hangzhou.aliyuncs.com" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card class="config-card" shadow="never" style="margin-top: 24px;">
      <h3>阿里云短信</h3>
      <el-form :model="smsForm" :rules="smsRules" label-width="140px" ref="smsFormRef">
        <el-form-item label="AccessKeyId" prop="access_key_id">
          <el-input v-model="smsForm.access_key_id" placeholder="请输入AccessKeyId" />
        </el-form-item>
        <el-form-item label="AccessKeySecret" prop="access_key_secret">
          <el-input v-model="smsForm.access_key_secret" type="password" show-password placeholder="请输入AccessKeySecret" />
        </el-form-item>
        <el-form-item label="签名名称" prop="sign_name">
          <el-input v-model="smsForm.sign_name" placeholder="请输入短信签名 SignName" />
        </el-form-item>
        <el-form-item label="模板Code" prop="template_code">
          <el-input v-model="smsForm.template_code" placeholder="请输入模板 TemplateCode" />
        </el-form-item>
        <el-form-item label="RegionId" prop="region_id">
          <el-input v-model="smsForm.region_id" placeholder="例如：cn-hangzhou" />
        </el-form-item>
        <el-form-item label="Endpoint" prop="endpoint">
          <el-input v-model="smsForm.endpoint" placeholder="例如：dysmsapi.aliyuncs.com" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="smsSaving" @click="handleSaveSms">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getOssConfig, updateOssConfig, getConfig, updateConfig } from '@/api/systemConfig'

const formRef = ref(null)
const saving = ref(false)
const smsFormRef = ref(null)
const smsSaving = ref(false)

const form = reactive({
  access_key_id: '',
  access_key_secret: '',
  bucket: '',
  endpoint: ''
})

const rules = {
  access_key_id: [{ required: true, message: '请输入AccessKeyId', trigger: 'blur' }],
  access_key_secret: [{ required: true, message: '请输入AccessKeySecret', trigger: 'blur' }],
  bucket: [{ required: true, message: '请输入Bucket', trigger: 'blur' }],
  endpoint: [{ required: true, message: '请输入Endpoint', trigger: 'blur' }]
}

const smsForm = reactive({
  access_key_id: '',
  access_key_secret: '',
  sign_name: '',
  template_code: '',
  region_id: 'cn-hangzhou',
  endpoint: 'dysmsapi.aliyuncs.com'
})

const smsRules = {
  access_key_id: [{ required: true, message: '请输入AccessKeyId', trigger: 'blur' }],
  access_key_secret: [{ required: true, message: '请输入AccessKeySecret', trigger: 'blur' }],
  sign_name: [{ required: true, message: '请输入签名名称', trigger: 'blur' }],
  template_code: [{ required: true, message: '请输入模板Code', trigger: 'blur' }],
  region_id: [{ required: true, message: '请输入RegionId', trigger: 'blur' }],
  endpoint: [{ required: true, message: '请输入Endpoint', trigger: 'blur' }]
}

const fetchConfig = async () => {
  try {
    const res = await getOssConfig()
    const cfg = res.data || {}
    form.access_key_id = cfg.access_key_id || ''
    form.access_key_secret = cfg.access_key_secret || ''
    form.bucket = cfg.bucket || ''
    form.endpoint = cfg.endpoint || ''
  } catch (e) {
  }
}

onMounted(() => {
  fetchConfig()
  fetchSmsConfig()
})

const handleSave = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      await updateOssConfig({
        access_key_id: form.access_key_id,
        access_key_secret: form.access_key_secret,
        bucket: form.bucket,
        endpoint: form.endpoint
      })
      ElMessage.success('保存成功')
    } catch (e) {
    } finally {
      saving.value = false
    }
  })
}

const fetchSmsConfig = async () => {
  try {
    const res = await getConfig('sms')
    const cfg = res.data || {}
    smsForm.access_key_id = cfg.access_key_id || smsForm.access_key_id
    smsForm.access_key_secret = cfg.access_key_secret || smsForm.access_key_secret
    smsForm.sign_name = cfg.sign_name || smsForm.sign_name
    smsForm.template_code = cfg.template_code || smsForm.template_code
    smsForm.region_id = cfg.region_id || smsForm.region_id
    smsForm.endpoint = cfg.endpoint || smsForm.endpoint
  } catch (e) {
  }
}

const handleSaveSms = async () => {
  if (!smsFormRef.value) return
  await smsFormRef.value.validate(async (valid) => {
    if (!valid) return
    smsSaving.value = true
    try {
      await updateConfig('sms', { ...smsForm })
      ElMessage.success('保存成功')
    } catch (e) {
    } finally {
      smsSaving.value = false
    }
  })
}
</script>

<style scoped lang="scss">
.system-config {
  .config-card {
    border: none;
    h3 { margin: 0 0 16px; font-size: 18px; font-weight: 500; }
    max-width: 700px;
  }
}
</style>
