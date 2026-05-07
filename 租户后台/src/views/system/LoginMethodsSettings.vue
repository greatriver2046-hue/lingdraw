<template>
  <div class="login-methods-settings">
    <el-card class="box-card" shadow="never">
      <el-form label-width="160px" class="settings-form" v-loading="loading">
        <el-form-item label="账号密码登录">
          <el-switch v-model="form.account" active-text="启用" inactive-text="禁用" />
        </el-form-item>
        <el-form-item label="手机验证码登录">
          <el-switch v-model="form.phone" active-text="启用" inactive-text="禁用" />
        </el-form-item>
        <el-form-item label="微信扫码登录">
          <el-switch v-model="form.wechat_scan" active-text="启用" inactive-text="禁用" />
        </el-form-item>
        <el-form-item label="启用图文创作">
          <el-switch v-model="form.graphic_creation_enabled" active-text="启用" inactive-text="禁用" />
        </el-form-item>

        <el-form-item>
          <el-button type="primary" @click="saveConfig" :loading="saving">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  account: true,
  phone: true,
  wechat_scan: true,
  graphic_creation_enabled: true
})

const toBool = (v, defaultValue = true) => {
  if (v === 0 || v === '0' || v === false) return false
  if (v === 1 || v === '1' || v === true) return true
  return defaultValue
}

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/system/config')
    const data = res.data || {}
    const methods = data && typeof data === 'object' ? data.login_methods : null
    const m = methods && typeof methods === 'object' ? methods : {}

    form.account = toBool(m.account, true)
    form.phone = toBool(m.phone, true)
    form.wechat_scan = toBool(m.wechat_scan, true)
    form.graphic_creation_enabled = toBool(data.graphic_creation_enabled, true)
  } finally {
    loading.value = false
  }
}

const saveConfig = async () => {
  if (!form.account && !form.phone && !form.wechat_scan) {
    ElMessage.warning('至少开启一种登录方式')
    return
  }

  saving.value = true
  try {
    await request.post('/admin/system/config', {
      login_methods: {
        account: form.account ? 1 : 0,
        phone: form.phone ? 1 : 0,
        wechat_scan: form.wechat_scan ? 1 : 0
      },
      graphic_creation_enabled: form.graphic_creation_enabled ? 1 : 0
    })
    ElMessage.success('设置已保存')
    await fetchConfig()
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<style scoped>
.login-methods-settings {
  padding: 20px;
}

.box-card {
  border: none;
  background-color: #fff;
  border-radius: 8px;
}

.settings-form {
  max-width: 720px;
}
</style>
