import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useAppStore = defineStore('app', () => {
  const isSidebarCollapsed = ref(false)
  const isAppSidebarHovered = ref(false)
  const isHistorySidebarHovered = ref(false)
  const isWorkspaceLoading = ref(false)
  const pendingSidebarCollapse = ref(false)
  const siteConfig = ref({})
  const isSiteConfigLoaded = ref(false)
  const showSubscriptionModal = ref(false)

  const toggleSidebar = () => {
    isSidebarCollapsed.value = !isSidebarCollapsed.value
  }

  const setSidebarCollapsed = (value) => {
    isSidebarCollapsed.value = value
  }

  const requestSidebarCollapse = (value) => {
    const next = !!value
    if (next) {
      if (isWorkspaceLoading.value) {
        pendingSidebarCollapse.value = true
        return
      }
      pendingSidebarCollapse.value = false
      setSidebarCollapsed(true)
      return
    }

    pendingSidebarCollapse.value = false
    setSidebarCollapsed(false)
  }

  const setWorkspaceLoading = (value) => {
    isWorkspaceLoading.value = !!value
    if (!isWorkspaceLoading.value && pendingSidebarCollapse.value) {
      pendingSidebarCollapse.value = false
      setSidebarCollapsed(true)
    }
  }

  const setAppSidebarHovered = (value) => {
    isAppSidebarHovered.value = value
  }

  const setHistorySidebarHovered = (value) => {
    isHistorySidebarHovered.value = value
  }

  const setSiteConfig = (config) => {
    siteConfig.value = config
    isSiteConfigLoaded.value = true
    const siteName = String(config?.site_name || '').trim()
    const siteTitle = String(config?.site_title || '').trim()
    if (siteName && siteTitle) {
      document.title = `${siteName}-${siteTitle}`
    } else if (siteName) {
      document.title = siteName
    } else if (siteTitle) {
      document.title = siteTitle
    }
  }

  const openSubscriptionModal = () => {
    showSubscriptionModal.value = true
  }

  const closeSubscriptionModal = () => {
    showSubscriptionModal.value = false
  }

  return {
    isSidebarCollapsed,
    isAppSidebarHovered,
    isHistorySidebarHovered,
    isWorkspaceLoading,
    siteConfig,
    isSiteConfigLoaded,
    showSubscriptionModal,
    toggleSidebar,
    setSidebarCollapsed,
    requestSidebarCollapse,
    setWorkspaceLoading,
    setAppSidebarHovered,
    setHistorySidebarHovered,
    setSiteConfig,
    openSubscriptionModal,
    closeSubscriptionModal
  }
})
