<template>
  <div class="history-sidebar">
    <div class="sidebar-header">
      <div class="tabs">
        <span class="tab active">对话</span>
      </div>
      <el-button type="primary" link class="new-btn" @click="handleNew">
        <el-icon><Plus /></el-icon> 新建对话
      </el-button>
    </div>
    
    <el-scrollbar ref="scrollbar">
      <div class="history-list">
        <div 
          v-for="item in chatStore.conversations" 
          :key="item.id"
          class="history-item"
          :class="{ active: chatStore.currentConversationId === item.id }"
          @click="handleEnterConversation(item.id)"
        >
          <div class="delete-btn" @click.stop="handleDeleteConversation(item)">
            <el-icon><Delete /></el-icon>
          </div>
          <div class="item-image">
            <img v-if="item.cover_thumb_url" :src="item.cover_thumb_url" loading="lazy" decoding="async" style="width:100%; height:100%; object-fit: cover;" />
            <span v-else class="avatar-text">{{ (item.title || '对话').charAt(0) }}</span>
          </div>
          <div class="item-info">
            <p class="prompt-text">{{ item.title || ('对话 #' + item.id) }}</p>
            <span class="time-text">{{ formatTimeStr(item.updated_at || item.created_at) }}</span>
          </div>
        </div>
        <div class="load-more-sentinel" ref="sentinel">
          <div v-if="chatStore.isLoadingMore" class="loading-more">
            <el-icon class="is-loading"><Loading /></el-icon>
          </div>
        </div>
      </div>
    </el-scrollbar>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, computed, reactive } from 'vue'
import { useChatStore } from '@/stores/chat'
import { useRouter, useRoute } from 'vue-router'
import { Plus, Loading, Delete } from '@element-plus/icons-vue'
import { ElMessageBox } from 'element-plus'
import { config } from '@/config'

const chatStore = useChatStore()
const router = useRouter()
const route = useRoute()
const sentinel = ref(null)
const scrollbar = ref(null)
let observer = null

const handleDeleteConversation = async (item) => {
  if (!item) return
  
  try {
    await ElMessageBox.confirm('确定要删除该对话吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    })
    
    const isCurrent = String(chatStore.currentConversationId) === String(item.id)
    const success = await chatStore.deleteConversation(item.id)
    
    if (success && isCurrent) {
       if (chatStore.conversations.length > 0) {
          // Open the most recent one (first in list)
          const next = chatStore.conversations[0]
          handleEnterConversation(next.id)
       } else {
          // No more conversations, go to default state
          router.push({ name: 'general-creation' })
       }
    }
  } catch (e) {
    // Cancelled or error
  }
}

const handleNew = async () => {
  const token = localStorage.getItem('token')
  try {
    const resp = await fetch(`${config.API_BASE_URL}/api/v1/conversation/create`, {
      method: 'POST',
      headers: { 'Authorization': token, 'Content-Type': 'application/json' },
      body: JSON.stringify({ title: '新的对话' })
    })
    const json = await resp.json()
    if (json.code === 200) {
      const newId = json.data?.conversation_id
      
      // Optimistically add to list so UI updates immediately
      chatStore.upsertConversationItem(newId, {
        title: '新的对话',
        created_at: new Date().toISOString().replace('T',' ').slice(0,19),
        updated_at: new Date().toISOString().replace('T',' ').slice(0,19),
        cover_thumb_url: '',
        last_image_url: '',
        last_image_thumb_url: ''
      })

      const currentName = route.name
      const targetName = ['general-creation'].includes(currentName) 
        ? currentName 
        : 'general-creation'
        
      router.push({ name: targetName, params: { conversation_id: newId } })
    }
  } catch (e) { 
    console.error('Failed to create conversation:', e)
  }
}

const handleEnterConversation = async (conversationId) => {
  await chatStore.loadConversation(conversationId)
  
  const currentName = route.name
  const targetName = ['general-creation'].includes(currentName) 
    ? currentName 
    : 'general-creation'
    
  router.push({ name: targetName, params: { conversation_id: conversationId } })
}

const formatTimeStr = (str) => {
  if (!str) return ''
  const d = new Date(str)
  const pad = (n) => String(n).padStart(2, '0')
  const y = d.getFullYear()
  const m = pad(d.getMonth() + 1)
  const day = pad(d.getDate())
  const hh = pad(d.getHours())
  const mm = pad(d.getMinutes())
  return `${y}-${m}-${day} ${hh}:${mm}`
}

onMounted(() => {
  // Initialize observer immediately
  if (window.IntersectionObserver && sentinel.value) {
    observer = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting) {
        if (chatStore.hasMore && !chatStore.isLoadingMore) {
          chatStore.fetchConversations(true)
        }
      }
    }, { threshold: 0.1 })
    
    observer.observe(sentinel.value)
  }

  // Initial fetch
  // If we are loading a specific conversation, delay the list fetch to prioritize the conversation content
  if (route.params.conversation_id) {
    setTimeout(() => {
      chatStore.fetchConversations()
    }, 800)
  } else {
    chatStore.fetchConversations()
  }
})

onUnmounted(() => {
  if (observer) {
    observer.disconnect()
    observer = null
  }
})
</script>

<style scoped lang="scss">
.history-sidebar {
  width: 260px;
  height: 100%;
  border-right: 1px solid #eef0f3;
  display: flex;
  flex-direction: column;
  background-color: #f7f7f7;
  flex-shrink: 0;
  overflow: hidden;

  :deep(.el-scrollbar) {
    flex: 1;
    min-height: 0;
  }

  .sidebar-header {
    padding: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }

  .tabs {
    display: flex;
    gap: 20px;
  }

  .tab {
    cursor: default;
    font-size: 14px;
    color: #666;
    padding-bottom: 4px;

    &.active {
      color: #000;
      font-weight: 600;
      border-bottom: 2px solid #000;
    }
  }

  .new-btn { 
    font-size: 13px; 
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .history-list {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .history-item {
    display: flex;
    align-items: center;
    padding: 10px;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 1px solid transparent;
    position: relative;

    &:hover { 
      background-color: #fff; 

      .delete-btn {
        opacity: 1;
        pointer-events: auto;
      }
    }

    &.active {
      background-color: #fff;
      border-color: #fff;
      
      .item-info .prompt-text {
        color: #1677ff;
        font-weight: 500;
      }
    }

    .delete-btn {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      right: 8px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.9);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #999;
      cursor: pointer;
      opacity: 0;
      pointer-events: none;
      transition: all 0.2s ease;
      z-index: 10;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);

      &:hover {
        background: #ff4d4f;
        color: #fff;
      }
    }

    .item-image {
      width: 48px;
      height: 48px;
      flex-shrink: 0;
      border-radius: 8px;
      overflow: hidden;
      background-color: #f0f2f5;
      margin-right: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #eee;

      .avatar-text {
        color: #DCDFE6;
        font-size: 20px;
        font-weight: 600;
      }
    }

    .item-info {
      flex: 1;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: center;
      gap: 4px;
      padding-right: 32px;

      .prompt-text {
        margin: 0;
        font-size: 14px;
        color: #333;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.2;
      }

      .time-text { 
        font-size: 12px; 
        color: #999; 
      }
    }
  }

  .load-more-sentinel {
    height: 1px;
  }
  .load-more-sentinel {
    height: 30px;
    width: 100%;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #909399;
  }
  
  .loading-more {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px 0;
  }
}
</style>
