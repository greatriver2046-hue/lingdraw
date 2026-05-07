<script setup>
import { RouterView, useRoute } from 'vue-router'
import MainLayout from './layout/MainLayout.vue'
import { computed, onMounted, watch } from 'vue'
import request from '@/utils/request'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { useGenerationStore } from '@/stores/generation'

const route = useRoute()
const appStore = useAppStore()
const userStore = useUserStore()
const generationStore = useGenerationStore()

const showLayout = computed(() => {
  const noLayoutRoutes = ['login', 'home', 'creative']
  return !noLayoutRoutes.includes(route.name)
})

// 初始化 WebSocket
const initWs = () => {
  if (userStore.token) {
    generationStore.initWebSocket(userStore.token)
  }
}

watch(() => userStore.token, (newToken) => {
  if (newToken) {
    initWs()
  }
})

onMounted(async () => {
  initWs()
  try {
    const res = await request.get('/api/public/home_config')
    if (res.data && res.data.code === 200 && res.data.data) {
      appStore.setSiteConfig(res.data.data)
    }
  } catch (e) {
    console.error('Failed to fetch site config', e)
  }
})
</script>

<template>
  <MainLayout v-if="showLayout">
    <RouterView v-slot="{ Component }">
      <keep-alive include="WorksGallery,InspirationGallery">
        <component :is="Component" />
      </keep-alive>
    </RouterView>
  </MainLayout>
  <RouterView v-else />
</template>

<style>
html, body, #app {
  height: 100%;
  margin: 0;
  padding: 0;
  font-family: 'Helvetica Neue', Helvetica, 'PingFang SC', 'Hiragino Sans GB', 'Microsoft YaHei', '微软雅黑', Arial, sans-serif;
}
</style>
