import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import AppSidebar from '../AppSidebar.vue'

const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', component: { template: '<div />' } },
    { path: '/creation/general', component: { template: '<div />' } },
    { path: '/works', component: { template: '<div />' } }
  ]
})

const mountSidebar = async () => {
  const pinia = createPinia()
  await router.push('/creation/general')
  await router.isReady()

  return mount(AppSidebar, {
    props: { isCollapsed: false },
    global: {
      plugins: [pinia, router],
      stubs: {
        'el-icon': true,
        'el-tooltip': true,
        'el-avatar': true,
        'el-button': true,
        'el-popover': true,
        PointsHistoryDialog: true,
        SubscriptionModal: true
      }
    }
  })
}

describe('AppSidebar', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('renders main nav labels', async () => {
    const wrapper = await mountSidebar()
    expect(wrapper.text()).toContain('创作')
    expect(wrapper.text()).toContain('作品库')
    expect(wrapper.text()).toContain('灵感池')
    expect(wrapper.text()).toContain('教程')
  })

  it('highlights default active sub item', async () => {
    const wrapper = await mountSidebar()
    const activeSubItem = wrapper.find('.nav-item.sub-item.active')
    expect(activeSubItem.exists()).toBe(true)
    expect(activeSubItem.text()).toContain('通用创作')
  })
})
