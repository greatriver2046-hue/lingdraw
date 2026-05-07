import request from '@/utils/request'

export function getErrorLogs(params) {
  return request({
    url: '/admin/system-errors',
    method: 'get',
    params
  })
}

