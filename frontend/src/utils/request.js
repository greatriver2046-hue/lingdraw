import axios from 'axios'
import { ElMessage } from 'element-plus'
import router from '@/router'
import { config } from '@/config'

const service = axios.create({
  // Use absolute URL or relative if proxy is set up. 
  // Based on existing code, it uses 'http://127.0.0.1:9998'
  baseURL: config.API_BASE_URL, 
  timeout: 60000
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
    
    // Check for custom code 401
    // User specified: {"code":401,"msg":"The token is expired."}
    if (res.code === 401) {
      // Avoid redirect loop or unnecessary message if already on login page (optional, but good UX)
      // However, if token expired while on login page (rare), we might still want to know.
      // But typically we only redirect if not on login.
      
      const currentPath = router.currentRoute.value.path
      if (currentPath !== '/login') {
         ElMessage.error('登录超时，请重新登录')
         localStorage.removeItem('token')
         router.push('/login')
      }
      return Promise.reject(new Error(res.msg || 'Token expired'))
    }
    
    return response
  },
  error => {
    // Handle HTTP 401 as well, just in case
    if (error.response && error.response.status === 401) {
      const currentPath = router.currentRoute.value.path
      if (currentPath !== '/login') {
         ElMessage.error('登录超时，请重新登录')
         localStorage.removeItem('token')
         router.push('/login')
      }
    }
    return Promise.reject(error)
  }
)

export default service
