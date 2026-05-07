import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import CreativeGallery from '../views/CreativeGallery.vue'
import GeneralCreation from '../views/GeneralCreation.vue'
import GraphicCreation from '../views/GraphicCreation.vue'
import InspirationGallery from '../views/InspirationGallery.vue'
import WorksGallery from '../views/WorksGallery.vue'
import LoginView from '../views/LoginView.vue'
import Tutorials from '../views/Tutorials.vue'

const DashboardHome = { template: '<div style="padding:24px">欢迎来到主页（占位）</div>' }
const ScriptCreationPlaceholder = { template: '<div style="padding:24px">剧本创作（占位）</div>' }

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView
    },
    {
      path: '/creative',
      name: 'creative',
      component: CreativeGallery,
      // meta: { requiresAuth: true }
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: DashboardHome,
      meta: { requiresAuth: true }
    },
    {
      path: '/creation/general/:conversation_id?',
      name: 'general-creation',
      component: GeneralCreation,
      meta: { requiresAuth: true }
    },
    {
      path: '/creation/graphic/:work_id?',
      name: 'graphic-creation',
      component: GraphicCreation,
      meta: { requiresAuth: true }
    },
    {
      path: '/creation/script',
      name: 'script-creation',
      component: ScriptCreationPlaceholder,
      meta: { requiresAuth: true }
    },
    {
      path: '/inspiration',
      name: 'inspiration',
      component: InspirationGallery,
      meta: { requiresAuth: true }
    },
    {
      path: '/works',
      name: 'works-gallery',
      component: WorksGallery,
      meta: { requiresAuth: true }
    },
    {
      path: '/tutorials',
      name: 'tutorials',
      component: Tutorials,
      meta: { requiresAuth: true }
    },
    // Optional: route unknown menu placeholders to dashboard
    { path: '/projects', component: WorksGallery, meta: { requiresAuth: true } },
    { path: '/usage', component: DashboardHome, meta: { requiresAuth: true } },
    { path: '/logs', component: DashboardHome, meta: { requiresAuth: true } }
  ]
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('token')
  if (to.meta.requiresAuth && !token) {
    next('/login')
  } else {
    next()
  }
})

export default router
