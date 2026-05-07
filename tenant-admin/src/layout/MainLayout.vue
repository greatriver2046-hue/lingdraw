<template>
  <el-container class="main-layout">
    <el-aside width="240px" class="main-aside">
      <div class="aside-header">
        <h2>平台管理</h2>
      </div>
      <el-menu
        :default-active="activeMenu"
        class="el-menu-vertical"
        router
        :collapse="false"
      >
        <div class="menu-group-title">业务管理</div>
        <el-menu-item index="/users">
          <el-icon><UserFilled /></el-icon>
          <span>用户管理</span>
        </el-menu-item>
        <el-menu-item index="/packages">
          <el-icon><Goods /></el-icon>
          <span>套餐管理</span>
        </el-menu-item>
        <el-menu-item index="/works">
          <el-icon><Picture /></el-icon>
          <span>作品管理</span>
        </el-menu-item>
        <el-menu-item index="/inspiration">
          <el-icon><Collection /></el-icon>
          <span>灵感池库</span>
        </el-menu-item>
        <el-menu-item index="/finance">
          <el-icon><Money /></el-icon>
          <span>财务统计</span>
        </el-menu-item>
        <el-menu-item index="/orders">
          <el-icon><Document /></el-icon>
          <span>订单管理</span>
        </el-menu-item>
        <el-menu-item index="/models">
          <el-icon><Cpu /></el-icon>
          <span>模型设置</span>
        </el-menu-item>

        <el-sub-menu index="/system">
          <template #title>
            <el-icon><Tools /></el-icon>
            <span>系统设置</span>
          </template>
          <el-menu-item index="/system/home">
            <el-icon><House /></el-icon>
            <span>主页设置</span>
          </el-menu-item>
          <el-menu-item index="/system/account">
            <el-icon><Key /></el-icon>
            <span>账号密码</span>
          </el-menu-item>
          <el-menu-item index="/system/settings">
            <el-icon><Iphone /></el-icon>
            <span>系统设置</span>
          </el-menu-item>
          <el-menu-item index="/system/payment">
            <el-icon><CreditCard /></el-icon>
            <span>支付配置</span>
          </el-menu-item>
          <el-menu-item index="/system/legal">
            <el-icon><Memo /></el-icon>
            <span>用户协议</span>
          </el-menu-item>
          <el-menu-item index="/system/customer-service">
            <el-icon><Service /></el-icon>
            <span>客服设置</span>
          </el-menu-item>
        </el-sub-menu>
      </el-menu>
    </el-aside>
    
    <el-container>
      <el-header class="main-header">
        <div class="header-left">
          <h2 class="page-title">{{ pageTitle }}</h2>
        </div>
        <div class="header-right">
          <el-dropdown trigger="click" @command="handleCommand">
            <div class="user-profile">
              <el-avatar :size="32" class="user-avatar">{{ avatarText }}</el-avatar>
              <span class="user-account">{{ userAccount }}</span>
              <el-icon class="dropdown-icon"><ArrowDown /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="logout" divided>
                  <el-icon><SwitchButton /></el-icon>退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>
      <el-main class="main-content">
        <router-view />
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { 
  ArrowDown, UserFilled, Goods, Picture, Collection, Money, Document, Cpu, Tools, House, Key, Iphone, CreditCard, SwitchButton, Memo, Service
} from '@element-plus/icons-vue'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const activeMenu = computed(() => route.path)

const userAccount = computed(() => {
  return userStore.user?.email || userStore.user?.username || '-'
})

const avatarText = computed(() => {
  const text = userStore.user?.username || userStore.user?.email || ''
  return text ? text.charAt(0).toUpperCase() : 'U'
})

const handleCommand = (command) => {
  if (command === 'logout') {
    userStore.logout()
    router.push('/login')
  }
}

const pageTitle = computed(() => {
  const path = route.path
  const menuMap = {
    '/users': '用户管理',
    '/packages': '套餐管理',
    '/works': '作品管理',
    '/inspiration': '灵感池库',
    '/finance': '财务统计',
    '/orders': '订单管理',
    '/models': '模型设置',
    '/system/home': '主页设置',
    '/system/account': '账号密码',
    '/system/settings': '系统设置',
    '/system/payment': '支付配置',
    '/system/legal': '用户协议',
    '/system/customer-service': '客服设置',
    '/notifications': '通知消息'
  }
  return menuMap[path] || '平台管理'
})
</script>

<style lang="scss" scoped>
.main-layout {
  height: 100vh;
}

.main-aside {
  background-color: #f5f7fa;
  border-right: 1px solid #e4e7ed;
  display: flex;
  flex-direction: column;
  
  .aside-header {
    padding: 20px;
    h2 {
      margin: 0;
      font-size: 16px;
      font-weight: 600;
      color: #303133;
    }
  }
  
  .el-menu {
    border-right: none;
    background-color: transparent;
  }
  
  .menu-group-title {
    padding: 12px 20px 8px;
    font-size: 12px;
    color: #909399;
  }
  
  :deep(.el-menu-item) {
    height: 40px;
    line-height: 40px;
    margin: 4px 10px;
    border-radius: 4px;
    
    &.is-active {
      background-color: #e6e8eb;
      color: #303133;
      font-weight: 500;
    }
    
    &:hover {
      background-color: #ebeef5;
    }

    .pro-tag {
      margin-left: 8px;
      font-size: 10px;
      height: 18px;
      line-height: 16px;
    }
  }

  :deep(.el-sub-menu__title) {
    height: 40px;
    line-height: 40px;
    margin: 4px 10px;
    border-radius: 4px;
    
    &:hover {
      background-color: #ebeef5;
    }
  }

  :deep(.el-menu--inline) {
    background-color: transparent;
    overflow: hidden; /* Prevent margin collapse issues during animation */
  }

  :deep(.el-sub-menu .el-menu-item) {
    background-color: transparent;

    &:hover {
      background-color: #ebeef5;
    }

    &.is-active {
      background-color: #e6e8eb;
    }
  }
}

.main-header {
  height: 56px;
  background-color: #fff;
  border-bottom: 1px solid #e4e7ed;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  
  .header-left {
    display: flex;
    align-items: center;
    
    .page-title {
      margin: 0;
      font-size: 18px;
      font-weight: 600;
      color: #303133;
    }
  }
  
  .header-right {
    display: flex;
    align-items: center;
    gap: 12px;
    
    .user-avatar {
      background-color: #909399;
      color: #fff;
      font-size: 12px;
    }

    .user-profile {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
    }

    .user-account {
      font-size: 13px;
      color: #303133;
      max-width: 220px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .dropdown-icon {
      color: #909399;
    }
  }
}

.main-content {
  padding: 0;
  background-color: #fff;
}
</style>
