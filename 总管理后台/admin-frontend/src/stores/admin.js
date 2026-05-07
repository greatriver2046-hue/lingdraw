import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAdminStore = defineStore('admin', () => {
  // Mock Data: SaaS Instances
  const instances = ref([
    { 
      id: 1, 
      name: 'Alpha Corp', 
      domain: 'alpha.aisaas.com', 
      status: 'active', 
      admin: 'admin@alpha.com', 
      phone: '13800138000',
      quota: 10000, 
      used: 2500,
      expiry: '2025-12-31',
      created_at: '2024-01-15'
    },
    { 
      id: 2, 
      name: 'Beta Creative', 
      domain: 'beta.aisaas.com', 
      status: 'active', 
      admin: 'john@beta.com', 
      phone: '13900139000',
      quota: 5000, 
      used: 4800,
      expiry: '2024-06-30',
      created_at: '2024-02-01'
    },
    { 
      id: 3, 
      name: 'Gamma Studio', 
      domain: 'gamma.aisaas.com', 
      status: 'suspended', 
      admin: 'sarah@gamma.com', 
      phone: '13700137000',
      quota: 2000, 
      used: 2000,
      expiry: '2024-05-01',
      created_at: '2024-03-10'
    }
  ])

  // Mock Data: Users (Admins)
  const users = ref([
    { id: 1, name: 'Super Admin', email: 'root@aisaas.com', role: 'super_admin', status: 'active' },
    { id: 2, name: 'Alpha Admin', email: 'admin@alpha.com', role: 'instance_admin', instance_id: 1, status: 'active' },
    { id: 3, name: 'Beta Admin', email: 'john@beta.com', role: 'instance_admin', instance_id: 2, status: 'active' }
  ])

  // Mock Data: SaaS End Users
  const saasUsers = ref([
    {
      id: 101,
      avatar: '',
      nickname: 'CreativeArtist',
      phone: '13800138001',
      email: 'artist@example.com',
      instance_id: 1, // Alpha Corp
      register_time: '2024-01-20 10:00:00',
      last_login_time: '2024-05-20 09:30:00',
      balance: 500
    },
    {
      id: 102,
      avatar: '',
      nickname: 'VideoMaker',
      phone: '13900139002',
      email: 'maker@example.com',
      instance_id: 1, // Alpha Corp
      register_time: '2024-02-15 14:20:00',
      last_login_time: '2024-05-19 16:45:00',
      balance: 1200
    },
    {
      id: 103,
      avatar: '',
      nickname: 'ScriptWriter',
      phone: '13700137003',
      email: 'writer@example.com',
      instance_id: 2, // Beta Creative
      register_time: '2024-03-01 09:00:00',
      last_login_time: '2024-05-18 11:20:00',
      balance: 50
    }
  ])

  // Mock Data: Models
  const models = ref([
    { 
      id: 1, 
      name: 'Stable Diffusion XL', 
      modelId: 'stability-ai/sdxl', 
      provider: 'Stability AI', 
      status: 'active', 
      apiKey: 'sk-...' 
    },
    { 
      id: 2, 
      name: 'Midjourney V6', 
      modelId: 'midjourney-v6', 
      provider: 'Midjourney', 
      status: 'active', 
      apiKey: 'mj-...' 
    },
    { 
      id: 3, 
      name: 'DALL-E 3', 
      modelId: 'dall-e-3', 
      provider: 'OpenAI', 
      status: 'inactive', 
      apiKey: '' 
    }
  ])

  // User State
  const currentUser = ref({
    id: null,
    username: '',
    role: '',
    avatar: ''
  })

  // Actions
  const fetchUserInfo = async () => {
    // Use dynamic import to avoid circular dependency if request uses store
    const { default: request } = await import('@/utils/request')
    try {
      const res = await request.get('/admin/auth/info')
      if (res.data) {
        currentUser.value = {
          ...res.data,
          avatar: '' // Backend doesn't return avatar yet
        }
      }
    } catch (error) {
      console.error('Failed to fetch user info:', error)
    }
  }

  const addInstance = (instance) => {
    instances.value.push({
      id: Date.now(),
      ...instance,
      status: 'active',
      used: 0,
      created_at: new Date().toISOString().split('T')[0]
    })
  }

  const updateInstance = (id, updates) => {
    const index = instances.value.findIndex(i => i.id === id)
    if (index !== -1) {
      instances.value[index] = { ...instances.value[index], ...updates }
    }
  }

  const toggleInstanceStatus = (id) => {
    const instance = instances.value.find(i => i.id === id)
    if (instance) {
      instance.status = instance.status === 'active' ? 'suspended' : 'active'
    }
  }

  const updateQuota = (id, newQuota) => {
    const instance = instances.value.find(i => i.id === id)
    if (instance) {
      instance.quota = newQuota
    }
  }

  // Model Actions
  const addModel = (model) => {
    models.value.push({
      id: Date.now(),
      status: 'active',
      ...model
    })
  }

  const updateModel = (id, updates) => {
    const index = models.value.findIndex(m => m.id === id)
    if (index !== -1) {
      models.value[index] = { ...models.value[index], ...updates }
    }
  }

  const deleteModel = (id) => {
    models.value = models.value.filter(m => m.id !== id)
  }

  return {
    instances,
    users,
    currentUser,
    saasUsers,
    models,
    fetchUserInfo,
    addInstance,
    updateInstance,
    toggleInstanceStatus,
    updateQuota,
    addModel,
    updateModel,
    deleteModel
  }
})
