import request from '@/utils/request'

export function getUserList(params) {
  return request({
    url: '/admin/app-users',
    method: 'get',
    params
  })
}

export function deleteUser(id) {
  return request({
    url: `/admin/app-users/${id}`,
    method: 'delete'
  })
}

export function updateUserStatus(id, status) {
  return request({
    url: `/admin/app-users/${id}/status`,
    method: 'put',
    data: { status }
  })
}

export function updateUser(id, data) {
  return request({
    url: `/admin/app-users/${id}`,
    method: 'put',
    data
  })
}
