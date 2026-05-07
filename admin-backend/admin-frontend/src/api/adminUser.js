import request from '@/utils/request'

export function getAdminList(params) {
  return request({
    url: '/admin/users',
    method: 'get',
    params
  })
}

export function createAdmin(data) {
  return request({
    url: '/admin/users',
    method: 'post',
    data
  })
}

export function updateAdmin(id, data) {
  return request({
    url: `/admin/users/${id}`,
    method: 'put',
    data
  })
}

export function deleteAdmin(id) {
  return request({
    url: `/admin/users/${id}`,
    method: 'delete'
  })
}

export function updateAdminStatus(id, status) {
  return request({
    url: `/admin/users/${id}/status`,
    method: 'put',
    data: { status }
  })
}
