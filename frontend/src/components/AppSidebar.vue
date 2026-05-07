<template>
  <div 
    class="app-sidebar" 
    :class="{ collapsed: isCollapsed }"
    @mouseenter="handleMouseEnter"
    @mouseleave="handleMouseLeave"
  >
    <div class="logo-container">
      <div class="logo-wrapper" @click="goHome">
        <div v-if="appStore.siteConfig.logo && isConfigLoaded" class="logo-img-wrapper">
          <img :src="appStore.siteConfig.logo" class="logo-img" alt="Logo" />
        </div>
        <div v-else-if="isConfigLoaded" class="logo-placeholder"></div>
        <span class="brand" v-if="!isCollapsed && isConfigLoaded">{{ appStore.siteConfig.site_name || appStore.siteConfig.site_title || 'xuku AI' }}</span>
      </div>
    </div>
    
    <nav class="nav-menu">
      <div class="menu-section">
        <template v-for="item in menuItems" :key="item.key">
          <!-- Parent Item -->
          <div 
            class="nav-item"
            :class="{ active: isItemActive(item) }"
            @click="handleMenuClick(item)"
          >
            <el-tooltip 
              :content="item.label" 
              placement="right" 
              :disabled="!isCollapsed"
              effect="light"
            >
              <div class="nav-icon-wrapper">
                <el-icon v-if="item.icon" class="nav-icon"><component :is="item.icon" /></el-icon>
              </div>
            </el-tooltip>
            <span class="nav-label">{{ item.label }}</span>
            <el-icon v-if="item.children" class="expand-icon">
              <ArrowDown v-if="item.expanded" />
              <ArrowRight v-else />
            </el-icon>
          </div>

          <!-- Sub Menu -->
          <div v-if="item.children && item.expanded" class="sub-menu-container">
            <div 
              v-for="child in item.children" 
              :key="child.key"
              class="nav-item sub-item"
              :class="{ active: activeItem === child.key }"
              @click="handleMenuClick(child)"
            >
              <span class="nav-label">{{ child.label }}</span>
            </div>
          </div>
        </template>
      </div>
    </nav>

    <div class="bottom-menu">
      <el-popover
        v-model:visible="isProfilePopoverVisible"
        placement="right-end"
        :width="280"
        trigger="hover"
        popper-class="user-profile-popover"
        :show-arrow="false"
        :offset="12"
        transition="el-zoom-in-left"
        :show-after="100"
        :hide-after="300"
        @show="handlePopoverShow"
        @hide="handlePopoverHide"
      >
        <template #reference>
          <div class="user-profile">
            <div class="avatar">{{ userInitials }}</div>
            <div class="user-info">
              <span class="username" :title="userInfo.username">{{ userInfo.username }}</span>
              <span class="email-mini" v-if="userInfo.email" :title="userInfo.email">{{ userInfo.email }}</span>
            </div>
          </div>
        </template>

        <div class="profile-card-content">
          <el-popover
            v-model:visible="customerServicePanelVisible"
            placement="right-start"
            :width="340"
            trigger="manual"
            popper-class="customer-service-popover"
            :show-arrow="false"
            :offset="12"
            transition="el-zoom-in-left"
            @show="loadCustomerService"
          >
            <template #reference>
              <div class="cs-panel-anchor"></div>
            </template>
            <div
              class="cs-card"
              @mouseenter="cancelHideCustomerServicePanel"
              @mouseleave="scheduleHideCustomerServicePanel"
            >
              <div class="cs-header">
                <div class="cs-title">联系客服</div>
                <div class="cs-subtitle">扫码添加客服，获得帮助与问题反馈</div>
              </div>
              <div class="cs-body">
                <div class="cs-qr">
                  <div v-if="customerServiceLoading" class="cs-qr-skeleton"></div>
                  <img
                    v-else-if="customerService.qrcode"
                    :src="customerService.qrcode"
                    alt="客服二维码"
                    loading="lazy"
                    decoding="async"
                  />
                  <div v-else class="cs-qr-empty">暂无二维码</div>
                </div>
                <div class="cs-desc">
                  <div v-if="customerServiceLoading" class="cs-desc-skeleton"></div>
                  <div v-else class="cs-desc-text">
                    {{ customerService.description || '如需帮助，请扫码联系在线客服。' }}
                  </div>
                </div>
              </div>
            </div>
          </el-popover>
          <div class="pc-header">
            <el-avatar :size="48" :style="{ backgroundColor: '#409EFF', fontSize: '20px' }">{{ userInitials }}</el-avatar>
            <div class="pc-user-details">
              <div class="pc-name-row">
                <div class="pc-name">{{ userInfo.username }}</div>
                <div v-if="userInfo.package_name" class="gold-tag">
                  <el-icon><Medal /></el-icon> {{ userInfo.package_name }}
                </div>
              </div>
              <div class="pc-email">{{ userInfo.email || 'greatriver2046@gmail.com' }}</div>
              <div v-if="userInfo.package_expire_time" class="pc-expire">
                到期: {{ formatDate(userInfo.package_expire_time) }}
              </div>
            </div>
            <el-button type="primary" size="small" class="upgrade-btn" color="#333639" round @click="handleUpgrade">升级会员</el-button>
          </div>

          <div class="pc-points-card" @click="handlePointsClick">
            <span class="label">积分</span>
            <div class="value-row">
              <span class="value">{{ Math.floor(Number(userInfo.period_points || 0) + Number(userInfo.extra_points || 0)) }}</span>
              <el-icon><ArrowRight /></el-icon>
            </div>
          </div>

          <div class="pc-menu">
            <div class="pc-menu-item" @click="openAccountManagement">
              <el-icon><User /></el-icon>
              <span>账户管理</span>
            </div>
            <div v-if="false" class="pc-menu-item">
              <el-icon><Reading /></el-icon>
              <span>使用教程</span>
            </div>
            <div
              class="pc-menu-item"
              @mouseenter="showCustomerServicePanel"
              @mouseleave="scheduleHideCustomerServicePanel"
              @click="showCustomerServicePanel"
            >
              <el-icon><ChatDotRound /></el-icon>
              <span>微信客服</span>
            </div>
            <div class="pc-menu-item logout" @click="handleLogout">
              <el-icon><SwitchButton /></el-icon>
              <span>退出登录</span>
            </div>
          </div>
        </div>
      </el-popover>
    </div>
    
    <el-dialog
      v-model="showAccountDialog"
      width="720px"
      class="account-management-dialog"
      append-to-body
      destroy-on-close
    >
      <template #header>
        <div class="account-dialog-title">账户管理</div>
      </template>

      <div class="account-panel">
        <div class="account-summary">
          <el-avatar :size="44" :style="{ backgroundColor: '#409EFF', fontSize: '18px' }">{{ userInitials }}</el-avatar>
          <div class="meta">
            <div class="name">{{ userInfo.username || '用户' }}</div>
            <div class="sub">ID: {{ userInfo.id ?? '-' }}</div>
          </div>
          <el-button size="small" color="#333639" round @click="refreshUserInfo">刷新</el-button>
        </div>

        <div class="account-cards">
          <div class="card">
            <div class="card-title">账号信息</div>
            <div class="info-row">
              <div class="label">邮箱</div>
              <div class="value">{{ userInfo.email || '-' }}</div>
            </div>
            <div class="info-row">
              <div class="label">手机号</div>
              <div class="value">{{ maskedPhone }}</div>
            </div>
          </div>

          <div class="card">
            <div class="card-title">安全</div>
            <el-tabs v-model="accountTab" class="account-tabs" stretch>
              <el-tab-pane label="修改密码" name="password">
                <el-form label-position="top" :model="passwordForm" class="account-form">
                  <el-form-item label="原密码">
                    <el-input v-model="passwordForm.oldPassword" type="password" show-password autocomplete="current-password" />
                  </el-form-item>
                  <el-form-item label="新密码">
                    <el-input v-model="passwordForm.newPassword" type="password" show-password autocomplete="new-password" />
                  </el-form-item>
                  <el-form-item label="确认新密码">
                    <el-input v-model="passwordForm.confirmPassword" type="password" show-password autocomplete="new-password" />
                  </el-form-item>
                  <div class="form-actions">
                    <el-button :loading="passwordLoading" type="primary" @click="submitChangePassword">保存</el-button>
                  </div>
                </el-form>
              </el-tab-pane>

              <el-tab-pane label="换绑手机号" name="phone">
                <el-form label-position="top" :model="bindPhoneForm" class="account-form">
                  <el-form-item label="新手机号">
                    <div class="send-code-row">
                      <el-input v-model="bindPhoneForm.phone" placeholder="请输入新手机号" maxlength="11" />
                      <el-button :loading="sendCodeLoading" :disabled="sendCodeSeconds > 0" @click="sendBindPhoneCode">
                        {{ sendCodeSeconds > 0 ? `${sendCodeSeconds}s` : '获取验证码' }}
                      </el-button>
                    </div>
                  </el-form-item>
                  <el-form-item label="验证码">
                    <el-input v-model="bindPhoneForm.code" placeholder="请输入验证码" />
                  </el-form-item>
                  <div class="form-actions">
                    <el-button :loading="bindPhoneLoading" type="primary" @click="submitBindPhone">确认换绑</el-button>
                  </div>
                </el-form>
              </el-tab-pane>
            </el-tabs>
          </div>
        </div>
      </div>
    </el-dialog>

    <PointsHistoryDialog v-model="showPointsDialog" />
    <SubscriptionModal v-model="appStore.showSubscriptionModal" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive, watch, onUnmounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { ElMessage } from 'element-plus'
import request from '@/utils/request'
import PointsHistoryDialog from './PointsHistoryDialog.vue'
import SubscriptionModal from './SubscriptionModal.vue'
import { 
  Fold, Expand, TopRight,
  Brush, Collection, MagicStick, Reading,
  ArrowDown, ArrowRight, User, ChatDotRound, SwitchButton, Medal
} from '@element-plus/icons-vue'

const props = defineProps({
  isCollapsed: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['toggle'])

const router = useRouter()
const route = useRoute()
const appStore = useAppStore()
const userStore = useUserStore()

const isConfigLoaded = computed(() => appStore.isSiteConfigLoaded)

const userInfo = computed(() => userStore.userInfo || { username: 'User' })

const goHome = () => {
  router.push('/')
}

const toggleSidebar = () => {
  emit('toggle')
  // If toggled manually while hovering, update the state to prevent auto-collapse/expand logic interference
  // If we just opened it manually, we don't want it to close on leave.
  // If we just closed it manually, we don't want it to open on hover (until next entry).
  // Actually, simpler: just update our memory to match the new intent.
  // If now Open, treat as "was Open". If now Closed, treat as "was Closed".
}

const handleMouseEnter = () => {
  appStore.setAppSidebarHovered(true)
  
  if (appStore.isSidebarCollapsed) {
    appStore.requestSidebarCollapse(false)
  }
}

const isProfilePopoverVisible = ref(false)
const showPointsDialog = ref(false)
const showAccountDialog = ref(false)
const accountTab = ref('password')
const customerServiceLoading = ref(false)
const customerServiceLoaded = ref(false)
const customerServicePanelVisible = ref(false)
const customerServiceTenantId = ref(null)
const customerService = reactive({
  qrcode: '',
  description: ''
})
let customerServiceHideTimer = null

const passwordLoading = ref(false)
const bindPhoneLoading = ref(false)
const sendCodeLoading = ref(false)
const sendCodeSeconds = ref(0)
let sendCodeTimer = null

const passwordForm = reactive({
  oldPassword: '',
  newPassword: '',
  confirmPassword: ''
})

const bindPhoneForm = reactive({
  phone: '',
  code: ''
})

const handlePopoverShow = () => {
  isProfilePopoverVisible.value = true
  loadCustomerService()
}

const handlePopoverHide = () => {
  isProfilePopoverVisible.value = false
  customerServicePanelVisible.value = false
  if (customerServiceHideTimer) {
    clearTimeout(customerServiceHideTimer)
    customerServiceHideTimer = null
  }
  // Manually trigger leave logic if needed, but the main leave handler might have already run.
  // We need to check if we should collapse.
  if (!appStore.isAppSidebarHovered) {
    handleMouseLeave()
  }
}

const showCustomerServicePanel = () => {
  if (customerServiceHideTimer) {
    clearTimeout(customerServiceHideTimer)
    customerServiceHideTimer = null
  }
  customerServicePanelVisible.value = true
  loadCustomerService(true)
}

const scheduleHideCustomerServicePanel = () => {
  if (customerServiceHideTimer) clearTimeout(customerServiceHideTimer)
  customerServiceHideTimer = setTimeout(() => {
    customerServicePanelVisible.value = false
    customerServiceHideTimer = null
  }, 180)
}

const cancelHideCustomerServicePanel = () => {
  if (customerServiceHideTimer) {
    clearTimeout(customerServiceHideTimer)
    customerServiceHideTimer = null
  }
}

const loadCustomerService = async (force = false) => {
  const tenantId =
    (userStore.userInfo && userStore.userInfo.tenant_id != null ? userStore.userInfo.tenant_id : null) ??
    (appStore.siteConfig && appStore.siteConfig.tenant_id != null ? appStore.siteConfig.tenant_id : null)
  if (customerServiceLoading.value) return
  if (!force && customerServiceLoaded.value && customerServiceTenantId.value === tenantId) return
  customerServiceLoading.value = true
  try {
    const hasToken = !!localStorage.getItem('token')
    const res = hasToken
      ? await request.get('/api/v1/customer_service')
      : await request.get('/api/public/customer_service', {
          params: tenantId ? { tenant_id: tenantId } : undefined
        })
    const data = res.data?.data || {}
    customerService.qrcode = String(data.qrcode || data.wechat_qr || '')
    customerService.description = String(data.description || '')
    customerServiceLoaded.value = true
    customerServiceTenantId.value = tenantId
  } catch (e) {
    customerServiceLoaded.value = true
    customerServiceTenantId.value = tenantId
  } finally {
    customerServiceLoading.value = false
  }
}

const formatDate = (dateStr) => {
  if (!dateStr) return ''
  const date = new Date(dateStr)
  return date.toLocaleDateString()
}

const handleMouseLeave = () => {
  appStore.setAppSidebarHovered(false)
}

onMounted(() => {
  // Fetch latest user info (including points)
  userStore.getUserInfo()
  loadCustomerService()
})

const userInitials = computed(() => {
  return userInfo.value.username ? userInfo.value.username.charAt(0).toUpperCase() : 'U'
})

const handleLogout = () => {
  userStore.logout()
  ElMessage.success('已退出登录')
  router.push('/login')
}

const handleUpgrade = () => {
  appStore.openSubscriptionModal()
  isProfilePopoverVisible.value = false
}

const handlePointsClick = () => {
  showPointsDialog.value = true
  isProfilePopoverVisible.value = false
}

const refreshUserInfo = async () => {
  try {
    await userStore.getUserInfo()
    ElMessage.success('已刷新')
  } catch (e) {
    ElMessage.error('刷新失败')
  }
}

const openAccountManagement = () => {
  showAccountDialog.value = true
  accountTab.value = 'password'
  isProfilePopoverVisible.value = false
  userStore.getUserInfo()
}

const maskedPhone = computed(() => {
  const p = String(userInfo.value.phone || '').trim()
  if (!p) return '-'
  if (/^1\d{10}$/.test(p)) return `${p.slice(0, 3)}****${p.slice(7)}`
  if (p.length >= 7) return `${p.slice(0, 3)}****${p.slice(-3)}`
  return p
})

const resetAccountForms = () => {
  passwordForm.oldPassword = ''
  passwordForm.newPassword = ''
  passwordForm.confirmPassword = ''
  bindPhoneForm.phone = ''
  bindPhoneForm.code = ''
  sendCodeLoading.value = false
  sendCodeSeconds.value = 0
  if (sendCodeTimer) {
    clearInterval(sendCodeTimer)
    sendCodeTimer = null
  }
}

watch(showAccountDialog, (val) => {
  if (!val) resetAccountForms()
})

onUnmounted(() => {
  if (sendCodeTimer) clearInterval(sendCodeTimer)
})

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

const submitChangePassword = async () => {
  const oldPassword = String(passwordForm.oldPassword || '')
  const newPassword = String(passwordForm.newPassword || '')
  const confirmPassword = String(passwordForm.confirmPassword || '')

  if (!oldPassword) {
    ElMessage.warning('请输入原密码')
    return
  }
  if (!newPassword || newPassword.length < 6) {
    ElMessage.warning('新密码长度不能少于6位')
    return
  }
  if (newPassword !== confirmPassword) {
    ElMessage.warning('两次输入的新密码不一致')
    return
  }

  passwordLoading.value = true
  try {
    const res = await request.post('/api/v1/user/change_password', {
      old_password: oldPassword,
      new_password: newPassword
    })
    if (res.data?.code === 200) {
      ElMessage.success('密码已更新')
      passwordForm.oldPassword = ''
      passwordForm.newPassword = ''
      passwordForm.confirmPassword = ''
      return
    }
    ElMessage.error(res.data?.msg || '更新失败')
  } catch (e) {
    ElMessage.error(e?.response?.data?.msg || e?.message || '更新失败')
  } finally {
    passwordLoading.value = false
  }
}

const sendBindPhoneCode = async () => {
  if (sendCodeSeconds.value > 0 || sendCodeLoading.value) return

  const phone = String(bindPhoneForm.phone || '').trim()
  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }

  sendCodeLoading.value = true
  try {
    const res = await request.post('/api/auth/send_code', { phone, type: 'bind_phone' })
    const { code, msg, data } = res.data || {}
    if (code === 200) {
      ElMessage.success(msg || '验证码已发送')
      startSendCodeCountdown()
      if (data && data.debug_code) {
        bindPhoneForm.code = data.debug_code
      }
      return
    }
    ElMessage.error(msg || '验证码发送失败')
  } catch (e) {
    ElMessage.error(e?.response?.data?.msg || e?.message || '验证码发送失败')
  } finally {
    sendCodeLoading.value = false
  }
}

const submitBindPhone = async () => {
  const phone = String(bindPhoneForm.phone || '').trim()
  const code = String(bindPhoneForm.code || '').trim()

  if (!/^1\d{10}$/.test(phone)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  if (!code) {
    ElMessage.warning('请输入验证码')
    return
  }

  bindPhoneLoading.value = true
  try {
    const res = await request.post('/api/v1/user/bind_phone', { phone, code })
    if (res.data?.code === 200) {
      ElMessage.success('手机号已更新')
      await userStore.getUserInfo()
      bindPhoneForm.code = ''
      return
    }
    ElMessage.error(res.data?.msg || '更新失败')
  } catch (e) {
    ElMessage.error(e?.response?.data?.msg || e?.message || '更新失败')
  } finally {
    bindPhoneLoading.value = false
  }
}

const isGraphicEnabled = computed(() => {
  if (!isConfigLoaded.value) return false
  const v = appStore.siteConfig?.graphic_creation_enabled
  if (v === 0 || v === '0' || v === false) return false
  if (v === 1 || v === '1' || v === true) return true
  return true
})

const buildMenuItems = () => {
  const children = [
    { key: 'general-creation', label: '通用创作', path: '/creation/general' }
  ]
  if (isGraphicEnabled.value) {
    children.push({ key: 'graphic-creation', label: '图文创作', path: '/creation/graphic' })
  }
  return [
    {
      key: 'creation',
      label: '创作',
      icon: Brush,
      expanded: true,
      children
    },
    { key: 'gallery', label: '作品库', path: '/works', icon: Collection },
    { key: 'inspiration', label: '灵感池', path: '/inspiration', icon: MagicStick },
    { key: 'tutorials', label: '教程', path: '/tutorials', icon: Reading }
  ]
}

const menuItems = ref(buildMenuItems())

watch(isGraphicEnabled, () => {
  const prevExpanded = menuItems.value.find(i => i.key === 'creation')?.expanded
  menuItems.value = buildMenuItems()
  const creation = menuItems.value.find(i => i.key === 'creation')
  if (creation) {
    creation.expanded = prevExpanded !== false
  }
})

const activeItem = computed(() => {
  const curPath = String(route.path || '')
  const isActivePath = (p) => {
    const base = String(p || '')
    if (!base) return false
    return curPath === base || curPath.startsWith(base + '/')
  }
  // Find active child first
  for (const item of menuItems.value) {
    if (item.children) {
      const child = item.children.find(c => isActivePath(c.path))
      if (child) return child.key
    } else if (isActivePath(item.path)) {
      return item.key
    }
  }
  return 'general-creation' // Default fallback
})

const isItemActive = (item) => {
  if (item.children) {
    // Parent is active if any child is active (optional style choice, maybe just expand?)
    // Usually we don't highlight parent if child is selected in this style, but let's see.
    // Let's strictly check key match for now, but activeItem returns child key.
    return false 
  }
  return activeItem.value === item.key
}

const handleMenuClick = (item) => {
  if (item.children) {
    item.expanded = !item.expanded
    return
  }
  
  if (item.path) {
    // Lock sidebar open when navigating
    appStore.requestSidebarCollapse(false)
    
    router.push(item.path)
  }
}
</script>

<style scoped lang="scss">
.app-sidebar {
  height: 100%;
  background-color: #fff;
  display: flex;
  flex-direction: column;
  padding: 16px 12px;
  box-sizing: border-box;
  color: #444746;
  
  .logo-container {
    padding: 0 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 48px;
    
    .logo-wrapper {
      display: flex;
      align-items: center;
      gap: 10px;
      overflow: hidden;
      flex: 1;
      cursor: pointer;

      .logo-img-wrapper {
        height: 32px;
        width: 32px;
        flex-shrink: 0;

        .logo-img {
          height: 32px;
          width: 32px;
          object-fit: contain;
        }
      }

      .logo-placeholder {
        height: 32px;
        width: 32px;
        flex-shrink: 0;
        background-color: #e5e7eb;
        border-radius: 6px;
      }

      .brand {
        font-size: 20px;
        font-weight: 400;
        color: #1f1f1f;
        font-family: 'Product Sans', 'Roboto', sans-serif;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        opacity: 1;
        transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
    }
  }

  &.collapsed {
    width: 68px; // Fixed width when collapsed to ensure centering works well

    .logo-container {
      padding: 0;
      justify-content: center;
    }

    .logo-wrapper {
      justify-content: center;
      padding: 0;
      flex: 0 0 auto;
      
      .brand {
        display: none;
      }
      
      .logo-img-wrapper {
        height: 32px;
        width: 32px;
        flex-shrink: 0;

        .logo-img {
          height: 32px;
          width: 32px;
          object-fit: contain;
        }
      }

      .logo-placeholder {
        height: 32px;
        width: 32px;
        flex-shrink: 0;
      }
    }
    
    .nav-label {
      opacity: 0;
      width: 0;
      flex: 0 !important;
      margin: 0;
    }

    .expand-icon {
      opacity: 0;
      width: 0;
      margin: 0;
    }

    .sub-menu-container {
      height: 0;
      opacity: 0;
      overflow: hidden;
      margin: 0;
    }

    .user-info {
      opacity: 0;
      width: 0;
      flex: 0 !important;
      margin: 0;
      padding: 0;
    }

    // Center items when collapsed using precise padding
    .nav-menu .nav-item {
      padding-left: 13px;
      padding-right: 13px;

      .nav-icon {
        margin-right: 0;
      }
    }

    .bottom-menu {
      .nav-item {
        padding-left: 10px;
        padding-right: 10px;
        gap: 0;
      }
      
      .user-profile {
        padding-left: 10px;
        padding-right: 10px;
        gap: 0;
      }
    }
  }
  
  .nav-menu {
    flex: 1;
    display: flex;
    flex-direction: column;
    
    .nav-item {
      height: 40px;
      padding: 0 16px;
      margin-bottom: 4px;
      border-radius: 20px; // Pill shape
      cursor: pointer;
      display: flex;
      align-items: center;
      font-size: 14px;
      font-weight: 500;
      color: #444746;
      transition: background-color 0.2s, padding 0.3s;
      
      .nav-icon {
        margin-right: 12px;
        font-size: 18px;
        transition: margin 0.3s;
      }

      .expand-icon {
        margin-left: auto;
        font-size: 12px;
        transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1), margin 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
      
      &:hover {
        background-color: #f0f1f3;
        border-radius: 10px;
      }
      
      &.active {
        background-color: #f0f1f3;
        color: #000;
        font-weight: 600;
        border-radius: 10px;
      }

      .nav-label {
        flex: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), flex 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      }
    }

    .sub-menu-container {
      margin-bottom: 4px;
      transition: height 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      
      .sub-item {
        padding-left: 46px; // Indent sub items (icon width + padding)
        height: 36px;
        font-size: 13px;
        
        &.active {
          background-color: #f0f1f3;
          color: #000;
          border-radius: 10px;
        }
      }
    }
  }

  .bottom-menu {
    border-top: 1px solid transparent;
    padding-top: 8px;

    .nav-item {
      height: 40px;
      padding: 0 12px;
      border-radius: 20px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      color: #444746;
      transition: background-color 0.2s, padding 0.3s, gap 0.3s;
      
      &:hover {
        background-color: #f0f1f3;
      }

      .menu-icon {
        font-size: 18px;
        width: 24px; // Match avatar width for alignment
        display: flex;
        justify-content: center;
        align-items: center;
      }
    }

    .user-profile {
      margin-top: 8px;
      padding: 8px 12px;
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      border-radius: 24px;
      transition: background-color 0.2s, padding 0.3s, gap 0.3s;

      &:hover {
        background-color: #f0f1f3;
      }
      
      .avatar {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background-color: #b3261e; // Red-ish like the screenshot
        color: white;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 500;
        flex-shrink: 0;
      }

      .user-info {
        flex: 1;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: center;
        
        .name-row {
          display: flex;
          align-items: center;
          gap: 4px;
          margin-bottom: 2px;
        }

        .username {
          font-size: 14px;
          font-weight: 500;
          color: #333;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        
        .gold-tag-mini {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          background: linear-gradient(135deg, #FFD700 0%, #FDB931 100%);
          color: #8a6d3b;
          border-radius: 4px;
          width: 16px;
          height: 16px;
          font-size: 10px;
          flex-shrink: 0;
          cursor: help;
        }

        .email-mini {
          font-size: 11px;
          color: #999;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
      }
    }
  }
}
</style>

<style lang="scss">
.user-profile-popover {
  padding: 0 !important;
  border-radius: 16px !important;
  border: 1px solid #e0e0e0 !important;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
  overflow: hidden;
}

.customer-service-popover {
  padding: 0 !important;
  border-radius: 16px !important;
  border: 1px solid #ececec !important;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12) !important;
  overflow: hidden;
}

.cs-panel-anchor {
  position: absolute;
  top: 0;
  right: 0;
  width: 1px;
  height: 1px;
  pointer-events: none;
}

.cs-card {
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, #ffffff 0%, #fbfbfc 100%);
  padding: 14px 14px 16px;
}

.cs-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding-bottom: 12px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
}

.cs-title {
  font-size: 15px;
  font-weight: 600;
  color: #111827;
  letter-spacing: 0.2px;
}

.cs-subtitle {
  font-size: 12px;
  color: #6b7280;
  line-height: 1.4;
}

.cs-body {
  display: flex;
  gap: 12px;
  padding-top: 12px;
  align-items: flex-start;
}

.cs-qr {
  width: 120px;
  height: 120px;
  border-radius: 14px;
  background-color: #f7f7f7;
  border: 1px solid #eeeeee;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  flex-shrink: 0;
}

.cs-qr img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.cs-qr-empty {
  font-size: 12px;
  color: #9ca3af;
}

.cs-qr-skeleton {
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, #f0f0f0 0%, #f7f7f7 50%, #f0f0f0 100%);
  background-size: 200% 100%;
  animation: csShimmer 1.2s ease-in-out infinite;
}

.cs-desc {
  flex: 1;
  min-width: 0;
}

.cs-desc-text {
  font-size: 13px;
  color: #111827;
  line-height: 1.6;
  white-space: pre-line;
}

.cs-desc-skeleton {
  height: 72px;
  border-radius: 10px;
  background: linear-gradient(90deg, #f0f0f0 0%, #f7f7f7 50%, #f0f0f0 100%);
  background-size: 200% 100%;
  animation: csShimmer 1.2s ease-in-out infinite;
}

@keyframes csShimmer {
  0% { background-position: 0% 0; }
  100% { background-position: 200% 0; }
}

.profile-card-content {
  display: flex;
  flex-direction: column;
  background-color: #fff;
  padding: 8px;
  position: relative;
  
  .pc-header {
    display: flex;
    align-items: center;
    padding-bottom: 16px;
    border-bottom: 1px solid #f0f0f0;
    margin-bottom: 16px;
    gap: 12px;
    
    .pc-user-details {
      flex: 1;
      overflow: hidden;
      
      .pc-name-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
      }
      
      .pc-name {
        font-weight: 600;
        font-size: 16px;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      
      .gold-tag {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        padding: 1px 6px;
        background: linear-gradient(135deg, #FFD700 0%, #FDB931 100%);
        color: #8a6d3b;
        border-radius: 10px;
        font-size: 10px;
        font-weight: bold;
        white-space: nowrap;
        text-shadow: 0 1px 0 rgba(255,255,255,0.4);
      }
      
      .pc-email {
        font-size: 12px;
        color: #999;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      
      .pc-expire {
        font-size: 11px;
        color: #e6a23c;
        margin-top: 2px;
      }
    }
  }
}

.pc-points-card {
  margin: 0 16px 8px;
  background-color: #f8f9fa;
  border-radius: 8px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  transition: background-color 0.2s;
}
.pc-points-card:hover {
  background-color: #f0f1f3;
}
.pc-points-card .label {
  font-size: 14px;
  color: #444746;
}
.pc-points-card .value-row {
  display: flex;
  align-items: center;
  gap: 4px;
  color: #444746;
}
.pc-points-card .value {
  font-size: 14px;
  font-weight: 500;
}

.pc-menu {
  padding: 8px 0;
}

.pc-menu-item {
  height: 44px;
  padding: 0 20px;
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: #1f1f1f;
  cursor: pointer;
  transition: background-color 0.2s;
}

.pc-menu-item:hover {
  background-color: #f0f1f3;
}

.pc-menu-item .el-icon {
  font-size: 18px;
  color: #444746;
}

.pc-menu-item .arrow-right {
  margin-left: auto;
  font-size: 14px;
  color: #757575;
}

.pc-menu-item.logout {
  margin-top: 4px;
  border-top: 1px solid #f0f0f0;
}

.account-management-dialog {
  border-radius: 16px !important;
}

.account-management-dialog .el-dialog__header {
  padding: 16px 20px 0 !important;
}

.account-management-dialog .el-dialog__body {
  padding: 12px 20px 20px !important;
}

.account-dialog-title {
  font-size: 16px;
  font-weight: 600;
  color: #1f1f1f;
}

.account-panel {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.account-summary {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  background-color: #f8f9fa;
  border-radius: 12px;
}

.account-summary .meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.account-summary .meta .name {
  font-size: 15px;
  font-weight: 600;
  color: #1f1f1f;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.account-summary .meta .sub {
  font-size: 12px;
  color: #757575;
}

.account-cards {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.account-cards .card {
  border: 1px solid #f0f0f0;
  border-radius: 12px;
  padding: 14px;
  background-color: #fff;
}

.account-cards .card-title {
  font-size: 13px;
  font-weight: 600;
  color: #1f1f1f;
  margin-bottom: 10px;
}

.info-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 0;
  gap: 12px;
}

.info-row .label {
  font-size: 12px;
  color: #757575;
  flex-shrink: 0;
}

.info-row .value {
  font-size: 13px;
  color: #1f1f1f;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 260px;
  text-align: right;
}

.account-tabs {
  margin-top: -6px;
}

.send-code-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 4px;
}
</style>
