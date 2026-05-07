import request from '@/utils/request'

/**
 * 获取SaaS实例列表
 * @param {Object} params
 * @param {number} params.page 页码
 * @param {number} params.limit 每页数量
 * @param {string} params.keyword 搜索关键词
 * @param {number} params.status 状态筛选
 */
export function getInstanceList(params) {
  return request({
    url: '/admin/instances',
    method: 'get',
    params
  })
}

/**
 * 创建SaaS实例
 * @param {Object} data
 */
export function createInstance(data) {
  return request({
    url: '/admin/instances',
    method: 'post',
    data
  })
}

/**
 * 更新SaaS实例
 * @param {number} id
 * @param {Object} data
 */
export function updateInstance(id, data) {
  return request({
    url: `/admin/instances/${id}`,
    method: 'put',
    data
  })
}

/**
 * 删除SaaS实例
 * @param {number} id
 */
export function deleteInstance(id) {
  return request({
    url: `/admin/instances/${id}`,
    method: 'delete'
  })
}

/**
 * 更新SaaS实例状态
 * @param {number} id
 * @param {number} status 1:启用, 0:停用
 */
export function updateInstanceStatus(id, status) {
  return request({
    url: `/admin/instances/${id}/status`,
    method: 'patch',
    data: { status }
  })
}

export function getInstanceSsoUrl(id) {
  return request({
    url: `/admin/instances/${id}/sso`,
    method: 'post'
  })
}
