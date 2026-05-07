<template>
  <div class="main-layout">
    <aside class="sidebar-container" :class="{ collapsed: isSidebarCollapsed }">
      <slot name="sidebar">
        <AppSidebar 
          :isCollapsed="isSidebarCollapsed"
          @toggle="toggleSidebar"
        />
      </slot>
    </aside>
    
    <main class="content-container">
      <slot></slot>
    </main>
  </div>
</template>

<script setup>
import { storeToRefs } from 'pinia'
import AppSidebar from '@/components/AppSidebar.vue'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
const { isSidebarCollapsed } = storeToRefs(appStore)

const toggleSidebar = () => {
  appStore.toggleSidebar()
}
</script>

<style scoped lang="scss">
.main-layout {
  display: flex;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
  background-color: #ffffff;

  .sidebar-container {
    width: 220px;
    background-color: #f9f9f9; // Light gray background
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    border-right: 1px solid #e0e0e0;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    z-index: 200;
    
    &.collapsed {
      width: 72px; // Collapsed width
    }
  }

  .content-container {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto; // Allow scrolling within content
    overflow-x: hidden;
    background-color: #ffffff;
  }
}
</style>
