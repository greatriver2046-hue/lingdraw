<template>
  <el-container class="main-layout">
    <el-aside width="240px" class="main-aside">
      <div class="aside-header">
        <h2>总管理后台</h2>
      </div>

      <el-menu
        :default-active="activeMenu"
        class="el-menu-vertical"
        router
        :collapse="false"
      >
        <div class="menu-group-title">业务管理</div>

        <el-menu-item index="/dashboard">
          <el-icon><DataLine /></el-icon>
          <span>监控看板</span>
        </el-menu-item>
        <el-menu-item index="/instances">
          <el-icon><OfficeBuilding /></el-icon>
          <span>租户管理</span>
        </el-menu-item>
        <el-menu-item index="/users">
          <el-icon><User /></el-icon>
          <span>账号权限</span>
        </el-menu-item>
        <el-menu-item index="/app-users">
          <el-icon><User /></el-icon>
          <span>用户管理</span>
        </el-menu-item>
        <el-sub-menu index="/models">
          <template #title>
            <el-icon><Cpu /></el-icon>
            <span>模型配置</span>
          </template>
          <el-menu-item index="/models">
            <el-icon><Cpu /></el-icon>
            <span>模型配置</span>
          </el-menu-item>
          <el-menu-item index="/prompts">
            <el-icon><ChatLineRound /></el-icon>
            <span>提示词配置</span>
          </el-menu-item>
        </el-sub-menu>
        <el-menu-item index="/assets">
          <el-icon><Picture /></el-icon>
          <span>用户文件</span>
        </el-menu-item>

        <el-sub-menu index="/system">
          <template #title>
            <el-icon><Tools /></el-icon>
            <span>系统设置</span>
          </template>
          <el-menu-item index="/system">
            <el-icon><Setting /></el-icon>
            <span>系统配置</span>
          </el-menu-item>
          <el-menu-item index="/system-errors">
            <el-icon><Warning /></el-icon>
            <span>错误日志</span>
          </el-menu-item>
          <el-menu-item index="/sms-logs">
            <el-icon><ChatLineRound /></el-icon>
            <span>短信日志</span>
          </el-menu-item>
          <el-menu-item index="/system-customer-service">
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
        <div class="page-container">
          <router-view />
        </div>
      </el-main>
    </el-container>
  </el-container>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { 
  ArrowDown,
  ChatLineRound,
  Cpu,
  DataLine,
  OfficeBuilding,
  Picture,
  Service,
  Setting,
  SwitchButton,
  Tools,
  User,
  Warning
} from '@element-plus/icons-vue'
import { ElMessageBox, ElMessage } from 'element-plus'

const router = useRouter()
const route = useRoute()
const adminStore = useAdminStore()

onMounted(() => {
  adminStore.fetchUserInfo()
})

const activeMenu = computed(() => route.path)

const userAccount = computed(() => {
  return adminStore.currentUser?.username || '-'
})

const avatarText = computed(() => {
  const text = adminStore.currentUser?.username || ''
  return text ? text.charAt(0).toUpperCase() : 'A'
})

const handleCommand = (command) => {
  if (command === 'logout') {
    ElMessageBox.confirm(
      '确定要退出登录吗?',
      '提示',
      {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning',
      }
    )
      .then(() => {
        localStorage.clear()
        sessionStorage.clear()
        
        ElMessage.success('已退出登录')
        router.push('/login')
      })
      .catch(() => {
      })
  }
}

const pageTitle = computed(() => route.meta.title || '平台管理')
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
    overflow: hidden;
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

.page-container {
  height: 100%;
  padding: 20px;
  box-sizing: border-box;
}
</style>
