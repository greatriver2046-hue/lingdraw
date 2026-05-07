import request from '@/utils/request'

export function getAssets(params) {
  return request({
    url: '/admin/assets',
    method: 'get',
    params
  })
}

export function deleteAsset(id) {
  return request({
    url: `/admin/assets/${id}`,
    method: 'delete'
  })
}

