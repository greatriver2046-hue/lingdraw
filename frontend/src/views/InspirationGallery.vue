<template>
  <div class="inspiration-container">
    <!-- Filter Header -->
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

    <!-- Waterfall Grid -->
    <div class="gallery-scroll-area" @scroll="handleScroll">
      <div class="waterfall-grid">
        <div v-for="item in galleryItems" :key="item.id" class="grid-item">
          <div class="card" @click="openDetail(item)">
            <!-- Image (Top) -->
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
                      :src="getThumbUrl(img.url)" 
                      fit="cover" 
                      loading="lazy" 
                      class="card-image"
                    />
                  </el-carousel-item>
                </el-carousel>
              </template>
              <template v-else>
                <el-image 
                  :src="getThumbUrl(item.image)" 
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

            <div class="card-content">
              <!-- Title -->
              <h3 class="card-title">{{ item.title }}</h3>

              <!-- Header: Author & Date -->
              <div class="card-header">
                <div class="author-info">
                  <span class="author-name">@{{ item.author_name || 'UNKNOWN' }}</span>
                </div>
                <span class="date">{{ formatDate(item.created_at) }}</span>
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
      </div>
    </div>

    <!-- Detail Dialog -->
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
defineOptions({
  name: 'InspirationGallery'
})

import { ref, onMounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { 
  VideoCamera, Star, ArrowDown, Filter, Sort,
  Film, Picture, MagicStick, Camera, Close,
  CopyDocument, Share, Collection, Lightning
} from '@element-plus/icons-vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

const router = useRouter()

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

const getThumbUrl = (url) => {
  const raw = typeof url === 'string' ? url.trim() : ''
  if (!raw) return raw
  if (raw.startsWith('data:')) return raw
  if (raw.includes('x-oss-process=')) return raw

  const hashIndex = raw.indexOf('#')
  const base = hashIndex >= 0 ? raw.slice(0, hashIndex) : raw
  const hash = hashIndex >= 0 ? raw.slice(hashIndex) : ''

  const sep = base.includes('?') ? '&' : '?'
  return `${base}${sep}x-oss-process=style/w400${hash}`
}

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
    name: 'general-creation', // Ensure this route name exists, or use path: '/creation'
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
  if (scrollHeight - scrollTop - clientHeight < 100) {
    fetchInspirations()
  }
}
</script>

<style scoped lang="scss">
.inspiration-container {
  height: 100%;
  display: flex;
  flex-direction: column;
  background-color: #f7f7f7; // 调整为 #f7f7f7
  overflow: hidden;
  text-align: left;

  .filter-header {
    background-color: #f7f7f7; // 与页面背景一致
    padding: 16px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    // border-bottom: 1px solid #f0f0f0; // 移除边框以实现无缝衔接
    flex-shrink: 0;
    gap: 24px;

    .category-list {
      flex: 1;
      display: flex;
      gap: 8px;
      overflow-x: auto;
      scrollbar-width: none; // Hide scrollbar Firefox
      -ms-overflow-style: none; // Hide scrollbar IE/Edge
      
      &::-webkit-scrollbar {
        display: none; // Hide scrollbar Chrome
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
    padding: 10px;
  }

  .waterfall-grid {
    column-width: 300px;
    column-gap: 20px;
    
    .grid-item {
      break-inside: avoid;
      margin: 0 0 24px 0;
      width: 100%;
      
      .card {
        border-radius: 16px; // 更圆润的角
        overflow: hidden;
        cursor: pointer;
        transition: all 0.3s ease;
        background-color: #ffffff;
        border: 1px solid rgba(0, 0, 0, 0.04); // 极淡的边框
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04); // 默认微弱阴影
        
        &:hover {
          transform: translateY(-4px);
          box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08); // 悬浮时阴影加深
        }

        .media-wrapper {
          position: relative;
          width: 100%;
          margin: 0;
          border: none;
          background-color: #f8f9fa;
          overflow: hidden;
          
          .card-image {
            width: 100%;
            height: 100%;
            display: block;
            transition: transform 0.5s ease;
          }

          &:hover .card-image {
            transform: scale(1.03); // 图片微放大
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
              top: 10px;
              right: 10px;
              background-color: rgba(0, 0, 0, 0.3);
              backdrop-filter: blur(4px);
              color: white;
              padding: 4px 8px;
              border-radius: 6px;
              z-index: 2;
              display: flex;
              align-items: center;
              justify-content: center;
              
              .el-icon {
                font-size: 14px;
              }
          }
        }
        
        .card-content {
            padding: 16px;
            
            .card-title {
                font-size: 16px;
                font-weight: 600;
                color: #1f1f1f;
                margin: 0 0 8px 0;
                line-height: 1.4;
            }

            .card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px;
                font-size: 12px;
                
                .author-info {
                    display: flex;
                    align-items: center;
                    gap: 6px;
                }

                .author-name {
                    font-weight: 500;
                    color: #5f6368;
                }
                
                .date {
                    color: #9aa0a6;
                    font-size: 11px;
                }
            }
            
            .card-desc {
                font-size: 13px;
                color: #444746;
                margin-bottom: 16px;
                line-height: 1.5;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }
            
            .card-prompt {
                background-color: #f8f9fa;
                border-radius: 8px;
                padding: 12px;
                margin-bottom: 16px;
                border: 1px solid transparent;
                transition: border-color 0.2s;
                
                &:hover {
                  border-color: #e0e0e0;
                }
                
                .prompt-header-row {
                  display: flex;
                  justify-content: space-between;
                  align-items: center;
                  margin-bottom: 6px;
                }

                .prompt-tag {
                    color: #9aa0a6;
                    font-size: 11px;
                    font-weight: 500;
                }
                
                .model-tag {
                    font-size: 10px;
                    color: #5f6368;
                    background-color: #f1f3f4;
                    border: 1px solid #dadce0;
                    padding: 1px 5px;
                    border-radius: 4px;
                }
                
                .prompt-text {
                    font-family: 'Menlo', 'Monaco', 'Courier New', monospace;
                    font-size: 12px;
                    color: #5f6368;
                    line-height: 1.5;
                    display: -webkit-box;
                    -webkit-line-clamp: 3;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                }
            }
            
            .card-action-footer {
                .try-btn {
                    width: 100%;
                    height: 36px;
                    border-radius: 8px;
                    background-color: #1f1f1f; // 黑色
                    border: none;
                    font-size: 13px;
                    font-weight: 500;
                    
                    &:hover {
                        background-color: #333;
                        transform: translateY(-1px);
                    }
                }
            }
        }
      }
    }
  }
}

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
        background-color: #f0f0f0; // Darker header for prompt box
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
