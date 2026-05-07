import { createRouter, createWebHistory } from 'vue-router'
import AdminLayout from '../layout/AdminLayout.vue'
import Dashboard from '../views/Dashboard.vue'
import InstanceManagement from '../views/InstanceManagement.vue'
import UserManagement from '../views/UserManagement.vue'
import ModelConfig from '../views/ModelConfig.vue'
import SystemConfig from '../views/SystemConfig.vue'
import UserAssets from '../views/UserAssets.vue'
import SystemPrompts from '../views/SystemPrompts.vue'
import AppUsers from '../views/AppUsers.vue'
import LoginView from '../views/LoginView.vue'
import SystemErrorLogs from '../views/SystemErrorLogs.vue'
import SmsLogs from '../views/SmsLogs.vue'
import CustomerServiceSettings from '../views/CustomerServiceSettings.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { title: '登录' }
    },
    {
      path: '/',
      component: AdminLayout,
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'dashboard',
          component: Dashboard,
          meta: { title: '监控看板', requiresAuth: true }
        },
        {
          path: 'instances',
          name: 'instances',
          component: InstanceManagement,
          meta: { title: '租户管理', requiresAuth: true }
        },
        {
          path: 'users',
          name: 'users',
          component: UserManagement,
          meta: { title: '账号权限管理', requiresAuth: true }
        },
        {
          path: 'app-users',
          name: 'appUsers',
          component: AppUsers,
          meta: { title: '用户管理', requiresAuth: true }
        },
        {
          path: 'models',
          name: 'models',
          component: ModelConfig,
          meta: { title: '模型配置', requiresAuth: true }
        },
        {
          path: 'assets',
          name: 'assets',
          component: UserAssets,
          meta: { title: '用户文件管理', requiresAuth: true }
        },
        {
          path: 'prompts',
          name: 'prompts',
          component: SystemPrompts,
          meta: { title: '提示词配置', requiresAuth: true }
        },
        {
          path: 'system',
          name: 'system',
          component: SystemConfig,
          meta: { title: '系统配置', requiresAuth: true }
        },
        {
          path: 'system-errors',
          name: 'systemErrors',
          component: SystemErrorLogs,
          meta: { title: '错误日志', requiresAuth: true }
        },
        {
          path: 'sms-logs',
          name: 'smsLogs',
          component: SmsLogs,
          meta: { title: '短信日志', requiresAuth: true }
        },
        {
          path: 'system-customer-service',
          name: 'customerServiceSettings',
          component: CustomerServiceSettings,
          meta: { title: '客服设置', requiresAuth: true }
        }
      ]
    }
  ]
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('token')
  if (to.meta.requiresAuth && !token) {
    next('/login')
  } else if (to.path === '/login' && token) {
    next('/')
  } else {
    next()
  }
})

export default router
