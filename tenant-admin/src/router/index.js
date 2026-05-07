import { createRouter, createWebHistory } from 'vue-router'
import MainLayout from '../layout/MainLayout.vue'
import LoginView from '../views/LoginView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView
    },
    {
      path: '/',
      component: MainLayout,
      redirect: '/users',
      children: [
        {
          path: 'users',
          name: 'user-management',
          component: () => import('../views/UserManagement.vue')
        },
        {
          path: 'packages',
          name: 'package-management',
          component: () => import('../views/PackageManagement.vue')
        },
        {
          path: 'works',
          name: 'works-management',
          component: () => import('../views/WorksManagement.vue')
        },
        {
          path: 'finance',
          name: 'finance-stats',
          component: () => import('../views/FinanceStats.vue')
        },
        {
          path: 'orders',
          name: 'order-management',
          component: () => import('../views/OrderManagement.vue')
        },
        {
          path: 'models',
          name: 'model-config',
          component: () => import('../views/ModelConfig.vue')
        },
        {
          path: 'inspiration',
          name: 'inspiration-library',
          component: () => import('../views/InspirationLibrary.vue')
        },
        {
          path: 'system/home',
          name: 'system-home',
          component: () => import('../views/system/HomeSettings.vue')
        },
        {
          path: 'system/account',
          name: 'system-account',
          component: () => import('../views/system/AccountSettings.vue')
        },
        {
          path: 'system/settings',
          name: 'system-settings',
          component: () => import('../views/system/LoginMethodsSettings.vue')
        },
        {
          path: 'system/payment',
          name: 'system-payment',
          component: () => import('../views/system/PaymentConfig.vue')
        },
        {
          path: 'system/legal',
          name: 'system-legal',
          component: () => import('../views/system/LegalSettings.vue')
        },
        {
          path: 'system/customer-service',
          name: 'system-customer-service',
          component: () => import('../views/system/CustomerServiceSettings.vue')
        },
        {
          path: 'notifications',
          name: 'notifications',
          component: () => import('../views/Notifications.vue')
        }
      ]
    }
  ]
})

router.beforeEach((to, from, next) => {
  document.title = '平台管理'
  next()
})

export default router
