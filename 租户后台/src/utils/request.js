import axios from 'axios'
import { ElMessage } from 'element-plus'
import router from '../router'

const getBaseURL = () => {
  if (typeof window !== 'undefined' && window.__APP_CONFIG__ && window.__APP_CONFIG__.API_BASE_URL) {
    return window.__APP_CONFIG__.API_BASE_URL
  }
  return import.meta.env.VITE_API_BASE_URL || '/api'
}

const service = axios.create({
  baseURL: getBaseURL(),
  timeout: 10000
})

// Request interceptor
service.interceptors.request.use(
  config => {
    const token = localStorage.getItem('token')
    if (token) {
      config.headers['Authorization'] = token
    }
    return config
  },
  error => {
    return Promise.reject(error)
  }
)

// Response interceptor
service.interceptors.response.use(
  response => {
    const res = response.data
    // Backend standard response: { code: 200, msg: '...', data: ... }
    if (res.code !== 200) {
      ElMessage.error(res.msg || 'Error')
      
      if (res.code === 401) {
        localStorage.removeItem('token')
        router.push('/login')
      }
      return Promise.reject(new Error(res.msg || 'Error'))
    } else {
      return res
    }
  },
  error => {
    console.error('err' + error)
    let message = error.message || 'Request Error'
    
    if (error.response && error.response.data) {
      // Handle 401 Unauthorized
      if (error.response.status === 401) {
        localStorage.removeItem('token')
        router.push('/login')
      }

      // 优先使用后端返回的错误信息
      if (error.response.data.msg) {
        message = error.response.data.msg
      } else if (error.response.data.message) {
        message = error.response.data.message
      }
    }
    
    ElMessage.error(message)
    return Promise.reject(error)
  }
)

export default service
