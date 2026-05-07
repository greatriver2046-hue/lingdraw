<template>
  <div class="dashboard-container" :class="{ 'is-loading': isPageLoading }">
    <div 
      class="history-wrapper"
      :class="{ 
        'is-pinned': !isFloating,
        'is-floating': isFloating,
        'is-expanded': isFloating && shouldShowSidebar 
      }"
      @mouseenter="handleSidebarHover(true)"
      @mouseleave="handleSidebarHover(false)"
    >
      <HistorySidebar />
    </div>
    
    <div class="canvas-area" @mousedown.capture="handleWorkspaceMouseDown">
      <div v-if="isPageLoading" class="page-loading-overlay" aria-busy="true">
        <div class="page-loading-card">
          <div class="page-loading-bar" aria-hidden="true">
            <div class="page-loading-bar-inner"></div>
          </div>
        </div>
      </div>
      <div class="canvas-header-actions">
          <el-button class="clear-btn" text @click="handleArrangeCanvas">
            <el-icon><Grid /></el-icon>
            整理画布
          </el-button>
          <el-button class="clear-btn" text @click="handleClearCanvas">
            <el-icon><Refresh /></el-icon>
            清空画布
          </el-button>
      </div>
      
      <div 
        class="infinite-canvas-container" 
        :class="{ 'ctrl-down': isCtrlPressed }"
        ref="containerRef"
        @mousedown="handleContainerMouseDown"
        @contextmenu.prevent="handleContextMenu"
        @wheel.prevent="handleWheel"
        @dragover.prevent
        @drop.prevent="handleDrop"
        tabindex="0"
        @keydown="handleKeyDown"
      >
        <div 
          class="infinite-canvas-world"
          :style="{ 
            transform: `translate(${viewport.x}px, ${viewport.y}px) scale(${viewport.scale})`,
            '--scale-factor': viewport.scale
          }"
        >
          <CanvasNode 
            v-for="el in canvasElements" 
            :key="el.id" 
            :element="el"
            :selected-ids="selectedIds"
            :highlighted-id="hoveredElementId"
            :is-brush-mode="isBrushMode"
            :is-erase-mode="isEraseMode"
            :is-text-mode="isTextMode"
            :is-marker-mode="isMarkerMode"
            :marker-mode-type="markerModeType"
            :brush-size="brushSize"
            :brush-color="brushColor"
            :erase-size="eraseSize"
            :text-size="textSize"
            :text-color="textColor"
            :brush-colors="brushColors"
            :viewport="viewport"
            :generation-store="generationStore"
            :mask-canvas-refs="maskCanvasRefs"
            :erase-canvas-refs="eraseCanvasRefs"
            :erase-outline-canvas-refs="eraseOutlineCanvasRefs"
            :drawing-box="drawingElementId === el.id ? currentBox : null"
            @element-mousedown="handleElementMouseDown"
            @element-contextmenu="handleElementContextMenu"
            @element-dblclick="handleElementDblClick"
            @mask-mousedown="handleMaskMouseDown"
            @mask-mousemove="handleMaskMouseMove"
            @mask-mouseup="handleMaskMouseUp"
            @erase-mousedown="handleEraseMouseDown"
            @erase-mousemove="handleEraseMouseMove"
            @erase-mouseup="handleEraseMouseUp"
            @text-mousedown="handleTextMouseDown"
            @text-resize-start="handleTextResizeStart"
            @update-text="updateTextContent"
            @remove-text="removeText"
            @text-layer-mousedown="handleTextLayerMouseDown"
            @update-brush-size="(s) => brushSize = s"
            @update-brush-color="(c) => brushColor = c"
            @update-erase-size="(s) => eraseSize = s"
            @update-text-size="(s) => textSize = s"
            @update-text-color="(c) => textColor = c"
            @video-click="handleVideoClick"
            @remove-element="removeElement"
            @resize-start="handleResizeStart"
            @toggle-brush="(el) => { if(selectedIds.includes(el.id)) toggleBrushMode() }"
            @toggle-erase="(el) => { if(selectedIds.includes(el.id)) toggleEraseMode() }"
            @toggle-text="(el) => { if(selectedIds.includes(el.id)) toggleTextMode() }"
            @toggle-marker="handleToggleMarker"
            @undo-stroke="undoLastStroke"
            @clear-mask="clearMask"
            @clear-erase="clearErase"
            @submit-erase="submitErase"
            @register-mask-canvas="(id, dom) => { if(dom) maskCanvasRefs[id] = dom }"
            @register-erase-canvas="(id, dom) => { if(dom) eraseCanvasRefs[id] = dom }"
            @register-erase-outline-canvas="(id, dom) => { if(dom) eraseOutlineCanvasRefs[id] = dom }"
            @submit-ocr="submitOcrEdit"
            @submit-watermark="submitWatermarkRemoval"
            @close-ocr="(el) => { el.ocrTexts = [] }"
            @close-watermark="(el) => { el.showWatermarkPanel = false }"
            @video-model-change="handleVideoModelChange"
            @generate-video="handleGenerateVideo"
            @move-video="moveVideoToCanvas"
            @trigger-video-upload="triggerVideoUpload"
            @marker-layer-mousedown="handleMarkerLayerMouseDown"
            @marker-layer-mousemove="handleMarkerLayerMouseMove"
            @marker-layer-mouseup="handleMarkerLayerMouseUp"
            @remove-marker="removeMarker"
            @upscale-image="handleUpscaleImage"
            @add-element="handleAddElementFromCanvasNode"
            @request-viewport-pan="handleViewportPanRequest"
          />
        </div>
      </div>

        <div class="canvas-overlay-controls">
          <el-button-group>
            <el-button :icon="ZoomOut" @click="zoom(-0.1)" />
            <el-button class="zoom-text-btn" @click="fitToView" title="适应画布">
              {{ Math.round(viewport.scale * 100) }}%
            </el-button>
            <el-button :icon="ZoomIn" @click="zoom(0.1)" />
          </el-button-group>
        </div>
        
        <!-- Left Toolbar -->
        <div class="left-toolbar">
           <div class="tool-btn" title="上传文件" @click="triggerLeftUpload">
              <el-icon><Plus /></el-icon>
           </div>
           <div class="tool-btn" :class="{ active: showLayersPanel }" title="图层" @click="showLayersPanel = !showLayersPanel">
              <el-icon>
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </el-icon>
           </div>
        </div>

        <!-- Layers Panel -->
        <div v-if="showLayersPanel" class="layers-panel-wrapper" @mousedown.stop>
           <div class="layers-header">
              <span>图层</span>
              <el-icon class="close-btn" @click="showLayersPanel = false"><Close /></el-icon>
           </div>
           <LayerPanel 
              :elements="canvasElements"
              :selected-ids="selectedIds"
              @select="handleLayerSelect"
              @hover="handleLayerHover"
              @toggle-visibility="handleToggleVisibility"
              @toggle-lock="handleToggleLock"
              @rename="handleRename"
              @toggle-expand="handleToggleExpand"
              @move-layer="handleMoveLayer"
           />
        </div>
        <input  
           type="file" 
           ref="leftUploadInput" 
           style="display:none" 
           :accept="leftUploadAccept" 
           @change="handleLeftUpload" 
           multiple
        />
        <!-- Video Generator Upload Input -->
        <input 
           type="file" 
           ref="videoUploadInput" 
           style="display:none" 
           @change="handleVideoUpload" 
        />
        
        <!-- Context Menu -->
        <div 
          v-if="contextMenu.show" 
          class="context-menu" 
          :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
          @mousedown.stop
        >
          <template v-if="selectedIds.length > 1">
            <div class="menu-item" @click="downloadSelectedElements">
              <el-icon><Download /></el-icon>
              <span>下载图片</span>
            </div>
            <div v-if="canAddSelectedImagesToRef" class="menu-item" @click="addSelectedImagesToRef">
              <el-icon><Picture /></el-icon>
              <span>加入参考</span>
            </div>
            <div class="menu-item danger" @click="removeSelectedElements">
              <el-icon><Delete /></el-icon>
              <span>删除图片</span>
            </div>
          </template>
          <template v-else>
            <div v-if="selectedIds.length === 1 && isSelectedGroup" class="menu-item" @click="handleUngroup">
               <el-icon><Connection /></el-icon>
               <span>解散编组</span>
            </div>
            <div v-if="selectedIds.length === 1 && isSelectedGroup" class="menu-item" @click="handleMergeGroup">
               <el-icon><Picture /></el-icon>
               <span>合并图层</span>
            </div>

            <div v-if="selectedIds.length === 1 && isSelectedGroup" class="menu-divider"></div>

            <template v-if="contextMenu.type === 'canvas'">
                <div class="menu-item" @click="triggerUpload">
                  <el-icon><Upload /></el-icon>
                  <span>上传图片</span>
                </div>
                <div class="menu-item" @click="pasteFromMenu">
                  <el-icon><DocumentCopy /></el-icon>
                  <span>粘贴图片</span>
                </div>
            </template>
            <template v-else-if="contextMenu.type === 'image'">
                <div class="menu-item" @click="copyElement">
                  <el-icon><DocumentCopy /></el-icon>
                  <span>复制图片</span>
                </div>
                <div class="menu-item" @click="removeBackground">
                  <el-icon><MagicStick /></el-icon>
                  <span>去除背景</span>
                </div>
                <div class="menu-item" @click="extractTextFromImage">
                  <el-icon><EditPen /></el-icon>
                  <span>修改文字</span>
                </div>
                <div class="menu-item" @click="reversePrompt">
                  <el-icon><View /></el-icon>
                  <span style="font-size: 12px; letter-spacing: -1px;">逆推提示词</span>
                </div>
                <div class="menu-item" @click="showWatermarkPanel">
                  <el-icon><Brush /></el-icon>
                  <span>去除水印</span>
                </div>
                <div class="menu-item" @click="addToRef">
                  <el-icon><Picture /></el-icon>
                  <span>加入参考</span>
                </div>
                <div class="menu-item" @click="downloadElement">
                  <el-icon><Download /></el-icon>
                  <span>下载图片</span>
                </div>
                <div class="menu-item danger" @click="removeElementFromMenu(contextMenu.targetId)">
                  <el-icon><Delete /></el-icon>
                  <span>删除图片</span>
                </div>
            </template>
            <template v-else-if="contextMenu.type === 'video'">
                <div class="menu-item" @click="addToRef">
                  <el-icon><VideoPlay /></el-icon>
                  <span>添加到参考</span>
                </div>
                <div class="menu-item" @click="downloadElement">
                  <el-icon><Download /></el-icon>
                  <span>下载视频</span>
                </div>
                <div class="menu-item danger" @click="removeElementFromMenu(contextMenu.targetId)">
                  <el-icon><Delete /></el-icon>
                  <span>删除视频</span>
                </div>
            </template>
            <template v-else-if="contextMenu.type === 'video-generator'">
                <div class="menu-item danger" @click="removeElementFromMenu(contextMenu.targetId)">
                  <el-icon><Delete /></el-icon>
                  <span>删除生成器</span>
                </div>
            </template>
          </template>
        </div>
        
        <input type="file" ref="menuUploadInput" style="display:none" accept="image/*" @change="handleMenuUpload" multiple />
    </div>

    <div class="resize-handle" @mousedown="startResize"></div>

    <div class="chat-sidebar" :style="{ width: sidebarWidth + 'px' }" @mousedown.capture="handleWorkspaceMouseDown">
      <ChatPanel @request-width-expand="handleWidthExpand" />
    </div>

    <!-- Settings Popover (Simplified) -->
    <el-dialog v-model="showSettings" title="生成设置" width="400px">
       <el-form label-position="top">
         <el-form-item label="画面比例">
            <el-radio-group v-model="generationStore.aspectRatio">
               <el-radio-button label="1:1" />
               <el-radio-button label="4:3" />
               <el-radio-button label="16:9" />
               <el-radio-button label="3:4" />
               <el-radio-button label="9:16" />
            </el-radio-group>
         </el-form-item>
         <el-form-item label="生成数量">
            <el-input-number v-model="generationStore.imageCount" :min="1" :max="4" />
         </el-form-item>
       </el-form>
    </el-dialog>

    <el-image-viewer
      v-if="showImageViewer"
      :url-list="previewUrlList"
      @close="closeImageViewer"
      :hide-on-click-modal="true"
    />

  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, watch, nextTick, computed } from 'vue'
import request from '@/utils/request'
import { config } from '@/config'
import { ElMessage, ElImageViewer, ElMessageBox } from 'element-plus'
import { useRoute, useRouter } from 'vue-router'
import { useGenerationStore } from '@/stores/generation'
import { useChatStore } from '@/stores/chat'
import { useAppStore } from '@/stores/app'
import { 
  Refresh, Close, Plus, Setting, MagicStick, 
  ZoomIn, ZoomOut, Brush, Picture, VideoPlay, Upload, Lightning, ArrowDown,
  WarningFilled, VideoCamera, RefreshLeft, EditPen, Delete, DocumentCopy, Download, Connection, View, Grid
} from '@element-plus/icons-vue'
import ChatPanel from '@/components/ChatPanel.vue'
import HistorySidebar from '@/components/HistorySidebar.vue'
import CanvasNode from '@/components/CanvasNode.vue'
import LayerPanel from '@/components/LayerPanel.vue'

const generationStore = useGenerationStore()
const chatStore = useChatStore()
const appStore = useAppStore()
const route = useRoute()
const router = useRouter()

const baseConfigReady = ref(false)
let baseConfigPromise = null
const initBaseConfig = async () => {
  if (baseConfigReady.value) return
  if (!baseConfigPromise) {
    baseConfigPromise = Promise.all([
      generationStore.fetchImageModels(),
      generationStore.fetchVideoModels()
    ])
      .catch(() => {})
      .finally(() => {
        baseConfigReady.value = true
      })
  }
  await baseConfigPromise
}

const routeConversationId = computed(() => {
  const v = route.params?.conversation_id
  return v ? String(v) : ''
})
const conversationReadyId = ref('')
const isConversationLoading = ref(false)
const isPageLoadingRaw = computed(() => {
  if (!baseConfigReady.value) return true
  if (!routeConversationId.value) return false
  if (isConversationLoading.value) return true
  return conversationReadyId.value !== routeConversationId.value
})

const isPageLoading = ref(false)
const minLoadingUntil = ref(0)
let minLoadingTimer = null
watch(
  isPageLoadingRaw,
  (val) => {
    if (val) {
      minLoadingUntil.value = Date.now() + 1000
      if (minLoadingTimer) {
        clearTimeout(minLoadingTimer)
        minLoadingTimer = null
      }
      isPageLoading.value = true
      return
    }

    const now = Date.now()
    const remain = Math.max(0, minLoadingUntil.value - now)
    if (remain === 0) {
      isPageLoading.value = false
      return
    }
    if (minLoadingTimer) clearTimeout(minLoadingTimer)
    minLoadingTimer = setTimeout(() => {
      minLoadingTimer = null
      if (!isPageLoadingRaw.value) isPageLoading.value = false
    }, remain)
  },
  { immediate: true }
)

watch(
  () => isPageLoading.value,
  (val) => {
    appStore.setWorkspaceLoading(val)
  },
  { immediate: true }
)

// Sidebar Visibility Logic
const isLocalSidebarHovered = ref(false)
const inConversation = computed(() => !!route.params.conversation_id)
const isEnteringConversation = ref(false)

const isFloating = computed(() => appStore.isSidebarCollapsed)

const shouldShowSidebar = computed(() => {
  if (isFloating.value) {
    return isLocalSidebarHovered.value || appStore.isAppSidebarHovered
  }
  return false
})

const handleSidebarHover = (isHover) => {
  isLocalSidebarHovered.value = isHover
  appStore.setHistorySidebarHovered(isHover)
}

let fitToViewAfterSidebarTimer = null
const scheduleFitToViewAfterSidebarCollapse = () => {
  if (fitToViewAfterSidebarTimer) clearTimeout(fitToViewAfterSidebarTimer)
  fitToViewAfterSidebarTimer = setTimeout(() => {
    fitToViewAfterSidebarTimer = null
    if (isPageLoading.value) return
    nextTick(() => {
      fitToView()
    })
  }, 340)
}

watch(
  () => appStore.isSidebarCollapsed,
  (val, oldVal) => {
    if (!val || oldVal) return
    if (inConversation.value) return
    scheduleFitToViewAfterSidebarCollapse()
  }
)

const handleWorkspaceMouseDown = () => {
  if (isPageLoading.value) return
  appStore.requestSidebarCollapse(true)
}

onMounted(async () => {
  // Restore canvas state immediately to prevent data loss if network requests fail or take too long
  try {
    const savedElements = localStorage.getItem('app_canvas_elements')
    if (savedElements) {
      canvasElements.value = JSON.parse(savedElements)
    }
    
    const savedMarkers = localStorage.getItem('app_marker_prompts')
    if (savedMarkers) {
      generationStore.markerPrompts = JSON.parse(savedMarkers)
    }

    pruneOrphanMarkers()
  } catch (e) {
    console.error('Failed to restore canvas state:', e)
  }

  await initBaseConfig()

  // Handle auto-create from inspiration
  const query = route.query
  if (query.auto_create === 'true' && query.prompt) {
      // Reset conversation for a fresh start
      chatStore.currentConversationId = null
      chatStore.messages = [
        {
          id: 1,
          role: 'assistant',
          content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！',
          timestamp: Date.now()
        }
      ]
      
      // Set prompt (ChatPanel watcher will pick this up)
      generationStore.prompt = query.prompt
      
      // Set model if provided
      if (query.model) {
          const modelName = query.model
          // Try to find by name or identity
          const imgModel = generationStore.imageModels.find(m => m.name === modelName || m.model_identity === modelName)
          const vidModel = generationStore.videoModels.find(m => m.name === modelName || m.model_identity === modelName)
          
          if (imgModel) {
              generationStore.setImageModel(imgModel.model_identity)
          } else if (vidModel) {
              generationStore.setImageModel(vidModel.model_identity || vidModel.name)
          } else {
              // Fallback: if not found, maybe it's a raw identity
              generationStore.setImageModel(modelName)
          }
      }
      
      // Clear query params to prevent re-triggering on reload? 
      // Actually replacing route might be better but user might want to see the url.
      // But if they refresh, it will reset again. That's probably fine or even desired.
      router.replace({ query: {} })
  }
})



onUnmounted(() => {
  window.removeEventListener('mousemove', handleMarkerBoxMouseMove)
  window.removeEventListener('mouseup', handleMarkerBoxMouseUp)
})

// Layout
const sidebarWidth = ref(Number(localStorage.getItem('app_chat_sidebar_width')) || 450)
watch(sidebarWidth, (val) => {
  localStorage.setItem('app_chat_sidebar_width', val)
})

const handleWidthExpand = (neededWidth) => {
  if (neededWidth > sidebarWidth.value) {
    // Limit to reasonable max width, e.g., 65% of screen
    const maxAllowed = window.innerWidth * 0.65
    sidebarWidth.value = Math.min(neededWidth, maxAllowed)
  }
}

let isDraggingSidebar = false
const showSettings = ref(false)
const isDragOver = ref(false)
const showLayersPanel = ref(false)
const hoveredElementId = ref(null)
const isCtrlPressed = ref(false)

const handleGlobalKeyDown = (e) => {
  if (e && (e.key === 'Control' || e.key === 'Meta' || e.ctrlKey || e.metaKey)) {
    isCtrlPressed.value = true
  }
}

const handleGlobalKeyUp = (e) => {
  if (e && (e.key === 'Control' || e.key === 'Meta')) {
    isCtrlPressed.value = false
    return
  }
  isCtrlPressed.value = !!(e && (e.ctrlKey || e.metaKey))
}

const handleWindowBlur = () => {
  isCtrlPressed.value = false
}

// Infinite Canvas State
const containerRef = ref(null)
const canvasElements = ref([])

const pruneOrphanMarkers = () => {
  const markers = Array.isArray(generationStore.markerPrompts) ? generationStore.markerPrompts : []
  const validNumbers = new Set(markers.map(m => m.number))
  const markerMap = new Map(markers.map(m => [m.number, m]))

  canvasElements.value.forEach(el => {
    if (!el || !Array.isArray(el.markers) || el.markers.length === 0) return
    el.markers = el.markers
      .filter(m => validNumbers.has(m.number))
      .map(m => {
        const mp = markerMap.get(m.number)
        if (!mp) return m

        const xPercent = Number.isFinite(Number(mp.xPercent)) ? Number(mp.xPercent) : null
        const yPercent = Number.isFinite(Number(mp.yPercent)) ? Number(mp.yPercent) : null
        const widthPercent = Number.isFinite(Number(mp.widthPercent)) ? Number(mp.widthPercent) : null
        const heightPercent = Number.isFinite(Number(mp.heightPercent)) ? Number(mp.heightPercent) : null

        if (xPercent !== null) m.xPercent = xPercent
        if (yPercent !== null) m.yPercent = yPercent
        if (widthPercent !== null) m.widthPercent = widthPercent
        if (heightPercent !== null) m.heightPercent = heightPercent

        if (typeof mp.content === 'string') m.content = mp.content

        if (Number.isFinite(Number(m.xPercent)) && Number.isFinite(Number(m.yPercent)) && Number.isFinite(Number(m.widthPercent)) && Number.isFinite(Number(m.heightPercent))) {
          m.x = m.xPercent * el.width
          m.y = m.yPercent * el.height
          m.width = m.widthPercent * el.width
          m.height = m.heightPercent * el.height

          if (m.display === 'pin') {
            m.pointXPercent = m.xPercent + m.widthPercent / 2
            m.pointYPercent = m.yPercent + m.heightPercent / 2
          }
        }

        return m
      })
  })
}

// Auto-save canvas state
watch(canvasElements, (newVal) => {
  try {
    localStorage.setItem('app_canvas_elements', JSON.stringify(newVal))
  } catch (e) {
    console.error('Failed to save canvas state:', e)
  }
}, { deep: true })

watch(() => generationStore.markerPrompts, (newVal) => {
  try {
    localStorage.setItem('app_marker_prompts', JSON.stringify(newVal))
  } catch (e) {
    console.error('Failed to save marker prompts:', e)
  }
  pruneOrphanMarkers()
}, { deep: true })

let saveTimer = null
const viewport = reactive({ x: 0, y: 0, scale: 1 })
const handleViewportPanRequest = ({ dx = 0, dy = 0 } = {}) => {
  if (!Number.isFinite(dx) || !Number.isFinite(dy)) return
  viewport.x += dx
  viewport.y += dy
}
const getNextZIndex = () => {
  if (canvasElements.value.length === 0) return 1
  return Math.max(...canvasElements.value.map(el => el.zIndex || 0)) + 1
}
const selectedIds = ref([])
const selectedElementId = computed({
  get: () => selectedIds.value[0] || null,
  set: (val) => {
    if (val === null) selectedIds.value = []
    else selectedIds.value = [val]
  }
})

const activeTool = computed(() => {
  if (isMarkerMode.value) return 'marker'
  if (isTextMode.value) return 'text'
  if (isBrushMode.value) return 'brush'
  if (isEraseMode.value) return 'erase'
  return ''
})

const showTopToolbar = computed(() => {
  if (selectedIds.value.length !== 1) return false
  const el = findElementById(canvasElements.value, selectedIds.value[0])
  return el && el.type === 'image'
})

const canAddSelectedImagesToRef = computed(() => {
  if (!Array.isArray(selectedIds.value) || selectedIds.value.length <= 1) return false
  const els = getAllSelectedElements()
  if (!els || els.length <= 1) return false
  if (!els.every(el => el && el.type === 'image')) return false
  return els.some(el => !!el?.src)
})

const handleToolClick = (tool) => {
  const el = findElementById(canvasElements.value, selectedIds.value[0])
  if (!el) return

  switch (tool) {
    case 'marker':
      toggleMarkerMode()
      break
    case 'text':
      toggleTextMode()
      break
    case 'brush':
      toggleBrushMode()
      break
    case 'erase':
      toggleEraseMode()
      break
    case 'delete':
      removeElement(el.id)
      break
  }
}

const isSelectedGroup = computed(() => {
  if (selectedIds.value.length !== 1) return false
  const el = findElementById(canvasElements.value, selectedIds.value[0])
  return el && el.type === 'group'
})

const handleLayerSelect = (id, multi) => {
  if (multi) {
    const index = selectedIds.value.indexOf(id)
    if (index > -1) {
      selectedIds.value.splice(index, 1)
    } else {
      selectedIds.value.push(id)
    }
  } else {
    selectedIds.value = [id]
  }
}

const handleLayerHover = (id) => {
  if (!id) {
    hoveredElementId.value = null
    return
  }
  const el = findElementById(canvasElements.value, id)
  if (!el || el.hidden) {
    hoveredElementId.value = null
    return
  }
  hoveredElementId.value = id
}
const isBrushMode = ref(false)
const isEraseMode = ref(false)
const isTextMode = ref(false)
const isMarkerMode = ref(false)
const markerModeType = ref('point')
const brushSize = ref(20)
const brushColor = ref('rgba(255, 0, 0, 0.5)')
const eraseSize = ref(40)
const textSize = ref(20)
const textColor = ref('rgba(0, 0, 0, 1)')
const brushColors = [
  'rgba(255, 0, 0, 0.5)',   // Red
  'rgba(0, 0, 255, 0.5)',   // Blue
  'rgba(0, 255, 0, 0.5)',   // Green
  'rgba(255, 255, 0, 0.5)', // Yellow
  'rgba(0, 0, 0, 0.5)',     // Black
  'rgba(255, 255, 255, 0.5)', // White
  'rgba(128, 0, 128, 0.5)', // Purple
  'rgba(255, 165, 0, 0.5)'  // Orange
]
const maskCanvasRefs = ref({})
const maskHistoryRefs = ref({})
const eraseCanvasRefs = ref({})
const eraseOutlineCanvasRefs = ref({})

const saveMaskState = (el) => {
  const canvas = maskCanvasRefs.value[el.id]
  if (!canvas) return
  
  const ctx = canvas.getContext('2d')
  const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
  
  if (!maskHistoryRefs.value[el.id]) {
    maskHistoryRefs.value[el.id] = []
  }
  
  // Limit history
  if (maskHistoryRefs.value[el.id].length > 20) {
    maskHistoryRefs.value[el.id].shift()
  }
  
  maskHistoryRefs.value[el.id].push(imageData)
}

const undoLastStroke = (el) => {
  if (!maskHistoryRefs.value[el.id] || maskHistoryRefs.value[el.id].length === 0) return
  
  const canvas = maskCanvasRefs.value[el.id]
  if (!canvas) return
  
  const ctx = canvas.getContext('2d')
  const lastState = maskHistoryRefs.value[el.id].pop()
  
  ctx.putImageData(lastState, 0, 0)
}

// Watch selection to reset brush/text mode
watch(selectedElementId, () => {
  isBrushMode.value = false
  isEraseMode.value = false
  isTextMode.value = false
  isMarkerMode.value = false
})

// Preview State
const showImageViewer = ref(false)
const previewUrlList = ref([])

const handleElementDblClick = (el) => {
  if (el.type === 'image' && el.src) {
    // Clear selection to avoid blue border during preview
    selectedElementId.value = null
    
    previewUrlList.value = [el.src]
    showImageViewer.value = true
  }
}

const closeImageViewer = () => {
  showImageViewer.value = false
}

// Context Menu State
const contextMenu = reactive({ show: false, x: 0, y: 0, type: 'canvas', targetId: null })
const menuUploadInput = ref(null)

// Left Toolbar State
const leftUploadInput = ref(null)
const leftUploadAccept = ref('image/*')

const triggerLeftUpload = () => {
  leftUploadAccept.value = 'image/*,video/*'
  nextTick(() => {
    if (leftUploadInput.value) leftUploadInput.value.click()
  })
}

const handleLeftUpload = async (e) => {
  if (e.target.files && e.target.files.length) {
    await handleFiles(e.target.files)
  }
  e.target.value = ''
}

// Interaction State
let isPanning = false
let startPan = { x: 0, y: 0 }
let startViewport = { x: 0, y: 0 }

let isDraggingEl = false
let isDraggingText = false
let dragElId = null
let dragTextId = null
let hasElementMoved = false // Track if actual movement occurred
let dragStart = { x: 0, y: 0 } // mouse pos
let elStart = { x: 0, y: 0 }   // element pos
let textStart = { x: 0, y: 0 }

let isResizing = false
let resizingElementId = null
let resizeStart = { x: 0, y: 0 }
let resizeElStart = { x: 0, y: 0, w: 0, h: 0 }
let resizeAspectRatio = 1
let resizeHandleType = ''

// --- Canvas Interactions ---

const snapViewport = () => {
  const dpr = Number(window?.devicePixelRatio) || 1
  const step = 1 / dpr
  const x = Number(viewport.x)
  const y = Number(viewport.y)
  if (Number.isFinite(x)) viewport.x = Math.round(x / step) * step
  if (Number.isFinite(y)) viewport.y = Math.round(y / step) * step
}

const handleWheel = (e) => {
  const zoomSensitivity = 0.001
  const delta = -e.deltaY * zoomSensitivity
  const newScale = Math.min(Math.max(0.1, viewport.scale + delta), 5)
  
  // Zoom towards mouse position
  if (containerRef.value) {
     const rect = containerRef.value.getBoundingClientRect()
     const mouseX = e.clientX - rect.left
     const mouseY = e.clientY - rect.top
     
     const worldX = (mouseX - viewport.x) / viewport.scale
     const worldY = (mouseY - viewport.y) / viewport.scale
     
     viewport.scale = newScale
     
     viewport.x = mouseX - worldX * newScale
     viewport.y = mouseY - worldY * newScale
     snapViewport()
  } else {
     viewport.scale = newScale
  }
}

const zoom = (amount) => {
  // Zoom center
  if (containerRef.value) {
     const rect = containerRef.value.getBoundingClientRect()
     const cx = rect.width / 2
     const cy = rect.height / 2
     
     const worldX = (cx - viewport.x) / viewport.scale
     const worldY = (cy - viewport.y) / viewport.scale
     
     const newScale = Math.min(Math.max(0.1, viewport.scale + amount), 5)
     viewport.scale = newScale
     
     viewport.x = cx - worldX * newScale
     viewport.y = cy - worldY * newScale
     snapViewport()
  } else {
    viewport.scale = Math.min(Math.max(0.1, viewport.scale + amount), 5)
  }
}

const resetZoom = () => {
  if (containerRef.value) {
     const rect = containerRef.value.getBoundingClientRect()
     const cx = rect.width / 2
     const cy = rect.height / 2
     
     // Current world center
     const worldX = (cx - viewport.x) / viewport.scale
     const worldY = (cy - viewport.y) / viewport.scale
     
     viewport.scale = 1
     
     // New viewport position to keep world center at screen center
     viewport.x = cx - worldX * 1
     viewport.y = cy - worldY * 1
     snapViewport()
  } else {
    viewport.scale = 1
  }
}

const fitToView = () => {
  if (canvasElements.value.length === 0) {
    viewport.scale = 1
    viewport.x = 0
    viewport.y = 0
    return
  }
  
  // Calculate bounding box of all elements
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  canvasElements.value.forEach(el => {
    minX = Math.min(minX, el.x)
    minY = Math.min(minY, el.y)
    maxX = Math.max(maxX, el.x + el.width)
    maxY = Math.max(maxY, el.y + el.height)
  })
  
  if (containerRef.value) {
    const rect = containerRef.value.getBoundingClientRect()
    const padding = 50
    const w = maxX - minX + padding * 2
    const h = maxY - minY + padding * 2
    
    const scaleX = rect.width / w
    const scaleY = rect.height / h
    const newScale = Math.min(scaleX, scaleY, 1) // Don't zoom in too much
    
    viewport.scale = newScale
    // Center the bounding box
    const centerX = (minX + maxX) / 2
    const centerY = (minY + maxY) / 2
    
    viewport.x = rect.width / 2 - centerX * newScale
    viewport.y = rect.height / 2 - centerY * newScale
    snapViewport()
  }
}

const resetView = () => {
  viewport.scale = 1
  viewport.x = 0
  viewport.y = 0
}

const handleContainerMouseDown = (e) => {
  // Focus the container to capture key events (paste, delete)
  if (containerRef.value) containerRef.value.focus()

  // Close layers panel if open
  if (showLayersPanel.value) showLayersPanel.value = false

  // Deactivate marker mode if active
  if (isMarkerMode.value) isMarkerMode.value = false
  if (isEraseMode.value) isEraseMode.value = false

  // If clicked on background (not element), start panning
  if (e.target.closest('.canvas-node') === null) {
      if (e.button === 0 || e.button === 1) {
        isPanning = true
        startPan = { x: e.clientX, y: e.clientY }
        startViewport = { x: viewport.x, y: viewport.y }
        selectedElementId.value = null // Deselect
        
        // Ensure marker mode is turned off when clicking on background
        isMarkerMode.value = false
      }
  }
}

const findElementById = (elements, id) => {
  for (const el of elements) {
    if (el.id === id) return el
    if (el.children) {
      const found = findElementById(el.children, id)
      if (found) return found
    }
  }
  return null
}

const getAllSelectedElements = () => {
  const result = []
  const traverse = (elements) => {
    for (const el of elements) {
      if (selectedIds.value.includes(el.id)) {
        result.push(el)
      }
      if (el.children) {
        traverse(el.children)
      }
    }
  }
  traverse(canvasElements.value)
  return result
}

let dragStarts = {}

const handleElementMouseDown = (e, el) => {
  if (e.button !== 0) return
  
  // Disable brush mode when starting to drag
  if (isBrushMode.value) isBrushMode.value = false
  if (isEraseMode.value) isEraseMode.value = false
  
  e.stopPropagation()

  if (e.ctrlKey || e.metaKey) {
    const index = selectedIds.value.indexOf(el.id)
    if (index > -1) {
      selectedIds.value.splice(index, 1)
    } else {
      selectedIds.value.push(el.id)
    }
  } else {
    if (!selectedIds.value.includes(el.id)) {
      selectedIds.value = [el.id]
    }
  }
  
  // Bring to front (update zIndex) - Only if single selection or logic allows
  if (selectedIds.value.length === 1) {
      const maxZ = canvasElements.value.length > 0 
         ? Math.max(...canvasElements.value.map(e => e.zIndex || 0)) 
         : 0
      
      if (el.zIndex < maxZ) {
          el.zIndex = maxZ + 1
      }
  }

  // Start dragging element
  isDraggingEl = true
  hasElementMoved = false
  dragElId = el.id
  dragStart = { x: e.clientX, y: e.clientY }
  
  // Save state for undo
  tempStateBeforeDrag = JSON.parse(JSON.stringify(canvasElements.value))
  
  dragStarts = {}
  const selectedEls = getAllSelectedElements()
  selectedEls.forEach(item => {
    dragStarts[item.id] = { x: item.x, y: item.y }
  })
}

const handleVideoClick = (e) => {
  // Prevent default click behavior (play/pause) on the video element itself
  // Only allow controls to trigger playback
  e.preventDefault()
  e.stopPropagation()
}

const handleContextMenu = (e) => {
  // If we are clicking on background, show canvas menu
  contextMenu.show = true
  contextMenu.x = e.clientX
  contextMenu.y = e.clientY
  contextMenu.type = 'canvas'
  contextMenu.targetId = null
}

const handleElementContextMenu = (e, el) => {
  // If the element is not already selected, select it (and deselect others)
  if (!selectedIds.value.includes(el.id)) {
      selectedIds.value = [el.id]
  }

  contextMenu.show = true
  contextMenu.x = e.clientX
  contextMenu.y = e.clientY
  contextMenu.type = el.type // 'image' or 'video'
  contextMenu.targetId = el.id
}

const closeContextMenu = () => { contextMenu.show = false }

const removeElementsByIds = (elements, idsSet, removed) => {
  const next = []
  for (const el of elements) {
    if (idsSet.has(el.id)) {
      removed.push(el)
      continue
    }
    if (el.children && Array.isArray(el.children) && el.children.length > 0) {
      el.children = removeElementsByIds(el.children, idsSet, removed)
    }
    next.push(el)
  }
  return next
}

const removeSelectedElements = () => {
  closeContextMenu()
  if (!Array.isArray(selectedIds.value) || selectedIds.value.length === 0) return

  pushState()

  const idsSet = new Set(selectedIds.value)
  const removed = []
  canvasElements.value = removeElementsByIds(canvasElements.value, idsSet, removed)

  if (removed.length === 0) {
    undoStack.value.pop()
    return
  }

  removed.forEach((el) => {
    if (el?.src) processedUrls.value.add(el.src)
    if (typeof el?.id === 'string') {
      let msgId = null
      if (el.id.startsWith('Video-')) msgId = el.id.substring(6)
      else if (el.id.startsWith('Image-')) msgId = el.id.substring(6)
      if (msgId) processedMsgIds.value.add(msgId)
    }
  })
  saveProcessedIds()

  selectedIds.value = []
  selectedElementId.value = null

  if (chatStore.currentConversationId) {
    if (saveTimer) {
      clearTimeout(saveTimer)
      saveTimer = null
    }
    chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
  }
}

const handleGlobalPointerDownCapture = (e) => {
  if (!contextMenu.show) return
  const t = e?.target
  if (t?.closest?.('.context-menu')) return
  closeContextMenu()
}

const handleResizeStart = (e, el, handle) => {
  e.stopPropagation() // Prevent element drag
  
  // Disable brush mode when starting to resize
  if (isBrushMode.value) isBrushMode.value = false
  
  isResizing = true
  resizingElementId = el.id
  resizeHandleType = handle
  resizeStart = { x: e.clientX, y: e.clientY }
  resizeElStart = { x: el.x, y: el.y, w: el.width, h: el.height }

  // Save state for undo
  tempStateBeforeDrag = JSON.parse(JSON.stringify(canvasElements.value))
  
  // Use natural dimensions for aspect ratio if available to fix any distortion
  if (el.type === 'image' && el.naturalWidth && el.naturalHeight) {
    resizeAspectRatio = el.naturalWidth / el.naturalHeight
  } else {
    resizeAspectRatio = el.width / el.height
  }

  // Ensure texts have percentage coordinates for scaling
  if (el.texts) {
    el.texts.forEach(t => {
      if (t.xPercent === undefined) t.xPercent = t.x / el.width
      if (t.yPercent === undefined) t.yPercent = t.y / el.height
    })
  }
}

const handleGlobalMouseMove = (e) => {
  if (isPanning) {
    const dx = e.clientX - startPan.x
    const dy = e.clientY - startPan.y
    viewport.x = startViewport.x + dx
    viewport.y = startViewport.y + dy
  } else if (isDraggingEl && dragElId) {
    const dx = (e.clientX - dragStart.x) / viewport.scale
    const dy = (e.clientY - dragStart.y) / viewport.scale
    
    // Check threshold to mark as moved
    if (Math.abs(e.clientX - dragStart.x) > 2 || Math.abs(e.clientY - dragStart.y) > 2) {
       hasElementMoved = true
    }

    const selectedEls = getAllSelectedElements()
    selectedEls.forEach(el => {
      if (dragStarts[el.id]) {
        el.x = dragStarts[el.id].x + dx
        el.y = dragStarts[el.id].y + dy
      }
    })
  } else if (isDraggingText && dragTextId && dragElId) {
    const dx = (e.clientX - dragStart.x) / viewport.scale
    const dy = (e.clientY - dragStart.y) / viewport.scale
    
    // Check threshold to mark as moved
    if (Math.abs(e.clientX - dragStart.x) > 2 || Math.abs(e.clientY - dragStart.y) > 2) {
       hasElementMoved = true
       e.preventDefault()
    }

    const el = canvasElements.value.find(e => e.id === dragElId)
    if (el && el.texts) {
      const text = el.texts.find(t => t.id === dragTextId)
      if (text) {
        text.x = textStart.x + dx
        text.y = textStart.y + dy
        text.xPercent = text.x / el.width
        text.yPercent = text.y / el.height
      }
    }
  } else if (isResizingText && dragTextId && dragElId) {
    const dx = (e.clientX - resizeStart.x) / viewport.scale
    const dy = (e.clientY - resizeStart.y) / viewport.scale
    
    // Average delta to drive resize
    const delta = (dx + dy) / 2
    
    const el = canvasElements.value.find(e => e.id === dragElId)
    if (el && el.texts) {
      const text = el.texts.find(t => t.id === dragTextId)
      if (text) {
        text.size = Math.max(8, resizeTextStart.size + delta)
      }
    }
  } else if (isResizing && resizingElementId) {
    const el = findElementById(canvasElements.value, resizingElementId)
    if (el) {
      const dx = (e.clientX - resizeStart.x) / viewport.scale
      const dy = (e.clientY - resizeStart.y) / viewport.scale
      
      const minSize = 20
      let newW = resizeElStart.w
      let newH = resizeElStart.h
      let newX = resizeElStart.x
      let newY = resizeElStart.y

      // Helper to maintain aspect ratio
      const maintainRatio = (w, h, lockedSide) => {
        if (lockedSide === 'w') return w / resizeAspectRatio
        if (lockedSide === 'h') return h * resizeAspectRatio
        return h // fallback
      }

      // Handle logic
      switch (resizeHandleType) {
        case 'se':
          newW = Math.max(minSize, resizeElStart.w + dx)
          newH = maintainRatio(newW, 0, 'w')
          break
        case 'sw':
          newW = Math.max(minSize, resizeElStart.w - dx)
          newH = maintainRatio(newW, 0, 'w')
          newX = resizeElStart.x + (resizeElStart.w - newW)
          break
        case 'ne':
          newW = Math.max(minSize, resizeElStart.w + dx)
          newH = maintainRatio(newW, 0, 'w')
          newY = resizeElStart.y + (resizeElStart.h - newH)
          break
        case 'nw':
          newW = Math.max(minSize, resizeElStart.w - dx)
          newH = maintainRatio(newW, 0, 'w')
          newX = resizeElStart.x + (resizeElStart.w - newW)
          newY = resizeElStart.y + (resizeElStart.h - newH)
          break
        case 'e':
          newW = Math.max(minSize, resizeElStart.w + dx)
          newH = maintainRatio(newW, 0, 'w')
          break
        case 'w':
          newW = Math.max(minSize, resizeElStart.w - dx)
          newH = maintainRatio(newW, 0, 'w')
          newX = resizeElStart.x + (resizeElStart.w - newW)
          break
        case 's':
          newH = Math.max(minSize, resizeElStart.h + dy)
          newW = maintainRatio(0, newH, 'h')
          break
        case 'n':
          newH = Math.max(minSize, resizeElStart.h - dy)
          newW = maintainRatio(0, newH, 'h')
          newY = resizeElStart.y + (resizeElStart.h - newH)
          break
      }

      el.width = newW
      el.height = newH
      el.x = newX
      el.y = newY
      
      // Update text pixels to match new scale
      if (el.texts) {
        el.texts.forEach(t => {
          if (t.xPercent !== undefined) t.x = t.xPercent * newW
          if (t.yPercent !== undefined) t.y = t.yPercent * newH
        })
      }
    }
  } else if (isDraggingSidebar) {
    const container = document.querySelector('.dashboard-container')
    if (container) {
       const rect = container.getBoundingClientRect()
       const w = rect.right - e.clientX
       const maxW = window.innerWidth / 2
       if (w >= 300 && w <= maxW) sidebarWidth.value = w
    }
  }
}

const updateGroupBounds = (group) => {
  if (!group.children || group.children.length === 0) return

  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  
  group.children.forEach(child => {
    minX = Math.min(minX, child.x)
    minY = Math.min(minY, child.y)
    maxX = Math.max(maxX, child.x + child.width)
    maxY = Math.max(maxY, child.y + child.height)
  })
  
  const newWidth = maxX - minX
  const newHeight = maxY - minY
  
  const dx = minX
  const dy = minY
  
  if (dx === 0 && dy === 0 && newWidth === group.width && newHeight === group.height) return

  group.x += dx
  group.y += dy
  group.width = newWidth
  group.height = newHeight
  
  group.children.forEach(child => {
    child.x -= dx
    child.y -= dy
  })
}

const handleGlobalMouseUp = () => {
  if ((isDraggingEl && hasElementMoved) || isResizing || isDraggingText || isResizingText) {
    // Auto-resize groups if children were moved or resized
    if ((isDraggingEl && hasElementMoved) || isResizing) {
       const selected = getAllSelectedElements()
       const groupsToUpdate = new Map()
       
       selected.forEach(el => {
           const path = findPath(canvasElements.value, el.id)
           if (path) {
               // Iterate all ancestors (exclude last item which is el itself)
               for (let i = 0; i < path.length - 1; i++) {
                   const node = path[i]
                   if (node.type === 'group') {
                       if (!groupsToUpdate.has(node.id) || groupsToUpdate.get(node.id).depth < i) {
                           groupsToUpdate.set(node.id, { group: node, depth: i })
                       }
                   }
               }
           }
       })
       
       // Sort by depth descending (deepest first)
       const sortedGroups = Array.from(groupsToUpdate.values())
           .sort((a, b) => b.depth - a.depth)
           .map(item => item.group)
           
       sortedGroups.forEach(group => updateGroupBounds(group))
    }

    if (tempStateBeforeDrag) {
       undoStack.value.push(tempStateBeforeDrag)
       if (undoStack.value.length > maxHistory) undoStack.value.shift()
       redoStack.value = []
    }
  }
  tempStateBeforeDrag = null

  isPanning = false
  isDraggingEl = false
  dragElId = null
  isDraggingText = false
  dragTextId = null
  isResizing = false
  isResizingText = false
  resizingElementId = null
  if (isDraggingSidebar) {
    isDraggingSidebar = false
    document.body.style.cursor = ''
    document.body.style.userSelect = ''
  }
  isDrawingMask = false
}

// --- Brush / Masking Logic ---
let isDrawingMask = false
let lastMaskPoint = { x: 0, y: 0 }
let isDrawingErase = false

const toggleBrushMode = () => {
  isBrushMode.value = !isBrushMode.value
  if (isBrushMode.value) {
    isEraseMode.value = false
    isTextMode.value = false
    isMarkerMode.value = false
  }
}

const toggleEraseMode = () => {
  isEraseMode.value = !isEraseMode.value
  if (isEraseMode.value) {
    isBrushMode.value = false
    isTextMode.value = false
    isMarkerMode.value = false
    const el = selectedElementId.value ? findElementById(canvasElements.value, selectedElementId.value) : null
    if (el) {
      if (el.hasEraseStrokes === undefined) el.hasEraseStrokes = false
      if (el.eraseProcessing === undefined) el.eraseProcessing = false
    }
  }
}

const getErasePanelElementId = () => {
  if (!isEraseMode.value) return null
  if (!Array.isArray(selectedIds.value) || selectedIds.value.length !== 1) return null
  const id = selectedIds.value[0]
  const el = id ? findElementById(canvasElements.value, id) : null
  if (!el || el.type !== 'image') return null
  return el.id
}

const lastErasePanelElementId = ref(null)
watch([isEraseMode, selectedIds], () => {
  const currentId = getErasePanelElementId()
  const prevId = lastErasePanelElementId.value
  if (prevId && prevId !== currentId) {
    const prevEl = findElementById(canvasElements.value, prevId)
    if (prevEl && prevEl.type === 'image') {
      clearErase(prevEl)
    }
  }
  lastErasePanelElementId.value = currentId
}, { deep: true })

const toggleTextMode = () => {
  isTextMode.value = !isTextMode.value
  if (isTextMode.value) {
    isBrushMode.value = false
    isEraseMode.value = false
    isMarkerMode.value = false
  }
}

const toggleMarkerMode = () => {
  isMarkerMode.value = !isMarkerMode.value
  if (isMarkerMode.value) {
    isBrushMode.value = false
    isEraseMode.value = false
    isTextMode.value = false
  }
}

const handleToggleMarker = (el, mode) => {
  if (mode === 'box' || mode === 'point') {
    markerModeType.value = mode
    isMarkerMode.value = true
    isBrushMode.value = false
    isEraseMode.value = false
    isTextMode.value = false
    return
  }
  toggleMarkerMode()
}

const markerSubmitTimers = new Map()

const isBoxDrawing = ref(false)
const boxStart = ref({ x: 0, y: 0 })
const currentBox = ref(null) // Temporary box during drag
const drawingElementId = ref(null)
const drawingElement = ref(null) // Store the element being drawn on

const handleMarkerBoxMouseMove = (e) => {
  if (!isBoxDrawing.value || !drawingElement.value) return

  const el = drawingElement.value
  const domEl = document.getElementById('element-' + el.id)
  if (!domEl) return

  const rect = domEl.getBoundingClientRect()
  const scaleX = rect.width / el.width
  const scaleY = rect.height / el.height

  // Prevent division by zero
  if (scaleX === 0 || scaleY === 0) return

  const currentX = (e.clientX - rect.left) / scaleX
  const currentY = (e.clientY - rect.top) / scaleY

  const startX = boxStart.value.x
  const startY = boxStart.value.y

  const width = Math.abs(currentX - startX)
  const height = Math.abs(currentY - startY)
  const x = Math.min(startX, currentX)
  const y = Math.min(startY, currentY)

  currentBox.value = { x, y, width, height }
}

const handleMarkerBoxMouseUp = (e) => {
  if (!isBoxDrawing.value || !drawingElement.value) return
  
  const el = drawingElement.value
  const finalBox = currentBox.value
  
  isBoxDrawing.value = false
  drawingElementId.value = null
  drawingElement.value = null
  currentBox.value = null
  
  window.removeEventListener('mousemove', handleMarkerBoxMouseMove)
  window.removeEventListener('mouseup', handleMarkerBoxMouseUp)

  if (!finalBox) return

  let { x, y, width, height } = finalBox
  
  if (width < 10 || height < 10) {
    return
  }
  
  // Clamp to element bounds
  if (x < 0) x = 0
  if (y < 0) y = 0
  if (x + width > el.width) width = el.width - x
  if (y + height > el.height) height = el.height - y
  if (width <= 0 || height <= 0) return
  
  if (!el.markers) el.markers = []
  
  // Calculate next number globally to avoid collisions across layers
  const allMarkers = generationStore.markerPrompts
  const maxNum = allMarkers.reduce((max, m) => Math.max(max, m.number), 0)
  const nextNum = maxNum + 1
  
  // Calculate percentages
  const xPercent = x / el.width
  const yPercent = y / el.height
  const widthPercent = width / el.width
  const heightPercent = height / el.height

  const marker = {
    id: Date.now(),
    x,
    y,
    width,
    height,
    xPercent,
    yPercent,
    widthPercent,
    heightPercent,
    number: nextNum
  }

  el.markers.push(marker)

  // Add marker to store with loading state
  generationStore.addMarkerPrompt({
    id: marker.id,
    number: nextNum,
    content: '识别中...',
    status: 'loading',
    xPercent,
    yPercent,
    widthPercent,
    heightPercent,
    elementId: el.id,
    elementSrc: el.src // Pass source image for reference
  })
  
  // Generate thumbnail asynchronously (optional for box, but good for UI)
  generateMarkerThumbnail(el, marker)

  // Only trigger recognition for this specific new marker
  triggerMarkerRecognition(el, nextNum)
}

const submitMarkerPointSelect = (e, el) => {
  try {
    if (!el || !el.id || el.type !== 'image') return
    if (e.button !== 0) return

    const domEl = document.getElementById('element-' + el.id)
    if (!domEl) return

    const rect = domEl.getBoundingClientRect()
    if (el.width === 0 || el.height === 0 || rect.width === 0 || rect.height === 0) return

    const scaleX = rect.width / el.width
    const scaleY = rect.height / el.height
    if (scaleX === 0 || scaleY === 0) return

    const clickX = (e.clientX - rect.left) / scaleX
    const clickY = (e.clientY - rect.top) / scaleY

    const naturalWidth = Number(el.naturalWidth) || el.width
    const naturalHeight = Number(el.naturalHeight) || el.height
    const minNatural = Math.min(naturalWidth, naturalHeight)
    const baseRef = 1024
    const baseSize = 220
    let sizeNatural = baseSize * (minNatural / baseRef)
    if (sizeNatural < 90) sizeNatural = 90
    if (sizeNatural > 600) sizeNatural = 600

    let width = sizeNatural * (el.width / naturalWidth)
    let height = sizeNatural * (el.height / naturalHeight)

    let x = clickX - width / 2
    let y = clickY - height / 2

    if (x < 0) x = 0
    if (y < 0) y = 0
    if (x + width > el.width) width = el.width - x
    if (y + height > el.height) height = el.height - y
    if (width <= 0 || height <= 0) return

    if (!el.markers) el.markers = []

    const allMarkers = generationStore.markerPrompts
    const maxNum = allMarkers.reduce((max, m) => Math.max(max, m.number), 0)
    const nextNum = maxNum + 1

    const xPercent = x / el.width
    const yPercent = y / el.height
    const widthPercent = width / el.width
    const heightPercent = height / el.height
    const pointXPercent = clickX / el.width
    const pointYPercent = clickY / el.height

    const marker = {
      id: Date.now(),
      display: 'pin',
      x,
      y,
      width,
      height,
      xPercent,
      yPercent,
      widthPercent,
      heightPercent,
      pointXPercent,
      pointYPercent,
      number: nextNum
    }

    el.markers.push(marker)

    generationStore.addMarkerPrompt({
      id: marker.id,
      number: nextNum,
      content: '识别中...',
      status: 'loading',
      xPercent,
      yPercent,
      widthPercent,
      heightPercent,
      display: 'pin',
      pointXPercent,
      pointYPercent,
      elementId: el.id,
      elementSrc: el.src
    })

    generateMarkerThumbnail(el, marker)
    triggerMarkerRecognition(el, nextNum)
  } catch (err) {
    console.error('Error in submitMarkerPointSelect:', err)
  }
}

const handleMarkerLayerMouseDown = (e, el) => {
  try {
    if (!isMarkerMode.value) return
    if (e.button !== 0) return
    
    // Safety check for element
    if (!el || !el.id) {
       console.warn('Marker layer mousedown: Invalid element', el)
       return
    }

    if (!selectedIds.value.includes(el.id) || selectedIds.value.length !== 1) {
      selectedIds.value = [el.id]
    }

    if (markerModeType.value === 'point') {
      submitMarkerPointSelect(e, el)
      return
    }

    // Use robust coordinate calculation
    const domEl = document.getElementById('element-' + el.id)
    if (!domEl) {
       console.warn('Marker layer mousedown: DOM element not found', el.id)
       return
    }
    
    const rect = domEl.getBoundingClientRect()
    // Prevent division by zero
    if (el.width === 0 || el.height === 0 || rect.width === 0 || rect.height === 0) {
      console.warn('Marker layer mousedown: Invalid dimensions', el.width, el.height, rect)
      return
    }

    isBoxDrawing.value = true
    drawingElement.value = el
    drawingElementId.value = el.id

    const scaleX = rect.width / el.width
    const scaleY = rect.height / el.height
    
    const startX = (e.clientX - rect.left) / scaleX
    const startY = (e.clientY - rect.top) / scaleY
    
    boxStart.value = { x: startX, y: startY }
    
    // Initialize current box
    currentBox.value = {
      x: startX,
      y: startY,
      width: 0,
      height: 0
    }
    
    window.addEventListener('mousemove', handleMarkerBoxMouseMove)
    window.addEventListener('mouseup', handleMarkerBoxMouseUp)
  } catch (err) {
    console.error('Error in handleMarkerLayerMouseDown:', err)
    // Clean up if error occurs
    isBoxDrawing.value = false
    drawingElement.value = null
    drawingElementId.value = null
  }
}

// Keep these for template compatibility but make them empty
const handleMarkerLayerMouseMove = (e, el) => {}
const handleMarkerLayerMouseUp = (e, el) => {}

// Ensure MouseUp is global if user releases outside? 
// For now, attach to layer. If user drags out, it might be an issue.
// Better to attach 'mouseup' to window during drag.
// But we'll stick to layer handlers for now as per request scope.

const generateMarkerThumbnail = async (el, marker) => {
  try {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = reject
      img.src = el.src
    })

    const canvas = document.createElement('canvas')
    const ctx = canvas.getContext('2d')
    
    // Crop size: Use the marker's box size
    const naturalX = marker.xPercent * img.naturalWidth
    const naturalY = marker.yPercent * img.naturalHeight
    const naturalWidth = (marker.widthPercent || 0.1) * img.naturalWidth
    const naturalHeight = (marker.heightPercent || 0.1) * img.naturalHeight
    
    canvas.width = naturalWidth
    canvas.height = naturalHeight
    
    // Draw slice
    ctx.drawImage(
      img, 
      naturalX, 
      naturalY, 
      naturalWidth, 
      naturalHeight, 
      0, 
      0, 
      naturalWidth, 
      naturalHeight
    )
    
    // Compress
    const thumbnail = canvas.toDataURL('image/jpeg', 0.8)
    
    // Update marker in store
    generationStore.updateMarkerThumbnail(marker.number, thumbnail)
    
    // Update local marker
    marker.thumbnail = thumbnail
    
  } catch (e) {
    console.error('Failed to generate marker thumbnail', e)
  }
}

const removeMarker = (el, markerId) => {
  if (!el.markers) return
  const idx = el.markers.findIndex(m => m.id === markerId)
  if (idx > -1) {
    const marker = el.markers[idx]
    el.markers.splice(idx, 1)
    
    // Remove from store
    generationStore.removeMarkerPrompt(marker.number)
    
    // Cancel any pending recognition for this marker
    if (markerSubmitTimers.has(marker.number)) {
      clearTimeout(markerSubmitTimers.get(marker.number))
      markerSubmitTimers.delete(marker.number)
    }
  }
}

const triggerMarkerRecognition = (el, targetMarkerNum = null) => {
  const key = targetMarkerNum ? `${el.id}-${targetMarkerNum}` : `${el.id}-all`
  
  if (markerSubmitTimers.has(key)) {
    clearTimeout(markerSubmitTimers.get(key))
  }
  
  const timer = setTimeout(() => {
    submitMarkerRecognition(el, targetMarkerNum)
    markerSubmitTimers.delete(key)
  }, 2000)
  
  markerSubmitTimers.set(key, timer)
}

const submitMarkerRecognition = async (el, targetMarkerNum = null) => {
  if (!el.markers || el.markers.length === 0) return
  
  el.recognizingMarkers = true 
  
  try {
    // 1. Create temporary canvas to composite markers
    const canvas = document.createElement('canvas')
    const ctx = canvas.getContext('2d')
    const img = new Image()
    img.crossOrigin = 'anonymous'
    
    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = reject
      img.src = el.src // Use original src
    })
    
    // Resize if too large to ensure markers are visible and file size is small
    const MAX_DIM = 1024
    let targetWidth = img.width
    let targetHeight = img.height
    
    let cropX = 0
    let cropY = 0
    let isCropped = false
    let sourceWidth = img.width
    let sourceHeight = img.height

    // If targetMarkerNum is provided (single marker), crop exactly what the user selected
    // plus some padding for context
    if (targetMarkerNum) {
      const marker = el.markers.find(m => m.number === targetMarkerNum)
      if (marker) {
        const fullW = img.width
        const fullH = img.height

        const naturalX = marker.xPercent * fullW
        const naturalY = marker.yPercent * fullH
        const naturalWidth = (marker.widthPercent || 0.1) * fullW
        const naturalHeight = (marker.heightPercent || 0.1) * fullH
        
        // Add padding (context)
        // Expand by 20% of the larger dimension or fixed pixel amount?
        // Let's try expanding by 50% of the box size in each direction to provide good context
        // Ensure we don't go out of bounds
        
        const paddingRatio = 0.5 // 50% expansion
        const paddingX = naturalWidth * paddingRatio
        const paddingY = naturalHeight * paddingRatio
        
        const expandedX = Math.max(0, naturalX - paddingX)
        const expandedY = Math.max(0, naturalY - paddingY)
        const expandedW = Math.min(fullW, naturalX + naturalWidth + paddingX) - expandedX
        const expandedH = Math.min(fullH, naturalY + naturalHeight + paddingY) - expandedY
        
        cropX = expandedX
        cropY = expandedY
        sourceWidth = expandedW
        sourceHeight = expandedH
        
        // Update target size to match crop size initially
        targetWidth = sourceWidth
        targetHeight = sourceHeight
        isCropped = true
      }
    }

    // Resize if target dimensions (whether full or cropped) exceed MAX_DIM
    if (targetWidth > MAX_DIM || targetHeight > MAX_DIM) {
      const ratio = Math.min(MAX_DIM / targetWidth, MAX_DIM / targetHeight)
      targetWidth = Math.round(targetWidth * ratio)
      targetHeight = Math.round(targetHeight * ratio)
    }

    canvas.width = targetWidth
    canvas.height = targetHeight
    
    // Draw image
    // sourceWidth/Height is the size of the area to read from original image
    // targetWidth/Height is the size to draw on canvas
    ctx.drawImage(img, cropX, cropY, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight)
    
    // Draw Blue Box for the marker
    if (isCropped && targetMarkerNum) {
       const marker = el.markers.find(m => m.number === targetMarkerNum)
       if (marker) {
          const fullW = img.width
          const fullH = img.height

          const naturalX = marker.xPercent * fullW
          const naturalY = marker.yPercent * fullH
          const naturalW = (marker.widthPercent || 0.1) * fullW
          const naturalH = (marker.heightPercent || 0.1) * fullH
          
          // Calculate relative position in the crop
          const relativeX = naturalX - cropX
          const relativeY = naturalY - cropY
          
          // Scale to target canvas size
          const scaleX = targetWidth / sourceWidth
          const scaleY = targetHeight / sourceHeight
          
          const mx = relativeX * scaleX
          const my = relativeY * scaleY
          const mw = naturalW * scaleX
          const mh = naturalH * scaleY
          
          // Draw Blue Box
          ctx.strokeStyle = '#409eff'
          ctx.lineWidth = 3
          ctx.strokeRect(mx, my, mw, mh)
       }
    }
    
    // If NOT cropped (full image batch), draw markers as boxes?
    if (!isCropped) {
        const markersToDraw = targetMarkerNum 
            ? el.markers.filter(m => m.number === targetMarkerNum)
            : el.markers.filter(m => {
                const mp = generationStore.markerPrompts.find(p => p.number === m.number)
                return mp && mp.status === 'loading'
            })

        markersToDraw.forEach(m => {
          // Calculate marker position relative to the source area (full)
          const naturalX = m.xPercent * img.width
          const naturalY = m.yPercent * img.height
          const naturalW = (m.widthPercent || 0.05) * img.width
          const naturalH = (m.heightPercent || 0.05) * img.height
          
          const relativeX = naturalX - cropX
          const relativeY = naturalY - cropY
          
          // Scale to target canvas size
          const scaleX = targetWidth / sourceWidth
          const scaleY = targetHeight / sourceHeight
          
          const mx = relativeX * scaleX
          const my = relativeY * scaleY
          const mw = naturalW * scaleX
          const mh = naturalH * scaleY
          
          // Draw Red Box
          ctx.strokeStyle = '#C00000'
          ctx.lineWidth = 2
          ctx.strokeRect(mx, my, mw, mh)
        })
    }
    
    // Compress to < 200k
    let quality = 0.8
    let dataUrl = canvas.toDataURL('image/jpeg', quality)
    while (dataUrl.length > 200 * 1024 && quality > 0.1) {
      quality -= 0.1
      dataUrl = canvas.toDataURL('image/jpeg', quality)
    }
    
    // Call API
    const res = await request.post('/api/v1/image/recognize-markers', {
      image: dataUrl
    })
    
    if (res.code === 200 || res.data?.code === 200) {
      const resultPayload = (res.data?.data?.result ?? res.data?.result ?? '')
      if (resultPayload) {
         const tryParseJson = (txt) => {
           if (typeof txt !== 'string') return null
           const m = txt.match(/```(?:json)?\s*([\s\S]*?)\s*```/i)
           const candidate = (m && m[1]) ? m[1].trim() : txt.trim()
           if (!candidate) return null
           if (!(candidate.startsWith('{') || candidate.startsWith('['))) return null
           try {
             return JSON.parse(candidate)
           } catch {
             return null
           }
         }

         const normalizeSuggestions = (suggestions, toGlobalBBox) => {
           if (!Array.isArray(suggestions)) return []
           return suggestions
             .map(s => {
               const label = (s && typeof s.label === 'string') ? s.label.trim() : ''
               const kind = (s && typeof s.kind === 'string') ? s.kind.trim() : ''
               const bbox = s && s.bbox && typeof s.bbox === 'object' ? s.bbox : null
               const bb = bbox ? toGlobalBBox(bbox) : null
               if (!label) return null
               return { label, kind, bbox: bb }
             })
             .filter(Boolean)
         }

         const buildSuggestionThumbnail = (bbox) => {
           try {
             if (!bbox || typeof bbox !== 'object') return ''
             const nx = Math.max(0, Math.min(1, Number(bbox.x)))
             const ny = Math.max(0, Math.min(1, Number(bbox.y)))
             const nw = Math.max(0, Math.min(1, Number(bbox.width)))
             const nh = Math.max(0, Math.min(1, Number(bbox.height)))
             if (![nx, ny, nw, nh].every(v => Number.isFinite(v))) return ''
             if (!img || !img.naturalWidth || !img.naturalHeight) return ''

             const sx = Math.max(0, Math.min(img.naturalWidth - 1, Math.round(nx * img.naturalWidth)))
             const sy = Math.max(0, Math.min(img.naturalHeight - 1, Math.round(ny * img.naturalHeight)))
             const sw = Math.max(1, Math.min(img.naturalWidth - sx, Math.round(nw * img.naturalWidth)))
             const sh = Math.max(1, Math.min(img.naturalHeight - sy, Math.round(nh * img.naturalHeight)))

             const size = 72
             const canvas = document.createElement('canvas')
             canvas.width = size
             canvas.height = size
             const ctx = canvas.getContext('2d')
             ctx.drawImage(img, sx, sy, sw, sh, 0, 0, size, size)
             return canvas.toDataURL('image/jpeg', 0.82)
           } catch {
             return ''
           }
         }

         const applyMarkerResult = (num, suggestions) => {
           if (!num || !Array.isArray(suggestions) || suggestions.length === 0) return
           const enrichedSuggestions = suggestions.map(s => {
             if (!s || typeof s !== 'object') return null
             const thumb = s.bbox ? buildSuggestionThumbnail(s.bbox) : ''
             return { ...s, thumbnail: thumb || s.thumbnail || '' }
           }).filter(Boolean)
           if (enrichedSuggestions.length === 0) return

           const first = enrichedSuggestions[0]
           const patch = {
             content: first.label,
             status: 'done',
             suggestions: enrichedSuggestions,
             selectedSuggestionIndex: 0,
             kind: first.kind
           }
           if (first.thumbnail) {
             patch.thumbnail = first.thumbnail
           }

           generationStore.updateMarkerPromptDetails(num, patch)

           const mIndex = el.markers.findIndex(m => m.number === num)
           if (mIndex > -1) {
             const mk = el.markers[mIndex]
             mk.content = first.label
             if (first.thumbnail) {
               mk.thumbnail = first.thumbnail
             }
           }
         }

         const fullW = img.width
         const fullH = img.height
         const toGlobalBBox = (bbox) => {
           const x = Number(bbox?.x)
           const y = Number(bbox?.y)
           const w = Number(bbox?.width)
           const h = Number(bbox?.height)
           if (![x, y, w, h].every(v => Number.isFinite(v))) return null

           let gx = x
           let gy = y
           let gw = w
           let gh = h

           if (isCropped) {
             gx = (cropX + x * sourceWidth) / fullW
             gy = (cropY + y * sourceHeight) / fullH
             gw = (w * sourceWidth) / fullW
             gh = (h * sourceHeight) / fullH
           }

           gx = Math.max(0, Math.min(1, gx))
           gy = Math.max(0, Math.min(1, gy))
           gw = Math.max(0, Math.min(1, gw))
           gh = Math.max(0, Math.min(1, gh))

           if (gx + gw > 1) gw = Math.max(0, 1 - gx)
           if (gy + gh > 1) gh = Math.max(0, 1 - gy)

           return { x: gx, y: gy, width: gw, height: gh }
         }

         const parsed = (resultPayload && typeof resultPayload === 'object')
           ? resultPayload
           : (typeof resultPayload === 'string' ? tryParseJson(resultPayload) : null)
         if (parsed && typeof parsed === 'object') {
           const map = new Map()

           if (Array.isArray(parsed.suggestions)) {
             if (targetMarkerNum) {
               map.set(targetMarkerNum, normalizeSuggestions(parsed.suggestions, toGlobalBBox))
             }
           } else if (Array.isArray(parsed.markers)) {
             parsed.markers.forEach(it => {
               const num = Number(it?.number)
               if (!Number.isFinite(num)) return
               const sug = normalizeSuggestions(it?.suggestions, toGlobalBBox)
               if (sug.length > 0) map.set(num, sug)
             })
           } else {
             Object.keys(parsed).forEach(k => {
               const num = Number(k)
               if (!Number.isFinite(num)) return
               const v = parsed[k]
               const sug = normalizeSuggestions(v?.suggestions, toGlobalBBox)
               if (sug.length > 0) map.set(num, sug)
             })
           }

           if (map.size > 0) {
             for (const [num, suggestions] of map.entries()) {
               if (targetMarkerNum && num !== targetMarkerNum) continue
               if (!targetMarkerNum) {
                 const mp = generationStore.markerPrompts.find(p => p.number === num)
                 if (mp && mp.status === 'done') continue
               }
               applyMarkerResult(num, suggestions)
             }
             return
           }
         }

         const resultText = (typeof resultPayload === 'string') ? resultPayload : ''
         if (!resultText.trim()) return

         // Parse result text line by line to handle multiple markers
         const resultLines = resultText.split('\n')
         
         let hasMatch = false
         
         resultLines.forEach(line => {
             line = line.trim()
             if (!line) return

             let num = null
             let content = ''

             // Try format 1: [标记点X]: content or 标记点X: content or 标记点X content
             let match = line.match(/^\[?标记点\s*(\d+)\]?(?:[:：]|\s+)(.*)/)
             if (match) {
                 num = parseInt(match[1])
                 content = match[2]
             } else {
                 // Try format 2: X. content or X: content or X、content
                 match = line.match(/^(\d+)[\.、:：]\s*(.*)/)
                 if (match) {
                     num = parseInt(match[1])
                     content = match[2]
                 }
             }

             if (num !== null) {
                 hasMatch = true
                 content = content ? content.trim() : ''
                 
                 // 1. If target is specified, ignore any other numbers
                 if (targetMarkerNum && num !== targetMarkerNum) return

                 // 2. If target is NOT specified (batch), ignore markers that are already done
                 // This prevents overwriting manual edits or previous recognitions
                 if (!targetMarkerNum) {
                     const mp = generationStore.markerPrompts.find(p => p.number === num)
                     if (mp && mp.status === 'done') return
                 }

                 generationStore.updateMarkerPrompt(num, content)
                 
                 // Also update element marker data for persistence
                 const mIndex = el.markers.findIndex(m => m.number === num)
                 if (mIndex > -1) {
                     el.markers[mIndex].content = content
                 }
             }
         })
         
         // If no structured match found, but we have result text and a single target, assume the whole text is the content
         if (!hasMatch && targetMarkerNum && resultText.trim()) {
             const content = resultText.trim()
             generationStore.updateMarkerPrompt(targetMarkerNum, content)
             
             const mIndex = el.markers.findIndex(m => m.number === targetMarkerNum)
             if (mIndex > -1) {
                 el.markers[mIndex].content = content
             }
         }
      }
    } else {
       ElMessage.error(res.msg || '识别失败')
    }
    
  } catch (e) {
    console.error(e)
    ElMessage.error('标记识别出错')
  } finally {
    el.recognizingMarkers = false
  }
}

const handleTextMouseDown = (e, el, text) => {
  // Select the parent element if not already
  if (selectedElementId.value !== el.id) {
    selectedElementId.value = el.id
  }
  
  if (isTextMode.value) return 

  isDraggingText = true
  dragTextId = text.id
  dragElId = el.id
  dragStart = { x: e.clientX, y: e.clientY }
  textStart = { x: text.x, y: text.y }
  
  e.stopPropagation()
}

let isResizingText = false
let resizeTextStart = { size: 0 }

const handleTextResizeStart = (e, el, text) => {
  if (selectedElementId.value !== el.id) {
    selectedElementId.value = el.id
  }
  
  isResizingText = true
  dragTextId = text.id
  dragElId = el.id
  resizeStart = { x: e.clientX, y: e.clientY }
  resizeTextStart = { size: text.size || 16 }
  
  e.stopPropagation()
}

const handleTextLayerMouseDown = (e, el) => {
  if (!isTextMode.value) return
  
  // Calculate relative position to the element content box
  // The event target is the interaction layer which fills the element
  const x = e.offsetX
  const y = e.offsetY
  
  // Force opaque color for text
  let color = textColor.value
  if (color.startsWith('rgba')) {
    color = color.replace('rgba', 'rgb').replace(/,\s*[\d\.]+\)$/, ')')
  }

  const newText = {
    id: Date.now(),
    x,
    y,
    xPercent: x / el.width,
    yPercent: y / el.height,
    content: '', // Start empty
    color: color,
    size: textSize.value
  }
  
  if (!el.texts) el.texts = []
  el.texts.push(newText)
  
  // Turn off text mode so the interaction layer disappears and user can edit directly
  isTextMode.value = false

  setTimeout(() => {
    const textEl = document.getElementById(`text-content-${newText.id}`)
    if (textEl) {
        textEl.focus()
        // Ensure cursor is active
        const range = document.createRange()
        const sel = window.getSelection()
        range.selectNodeContents(textEl)
        range.collapse(false)
        sel.removeAllRanges()
        sel.addRange(range)
    }
  }, 50)
}

const updateTextContent = (e, el, text) => {
  const content = e.target.innerText
  text.content = content
  if (!content || !content.trim()) {
     removeText(el, text.id)
  }
}

const removeText = (el, textId) => {
  const idx = el.texts.findIndex(t => t.id === textId)
  if (idx !== -1) el.texts.splice(idx, 1)
}

const handleMaskMouseDown = (e, el) => {
  if (!isBrushMode.value) return
  
  saveMaskState(el)
  
  isDrawingMask = true
  el.hasMask = true
  
  const canvas = maskCanvasRefs.value[el.id]
  if (!canvas) return
  
  const ctx = canvas.getContext('2d')
  
  const rect = canvas.getBoundingClientRect()
  const scaleX = canvas.width / rect.width
  const scaleY = canvas.height / rect.height
  
  const x = (e.clientX - rect.left) * scaleX
  const y = (e.clientY - rect.top) * scaleY
  
  lastMaskPoint = { x, y }
  
  ctx.beginPath()
  ctx.moveTo(x, y)
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.strokeStyle = brushColor.value
  ctx.lineWidth = brushSize.value * scaleX 
  ctx.lineTo(x, y)
  ctx.stroke()
}

const handleMaskMouseMove = (e, el) => {
  if (!isDrawingMask || !isBrushMode.value) return
  
  const canvas = maskCanvasRefs.value[el.id]
  if (!canvas) return
  const ctx = canvas.getContext('2d')
  
  const rect = canvas.getBoundingClientRect()
  const scaleX = canvas.width / rect.width
  const scaleY = canvas.height / rect.height
  
  const x = (e.clientX - rect.left) * scaleX
  const y = (e.clientY - rect.top) * scaleY
  
  ctx.lineTo(x, y)
  ctx.stroke()
  lastMaskPoint = { x, y }
}

const handleMaskMouseUp = () => {
  isDrawingMask = false
}

const clearMask = (el) => {
  saveMaskState(el)
  const canvas = maskCanvasRefs.value[el.id]
  if (canvas) {
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    el.hasMask = false
  }
}

const handleEraseMouseDown = (e, el) => {
  if (!isEraseMode.value) return

  isDrawingErase = true
  el.hasEraseStrokes = true

  const canvas = eraseCanvasRefs.value[el.id]
  if (!canvas) return

  const ctx = canvas.getContext('2d')
  const rect = canvas.getBoundingClientRect()
  const scaleX = canvas.width / rect.width
  const scaleY = canvas.height / rect.height

  const x = (e.clientX - rect.left) * scaleX
  const y = (e.clientY - rect.top) * scaleY

  ctx.beginPath()
  ctx.moveTo(x, y)
  ctx.lineCap = 'round'
  ctx.lineJoin = 'round'
  ctx.globalAlpha = 1
  ctx.strokeStyle = '#1B5FD6'
  ctx.lineWidth = eraseSize.value * scaleX
  ctx.lineTo(x, y)
  ctx.stroke()
}

const handleEraseMouseMove = (e, el) => {
  if (!isDrawingErase || !isEraseMode.value) return

  const canvas = eraseCanvasRefs.value[el.id]
  if (!canvas) return
  const ctx = canvas.getContext('2d')

  const rect = canvas.getBoundingClientRect()
  const scaleX = canvas.width / rect.width
  const scaleY = canvas.height / rect.height

  const x = (e.clientX - rect.left) * scaleX
  const y = (e.clientY - rect.top) * scaleY

  ctx.lineTo(x, y)
  ctx.stroke()
}

const handleEraseMouseUp = (e, el) => {
  isDrawingErase = false
  if (el && el.hasEraseStrokes) updateEraseOutline(el)
}

const updateEraseOutline = (el) => {
  if (!el) return
  const srcCanvas = eraseCanvasRefs.value[el.id]
  const outlineCanvas = eraseOutlineCanvasRefs.value[el.id]
  if (!srcCanvas || !outlineCanvas) return

  const w = srcCanvas.width
  const h = srcCanvas.height
  if (!w || !h) return

  const sctx = srcCanvas.getContext('2d', { willReadFrequently: true })
  const octx = outlineCanvas.getContext('2d')
  if (!sctx || !octx) return

  octx.clearRect(0, 0, w, h)

  const src = sctx.getImageData(0, 0, w, h).data
  const mask = new Uint8Array(w * h)
  for (let i = 0; i < w * h; i++) {
    mask[i] = src[i * 4 + 3] > 0 ? 1 : 0
  }

  const out = octx.createImageData(w, h)
  const outData = out.data
  const thickness = 2
  const alpha = 220

  for (let y = 1; y < h - 1; y++) {
    const row = y * w
    for (let x = 1; x < w - 1; x++) {
      const idx = row + x
      if (!mask[idx]) continue
      if (
        mask[idx - 1] &&
        mask[idx + 1] &&
        mask[idx - w] &&
        mask[idx + w] &&
        mask[idx - w - 1] &&
        mask[idx - w + 1] &&
        mask[idx + w - 1] &&
        mask[idx + w + 1]
      ) {
        continue
      }

      for (let dy = -thickness; dy <= thickness; dy++) {
        const yy = y + dy
        if (yy <= 0 || yy >= h - 1) continue
        for (let dx = -thickness; dx <= thickness; dx++) {
          const xx = x + dx
          if (xx <= 0 || xx >= w - 1) continue
          if ((dx * dx + dy * dy) > (thickness * thickness)) continue
          const o = (yy * w + xx) * 4
          outData[o] = 255
          outData[o + 1] = 255
          outData[o + 2] = 255
          outData[o + 3] = alpha
        }
      }
    }
  }

  octx.putImageData(out, 0, 0)
}

const clearErase = (el) => {
  const canvas = eraseCanvasRefs.value[el.id]
  if (canvas) {
    const ctx = canvas.getContext('2d')
    ctx.clearRect(0, 0, canvas.width, canvas.height)
  }
  const outlineCanvas = eraseOutlineCanvasRefs.value[el.id]
  if (outlineCanvas) {
    const octx = outlineCanvas.getContext('2d')
    octx.clearRect(0, 0, outlineCanvas.width, outlineCanvas.height)
  }
  el.hasEraseStrokes = false
}

const submitErase = async (el) => {
  if (!el || el.type !== 'image' || !el.src) return
  if (!el.hasEraseStrokes) return

  const eraseCanvas = eraseCanvasRefs.value[el.id]
  if (!eraseCanvas) return

  let objectUrl = ''
  try {
    el.eraseProcessing = true

    let promptText = ''
    try {
      promptText = typeof generationStore.getEraseToolPrompt === 'function'
        ? await generationStore.getEraseToolPrompt()
        : ''
    } catch {}
    if (!promptText) promptText = '请根据涂抹标记区域进行擦除，并保持其它内容不变。'

    const loadImg = (url) => new Promise((resolve, reject) => {
      const img = new Image()
      img.crossOrigin = 'anonymous'
      img.onload = () => resolve(img)
      img.onerror = reject
      img.src = url
    })

    let loadUrl = el.src
    if (!loadUrl.startsWith('data:') && !loadUrl.startsWith('blob:')) {
      try {
        const resp = await fetch(loadUrl)
        const blob = await resp.blob()
        objectUrl = URL.createObjectURL(blob)
        loadUrl = objectUrl
      } catch {
        loadUrl = `${config.API_BASE_URL}/api/proxy/image?url=` + encodeURIComponent(el.src)
      }
    }

    const img = await loadImg(loadUrl)
    const w = img.naturalWidth || img.width || eraseCanvas.width || 1024
    const h = img.naturalHeight || img.height || eraseCanvas.height || 1024

    const composed = document.createElement('canvas')
    composed.width = w
    composed.height = h
    const cctx = composed.getContext('2d')
    cctx.drawImage(img, 0, 0, w, h)
    cctx.save()
    cctx.globalAlpha = 0.45
    cctx.drawImage(eraseCanvas, 0, 0, w, h)
    cctx.restore()

    const dataUrl = composed.toDataURL('image/png')
    const file = dataUrlToFile(dataUrl, `erase_${Date.now()}.png`)
    if (!file) throw new Error('擦除参考图生成失败')

    const urls = await generationStore.uploadReferences([file], false, false)
    const refUrl = Array.isArray(urls) && urls[0] ? urls[0] : ''
    if (!refUrl) throw new Error('擦除参考图上传失败')

    let targetModelIdentity = null
    const rawModel = (appStore.siteConfig?.erase_tool_model || '').trim()
    if (rawModel) {
      const m = generationStore.imageModels.find(it => it.model_identity === rawModel || it.model_id === rawModel)
      targetModelIdentity = m ? (m.model_identity || rawModel) : rawModel
    }

    const options = {
      generation_mode: 'image_to_image',
      reference_images: [refUrl],
      use_param_adapt: true,
      ...(w && h ? { width: w, height: h, size: `${w}x${h}` } : {})
    }

    const closingText = '擦除任务已经完成，需要任何修改请告诉我'
    const imageName = el.name ? `${el.name}-擦除` : '擦除结果'

    const submitted = await generationStore.submitImageTask(promptText, options, targetModelIdentity)
    if (submitted?.direct) {
      const img = Array.isArray(submitted.images) ? submitted.images[0] : null
      const finalUrl = img?.url || (img?.b64 ? `data:image/png;base64,${img.b64}` : '')
      if (!finalUrl) throw new Error('未获取到擦除结果图片地址')

      chatStore.appendToolResultMessage({
        image_url: finalUrl,
        image_name: imageName,
        resolution: (w && h) ? `${w}x${h}` : '',
        no_auto_add_to_canvas: true,
        model_identity: targetModelIdentity || ''
      })
      chatStore.appendAssistantMessage(closingText)

      const thumbSize = getMinLongSideSize(el.width, el.height, 600)
      addElement('image', finalUrl, thumbSize.width, thumbSize.height, 'Erased', { nw: w, nh: h })
      clearErase(el)
      ElMessage.success('擦除任务已完成')
      return
    }

    if (submitted?.taskId) {
      const tracked = await chatStore.trackExternalImageTask({
        taskId: submitted.taskId,
        imageName,
        modelIdentity: targetModelIdentity,
        width: w || undefined,
        height: h || undefined,
        resolution: (w && h) ? `${w}x${h}` : '',
        toolResultExtra: { no_auto_add_to_canvas: true },
        closingText
      })

      const finalUrl = tracked?.imageUrl || ''
      if (!finalUrl) throw new Error('未获取到擦除结果图片地址')

      const thumbSize = getMinLongSideSize(el.width, el.height, 600)
      addElement('image', finalUrl, thumbSize.width, thumbSize.height, 'Erased', { nw: w, nh: h })
      clearErase(el)
      ElMessage.success('擦除任务已完成')
      return
    }

    throw new Error('擦除任务提交失败')
  } catch (e) {
    ElMessage.error('擦除失败：' + (e.message || '未知错误'))
  } finally {
    if (objectUrl) {
      try { URL.revokeObjectURL(objectUrl) } catch {}
    }
    el.eraseProcessing = false
  }
}

const handleKeyDown = (e) => {
  if (e.key === 'Escape') {
    if (isBoxDrawing.value) {
      isBoxDrawing.value = false
      drawingElementId.value = null
      drawingElement.value = null
      currentBox.value = null
      window.removeEventListener('mousemove', handleMarkerBoxMouseMove)
      window.removeEventListener('mouseup', handleMarkerBoxMouseUp)
    }
    if (isMarkerMode.value) isMarkerMode.value = false
    return
  }

  // Delete key
  if (e.key === 'Delete') {
    if (selectedElementId.value) {
      removeElement(selectedElementId.value)
    } else if (selectedIds.value.length > 0) {
      removeSelectedElements()
    }
  }

  // Undo: Ctrl+Z
  if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !e.shiftKey) {
     e.preventDefault()
     undo()
  }
  // Redo: Ctrl+Shift+Z or Ctrl+Y
  if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.key === 'z' && e.shiftKey))) {
     e.preventDefault()
     redo()
  }
}

// Also listen to global paste event for better compatibility
const handlePaste = async (e) => {
  if (e.clipboardData && e.clipboardData.files.length > 0) {
    e.preventDefault()
    await handleFiles(e.clipboardData.files)
  }
}

// --- Element Management ---

const coverThumbUploadState = {
  inFlight: new Set(),
  done: new Set()
}

const dataUrlToFile = (dataUrl, filename) => {
  try {
    const arr = String(dataUrl).split(',')
    if (arr.length < 2) return null
    const mimeMatch = arr[0].match(/:(.*?);/)
    const mime = mimeMatch ? mimeMatch[1] : 'image/jpeg'
    const bstr = atob(arr[1])
    let n = bstr.length
    const u8arr = new Uint8Array(n)
    while (n--) u8arr[n] = bstr.charCodeAt(n)
    return new File([u8arr], filename, { type: mime })
  } catch {
    return null
  }
}

const generateSquareThumbDataUrl = async (src, size = 60) => {
  const u = typeof src === 'string' ? src.trim() : ''
  if (!u) return null

  const load = (url) => new Promise((resolve, reject) => {
    const img = new Image()
    img.crossOrigin = 'anonymous'
    img.onload = () => resolve(img)
    img.onerror = reject
    img.src = url
  })

  let objectUrl = ''
  try {
    let loadUrl = u
    if (!u.startsWith('data:') && !u.startsWith('blob:')) {
      try {
        const resp = await fetch(u)
        const blob = await resp.blob()
        objectUrl = URL.createObjectURL(blob)
        loadUrl = objectUrl
      } catch {
        loadUrl = `${config.API_BASE_URL}/api/proxy/image?url=` + encodeURIComponent(u)
      }
    }

    const img = await load(loadUrl)
    const w = img.naturalWidth || img.width || 0
    const h = img.naturalHeight || img.height || 0
    if (!w || !h) return null

    const side = Math.min(w, h)
    const sx = Math.max(0, Math.floor((w - side) / 2))
    const sy = Math.max(0, Math.floor((h - side) / 2))

    const canvas = document.createElement('canvas')
    canvas.width = size
    canvas.height = size
    const ctx = canvas.getContext('2d')
    if (!ctx) return null
    ctx.drawImage(img, sx, sy, side, side, 0, 0, size, size)
    return canvas.toDataURL('image/jpeg', 0.8)
  } catch {
    return null
  } finally {
    if (objectUrl) {
      try { URL.revokeObjectURL(objectUrl) } catch {}
    }
  }
}

const updateConversationCoverThumbFromImage = async (src) => {
  const convId = String(chatStore.currentConversationId || '').trim()
  if (!convId) return
  if (coverThumbUploadState.done.has(convId) || coverThumbUploadState.inFlight.has(convId)) return

  const conv = (chatStore.conversations || []).find((c) => {
    const id = String((c && (c.conversation_id || c.id)) || '').trim()
    return id && id === convId
  })
  if (conv && conv.cover_thumb_url) {
    coverThumbUploadState.done.add(convId)
    return
  }

  coverThumbUploadState.inFlight.add(convId)
  try {
    const dataUrl = await generateSquareThumbDataUrl(src, 60)
    if (!dataUrl) return

    const file = dataUrlToFile(dataUrl, `cover_${convId}.jpg`)
    if (!file) return

    const form = new FormData()
    form.append('files[]', file)
    const up = await request.post('/api/v1/image/upload', form)
    const url = up?.data?.data?.urls?.[0] || ''
    if (!url) return

    await request.post('/api/v1/conversation/update_cover_thumb', {
      conversation_id: convId,
      cover_thumb_url: url
    })

    coverThumbUploadState.done.add(convId)
    chatStore.fetchConversations()
  } catch {}
  finally {
    coverThumbUploadState.inFlight.delete(convId)
  }
}

const addElement = (type, src, width = 300, height = 300, name = '', options = {}) => {
  pushState() // Save state before adding

  // Add to center of viewport
  let cx = 0, cy = 0
  if (containerRef.value) {
    const rect = containerRef.value.getBoundingClientRect()
    // Center of screen in world coords
    cx = (rect.width / 2 - viewport.x) / viewport.scale
    cy = (rect.height / 2 - viewport.y) / viewport.scale
  }
  
  // Base position (centered)
  let x = cx - width / 2
  let y = cy - height / 2

  // Smart positioning: Avoid exact overlap by cascading
  const offset = 30
  let isOverlapping = true
  let attempts = 0
  const maxAttempts = 10

  while (isOverlapping && attempts < maxAttempts) {
    isOverlapping = canvasElements.value.some(el => {
       return Math.abs(el.x - x) < 10 && Math.abs(el.y - y) < 10
    })
    
    if (isOverlapping) {
      x += offset
      y += offset
      attempts++
    }
  }

  // Calculate max zIndex
  const maxZ = canvasElements.value.length > 0 
     ? Math.max(...canvasElements.value.map(e => e.zIndex || 0)) 
     : 0

  const newId = Date.now() + Math.random().toString()
  canvasElements.value.push({
    id: newId,
    type,
    src,
    name: name || (type === 'image' ? 'Image' : 'Video'),
    x,
    y,
    width,
    height,
    zIndex: maxZ + 1,
    hasMask: false,
    hidden: false,
    texts: [],
    naturalWidth: options.nw || width,
    naturalHeight: options.nh || height,
    imageLoading: options.imageLoading === true
  })
  
  // Auto-select the new element to bring it to front (visually and logically)
  selectedElementId.value = newId

  if (type === 'image' && options && options.setConversationCover) {
    updateConversationCoverThumbFromImage(src)
  }
}

const getMinLongSideSize = (width, height, targetLongSide = 600) => {
  let w = Number(width) || 0
  let h = Number(height) || 0
  if (!w || !h) return { width: targetLongSide, height: targetLongSide }

  const currentLongSide = Math.max(w, h)
  if (currentLongSide > 0 && currentLongSide < targetLongSide) {
    const scale = targetLongSide / currentLongSide
    w = Math.max(1, Math.round(w * scale))
    h = Math.max(1, Math.round(h * scale))
  }
  return { width: w, height: h }
}

const handleAddElementFromCanvasNode = (payload) => {
  if (!payload || typeof payload !== 'object') return
  if (payload.type !== 'image' && payload.type !== 'video') return
  if (!payload.src) return

  pushState()
  const newId = Date.now() + Math.random().toString()
  canvasElements.value.push({
    id: newId,
    type: payload.type,
    src: payload.src,
    name: payload.name || (payload.type === 'image' ? 'Image' : 'Video'),
    x: typeof payload.x === 'number' ? payload.x : 0,
    y: typeof payload.y === 'number' ? payload.y : 0,
    width: payload.width || 300,
    height: payload.height || 300,
    zIndex: getNextZIndex(),
    hasMask: false,
    hidden: false,
    texts: [],
    naturalWidth: payload.naturalWidth || payload.width || 300,
    naturalHeight: payload.naturalHeight || payload.height || 300
  })
  selectedElementId.value = newId
}



const findParentArray = (elements, childId) => {
  for (const el of elements) {
    if (el.id === childId) return elements // Found in this array
    if (el.children) {
      const found = findParentArray(el.children, childId)
      if (found) return found
    }
  }
  return null
}

const findPath = (elements, targetId, path = []) => {
  for (const el of elements) {
    if (el.id === targetId) return [...path, el]
    if (el.children) {
      const found = findPath(el.children, targetId, [...path, el])
      if (found) return found
    }
  }
  return null
}

const handleGroup = () => {
  closeContextMenu()
  const selectedEls = getAllSelectedElements()
  if (selectedEls.length < 2) return

  // 1. Calculate Global Positions and Paths
  const elPaths = selectedEls.map(el => {
    const path = findPath(canvasElements.value, el.id)
    return { el, path: path || [] }
  })

  // 2. Find Common Ancestor
  // Path includes the element itself. Ancestors are path.slice(0, -1)
  const ancestorPaths = elPaths.map(x => x.path.slice(0, -1))
  
  let commonAncestorPath = ancestorPaths[0]
  for (let i = 1; i < ancestorPaths.length; i++) {
    const currentPath = ancestorPaths[i]
    // Intersect commonAncestorPath and currentPath
    const newCommon = []
    const len = Math.min(commonAncestorPath.length, currentPath.length)
    for (let j = 0; j < len; j++) {
      if (commonAncestorPath[j].id === currentPath[j].id) {
        newCommon.push(commonAncestorPath[j])
      } else {
        break
      }
    }
    commonAncestorPath = newCommon
  }

  const targetParentArray = commonAncestorPath.length > 0 
    ? commonAncestorPath[commonAncestorPath.length - 1].children 
    : canvasElements.value

  // Calculate Common Ancestor Global Offset
  let ancestorOffsetX = 0
  let ancestorOffsetY = 0
  commonAncestorPath.forEach(p => {
    ancestorOffsetX += p.x
    ancestorOffsetY += p.y
  })

  // 3. Calculate Bounding Box relative to Common Ancestor
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity
  let minZ = Infinity

  elPaths.forEach(({ el, path }) => {
    // Calculate global pos
    let gx = 0, gy = 0
    path.slice(0, -1).forEach(p => { gx += p.x; gy += p.y })
    gx += el.x
    gy += el.y
    
    // Store relative to ancestor for later
    el._relX = gx - ancestorOffsetX
    el._relY = gy - ancestorOffsetY
    
    minX = Math.min(minX, el._relX)
    minY = Math.min(minY, el._relX) // Typo fix: el._relY
    minY = Math.min(minY, el._relY) // Fixed
    maxX = Math.max(maxX, el._relX + el.width)
    maxY = Math.max(maxY, el._relY + el.height)
    minZ = Math.min(minZ, el.zIndex || 0)
  })

  // 4. Create Group
  const group = {
    id: Date.now() + Math.random().toString(),
    type: 'group',
    name: '编组',
    x: minX,
    y: minY,
    width: maxX - minX,
    height: maxY - minY,
    zIndex: minZ,
    children: [],
    expanded: true,
    locked: false,
    hidden: false
  }

  // 5. Reparent
  pushState()

  // Sort by zIndex
  selectedEls.sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0))

  selectedEls.forEach(el => {
    // Remove from old parent
    // Note: findParentArray might be slow if called repeatedly, but safe
    // Since we know the path, we could optimize, but findParentArray is reliable
    // Actually, we must be careful: if we remove an item, findParentArray for next item still works? Yes.
    
    // We can use the path we already found, but indices might shift if we splice from same array.
    // Safest to look up array again or just use findParentArray logic.
    const parentArr = findParentArray(canvasElements.value, el.id)
    if (parentArr) {
       const idx = parentArr.findIndex(e => e.id === el.id)
       if (idx > -1) parentArr.splice(idx, 1)
    }

    el.x = el._relX - group.x
    el.y = el._relY - group.y
    delete el._relX
    delete el._relY

    group.children.push(el)
  })

  targetParentArray.push(group)
  selectedIds.value = [group.id]
}

const handleUngroup = () => {
  closeContextMenu()
  if (selectedIds.value.length !== 1) return
  
  const group = findElementById(canvasElements.value, selectedIds.value[0])
  if (!group || group.type !== 'group') return
  
  const parentArray = findParentArray(canvasElements.value, group.id)
  if (!parentArray) return

  pushState()

  const groupIndex = parentArray.findIndex(el => el.id === group.id)
  if (groupIndex > -1) {
    parentArray.splice(groupIndex, 1)
    
    const newSelectedIds = []
    group.children.forEach(child => {
      // Convert child pos to parent's coordinate space
      // child.x is relative to group
      // group.x is relative to parent
      // so new child.x = group.x + child.x
      child.x += group.x
      child.y += group.y
      
      parentArray.push(child)
      newSelectedIds.push(child.id)
    })
    
    selectedIds.value = newSelectedIds
  }
}

const handleMergeGroup = async () => {
  closeContextMenu()
  if (selectedIds.value.length !== 1) return
  
  const group = findElementById(canvasElements.value, selectedIds.value[0])
  if (!group || group.type !== 'group') return

  // Create offscreen canvas
  const canvas = document.createElement('canvas')
  const scale = 2 // Higher resolution for better quality
  canvas.width = group.width * scale
  canvas.height = group.height * scale
  const ctx = canvas.getContext('2d')
  ctx.scale(scale, scale)
  
  const loadImage = (src) => {
    return new Promise((resolve, reject) => {
      const img = new Image()
      img.crossOrigin = 'Anonymous'
      img.onload = () => resolve(img)
      img.onerror = reject
      // Add timestamp to bypass cache for non-data/blob URLs
      if (src.startsWith('http')) {
        img.src = src + (src.includes('?') ? '&' : '?') + 't=' + new Date().getTime()
      } else {
        img.src = src
      }
    })
  }

  const drawElement = async (ctx, element, x, y) => {
    if (element.hidden) return
    
    if (element.type === 'group') {
        const children = [...element.children].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0))
        for (const child of children) {
            await drawElement(ctx, child, x + element.x, y + element.y)
        }
        return
    }
    
    const absX = x + element.x
    const absY = y + element.y
    const w = element.width
    const h = element.height

    if (element.type === 'image') {
         try {
             // Always reload image with crossOrigin to avoid tainted canvas
             const img = await loadImage(element.src)
             ctx.drawImage(img, absX, absY, w, h)
         } catch (e) {
             console.warn('Failed to draw image', element.id, e)
         }
    } else if (element.type === 'video') {

         const domId = 'element-' + element.id
         const domEl = document.getElementById(domId)
         const videoEl = domEl ? domEl.querySelector('video') : null
         if (videoEl) {
             try {
                ctx.drawImage(videoEl, absX, absY, w, h)
             } catch (e) {
                console.warn('Failed to draw video', e)
             }
         }
    }
    
    // Draw texts
    if (element.texts) {
        for (const t of element.texts) {
             ctx.font = `${t.size || 16}px sans-serif`
             ctx.fillStyle = t.color || '#000'
             ctx.textBaseline = 'top'
             ctx.fillText(t.content || '', absX + t.x, absY + t.y)
        }
    }
  }

  try {
      const sortedChildren = [...group.children].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0))
      for (const child of sortedChildren) {
          await drawElement(ctx, child, 0, 0)
      }

      const dataURL = canvas.toDataURL('image/png')
      
      const parentArr = findParentArray(canvasElements.value, group.id)
      if (parentArr) {
          const idx = parentArr.findIndex(e => e.id === group.id)
          if (idx > -1) {
              pushState()
              
              const newImg = {
                  id: Date.now() + Math.random().toString(),
                  type: 'image',
                  name: '合并图层',
                  x: group.x,
                  y: group.y,
                  width: group.width,
                  height: group.height,
                  zIndex: group.zIndex,
                  src: dataURL,
                  texts: [],
                  expanded: false,
                  locked: false,
                  hidden: false
              }
              
              parentArr.splice(idx, 1, newImg)
              selectedIds.value = [newImg.id]
              ElMessage.success('编组已合并')
          }
      }
  } catch (e) {
      console.error(e)
      ElMessage.error('合并失败: ' + e.message)
  }
}


const handleToggleLock = (id) => {
  const el = findElementById(canvasElements.value, id)
  if (el) el.locked = !el.locked
}

const handleToggleVisibility = (id) => {
  const el = findElementById(canvasElements.value, id)
  if (el) {
    el.hidden = !el.hidden
    if (el.hidden && hoveredElementId.value === id) hoveredElementId.value = null
  }
}

const handleRename = (id, newName) => {
  const el = findElementById(canvasElements.value, id)
  if (el) el.name = newName
}

const handleToggleExpand = (id) => {
  const el = findElementById(canvasElements.value, id)
  if (el) el.expanded = !el.expanded
}

const handleMoveLayer = (dragId, targetId) => {
  if (dragId === targetId) return

  // 1. Find Dragged Element
  const dragPath = findPath(canvasElements.value, dragId)
  if (!dragPath || dragPath.length === 0) return
  const dragEl = dragPath[dragPath.length - 1]

  // 2. Check Validity (Circular Dependency)
  if (targetId) {
    const targetPath = findPath(canvasElements.value, targetId)
    if (targetPath && targetPath.some(node => node.id === dragId)) {
      ElMessage.warning('不能将编组移动到其子图层中')
      return
    }
  }

  // 3. Calculate Global Position of Drag Element
  let dragGlobalX = 0
  let dragGlobalY = 0
  dragPath.forEach(node => {
    dragGlobalX += node.x
    dragGlobalY += node.y
  })

  // 4. Identify Target Group and Target Global Offset
  let targetList = canvasElements.value
  let targetGroup = null
  let targetGlobalX = 0
  let targetGlobalY = 0

  if (targetId) {
    const targetPath = findPath(canvasElements.value, targetId)
    if (!targetPath) return
    targetGroup = targetPath[targetPath.length - 1]
    
    // Ensure target is a group
    if (targetGroup.type !== 'group') return

    if (!targetGroup.children) targetGroup.children = []
    targetList = targetGroup.children

    targetPath.forEach(node => {
      targetGlobalX += node.x
      targetGlobalY += node.y
    })
  }

  // 5. Remove from Old Parent
  const oldParentList = findParentArray(canvasElements.value, dragId)
  if (!oldParentList) return

  const idx = oldParentList.findIndex(e => e.id === dragId)
  if (idx === -1) return

  pushState() // Save history before modification

  oldParentList.splice(idx, 1)

  // 6. Update Position relative to New Parent
  dragEl.x = dragGlobalX - targetGlobalX
  dragEl.y = dragGlobalY - targetGlobalY

  // 7. Add to New Parent
  targetList.push(dragEl)

  // 8. Post-Move Updates
  // Expand target group
  if (targetGroup) {
    targetGroup.expanded = true
    updateGroupBounds(targetGroup)
  }

  // Update old parent group bounds if needed
  if (dragPath.length > 1) {
    const oldParentGroup = dragPath[dragPath.length - 2]
    updateGroupBounds(oldParentGroup)
  }
}

// Undo/Redo System
const undoStack = ref([])
const redoStack = ref([])
const maxHistory = 50

const pushState = () => {
  const state = JSON.parse(JSON.stringify(canvasElements.value))
  undoStack.value.push(state)
  if (undoStack.value.length > maxHistory) undoStack.value.shift()
  redoStack.value = []
}

const undo = () => {
  if (undoStack.value.length === 0) return
  const current = JSON.parse(JSON.stringify(canvasElements.value))
  redoStack.value.push(current)
  
  const prev = undoStack.value.pop()
  canvasElements.value = prev
  selectedIds.value = []
}

const redo = () => {
  if (redoStack.value.length === 0) return
  const current = JSON.parse(JSON.stringify(canvasElements.value))
  undoStack.value.push(current)
  
  const next = redoStack.value.pop()
  canvasElements.value = next
  selectedIds.value = []
}

// Temporary state for drag/resize undo
let tempStateBeforeDrag = null



const checkAndRecoverTasks = () => {
  canvasElements.value.forEach(el => {
    if (el.type === 'video-generator' && el.loading && el.taskId) {
      recoverTask(el)
    }
  })
}

const recoverTask = async (el) => {
  if (el._recovering) return
  el._recovering = true
  
  try {
    const url = await generationStore.recoverVideoTask(el.taskId, (progress) => {
        el.progress = progress
    })
    if (url) {
        el.generatedVideoUrl = url
        el.loading = false
        el.taskId = null
        el.progress = 0
        ElMessage.success('视频生成成功')
        if (chatStore.currentConversationId) {
           chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
        }
    }
  } catch (e) {
    // Keep loading if it's just a timeout or network error that might resolve?
    // But for now, we stop loading on error.
    el.loading = false
    el.progress = 0
    el.error = e.message || '恢复任务失败'
    // Don't clear taskId so user might retry (though we need UI for that)
    console.error('Task recovery failed', e)
    if (chatStore.currentConversationId) {
       chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
    }
  } finally {
    delete el._recovering
  }
}

const handleGenerateVideo = async (el) => {
    if (!el.prompt || !el.prompt.trim()) {
        ElMessage.warning('请输入视频描述')
        return
    }
    
    el.loading = true
    el.progress = 0
    el.error = null
    try {
        const modelObj = getModelByElement(el)
        const modelIdentity = modelObj ? modelObj.model_identity : el.videoSettings.model

        const videoUrl = await generationStore.generateVideo({
            prompt: el.prompt,
            model: modelIdentity,
            aspectRatio: el.videoSettings.aspectRatio,
            duration: el.videoSettings.duration,
            resolution: el.videoSettings.resolution,
            referenceMode: el.videoSettings.referenceMode,
            firstFrame: el.videoSettings.firstFrame,
            lastFrame: el.videoSettings.lastFrame,
            referenceImages: el.videoSettings.referenceImages,
            referenceVideo: el.videoSettings.referenceVideo
        }, (taskId) => {
            el.taskId = taskId
            if (chatStore.currentConversationId) {
                chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
            }
        }, (progress) => {
            el.progress = progress
        })
        
        if (videoUrl) {
             el.generatedVideoUrl = videoUrl
             el.taskId = null // Clear task id on success
             el.progress = 0
             el.error = null
             ElMessage.success('视频生成成功')
        }
    } catch (e) {
        console.error(e)
        el.error = e.message || '生成失败'
        el.taskId = null
    } finally {
        el.loading = false
        el.progress = 0
        if (chatStore.currentConversationId) {
            chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
        }
    }
}

const moveVideoToCanvas = (el) => {
    if (!el.generatedVideoUrl) return
    
    const tempVideo = document.createElement('video')
    tempVideo.preload = 'metadata'
    tempVideo.onloadedmetadata = () => {
        let w = tempVideo.videoWidth
        let h = tempVideo.videoHeight
        
        // Scale down if too large, keeping aspect ratio
        const max = 512
        if (w > max || h > max) {
            const scale = Math.min(max / w, max / h)
            w = Math.round(w * scale)
            h = Math.round(h * scale)
        }
        
        // Ensure min size
        w = Math.max(w, 100)
        h = Math.max(h, 100)

        addElement(
            'video',
            el.generatedVideoUrl,
            w,
            h,
            'Generated Video',
            { nw: tempVideo.videoWidth, nh: tempVideo.videoHeight }
        )
        
        // Clear the video from the card after moving
        el.generatedVideoUrl = null
        
        ElMessage.success('已移入画布')
    }

    tempVideo.onerror = () => {
        // Fallback
        addElement(
            'video',
            el.generatedVideoUrl,
            512,
            512,
            'Generated Video',
            { nw: 512, nh: 512 }
        )
        el.generatedVideoUrl = null
        ElMessage.success('已移入画布')
    }
    
    tempVideo.src = el.generatedVideoUrl
}

const videoUploadInput = ref(null)
const currentUploadTarget = ref({ el: null, type: null })

const triggerVideoUpload = (el, type) => {
    if (!videoUploadInput.value) return
    
    currentUploadTarget.value = { el, type }
    
    // Set accept type based on upload type
    if (type === 'referenceVideo') {
        videoUploadInput.value.accept = 'video/*'
    } else {
        videoUploadInput.value.accept = 'image/*'
    }
    
    if (type === 'referenceImages') {
         videoUploadInput.value.multiple = true
    } else {
         videoUploadInput.value.multiple = false
    }

    videoUploadInput.value.value = '' // reset
    videoUploadInput.value.click()
}

const handleVideoUpload = async (e) => {
    const files = e.target.files
    if (!files || files.length === 0) return
    
    const { el, type } = currentUploadTarget.value
    if (!el) return

    const formData = new FormData()
    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i])
    }

    try {
        const token = localStorage.getItem('token')
        const res = await axios.post(`${config.API_BASE_URL}/api/v1/image/upload`, formData, {
            headers: { 
                'Authorization': token
            }
        })

        if (res.data?.code === 200) {
            const urls = res.data.data
            if (urls && urls.length > 0) {
                if (type === 'referenceImages') {
                    el.videoSettings.referenceImages = [...el.videoSettings.referenceImages, ...urls]
                } else if (type === 'firstFrame') {
                    el.videoSettings.firstFrame = urls[0]
                } else if (type === 'lastFrame') {
                    el.videoSettings.lastFrame = urls[0]
                } else if (type === 'referenceVideo') {
                    el.videoSettings.referenceVideo = urls[0]
                }
                ElMessage.success('上传成功')
            }
        } else {
            ElMessage.error(res.data?.msg || '上传失败')
        }
    } catch (err) {
        console.error(err)
        ElMessage.error('上传出错')
    } finally {
        currentUploadTarget.value = { el: null, type: null }
    }
}

const getModelByElement = (el) => {
    if (!el || !el.videoSettings?.model) return null
    return generationStore.videoModels.find(m => (m.name === el.videoSettings.model || m.model_identity === el.videoSettings.model))
}

const getSettingsLabel = (el) => {
    const model = getModelByElement(el)
    if (!model) return '配置'

    const parts = []
    const config = model.config || {}
    
    // Check if model supports aspect ratio
    if (config.aspect_ratios && config.aspect_ratios.length > 0) {
        parts.push(el.videoSettings?.aspectRatio || '16:9')
    }

    // Check if model supports duration
    if (config.durations && config.durations.length > 0) {
        parts.push(el.videoSettings?.duration || '5s')
    }

    // Check if model supports resolution
    if (config.resolutions && config.resolutions.length > 0) {
        parts.push(el.videoSettings?.resolution || '720P')
    }
    
    if (parts.length === 0) return '配置'

    return parts.join(' · ')
}

const calculateVideoPoints = (el) => {
    const model = getModelByElement(el)
    if (!model) return 0
    
    let total = parseFloat(model.cost_per_request) || 0
    
    const config = model.config || {}
    
    const getPrice = (list, val) => {
        if (!list || !Array.isArray(list)) return 0
        const item = list.find(i => i.value === val)
        return item ? (parseFloat(item.price) || 0) : 0
    }
    
    total += getPrice(config.aspect_ratios, el.videoSettings.aspectRatio)
    total += getPrice(config.durations, el.videoSettings.duration)
    total += getPrice(config.resolutions, el.videoSettings.resolution)
    
    return total
}

const isModeEnabled = (el, mode) => {
    const model = getModelByElement(el)
    if (!model) return false
    
    // Check fields directly from model object
    if (mode === 'first_last') {
        return model.enable_first_frame == 1 || model.enable_first_last_frame == 1
    }
    if (mode === 'multi') {
        return model.enable_multi_image_ref == 1
    }
    if (mode === 'video') {
        return model.enable_video_ref == 1
    }
    return false
}

const getDefaultReferenceMode = (model) => {
    if (!model) return null
    if (model.enable_first_frame == 1 || model.enable_first_last_frame == 1) return 'first_last'
    if (model.enable_multi_image_ref == 1) return 'multi'
    if (model.enable_video_ref == 1) return 'video'
    return null
}

const handleVideoModelChange = (cmd, el) => {
    const model = generationStore.videoModels.find(m => (m.name === cmd || m.model_identity === cmd))
    if (model) {
        el.videoSettings.model = cmd
        
        // Reset to first option for each config
        const config = model.config || {}
        
        if (config.aspect_ratios && Array.isArray(config.aspect_ratios) && config.aspect_ratios.length > 0) {
            el.videoSettings.aspectRatio = config.aspect_ratios[0].value
        }
        
        if (config.durations && Array.isArray(config.durations) && config.durations.length > 0) {
            el.videoSettings.duration = config.durations[0].value
        }
        
        if (config.resolutions && Array.isArray(config.resolutions) && config.resolutions.length > 0) {
            el.videoSettings.resolution = config.resolutions[0].value
        }
        
        // Reset reference mode
        el.videoSettings.referenceMode = getDefaultReferenceMode(model)
    }
}

const removeElement = (id) => {
  const idx = canvasElements.value.findIndex(e => e.id === id)
  if (idx !== -1) {
    pushState() // Save state before removing

    const el = canvasElements.value[idx]

    // Mark as processed/deleted so it doesn't reappear
    if (el.src) processedUrls.value.add(el.src)
    
    // Try to extract message ID
    if (typeof el.id === 'string') {
        let msgId = null
        if (el.id.startsWith('Video-')) msgId = el.id.substring(6)
        else if (el.id.startsWith('Image-')) msgId = el.id.substring(6)
        
        if (msgId) processedMsgIds.value.add(msgId)
    }
    saveProcessedIds()

    canvasElements.value.splice(idx, 1)
    if (selectedElementId.value === id) selectedElementId.value = null
    
    // Force immediate save to ensure state is persisted before any potential page reload
    if (chatStore.currentConversationId) {
        // Cancel any pending debounced save
        if (saveTimer) {
            clearTimeout(saveTimer)
            saveTimer = null
        }
        chatStore.saveCanvasState(chatStore.currentConversationId, canvasElements.value)
    }
  }
}

const removeElementFromMenu = (id) => {
  closeContextMenu()
  if (!id) return
  removeElement(id)
}

const handleClearCanvas = () => {
  if (!canvasElements.value || canvasElements.value.length === 0) return

  ElMessageBox.confirm(
    '确定要清空画布吗？此操作不可撤销。',
    '清空确认',
    {
      confirmButtonText: '清空',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(() => {
      canvasElements.value.forEach(el => {
        if (el.src) processedUrls.value.add(el.src)
        if (typeof el.id === 'string') {
          let msgId = null
          if (el.id.startsWith('Video-')) msgId = el.id.substring(6)
          else if (el.id.startsWith('Image-')) msgId = el.id.substring(6)
          if (msgId) processedMsgIds.value.add(msgId)
        }
      })
      saveProcessedIds()

      canvasElements.value = []
      resetView()

      if (chatStore.currentConversationId) {
        if (saveTimer) {
          clearTimeout(saveTimer)
          saveTimer = null
        }
        chatStore.saveCanvasState(chatStore.currentConversationId, [])
      }
    })
    .catch(() => {})
}

const handleArrangeCanvas = async () => {
  const els = Array.isArray(canvasElements.value) ? canvasElements.value : []
  const arrangeEls = els.filter(el => !el?.hidden)
  if (arrangeEls.length === 0) return
  pushState()
  selectedIds.value = []
  await nextTick()

  resetZoom()

  const rect = containerRef.value?.getBoundingClientRect?.()
  const viewportW = Math.max(1, rect?.width || 1)

  const scale = Math.max(1e-6, Number(viewport.scale) || 1)
  const paddingPx = 40
  const gapPx = 16
  const topSafeAreaPx = 70
  const padding = paddingPx / scale
  const gap = gapPx / scale
  const topSafeArea = topSafeAreaPx / scale
  const targetWidthPx = 300
  const targetWidth = targetWidthPx / scale

  const visibleWorldLeft = (-viewport.x) / scale
  const visibleWorldTop = (-viewport.y) / scale
  const availableW = viewportW / scale - padding * 2

  const safeW = Math.max(1e-6, availableW)

  const applyScale = (node, s) => {
    if (!node || !Number.isFinite(s) || s <= 0) return
    const w = Number(node.width) || 0
    const h = Number(node.height) || 0
    node.width = Math.max(1, w * s)
    node.height = Math.max(1, h * s)
    if (node.type === 'group' && Array.isArray(node.children)) {
      for (const child of node.children) {
        child.x = (Number(child.x) || 0) * s
        child.y = (Number(child.y) || 0) * s
        applyScale(child, s)
      }
    }
  }

  for (const el of arrangeEls) {
    const w = Number(el?.width) || 0
    if (w <= 0) continue
    const s = targetWidth / w
    applyScale(el, s)
    el.width = Math.max(1, targetWidth)
  }

  const startX = visibleWorldLeft + padding
  const startY = visibleWorldTop + padding + topSafeArea

  const colCount = Math.max(
    1,
    Math.min(arrangeEls.length, Math.floor((safeW + gap) / (targetWidth + gap)))
  )
  const colHeights = Array.from({ length: colCount }, () => startY)

  for (const el of arrangeEls) {
    const w = Number(el?.width) || 0
    const h = Number(el?.height) || 0
    if (w <= 0 || h <= 0) continue

    let targetCol = 0
    let minH = colHeights[0]
    for (let i = 1; i < colHeights.length; i++) {
      const ch = colHeights[i]
      if (ch < minH) {
        minH = ch
        targetCol = i
      }
    }

    el.x = startX + targetCol * (targetWidth + gap)
    el.y = colHeights[targetCol]
    colHeights[targetCol] += h + gap
  }
}

// --- Drop & File Handling ---

const handleDrop = async (e) => {
  const dt = e.dataTransfer
  const url = dt.getData('text/uri-list') || dt.getData('text/plain')
  
  if (url) {
    // Basic validation to filter out plain text
    if (!url.match(/^https?:\/\//i) && !url.match(/^blob:/i) && !url.match(/^data:image\//i)) {
        return
    }

    // Determine type by extension or default to image
    const img = new Image()
    img.onload = () => {
        let w = img.width
        let h = img.height
        if (w > 500) {
             const r = 500 / w
             w = 500
             h *= r
        }
        addElement('image', url, w, h, 'Dropped Image', { nw: img.width, nh: img.height })
    }
    img.onerror = () => {
        // Silent failure for non-image URLs to prevent empty elements
        console.warn('Dropped content could not be loaded as image')
    }
    img.src = url
    return
  }
  
  const files = dt.files
  if (files && files.length) {
    await handleFiles(files)
  }
}

const handleFileSelect = async (file) => {
  // Element plus upload 'on-change' passes a file object wrapper
  if (file.raw) await handleFiles([file.raw])
}

const handleFiles = async (files) => {
  const fileArray = Array.from(files)
  if (fileArray.length > 10) {
    ElMessage.warning('一次最多只能上传10张图片，已自动截取前10张')
    fileArray.splice(10)
  }

  for (const file of fileArray) {
    if (file.type.startsWith('image/')) {
      try {
        // Upload but don't add to global reference images
        const urls = await generationStore.uploadReferences([file], false, false)
        const useUrl = Array.isArray(urls) && urls.length ? urls[0] : ''
        const img = new Image()
        img.onload = () => {
          const nw = img.width
          const nh = img.height
          let w = img.width
          let h = img.height
          if (w > 500) {
            const r = 500 / w
            w = 500
            h *= r
          }
          addElement('image', useUrl || URL.createObjectURL(file), w, h, file.name, { nw, nh })
        }
        img.onerror = () => {
          addElement('image', useUrl || URL.createObjectURL(file), 300, 300, file.name)
        }
        img.src = useUrl || URL.createObjectURL(file)
      } catch (e) {
        const fallbackUrl = URL.createObjectURL(file)
        addElement('image', fallbackUrl, 300, 300, file.name)
      }
    } else if (file.type.startsWith('video/')) {
       const url = URL.createObjectURL(file)
       
       const tempVideo = document.createElement('video')
       tempVideo.onloadedmetadata = () => {
           let w = tempVideo.videoWidth
           let h = tempVideo.videoHeight
           const max = 512
           
           if (w > max || h > max) {
               const scale = Math.min(max / w, max / h)
               w = Math.round(w * scale)
               h = Math.round(h * scale)
           }
           
           // Ensure min size
           w = Math.max(w, 100)
           h = Math.max(h, 100)
           
           addElement('video', url, w, h, file.name, { nw: tempVideo.videoWidth, nh: tempVideo.videoHeight })
       }
       tempVideo.onerror = () => {
           addElement('video', url, 400, 300, file.name)
       }
       tempVideo.src = url
    }
  }
}

// --- Context Menu Actions ---

const triggerUpload = async () => {
  closeContextMenu()
  if (menuUploadInput.value) menuUploadInput.value.click()
}

const handleMenuUpload = async (e) => {
  if (e.target.files && e.target.files.length) {
    await handleFiles(e.target.files)
  }
  e.target.value = ''
}

const pasteFromMenu = async () => {
  closeContextMenu()
  try {
     const items = await navigator.clipboard.read()
     let found = false
     for (const item of items) {
        if (item.types.includes('image/png') || item.types.includes('image/jpeg')) {
           const blob = await item.getType(item.types.find(t => t.startsWith('image/')))
           const file = new File([blob], "Pasted Image", { type: blob.type })
           await handleFiles([file])
           found = true
        }
     }
     if (!found) ElMessage.info('剪贴板中没有图片')
  } catch (e) {
     ElMessage.error('无法访问剪贴板，请确保已授予权限或使用 Ctrl+V')
  }
}

const removeBackground = async () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || !el.src) return
  
  try {
    // Set loading state on the element
    el.matting = true
    
    const result = await generationStore.removeBackground(el.src)
    if (result && result.images && result.images.length > 0) {
      const newImgUrl = result.images[0].url
      
      // Load the new image to get its dimensions
      const img = new Image()
      img.onload = () => {
        const size = getMinLongSideSize(el.width, el.height, 600)
        addElement(
          'image',
          newImgUrl,
          size.width,
          size.height,
          'Background Removed',
          { nw: img.width, nh: img.height }
        )
        ElMessage.success('背景去除成功')
        el.matting = false // Clear loading after adding new element
      }
      img.onerror = () => {
        el.matting = false
        ElMessage.error('结果图片加载失败')
      }
      img.src = newImgUrl
    } else {
      el.matting = false
      ElMessage.error('背景去除失败：未返回图片')
    }
  } catch (e) {
    el.matting = false
    ElMessage.error('背景去除失败：' + (e.message || '未知错误'))
  }
}

const extractTextFromImage = async () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || !el.src) return
  
  try {
    el.ocrLoading = true
    el.ocrTexts = [] // Clear previous results
    
    const ocrModel = appStore.siteConfig?.ocr_model || null
    const texts = await generationStore.extractText(el.src, ocrModel)
    if (texts && Array.isArray(texts)) {
      el.ocrTexts = texts
      // Use configured Text Edit model
      const textEditModel = appStore.siteConfig?.text_edit_model
      el.ocrTargetModel = textEditModel || generationStore.selectedImageModel || (generationStore.imageModels[0]?.model_identity)
      if (texts.length === 0) {
        ElMessage.info('未识别到文字')
      }
    } else {
      ElMessage.error('文字识别失败')
    }
  } catch (e) {
    ElMessage.error('文字识别失败：' + (e.message || '未知错误'))
  } finally {
    el.ocrLoading = false
  }
}



const submitOcrEdit = async (el) => {
  if (!el.ocrTexts || el.ocrTexts.length === 0) return

  // Find modifications
  const modifications = el.ocrTexts.filter(t => t.old !== t.new && t.new.trim() !== '')
  if (modifications.length === 0) {
    ElMessage.info('未做任何修改')
    el.ocrTexts = []
    return
  }

  let pendingMsgId = ''
  try {
    el.ocrProcessing = true
    
    // Adjust resolution: if long side < 2048 (2k), scale up proportionally; otherwise keep original
    let targetWidth = Math.round(el.naturalWidth || el.width)
    let targetHeight = Math.round(el.naturalHeight || el.height)
    const targetLongSide = 2048
    const currentLongSide = Math.max(targetWidth, targetHeight)
    
    if (currentLongSide < targetLongSide) {
      const scale = targetLongSide / currentLongSide
      targetWidth = Math.ceil(targetWidth * scale)
      targetHeight = Math.ceil(targetHeight * scale)
    }

    const configuredTextEditModel = String(appStore.siteConfig?.text_edit_model || '').trim()
    const targetModelIdentity = (
      configuredTextEditModel ||
      String(el.ocrTargetModel || '').trim() ||
      String(generationStore.selectedImageModel || '').trim()
    )
    el.ocrTargetModel = targetModelIdentity
    let targetModelName = '图片模型'
    if (targetModelIdentity) {
      const mdl = generationStore.imageModels.find(it => it.model_identity === targetModelIdentity)
      targetModelName = (mdl && (mdl.name || mdl.model_identity)) ? (mdl.name || mdl.model_identity) : targetModelIdentity
    }

    const imageName = el.name ? `${el.name}-文字修改` : '文字修改'
    pendingMsgId = 'msg_text_edit_' + Date.now()
    const resolution = (targetWidth && targetHeight) ? `${targetWidth}x${targetHeight}` : ''

    const buildToolSummary = (toolResult) => {
      const name = (toolResult?.image_name || '').trim() || '生成图像'
      const type = toolResult?.video_url ? '视频' : '图片'
      const res = (toolResult?.resolution || '').trim()
      const model = (toolResult?.model_name || toolResult?.model_identity || '').toString().trim()
      const lines = [`名称：${name}`, `类型：${type}`]
      if (res) lines.push(`分辨率：${res}`)
      if (model) lines.push(`模型：${model}`)
      return lines.join('\n')
    }

    chatStore.messages.push({
      id: pendingMsgId,
      role: 'assistant',
      content: '',
      timestamp: Date.now(),
      pendingTool: {
        name: 'image_generate',
        modelName: targetModelName,
        width: targetWidth,
        height: targetHeight,
        imageName,
        loading: true,
        taskId: pendingMsgId,
        resolution,
        isVideo: false,
        statusText: '生成中…',
        progress: null
      }
    })
    chatStore.streamTicker++

    const options = {
      ocr_modifications: modifications, // Pass raw modifications to backend for prompt splicing
      width: targetWidth,
      height: targetHeight,
      reference_images: [el.src],
      generation_mode: 'image_to_image',
    }

    const submitted = await generationStore.submitImageTask('', options, '')

    let finalUrl = ''
    if (submitted?.direct) {
      const img = Array.isArray(submitted.images) ? submitted.images[0] : null
      finalUrl = img?.url || (img?.b64 ? `data:image/png;base64,${img.b64}` : '')
    } else if (submitted?.taskId) {
      const taskId = String(submitted.taskId)
      const pendingMsg = chatStore.messages.find(m => String(m.id) === String(pendingMsgId))
      if (pendingMsg?.pendingTool) {
        pendingMsg.pendingTool.taskId = taskId
        pendingMsg.pendingTool.statusText = '队列中…'
        pendingMsg.pendingTool.progress = null
        chatStore.streamTicker++
      }

      const result = await generationStore.waitForTask(taskId, (p) => {
        if (!pendingMsg?.pendingTool) return
        const st = p && typeof p === 'object' ? (p.status || '') : ''
        const prog = p && typeof p === 'object' ? p.progress : null
        pendingMsg.pendingTool.progress = (typeof prog === 'number' ? prog : null)
        if (st === 'queued') {
          pendingMsg.pendingTool.statusText = '队列中…'
        } else if (st === 'processing') {
          if (typeof prog === 'number' && isFinite(prog)) {
            const pct = Math.max(0, Math.min(100, Math.round(prog)))
            pendingMsg.pendingTool.statusText = `生成中 ${pct}%…`
          } else {
            pendingMsg.pendingTool.statusText = '生成中…'
          }
        }
        chatStore.streamTicker++
      })

      const img = (result && Array.isArray(result.images)) ? result.images[0] : null
      finalUrl = img?.url || (img?.b64 ? `data:image/png;base64,${img.b64}` : '')
    }

    if (finalUrl) {
      // Load the image to get its actual natural resolution
      const img = new Image()
      await new Promise((resolve, reject) => {
        img.onload = resolve
        img.onerror = () => reject(new Error('结果图片加载失败'))
        img.src = finalUrl
      })

      const pendingMsg = chatStore.messages.find(m => String(m.id) === String(pendingMsgId))
      if (pendingMsg) {
        pendingMsg.pendingTool = null
        pendingMsg.toolResult = {
          image_url: finalUrl,
          image_name: imageName,
          resolution,
          no_auto_add_to_canvas: true,
          model_identity: targetModelIdentity || '',
          model_name: targetModelName
        }
        const summary = buildToolSummary(pendingMsg.toolResult)
        if (summary) pendingMsg.content = summary
        chatStore.streamTicker++
      }

      const newId = Date.now().toString()
      const size = getMinLongSideSize(el.width, el.height, 600)
      canvasElements.value.push({
        id: newId,
        type: 'image',
        src: finalUrl,
        x: el.x + 50, // Offset a bit
        y: el.y + 50,
        width: size.width,
        height: size.height,
        naturalWidth: img.width,
        naturalHeight: img.height,
        zIndex: getNextZIndex()
      })
      
      // Auto-select the new image
      selectedElementId.value = newId
      
      el.ocrTexts = [] // Close panel
      ElMessage.success('修改成功，已生成新图片')
    } else {
      throw new Error('未获取到生成的图片地址')
    }
  } catch (e) {
    const pendingMsg = pendingMsgId ? chatStore.messages.find(m => String(m.id) === String(pendingMsgId)) : null
    if (pendingMsg && pendingMsg.pendingTool) {
      pendingMsg.pendingTool = null
      pendingMsg.content = '文字修改失败'
      chatStore.streamTicker++
    }
    ElMessage.error('图片生成失败：' + (e.message || '未知错误'))
  } finally {
    el.ocrProcessing = false
  }
}

const showWatermarkPanel = () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || !el.src) return
  
  el.showWatermarkPanel = true
  // el.watermarkModel = generationStore.selectedImageModel || (generationStore.imageModels[0]?.model_identity)
}

const submitWatermarkRemoval = async (el) => {
  // if (!el.watermarkModel) {
  //   ElMessage.warning('请选择生图模型')
  //   return
  // }

  try {
    el.watermarkProcessing = true
    
    // Adjust resolution: if long side < 2048 (2k), scale up proportionally; otherwise keep original
    let targetWidth = Math.round(el.naturalWidth || el.width)
    let targetHeight = Math.round(el.naturalHeight || el.height)
    const targetLongSide = 2048
    const currentLongSide = Math.max(targetWidth, targetHeight)
    
    if (currentLongSide < targetLongSide) {
      const scale = targetLongSide / currentLongSide
      targetWidth = Math.ceil(targetWidth * scale)
      targetHeight = Math.ceil(targetHeight * scale)
    }

    const options = {
      width: targetWidth,
      height: targetHeight,
      reference_images: [el.src],
      generation_mode: 'image_to_image',
      is_watermark_removal: true
    }

    const result = await generationStore.generateImage('去除图片中的水印，保留原始图像内容完整清晰', options, null, false)
    
    if (result && result.url) {
      const finalUrl = result.url
      // Load the image to get its actual natural resolution
      const img = new Image()
      await new Promise((resolve, reject) => {
        img.onload = resolve
        img.onerror = () => reject(new Error('结果图片加载失败'))
        img.src = finalUrl
      })

      const newId = Date.now().toString()
      const size = getMinLongSideSize(el.width, el.height, 600)
      canvasElements.value.push({
        id: newId,
        type: 'image',
        src: finalUrl,
        x: el.x + 50,
        y: el.y + 50,
        width: size.width,
        height: size.height,
        naturalWidth: img.width,
        naturalHeight: img.height,
        zIndex: getNextZIndex()
      })
      
      selectedElementId.value = newId
      el.showWatermarkPanel = false 
      ElMessage.success('水印去除成功，已生成新图片')
    } else {
      throw new Error('未获取到生成的图片地址')
    }
  } catch (e) {
    ElMessage.error('水印去除失败：' + (e.message || '未知错误'))
  } finally {
    el.watermarkProcessing = false
  }
}

const handleUpscaleImage = async (el) => {
  try {
    el.upscaling = true
    
    let targetWidth = Math.round(el.naturalWidth || el.width)
    let targetHeight = Math.round(el.naturalHeight || el.height)

    const maxSide = 4096
    const currentLongSide = Math.max(targetWidth, targetHeight)
    if (currentLongSide > 0 && currentLongSide < maxSide) {
      const scale = maxSide / currentLongSide
      targetWidth = Math.ceil(targetWidth * scale)
      targetHeight = Math.ceil(targetHeight * scale)
    }
    
    const options = {
      width: targetWidth,
      height: targetHeight,
      size: `${targetWidth}x${targetHeight}`,
      reference_images: [el.src],
      generation_mode: 'image_to_image',
      is_upscale: true,
    }

    const result = await generationStore.generateImage('High definition restoration, 4k, detailed', options, '', false)
    
    if (result && result.url) {
      const finalUrl = result.url
      // Load the image to get its actual natural resolution
      const img = new Image()
      await new Promise((resolve, reject) => {
        img.onload = resolve
        img.onerror = () => reject(new Error('结果图片加载失败'))
        img.src = finalUrl
      })

      const newId = Date.now().toString()
      const size = getMinLongSideSize(el.width, el.height, 600)
      canvasElements.value.push({
        id: newId,
        type: 'image',
        src: finalUrl,
        x: el.x + 50,
        y: el.y + 50,
        width: size.width,
        height: size.height,
        naturalWidth: img.width,
        naturalHeight: img.height,
        zIndex: getNextZIndex()
      })
      
      selectedElementId.value = newId
      ElMessage.success('图片高清化成功')
    } else {
      throw new Error('未获取到生成的图片地址')
    }
  } catch (e) {
    ElMessage.error('图片高清化失败：' + (e.message || '未知错误'))
  } finally {
    el.upscaling = false
  }
}

const applyOcrToCanvas = (el) => {
  if (!el.ocrTexts || el.ocrTexts.length === 0) return
  
  if (!el.texts) el.texts = []
  
  // Place identified texts in the middle of the image, slightly offset
  el.ocrTexts.forEach((content, index) => {
    if (!content || content.trim() === '') return
    
    const id = Date.now() + index
    const x = el.width * 0.1
    const y = el.height * (0.2 + (index * 0.1)) // Stack them vertically
    
    el.texts.push({
      id,
      x,
      y,
      xPercent: x / el.width,
      yPercent: y / el.height,
      content: content.trim(),
      color: '#ffffff', // Default white
      size: 24
    })
  })
  
  el.ocrTexts = [] // Clear OCR panel after applying
  ElMessage.success('已转为画布文字，可拖动修改')
}

const copyElement = async () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || !el.src) return
  try {
     if (!navigator.clipboard || typeof navigator.clipboard.write !== 'function' || typeof window.ClipboardItem !== 'function') {
        throw new Error('当前浏览器不支持复制图片')
     }

     const buildProxyUrl = (url) => `${String(config.API_BASE_URL || '').replace(/\/$/, '')}/api/proxy/image?url=${encodeURIComponent(url)}`
     const fetchBlob = async (url) => {
        const resp = await fetch(url)
        if (!resp.ok) throw new Error(`图片拉取失败(${resp.status})`)
        const contentType = (resp.headers.get('content-type') || '').split(';')[0].trim()
        const buf = await resp.arrayBuffer()
        const mime = (contentType && contentType.startsWith('image/')) ? contentType : 'image/png'
        return new Blob([buf], { type: mime })
     }

     const toPngBlob = async (blob) => {
        if ((blob.type || '').toLowerCase() === 'image/png') return blob
        const bmp = await createImageBitmap(blob)
        const canvas = document.createElement('canvas')
        canvas.width = bmp.width || 1
        canvas.height = bmp.height || 1
        const ctx = canvas.getContext('2d')
        if (!ctx) throw new Error('画布创建失败')
        ctx.drawImage(bmp, 0, 0)
        const png = await new Promise((resolve, reject) => {
          canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('图片编码失败'))), 'image/png')
        })
        return png
     }

     let blob = null
     try {
        blob = await fetchBlob(el.src)
     } catch (e) {
        if (el.src.startsWith('data:') || el.src.startsWith('blob:') || String(el.src).includes('/api/proxy/image?url=')) {
          throw e
        }
        blob = await fetchBlob(buildProxyUrl(el.src))
     }

     let finalBlob = blob
     try {
        finalBlob = await toPngBlob(blob)
     } catch {}

     await navigator.clipboard.write([new ClipboardItem({ [finalBlob.type || 'image/png']: finalBlob })])
     ElMessage.success('已复制到剪贴板')
  } catch (e) {
     console.error('copy image failed:', e)
     const msg = (e && e.message) ? String(e.message) : '复制失败'
     ElMessage.error(msg)
     try {
        await navigator.clipboard.writeText(el.src)
        ElMessage.warning('已复制图片链接')
     } catch {}
  }
}

const addToRef = async () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || !el.src) return
  if (generationStore.referenceImages.length >= 4) {
    ElMessage.warning('参考图已达上限')
    return
  }
  const added = await addElementToReference(el, true)
  if (added) ElMessage.success('已加入参考')
}

const addSelectedImagesToRef = async () => {
  closeContextMenu()
  const els = getAllSelectedElements().filter(el => el && el.type === 'image' && el.src)
  if (els.length === 0) return

  let ok = 0
  let hitLimit = false
  for (const el of els) {
    if (generationStore.referenceImages.length >= 4) {
      hitLimit = true
      break
    }
    const added = await addElementToReference(el, false)
    if (added) ok += 1
  }

  if (ok > 0) {
    ElMessage.success(hitLimit ? `已加入参考图（${ok}张，已达上限）` : `已加入参考图（${ok}张）`)
  } else if (hitLimit) {
    ElMessage.warning('参考图已达上限')
  } else {
    ElMessage.warning('未加入参考图')
  }
}

const addElementToReference = async (el, allowVideo) => {
  if (!el || !el.src) return false
  if (!allowVideo && el.type !== 'image') return false
  if (generationStore.referenceImages.length >= 4) return false

  const pushRef = (src) => {
    if (!src) return false
    if (generationStore.referenceImages.length >= 4) return false
    if (generationStore.referenceImages.includes(src)) return false
    generationStore.referenceImages.push(src)
    return true
  }

  try {
    const src = await buildReferenceSourceFromElement(el)
    return pushRef(src)
  } catch (e) {
    console.error('Composition failed:', e)
    const ok = pushRef(el.src)
    if (ok) ElMessage.warning('无法合成涂抹层，已添加原图')
    return ok
  }
}

const MAX_REFERENCE_BYTES = 2 * 1024 * 1024

const buildProxyUrl = (url) => `${String(config.API_BASE_URL || '').replace(/\/$/, '')}/api/proxy/image?url=${encodeURIComponent(url)}`

const fetchBlob = async (url) => {
  const resp = await fetch(url)
  if (!resp.ok) throw new Error(`图片拉取失败(${resp.status})`)
  const contentType = (resp.headers.get('content-type') || '').split(';')[0].trim()
  const buf = await resp.arrayBuffer()
  const mime = (contentType && contentType.startsWith('image/')) ? contentType : 'image/png'
  return new Blob([buf], { type: mime })
}

const fetchBlobWithProxyFallback = async (src) => {
  try {
    return await fetchBlob(src)
  } catch (e) {
    if (String(src).startsWith('data:') || String(src).startsWith('blob:') || String(src).includes('/api/proxy/image?url=')) {
      throw e
    }
    return await fetchBlob(buildProxyUrl(src))
  }
}

const loadImageFromBlob = async (blob) => {
  const url = URL.createObjectURL(blob)
  try {
    const img = new Image()
    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = reject
      img.src = url
    })
    return img
  } finally {
    URL.revokeObjectURL(url)
  }
}

const canvasToJpegBlob = async (canvas, quality) => {
  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
  if (!blob) throw new Error('图片编码失败')
  return blob
}

const drawImageToCanvas = (img, maxDim = 1536) => {
  const w0 = img.naturalWidth || img.width || 1
  const h0 = img.naturalHeight || img.height || 1
  const ratio = w0 / h0
  let w = w0
  let h = h0
  if (w > maxDim || h > maxDim) {
    if (w > h) {
      w = maxDim
      h = Math.max(1, Math.round(maxDim / ratio))
    } else {
      h = maxDim
      w = Math.max(1, Math.round(maxDim * ratio))
    }
  }
  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(w))
  canvas.height = Math.max(1, Math.round(h))
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('画布创建失败')
  ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
  return canvas
}

const compressCanvasToMaxBytes = async (canvas, maxBytes) => {
  const qualities = [0.9, 0.82, 0.74, 0.66, 0.58, 0.5, 0.42, 0.36, 0.3]
  const minSide = 512
  let current = canvas

  for (;;) {
    for (const q of qualities) {
      const blob = await canvasToJpegBlob(current, q)
      if (blob.size <= maxBytes) return blob
    }

    const w = current.width || 1
    const h = current.height || 1
    const longSide = Math.max(w, h)
    if (longSide <= minSide) {
      return await canvasToJpegBlob(current, 0.28)
    }

    const scale = 0.85
    const nextW = Math.max(1, Math.round(w * scale))
    const nextH = Math.max(1, Math.round(h * scale))
    const next = document.createElement('canvas')
    next.width = nextW
    next.height = nextH
    const ctx = next.getContext('2d')
    if (!ctx) throw new Error('画布创建失败')
    ctx.drawImage(current, 0, 0, nextW, nextH)
    current = next
  }
}

const uploadBlobAsReferenceUrl = async (blob) => {
  const type = (blob && blob.type) ? blob.type : 'image/jpeg'
  const ext = type.includes('png') ? 'png' : (type.includes('webp') ? 'webp' : 'jpg')
  const filename = `ref_${Date.now()}_${Math.random().toString(36).slice(2, 7)}.${ext}`
  const file = new File([blob], filename, { type })
  const urls = await generationStore.uploadReferences([file], false, false)
  return Array.isArray(urls) && urls.length ? urls[0] : ''
}

const buildReferenceSourceFromElement = async (el) => {
  let finalSrc = el.src
  const hasMask = el.hasMask && maskCanvasRefs.value[el.id]
  const hasText = el.texts && el.texts.length > 0

  if (el.type === 'image' && (hasMask || hasText)) {
    const srcBlob = await fetchBlobWithProxyFallback(el.src)
    const img = await loadImageFromBlob(srcBlob)
    const canvas = drawImageToCanvas(img, 1536)
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('画布创建失败')

    if (hasMask) {
      const maskCanvas = maskCanvasRefs.value[el.id]
      ctx.drawImage(maskCanvas, 0, 0, canvas.width, canvas.height)
    }

    if (hasText) {
      const scaleX = canvas.width / el.width
      const scaleY = canvas.height / el.height

      ctx.textBaseline = 'top'
      el.texts.forEach(text => {
        const fontSize = text.size * scaleX
        ctx.font = `${fontSize}px "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", Arial, sans-serif`
        ctx.fillStyle = text.color

        const tx = (text.x + 4) * scaleX
        const ty = (text.y + 2) * scaleY
        const lineHeight = fontSize * 1.2

        const lines = (text.content || '').split('\n')
        lines.forEach((line, i) => {
          ctx.fillText(line, tx, ty + i * lineHeight)
        })
      })
    }

    const blob = await compressCanvasToMaxBytes(canvas, MAX_REFERENCE_BYTES)
    finalSrc = await uploadBlobAsReferenceUrl(blob)
  } else if (el.type === 'video') {
    const video = document.createElement('video')
    video.crossOrigin = 'anonymous'
    video.src = el.src
    video.currentTime = 0

    await new Promise((resolve) => {
      video.onloadeddata = () => {
        video.currentTime = 0
      }
      video.onseeked = resolve
      setTimeout(resolve, 1000)
    })

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth || 1024
    canvas.height = video.videoHeight || 1024
    const ctx = canvas.getContext('2d')
    if (!ctx) throw new Error('画布创建失败')
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height)
    const blob = await compressCanvasToMaxBytes(canvas, MAX_REFERENCE_BYTES)
    finalSrc = await uploadBlobAsReferenceUrl(blob)
  } else if (el.type === 'image') {
    const isRemoteUrl = typeof el.src === 'string' && !el.src.startsWith('data:') && !el.src.startsWith('blob:')
    const srcBlob = await fetchBlobWithProxyFallback(el.src)
    if (isRemoteUrl && srcBlob.size > 0 && srcBlob.size <= MAX_REFERENCE_BYTES) {
      return el.src
    }
    const img = await loadImageFromBlob(srcBlob)
    const canvas = drawImageToCanvas(img, 1536)
    const blob = await compressCanvasToMaxBytes(canvas, MAX_REFERENCE_BYTES)
    finalSrc = await uploadBlobAsReferenceUrl(blob)
  }

  return finalSrc
}

const reversePrompt = async () => {
  closeContextMenu()
  const el = canvasElements.value.find(e => e.id === contextMenu.targetId)
  if (!el || el.type !== 'image') return
  
  // Set loading state on the element itself
  el.reversePrompting = true

  try {
    let finalSrc = el.src
    
    // Composition logic (Simplified version of addToRef)
    const hasMask = el.hasMask && maskCanvasRefs.value[el.id]
    const hasText = el.texts && el.texts.length > 0
    
    if (hasMask || hasText) {
       const img = new Image()
       img.crossOrigin = "anonymous"
       let loadSrc = el.src
       if (!el.src.startsWith('data:') && !el.src.startsWith('blob:')) {
           try {
               const resp = await fetch(el.src)
               const blob = await resp.blob()
               loadSrc = URL.createObjectURL(blob)
           } catch(e) {}
       }

       await new Promise((resolve, reject) => {
         img.onload = resolve
         img.onerror = reject
         img.src = loadSrc
       })

       const canvas = document.createElement('canvas')
       // Use natural size or fallback
       canvas.width = img.naturalWidth || img.width || 1024
       canvas.height = img.naturalHeight || img.height || 1024
       const ctx = canvas.getContext('2d')
       
       ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
       
       if (hasMask) {
          const maskCanvas = maskCanvasRefs.value[el.id]
          ctx.drawImage(maskCanvas, 0, 0, canvas.width, canvas.height)
       }
       
       // Text rendering if needed (simplified)
       if (hasText) {
          const scaleX = canvas.width / el.width
          const scaleY = canvas.height / el.height
          ctx.textBaseline = 'top'
          el.texts.forEach(text => {
              const fontSize = text.size * scaleX
              ctx.font = `${fontSize}px "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", Arial, sans-serif`
              ctx.fillStyle = text.color
              const tx = (text.x + 4) * scaleX
              const ty = (text.y + 2) * scaleY
              const lineHeight = fontSize * 1.2
              const lines = (text.content || '').split('\n')
              lines.forEach((line, i) => {
                  ctx.fillText(line, tx, ty + i * lineHeight)
              })
          })
       }
       
       finalSrc = canvas.toDataURL('image/jpeg', 0.8)
    } else if (!finalSrc.startsWith('data:') && !finalSrc.startsWith('blob:')) {
        // Convert remote URL to base64 to ensure backend can access it
         try {
             const resp = await fetch(finalSrc)
             const blob = await resp.blob()
             await new Promise((resolve) => {
                const reader = new FileReader()
                reader.onloadend = () => {
                    finalSrc = reader.result
                    resolve()
                }
                reader.readAsDataURL(blob)
             })
         } catch(e) {
             console.warn('Failed to convert remote image to base64', e)
         }
    }

    const res = await request.post('/api/v1/image/reverse-prompt', {
      image_url: finalSrc,
      options: {}
    })

    if (res.data.code === 200) {
      const taskId = res.data.data.task_id
      // Use WebSocket via generationStore
      try {
          const result = await generationStore.waitForTask(taskId)
          if (result && result.text) {
             generationStore.prompt = result.text
             ElMessage.success('提示词已生成')
             // Focus input
             nextTick(() => {
                 const inputEl = document.querySelector('.rich-input')
                 if (inputEl) inputEl.focus()
             })
          } else {
             ElMessage.warning('未获取到提示词')
          }
      } catch(e) {
          ElMessage.error(e.message || '逆推失败')
      } finally {
          el.reversePrompting = false
      }
    } else {
      ElMessage.error(res.data.msg || '请求失败')
      el.reversePrompting = false
    }
  } catch (e) {
    console.error(e)
    ElMessage.error('操作失败')
    el.reversePrompting = false
  }
}

const downloadElement = async () => {
  closeContextMenu()
  const el = findElementById(canvasElements.value, contextMenu.targetId)
  if (!el || !el.src) return

  try {
    await triggerDownloadForElement(el)
    ElMessage.success('已开始下载')
  } catch (e) {
    console.error('Download failed:', e)
    ElMessage.error('下载失败')
  }
}

const getDownloadFileName = (el) => {
  let name = el?.name || `download_${Date.now()}`
  if (el?.type === 'video' && !name.match(/\.(mp4|webm|mov)$/i)) name += '.mp4'
  if (el?.type === 'image' && !name.match(/\.(png|jpg|jpeg|webp)$/i)) name += '.png'
  return name
}

const resolveDownloadHref = async (el) => {
  let finalSrc = el.src
  let isBlob = false

  const hasMask = el.hasMask && maskCanvasRefs.value[el.id]
  const hasText = el.texts && el.texts.length > 0

  if (el.type === 'image' && (hasMask || hasText)) {
    const img = new Image()
    img.crossOrigin = "anonymous"
    let loadSrc = el.src
    if (!el.src.startsWith('data:') && !el.src.startsWith('blob:')) {
      try {
        const resp = await fetch(el.src)
        const blob = await resp.blob()
        loadSrc = URL.createObjectURL(blob)
      } catch (e) {}
    }

    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = reject
      img.src = loadSrc
    })

    const canvas = document.createElement('canvas')
    canvas.width = img.naturalWidth || img.width || 1024
    canvas.height = img.naturalHeight || img.height || 1024
    const ctx = canvas.getContext('2d')

    ctx.drawImage(img, 0, 0, canvas.width, canvas.height)

    if (hasMask) {
      const maskCanvas = maskCanvasRefs.value[el.id]
      ctx.drawImage(maskCanvas, 0, 0, canvas.width, canvas.height)
    }

    if (hasText) {
      const scaleX = canvas.width / el.width
      const scaleY = canvas.height / el.height

      ctx.textBaseline = 'top'
      el.texts.forEach(text => {
        const fontSize = text.size * scaleX
        ctx.font = `${fontSize}px "Helvetica Neue", Helvetica, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "微软雅黑", Arial, sans-serif`
        ctx.fillStyle = text.color

        const tx = (text.x + 4) * scaleX
        const ty = (text.y + 2) * scaleY
        const lineHeight = fontSize * 1.2

        const lines = (text.content || '').split('\n')
        lines.forEach((line, i) => {
          ctx.fillText(line, tx, ty + i * lineHeight)
        })
      })
    }

    finalSrc = canvas.toDataURL('image/png')
  } else if (!el.src.startsWith('data:') && !el.src.startsWith('blob:')) {
    try {
      const resp = await fetch(el.src)
      const blob = await resp.blob()
      finalSrc = URL.createObjectURL(blob)
      isBlob = true
    } catch (e) {}
  }

  return { href: finalSrc, isBlob }
}

const triggerDownloadForElement = async (el) => {
  const { href, isBlob } = await resolveDownloadHref(el)
  const link = document.createElement('a')
  link.href = href
  link.download = getDownloadFileName(el)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)

  if (isBlob && href.startsWith('blob:')) {
    URL.revokeObjectURL(href)
  }
}

const downloadSelectedElements = async () => {
  closeContextMenu()
  const els = getAllSelectedElements().filter(el => el && el.type === 'image' && el.src)
  if (els.length === 0) {
    ElMessage.warning('未选择可下载的图片')
    return
  }

  let ok = 0
  for (const el of els) {
    try {
      await triggerDownloadForElement(el)
      ok += 1
    } catch (e) {}
  }

  if (ok > 0) ElMessage.success('已开始下载')
  else ElMessage.error('下载失败')
}

// --- Generation Integration ---

const handleGenerate = () => {
  if (!generationStore.prompt.trim()) {
    ElMessage.warning('请输入提示词')
    return
  }
  generationStore.generateImage(generationStore.prompt)
}

// Watch for new generated images
const processedUrls = ref(new Set())
const loadingUrls = ref(new Set()) // Track images currently loading to prevent duplicates

watch(() => generationStore.generatedImage, (newVal) => {
  if (newVal) {
    // Check if image already exists on canvas to avoid duplicates
    const exists = canvasElements.value.some(el => el.src === newVal)
    if (exists) {
      generationStore.generatedImage = '' // Clear even if exists to prevent re-triggering
      return
    }

    // Check if currently loading
    if (loadingUrls.value.has(newVal)) {
      return
    }

    // Check if this image belongs to a message that has already been processed (e.g. deleted by user)
    // We check if the URL is in processedUrls
    if (processedUrls.value.has(newVal)) {
        generationStore.generatedImage = ''
        return
    }

    loadingUrls.value.add(newVal)

    const sourceW = Math.max(1, Number(generationStore.width) || 0)
    const sourceH = Math.max(1, Number(generationStore.height) || 0)
    const targetLongSide = 600
    const currentLongSide = Math.max(sourceW, sourceH)
    const scale = currentLongSide > 0 ? (targetLongSide / currentLongSide) : 1

    const w = Math.max(1, Math.round(sourceW * scale))
    const h = Math.max(1, Math.round(sourceH * scale))

    addElement('image', newVal, w, h, `Generated-${Date.now().toString().slice(-4)}`, {
      nw: sourceW,
      nh: sourceH,
      setConversationCover: true,
      imageLoading: true
    })
    loadingUrls.value.delete(newVal)
    generationStore.generatedImage = '' // Clear after adding
  }
})

// Watch for chat messages to auto-insert video/image results
const processedMsgIds = ref(new Set())
const loadedMsgIds = ref(new Set())

// Persistence helper
const saveProcessedIds = () => {
  if (chatStore.currentConversationId) {
    const keyMsg = `processed_msgs_${chatStore.currentConversationId}`
    const keyUrl = `processed_urls_${chatStore.currentConversationId}`
    try {
      localStorage.setItem(keyMsg, JSON.stringify([...processedMsgIds.value]))
      localStorage.setItem(keyUrl, JSON.stringify([...processedUrls.value]))
    } catch (e) { /* ignore */ }
  }
}

// Load persisted IDs when conversation changes
watch(() => chatStore.currentConversationId, (newId) => {
  if (newId) {
    const keyMsg = `processed_msgs_${newId}`
    const keyUrl = `processed_urls_${newId}`
    try {
      const savedMsg = JSON.parse(localStorage.getItem(keyMsg) || '[]')
      processedMsgIds.value = new Set(savedMsg)
      
      const savedUrl = JSON.parse(localStorage.getItem(keyUrl) || '[]')
      processedUrls.value = new Set(savedUrl)
    } catch (e) {
      processedMsgIds.value = new Set()
      processedUrls.value = new Set()
    }
  } else {
    processedMsgIds.value = new Set()
    processedUrls.value = new Set()
  }
  loadedMsgIds.value = new Set() // Reset session tracking
}, { immediate: true })

watch(() => chatStore.messages, (msgs) => {
  if (!msgs || !msgs.length) return
  
  let needsSave = false

  // 1. Mark newly loaded messages (history) as "seen" so we don't auto-add their results if they already exist
  msgs.forEach(m => {
    if (!loadedMsgIds.value.has(m.id)) {
      // This is a newly loaded message (e.g. from history fetch)
      
      // 无论时间戳如何，只要是首次加载且已有结果，就视为"已处理"（即不自动上画布）
      // 只有那些"当前没有结果"（任务进行中）的消息，我们才保留监听，等待结果返回时自动上画布
      if (m.toolResult && (m.toolResult.video_url || m.toolResult.image_url)) {
          if (!processedMsgIds.value.has(m.id)) {
             processedMsgIds.value.add(m.id)
             needsSave = true
          }
      }
      
      loadedMsgIds.value.add(m.id)
    }
  })
  
  // 2. Process all messages for new results
  // We iterate backwards to process newer messages first
  for (let i = msgs.length - 1; i >= 0; i--) {
    const m = msgs[i]
    
    // Skip if not assistant or no result
    if (m.role !== 'assistant' || !m.toolResult) continue
    if (m.toolResult && m.toolResult.no_auto_add_to_canvas) {
      processedMsgIds.value.add(m.id)
      needsSave = true
      continue
    }
    
    const videoUrl = m.toolResult.video_url
    const imageUrl = m.toolResult.image_url
    
    if (!videoUrl && !imageUrl) continue

    // Skip if already processed in this session
    if (processedMsgIds.value.has(m.id)) continue
    
    // Double check canvas existence just in case (e.g. added manually or persisted)
    const urlToCheck = videoUrl || imageUrl
    
    // Check if URL is marked as processed (deleted)
    if (processedUrls.value.has(urlToCheck)) {
        processedMsgIds.value.add(m.id)
        needsSave = true
        continue
    }

    if (canvasElements.value.some(el => el.src === urlToCheck)) {
        processedMsgIds.value.add(m.id)
        needsSave = true
        continue
    }

    // Check if currently loading via other watcher
    if (loadingUrls.value.has(urlToCheck)) continue

    // Add to canvas
    processedMsgIds.value.add(m.id)
    needsSave = true
    
    loadingUrls.value.add(urlToCheck)

    if (videoUrl) {
       // Load video metadata to get dimensions
       const tempVideo = document.createElement('video')
       tempVideo.onloadedmetadata = () => {
           let w = tempVideo.videoWidth
           let h = tempVideo.videoHeight
           const max = 512
           
           if (w > max || h > max) {
               const scale = Math.min(max / w, max / h)
               w = Math.round(w * scale)
               h = Math.round(h * scale)
           }
           
           // Ensure min size
           w = Math.max(w, 100)
           h = Math.max(h, 100)
           
           addElement('video', videoUrl, w, h, m.toolResult.image_name || `Video-${m.id}`, { nw: tempVideo.videoWidth, nh: tempVideo.videoHeight })
           loadingUrls.value.delete(urlToCheck)
           ElMessage.success('视频已添加到画布')
       }
       tempVideo.onerror = () => {
           // Fallback if load fails
           addElement('video', videoUrl, 512, 512, m.toolResult.image_name || `Video-${m.id}`, {})
           loadingUrls.value.delete(urlToCheck)
           ElMessage.success('视频已添加到画布')
       }
       // Set crossOrigin just in case
       tempVideo.crossOrigin = "anonymous" 
       tempVideo.src = videoUrl
    } else if (imageUrl) {
       // Load image to get dimensions
       const img = new Image()
       img.onload = () => {
           let w = img.width
           let h = img.height
           const targetLongSide = 600
           const currentLongSide = Math.max(w, h)

           if (currentLongSide > 0 && currentLongSide !== targetLongSide) {
               const scale = targetLongSide / currentLongSide
               w = Math.max(1, Math.round(w * scale))
               h = Math.max(1, Math.round(h * scale))
           }
           
           addElement('image', imageUrl, w, h, m.toolResult.image_name || `Image-${m.id}`, { nw: img.width, nh: img.height, setConversationCover: true })
           loadingUrls.value.delete(urlToCheck)
           ElMessage.success('图片已添加到画布')
       }
       img.onerror = () => {
           addElement('image', imageUrl, 600, 600, m.toolResult.image_name || `Image-${m.id}`, { setConversationCover: true })
           loadingUrls.value.delete(urlToCheck)
           ElMessage.success('图片已添加到画布')
       }
       img.src = imageUrl
    }
  }
  
  if (needsSave) saveProcessedIds()
}, { deep: true })

// Watch for conversation route changes to auto-collapse sidebar
watch(
  () => route.params.conversation_id,
  async (newVal) => {
    const nextId = newVal ? String(newVal) : ''
    if (nextId) {
      generationStore.clearMarkerPrompts()
      generationStore.referenceImages = []
      try {
        localStorage.removeItem('app_marker_prompts')
      } catch {}
      isEnteringConversation.value = true
      isConversationLoading.value = true
      conversationReadyId.value = ''
      try {
        await initBaseConfig()
        if (nextId !== String(chatStore.currentConversationId || '')) {
          await chatStore.loadConversation(nextId)
          chatStore.fetchConversations()
        }
      } finally {
        conversationReadyId.value = nextId
        isConversationLoading.value = false
        isEnteringConversation.value = false
      }
      return
    }

    isEnteringConversation.value = false
    isConversationLoading.value = false
    conversationReadyId.value = ''
    chatStore.resetCurrentState()
    canvasElements.value = []
    generationStore.clearMarkerPrompts()
    generationStore.referenceImages = []
    try {
      localStorage.removeItem('app_marker_prompts')
    } catch {}
    resetView()
  },
  { immediate: true }
)

// --- Refs & Utilities ---

const previewOfRef = (item) => {
  if (typeof item === 'string') return item
  if (item instanceof File) return URL.createObjectURL(item)
  return ''
}

// Sidebar Resize
const startResize = () => {
  isDraggingSidebar = true
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
}

let resizeObserver = null

// --- Persistence ---
let isRemoteUpdate = false

// Watch for conversation switch to clear any pending saves
watch(() => chatStore.currentConversationId, (newId) => {
       if (newId && String(newId) !== String(route.params.conversation_id)) {
          router.push({ name: 'general-creation', params: { conversation_id: newId } })
       }

       if (saveTimer) {
         clearTimeout(saveTimer)
         saveTimer = null
       }
       // Also clear canvas immediately to prevent old canvas showing
       // Mark as remote update to prevent saving the empty state
       isRemoteUpdate = true
       canvasElements.value = []
       resetView()
       // Reset flag in nextTick to allow subsequent user edits to trigger save
       nextTick(() => {
          isRemoteUpdate = false
       })
    })

watch(() => chatStore.currentCanvasState, (val) => {
  isRemoteUpdate = true
  if (val) {
     canvasElements.value = val
     pruneOrphanMarkers()
     // We might want to auto-fit if it's the first load
     if (canvasElements.value.length > 0 && !isEnteringConversation.value) {
        nextTick(() => fitToView())
     }
     checkAndRecoverTasks()
  } else {
     canvasElements.value = []
     resetView()
  }
  // Reset flag after watchers have fired
  nextTick(() => { isRemoteUpdate = false })
})


watch(canvasElements, (newVal) => {
  if (isRemoteUpdate) return
  if (chatStore.currentConversationId) {
     if (saveTimer) clearTimeout(saveTimer)
     saveTimer = setTimeout(() => {
        // Double check we are not in remote update and ID is still valid
        if (!isRemoteUpdate && chatStore.currentConversationId) {
           chatStore.saveCanvasState(chatStore.currentConversationId, newVal)
        }
     }, 1000)
  }
}, { deep: true })

// Sync markers removal from store to canvas
watch(() => generationStore.markerPrompts, (newMarkers) => {
  const validNumbers = new Set(newMarkers.map(m => m.number))
  
  canvasElements.value.forEach(el => {
    if (el.markers && el.markers.length > 0) {
      el.markers = el.markers.filter(m => validNumbers.has(m.number))
    }
  })
}, { deep: true })

// --- Lifecycle ---

onMounted(async () => {
  window.addEventListener('mousemove', handleGlobalMouseMove)
  window.addEventListener('mouseup', handleGlobalMouseUp)
  window.addEventListener('paste', handlePaste)
  window.addEventListener('pointerdown', handleGlobalPointerDownCapture, true)
  window.addEventListener('keydown', handleGlobalKeyDown)
  window.addEventListener('keyup', handleGlobalKeyUp)
  window.addEventListener('blur', handleWindowBlur)
  
  // Auto fit on entry
  resetView()
  
  // Check for any pending tasks that need recovery (e.g. after HMR or reload)
  checkAndRecoverTasks()
  
  // Observe container resize (e.g. sidebar toggle)
  if (containerRef.value) {
    resizeObserver = new ResizeObserver(() => {
       // Optional: fitToView() on resize if desired, or just let user handle it.
       // The user request "Entering canvas auto zoom left canvas" implies entry.
       // But if the sidebar "zoom button" (toggle) is used, maybe they expect re-fit?
       // Let's stick to initial entry for now to avoid annoying auto-zoom during manual resizing.
       // But if the user explicitly toggles the sidebar to "zoom", maybe they want more space.
       // For now, let's just ensure we have the observer structure if needed later.
    })
    resizeObserver.observe(containerRef.value)
  }
})

onUnmounted(() => {
  window.removeEventListener('mousemove', handleGlobalMouseMove)
  window.removeEventListener('mouseup', handleGlobalMouseUp)
  window.removeEventListener('paste', handlePaste)
  window.removeEventListener('pointerdown', handleGlobalPointerDownCapture, true)
  window.removeEventListener('keydown', handleGlobalKeyDown)
  window.removeEventListener('keyup', handleGlobalKeyUp)
  window.removeEventListener('blur', handleWindowBlur)
  if (resizeObserver) resizeObserver.disconnect()
  if (minLoadingTimer) {
    clearTimeout(minLoadingTimer)
    minLoadingTimer = null
  }
  if (fitToViewAfterSidebarTimer) {
    clearTimeout(fitToViewAfterSidebarTimer)
    fitToViewAfterSidebarTimer = null
  }
  
  // Reset sidebar state
  appStore.requestSidebarCollapse(false)
  appStore.setWorkspaceLoading(false)
  
  // Reset chat and canvas state when leaving the page
  chatStore.resetCurrentState()
})

</script>

<style scoped lang="scss">
.dashboard-container {
  position: relative;
}

.page-loading-overlay {
  position: absolute;
  inset: 0;
  z-index: 999;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(6px);
}

.page-loading-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 14px 18px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.page-loading-bar {
  width: 160px;
  height: 3px;
  border-radius: 999px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.08);
}

.page-loading-bar-inner {
  width: 40%;
  height: 100%;
  border-radius: 999px;
  background: rgba(64, 158, 255, 0.95);
  animation: page-loading-move 0.9s ease-in-out infinite;
}

@keyframes page-loading-move {
  0% { transform: translateX(-120%); }
  100% { transform: translateX(320%); }
}

.matting-overlay {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 10;
  border-radius: 4px;
  pointer-events: none;
}

.matting-spinner {
  width: 30px;
  height: 30px;
  border: 3px solid rgba(255, 255, 255, 0.3);
  border-radius: 50%;
  border-top-color: #fff;
  animation: spin 1s ease-in-out infinite;
  margin-bottom: 8px;
}

// Fix for missing left sidebar styles (moved to root scope)
.left-toolbar {
  position: absolute;
  left: 24px;
  top: auto;
  bottom: max(10%, 80px);
  transform: none;
  z-index: 20;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(4px);
  padding: 8px;
  border-radius: 12px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);

  &:hover {
    background: #fff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  }
  
  .tool-btn {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border-radius: 8px;
    transition: all 0.2s;
    color: #606266;
    outline: none;
    
    &:hover {
      background: #f0f2f5;
      color: #409eff;
    }

    &.active {
      background: #ecf5ff;
      color: #409eff;
    }
    
    .el-icon { font-size: 20px; }
  }
}

.layers-panel-wrapper {
  position: absolute;
  left: 88px;
  bottom: max(10%, 80px);
  width: 280px;
  max-height: 800px;
  height: 60%;
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 24px rgba(0,0,0,0.15);
  display: flex;
  flex-direction: column;
  z-index: 21;
  overflow: hidden;

  .layers-header {
    padding: 12px 16px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 500;
    color: #303133;
    font-size: 14px;
    flex-shrink: 0;

    .close-btn {
      cursor: pointer;
      color: #909399;
      font-size: 16px;
      &:hover { color: #f56c6c; }
    }
  }
}

.canvas-overlay-controls {
  position: absolute;
  bottom: 34px;
  right: 34px;
  z-index: 20;
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255, 255, 255, 0.8);
  backdrop-filter: blur(4px);
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
  padding: 6px;

  &:hover {
    background: #fff;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  }

  :deep(.el-button-group) {
    display: flex;
  }

  :deep(.el-button) {
    border: none;
    background: transparent;
  }
  
  .zoom-text-btn {
    min-width: 60px;
    font-variant-numeric: tabular-nums;
  }
}

.matting-text {
  color: #fff;
  font-size: 12px;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
}

.ocr-panel {
  position: absolute;
  top: 0;
  width: 200px;
  background: white;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  display: flex;
  flex-direction: column;
  z-index: 20;
  overflow: hidden;
  border: 1px solid #eee;

  .ocr-panel-header {
    padding: 8px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: bold;
    color: #606266;

    .ocr-close {
      cursor: pointer;
      &:hover { color: #f56c6c; }
    }
  }

  .ocr-panel-body {
    max-height: 300px;
    overflow-y: auto;
    padding: 8px;

    .ocr-item {
      margin-bottom: 8px;
      &:last-child { margin-bottom: 0; }
      
      :deep(.el-textarea__inner) {
        font-size: 12px;
        padding: 4px 8px;
      }
    }
  }

  .ocr-panel-footer {
    padding: 8px;
    border-top: 1px solid #eee;
    text-align: center;

    .ocr-model-select {
      margin-bottom: 12px;
      text-align: left;
      .label {
        font-size: 12px;
        color: #909399;
        margin-bottom: 4px;
      }
      .el-select {
        width: 100%;
      }
    }
    
    .el-button {
      width: 100%;
    }
  }
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.dashboard-container {
  height: 100%;
  display: flex;
  width: 100%;
  overflow: hidden;
  position: relative; // Ensure absolute children are relative to this container
  background-color: #f7f7f7; // Added background color for the gap
  
  .infinite-canvas-container {
    outline: none;
    -webkit-tap-highlight-color: transparent;
  }
  .infinite-canvas-container:focus,
  .infinite-canvas-container:focus-visible {
    outline: none;
  }

  .history-wrapper {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 100;
    display: flex;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    
    // Pinned State (Default when no conversation)
    &.is-pinned {
       width: 260px;
    }
    
    // Floating State (In conversation)
    &.is-floating {
       position: absolute;
       left: 0;
       top: 0;
       bottom: 0;
       width: 0; // Collapsed by default
       overflow: visible; // Allow trigger and expanded content
       
       .hover-trigger {
          position: absolute;
          left: 0;
          top: 0;
          bottom: 0;
          width: 20px;
          z-index: 102;
          background: transparent;
       }
       
       // Hide sidebar initially
       :deep(.history-sidebar) {
          position: absolute;
          left: 0;
          top: 0;
          height: 100%;
          transform: translateX(-100%);
          transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
          box-shadow: none;
          z-index: 101;
       }
       
       // Expanded State
       &.is-expanded {
          :deep(.history-sidebar) {
             transform: translateX(0);
             box-shadow: 4px 0 16px rgba(0,0,0,0.1);
          }
       }
    }
  }

  .canvas-area {
    flex: 1;
    display: flex;
    flex-direction: column;
    padding: 0;
    position: relative;
    background-color: #f0f2f5;
    border-radius: 0 16px 16px 0; // Added rounded corners
    overflow: hidden; // Clip content
    
    // When history sidebar is floating, canvas takes full width naturally
    // When pinned, it shares space.
    // Ensure z-index is lower than floating sidebar
    z-index: 1;
    
    .top-toolbar-container {
      position: absolute;
      top: 20px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 20;
    }

    .canvas-header-actions {
      position: absolute;
      top: 16px;
      right: 24px;
      z-index: 10;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 8px;
      
      .clear-btn {
        background: rgba(255,255,255,0.8);
        backdrop-filter: blur(4px);
        border-radius: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        padding: 8px 12px;
        height: auto;
        
        &:hover {
          background: #fff;
          box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
      }
    }
    
      .infinite-canvas-container {
      flex: 1;
      width: 100%;
      height: 100%;
      overflow: hidden;
      cursor: grab;
      position: relative;
      background-color: #f8f9fa;
      background-image: 
        linear-gradient(45deg, #e9ecef 25%, transparent 25%, transparent 75%, #e9ecef 75%, #e9ecef),
        linear-gradient(45deg, #e9ecef 25%, transparent 25%, transparent 75%, #e9ecef 75%, #e9ecef);
      background-size: 20px 20px;
      background-position: 0 0, 10px 10px;
      user-select: none; /* Prevent native selection */
      
      &:active {
        cursor: grabbing;
      }

      &.ctrl-down,
      &.ctrl-down:active {
        cursor: default;
      }
      
      .infinite-canvas-world {
        position: absolute;
        top: 0;
        left: 0;
        transform-origin: 0 0;
        will-change: transform;
      }
      
      .canvas-element {
        position: absolute;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transition: box-shadow 0.2s;
        
        &:hover {
          box-shadow: 0 0 0 1px #409eff;
        }

        &.selected {
          box-shadow: 0 0 0 2px #409eff;
          z-index: 1000 !important;
        }

        &.highlighted {
          box-shadow: 0 0 0 2px #409eff;
        }
        
        .element-content {
          width: 100%;
          height: 100%;
          object-fit: contain;
          pointer-events: none; // Let events pass to container for drag
          display: block;
          
          // Allow interaction with video controls
          &[controls], &:is(video) {
             pointer-events: auto;
          }
        }
        
        .text-layer {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          z-index: 6; 
          pointer-events: none;
          
          .text-item {
             position: absolute;
             pointer-events: auto;
             user-select: text;
             min-width: 20px;
             min-height: 20px;
             white-space: nowrap;
             
             .text-content {
                outline: none;
                user-select: text;
                padding: 2px 4px;
                width: 100%;
                height: 100%;
                border: 1px dashed transparent;
                background: rgba(255, 255, 255, 0.01); 
                min-width: 20px;
                min-height: 1.2em;
                
                &:focus {
                   border-color: #409eff;
                   background: rgba(255, 255, 255, 0.5);
                }

                &:empty::before {
                   content: '输入文字';
                   color: rgba(128, 128, 128, 0.5);
                   pointer-events: none;
                }
             }

             .text-delete-btn {
                position: absolute;
                top: calc(-12px / var(--scale-factor));
                right: calc(-12px / var(--scale-factor));
                transform: scale(calc(1 / var(--scale-factor)));
                background: #f56c6c;
                color: white;
                border-radius: 50%;
                width: 16px;
                height: 16px;
                font-size: 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                opacity: 0;
                transition: opacity 0.2s;
             }

             &:hover .text-delete-btn {
                opacity: 1;
             }
          }
        }

        .text-interaction-layer {
           position: absolute;
           inset: 0;
           z-index: 99; 
           cursor: text;
        }

        .mask-layer {
          position: absolute;
          top: 0;
          left: 0;
          width: 100%;
          height: 100%;
          z-index: 5;
          pointer-events: none;
          
          &.active {
            pointer-events: auto;
            cursor: crosshair;
          }
        }

        .element-controls {
          position: absolute;
          inset: 0;
          pointer-events: none;
          
          .brush-settings {
            position: absolute;
            top: auto;
            bottom: calc(100% + 20px / var(--scale-factor));
            right: calc(100px / var(--scale-factor));
            transform: scale(calc(1 / var(--scale-factor)));
            transform-origin: bottom right;
            background: #fff;
            padding: 8px 12px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: auto;
            z-index: 100;
            white-space: nowrap;
            
            .label {
              font-size: 12px;
              color: #606266;
            }

            .color-palette {
              display: flex;
              gap: 4px;
              margin: 0 8px;
              
              .color-dot {
                width: 16px;
                height: 16px;
                border-radius: 50%;
                cursor: pointer;
                border: 1px solid rgba(0,0,0,0.1);
                
                &.active {
                  box-shadow: 0 0 0 2px #409eff;
                }
              }
            }
          }

          .brush-btn {
            position: absolute;
            top: auto;
            bottom: calc(100% + 12px / var(--scale-factor));
            right: calc(32px / var(--scale-factor));
            transform: scale(calc(1 / var(--scale-factor)));
            transform-origin: bottom right;
            width: 24px;
            height: 24px;
            background: #409eff;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            pointer-events: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            z-index: 100;
            
            &.active {
               background: #e6a23c;
            }
            
            .el-icon { font-size: 14px; }
          }

          .text-btn {
            position: absolute;
            top: auto;
            bottom: calc(100% + 12px / var(--scale-factor));
            right: calc(64px / var(--scale-factor));
            transform: scale(calc(1 / var(--scale-factor)));
            transform-origin: bottom right;
            width: 24px;
            height: 24px;
            background: #67c23a;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            pointer-events: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            z-index: 100;
            
            &.active {
               background: #e6a23c;
            }
            
            .el-icon { font-size: 14px; }
          }

          .delete-btn {
            position: absolute;
            top: auto;
            bottom: calc(100% + 12px / var(--scale-factor));
            right: calc(0px / var(--scale-factor));
            transform: scale(calc(1 / var(--scale-factor)));
            transform-origin: bottom right;
            width: 24px;
            height: 24px;
            background: red;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            pointer-events: auto;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            
            .el-icon { font-size: 14px; }
          }
          
          .resize-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            transform: scale(calc(1 / var(--scale-factor)));
            background: #fff;
            border: 1px solid #409eff;
            pointer-events: auto;
            z-index: 10;
            
            // Corners
            &.nw { top: -5px; left: -5px; cursor: nw-resize; }
            &.ne { top: -5px; right: -5px; cursor: ne-resize; }
            &.sw { bottom: -5px; left: -5px; cursor: sw-resize; }
            &.se { bottom: -5px; right: -5px; cursor: se-resize; }
            
            // Edges (rectangular shape)
            &.n { top: -5px; left: 50%; margin-left: -5px; cursor: n-resize; }
            &.s { bottom: -5px; left: 50%; margin-left: -5px; cursor: s-resize; }
            &.w { left: -5px; top: 50%; margin-top: -5px; cursor: w-resize; }
            &.e { right: -5px; top: 50%; margin-top: -5px; cursor: e-resize; }
          }
        }
        
        .element-info-overlay {
           position: absolute;
           top: auto;
           bottom: calc(100% + 8px / var(--scale-factor));
           left: 0;
           transform: scale(calc(1 / var(--scale-factor)));
           transform-origin: bottom left;
           background: rgba(0, 0, 0, 0.7);
           color: #fff;
           padding: 2px 6px;
           border-radius: 4px;
           font-size: 12px;
           white-space: nowrap;
           pointer-events: none;
           display: flex;
           gap: 8px;
           z-index: 2000;
           
           .info-text {
              max-width: 150px;
              overflow: hidden;
              text-overflow: ellipsis;
           }
           
           .info-res {
              opacity: 0.8;
           }
        }
      }
      
      .left-toolbar {
        position: absolute;
        left: 24px;
        top: auto;
        bottom: max(10%, 80px);
        transform: none;
        z-index: 20;
        display: flex;
        flex-direction: column;
        gap: 12px;
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(4px);
        padding: 8px;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);

        &:hover {
          background: #fff;
          box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .tool-btn {
           width: 40px;
           height: 40px;
           display: flex;
           align-items: center;
           justify-content: center;
           cursor: pointer;
           border-radius: 8px;
           transition: all 0.2s;
           color: #606266;
           outline: none; // Remove focus outline for dropdown trigger
           
           &:hover {
              background: #f0f2f5;
              color: #409eff;
           }

           &.active {
              background: #ecf5ff;
              color: #409eff;
           }
           
           .el-icon { font-size: 20px; }
        }
      }

      .layers-panel-wrapper {
        position: absolute;
        left: 88px;
        bottom: max(10%, 80px);
        width: 280px;
        max-height: 800px;
        height: 60%;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.15);
        display: flex;
        flex-direction: column;
        z-index: 21;
        overflow: hidden;

        .layers-header {
          padding: 12px 16px;
          border-bottom: 1px solid #f0f0f0;
          display: flex;
          align-items: center;
          justify-content: space-between;
          font-weight: 500;
          color: #303133;
          font-size: 14px;
          flex-shrink: 0;

          .close-btn {
            cursor: pointer;
            color: #909399;
            font-size: 16px;
            &:hover { color: #f56c6c; }
          }
        }
      }


      

    }
    
    .prompt-container {
      position: absolute;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      width: 90%;
      max-width: 800px;
      z-index: 20;
      
      .input-box-container {
        background: #fff;
        border-radius: 16px;
        padding: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.1);
        
        .input-area {
          display: flex;
          gap: 12px;
          
          .add-btn {
            height: auto;
            flex-direction: column;
            .add-icon-box {
               width: 48px;
               height: 48px;
               background: #f5f7fa;
               border-radius: 8px;
               display: flex;
               align-items: center;
               justify-content: center;
            }
          }
          
          .prompt-textarea {
             flex: 1;
             :deep(.el-textarea__inner) {
               box-shadow: none;
               padding: 8px;
             }
          }
        }
        
        .controls-bar {
           display: flex;
           justify-content: space-between;
           margin-top: 8px;
           padding-top: 8px;
           border-top: 1px solid #eee;
        }
      }
    }
  }

  .resize-handle {
    width: 10px; // Increased width for gap
    cursor: col-resize;
    background: transparent;
    flex-shrink: 0; // Prevent shrinking
    // &:hover { background: #d0d0d0; } // Removed hover background to keep gap clean
  }

  .chat-sidebar {
    background: #fff;
    // border-left: 1px solid #e0e0e0; // Removed border
    border-radius: 16px 0 0 16px; // Added rounded corners
    overflow: hidden;
  }

  /* Video Generator Styles */
  .video-generator-card {
    width: 100%;
    height: 100%;
    background: #fff;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border-radius: 12px;
    
    .vg-header {
      padding: 8px 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #606266;
      font-size: 13px;
      
      .vg-title {
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
      }
      .vg-close-btn {
        color: #909399;
        font-size: 16px;
        padding: 4px;
        height: auto;
        
        &:hover {
          color: #f56c6c;
          background: rgba(245, 108, 108, 0.1);
          border-radius: 4px;
        }
      }
    }

    .vg-preview {
      flex: 1;
      background: #f5f7fa;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 12px;
      border-radius: 12px;
      overflow: hidden;
      position: relative;
      min-height: 100px;

      .play-icon {
        font-size: 64px;
        color: #93c5fd;
        opacity: 0.6;
      }
      
      .vg-floating-action {
          position: absolute;
          top: 8px;
          right: 8px;
          z-index: 10;
          opacity: 0;
          transition: opacity 0.2s;
      }
      
      &:hover .vg-floating-action {
          opacity: 1;
      }

      .vg-loading {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        color: #909399;
        font-size: 14px;
        width: 100%;

        .vg-progress-bar {
            width: 60%;
            height: 4px;
            background-color: #e4e7ed;
            border-radius: 2px;
            overflow: hidden;
            
            .vg-progress-inner {
                height: 100%;
                background-color: #409eff;
                transition: width 0.3s ease;
            }
        }
        
        .loading-spinner {
            width: 24px;
            height: 24px;
            border: 2px solid #e5e7eb;
            border-top-color: #409eff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
      }

      .vg-error {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          gap: 8px;
          color: #f56c6c;
          font-size: 14px;
          text-align: center;
          padding: 0 20px;
          width: 100%;
          height: 100%;
          
          .error-icon {
              font-size: 28px;
              margin-bottom: 4px;
          }
          
          .error-msg {
              line-height: 1.4;
              word-break: break-all;
              max-height: 60px;
              overflow-y: auto;
          }
      }
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }

    .vg-input-area {
      padding: 12px;
      background: #fff;
      margin: 12px;
      border-radius: 12px;
      box-shadow: 0 2px 12px rgba(0,0,0,0.05);
      
      &.disabled {
         opacity: 0.6;
         pointer-events: none;
         user-select: none;
      }

      .vg-prompt-input {
        :deep(.el-textarea__inner) {
          border: none;
          box-shadow: none;
          padding: 0;
          font-size: 15px;
          background: transparent;
          resize: none;
          font-family: inherit;
          
          &::placeholder {
            color: #c0c4cc;
          }
        }
      }

      .vg-attachments {
         display: flex;
         gap: 8px;
         margin-top: 8px;
      }

      .vg-btn {
         width: 40px;
         height: 40px;
         border: 1px solid #ebeef5;
         border-radius: 8px;
         display: flex;
         align-items: center;
         justify-content: center;
         cursor: pointer;
         color: #909399;
         transition: all 0.2s;
         font-size: 18px;
         
         &:hover {
           border-color: #409eff;
           color: #409eff;
           background: #f5f7fa;
         }
         
         &.disabled {
             opacity: 0.5;
             cursor: not-allowed;
             &:hover {
                 border-color: #ebeef5;
                 color: #909399;
                 background: transparent;
             }
         }
      }

      .vg-controls-row {
         display: flex;
         justify-content: space-between;
         align-items: center;
         margin-top: 12px;

         .mode-switcher {
             display: flex;
             position: relative;
             background: #f1f2f5;
             border-radius: 20px;
             padding: 3px;
             height: 32px;
             box-sizing: border-box;

             .mode-indicator {
               position: absolute;
               top: 3px;
               bottom: 3px;
               left: 3px;
               width: calc((100% - 6px) / 3);
               background: #fff;
               border-radius: 16px;
               box-shadow: 0 1px 2px rgba(0,0,0,0.1);
               transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
               pointer-events: none;
             }

             .mode-item {
               flex: 1 1 0px; /* Force equal width regardless of content */
               min-width: 70px; /* Ensure enough space for text */
               z-index: 1;
               font-size: 12px;
               color: #606266;
               text-align: center;
               cursor: pointer;
               padding: 0;
               white-space: nowrap;
               transition: color 0.3s;
               user-select: none;
               border-radius: 16px;
               
               display: flex;
               align-items: center;
               justify-content: center;
               height: 100%;
               line-height: normal;
               
               &.active {
                 color: #303133;
                 font-weight: 500;
               }
               
               &.disabled {
                   cursor: not-allowed;
                   opacity: 0.4;
                   color: #c0c4cc;
                   &:hover {
                       color: #c0c4cc;
                   }
               }
               
               &:hover:not(.active):not(.disabled) {
                 color: #303133;
               }
             }
          }
 
          .vg-right-actions {
           display: flex;
           align-items: center;
           gap: 8px;

           .vg-pill-btn {
            height: 32px;
            padding: 0 12px;
            border-radius: 16px;
            background: #f5f7fa;
            color: #606266;
            font-size: 12px;
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
            
            &:hover {
               background: #e6e8eb;
            }
            
            &.active {
               background: #e6f7ff;
               color: #409eff;
               border: 1px solid #b3e0ff;
            }
            
            &.model-selector, &.settings-selector {
               background: #fff;
               /* border: 1px solid #e4e7ed; */ /* Removed border to match clean look */
               font-weight: 500;
               
               &:hover {
                 background: #f5f7fa;
               }
            }
          }
          
          .vg-generate-btn {
             border-radius: 8px;
             padding: 8px 16px;
             font-weight: 500;
             display: flex;
             align-items: center;
             gap: 4px;
             background: #b0b8c1; 
             border-color: #b0b8c1;
             color: #fff;
             
             &:hover {
               background: #a0a8b1;
               border-color: #a0a8b1;
             }
          }
        }
      }
    }
  }
}

.context-menu {
  position: fixed;
  z-index: 9999;
  background: #fff;
  border-radius: 4px;
  box-shadow: 0 2px 12px rgba(0,0,0,0.1);
  padding: 4px 0;
  min-width: 120px;
  border: 1px solid #ebeef5;
  
  .menu-item {
    padding: 8px 16px;
    cursor: pointer;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #606266;
    transition: background 0.1s;
    
    &:hover {
      background: #f5f7fa;
      color: #409eff;
    }
    
    &.danger {
      color: #f56c6c;
      
      &:hover {
        background: #fef0f0;
      }
    }
  }
  
  .menu-divider {
    height: 1px;
    background: #ebeef5;
    margin: 4px 0;
  }
}
</style>

<style lang="scss">
.vg-settings-popper {
  padding: 16px !important;
  
  .video-settings-popover {
    .setting-row {
      margin-bottom: 16px;
      
      &:last-child {
        margin-bottom: 0;
      }
      
      .setting-label {
        font-size: 13px;
        color: #606266;
        margin-bottom: 8px;
        font-weight: 500;
      }
      
      .el-radio-group {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        
        .el-radio-button {
           margin-right: 0;
           
           .el-radio-button__inner {
             border-radius: 6px;
             border: 1px solid #dcdfe6;
             box-shadow: none !important;
             padding: 6px 12px;
             font-size: 12px;
             height: auto;
             line-height: 1.2;
           }
           
           &:first-child .el-radio-button__inner {
              border-radius: 6px;
              border-left: 1px solid #dcdfe6;
           }
           
           &:last-child .el-radio-button__inner {
              border-radius: 6px;
           }
           
           &.is-active .el-radio-button__inner {
              background-color: #ecf5ff;
              border-color: #409eff;
              color: #409eff;
           }
        }
      }
    }
    
    .no-config {
      text-align: center;
      color: #909399;
      font-size: 12px;
      padding: 10px 0;
    }
  }
}
</style>
