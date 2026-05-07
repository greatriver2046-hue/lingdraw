import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

export const useUserStore = defineStore('user', () => {
  const userInfo = ref({})
  const token = ref(localStorage.getItem('token') || '')

  // Hydrate user info from localStorage if available
  try {
    const storedUser = localStorage.getItem('user')
    if (storedUser) {
      userInfo.value = JSON.parse(storedUser)
    }
  } catch (e) {
    console.error('Error parsing stored user info', e)
  }

  const isLoggedIn = computed(() => !!token.value)

  const login = async (username, password) => {
    try {
      const res = await request.post('/api/auth/login', { username, password })
      if (res.data.code === 200) {
        const data = res.data.data
        token.value = data.token
        userInfo.value = data.user
        
        localStorage.setItem('token', data.token)
        localStorage.setItem('user', JSON.stringify(data.user))
        
        return true
      } else {
        throw new Error(res.data.msg || 'Login failed')
      }
    } catch (error) {
      throw error
    }
  }

  const logout = () => {
    token.value = ''
    userInfo.value = {}
    localStorage.removeItem('token')
    localStorage.removeItem('user')
  }

  const getUserInfo = async () => {
    if (!token.value) return

    try {
      const res = await request.get('/api/v1/user/info')
      if (res.data.code === 200) {
        userInfo.value = res.data.data
        localStorage.setItem('user', JSON.stringify(res.data.data))
      }
    } catch (error) {
      console.error('Failed to get user info', error)
    }
  }

  return {
    userInfo,
    token,
    isLoggedIn,
    login,
    logout,
    getUserInfo
  }
})
