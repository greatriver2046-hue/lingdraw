import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'

export const useUserStore = defineStore('user', () => {
  let initialUser = null
  try {
    const storedUser = localStorage.getItem('tenant_user')
    if (storedUser) {
      initialUser = JSON.parse(storedUser)
    }
  } catch {
    initialUser = null
  }

  const user = ref(initialUser)
  const isAuthenticated = ref(!!localStorage.getItem('token'))

  const login = async (username, password) => {
    try {
      const res = await request.post('/admin/auth/login', { username, password })
      // res is res.data from interceptor, which is { code, msg, data }
      // Actually interceptor returns res (res.data of axios) if code==200
      // So res is { code: 200, msg: '...', data: { token: '...', user: ... } }
      
      const token = res.data?.token
      const userInfo = res.data?.userInfo || res.data?.user || null
      
      if (token) {
        localStorage.setItem('token', token)
      }
      if (userInfo) {
        localStorage.setItem('tenant_user', JSON.stringify(userInfo))
      } else {
        localStorage.removeItem('tenant_user')
      }
      user.value = userInfo
      isAuthenticated.value = true
      return true
    } catch {
      return false
    }
  }

  const logout = () => {
    localStorage.removeItem('token')
    localStorage.removeItem('tenant_user')
    user.value = null
    isAuthenticated.value = false
  }

  return {
    user,
    isAuthenticated,
    login,
    logout
  }
})
