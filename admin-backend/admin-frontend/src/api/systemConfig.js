import request from '@/utils/request'

export function getOssConfig() {
  return request({
    url: '/admin/system/configs/oss',
    method: 'get'
  })
}

export function updateOssConfig(data) {
  return request({
    url: '/admin/system/configs/oss',
    method: 'put',
    data
  })
}

export function getConfig(category) {
  return request({
    url: `/admin/system/configs/${category}`,
    method: 'get'
  })
}

export function updateConfig(category, data) {
  return request({
    url: `/admin/system/configs/${category}`,
    method: 'put',
    data
  })
}

export function uploadCustomerServiceWechatQr(file) {
  const formData = new FormData()
  formData.append('file', file)
  return request({
    url: '/admin/system/customer_service/wechat_qr',
    method: 'post',
    data: formData,
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}
