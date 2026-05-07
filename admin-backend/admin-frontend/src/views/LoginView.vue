<template>
  <div class="login-container">
    <div class="login-content">
      <div class="login-left">
        <div class="login-bg-content">
          <h1 class="welcome-title">AIsaas Platform</h1>
          <p class="welcome-desc">总管理后台</p>
          <div class="feature-list">
            <div class="feature-item">
              <el-icon><Monitor /></el-icon>
              <span>智能监控面板</span>
            </div>
            <div class="feature-item">
              <el-icon><DataLine /></el-icon>
              <span>实时数据分析</span>
            </div>
            <div class="feature-item">
              <el-icon><OfficeBuilding /></el-icon>
              <span>多租户管理</span>
            </div>
          </div>
        </div>
      </div>

      <div class="login-right">
        <div class="login-form-box">
          <div class="login-header">
            <h2>欢迎登录</h2>
            <p>请输入您的账号密码访问平台</p>
          </div>

          <el-form
            ref="loginFormRef"
            :model="loginForm"
            :rules="loginRules"
            label-width="0"
            class="login-form"
            @keyup.enter="handleLogin"
          >
            <el-form-item prop="username">
              <el-input
                v-model="loginForm.username"
                placeholder="用户名"
                size="large"
                :prefix-icon="User"
              />
            </el-form-item>

            <el-form-item prop="password">
              <el-input
                v-model="loginForm.password"
                type="password"
                placeholder="密码"
                size="large"
                :prefix-icon="Lock"
                show-password
              />
            </el-form-item>

            <div class="form-options">
              <el-checkbox v-model="rememberMe">记住我</el-checkbox>
              <el-link type="primary" :underline="false">忘记密码？</el-link>
            </div>

            <el-form-item>
              <el-button
                type="primary"
                class="login-button"
                :loading="loading"
                size="large"
                @click="handleLogin"
              >
                {{ loading ? '登录中...' : '登 录' }}
              </el-button>
            </el-form-item>
          </el-form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { DataLine, Lock, Monitor, OfficeBuilding, User } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import request from '../utils/request'

const router = useRouter()
const loginFormRef = ref(null)
const loading = ref(false)
const rememberMe = ref(false)

const loginForm = reactive({
  username: '',
  password: ''
})

const loginRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, message: '用户名长度不能少于3位', trigger: 'blur' }
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, message: '密码长度不能少于6位', trigger: 'blur' }
  ]
}

const handleLogin = async () => {
  if (!loginFormRef.value) return
  
  await loginFormRef.value.validate(async (valid) => {
    if (valid) {
      loading.value = true
      try {
        const res = await request.post('/admin/auth/login', loginForm)

        localStorage.setItem('token', res.data.token)
        localStorage.setItem('userInfo', JSON.stringify(res.data.userInfo))
        ElMessage.success('登录成功')
        router.push('/')
        
      } catch (error) {
        console.error(error)
      } finally {
        loading.value = false
      }
    }
  })
}
</script>

<style scoped lang="scss">
.login-container {
  height: 100vh;
  width: 100vw;
  display: flex;
  justify-content: center;
  align-items: center;
  background: #f0f0f0;
}

.login-content {
  display: flex;
  width: 900px;
  height: 520px;
  background: #fff;
  border: 1px solid #e0e0e0;
  overflow: hidden;
}

.login-left {
  flex: 1;
  background: #1a1a1a;
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 40px;
  color: #fff;
}

.login-bg-content {
  position: relative;
  z-index: 2;
}

.welcome-title {
  font-size: 32px;
  font-weight: bold;
  margin: 0 0 10px 0;
  letter-spacing: -0.5px;
}

.welcome-desc {
  font-size: 16px;
  margin: 0 0 40px 0;
  opacity: 0.8;
  color: #ccc;
}

.feature-list {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.feature-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: #ccc;
}

.feature-item .el-icon {
  font-size: 18px;
  background: rgba(255, 255, 255, 0.1);
  padding: 8px;
  border-radius: 4px;
  color: #fff;
}

.login-right {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px;
  background: #fff;
}

.login-form-box {
  width: 100%;
  max-width: 320px;
}

.login-header {
  margin-bottom: 30px;
  text-align: left;
}

.login-header h2 {
  font-size: 24px;
  color: #000;
  margin: 0 0 8px 0;
  font-weight: 600;
}

.login-header p {
  color: #666;
  font-size: 14px;
  margin: 0;
}

.login-form .el-input {
  --el-input-hover-border-color: #666;
  --el-input-focus-border-color: #000;
}

.login-form :deep(.el-input__wrapper) {
  background-color: #fff;
  box-shadow: none !important;
  border: 1px solid #dcdfe6;
  border-radius: 0;
  transition: all 0.3s;
  padding-left: 10px;
}

.login-form :deep(.el-input__wrapper.is-focus),
.login-form :deep(.el-input__wrapper:hover) {
  border-color: #000;
}

.form-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
}

.form-options :deep(.el-checkbox__label) {
  color: #666;
}

.form-options :deep(.el-checkbox__input.is-checked .el-checkbox__inner) {
  background-color: #000;
  border-color: #000;
}

.form-options :deep(.el-link.el-link--primary) {
  color: #666;
}

.form-options :deep(.el-link.el-link--primary:hover) {
  color: #000;
}

.login-button {
  width: 100%;
  font-weight: bold;
  background: #000;
  border: none;
  border-radius: 0;
  transition: all 0.3s;
  height: 44px;
}

.login-button:hover {
  background: #333;
  opacity: 1;
}

.login-button:active {
  background: #000;
}

@media (max-width: 768px) {
  .login-content {
    width: 90%;
    height: auto;
    flex-direction: column;
    border: none;
  }

  .login-left {
    padding: 30px;
    min-height: 160px;
  }

  .feature-list {
    display: none;
  }

  .login-right {
    padding: 30px;
  }
}
</style>
