<template>
  <div class="creative-gallery-page">
    <!-- Navbar (From HomeView) -->
    <nav class="navbar">
      <div class="navbar-left">
        <div class="logo">
          <img v-if="appStore.siteConfig.logo" :src="appStore.siteConfig.logo" class="logo-img" alt="Logo" />
          <template v-else>
            <span class="logo-icon">{{ siteIconText }}</span>
            <span class="logo-text">{{ siteTitleText }}</span>
          </template>
        </div>
        <div class="nav-links">
          <a href="#" @click.prevent="router.push('/')">首页</a>
          <a href="#" class="active" @click.prevent="router.push('/creative')">创意</a>
        </div>
      </div>
      <div class="navbar-right">
        <template v-if="userStore.isLoggedIn">
          <el-dropdown trigger="click" @command="handleCommand">
            <div class="user-profile-trigger">
              <el-avatar :size="32" :src="userStore.userInfo.avatar" v-if="userStore.userInfo.avatar" />
              <el-avatar :size="32" :icon="UserFilled" v-else />
              <span class="username">{{ userStore.userInfo.nickname || userStore.userInfo.username || 'User' }}</span>
              <el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="creation-center">创作中心</el-dropdown-item>
                <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
        <el-button v-else type="primary" class="signin-btn" @click="handleSignIn">登录</el-button>
      </div>
    </nav>

    <!-- Main Content Area -->
    <div class="creative-content">
      <!-- Filter Header (From InspirationGallery) -->
      <div class="filter-header">
        <div class="category-list">
          <div 
            v-for="(cat, index) in categories" 
            :key="index"
            class="category-item"
            :class="{ active: activeCategory === cat.id }"
            @click="activeCategory = cat.id"
          >
            <el-icon v-if="cat.icon" class="cat-icon"><component :is="cat.icon" /></el-icon>
            <span>{{ cat.name }}</span>
          </div>
        </div>
        
        <div class="filter-actions">
          <el-dropdown trigger="click" @command="handleSort">
            <div class="action-btn">
              <el-icon><Sort /></el-icon>
              <span>{{ sortLabel }}</span>
              <el-icon class="arrow"><ArrowDown /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="recommend">推荐</el-dropdown-item>
                <el-dropdown-item command="new">最新</el-dropdown-item>
                <el-dropdown-item command="hot">最热</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
          
          <div class="action-btn">
            <el-icon><Filter /></el-icon>
            <span>筛选</span>
          </div>
        </div>
      </div>

      <!-- Scrollable Gallery Area -->
      <div class="gallery-scroll-area" @scroll="handleScroll">
        <div class="waterfall-grid">
          <div v-for="item in galleryItems" :key="item.id" class="grid-item">
            <div class="card" @click="openDetail(item)">
              <!-- Header: Author & Date -->
              <div class="card-header">
                <span class="author-name">@{{ item.author_name || 'UNKNOWN' }}</span>
                <span class="date">{{ formatDate(item.created_at) }}</span>
              </div>

              <!-- Title -->
              <h3 class="card-title">{{ item.title }}</h3>

              <!-- Image -->
              <div class="media-wrapper">
                <template v-if="item.images && item.images.length > 1">
                  <el-carousel 
                    :autoplay="false" 
                    arrow="hover" 
                    indicator-position="none"
                    :height="0"
                    :style="{ aspectRatio: item.aspectRatio }"
                    class="card-carousel"
                  >
                    <el-carousel-item v-for="(img, idx) in item.images" :key="idx">
                      <el-image 
                        :src="img.url" 
                        fit="cover" 
                        loading="lazy" 
                        class="card-image"
                      />
                    </el-carousel-item>
                  </el-carousel>
                </template>
                <template v-else>
                  <el-image 
                    :src="item.image" 
                    fit="cover" 
                    loading="lazy" 
                    class="card-image"
                    :style="{ aspectRatio: item.aspectRatio }"
                  />
                </template>
                
                <div class="type-badge" v-if="item.isVideo">
                  <el-icon><VideoCamera /></el-icon>
                </div>
              </div>

              <!-- Description -->
              <div class="card-desc" v-if="item.description">
                {{ item.description }}
              </div>

              <!-- Prompt Box -->
              <div class="card-prompt" v-if="item.prompt_content">
                <div class="prompt-header-row">
                  <div class="prompt-tag">提示词</div>
                  <div class="model-tag" v-if="item.model">{{ item.model }}</div>
                </div>
                <div class="prompt-text">{{ item.prompt_content }}</div>
              </div>

              <!-- Footer: Actions -->
              <div class="card-action-footer">
                <el-button type="primary" class="try-btn" @click.stop="handleTryNow(item)">
                  立刻尝试
                </el-button>
              </div>

            </div>
          </div>
        </div>

        <div v-if="loading" class="loading-more">
            <el-icon class="is-loading"><Loading /></el-icon> 加载中...
        </div>
        <div v-if="finished && galleryItems.length > 0" class="no-more">
            没有更多灵感了
        </div>

        <!-- Footer (From HomeView) -->
        <footer class="footer">
          <div class="footer-top">
            <div class="footer-brand">
              <div class="logo">
                <img v-if="appStore.siteConfig.logo" :src="appStore.siteConfig.logo" class="logo-img" alt="Logo" />
                <span v-else class="logo-icon">{{ siteIconText }}</span>
                <span class="logo-text">{{ siteTitleText }}</span>
              </div>
              <p>{{ appStore.siteConfig.footer_text || '' }}</p>
              <div class="footer-btns">
                <el-button class="discord-btn"><el-icon><ChatLineRound /></el-icon> Join Discord</el-button>
                <el-button class="support-btn"><el-icon><Headset /></el-icon> Client Support</el-button>
              </div>
            </div>
            <div class="footer-links">
              <div class="link-group">
                <h5>MODELS</h5>
                <a href="#">Qwen Collection</a>
                <a href="#">Wan Collection</a>
              </div>
              <div class="link-group">
                <h5>RESOURCES</h5>
                <a href="#">Documentation</a>
                <a href="#">Explore</a>
                <a href="#">Contact Sales</a>
              </div>
              <div class="link-group">
                <h5>SOCIAL</h5>
                <a href="#">Discord</a>
              </div>
              <div class="link-group">
                <h5>PLAYGROUND</h5>
                <a href="#">What does it look like</a>
              </div>
            </div>
          </div>
          <div class="footer-bottom">
            <span>© {{ currentYear }} {{ siteTitleText }}</span>
            <div class="bottom-links">
              <a href="#">Privacy</a>
              <a href="#">Terms</a>
            </div>
          </div>
        </footer>
      </div>
    </div>

    <!-- Detail Dialog (From InspirationGallery) -->
    <el-dialog
      v-model="dialogVisible"
      width="800px"
      :show-close="false"
      class="inspiration-detail-dialog"
      align-center
      destroy-on-close
    >
      <template #header="{ close }">
        <div class="dialog-header">
          <div class="author-info">
            <span class="label">作者</span>
            <span class="name">{{ currentItem.author_name || 'Unknown' }}</span>
            <span class="date">{{ formatDate(currentItem.created_at) }}</span>
          </div>
          <el-button link class="close-btn" @click="close">
            <el-icon><Close /></el-icon>
          </el-button>
        </div>
      </template>

      <div class="detail-content" v-if="currentItem">
        <h1 class="detail-title">{{ currentItem.title }}</h1>
        
        <div class="detail-image-wrapper">
          <el-image 
            :src="currentItem.image" 
            fit="contain" 
            class="detail-image"
          />
        </div>

        <div class="detail-description">
          {{ currentItem.description }}
        </div>

        <div class="prompt-section" v-if="currentItem.prompt_content">
          <div class="prompt-header">
            <span class="prompt-label">提示词</span>
            <el-button link size="small" @click="copyPrompt">
              <el-icon><CopyDocument /></el-icon> 复制
            </el-button>
          </div>
          <div class="prompt-box">
            {{ currentItem.prompt_content }}
          </div>
        </div>
      </div>

      <template #footer>
        <div class="detail-footer">
          <el-button type="primary" size="large" class="try-btn" @click="handleTryNow">
            <el-icon><Lightning /></el-icon> 立刻尝试
          </el-button>
          <div class="action-buttons">
            <el-button circle size="large">
              <el-icon><Share /></el-icon>
            </el-button>
            <el-button circle size="large">
              <el-icon><Collection /></el-icon>
            </el-button>
          </div>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'
import { 
  ArrowDown, Search, ChatLineRound, Headset, UserFilled,
  VideoCamera, Star, Filter, Sort, Close, CopyDocument, Share, Collection, Lightning, Loading
} from '@element-plus/icons-vue'

defineOptions({
  name: 'CreativeGallery'
})

// --- Home/Navbar Logic ---
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const siteTitleText = computed(() => appStore.siteConfig.site_title || appStore.siteConfig.site_name || 'AI生成平台')
const siteIconText = computed(() => {
  const title = String(siteTitleText.value || '').trim()
  return title ? title.slice(0, 1) : 'A'
})
const currentYear = new Date().getFullYear()

const handleSignIn = () => {
  router.push('/login')
}

const handleCommand = (command) => {
  if (command === 'logout') {
    userStore.logout()
  } else if (command === 'creation-center') {
    router.push('/creation/general')
  }
}

// --- Inspiration Gallery Logic ---
const activeCategory = ref('all')
const categories = ref([
  { name: '全部', id: 'all' }
])

const galleryItems = ref([])
const loading = ref(false)
const finished = ref(false)
const page = ref(1)
const pageSize = ref(20)
const sortType = ref('recommend')

// Detail Dialog State
const dialogVisible = ref(false)
const currentItem = ref({})

const sortLabel = computed(() => {
  const map = { recommend: '推荐', new: '最新', hot: '最热' }
  return map[sortType.value]
})

const handleSort = (command) => {
  sortType.value = command
  fetchInspirations(true)
}

const formatDate = (dateString) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric'
  }).format(date)
}

const openDetail = (item) => {
  currentItem.value = item
  dialogVisible.value = true
}

const copyPrompt = async () => {
  if (currentItem.value.prompt_content) {
    try {
      await navigator.clipboard.writeText(currentItem.value.prompt_content)
      ElMessage.success('提示词已复制')
    } catch (err) {
      ElMessage.error('复制失败')
    }
  }
}

const handleTryNow = (item) => {
  const targetItem = item && item.prompt_content ? item : currentItem.value
  
  if (!targetItem || !targetItem.prompt_content) {
    ElMessage.warning('该灵感没有提示词')
    return
  }

  router.push({
    name: 'general-creation',
    query: {
      prompt: targetItem.prompt_content,
      model: targetItem.model || '',
      auto_create: 'true'
    }
  })
}

// Fetch Categories
const fetchCategories = async () => {
  try {
    const res = await request.get('/api/v1/inspiration/categories')
    if (res.data.code === 200) {
      const cats = res.data.data.map(c => ({
        id: c.id,
        name: c.name,
        icon: null 
      }))
      categories.value = [{ name: '全部', id: 'all' }, ...cats]
    }
  } catch (error) {
    console.error('Failed to fetch categories:', error)
  }
}

// Fetch Inspirations
const fetchInspirations = async (reset = false) => {
  if (loading.value) return
  if (reset) {
    page.value = 1
    finished.value = false
    galleryItems.value = []
  }
  if (finished.value) return

  loading.value = true
  try {
    const res = await request.get('/api/v1/inspiration/list', {
      params: {
        page: page.value,
        page_size: pageSize.value,
        category_id: activeCategory.value === 'all' ? '' : activeCategory.value,
        sort: sortType.value
      }
    })

    if (res.data.code === 200) {
      const items = res.data.data.data
      if (items.length < pageSize.value) {
        finished.value = true
      }
      galleryItems.value = reset ? items : [...galleryItems.value, ...items]
      page.value++
    }
  } catch (error) {
    console.error('Failed to fetch inspirations:', error)
    ElMessage.error('获取灵感列表失败')
  } finally {
    loading.value = false
  }
}

// Watch category change
watch(activeCategory, () => {
  fetchInspirations(true)
})

// Initial load
onMounted(() => {
  fetchCategories()
  fetchInspirations(true)
})

// Handle scroll for infinite loading
const handleScroll = (e) => {
  const { scrollTop, clientHeight, scrollHeight } = e.target
  if (scrollHeight - scrollTop - clientHeight < 150) { // Trigger slightly earlier
    fetchInspirations()
  }
}
</script>

<style scoped lang="scss">
.creative-gallery-page {
  height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #fff;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

// --- Navbar Styles (Copied from HomeView) ---
.navbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 40px;
  border-bottom: 1px solid #f0f0f0;
  background: #fff;
  flex-shrink: 0; // Prevent shrinking

  .navbar-left {
    display: flex;
    align-items: center;
    gap: 40px;
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 18px;
    
    .logo-icon {
      background: #000;
      color: #fff;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 0;
      font-size: 14px;
    }

    .logo-img {
      height: 32px;
      object-fit: contain;
    }
  }

  .nav-links {
    display: flex;
    gap: 24px;
    
    a {
      color: #666;
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      
      &:hover, &.active {
        color: #000;
      }
    }
  }

  .navbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
    
    .signin-btn {
      border-radius: 0;
      padding: 8px 20px;
      font-weight: 600;
      background-color: #111;
      border-color: #111;
      color: #fff;

      &:hover,
      &:focus {
        background-color: #000;
        border-color: #000;
        color: #fff;
      }
    }

    .user-profile-trigger {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      transition: background-color 0.2s;

      &:hover {
        background-color: #f5f7f9;
      }

      .username {
        font-size: 14px;
        font-weight: 500;
        color: #333;
      }
    }
  }
}

// --- Content Styles ---
.creative-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden; // Contain scroll area
}

.filter-header {
  padding: 16px 40px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f0f0f0;
  flex-shrink: 0;
  gap: 24px;
  background-color: #fff;

  .category-list {
    flex: 1;
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    
    &::-webkit-scrollbar {
      display: none;
    }

    .category-item {
      padding: 6px 16px;
      border-radius: 20px;
      font-size: 14px;
      color: #5f6368;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s;
      display: flex;
      align-items: center;
      gap: 6px;
      background-color: #ffffff;
      border: 1px solid transparent;

      &:hover {
        background-color: #f5f5f5;
        color: #1f1f1f;
      }

      &.active {
        background-color: #1f1f1f;
        color: #ffffff;
        font-weight: 500;
      }
      
      .cat-icon {
        font-size: 14px;
      }
    }
  }

  .filter-actions {
    display: flex;
    gap: 12px;
    flex-shrink: 0;

    .action-btn {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 6px 12px;
      border-radius: 8px;
      cursor: pointer;
      color: #444746;
      font-size: 14px;
      transition: background-color 0.2s;

      &:hover {
        background-color: #f0f1f3;
      }

      .arrow {
        font-size: 12px;
      }
    }
  }
}

.gallery-scroll-area {
  flex: 1;
  overflow-y: auto;
  padding: 0; // Removed padding to allow footer full width
}

// --- Waterfall Grid (Adapted from InspirationGallery with fixes) ---
.waterfall-grid {
  column-width: 300px;
  column-gap: 20px;
  width: 100%;
  padding: 20px 40px 0; // Moved padding here
  
  .grid-item {
    break-inside: avoid;
    margin: 0 0 24px 0;
    width: 100%;
    
    .card {
      border-radius: 0;
      overflow: hidden;
      cursor: pointer;
      transition: transform 0.2s;
      background-color: transparent;
      border: 1px solid #e0e0e0;
      padding: 16px;
      background: #fff;
      
      &:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      }

      .card-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 8px;
          font-size: 12px;
          
          .author-name {
              font-weight: 700;
              color: #5f6368;
              text-transform: uppercase;
              border-bottom: 1px solid #e0e0e0;
              padding-bottom: 2px;
          }
          
          .date {
              color: #9aa0a6;
              font-family: monospace;
          }
      }

      .card-title {
          font-size: 20px;
          font-weight: 800;
          color: #1f1f1f;
          margin: 0 0 12px 0;
          line-height: 1.2;
          word-break: break-word;
      }

      .media-wrapper {
        position: relative;
        width: 100%;
        margin-bottom: 12px;
        border: 2px solid #1f1f1f;
        
        .card-image {
          width: 100%;
          height: 100%;
          display: block;
          background-color: #f0f0f0;
        }
        
        .card-carousel {
           width: 100%;
           height: 100% !important;
           
           :deep(.el-carousel__container) {
              height: 100% !important;
           }
        }

        .type-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background-color: rgba(0,0,0,0.6);
            color: white;
            padding: 4px;
            border-radius: 4px;
            z-index: 2;
        }
      }
      
      .card-desc {
          font-size: 14px;
          color: #444746;
          margin-bottom: 12px;
          line-height: 1.5;
          display: -webkit-box;
          -webkit-line-clamp: 3;
          -webkit-box-orient: vertical;
          overflow: hidden;
      }
      
      .card-prompt {
          background-color: #f1f3f4;
          border: 1px solid #dadce0;
          border-radius: 4px;
          padding: 8px;
          margin-bottom: 16px;
          position: relative;
          
          .prompt-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
          }

          .prompt-tag {
              background-color: #000;
              color: #fff;
              display: inline-block;
              padding: 2px 6px;
              font-size: 10px;
              font-weight: 700;
          }
          
          .model-tag {
              font-size: 10px;
              color: #5f6368;
              font-weight: 600;
              background-color: #e8eaed;
              padding: 2px 6px;
              border-radius: 4px;
          }
          
          .prompt-text {
              font-family: monospace;
              font-size: 12px;
              color: #5f6368;
              max-height: 100px;
              overflow-y: auto;
              white-space: pre-wrap;
              word-break: break-all;
              
              &::-webkit-scrollbar {
                width: 4px;
              }
              &::-webkit-scrollbar-track {
                background: transparent;
              }
              &::-webkit-scrollbar-thumb {
                background: #ccc;
                border-radius: 2px;
              }
          }
      }
      
      .card-action-footer {
          display: flex;
          justify-content: space-between;
          align-items: center;
          gap: 12px;
          
          .try-btn {
              flex: 1;
              background-color: #1f1f1f;
              border-color: #1f1f1f;
              font-weight: 700;
              border-radius: 0;
              
              &:hover {
                  background-color: #333;
                  border-color: #333;
              }
          }
      }
    }
  }
}

.loading-more, .no-more {
  text-align: center;
  padding: 20px 0;
  color: #999;
  font-size: 14px;
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 8px;
}

// --- Footer Styles (Copied from HomeView) ---
.footer {
  background: #fff;
  padding: 80px 80px 40px;
  border-top: 1px solid #eee;
  margin-top: 40px;

  .footer-top {
    display: grid;
    grid-template-columns: 1.5fr 2fr;
    gap: 80px;
    margin-bottom: 60px;
  }

  .footer-brand {
    .logo {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      margin-bottom: 20px;
      
      .logo-img {
        height: 30px;
        object-fit: contain;
      }

      .logo-icon { background: #000; color: #fff; padding: 4px 8px; border-radius: 0; }
    }
    p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 32px; }
    .footer-btns {
      display: flex;
      gap: 12px;
      .el-button {
        border-radius: 0;
        padding: 10px 20px;
        font-weight: 600;
      }
    }
  }

  .footer-links {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 40px;
    
    h5 { font-size: 12px; color: #999; margin-bottom: 20px; }
    a { display: block; color: #333; text-decoration: none; font-size: 14px; margin-bottom: 12px; font-weight: 500; }
  }

  .footer-bottom {
    display: flex;
    justify-content: space-between;
    padding-top: 40px;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #999;
    
    .bottom-links {
      display: flex;
      gap: 24px;
      a { color: #999; text-decoration: none; }
    }
  }
}

// Media Queries
@media (max-width: 1024px) {
  .navbar { padding: 16px 20px; }
  .filter-header { padding: 16px 20px; }
  .waterfall-grid { padding: 20px 20px 0; }
  .footer { padding: 40px; }
  .footer-top { grid-template-columns: 1fr; gap: 40px; }
}

@media (max-width: 768px) {
  .navbar .nav-links { display: none; }
}

// Dialog Styles
:deep(.inspiration-detail-dialog) {
  border-radius: 16px;
  overflow: hidden;
  
  .el-dialog__header {
    padding: 0;
    margin: 0;
  }

  .el-dialog__body {
    padding: 0;
    max-height: 80vh;
    overflow-y: auto;
  }

  .el-dialog__footer {
    padding: 20px 30px;
    border-top: 1px solid #f0f0f0;
  }

  .dialog-header {
    padding: 20px 30px 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;

    .author-info {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      
      .label {
        font-weight: 600;
        color: #1f1f1f;
      }

      .name {
        color: #5f6368;
        font-weight: 500;
        text-transform: uppercase;
      }

      .date {
        color: #9aa0a6;
      }
    }

    .close-btn {
      font-size: 20px;
      color: #5f6368;
      &:hover {
        color: #1f1f1f;
      }
    }
  }

  .detail-content {
    padding: 10px 30px 30px;

    .detail-title {
      font-size: 28px;
      font-weight: 700;
      color: #1f1f1f;
      margin-bottom: 20px;
      line-height: 1.2;
    }

    .detail-image-wrapper {
      margin-bottom: 24px;
      border-radius: 12px;
      overflow: hidden;
      background-color: #f8f9fa;
      
      .detail-image {
        width: 100%;
        max-height: 500px;
        display: block;
      }
    }

    .detail-description {
      font-size: 16px;
      line-height: 1.6;
      color: #444746;
      margin-bottom: 24px;
    }

    .prompt-section {
      background-color: #f8f9fa;
      border-radius: 8px;
      overflow: hidden;
      border: 1px solid #e0e0e0;

      .prompt-header {
        padding: 8px 12px;
        background-color: #f0f0f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid #e0e0e0;

        .prompt-label {
          font-weight: 600;
          font-size: 12px;
          color: #1f1f1f;
        }
      }

      .prompt-box {
        padding: 12px;
        font-family: monospace;
        font-size: 14px;
        color: #444746;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-all;
      }
    }
  }

  .detail-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;

    .try-btn {
      flex: 1;
      max-width: 300px;
      font-weight: 600;
      background-color: #1f1f1f;
      border-color: #1f1f1f;
      
      &:hover {
        background-color: #333;
        border-color: #333;
      }
    }

    .action-buttons {
      display: flex;
      gap: 12px;
    }
  }
}
</style>
