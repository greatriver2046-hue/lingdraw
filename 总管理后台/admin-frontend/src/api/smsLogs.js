import request from '@/utils/request'

export function getSmsLogs(params) {
  return request({
    url: '/admin/sms-logs',
    method: 'get',
    params
  })
}
