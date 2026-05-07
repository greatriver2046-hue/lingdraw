import request from '@/utils/request'

export function getSystemPrompts() {
  return request({
    url: '/admin/system/prompts',
    method: 'get'
  })
}

export function saveSystemPrompts(data) {
  return request({
    url: '/admin/system/prompts',
    method: 'put',
    data
  })
}

