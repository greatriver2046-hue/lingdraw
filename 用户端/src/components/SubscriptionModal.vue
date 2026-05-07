<template>
  <el-dialog
    v-model="visible"
    width="1000px"
    :show-close="false"
    :close-on-click-modal="false"
    class="subscription-modal"
    append-to-body
    destroy-on-close
  >
    <div class="bili-modal-container">
      <!-- Warning Mask -->
      <div class="overwrite-warning-mask" v-if="warningVisible">
        <div class="warning-content">
          <el-icon class="warning-icon"><WarningFilled /></el-icon>
          <p class="warning-text">你的套餐尚未到期且积分未用完，新购买套餐将会将原套餐内容完全覆盖！</p>
          <el-button type="primary" color="#cfa972" class="confirm-btn" @click="warningVisible = false">确认</el-button>
        </div>
      </div>

      <!-- Left Panel: Selection -->
      <div class="left-panel">
        <!-- User Info Header -->
        <div class="user-header">
          <div class="avatar-wrapper">
            <div class="avatar-img" :style="{ backgroundColor: '#e0e0e0' }">
              <img v-if="userStore.userInfo?.avatar" :src="userStore.userInfo.avatar" />
              <span v-else>{{ (userStore.userInfo?.username || 'U').charAt(0).toUpperCase() }}</span>
            </div>
            <div class="vip-badge" v-if="userStore.userInfo?.package_name">
              <el-icon><Medal /></el-icon>
            </div>
          </div>
          <div class="user-details">
            <div class="username-row">
              <span class="username">{{ userStore.userInfo?.username || '用户' }}</span>
              <span class="user-id">({{ maskUserId(userStore.userInfo?.id) }})</span>
            </div>
            <div class="status-row">
              <span class="status-text" :class="{ 'is-active': userStore.userInfo?.package_name }">
                {{ userStore.userInfo?.package_name ? `${userStore.userInfo.package_name}生效中` : '当前未开通会员' }}
              </span>
              <el-icon class="info-icon"><InfoFilled /></el-icon>
            </div>
          </div>
        </div>

        <!-- Package Selection -->
        <div class="package-section">
          <div class="section-header">
            <span class="section-title">会员套餐</span>
            <span class="section-subtitle">解锁高级模型与极速体验</span>
          </div>

          <div class="packages-container">
            <div class="scroll-btn left" @click="scrollPackages('left')" v-show="canScrollLeft">
              <el-icon><ArrowLeft /></el-icon>
            </div>
            
            <div class="packages-grid" v-loading="loading" ref="packagesGridRef" @scroll="checkScroll">
              <div 
                v-for="(pkg, index) in displayPackages" 
                :key="pkg.id"
                class="pkg-card"
                :class="{ 'active': selectedPackage?.id === pkg.id }"
                @click="selectPackage(pkg)"
              >
                <div class="pkg-tag" v-if="index === 0">限时特惠</div>
                <div class="pkg-tag hot" v-if="index === 1">热销推荐</div>
                
                <div class="pkg-name">{{ pkg.name }}</div>
                <div class="pkg-price-row">
                  <span class="currency">¥</span>
                  <span class="price">{{ pkg.price }}</span>
                </div>
                <div class="pkg-original-price">¥{{ Number(pkg.original_price) > 0 ? Number(pkg.original_price) : (pkg.price * 1.5).toFixed(0) }}</div>
                
                <div class="pkg-features" v-if="pkg.description">
                  <div v-for="(feature, fIndex) in parseDescription(pkg.description)" :key="fIndex" class="pkg-feature-item">
                    <el-icon class="pkg-feature-icon"><Check /></el-icon>
                    <span>{{ feature }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="scroll-btn right" @click="scrollPackages('right')" v-show="canScrollRight">
              <el-icon><ArrowRight /></el-icon>
            </div>
          </div>
        </div>

        <!-- Footer Info -->
        <div class="left-footer">
          <p class="auto-renew-tip">
            <el-icon><Refresh /></el-icon>
            支持随时退订，到期自动断开
          </p>
        </div>
      </div>

      <!-- Right Panel: Payment -->
      <div class="right-panel">
        <div class="right-header">
          <div class="header-links">
            <span>赠送好友</span>
            <span class="divider">|</span>
            <span>积分兑换</span>
          </div>
          <div class="close-icon" @click="handleClose">
            <el-icon><Close /></el-icon>
          </div>
        </div>

        <div class="payment-content">
          <div class="pay-price">
            <span class="currency">¥</span>
            <span class="amount">{{ selectedPackage?.price || '0' }}</span>
          </div>

          <!-- QR Code Area -->
          <div class="qr-panel">
            <div class="qr-wrapper" v-loading="qrLoading">
              <div v-if="qrCodeUrl" class="qr-img-container">
                <img :src="qrCodeUrl" class="qr-code-img" />
                <div class="qr-overlay" v-if="paymentStatus === 'success'">
                  <el-icon class="success-icon"><CircleCheckFilled /></el-icon>
                  <span>支付成功</span>
                </div>
              </div>
              <div v-else-if="!selectedPackage" class="qr-placeholder">
                请选择套餐
              </div>
              <div v-else-if="!paymentMethod" class="qr-placeholder">
                当前未开启支付方式
              </div>
              <div v-else class="qr-placeholder">
                {{ qrLoading ? '正在生成支付二维码...' : '加载失败，请重试' }}
              </div>
            </div>
            
            <div class="scan-tip" v-if="paymentMethod">
              <el-icon v-if="paymentMethod === 'wechat'" class="pay-icon-small" color="#07c160"><ChatDotSquare /></el-icon>
              <el-icon v-else class="pay-icon-small" color="#1677ff"><Wallet /></el-icon>
              <span v-if="paymentMethod === 'wechat'">使用微信扫码支付</span>
              <span v-else>使用支付宝扫码支付</span>
            </div>
          </div>

          <!-- Payment Method Toggle -->
          <div class="payment-methods" v-if="visiblePaymentMethods.length">
            <div 
              v-if="isPaymentMethodEnabled('wechat')"
              class="method-item" 
              :class="{ active: paymentMethod === 'wechat' }"
              @click="switchMethod('wechat')"
            >
              <el-icon class="wechat-icon"><ChatDotSquare /></el-icon>
              <span>微信</span>
            </div>
            <div 
              v-if="isPaymentMethodEnabled('alipay')"
              class="method-item" 
              :class="{ active: paymentMethod === 'alipay' }"
              @click="switchMethod('alipay')"
            >
              <el-icon class="alipay-icon"><Wallet /></el-icon>
              <span>支付宝</span>
            </div>
          </div>
        </div>

        <div class="right-footer">
          <div class="agreement-row">
            <el-checkbox v-model="agreed" size="small">
              已阅读并同意 <span class="link">《会员服务协议》</span>
            </el-checkbox>
          </div>
        </div>
      </div>
    </div>
  </el-dialog>
</template>

<script setup>
import { ref, computed, watch, onUnmounted, onMounted, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import { 
  Close, Medal, InfoFilled, Refresh, 
  ChatDotSquare, Wallet, CircleCheckFilled, Check,
  ArrowLeft, ArrowRight, WarningFilled
} from '@element-plus/icons-vue'
import request from '@/utils/request'
import { useUserStore } from '@/stores/user'

const props = defineProps({
  modelValue: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['update:modelValue'])
const userStore = useUserStore()

// State
const loading = ref(false)
const packages = ref([])
const selectedPackage = ref(null)
const paymentMethod = ref('wechat') // wechat | alipay
const enabledPaymentMethods = ref(null)
const qrLoading = ref(false)
const qrCodeUrl = ref('')
const orderNo = ref('')
const paymentStatus = ref('') // pending | success
const agreed = ref(true)
const warningVisible = ref(false)
let pollTimer = null

// Computed
const visible = computed({
  get: () => props.modelValue,
  set: (val) => {
    emit('update:modelValue', val)
    if (!val) {
      stopPolling()
      loading.value = false
      qrLoading.value = false
      qrCodeUrl.value = ''
      paymentStatus.value = ''
      orderNo.value = ''
      packages.value = []
      selectedPackage.value = null
      warningVisible.value = false
    }
  }
})

const displayPackages = computed(() => packages.value)
const visiblePaymentMethods = computed(() => {
  if (enabledPaymentMethods.value === null) return ['wechat', 'alipay']
  return enabledPaymentMethods.value
})

// Methods
const maskUserId = (id) => {
  if (!id) return '000'
  return id.toString().padStart(6, '0')
}

const parseDescription = (desc) => {
  if (!desc) return []
  return desc.split(/[\n,，]/).filter(item => item.trim())
}

const handleClose = () => {
  visible.value = false
}

const isPaymentMethodEnabled = (method) => {
  return visiblePaymentMethods.value.includes(method)
}

const ensureValidPaymentMethod = () => {
  if (enabledPaymentMethods.value === null) return
  if (enabledPaymentMethods.value.includes(paymentMethod.value)) return

  const previousMethod = paymentMethod.value
  paymentMethod.value = enabledPaymentMethods.value[0] || ''
  stopPolling()
  qrLoading.value = false
  qrCodeUrl.value = ''
  paymentStatus.value = ''
  orderNo.value = ''

  if (props.modelValue && selectedPackage.value && paymentMethod.value && previousMethod !== paymentMethod.value) {
    createOrderAndGetQR()
  }
}

const fetchPaymentMethods = async () => {
  try {
    const response = await request.get('/api/v1/payment/methods')
    const res = response.data
    if (res?.code === 200 && Array.isArray(res.data)) {
      enabledPaymentMethods.value = res.data
    } else {
      enabledPaymentMethods.value = null
    }
  } catch (e) {
    enabledPaymentMethods.value = null
  } finally {
    ensureValidPaymentMethod()
  }
}

const fetchPackages = async () => {
  loading.value = true
  try {
    const response = await request.get('/api/public/packages')
    const res = response.data
    if (res.code === 200 && res.data) {
      packages.value = res.data
      // Auto select middle or first package
      if (packages.value.length > 0) {
        selectPackage(packages.value[1] || packages.value[0])
      }
    }
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
}

const selectPackage = (pkg) => {
  if (selectedPackage.value?.id === pkg.id) return
  selectedPackage.value = pkg
  if (props.modelValue) {
    createOrderAndGetQR()
  }
}

const switchMethod = (method) => {
  if (paymentMethod.value === method) return
  if (!isPaymentMethodEnabled(method)) return
  paymentMethod.value = method
  if (props.modelValue) {
    createOrderAndGetQR()
  }
}

const createOrderAndGetQR = async () => {
  if (!props.modelValue) return
  if (!selectedPackage.value) return
  if (!paymentMethod.value) {
    ElMessage.error('当前未开启支付方式')
    return
  }
  
  stopPolling()
  qrLoading.value = true
  qrCodeUrl.value = ''
  paymentStatus.value = 'pending'
  
  try {
    // 1. Create Order
    const orderRes = await request.post('/api/v1/order/create', {
      package_id: selectedPackage.value.id,
      payment_method: paymentMethod.value
    })
    
    if (orderRes.data.code !== 200) throw new Error(orderRes.data.msg)
    
    const order = orderRes.data.data
    orderNo.value = order.order_no
    
    // 2. Get Payment QR
    const payRes = await request.post('/api/v1/payment/pay', {
      order_no: order.order_no
    })
    
    if (payRes.data.code !== 200) throw new Error(payRes.data.msg)
    
    const payData = payRes.data.data
    
    if (paymentMethod.value === 'wechat' && payData.type === 'qrcode') {
       let url = payData.qr_code
       if (!url.startsWith('http') && !url.startsWith('data:')) {
           url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(url)
       }
       qrCodeUrl.value = url
       startPolling()
    } else if (paymentMethod.value === 'alipay' && payData.url) {
       // For Alipay, we usually redirect, but for this UI we want a QR code.
       // If backend returns a URL to open, we can try to generate a QR for it.
       // Or show a "Click to Pay" button.
       // For this demo, let's assume we can QR-ify the URL.
       qrCodeUrl.value = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(payData.url)
       startPolling()
    }
    
  } catch (e) {
    ElMessage.error(e.message || '获取支付信息失败')
  } finally {
    qrLoading.value = false
  }
}

const startPolling = () => {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = setInterval(async () => {
    try {
      const res = await request.get('/api/v1/order/status', {
        params: { order_no: orderNo.value }
      })
      if (res.data.code === 200 && res.data.data.status === 1) {
        paymentStatus.value = 'success'
        stopPolling()
        userStore.getUserInfo()
        setTimeout(() => {
          ElMessage.success('支付成功！')
        }, 500)
        // 3秒后自动关闭面板
        setTimeout(() => {
          visible.value = false
        }, 3000)
      }
    } catch (e) {}
  }, 2000)
}

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

watch(() => props.modelValue, async (val) => {
  if (val) {
    stopPolling()
    loading.value = false
    qrLoading.value = false
    qrCodeUrl.value = ''
    paymentStatus.value = ''
    orderNo.value = ''
    packages.value = []
    selectedPackage.value = null
    warningVisible.value = false
    enabledPaymentMethods.value = null
    paymentMethod.value = 'wechat'
    fetchPaymentMethods()
    fetchPackages()
    // Refresh user info to ensure points/expiry are up to date
    await userStore.getUserInfo()
    
    nextTick(() => {
      checkScroll()
      checkOverwriteWarning()
    })
  }
})

const checkOverwriteWarning = () => {
  const user = userStore.userInfo || {}
  const now = new Date().getTime()
  
  // Use package_expire_time from append or package_end_time from raw
  let expireStr = user.package_expire_time || user.package_end_time
  
  if (!expireStr) {
    warningVisible.value = false
    return
  }
  
  // Fix date format compatibility (e.g. Safari)
  expireStr = expireStr.toString().replace(/-/g, '/')
  
  const expireTime = new Date(expireStr).getTime()
  const hasPoints = (Number(user.period_points) || 0) > 0
  
  console.log('Overwrite Check:', {
      now: new Date().toLocaleString(),
      expireStr,
      expireTime: new Date(expireTime).toLocaleString(),
      points: user.period_points,
      hasPoints,
      isNotExpired: expireTime > now,
      warningVisible: expireTime > now && hasPoints
  })
  
  if (expireTime > now && hasPoints) {
    warningVisible.value = true
  } else {
    warningVisible.value = false
  }
}

const packagesGridRef = ref(null)
const canScrollLeft = ref(false)
const canScrollRight = ref(false)

const checkScroll = () => {
  if (!packagesGridRef.value) return
  const { scrollLeft, scrollWidth, clientWidth } = packagesGridRef.value
  canScrollLeft.value = scrollLeft > 0
  canScrollRight.value = scrollLeft + clientWidth < scrollWidth - 1
}

const scrollPackages = (direction) => {
  if (!packagesGridRef.value) return
  const scrollAmount = 200 // Scroll width of one card + gap
  const currentScroll = packagesGridRef.value.scrollLeft
  const targetScroll = direction === 'left' 
    ? currentScroll - scrollAmount 
    : currentScroll + scrollAmount
    
  packagesGridRef.value.scrollTo({
    left: targetScroll,
    behavior: 'smooth'
  })
}

// Watch packages to update scroll state
watch(() => displayPackages.value, () => {
  nextTick(checkScroll)
})

onMounted(() => {
  window.addEventListener('resize', checkScroll)
})

onUnmounted(() => {
  stopPolling()
  window.removeEventListener('resize', checkScroll)
})
</script>

<style>
/* Global overrides */
.subscription-modal.el-dialog {
  background: transparent !important;
  box-shadow: none !important;
  margin-top: 8vh !important;
  width: 1000px !important;
  max-width: 95vw;
}
.subscription-modal .el-dialog__header,
.subscription-modal .el-dialog__body {
  padding: 0 !important;
  background: transparent !important;
}
</style>

<style scoped>
.bili-modal-container {
  position: relative;
  display: flex;
  background: #fff;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
  height: 550px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

/* Left Panel */
.left-panel {
  flex: 0 0 70%;
  background: #fcfcfc;
  padding: 30px;
  display: flex;
  flex-direction: column;
  border-right: 1px solid #f0f0f0;
  overflow: hidden; /* Ensure content doesn't spill */
}

/* User Header */
.user-header {
  display: flex;
  align-items: center;
  margin-bottom: 30px;
}

.avatar-wrapper {
  position: relative;
  margin-right: 15px;
}

.avatar-img {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #666;
}

.avatar-img img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.vip-badge {
  position: absolute;
  bottom: -2px;
  right: -2px;
  background: #cfa972;
  color: #fff;
  border-radius: 50%;
  width: 18px;
  height: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  border: 2px solid #fff;
}

.username-row {
  margin-bottom: 4px;
}

.username {
  font-size: 16px;
  font-weight: 600;
  color: #333;
  margin-right: 8px;
}

.user-id {
  font-size: 12px;
  color: #999;
}

.status-row {
  display: flex;
  align-items: center;
  font-size: 12px;
  color: #999;
}

.status-text.is-active {
  color: #cfa972;
  background: rgba(207, 169, 114, 0.1);
  padding: 2px 6px;
  border-radius: 4px;
  margin-right: 5px;
}

.info-icon {
  margin-left: 5px;
  cursor: pointer;
}

/* Package Section */
.package-section {
  flex: 1;
}

.section-header {
  margin-bottom: 15px;
  display: flex;
  align-items: baseline;
}

.section-title {
  font-size: 18px;
  font-weight: bold;
  color: #333;
  margin-right: 10px;
}

.section-subtitle {
  font-size: 12px;
  color: #999;
}

.packages-container {
  position: relative;
  display: flex;
  align-items: center;
  width: 100%;
}

.packages-grid {
  display: flex;
  gap: 12px;
  overflow-x: auto;
  padding-top: 10px;
  padding-bottom: 10px;
  scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none; /* IE/Edge */
  scroll-behavior: smooth;
  width: 100%;
}

.packages-grid::-webkit-scrollbar {
  display: none; /* Chrome/Safari/Opera */
}

.scroll-btn {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 2;
  transition: all 0.2s;
  color: #666;
}

.scroll-btn:hover {
  background: #f5f7fa;
  color: #cfa972;
  transform: translateY(-50%) scale(1.1);
}

.scroll-btn.left {
  left: -16px;
}

.scroll-btn.right {
  right: -16px;
}

.pkg-card {
  flex: 0 0 180px; /* Fixed width, no shrink/grow */
  position: relative;
  border: 2px solid #e0e0e0;
  border-radius: 10px;
  padding: 20px 10px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s;
  background: #fff;
  min-height: 280px;
  display: flex;
  flex-direction: column;
}

.pkg-card:hover {
  border-color: #cfa972;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(207, 169, 114, 0.2);
}

.pkg-card.active {
  border-color: #cfa972;
  background: #fcf9f2;
}

.pkg-tag {
  position: absolute;
  top: 0;
  left: 0;
  background: #cfa972;
  color: #fff;
  font-size: 10px;
  padding: 2px 8px;
  border-bottom-right-radius: 10px;
  border-top-left-radius: 8px;
}

.pkg-tag.hot {
  background: #333;
}

.pkg-name {
  font-size: 14px;
  color: #333;
  margin-bottom: 10px;
  margin-top: 10px;
}

.pkg-price-row {
  color: #333;
  font-weight: bold;
  margin-bottom: 5px;
}

.pkg-card.active .pkg-price-row {
  color: #ff6699;
}

.pkg-price-row .currency {
  font-size: 14px;
  margin-right: 2px;
}

.pkg-price-row .price {
  font-size: 28px;
}

.pkg-original-price {
  font-size: 12px;
  color: #ccc;
  text-decoration: line-through;
  margin-bottom: 10px;
}

.pkg-features {
  margin-top: 10px;
  text-align: left;
  padding: 0 10px;
  flex: 1;
}

.pkg-feature-item {
  display: flex;
  align-items: flex-start;
  font-size: 12px;
  color: #666;
  margin-bottom: 6px;
  line-height: 1.4;
}

.pkg-feature-icon {
  margin-right: 4px;
  color: #ff6699;
  flex-shrink: 0;
  margin-top: 2px;
}

/* Left Footer */
.left-footer {
  margin-top: 20px;
  padding-top: 15px;
  border-top: 1px dashed #eee;
}

.auto-renew-tip {
  font-size: 12px;
  color: #999;
  display: flex;
  align-items: center;
  gap: 5px;
  margin-bottom: 10px;
}

/* Right Panel */
.right-panel {
  flex: 1;
  background: #f9f9f9;
  padding: 30px 20px;
  display: flex;
  flex-direction: column;
  position: relative;
}

.right-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 30px;
}

.header-links {
  font-size: 12px;
  color: #999;
}

.header-links .divider {
  margin: 0 8px;
  color: #eee;
}

.close-icon {
  font-size: 20px;
  color: #ccc;
  cursor: pointer;
  transition: color 0.2s;
}

.close-icon:hover {
  color: #333;
}

.payment-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.pay-price {
  margin-bottom: 20px;
  color: #333;
}

.pay-price .currency {
  font-size: 20px;
  font-weight: bold;
}

.pay-price .amount {
  font-size: 40px;
  font-weight: bold;
  line-height: 1;
}

.qr-panel {
  background: #fff;
  padding: 15px;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.05);
  margin-bottom: 20px;
  text-align: center;
}

.qr-wrapper {
  width: 160px;
  height: 160px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #f8f8f8;
  border-radius: 8px;
  margin-bottom: 10px;
  position: relative;
}

.qr-img-container {
  width: 100%;
  height: 100%;
}

.qr-code-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.qr-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(255, 255, 255, 0.9);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: #67c23a;
  font-weight: bold;
}

.success-icon {
  font-size: 32px;
  margin-bottom: 5px;
}

.qr-placeholder {
  color: #ccc;
  font-size: 12px;
  padding: 0 20px;
}

.scan-tip {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  font-size: 12px;
  color: #666;
}

.pay-icon-small {
  width: 16px;
  height: 16px;
}

.payment-methods {
  display: flex;
  gap: 15px;
  margin-top: auto;
}

.method-item {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 8px 16px;
  border: 1px solid #e0e0e0;
  border-radius: 20px;
  cursor: pointer;
  font-size: 12px;
  color: #666;
  transition: all 0.2s;
  background: #fff;
}

.method-item:hover {
  border-color: #ccc;
}

.method-item.active {
  border-color: #cfa972;
  color: #cfa972;
  background: #fcf9f2;
}

.wechat-icon { color: #07c160; font-size: 16px; }
.alipay-icon { color: #1677ff; font-size: 16px; }

.right-footer {
  margin-top: 20px;
  text-align: center;
}

.agreement-row {
  font-size: 12px;
  color: #999;
}

.link {
  color: #333;
  cursor: pointer;
}

/* Responsive */
@media (max-width: 768px) {
  .subscription-modal.el-dialog {
    width: 95% !important;
    margin-top: 2vh !important;
  }
  
  .bili-modal-container {
    flex-direction: column;
    height: auto;
    max-height: 90vh;
    overflow-y: auto;
  }
  
  .left-panel, .right-panel {
    flex: none;
    width: 100%;
  }
  
  .packages-grid {
    grid-template-columns: 1fr;
  }
}

.overwrite-warning-mask {
  position: absolute;
  top: 25px;
  left: 25px;
  width: calc(100% - 50px);
  height: calc(100% - 50px);
  background: rgba(255, 255, 255, 0.9);
  border-radius: 16px;
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  backdrop-filter: blur(3px);
  animation: fadeIn 0.3s ease;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.warning-container {
  width: 360px;
  text-align: center;
  padding: 0 20px;
}

.icon-box {
  width: 64px;
  height: 64px;
  background: #fffbf0;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 20px;
}

.warning-icon {
  font-size: 32px;
  color: #faad14;
}

.warning-title {
  font-size: 20px;
  font-weight: 600;
  color: #333;
  margin: 0 0 12px;
  letter-spacing: 0.5px;
}

.warning-desc {
  font-size: 15px;
  color: #666;
  margin: 0 0 4px;
  line-height: 1.5;
}

.warning-sub-desc {
  font-size: 14px;
  color: #999;
  margin: 0 0 30px;
}

.highlight {
  color: #ff4d4f;
  font-weight: bold;
  margin: 0 4px;
}

.btn-group {
  display: flex;
  gap: 12px;
  justify-content: center;
}

.confirm-btn {
  width: 140px;
  height: 40px;
  border-radius: 20px;
  font-weight: 500;
  letter-spacing: 1px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
  transition: transform 0.1s;
}

.confirm-btn:active {
  transform: scale(0.98);
}

.cancel-btn {
  color: #999;
}

.cancel-btn:hover {
  color: #666;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

/* Fade transition for Vue */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
