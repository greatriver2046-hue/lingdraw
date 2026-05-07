<template>
  <div class="login-page">
    <div class="login-container">
      <!-- Close Button (Optional, usually for modals, but good for "Back") -->
      <div class="close-btn" @click="$router.push('/')">
        <el-icon><Close /></el-icon>
      </div>

      <!-- Left: Image -->
      <div class="login-image" :style="loginBgStyle">
        <!-- Removed static img, using background image -->
        <div class="image-overlay">
          <div class="overlay-content">
            <h3>激发无限创意</h3>
            <p>AI 驱动的下一代创作平台</p>
          </div>
        </div>
      </div>

      <!-- Right: Form -->
      <div class="login-form-container">
        <div class="form-wrapper">
          <h2 class="welcome-text">{{ viewMode === 'register' ? '欢迎注册' : '欢迎登录' }}</h2>
          
          <template v-if="viewMode === 'login'">
          <el-tabs v-if="hasAnyLoginMethod" v-model="activeTab" class="login-tabs">
            <el-tab-pane v-if="loginMethods.account" label="账号登录" name="account">
              <el-form :model="accountForm" class="login-form" @submit.prevent="handleAccountLogin">
                <el-form-item>
                  <div class="account-input-group">
                    <el-icon class="input-icon"><User /></el-icon>
                    <el-input 
                      v-model="accountForm.username" 
                      placeholder="请输入用户名/账号" 
                      class="no-border-input"
                    />
                  </div>
                </el-form-item>
                <el-form-item>
                  <div class="account-input-group">
                    <el-icon class="input-icon"><Lock /></el-icon>
                    <el-input 
                      v-model="accountForm.password" 
                      type="password"
                      placeholder="请输入密码" 
                      class="no-border-input"
                      show-password
                    />
                  </div>
                </el-form-item>
                <div class="agreement">
                  <el-checkbox v-model="accountForm.agreement">
                    登录即代表同意
                    <span class="link" @click.stop.prevent="openLegal('user')">《用户协议》</span>
                    和
                    <span class="link" @click.stop.prevent="openLegal('privacy')">《隐私政策》</span>
                  </el-checkbox>
                </div>

                <el-button :loading="loading" type="primary" class="submit-btn" @click="handleAccountLogin">
                  登录
                </el-button>
              </el-form>
            </el-tab-pane>
            <el-tab-pane v-if="loginMethods.phone" label="手机登录" name="phone">
              <el-form :model="form" class="login-form" @submit.prevent="handlePhoneLogin">
                <el-form-item>
                  <div class="phone-input-group">
                    <div class="country-code">+86</div>
                    <el-input 
                      v-model="form.phone" 
                      placeholder="请输入手机号" 
                      class="no-border-input"
                    />
                  </div>
                </el-form-item>
                
                <el-form-item>
                  <div class="code-input-group">
                    <el-input 
                      v-model="form.code" 
                      placeholder="请输入验证码" 
                      class="no-border-input"
                    />
                    <el-button
                      :loading="sendLoginCodeLoading"
                      :disabled="sendLoginCodeSeconds > 0"
                      link
                      type="primary"
                      class="get-code-btn"
                      @click="handleSendLoginCode"
                    >
                      {{ sendLoginCodeSeconds > 0 ? `${sendLoginCodeSeconds}s` : '获取验证码' }}
                    </el-button>
                  </div>
                </el-form-item>

                <div class="agreement">
                  <el-checkbox v-model="form.agreement">
                    登录即代表同意
                    <span class="link" @click.stop.prevent="openLegal('user')">《用户协议》</span>
                    和
                    <span class="link" @click.stop.prevent="openLegal('privacy')">《隐私政策》</span>
                  </el-checkbox>
                </div>

                <el-button :loading="loading" type="primary" class="submit-btn" @click="handlePhoneLogin">
                  立即创作
                </el-button>
              </el-form>
            </el-tab-pane>
            
            <el-tab-pane v-if="loginMethods.wechat_scan" label="扫码登录" name="scan">
              <div class="scan-container">
                <div class="qr-placeholder">
                  <el-icon :size="160" color="#333"><FullScreen /></el-icon>
                  <p>请使用 App 扫码登录</p>
                </div>
              </div>
              <div class="agreement scan-agreement">
                <el-checkbox v-model="form.agreement">
                  登录即代表同意
                  <span class="link" @click.stop.prevent="openLegal('user')">《用户协议》</span>
                  和
                  <span class="link" @click.stop.prevent="openLegal('privacy')">《隐私政策》</span>
                </el-checkbox>
              </div>
            </el-tab-pane>
          </el-tabs>
          <div v-else class="login-disabled">
            当前站点已关闭所有登录方式，请联系管理员
          </div>
          </template>

          <template v-else>
            <el-form :model="registerForm" class="login-form" @submit.prevent="handleRegister">
              <el-form-item>
                <div class="account-input-group">
                  <el-icon class="input-icon"><User /></el-icon>
                  <el-input
                    v-model="registerForm.username"
                    placeholder="请输入用户名/账号"
                    class="no-border-input"
                  />
                </div>
              </el-form-item>
              <el-form-item>
                <div class="account-input-group">
                  <el-icon class="input-icon"><Lock /></el-icon>
                  <el-input
                    v-model="registerForm.password"
                    type="password"
                    placeholder="请输入密码（至少6位）"
                    class="no-border-input"
                    show-password
                  />
                </div>
              </el-form-item>
              <el-form-item>
                <div class="phone-input-group">
                  <div class="country-code">+86</div>
                  <el-input
                    v-model="registerForm.phone"
                    placeholder="请输入手机号"
                    class="no-border-input"
                  />
                </div>
              </el-form-item>
              <el-form-item>
                <div class="code-input-group">
                  <el-input
                    v-model="registerForm.code"
                    placeholder="请输入验证码"
                    class="no-border-input"
                  />
                  <el-button
                    :loading="sendCodeLoading"
                    :disabled="sendCodeSeconds > 0"
                    link
                    type="primary"
                    class="get-code-btn"
                    @click="handleSendRegisterCode"
                  >
                    {{ sendCodeSeconds > 0 ? `${sendCodeSeconds}s` : '获取验证码' }}
                  </el-button>
                </div>
              </el-form-item>

              <div class="agreement">
                <el-checkbox v-model="registerForm.agreement">
                  注册即代表同意
                  <span class="link" @click.stop.prevent="openLegal('user')">《用户协议》</span>
                  和
                  <span class="link" @click.stop.prevent="openLegal('privacy')">《隐私政策》</span>
                </el-checkbox>
              </div>

              <el-button :loading="loading" type="primary" class="submit-btn" @click="handleRegister">
                注册并登录
              </el-button>
            </el-form>
          </template>

          <div v-if="viewMode === 'login'" class="switch-entry">
            <span>没有账号？</span>
            <span class="switch-link" @click="goRegister">去注册</span>
          </div>
          <div v-else class="switch-entry">
            <span>已有账号？</span>
            <span class="switch-link" @click="goLogin">去登录</span>
          </div>

          <el-dialog
            v-model="showUserAgreementDialog"
            title="用户协议"
            width="1080px"
            class="legal-dialog"
          >
            <div class="legal-content" v-loading="legalLoading">
              {{ legalUserAgreement }}
            </div>
          </el-dialog>

          <el-dialog
            v-model="showPrivacyPolicyDialog"
            title="隐私政策"
            width="1080px"
            class="legal-dialog"
          >
            <div class="legal-content" v-loading="legalLoading">
              {{ legalPrivacyPolicy }}
            </div>
          </el-dialog>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { Close, FullScreen, User, Lock, OfficeBuilding } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'

const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const activeTab = ref('account')
const loading = ref(false)
const viewMode = ref('login')

onMounted(async () => {
  try {
    const res = await request.get('/api/public/home_config')
    if (res.data && res.data.code === 200 && res.data.data) {
      appStore.setSiteConfig(res.data.data)
    }
  } catch (e) {
  }
})

const loginMethods = computed(() => {
  const raw = appStore.siteConfig?.login_methods
  const m = raw && typeof raw === 'object' ? raw : {}
  const toBool = (v) => {
    if (v === 0 || v === '0' || v === false) return false
    if (v === 1 || v === '1' || v === true) return true
    return null
  }

  const account = toBool(m.account)
  const phone = toBool(m.phone)
  const wechatScan = toBool(m.wechat_scan)

  return {
    account: account ?? true,
    phone: phone ?? true,
    wechat_scan: wechatScan ?? true
  }
})

const hasAnyLoginMethod = computed(() => {
  const m = loginMethods.value
  return !!(m.account || m.phone || m.wechat_scan)
})

const isTabEnabled = (tab) => {
  const m = loginMethods.value
  if (tab === 'account') return !!m.account
  if (tab === 'phone') return !!m.phone
  if (tab === 'scan') return !!m.wechat_scan
  return false
}

const firstEnabledTab = () => {
  const m = loginMethods.value
  if (m.account) return 'account'
  if (m.phone) return 'phone'
  if (m.wechat_scan) return 'scan'
  return 'account'
}

watch([viewMode, loginMethods], () => {
  if (viewMode.value !== 'login') return
  if (!hasAnyLoginMethod.value) return
  if (!isTabEnabled(activeTab.value)) {
    activeTab.value = firstEnabledTab()
  }
}, { immediate: true })

const loginBgStyle = computed(() => {
  const slides = appStore.siteConfig.slides
  const img = (slides && slides.length > 0 && slides[0].image) ? slides[0].image : 'https://picsum.photos/800/1000'
  return {
    backgroundImage: `linear-gradient(rgba(5, 10, 21, 0.3), rgba(5, 10, 21, 0.3)), url('${img}')`,
    backgroundSize: 'cover',
    backgroundPosition: 'center'
  }
})

const accountForm = reactive({
  username: '',
  password: '',
  agreement: true
})

const form = ref({
  phone: '',
  code: '',
  agreement: true
})

const registerForm = reactive({
  username: '',
  password: '',
  phone: '',
  code: '',
  agreement: true
})

const sendCodeLoading = ref(false)
const sendCodeSeconds = ref(0)
let sendCodeTimer = null

const sendLoginCodeLoading = ref(false)
const sendLoginCodeSeconds = ref(0)
let sendLoginCodeTimer = null

const showUserAgreementDialog = ref(false)
const showPrivacyPolicyDialog = ref(false)
const legalLoading = ref(false)
const userAgreementText = ref('')
const privacyPolicyText = ref('')

const legalUserAgreement = computed(() => {
  const text = typeof userAgreementText.value === 'string' ? userAgreementText.value.trim() : ''
  return text || '暂无内容'
})

const legalPrivacyPolicy = computed(() => {
  const text = typeof privacyPolicyText.value === 'string' ? privacyPolicyText.value.trim() : ''
  return text || '暂无内容'
})

const fetchLegalConfig = async () => {
  legalLoading.value = true
  try {
    const res = await request.get('/api/public/legal')
    if (res.data?.code === 200) {
      const data = res.data?.data || {}
      userAgreementText.value = typeof data.user_agreement === 'string' ? data.user_agreement : ''
      privacyPolicyText.value = typeof data.privacy_policy === 'string' ? data.privacy_policy : ''
    }
  } finally {
    legalLoading.value = false
  }
}

const openLegal = async (type) => {
  if (type === 'privacy') {
    showPrivacyPolicyDialog.value = true
    await fetchLegalConfig()
    return
  }

  showUserAgreementDialog.value = true
  await fetchLegalConfig()
}

const startSendCodeCountdown = () => {
  if (sendCodeTimer) clearInterval(sendCodeTimer)
  sendCodeSeconds.value = 60
  sendCodeTimer = setInterval(() => {
    if (sendCodeSeconds.value <= 1) {
      sendCodeSeconds.value = 0
      clearInterval(sendCodeTimer)
      sendCodeTimer = null
      return
    }
    sendCodeSeconds.value -= 1
  }, 1000)
}

const startSendLoginCodeCountdown = () => {
  if (sendLoginCodeTimer) clearInterval(sendLoginCodeTimer)
  sendLoginCodeSeconds.value = 60
  sendLoginCodeTimer = setInterval(() => {
    if (sendLoginCodeSeconds.value <= 1) {
      sendLoginCodeSeconds.value = 0
      clearInterval(sendLoginCodeTimer)
      sendLoginCodeTimer = null
      return
    }
    sendLoginCodeSeconds.value -= 1
  }, 1000)
}

const goRegister = () => {
  viewMode.value = 'register'
}

const goLogin = () => {
  viewMode.value = 'login'
  activeTab.value = 'account'
}

const handleAccountLogin = async () => {
  if (!accountForm.agreement) {
    ElMessage.warning('请先同意用户协议和隐私政策')
    return
  }

  if (!accountForm.username || !accountForm.password) {
    ElMessage.warning('请输入用户名和密码')
    return
  }

  loading.value = true
  try {
    await userStore.login(accountForm.username, accountForm.password)
    ElMessage.success('登录成功')
    router.replace('/creation/general')
  } catch (error) {
    console.error('Login error:', error)
    if (error.response) {
      ElMessage.error(error.response.data?.msg || `登录请求失败 (${error.response.status})`)
    } else {
      ElMessage.error(error.message || '网络错误，请检查服务是否运行')
    }
  } finally {
    loading.value = false
  }
}

const handleSendRegisterCode = async () => {
  if (sendCodeSeconds.value > 0 || sendCodeLoading.value) return

  const phone = String(registerForm.phone || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }

  sendCodeLoading.value = true
  try {
    const response = await request.post('/api/auth/send_code', { phone, type: 'register' })
    const { code, msg, data } = response.data
    if (code === 200) {
      ElMessage.success(msg || '验证码已发送')
      startSendCodeCountdown()
      if (data && data.debug_code) {
        registerForm.code = data.debug_code
      }
      return
    }
    ElMessage.error(msg || '验证码发送失败')
  } catch (error) {
    if (error.response) {
      ElMessage.error(error.response.data?.msg || `请求失败 (${error.response.status})`)
    } else {
      ElMessage.error(error.message || '网络错误，请检查服务是否运行')
    }
  } finally {
    sendCodeLoading.value = false
  }
}

const handleSendLoginCode = async () => {
  if (sendLoginCodeSeconds.value > 0 || sendLoginCodeLoading.value) return

  const phone = String(form.value.phone || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }

  sendLoginCodeLoading.value = true
  try {
    const response = await request.post('/api/auth/send_login_code', { phone })
    const { code, msg, data } = response.data
    if (code === 200) {
      ElMessage.success(msg || '验证码已发送')
      startSendLoginCodeCountdown()
      if (data && data.debug_code) {
        form.value.code = data.debug_code
      }
      return
    }
    ElMessage.error(msg || '验证码发送失败')
  } catch (error) {
    if (error.response) {
      ElMessage.error(error.response.data?.msg || `请求失败 (${error.response.status})`)
    } else {
      ElMessage.error(error.message || '网络错误，请检查服务是否运行')
    }
  } finally {
    sendLoginCodeLoading.value = false
  }
}

const handleRegister = async () => {
  if (!registerForm.agreement) {
    ElMessage.warning('请先同意用户协议和隐私政策')
    return
  }

  if (!registerForm.username || String(registerForm.username).trim().length < 3) {
    ElMessage.warning('用户名长度不能少于3位')
    return
  }
  if (!registerForm.password || String(registerForm.password).length < 6) {
    ElMessage.warning('密码长度不能少于6位')
    return
  }
  const phone = String(registerForm.phone || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  if (!registerForm.code) {
    ElMessage.warning('请输入验证码')
    return
  }

  loading.value = true
  try {
    const response = await request.post('/api/auth/register', {
      username: String(registerForm.username).trim(),
      password: String(registerForm.password),
      phone,
      code: String(registerForm.code).trim()
    })

    const { code, msg, data } = response.data
    if (code === 200) {
      ElMessage.success('注册成功')
      userStore.token = data.token
      userStore.userInfo = data.user
      localStorage.setItem('token', data.token)
      localStorage.setItem('user', JSON.stringify(data.user))
      router.replace('/creation/general')
      return
    }
    ElMessage.error(msg || '注册失败')
  } catch (error) {
    if (error.response) {
      ElMessage.error(error.response.data?.msg || `请求失败 (${error.response.status})`)
    } else {
      ElMessage.error(error.message || '网络错误，请检查服务是否运行')
    }
  } finally {
    loading.value = false
  }
}

const handlePhoneLogin = async () => {
  if (!form.value.agreement) {
    ElMessage.warning('请先同意用户协议和隐私政策')
    return
  }

  const phone = String(form.value.phone || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  const code = String(form.value.code || '').trim()
  if (!/^\d{4,8}$/.test(code)) {
    ElMessage.warning('请输入正确的验证码')
    return
  }

  loading.value = true
  try {
    const response = await request.post('/api/auth/phone_login', { phone, code })
    const { code: respCode, msg, data } = response.data
    if (respCode === 200) {
      ElMessage.success(msg || '登录成功')
      userStore.token = data.token
      userStore.userInfo = data.user
      localStorage.setItem('token', data.token)
      localStorage.setItem('user', JSON.stringify(data.user))
      router.replace('/creation/general')
      return
    }
    ElMessage.error(msg || '登录失败')
  } catch (error) {
    if (error.response) {
      ElMessage.error(error.response.data?.msg || `请求失败 (${error.response.status})`)
    } else {
      ElMessage.error(error.message || '网络错误，请检查服务是否运行')
    }
  } finally {
    loading.value = false
  }
}

watch(viewMode, (val) => {
  if (val !== 'register') {
    if (sendCodeTimer) clearInterval(sendCodeTimer)
    sendCodeTimer = null
    sendCodeSeconds.value = 0
    sendCodeLoading.value = false
  }
  if (val !== 'login') {
    if (sendLoginCodeTimer) clearInterval(sendLoginCodeTimer)
    sendLoginCodeTimer = null
    sendLoginCodeSeconds.value = 0
    sendLoginCodeLoading.value = false
  }
})

onUnmounted(() => {
  if (sendCodeTimer) clearInterval(sendCodeTimer)
  if (sendLoginCodeTimer) clearInterval(sendLoginCodeTimer)
})
</script>

<style scoped lang="scss">
.login-page {
  height: 100vh;
  width: 100vw;
  display: flex;
  background-color: #ffffff;
}

.agreement :deep(.el-checkbox__input .el-checkbox__inner) {
  border-color: #111;
}

.agreement :deep(.el-checkbox__input.is-checked .el-checkbox__inner) {
  background-color: #111;
  border-color: #111;
}

.agreement :deep(.el-checkbox__input.is-focus .el-checkbox__inner) {
  border-color: #111;
}

.agreement :deep(.el-checkbox__input .el-checkbox__inner:hover) {
  border-color: #111;
}

.login-container {
  width: 100%;
  height: 100%;
  border-radius: 0;
  box-shadow: none;
  display: flex;
  overflow: hidden;
  position: relative;
}

.close-btn {
  position: absolute;
  top: 20px;
  right: 20px;
  z-index: 10;
  cursor: pointer;
  padding: 8px;
  border-radius: 50%;
  transition: background-color 0.2s;
  
  &:hover {
    background-color: #f0f0f0;
  }
}

.login-image {
  flex: 1;
  position: relative;
  background-color: #000;
  // background image is set via inline style
  
  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: radial-gradient(rgba(255, 255, 255, 0.2) 1.5px, transparent 1.5px);
    background-size: 5px 5px;
    pointer-events: none;
    z-index: 1;
  }
  
  /* img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.8;
  } */

  .image-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 40px;
    // background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
    color: white;
    z-index: 2;

    h3 {
      font-size: 28px;
      margin-bottom: 10px;
      font-weight: 600;
    }

    p {
      font-size: 16px;
      opacity: 0.9;
    }
  }
}

.login-form-container {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
  background-color: #ffffff;
}

.form-wrapper {
  width: 100%;
  max-width: 360px;

  .welcome-text {
    font-size: 24px;
    font-weight: 600;
    color: #1f1f1f;
    margin-bottom: 32px;
    text-align: center;
  }

  .login-disabled {
    padding: 14px 16px;
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.03);
    color: rgba(0, 0, 0, 0.72);
    font-size: 14px;
    line-height: 20px;
    text-align: center;
  }
}

.login-tabs {
  :deep(.el-tabs__nav-wrap::after) {
    height: 1px;
    background-color: #f0f0f0;
  }
  
  :deep(.el-tabs__item) {
    font-size: 16px;
    color: #888;
    
    &.is-active {
      color: #1f1f1f;
      font-weight: 600;
    }
  }
}

.login-form {
  margin-top: 24px;
}

.account-input-group, .phone-input-group, .code-input-group {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #e0e0e0;
  padding-bottom: 4px;
  transition: border-color 0.2s;
  width: 100%;

  &:focus-within {
    border-bottom-color: #1f1f1f;
  }
}

.input-icon {
  font-size: 18px;
  color: #999;
  margin-right: 8px;
}

.no-border-input {
  :deep(.el-input__wrapper) {
    box-shadow: none !important;
    padding: 0;
    background: transparent;
  }
  
  :deep(.el-input__inner) {
    height: 40px;
    font-size: 15px;
  }
}

.country-code {
  font-size: 15px;
  font-weight: 600;
  color: #333;
  margin-right: 12px;
  padding-right: 12px;
  border-right: 1px solid #e0e0e0;
}

.get-code-btn {
  font-weight: 600;
}

.agreement {
  margin-top: 12px;
  margin-bottom: 24px;
  
  :deep(.el-checkbox__label) {
    font-size: 12px;
    color: #999;
  }
  
  .link {
    color: #1f1f1f;
    font-weight: 500;
    cursor: pointer;
  }
}

.submit-btn {
  width: 100%;
  height: 48px;
  font-size: 16px;
  font-weight: 600;
  border-radius: 8px;
  background-color: #1f1f1f;
  border-color: #1f1f1f;
  
  &:hover {
    background-color: #333;
    border-color: #333;
  }
}

.switch-entry {
  margin-top: 14px;
  font-size: 13px;
  color: #999;
  text-align: center;
  user-select: none;
}

.switch-link {
  margin-left: 6px;
  color: #1f1f1f;
  font-weight: 600;
  cursor: pointer;
}

.scan-container {
  display: flex;
  justify-content: center;
  align-items: center;
  height: 240px;
  background-color: #f8f9fa;
  border-radius: 8px;
  margin-top: 24px;
  margin-bottom: 24px;
  
  .qr-placeholder {
    text-align: center;
    color: #999;
    
    p {
      margin-top: 16px;
      font-size: 14px;
    }
  }
}

.scan-agreement {
  text-align: center;
}

:deep(.legal-dialog) {
  height: calc(100vh - 48px);
  margin-top: 24px !important;
  margin-bottom: 24px !important;
  max-width: calc(100vw - 32px);
  overflow: hidden;
}

:deep(.legal-dialog .el-dialog__body) {
  height: calc(100vh - 48px - 54px);
  overflow: hidden;
  padding: 0;
}

.legal-content {
  white-space: pre-wrap;
  font-size: 13px;
  line-height: 1.7;
  color: #1f1f1f;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 16px 18px;
  height: 100%;
  box-sizing: border-box;
}
</style>
