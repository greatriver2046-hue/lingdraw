<template>
  <div class="payment-config">
    <el-card class="box-card" shadow="never">
      <div class="config-container" v-loading="loading">
        <!-- WeChat Pay Config -->
        <el-card class="config-section" shadow="never">
          <template #header>
            <div class="section-header">
              <span class="wechat-title">
                <el-icon class="icon"><ChatDotSquare /></el-icon> 微信支付
              </span>
              <el-switch v-model="form.wechat.is_enabled" active-text="启用" inactive-text="禁用" />
            </div>
          </template>
          
          <el-form :model="form.wechat" label-width="120px" :disabled="!form.wechat.is_enabled">
            <el-form-item label="AppID" prop="app_id" :rules="[{ required: true, message: '请输入微信支付AppID', trigger: 'blur' }]">
              <el-input v-model="form.wechat.app_id" placeholder="请输入微信支付绑定的AppID (公众号/小程序ID)" />
            </el-form-item>
            <el-form-item label="商户号 (MCHID)" prop="mch_id" :rules="[{ required: true, message: '请输入商户号', trigger: 'blur' }]">
              <el-input v-model="form.wechat.mch_id" placeholder="请输入微信支付商户号" />
            </el-form-item>
            <el-form-item label="API密钥" prop="api_key">
              <el-input v-model="form.wechat.api_key" type="password" show-password placeholder="请输入API密钥 (已隐藏)" />
            </el-form-item>
            <el-form-item label="支付回调URL">
              <el-input v-model="form.wechat.notify_url" placeholder="https://api.yourdomain.com/api/payment/notify/wechat" />
            </el-form-item>
            <el-form-item label="API证书">
              <el-upload
                class="cert-uploader"
                action="#"
                :show-file-list="false"
                :http-request="(opt) => uploadCert(opt, 'wechat')"
                accept=".pem,.p12"
              >
                <el-button type="primary" plain size="small">上传证书</el-button>
                <span v-if="form.wechat.cert_content" class="cert-status success">
                  <el-icon><CircleCheck /></el-icon> 已上传
                </span>
              </el-upload>
              <div class="form-tip">上传 apiclient_cert.pem 或 .p12 证书文件</div>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- Alipay Config -->
        <el-card class="config-section" shadow="never">
          <template #header>
            <div class="section-header">
              <span class="alipay-title">
                <el-icon class="icon"><Wallet /></el-icon> 支付宝支付
              </span>
              <el-switch v-model="form.alipay.is_enabled" active-text="启用" inactive-text="禁用" />
            </div>
          </template>
          
          <el-form :model="form.alipay" label-width="120px" :disabled="!form.alipay.is_enabled">
            <el-form-item label="应用ID (AppID)" prop="app_id" :rules="[{ required: true, message: '请输入应用ID', trigger: 'blur' }]">
              <el-input v-model="form.alipay.app_id" placeholder="请输入支付宝应用ID" />
            </el-form-item>
            <el-form-item label="商户私钥" prop="private_key">
              <el-input v-model="form.alipay.private_key" type="textarea" :rows="3" placeholder="请输入商户私钥 (已隐藏)" />
            </el-form-item>
            <el-form-item label="支付宝公钥" prop="public_key">
              <el-input v-model="form.alipay.public_key" type="textarea" :rows="3" placeholder="请输入支付宝公钥" />
            </el-form-item>
            <el-form-item label="支付回调URL">
              <el-input v-model="form.alipay.notify_url" placeholder="https://api.yourdomain.com/api/payment/notify/alipay" />
            </el-form-item>
          </el-form>
        </el-card>

        <div class="actions">
          <el-button type="primary" size="large" @click="saveConfig" :loading="saving">保存所有配置</el-button>
          <el-button size="large" @click="testConnection">测试连接</el-button>
          <el-button size="large" type="warning" @click="showSimulateDialog = true">模拟回调测试</el-button>
        </div>
      </div>
    </el-card>

    <!-- Simulation Dialog -->
    <el-dialog v-model="showSimulateDialog" title="模拟支付回调 (本地测试用)" width="500px">
      <el-form :model="simulateForm" label-width="100px">
        <el-alert
          title="注意：此功能仅用于开发或内网环境测试支付成功回调逻辑，会直接绕过签名验证将订单置为已支付。"
          type="warning"
          :closable="false"
          style="margin-bottom: 20px"
        />
        <el-form-item label="支付方式">
          <el-radio-group v-model="simulateForm.type">
            <el-radio label="wechat">微信支付</el-radio>
            <!-- <el-radio label="alipay">支付宝</el-radio> -->
          </el-radio-group>
        </el-form-item>
        <el-form-item label="订单号" required>
          <el-input v-model="simulateForm.order_no" placeholder="请输入待支付的订单号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <span class="dialog-footer">
          <el-button @click="showSimulateDialog = false">取消</el-button>
          <el-button type="primary" @click="handleSimulate" :loading="simulating">发送模拟回调</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ChatDotSquare, Wallet, CircleCheck } from '@element-plus/icons-vue'
import request from '../../utils/request'
import axios from 'axios'

const loading = ref(false)
const saving = ref(false)

// Simulation
const showSimulateDialog = ref(false)
const simulating = ref(false)
const simulateForm = reactive({
  type: 'wechat',
  order_no: ''
})

const handleSimulate = async () => {
  if (!simulateForm.order_no) {
    ElMessage.warning('请输入订单号')
    return
  }
  
  simulating.value = true
  try {
    // Construct XML payload
    const timestamp = Math.floor(Date.now() / 1000)
    const xml = `<xml>
      <out_trade_no><![CDATA[${simulateForm.order_no}]]></out_trade_no>
      <transaction_id><![CDATA[TEST_${timestamp}]]></transaction_id>
      <total_fee>1</total_fee>
      <result_code><![CDATA[SUCCESS]]></result_code>
      <return_code><![CDATA[SUCCESS]]></return_code>
      <openid><![CDATA[test_openid]]></openid>
      <trade_type><![CDATA[NATIVE]]></trade_type>
    </xml>`

    // Direct POST to callback URL using raw axios to bypass interceptors
    // Add test_bypass_sign=1 to URL query string
    const res = await axios.post('/api/callback/payment/notify/wechat?test_bypass_sign=1', xml, {
      headers: {
        'Content-Type': 'text/xml'
      }
    })

    // Check response (WeChat returns XML)
    if (res.data && res.data.includes('SUCCESS')) {
       ElMessageBox.alert(
        `模拟请求已发送到回调接口。<br/>
         回调响应: <pre>${res.data}</pre>
         <br/>请刷新用户端页面查看套餐是否到账。`, 
        '测试成功', 
        { dangerouslyUseHTMLString: true }
      )
      showSimulateDialog.value = false
    } else {
       ElMessageBox.alert(
        `模拟请求发送成功，但回调响应异常。<br/>响应内容: ${res.data}`,
        '测试警告',
        { dangerouslyUseHTMLString: true, type: 'warning' }
      )
    }

  } catch (e) {
    console.error(e)
    const errorMsg = e.response ? `状态码: ${e.response.status}<br/>数据: ${e.response.data}` : e.message
    ElMessageBox.alert(
      `请求失败: ${errorMsg}`,
      '测试失败',
      { dangerouslyUseHTMLString: true, type: 'error' }
    )
  } finally {
    simulating.value = false
  }
}

const form = reactive({
  wechat: {
    is_enabled: false,
    mch_id: '',
    api_key: '',
    notify_url: '',
    cert_content: ''
  },
  alipay: {
    is_enabled: false,
    app_id: '',
    private_key: '',
    public_key: '',
    notify_url: ''
  }
})

onMounted(() => {
  fetchConfig()
})

const fetchConfig = async () => {
  loading.value = true
  try {
    const res = await request.get('/admin/payment/config')
    if (res.code === 200) {
      // Merge data
      if (res.data.wechat) Object.assign(form.wechat, res.data.wechat)
      if (res.data.alipay) Object.assign(form.alipay, res.data.alipay)
      
      // Convert is_enabled to boolean if it comes as 0/1
      form.wechat.is_enabled = !!form.wechat.is_enabled
      form.alipay.is_enabled = !!form.alipay.is_enabled
    }
  } catch (error) {
    console.error('Failed to fetch config', error)
  } finally {
    loading.value = false
  }
}

const saveConfig = async () => {
  saving.value = true
  try {
    const dataToSave = {
      wechat: { ...form.wechat, is_enabled: form.wechat.is_enabled ? 1 : 0 },
      alipay: { ...form.alipay, is_enabled: form.alipay.is_enabled ? 1 : 0 }
    }
    const res = await request.post('/admin/payment/config', dataToSave)
    if (res.code === 200) {
      ElMessage.success('保存成功')
      fetchConfig() // Refresh to get masked data
    } else {
      ElMessage.error(res.msg || '保存失败')
    }
  } catch (error) {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

const uploadCert = async (option, type) => {
  const formData = new FormData()
  formData.append('file', option.file)
  formData.append('type', type)
  
  try {
    const res = await request.post('/admin/payment/upload_cert', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    })
    
    if (res.code === 200) {
      ElMessage.success('证书上传成功')
      form[type].cert_content = 'Has Certificate'
    } else {
      ElMessage.error(res.msg || '证书上传失败')
    }
  } catch (error) {
    ElMessage.error('证书上传失败')
  }
}

const testConnection = () => {
  // This would ideally call a backend test endpoint
  ElMessage.info('测试连接功能开发中...')
}
</script>

<style scoped>
.payment-config {
  padding: 20px;
}

.box-card {
  border: none;
}

.config-container {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.config-section {
  border: none;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.wechat-title {
  color: #07c160;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: bold;
}

.alipay-title {
  color: #1677ff;
  display: flex;
  align-items: center;
  gap: 5px;
  font-weight: bold;
}

.cert-status {
  margin-left: 10px;
  color: #67c23a;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.form-tip {
  font-size: 12px;
  color: #909399;
  margin-top: 5px;
}

.actions {
  margin-top: 20px;
  display: flex;
  gap: 15px;
}
</style>
