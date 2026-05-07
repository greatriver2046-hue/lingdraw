<template>
  <div class="inspiration-container">
    <!-- Filter Header -->
    <div class="filter-header">
      <div class="page-title">我的作品库</div>
      
      <div class="filter-actions">
        <div class="action-btn" @click="refreshData">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </div>
      </div>
    </div>

    <!-- Waterfall Grid -->
    <div class="gallery-scroll-area">
      <div v-if="loading && galleryItems.length === 0" class="loading-state">
        <el-icon class="is-loading"><Loading /></el-icon> 加载中...
      </div>
      <div v-else-if="galleryItems.length === 0" class="empty-state">
        暂无作品，快去创作吧！
      </div>
      <div v-else class="content-wrapper">
        <div class="waterfall-grid">
          <div v-for="item in galleryItems" :key="item.id" class="grid-item">
            <div class="card" @click="openDetail(item)">
              <div class="media-wrapper">
                <el-image 
                  :src="getThumbUrl(item.image)" 
                  fit="cover" 
                  loading="lazy" 
                  class="card-image"
                  :style="{ aspectRatio: item.aspectRatio }"
                />
                <div class="overlay">
                  <div class="bottom-content">
                    <div class="card-footer">
                      <div class="card-title" v-if="item.title" :title="item.title">{{ item.title }}</div>
                      <div class="date-info">
                          {{ item.createdAt }}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Pagination -->
        <div class="pagination-container">
          <el-pagination
            v-model:current-page="currentPage"
            v-model:page-size="pageSize"
            :total="total"
            layout="prev, pager, next, jumper"
            @current-change="handlePageChange"
            background
          />
        </div>
      </div>
    </div>

    <!-- Detail Dialog -->
    <el-dialog
      v-model="detailVisible"
      title="作品详情"
      width="min(86vw, 1280px)"
      class="detail-dialog"
      align-center
    >
      <div class="detail-content" v-if="currentItem">
        <div class="detail-image-wrapper">
          <el-image 
            :src="currentItem.image" 
            fit="contain" 
            class="detail-image"
          />
        </div>
        <div class="detail-info">
          <div class="info-section">
            <h3>提示词</h3>
            <div class="prompt-text">{{ currentItem.title || '无提示词' }}</div>
          </div>
          
          <div class="info-section">
            <h3>生成时间</h3>
            <div class="info-value">{{ currentItem.createdAt }}</div>
          </div>

          <div class="info-section" v-if="currentItem.width && currentItem.height">
            <h3>分辨率</h3>
            <div class="info-value">{{ currentItem.width }} x {{ currentItem.height }}</div>
          </div>
          
           <div class="info-section" v-if="currentItem.model_identity">
            <h3>模型</h3>
            <div class="info-value">{{ currentItem.model_identity }}</div>
          </div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'

defineOptions({
  name: 'WorksGallery'
})

import { Refresh, Loading } from '@element-plus/icons-vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'

const galleryItems = ref([])
const loading = ref(false)
const currentPage = ref(1)
const pageSize = ref(40)
const total = ref(0)

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

// Detail Dialog
const detailVisible = ref(false)
const currentItem = ref(null)

const CACHE_KEY = 'works_gallery_data'
const CACHE_EXPIRE_TIME = 1000 * 60 * 5 // 5 minutes

const saveCache = (page, items, totalVal) => {
  try {
    const data = {
      page,
      items,
      total: totalVal,
      timestamp: Date.now()
    }
    localStorage.setItem(CACHE_KEY, JSON.stringify(data))
  } catch (e) {
    console.error('Save cache failed', e)
  }
}

const loadCache = () => {
  try {
    const json = localStorage.getItem(CACHE_KEY)
    if (!json) return null
    const data = JSON.parse(json)
    if (Date.now() - data.timestamp > CACHE_EXPIRE_TIME) {
      localStorage.removeItem(CACHE_KEY)
      return null
    }
    return data
  } catch (e) {
    return null
  }
}

const fetchData = async (forceRefresh = false) => {
  // If not forcing refresh and we have valid cache for page 1, use it first
  if (!forceRefresh && currentPage.value === 1) {
    const cache = loadCache()
    if (cache && cache.page === 1) {
      galleryItems.value = cache.items
      total.value = cache.total
      // Still fetch in background if needed? 
      // User requested to avoid OSS traffic, so maybe don't fetch if cache is valid?
      // But we need to know if there are new items.
      // Let's rely on cache expiration or explicit refresh.
      // If we return here, we avoid the network request completely.
      return
    }
  }

  loading.value = true
  try {
    const res = await request.get('/api/v1/image/history', {
        params: {
            page: currentPage.value,
            page_size: pageSize.value
        }
    })
    if (res.data.code === 200) {
        const data = res.data.data
        const items = data.items || []
        total.value = data.total || 0
        
        const mappedItems = items.map(item => {
            // Determine aspect ratio if width/height available
            let ratio = 1
            if (item.width && item.height) {
                ratio = item.width / item.height
            }
            return {
                id: item.id,
                title: item.prompt,
                image: item.image_url,
                aspectRatio: ratio,
                createdAt: item.created_at,
                width: item.width,
                height: item.height,
                model_identity: item.model_identity
            }
        })

        galleryItems.value = mappedItems

        // Save to cache if we are on page 1
        if (currentPage.value === 1) {
          saveCache(1, mappedItems, total.value)
        }
    }
  } catch (err) {
    console.error(err)
    ElMessage.error('获取作品失败')
  } finally {
    loading.value = false
  }
}

const refreshData = () => {
  currentPage.value = 1
  // Explicit refresh ignores cache
  fetchData(true)
}

const handlePageChange = (val) => {
  currentPage.value = val
  fetchData()
  // Scroll to top
  const scrollArea = document.querySelector('.gallery-scroll-area')
  if (scrollArea) {
    scrollArea.scrollTop = 0
  }
}

const openDetail = (item) => {
  currentItem.value = item
  detailVisible.value = true
}

onMounted(() => {
  fetchData()
})
</script>

<style scoped lang="scss">
.inspiration-container {
  height: 100%;
  display: flex;
  flex-direction: column;
  background-color: #ffffff;
  overflow: hidden;
  text-align: left;

  .filter-header {
    padding: 16px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid #f0f0f0;
    flex-shrink: 0;

    .page-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f1f1f;
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
            background-color: #f5f5f5;
        }
      }
    }
  }

  .gallery-scroll-area {
    flex: 1;
    overflow-y: auto;
    padding: 10px;
    
    .loading-state, .empty-state {
        text-align: center;
        color: #999;
        margin-top: 50px;
        font-size: 14px;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 8px;
    }
    
    .content-wrapper {
      display: flex;
      flex-direction: column;
      min-height: 100%;
    }

    .waterfall-grid {
      column-count: 7;
      column-gap: 8px;
      flex: 1;
      
      @media (min-width: 1920px) {
        column-count: 8;
      }
      @media (min-width: 2400px) {
        column-count: 10;
      }
      @media (min-width: 3200px) {
        column-count: 12;
      }

      @media (max-width: 1600px) {
        column-count: 5;
      }
      @media (max-width: 1200px) {
        column-count: 4;
      }
      @media (max-width: 900px) {
        column-count: 3;
      }
      @media (max-width: 600px) {
        column-count: 2;
      }

      .grid-item {
        break-inside: avoid;
        margin-bottom: 8px;
        
        .card {
          border-radius: 12px;
          overflow: hidden;
          cursor: pointer;
          transition: transform 0.2s;
          
          &:hover {
            .media-wrapper .overlay {
              opacity: 1;
            }
          }

          .media-wrapper {
            position: relative;
            width: 100%;
            
            .card-image {
              width: 100%;
              display: block;
              background-color: #f0f0f0;
            }

            .overlay {
              position: absolute;
              top: 0;
              left: 0;
              right: 0;
              bottom: 0;
              background: linear-gradient(to bottom, rgba(0,0,0,0) 60%, rgba(0,0,0,0.6) 100%);
              opacity: 0;
              transition: opacity 0.3s;
              display: flex;
              flex-direction: column;
              justify-content: flex-end;
              padding: 12px;
              color: white;

              .bottom-content {
                width: 100%;

                .card-footer {
                  display: flex;
                  flex-direction: column;
                  gap: 4px;
                  
                  .card-title {
                    color: white;
                    font-size: 14px;
                    font-weight: 500;
                    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                    line-height: 1.4;
                  }

                  .date-info {
                    font-size: 11px;
                    opacity: 0.8;
                    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
                  }
                }
              }
            }
          }
        }
      }
    }
    
    .pagination-container {
      display: flex;
      justify-content: center;
      padding: 20px 0;
      margin-top: auto;
    }
  }
}

.detail-content {
  display: flex;
  height: 70vh;
  gap: 24px;
  overflow: hidden;
  
  .detail-image-wrapper {
    flex: 2;
    background-color: #f5f5f5;
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 0;
    
    .detail-image {
      width: 100%;
      height: 100%;
      
      :deep(img) {
        width: 100%;
        height: 100%;
        object-fit: contain;
      }
    }
  }
  
  .detail-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 24px;
    overflow-y: auto;
    padding-right: 8px;
    
    .info-section {
      h3 {
        font-size: 14px;
        color: #5f6368;
        margin: 0 0 8px 0;
        font-weight: 500;
      }
      
      .prompt-text {
        font-size: 14px;
        line-height: 1.6;
        color: #1f1f1f;
        white-space: pre-wrap;
        background-color: #f9f9f9;
        padding: 12px;
        border-radius: 8px;
      }
      
      .info-value {
        font-size: 14px;
        color: #1f1f1f;
        font-family: monospace;
      }
    }
  }
}

@media (max-width: 768px) {
  .detail-content {
    flex-direction: column;
    height: auto;
    max-height: 70vh;
    overflow-y: auto;
    
    .detail-image-wrapper {
      flex: none;
      height: 300px;
    }
    
    .detail-info {
      flex: none;
    }
  }
}
</style>
