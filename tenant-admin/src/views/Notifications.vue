<template>
  <div class="notifications-page">
    <el-card class="box-card" shadow="never">
      <div class="card-actions" style="text-align: right; margin-bottom: 10px;">
        <el-button text type="primary" @click="markAllRead">全部标记为已读</el-button>
      </div>
      
      <el-tabs v-model="activeTab">
        <el-tab-pane label="全部" name="all">
          <div class="notification-list">
            <div v-for="item in notifications" :key="item.id" class="notification-item" :class="{ unread: !item.read }" @click="viewDetail(item)">
              <div class="notification-icon">
                <el-icon :size="20" :class="item.type"><component :is="item.icon" /></el-icon>
              </div>
              <div class="notification-content">
                <div class="notification-title">{{ item.title }}</div>
                <div class="notification-time">{{ item.time }}</div>
              </div>
            </div>
          </div>
        </el-tab-pane>
        <el-tab-pane label="未读" name="unread">
          <div class="notification-list" v-if="unreadNotifications.length > 0">
            <div v-for="item in unreadNotifications" :key="item.id" class="notification-item unread" @click="viewDetail(item)">
              <div class="notification-icon">
                <el-icon :size="20" :class="item.type"><component :is="item.icon" /></el-icon>
              </div>
              <div class="notification-content">
                <div class="notification-title">{{ item.title }}</div>
                <div class="notification-time">{{ item.time }}</div>
              </div>
            </div>
          </div>
          <el-empty description="暂无未读消息" v-else />
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- Notification Detail Dialog -->
    <el-dialog
      v-model="dialogVisible"
      :title="currentNotification?.title"
      width="500px"
      destroy-on-close
    >
      <div class="notification-detail">
        <div class="detail-meta">
          <span class="time">{{ currentNotification?.time }}</span>
          <el-tag size="small" :type="currentNotification?.type === 'primary' ? '' : currentNotification?.type">{{ getTypeName(currentNotification?.type) }}</el-tag>
        </div>
        <div class="detail-content">
          {{ currentNotification?.content }}
        </div>
      </div>
      <template #footer>
        <span class="dialog-footer">
          <el-button type="primary" @click="dialogVisible = false">确 定</el-button>
        </span>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Bell, InfoFilled, Warning, CircleCheck } from '@element-plus/icons-vue'

const activeTab = ref('all')
const dialogVisible = ref(false)
const currentNotification = ref(null)

const notifications = ref([
  { 
    id: 1, 
    title: '您的订阅即将到期，请及时续费', 
    content: '尊敬的用户，您的专业版订阅将于 2025-12-10 到期。为了不影响您的正常使用，请及时前往账户中心进行续费。我们提供多种优惠套餐供您选择。',
    time: '10分钟前', 
    type: 'warning', 
    icon: 'Warning', 
    read: false 
  },
  { 
    id: 2, 
    title: '系统维护通知：今晚 00:00 - 02:00', 
    content: '为了提供更优质的服务，我们将于今晚 00:00 - 02:00 进行系统升级维护。维护期间，部分功能可能无法使用，给您带来的不便敬请谅解。',
    time: '2小时前', 
    type: 'info', 
    icon: 'InfoFilled', 
    read: false 
  },
  { 
    id: 3, 
    title: '充值成功 500 点数', 
    content: '您已成功充值 500 AI点数。订单号：ORDER20251204001。您现在的点数余额为 1250 点。感谢您的支持！',
    time: '昨天', 
    type: 'success', 
    icon: 'CircleCheck', 
    read: true 
  },
  { 
    id: 4, 
    title: '新功能上线：AI 视频生成', 
    content: '激动人心的时刻到了！我们正式推出了 AI 视频生成功能。只需输入一段文字描述，即可生成精美的短视频。快来体验吧！',
    time: '3天前', 
    type: 'primary', 
    icon: 'Bell', 
    read: true 
  },
])

const unreadNotifications = computed(() => {
  return notifications.value.filter(item => !item.read)
})

const viewDetail = (item) => {
  currentNotification.value = item
  if (!item.read) {
    item.read = true
  }
  dialogVisible.value = true
}

const markAllRead = () => {
  notifications.value.forEach(item => item.read = true)
}

const getTypeName = (type) => {
  const map = {
    warning: '提醒',
    info: '通知',
    success: '成功',
    primary: '消息'
  }
  return map[type] || '消息'
}
</script>

<style scoped lang="scss">
.notifications-page {
  padding: 20px;
}
.box-card {
  border: none;
}

.notification-item {
  display: flex;
  align-items: center;
  padding: 15px 0;
  border-bottom: 1px solid #f0f2f5;
  cursor: pointer;
  transition: background-color 0.3s;
  
  &:hover {
    background-color: #fafafa;
  }
  
  &.unread {
    .notification-title {
      font-weight: 600;
      color: #303133;
    }
    &::before {
      content: '';
      display: block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background-color: #f56c6c;
      margin-right: 10px;
    }
  }
}

.notification-icon {
  margin-right: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  background-color: #f0f2f5;
  border-radius: 50%;
  
  .el-icon {
    &.warning { color: #e6a23c; }
    &.info { color: #909399; }
    &.success { color: #67c23a; }
    &.primary { color: #409eff; }
  }
}

.notification-content {
  flex: 1;
  .notification-title {
    font-size: 14px;
    color: #606266;
    margin-bottom: 4px;
  }
  .notification-time {
    font-size: 12px;
    color: #909399;
  }
}

.detail-meta {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
  gap: 10px;
  
  .time {
    color: #909399;
    font-size: 13px;
  }
}

.detail-content {
  font-size: 14px;
  line-height: 1.6;
  color: #606266;
  white-space: pre-wrap;
}
</style>
