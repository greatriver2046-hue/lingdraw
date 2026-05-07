import request from '@/utils/request'

export function getModelList(params) {
  return request({
    url: '/admin/models',
    method: 'get',
    params
  })
}

export function getAllModels() {
  return request({
    url: '/admin/models/all',
    method: 'get'
  })
}

export function createModel(data) {
  return request({
    url: '/admin/models',
    method: 'post',
    data
  })
}

export function updateModel(id, data) {
  return request({
    url: `/admin/models/${id}`,
    method: 'put',
    data
  })
}

export function deleteModel(id) {
  return request({
    url: `/admin/models/${id}`,
    method: 'delete'
  })
}

export function updateModelStatus(id, status) {
  return request({
    url: `/admin/models/${id}/status`,
    method: 'patch',
    params: { status }
  })
}

export function setModelDefault(id) {
  return request({
    url: `/admin/models/${id}/default`,
    method: 'patch'
  })
}
