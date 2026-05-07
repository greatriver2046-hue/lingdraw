<template>
  <div class="chat-panel">
    <div class="chat-header">
      <h2>对话</h2>
      <div class="header-actions">
        <el-button 
          v-if="chatStore.currentConversationId" 
          type="danger" 
          link 
          @click="handleDelete"
          title="清空对话"
        >
          <el-icon><Delete /></el-icon>
        </el-button>
      </div>
    </div>
    
    <div class="messages-container" ref="messagesContainer">
      <div 
        v-for="msg in chatStore.messages" 
        :key="msg.id"
        class="message-item"
        v-show="shouldShowMessage(msg)"
        :class="{ 'is-user': msg.role === 'user' }"
      >
        
        <div class="message-content">
          <div class="sender-name" v-if="msg.role !== 'user' && !msg.thinking && !msg.isThinkingTrace">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="ai-icon">
              <path d="M16 4C16 9 19 12 23 12C19 12 16 15 16 20C16 15 13 12 9 12C13 12 16 9 16 4Z" fill="#1f1f1f" stroke="#1f1f1f" stroke-width="2.5" stroke-linejoin="round"/>
              <path d="M6 14C6 16.5 8 18 10 18C8 18 6 19.5 6 22C6 19.5 4 18 2 18C4 18 6 16.5 6 14Z" fill="#9CA3AF" stroke="#9CA3AF" stroke-width="2.5" stroke-linejoin="round"/>
              <path d="M6 5C6 5.8 6.8 6.5 8 6.5C6.8 6.5 6 7.2 6 8C6 7.2 5.2 6.5 4 6.5C5.2 6.5 6 5.8 6 5Z" fill="#D1D5DB" stroke="#D1D5DB" stroke-width="2.5" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="bubble" :class="{ thinking: msg.thinking && !msg.thinkingDone, 'streaming-msg': isStreamingMsg(msg), 'loading-bubble': (msg.thinking || msg.thinkingDone), 'thinking-done': msg.thinkingDone }" v-if="shouldShowBubble(msg) && !msg.isInsufficientPoints && !(msg.content && typeof msg.content === 'string' && msg.content.includes('点数不足'))">
            <template v-if="msg.thinking || msg.thinkingDone">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" :class="['ai-icon', 'processing-icon', { done: msg.thinkingDone }]">
                <path d="M16 4C16 9 19 12 23 12C19 12 16 15 16 20C16 15 13 12 9 12C13 12 16 9 16 4Z" fill="#1f1f1f" stroke="#1f1f1f" stroke-width="2.5" stroke-linejoin="round"/>
                <path d="M6 14C6 16.5 8 18 10 18C8 18 6 19.5 6 22C6 19.5 4 18 2 18C4 18 6 16.5 6 14Z" fill="#9CA3AF" stroke="#9CA3AF" stroke-width="2.5" stroke-linejoin="round"/>
                <path d="M6 5C6 5.8 6.8 6.5 8 6.5C6.8 6.5 6 7.2 6 8C6 7.2 5.2 6.5 4 6.5C5.2 6.5 6 5.8 6 5Z" fill="#D1D5DB" stroke="#D1D5DB" stroke-width="2.5" stroke-linejoin="round"/>
              </svg>
              <span class="processing-text">{{ msg.thinkingDone ? '思考结束' : '思考中' }}</span>
              <template v-if="!msg.thinkingDone">
                <span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>
              </template>
              <div v-if="msg.thinkingAction || (msg.thinkingSteps && msg.thinkingSteps.length)" class="thinking-trace-block">
                <div
                  class="thinking-action-row"
                  :class="{ clickable: msg.thinkingSteps && msg.thinkingSteps.length }"
                  role="button"
                  tabindex="0"
                  @click="(msg.thinkingSteps && msg.thinkingSteps.length) ? toggleThinkingSteps(msg) : null"
                  @keydown.enter="(msg.thinkingSteps && msg.thinkingSteps.length) ? toggleThinkingSteps(msg) : null"
                  @keydown.space.prevent="(msg.thinkingSteps && msg.thinkingSteps.length) ? toggleThinkingSteps(msg) : null"
                >
                  <button
                    v-if="msg.thinkingSteps && msg.thinkingSteps.length"
                    type="button"
                    class="thinking-steps-toggle-icon"
                    :class="{ collapsed: isThinkingStepsCollapsed(msg) }"
                    @click.stop="toggleThinkingSteps(msg)"
                  ></button>
                  <div v-if="msg.thinkingAction" class="thinking-action">{{ msg.thinkingAction }}</div>
                </div>
                <div
                  v-if="msg.thinkingSteps && msg.thinkingSteps.length"
                  class="thinking-steps"
                  :class="{ collapsed: isThinkingStepsCollapsed(msg) }"
                >
                  <div
                    v-for="it in getVisibleThinkingSteps(msg)"
                    :key="it.idx"
                    class="thinking-step"
                    :class="{ 'is-loading': isThinkingStepLoading(msg, it.idx), 'is-last-animated': isThinkingLastAnimated(msg, it.idx) }"
                  >
                    <span class="thinking-step-text">{{ it.text }}</span>
                    <span v-if="isThinkingStepLoading(msg, it.idx)" class="thinking-step-loading"><span class="dot">.</span><span class="dot">.</span><span class="dot">.</span></span>
                  </div>
                </div>
              </div>
            </template>
            <template v-else>
              <template v-for="(seg, idx) in getMessageSegments(msg.content)" :key="idx">
                <div v-if="seg.type === 'text'" v-html="renderMarkdown(seg.value)" class="markdown-body"></div>
                <el-image v-else :src="seg.value" :preview-src-list="[seg.value]" :hide-on-click-modal="true" fit="cover" class="inline-image" lazy />
              </template>
            </template>
          </div>
          <div v-if="msg.isInsufficientPoints || (msg.content && typeof msg.content === 'string' && msg.content.includes('点数不足'))" class="insufficient-points-card">
            <div class="card-icon">
              <el-icon><Warning /></el-icon>
            </div>
            <div class="card-info">
              <div class="card-title">点数不足</div>
              <div class="card-desc">{{ msg.content }}</div>
              <el-button type="primary" size="small" class="upgrade-btn" @click="appStore.openSubscriptionModal()">
                升级会员
              </el-button>
            </div>
          </div>
          <div v-if="msg.pendingTool" class="tool-result">
            <div class="image-card loading" :key="`pending-${msg.pendingTool.taskId}-${tick}`">
              <div class="image-skeleton" :style="getSkeletonStyle(msg.pendingTool)"></div>
              <div class="meta">
                <div class="name">{{ msg.pendingTool.imageName || '生成图像' }}</div>
                <div class="spec">
                  模型：{{ msg.pendingTool.modelName }} · {{ msg.pendingTool.statusText || '生成中…' }}
                </div>
                <div class="timeout-countdown">
                  {{ formatElapsed(msg.pendingTool.createdAt) }}
                </div>
              </div>
            </div>
          </div>
          <div v-if="msg.toolResult && msg.toolResult.video_url" class="tool-result">
            <div class="image-card">
              <video :src="msg.toolResult.video_url" controls style="width: 100%; height: auto; border-radius: 8px; object-fit: cover;"></video>
              <div class="meta">
                <div class="name">{{ msg.toolResult.image_name || '生成视频' }}</div>
              </div>
            </div>
          </div>
          <div v-if="msg.toolResult && msg.toolResult.image_url" class="tool-result">
            <div class="image-card">
              <el-image :src="getThumbUrl(msg.toolResult.image_url)" :preview-src-list="msg.toolResult.image_url ? [msg.toolResult.image_url] : []" :hide-on-click-modal="true" fit="contain" @load="onImageLoad(msg.id)" lazy />
              <div class="meta">
                <div class="name">{{ msg.toolResult.image_name || '生成图像' }}</div>
                <div class="spec">
                  <span v-if="isLoaded(msg.id) && msg.toolResult.resolution">{{ msg.toolResult.resolution }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="message-actions">
            <el-button 
              class="action-btn" 
              link 
              size="small" 
              @click="copyMessage(msg.content)"
              title="复制内容"
            >
              <el-icon><CopyDocument /></el-icon>
            </el-button>
            <el-button 
              class="action-btn" 
              link 
              size="small" 
              :disabled="msg.pendingTool || msg.thinking" 
              @click="confirmDeleteMessage(msg)"
              title="删除消息"
            >
              <el-icon><Delete /></el-icon>
            </el-button>
          </div>
        </div>

      </div>

      <div v-if="shouldShowInspirationCards" class="inspiration-empty-block">
        <div class="inspiration-cards">
          <template v-if="inspirationLoading">
            <div v-for="i in 3" :key="i" class="inspiration-card is-skeleton">
              <div class="inspiration-card-left">
                <div class="skeleton-line title"></div>
                <div class="skeleton-line desc"></div>
              </div>
              <div class="inspiration-card-right">
                <div class="skeleton-thumb"></div>
              </div>
            </div>
          </template>
          <template v-else>
            <div
              v-for="item in inspirationCards"
              :key="item.key"
              class="inspiration-card"
              role="button"
              tabindex="0"
              @click="applyInspiration(item)"
              @keydown.enter="applyInspiration(item)"
            >
              <div class="inspiration-card-left">
                <div class="inspiration-card-title" :title="item.title">{{ item.title }}</div>
                <div class="inspiration-card-desc" :title="item.description">{{ item.description }}</div>
              </div>
              <div class="inspiration-card-right">
                <div v-if="item.image" class="inspiration-thumb" :style="{ backgroundImage: `url(${item.image})` }"></div>
                <div v-else class="inspiration-thumb is-empty"></div>
              </div>
            </div>
          </template>
        </div>
        <div class="inspiration-empty-switch" role="button" tabindex="0" @click="refreshInspirationCards" @keydown.enter="refreshInspirationCards">
          <el-icon><Refresh /></el-icon>
          <span>切换</span>
        </div>
      </div>

      <div v-if="isProcessing" class="message-item">
        <div class="message-content">
          <div class="bubble loading-bubble">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="ai-icon processing-icon">
              <path d="M16 4C16 9 19 12 23 12C19 12 16 15 16 20C16 15 13 12 9 12C13 12 16 9 16 4Z" fill="#1f1f1f" stroke="#1f1f1f" stroke-width="2.5" stroke-linejoin="round"/>
              <path d="M6 14C6 16.5 8 18 10 18C8 18 6 19.5 6 22C6 19.5 4 18 2 18C4 18 6 16.5 6 14Z" fill="#9CA3AF" stroke="#9CA3AF" stroke-width="2.5" stroke-linejoin="round"/>
              <path d="M6 5C6 5.8 6.8 6.5 8 6.5C6.8 6.5 6 7.2 6 8C6 7.2 5.2 6.5 4 6.5C5.2 6.5 6 5.8 6 5Z" fill="#D1D5DB" stroke="#D1D5DB" stroke-width="2.5" stroke-linejoin="round"/>
            </svg>
            <span class="processing-text">任务进行中</span>
            <span class="dot">.</span><span class="dot">.</span><span class="dot">.</span>
          </div>
        </div>
      </div>
      <div ref="bottomAnchor" style="height: 1px; width: 100%"></div>
    </div>
    
    <div class="chat-input-area">
      <div class="prompt-container">
        <div class="reference-section">
          <div class="ref-items" v-if="generationStore.referenceImages.length">
            <div 
              v-for="(img, index) in generationStore.referenceImages" 
              :key="index" 
              class="ref-item"
            >
              <el-image :src="previewOfRef(img)" :preview-src-list="referencePreviewList" :initial-index="index" :hide-on-click-modal="true" fit="cover" lazy />
              <div class="remove-ref" @click.stop="generationStore.removeReferenceAt(index)">
                <el-icon><Close /></el-icon>
              </div>
            </div>
          </div>
          
          <el-upload
            class="ref-uploader"
            action="#"
            :http-request="({ file }) => generationStore.uploadReferences([file])"
            multiple
            :show-file-list="false"
            accept="image/*"
          >
            <div class="add-ref-btn" :class="{ 'has-items': generationStore.referenceImages.length }">
              <el-icon><Plus /></el-icon>
              <span v-if="!generationStore.referenceImages.length">添加参考图</span>
            </div>
          </el-upload>
        </div>

        <div 
          class="input-box-container" 
          :class="{ 'drag-over': isInputDragOver }"
          @dragenter="handleInputDragEnter"
          @dragleave="handleInputDragLeave"
          @dragover.prevent 
          @drop.prevent="handleInputDrop"
        >
          <div class="input-area">
            <!-- Rich Input Area -->
            <div 
              class="rich-input-wrapper"
              @click="focusRichInput"
            >
              <div
                ref="richInputRef"
                class="rich-input"
                contenteditable="true"
                :placeholder="isPolishEnabled ? '告诉我你的需求，我来帮你优化提示词' : '输入你的图片\\视频提示词'"
                @input="onRichInput"
                @paste="handleRichPaste"
                @keydown.enter.prevent="handleSend"
                @keydown.backspace="handleBackspace"
                @dragover.prevent
                @drop.prevent="handleInputDrop"
              ></div>
            </div>
          </div>
          <div class="controls-bar" ref="controlsBarRef">
            <div class="left-controls" ref="leftControlsRef">
              <el-dropdown trigger="click" @command="handleModelSelect" placement="top-start" max-height="300px">
                <span class="control-btn model-btn">
                  <el-icon class="btn-icon"><Cpu /></el-icon>
                  <span class="btn-label">{{ currentModelName }}</span>
                  <el-icon class="el-icon--right"><ArrowDown /></el-icon>
                </span>
                <template #dropdown>
                  <el-dropdown-menu>
                    <el-dropdown-item :command="'__auto__'">自动</el-dropdown-item>
                    <el-dropdown-item divided disabled>图片模型</el-dropdown-item>
                    <el-dropdown-item v-for="model in generationStore.imageModels" :key="model.model_identity" :command="model.model_identity">
                      {{ model.name || model.model_identity }}
                    </el-dropdown-item>
                    <el-dropdown-item divided disabled>视频模型</el-dropdown-item>
                    <el-dropdown-item v-for="model in generationStore.videoModels" :key="model.id" :command="model.model_identity || model.name">
                       <el-icon><VideoCamera /></el-icon>
                       {{ model.name || model.model_identity }}
                    </el-dropdown-item>
                  </el-dropdown-menu>
                </template>
              </el-dropdown>
              
              <div class="video-params-selector" v-if="(isVideoModelSelected && currentVideoModel) || (isImageModelSelected && currentImageModel)">
                <div style="display: flex; align-items: center; gap: 8px;">
                  <el-popover v-if="hasConfigurableParams" trigger="click" width="320" placement="top-start" popper-class="vg-settings-popper">
                     <template #reference>
                       <div class="vg-pill-btn settings-selector">
                         {{ currentSettingsLabel }}
                         <el-icon class="el-icon--right"><ArrowDown /></el-icon>
                       </div>
                     </template>
                     <div class="video-settings-popover">
                       <!-- Video Settings -->
                       <template v-if="isVideoModelSelected && currentVideoModel">
                         <div class="setting-row" v-if="currentVideoModel.config?.aspect_ratios?.length">
                            <span class="label">画面比例</span>
                            <el-radio-group v-model="generationStore.videoAspectRatio" size="small">
                              <el-radio-button 
                                  v-for="opt in currentVideoModel.config.aspect_ratios" 
                                  :key="opt.value" 
                                  :label="opt.value"
                              >{{ opt.value }}</el-radio-button>
                            </el-radio-group>
                          </div>
                          <div class="setting-row" v-if="currentVideoModel.config?.durations?.length">
                            <span class="label">视频时长</span>
                            <el-radio-group v-model="generationStore.videoDuration" size="small">
                              <el-radio-button 
                                  v-for="opt in currentVideoModel.config.durations" 
                                  :key="opt.value" 
                                  :label="opt.value"
                              >{{ opt.value }}</el-radio-button>
                            </el-radio-group>
                          </div>
                          <div class="setting-row" v-if="currentVideoModel.config?.resolutions?.length">
                            <span class="label">分辨率</span>
                            <el-radio-group v-model="generationStore.videoResolution" size="small">
                              <el-radio-button 
                                  v-for="opt in currentVideoModel.config.resolutions" 
                                  :key="opt.value" 
                                  :label="opt.value"
                              >{{ opt.value }}</el-radio-button>
                            </el-radio-group>
                          </div>
                       </template>

                       <!-- Image Settings -->
                       <template v-if="isImageModelSelected && currentImageModel">
                          <div class="setting-row" v-if="currentResolutionConfig.length">
                            <span class="label">分辨率</span>
                            <el-radio-group v-model="generationStore.imageResolution" size="small">
                              <el-radio-button
                                v-for="opt in currentResolutionConfig"
                                :key="opt.value"
                                :label="opt.value"
                              >
                                {{ opt.value }}
                              </el-radio-button>
                            </el-radio-group>
                          </div>
                       </template>
                     </div>
                  </el-popover>
                  
                  <span v-if="isImageModelSelected && estimatedPoints > 0" class="points-badge" style="display: none;">
                    {{ estimatedPoints }}积分
                  </span>
                </div>
              </div>
            </div>
            <div class="right-controls" ref="rightControlsRef">
              <el-tooltip
                effect="dark"
                :content="isPolishEnabled ? '开启后自动优化提示词' : '关闭后直接发送提示词'"
                placement="top"
              >
                <el-switch
                   v-model="isPolishEnabled"
                   class="polish-switch"
                   inline-prompt
                   active-text="优化"
                   inactive-text="原始"
                   width="55"
                 />
              </el-tooltip>
              <el-button
                class="send-btn"
                circle
                @click="handleSend"
                :disabled="!inputValue.trim() || chatStore.isLoading"
              >
                <div v-if="estimatedPoints > 0" class="send-btn-content">
                  <span class="cost-tag">
                     <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor">
                       <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                     </svg>
                     {{ estimatedPoints }}
                  </span>
                  <el-icon><Top /></el-icon>
                </div>
                <el-icon v-else><Top /></el-icon>
              </el-button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <el-popover
    v-model:visible="editMarkerVisible"
    :virtual-ref="editMarkerAnchorEl"
    virtual-triggering
    trigger="manual"
    placement="top-start"
    width="360"
    popper-class="marker-edit-popper"
  >
    <div class="marker-edit-panel" @mousedown.stop @click.stop>
      <div v-if="editingMarkerSuggestions.length > 0" class="marker-edit-list">
        <div
          v-for="(s, idx) in editingMarkerSuggestions"
          :key="idx"
          class="marker-edit-item"
          :class="{ active: idx === editMarkerSuggestionIndex }"
          role="button"
          tabindex="0"
          @click="selectMarkerSuggestion(idx)"
          @keydown.enter="selectMarkerSuggestion(idx)"
        >
          <div
            class="marker-edit-thumb"
            :class="{ 'is-empty': !s.thumbnail }"
            :style="s.thumbnail ? { backgroundImage: `url(${s.thumbnail})` } : {}"
          ></div>
          <div class="marker-edit-label">{{ s.label }}</div>
          <span v-if="idx === editMarkerSuggestionIndex" class="marker-edit-check"></span>
        </div>
      </div>
      <div class="marker-edit-custom">
        <el-input
          v-model="editMarkerContent"
          class="marker-edit-custom-input"
          size="small"
          placeholder="自定义"
          @focus="activateCustomMarkerContent"
          @blur="applyCustomMarkerContent"
          @input="onEditMarkerContentInput"
          @keyup.enter="applyCustomMarkerContent"
        />
      </div>
    </div>
  </el-popover>
</template>

<script setup>
import { ref, nextTick, watch, computed, onMounted, onUnmounted } from 'vue'
import { useChatStore } from '@/stores/chat'
import { useGenerationStore } from '@/stores/generation'
import { useAppStore } from '@/stores/app'
import { User, Service, Loading, Cpu, ArrowDown, ArrowUp, Plus, Close, Delete, VideoPlay, VideoCamera, MagicStick, CopyDocument, Warning, Picture, FullScreen, Timer, Refresh } from '@element-plus/icons-vue'
import { ElMessageBox, ElMessage } from 'element-plus'
import MarkdownIt from 'markdown-it'
import { config } from '@/config'
import request from '@/utils/request'

const loadImage = (src) => new Promise((resolve, reject) => {
  const img = new Image()
  img.onload = () => resolve(img)
  img.onerror = reject
  img.src = src
})

const resolveImageResolution = async (u) => {
  if (!u) return null
  try {
    const img = await loadImage(u)
    return { w: img.naturalWidth || img.width || 0, h: img.naturalHeight || img.height || 0 }
  } catch {
    try {
      const proxy = `${config.API_BASE_URL}/api/proxy/image?url=` + encodeURIComponent(u)
      const img = await loadImage(proxy)
      return { w: img.naturalWidth || img.width || 0, h: img.naturalHeight || img.height || 0 }
    } catch {
      return null
    }
  }
}

const emit = defineEmits(['request-width-expand'])

const md = new MarkdownIt({
  html: false,
  breaks: true,
  linkify: true
})

const renderMarkdown = (text) => {
  if (!text) return ''
  let html = md.render(text)
  
  // Replace marker text with styled chips
  // Pattern: [标记点N：Content] or [标记点N: Content]
  html = html.replace(/\[标记点(\d+)[：:]\s*(.*?)\]/g, (match, numStr, content) => {
    const num = parseInt(numStr)
    const marker = generationStore.markerPrompts.find(m => m.number === num)
    
    let thumbnailHtml = ''
    if (marker && marker.thumbnail) {
      thumbnailHtml = `<img src="${marker.thumbnail}" class="chip-thumbnail" loading="lazy" decoding="async" />`
    }
    
    return `<span class="marker-chip">
      <span class="chip-icon">${num}</span>
      ${thumbnailHtml}
      <span class="chip-content">${content}</span>
    </span>`
  })
  
  return html
}

const chatStore = useChatStore()
const generationStore = useGenerationStore()
const appStore = useAppStore()
const inputValue = ref('')
const messagesContainer = ref(null)
const bottomAnchor = ref(null)

const thinkingStepsCollapsed = ref({})

const isThinkingStepsCollapsed = (msg) => {
  const id = msg?.id
  const key = id !== undefined && id !== null ? String(id) : ''
  if (key && Object.prototype.hasOwnProperty.call(thinkingStepsCollapsed.value, key)) {
    return !!thinkingStepsCollapsed.value[key]
  }
  return true
}
const isThinkingStepLoading = (msg, idx) => {
  if (!msg) return false
  if (msg.thinkingDone) return false
  if (!msg.thinking) return false
  const li = typeof msg.thinkingLoadingIndex === 'number' ? msg.thinkingLoadingIndex : -1
  return li === idx
}
const toggleThinkingSteps = (msgOrId) => {
  const id = (msgOrId && typeof msgOrId === 'object') ? msgOrId.id : msgOrId
  const key = id !== undefined && id !== null ? String(id) : ''
  if (!key) return
  const defaultCollapsed = true
  const current = Object.prototype.hasOwnProperty.call(thinkingStepsCollapsed.value, key)
    ? !!thinkingStepsCollapsed.value[key]
    : defaultCollapsed
  thinkingStepsCollapsed.value = {
    ...thinkingStepsCollapsed.value,
    [key]: !current
  }
}

const getVisibleThinkingSteps = (msg) => {
  const steps = (msg && Array.isArray(msg.thinkingSteps)) ? msg.thinkingSteps : []
  const mapped = steps.map((s, idx) => ({ text: s, idx }))
  if (mapped.length <= 3) return mapped
  if (isThinkingStepsCollapsed(msg)) return mapped.slice(-3)
  return mapped
}

const isThinkingLastAnimated = (msg, idx) => {
  if (!msg) return false
  if (!msg.thinking) return false
  if (msg.thinkingDone) return false
  const steps = (msg && Array.isArray(msg.thinkingSteps)) ? msg.thinkingSteps : []
  if (steps.length === 0) return false
  return idx === steps.length - 1
}

const isProcessing = computed(() => {
  // If any message is in "thinking" state, do not show the global processing indicator
  if (chatStore.messages && chatStore.messages.some(m => m.thinking)) {
    return false
  }
  return chatStore.isLoading || (chatStore.messages && chatStore.messages.some(m => m.pendingTool))
})

const hasUserMessage = computed(() => {
  const msgs = chatStore.messages || []
  return msgs.some(m => m && m.role === 'user' && String(m.content || '').trim())
})

const shouldShowInspirationCards = computed(() => {
  if (isProcessing.value) return false
  if (chatStore.isStreaming) return false
  return !hasUserMessage.value
})

const inspirationCandidates = ref([])
const inspirationCards = ref([])
const inspirationLoading = ref(false)

const normalizeInspiration = (raw) => {
  const id = raw?.id ?? raw?.inspiration_id ?? ''
  const title = String(raw?.title || raw?.name || '灵感示例')
  const description = String(raw?.description || raw?.summary || raw?.tags || '').trim()
  const image = String(raw?.image || raw?.cover || raw?.thumb || '')
  const prompt = String(raw?.prompt_content || raw?.prompt || raw?.prompt_text || '').trim()
  const key = String(id || (title + '|' + prompt) || Math.random())
  return { id, key, title, description, image, prompt }
}

const pickRandom = (arr, count) => {
  const items = Array.isArray(arr) ? arr.slice() : []
  for (let i = items.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    const tmp = items[i]
    items[i] = items[j]
    items[j] = tmp
  }
  return items.slice(0, Math.max(0, Math.min(count, items.length)))
}

const fetchInspirationCandidates = async () => {
  inspirationLoading.value = true
  try {
    const res = await request.get('/api/v1/inspiration/list', {
      params: { page: 1, page_size: 60, category_id: '', sort: 'recommend' }
    })
    const json = res?.data
    if (json?.code === 200) {
      const list = json?.data?.data
      const rawItems = Array.isArray(list) ? list : (Array.isArray(json?.data) ? json.data : [])
      inspirationCandidates.value = rawItems.map(normalizeInspiration).filter(it => it && it.prompt)
    } else {
      inspirationCandidates.value = []
    }
  } catch {
    inspirationCandidates.value = []
  } finally {
    inspirationLoading.value = false
  }
}

const refreshInspirationCards = async () => {
  if (!shouldShowInspirationCards.value) return
  if (inspirationLoading.value) return
  if (!Array.isArray(inspirationCandidates.value) || inspirationCandidates.value.length < 3) {
    await fetchInspirationCandidates()
  }
  inspirationCards.value = pickRandom(inspirationCandidates.value, 3)
}

const applyInspiration = (item) => {
  const text = String(item?.prompt || '').trim()
  if (!text) {
    ElMessage.warning('该灵感没有提示词')
    return
  }
  generationStore.prompt = text
  nextTick(() => {
    focusRichInput()
  })
}

watch(
  () => shouldShowInspirationCards.value,
  (show) => {
    if (show) refreshInspirationCards()
  },
  { immediate: true }
)

// Auto-expand logic for toolbar
const controlsBarRef = ref(null)
const leftControlsRef = ref(null)
const rightControlsRef = ref(null)
let resizeObserver = null

const checkToolbarWidth = () => {
  if (!controlsBarRef.value || !leftControlsRef.value || !rightControlsRef.value) return
  
  const containerW = controlsBarRef.value.offsetWidth
  // use scrollWidth to ensure we capture full width even if wrapped/hidden
  const leftW = leftControlsRef.value.scrollWidth 
  const rightW = rightControlsRef.value.scrollWidth
  const minGap = 20
  const padding = 60 // Parent padding
  
  if (leftW + rightW + minGap > containerW) {
     emit('request-width-expand', leftW + rightW + minGap + padding)
  }
}

onMounted(() => {
    if (controlsBarRef.value) {
        resizeObserver = new ResizeObserver(() => {
            checkToolbarWidth()
        })
        resizeObserver.observe(controlsBarRef.value)
        // Also observe children in case they resize without container resizing
        if (leftControlsRef.value) resizeObserver.observe(leftControlsRef.value)
        if (rightControlsRef.value) resizeObserver.observe(rightControlsRef.value)
        
        // Check once initially
        checkToolbarWidth()
    }
    scrollToBottom()
    resetComposer()
    window.addEventListener('click', handleGlobalClickForMarkerEdit)
})

onUnmounted(() => {
    clearDraftFor(chatStore.currentConversationId)
    resetComposer()
    if (resizeObserver) resizeObserver.disconnect()
    window.removeEventListener('click', handleGlobalClickForMarkerEdit)
    if (editMarkerContentSyncTimer) clearTimeout(editMarkerContentSyncTimer)
})

const handleDelete = () => {
  if (!chatStore.currentConversationId) return
  ElMessageBox.confirm(
    '确定要清空当前对话的聊天记录吗？',
    '清空确认',
    {
      confirmButtonText: '清空',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(() => {
      chatStore.clearConversationMessages(chatStore.currentConversationId)
    })
    .catch(() => {})
}

const confirmDeleteMessage = (msg) => {
  ElMessageBox.confirm(
    msg && msg.isThinkingTrace ? '确定要删除该思考步骤吗？' : '确定要删除此消息吗？',
    msg && msg.isThinkingTrace ? '删除思考步骤' : '删除确认',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(() => {
      if (msg && msg.isThinkingTrace) {
        chatStore.deleteThinking(msg.id)
      } else {
        chatStore.deleteMessage(msg.id)
      }
    })
    .catch(() => {})
}

const copyMessage = (content) => {
  if (!content) return
  let text = content
  const rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：'
  if (text.startsWith(rawPrefix)) {
    text = text.substring(rawPrefix.length)
  }
  navigator.clipboard.writeText(text).then(() => {
    ElMessage.success('复制成功')
  }).catch(() => {
    ElMessage.error('复制失败')
  })
}

const localPreviewMap = new WeakMap()
const previewOfRef = (item) => {
  if (typeof item === 'string') return item
  if (item instanceof File) {
    let url = localPreviewMap.get(item)
    if (!url) {
      url = URL.createObjectURL(item)
      localPreviewMap.set(item, url)
    }
    return url
  }
  return ''
}

const currentModelName = computed(() => {
  const imgModel = generationStore.imageModels.find(m => m.model_identity === generationStore.selectedImageModel)
  if (imgModel) return imgModel.name || imgModel.model_identity
  
  const vidModel = generationStore.videoModels.find(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
  if (vidModel) return vidModel.name || vidModel.model_identity
  
  return generationStore.selectedImageModel ? generationStore.selectedImageModel : '自动'
})

const isVideoModelSelected = computed(() => {
  return generationStore.videoModels.some(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
})

const currentVideoModel = computed(() => {
  return generationStore.videoModels.find(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
})

const isImageModelSelected = computed(() => {
  return generationStore.imageModels.some(m => m.model_identity === generationStore.selectedImageModel)
})

const currentImageModel = computed(() => {
  return generationStore.imageModels.find(m => m.model_identity === generationStore.selectedImageModel)
})

const estimatedPoints = computed(() => {
  let cost = 0
  
  // Image Model Cost
  if (isImageModelSelected.value && currentImageModel.value) {
    const model = currentImageModel.value
    // Base cost
    if (model.cost_per_request) {
      cost += parseInt(model.cost_per_request)
    }
    
    // Resolution cost
    if (generationStore.imageResolution) {
      const config = currentResolutionConfig.value
      const resConfig = config.find(c => c.value === generationStore.imageResolution)
      if (resConfig && resConfig.price) {
        cost += parseInt(resConfig.price)
      }
    }
  }

  // Video Model Cost
  if (isVideoModelSelected.value && currentVideoModel.value) {
    const model = currentVideoModel.value
    // Base cost
    if (model.cost_per_request) {
      cost += parseInt(model.cost_per_request)
    }
    
    const config = model.config || {}
    
    // Aspect Ratio Cost
    if (generationStore.videoAspectRatio && config.aspect_ratios) {
       const opt = config.aspect_ratios.find(o => o.value === generationStore.videoAspectRatio)
       if (opt && opt.price) cost += parseInt(opt.price)
    }

    // Duration Cost
    if (generationStore.videoDuration && config.durations) {
       const opt = config.durations.find(o => o.value === generationStore.videoDuration)
       if (opt && opt.price) cost += parseInt(opt.price)
    }

    // Resolution Cost
    if (generationStore.videoResolution && config.resolutions) {
       const opt = config.resolutions.find(o => o.value === generationStore.videoResolution)
       if (opt && opt.price) cost += parseInt(opt.price)
    }
  }
  
  return cost
})

const currentSettingsLabel = computed(() => {
  const vidModel = currentVideoModel.value
  if (vidModel) {
    const parts = []
    const config = vidModel.config || {}
    
    if (config.aspect_ratios && config.aspect_ratios.length > 0) {
        parts.push(generationStore.videoAspectRatio || '16:9')
    }

    if (config.durations && config.durations.length > 0) {
        parts.push(generationStore.videoDuration || '5s')
    }

    if (config.resolutions && config.resolutions.length > 0) {
        parts.push(generationStore.videoResolution || '720P')
    }
    
    if (parts.length === 0) return '配置'

    return parts.join(' · ')
  }

  const imgModel = currentImageModel.value
  if (imgModel) {
    if (generationStore.imageResolution) {
      return generationStore.imageResolution
    }
  }

  return '配置'
})

const currentResolutionConfig = computed(() => {
  const model = currentImageModel.value
  if (!model || !model.resolution_config) return []
  
  let config = model.resolution_config
  if (typeof config === 'string') {
    try {
      config = JSON.parse(config)
    } catch (e) {
      return []
    }
  }
  
  if (Array.isArray(config)) return config
   if (typeof config === 'object') return Object.values(config)
   return []
 })

 const hasConfigurableParams = computed(() => {
  if (isVideoModelSelected.value && currentVideoModel.value) {
    const config = currentVideoModel.value.config || {}
    return (config.aspect_ratios && config.aspect_ratios.length > 0) || 
           (config.durations && config.durations.length > 0) || 
           (config.resolutions && config.resolutions.length > 0)
  }
  
  if (isImageModelSelected.value && currentImageModel.value) {
    return currentResolutionConfig.value.length > 0
  }
  
  return false
})
 
 // Watch for content changes that might affect toolbar width
 watch(
  [currentModelName, currentSettingsLabel],
  () => {
    nextTick(() => {
      checkToolbarWidth()
    })
  }
)

const referencePreviewList = computed(() => generationStore.referenceImages.map(previewOfRef).filter(u => !!u))

const tick = ref(0)
let tickTimer = null
onMounted(() => {
  generationStore.fetchImageModels()
  generationStore.fetchVideoModels()
  tickTimer = setInterval(() => { tick.value++ }, 1000)
})
onUnmounted(() => {
  if (tickTimer) { clearInterval(tickTimer); tickTimer = null }
})

// 计算已用时间并格式化（正计时）
const formatElapsed = (createdAt) => {
  if (!createdAt) return '00:00'
  const elapsed = Math.floor((Date.now() - createdAt) / 1000)
  const minutes = Math.floor(elapsed / 60)
  const seconds = elapsed % 60
  return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
}

const scrollToBottom = () => {
  const doScroll = () => {
    if (bottomAnchor.value) {
      bottomAnchor.value.scrollIntoView({ behavior: 'smooth', block: 'end' })
    } else if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight + 500
    }
  }
  nextTick(doScroll)
  // Double check scroll after a short delay to account for layout shifts
  setTimeout(doScroll, 100)
}

const isPolishEnabled = ref(localStorage.getItem('app_chat_polish_enabled') !== 'false') // Default to true if not set
watch(isPolishEnabled, (val) => {
  localStorage.setItem('app_chat_polish_enabled', val)
})

// Sync inputValue with generationStore.prompt
watch(
  () => generationStore.prompt,
  (newVal) => {
    if (newVal !== undefined && newVal !== '') {
      inputValue.value = newVal
      if (richInputRef.value) {
        richInputRef.value.innerText = newVal
      }
      // Reset store prompt so next update is detected even if text is same
      generationStore.prompt = ''
    }
  }
)

watch(
  () => chatStore.messages,
  () => {
    scrollToBottom()
  },
  { deep: true }
)

const editMarkerVisible = ref(false)
const editMarkerContent = ref('')
const currentEditingMarkerNum = ref(null)
const editMarkerSuggestionIndex = ref(0)
const editMarkerAnchorEl = ref(null)
let editMarkerContentSyncTimer = null

const currentEditingMarker = computed(() => {
  if (currentEditingMarkerNum.value === null) return null
  return (generationStore.markerPrompts || []).find(m => m.number === currentEditingMarkerNum.value) || null
})

const editingMarkerSuggestions = computed(() => {
  const m = currentEditingMarker.value
  return (m && Array.isArray(m.suggestions)) ? m.suggestions : []
})

// --- Rich Input Logic ---
const richInputRef = ref(null)
const insertedMarkerIds = ref(new Set())

const draftKey = (conversationId) => `chat_input_draft_${conversationId ? String(conversationId) : 'none'}`

const appendTextWithLineBreaks = (container, text) => {
  if (!container) return
  const parts = String(text || '').split('\n')
  for (let i = 0; i < parts.length; i++) {
    if (parts[i]) container.appendChild(document.createTextNode(parts[i]))
    if (i < parts.length - 1) container.appendChild(document.createElement('br'))
  }
}

const serializeDraft = () => {
  if (!richInputRef.value) return null

  const segments = []
  const usedMarkerIds = new Set()

  const walk = (node, insertNewlineBefore = false) => {
    if (!node) return
    if (insertNewlineBefore) segments.push({ t: 'br' })

    if (node.nodeType === Node.TEXT_NODE) {
      const txt = node.textContent || ''
      const prev = segments[segments.length - 1]
      if (txt === '\u00A0' && prev && prev.t === 'marker') return
      if (txt) segments.push({ t: 'text', v: txt })
      return
    }

    if (node.nodeType !== Node.ELEMENT_NODE) return

    const el = node
    if (el.classList && el.classList.contains('marker-chip')) {
      const id = Number(el.dataset.id)
      const number = Number(el.dataset.number)
      if (!isNaN(id) && !isNaN(number)) {
        usedMarkerIds.add(id)
        segments.push({ t: 'marker', id, number })
      }
      return
    }

    if (el.nodeName === 'BR') {
      segments.push({ t: 'br' })
      return
    }

    const isDiv = el.nodeName === 'DIV'
    const children = Array.from(el.childNodes || [])
    children.forEach((child, idx) => walk(child, isDiv && idx === 0))
  }

  Array.from(richInputRef.value.childNodes || []).forEach(node => walk(node, false))

  const markerPrompts = (generationStore.markerPrompts || [])
    .filter(m => m && usedMarkerIds.has(m.id))
    .map(m => ({
      id: m.id,
      number: m.number,
      content: m.content,
      status: m.status,
      thumbnail: m.thumbnail,
      referenceImageUrl: m.referenceImageUrl,
      elementSrc: m.elementSrc
    }))

  const plain = segments
    .map(s => {
      if (s.t === 'text') return s.v
      if (s.t === 'br') return '\n'
      if (s.t === 'marker') return `[标记点${s.number}]`
      return ''
    })
    .join('')

  if (!plain.trim() && markerPrompts.length === 0) return null

  return { v: 2, segments, markerPrompts }
}

const restoreDraft = (draft) => {
  if (!richInputRef.value) return

  if (!draft) return

  if (typeof draft === 'string') {
    inputValue.value = draft
    richInputRef.value.innerText = draft
    insertedMarkerIds.value = new Set()
    triggerInput()
    return
  }

  if (typeof draft !== 'object' || draft.v !== 2 || !Array.isArray(draft.segments)) return

  richInputRef.value.innerHTML = ''
  insertedMarkerIds.value = new Set()

  generationStore.clearMarkerPrompts()
  if (Array.isArray(draft.markerPrompts)) {
    insertedMarkerIds.value = new Set(
      draft.markerPrompts
        .map(m => (m ? Number(m.id) : NaN))
        .filter(id => !isNaN(id))
    )
    draft.markerPrompts.forEach(m => {
      if (m && typeof m.number === 'number') {
        generationStore.addMarkerPrompt({ ...m })
      }
    })
  }

  draft.segments.forEach(seg => {
    if (seg.t === 'text') {
      appendTextWithLineBreaks(richInputRef.value, seg.v)
      return
    }
    if (seg.t === 'br') {
      richInputRef.value.appendChild(document.createElement('br'))
      return
    }
    if (seg.t === 'marker') {
      const marker = (generationStore.markerPrompts || []).find(m => m && m.id === seg.id) ||
        (generationStore.markerPrompts || []).find(m => m && m.number === seg.number) ||
        { id: seg.id, number: seg.number, content: '', status: 'done' }
      insertMarkerChip(marker)
    }
  })

  triggerInput()
}

const saveDraftFor = (conversationId) => {
  const key = draftKey(conversationId)
  const draft = serializeDraft()
  if (draft) {
    localStorage.setItem(key, JSON.stringify(draft))
  } else {
    localStorage.removeItem(key)
  }
}

const loadDraftFor = (conversationId) => {
  const key = draftKey(conversationId)
  const raw = localStorage.getItem(key)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw)
    if (parsed && typeof parsed === 'object') return parsed
  } catch {}
  return raw
}

const clearDraftFor = (conversationId) => {
  const key = draftKey(conversationId)
  try {
    localStorage.removeItem(key)
  } catch {}
}

const resetComposer = () => {
  inputValue.value = ''
  if (richInputRef.value) {
    richInputRef.value.innerHTML = ''
  }
  insertedMarkerIds.value = new Set()
  editMarkerVisible.value = false
  editMarkerContent.value = ''
  currentEditingMarkerNum.value = null
  generationStore.referenceImages = []
  generationStore.clearMarkerPrompts()
  try {
    localStorage.removeItem('app_marker_prompts')
  } catch {}
}

watch(
  () => chatStore.currentConversationId,
  (newVal, oldVal) => {
    if (String(newVal || '') !== String(oldVal || '')) {
      clearDraftFor(oldVal)
      clearDraftFor(newVal)
      resetComposer()
    }
  }
)

const focusRichInput = () => {
  if (richInputRef.value) richInputRef.value.focus()
}

const handleRichPaste = (e) => {
  e.preventDefault()
  const text = e.clipboardData.getData('text/plain')
  if (text) {
    document.execCommand('insertText', false, text)
  }
}

const handleBackspace = (e) => {
  const selection = window.getSelection()
  if (!selection.rangeCount) return
  
  const range = selection.getRangeAt(0)
  if (!range.collapsed) return // Allow default selection deletion
  
  let prevNode = null
  
  // Case: Cursor in Text Node at offset 0
  if (range.startContainer.nodeType === Node.TEXT_NODE && range.startOffset === 0) {
    prevNode = range.startContainer.previousSibling
  } 
  // Case: Cursor in Element (div) at offset > 0
  else if (range.startContainer.nodeType === Node.ELEMENT_NODE && range.startOffset > 0) {
    prevNode = range.startContainer.childNodes[range.startOffset - 1]
  }
  
  // If previous node is a marker chip, delete it
  if (prevNode && prevNode.nodeType === Node.ELEMENT_NODE && prevNode.classList.contains('marker-chip')) {
    e.preventDefault()
    prevNode.remove()
    onRichInput() // Sync store
  }
}

const onRichInput = () => {
  triggerInput()
  checkDeletedMarkers()
}

const checkDeletedMarkers = () => {
  if (!richInputRef.value) return
  
  const currentIds = new Set()
  const chips = richInputRef.value.querySelectorAll('.marker-chip')
  chips.forEach(chip => {
    if (chip.dataset.id) currentIds.add(parseInt(chip.dataset.id))
  })
  
  // Find markers that are in store/insertedIds but not in DOM
  // generationStore.markerPrompts is the source of truth
  // We need to be careful not to remove markers that haven't been inserted yet (if any),
  // but insertedMarkerIds tracks what we HAVE inserted.
  
  const idsToRemove = []
  
  for (const id of insertedMarkerIds.value) {
    if (!currentIds.has(id)) {
       idsToRemove.push(id)
    }
  }
  
  idsToRemove.forEach(id => {
      const marker = generationStore.markerPrompts.find(m => m.id === id)
      if (marker) {
          generationStore.removeMarkerPrompt(marker.number)
      }
      insertedMarkerIds.value.delete(id)
  })
}

const triggerInput = () => {
  if (richInputRef.value) {
    inputValue.value = richInputRef.value.innerText
  }
}

// Watch markers to update rich input
watch(() => generationStore.markerPrompts, (newMarkers) => {
  if (!richInputRef.value) return

  // 1. Add new markers
  newMarkers.forEach(m => {
    if (!insertedMarkerIds.value.has(m.id)) {
      insertMarkerChip(m)
      insertedMarkerIds.value.add(m.id)
    } else {
      // 2. Update existing
      updateMarkerChipDOM(m)
    }
  })

  // 3. Remove deleted
  const currentIds = new Set(newMarkers.map(m => m.id))
  for (const id of insertedMarkerIds.value) {
    if (!currentIds.has(id)) {
      removeMarkerChipDOM(id)
      insertedMarkerIds.value.delete(id)
    }
  }
}, { deep: true })

const insertMarkerChip = (marker) => {
  const chip = document.createElement('span')
  chip.className = 'marker-chip'
  chip.contentEditable = 'false'
  chip.dataset.id = marker.id
  chip.dataset.number = marker.number
  
  let thumbnailHtml = ''
  if (marker.thumbnail) {
    thumbnailHtml = `<img src="${marker.thumbnail}" class="chip-thumbnail" loading="lazy" decoding="async" />`
  }

  chip.innerHTML = `
    <span class="chip-icon">${marker.number}</span>
    ${thumbnailHtml}
    <span class="chip-content">${marker.status === 'loading' ? '识别中...' : (marker.content || '无内容')}</span>
  `
  
  chip.addEventListener('click', (e) => {
    e.stopPropagation()
    editMarkerAnchorEl.value = chip
    handleEditMarker(marker)
  })
  
  richInputRef.value.appendChild(chip)
  richInputRef.value.appendChild(document.createTextNode('\u00A0')) // Space
  
  richInputRef.value.scrollTop = richInputRef.value.scrollHeight
  triggerInput()
}

const updateMarkerChipDOM = (marker) => {
  const chip = richInputRef.value.querySelector(`.marker-chip[data-id="${marker.id}"]`)
  if (chip) {
    // Update content
    const contentSpan = chip.querySelector('.chip-content')
    if (contentSpan) {
      const newText = marker.status === 'loading' ? '识别中...' : (marker.content || '无内容')
      if (contentSpan.innerText !== newText) {
        contentSpan.innerText = newText
        triggerInput()
      }
    }
    
    // Update thumbnail if needed
    if (marker.thumbnail) {
        let img = chip.querySelector('.chip-thumbnail')
        if (!img) {
            // Insert after icon
            const icon = chip.querySelector('.chip-icon')
            img = document.createElement('img')
            img.className = 'chip-thumbnail'
            img.src = marker.thumbnail
            img.loading = 'lazy'
            img.decoding = 'async'
            if (icon) {
                icon.after(img)
            } else {
                chip.prepend(img)
            }
        } else if (img.src !== marker.thumbnail) {
            img.src = marker.thumbnail
        }
    }
  }
}

const removeMarkerChipDOM = (id) => {
  const chip = richInputRef.value.querySelector(`.marker-chip[data-id="${id}"]`)
  if (chip) {
    chip.remove()
    triggerInput()
  }
}

const handleRemoveMarker = (marker) => {
  generationStore.removeMarkerPrompt(marker.number)
}

const handleEditMarker = (marker) => {
  if (marker.status === 'loading') return
  currentEditingMarkerNum.value = marker.number
  editMarkerContent.value = marker.content
  const suggestions = Array.isArray(marker.suggestions) ? marker.suggestions : []
  const rawSelected = Number(marker.selectedSuggestionIndex)
  if (Number.isFinite(rawSelected) && rawSelected >= 0 && rawSelected < suggestions.length) {
    editMarkerSuggestionIndex.value = rawSelected
  } else {
    const content = typeof marker.content === 'string' ? marker.content.trim() : ''
    const matchIdx = suggestions.findIndex(s => typeof s?.label === 'string' && s.label.trim() === content)
    editMarkerSuggestionIndex.value = matchIdx >= 0 ? matchIdx : -1
  }
  editMarkerVisible.value = true
}

const selectMarkerSuggestion = (idx) => {
  const marker = currentEditingMarker.value
  if (!marker) return
  const suggestions = Array.isArray(marker.suggestions) ? marker.suggestions : []
  const i = Number(idx)
  if (!Number.isFinite(i) || i < 0 || i >= suggestions.length) return

  const selected = suggestions[i]
  const label = typeof selected?.label === 'string' ? selected.label.trim() : ''
  if (!label) return

  editMarkerSuggestionIndex.value = i
  editMarkerContent.value = label

  const patch = {
    content: label,
    selectedSuggestionIndex: i,
    kind: selected?.kind
  }
  if (selected?.thumbnail) {
    patch.thumbnail = selected.thumbnail
  }

  generationStore.updateMarkerPromptDetails(marker.number, patch)
}

const syncEditingContentToStore = () => {
  const marker = currentEditingMarker.value
  if (!marker) return
  const raw = String(editMarkerContent.value || '')
  const content = raw.trim()
  if (!content) return

  const suggestions = Array.isArray(marker.suggestions) ? marker.suggestions : []
  const matchIdx = suggestions.findIndex(s => typeof s?.label === 'string' && s.label.trim() === content)

  const patch = {
    content,
    selectedSuggestionIndex: matchIdx >= 0 ? matchIdx : -1
  }
  generationStore.updateMarkerPromptDetails(marker.number, patch)
}

const onEditMarkerContentInput = () => {
  editMarkerSuggestionIndex.value = -1
  if (editMarkerContentSyncTimer) clearTimeout(editMarkerContentSyncTimer)
  editMarkerContentSyncTimer = setTimeout(() => {
    editMarkerContentSyncTimer = null
    syncEditingContentToStore()
  }, 80)
}

const activateCustomMarkerContent = () => {
  editMarkerSuggestionIndex.value = -1
}

const applyCustomMarkerContent = () => {
  const marker = currentEditingMarker.value
  if (!marker) return
  const raw = String(editMarkerContent.value || '')
  const content = raw.trim()
  if (!content) return

  generationStore.updateMarkerPromptDetails(marker.number, {
    content,
    selectedSuggestionIndex: -1
  })
}

const handleGlobalClickForMarkerEdit = (e) => {
  if (!editMarkerVisible.value) return
  const target = e?.target
  if (!target) return
  const popper = document.querySelector('.marker-edit-popper')
  if (popper && popper.contains(target)) return
  if (editMarkerAnchorEl.value && editMarkerAnchorEl.value.contains(target)) return
  editMarkerVisible.value = false
}

const handleSend = async () => {
  if (!richInputRef.value) return
  
  // Upload markers reference images (layers)
  await generationStore.uploadMarkerReferenceImages()
  await generationStore.uploadMarkerThumbnails() // Keep thumbnails for UI consistency if needed

  // Collect all markers used in the input to identify unique reference images
  const usedMarkers = []
  const nodes = richInputRef.value.childNodes
  
  nodes.forEach(node => {
      if (node.nodeType === Node.ELEMENT_NODE && node.classList.contains('marker-chip')) {
          const id = parseInt(node.dataset.id)
          const marker = generationStore.markerPrompts.find(m => m.id === id)
          if (marker && marker.status !== 'loading') {
              usedMarkers.push(marker)
          }
      }
  })

  // Identify unique reference images
  const refImages = [] // { url: string, index: number, resolution: string }
  const refMap = new Map() // url -> index (1-based)
  
  for (const m of usedMarkers) {
      const url = m.referenceImageUrl
      if (url && !refMap.has(url)) {
          const idx = refImages.length + 1
          refMap.set(url, idx)
          
          let resolution = ''
          try {
             const dim = await resolveImageResolution(url)
             if (dim && dim.w && dim.h) {
                resolution = `(${dim.w}x${dim.h})`
             }
          } catch (e) { /* ignore */ }

          refImages.push({ url, index: idx, resolution })
      }
  }

  // Build prefix
  let prefix = ''
  if (refImages.length > 0) {
      prefix = refImages.map(r => `参考图${r.index}${r.resolution}：${r.url}`).join('\n') + '\n\n'
  }

  // Parse content
  let finalPrompt = ''
  
  nodes.forEach(node => {
      if (node.nodeType === Node.TEXT_NODE) {
          finalPrompt += node.textContent
      } else if (node.nodeType === Node.ELEMENT_NODE && node.classList.contains('marker-chip')) {
          const id = parseInt(node.dataset.id)
          const number = parseInt(node.dataset.number)
          const marker = generationStore.markerPrompts.find(m => m.id === id)
          
          if (marker) {
              if (marker.status !== 'loading') {
                  const url = marker.referenceImageUrl
                  const refIdx = refMap.get(url)
                  
                  if (refIdx) {
                      finalPrompt += `参考图${refIdx}[标记点${number}：${marker.content}]`
                  } else {
                      finalPrompt += `[标记点${number}：${marker.content}]`
                  }
              } else {
                   finalPrompt += `[标记点${number}]`
              }
          }
      } else if (node.nodeName === 'BR') {
           finalPrompt += '\n'
      } else if (node.nodeName === 'DIV') {
           finalPrompt += '\n' + node.textContent
      } else {
           finalPrompt += node.textContent
      }
  })
  
  if (prefix) {
      finalPrompt = prefix + finalPrompt
  }
  
  if (!finalPrompt.trim()) return

  // Apply "Raw Mode" prefix if polish is disabled
  if (!isPolishEnabled.value) {
    finalPrompt = '直接将以下内容作为prompt传入工具不要做任何解析修改：' + finalPrompt
  }

  // Pass true for polish enabled to bypass old logic if needed, or keep as is.
  // The user said "cancel the original logic". 
  // If we pass isPolishEnabled.value (false), the backend might still do something specific for "false".
  // But now we want to force raw mode via prompt prefix.
  // Let's assume the backend handles the prompt as is.
  // We still pass isPolishEnabled.value just in case, but the prefix is key.
  // Actually, user said "cancel the original logic". 
  // If original logic was "false -> no polish", now "false -> add prefix".
  // So we modify finalPrompt.
  
  chatStore.sendMessage(finalPrompt, isPolishEnabled.value)
  
  // Clear input
  richInputRef.value.innerHTML = ''
  inputValue.value = ''
  saveDraftFor(chatStore.currentConversationId)
  
  // Clear markers from canvas and store
  generationStore.clearMarkerPrompts()
  
  scrollToBottom()
}

const handleModelSelect = (command) => {
  const id = command === '__auto__' ? '' : command
  generationStore.setImageModel(id)

  const imgModel = generationStore.imageModels.find(m => m.model_identity === id)
  if (imgModel) {
     // Use helper logic or access raw config if needed, but here we can just do simple parse check
     // Or rely on user to pick. But auto-selecting first option is nice.
     // Re-implementing parsing logic briefly here or we could move parsing to store/model level.
     let config = imgModel.resolution_config || []
     if (typeof config === 'string') {
        try { config = JSON.parse(config) } catch(e) {}
     }
     if (config && typeof config === 'object' && !Array.isArray(config)) {
        config = Object.values(config)
     }
     
     if (Array.isArray(config) && config.length > 0) {
       generationStore.imageResolution = config[0].value
     } else {
       generationStore.imageResolution = ''
     }
  } else {
     generationStore.imageResolution = ''
  }

  const vidModel = generationStore.videoModels.find(m => (m.name === id || m.model_identity === id))
  if (vidModel && vidModel.config) {
    if (vidModel.config.aspect_ratios?.length) {
      generationStore.videoAspectRatio = vidModel.config.aspect_ratios[0].value
    }
    if (vidModel.config.durations?.length) {
      generationStore.videoDuration = vidModel.config.durations[0].value
    }
    if (vidModel.config.resolutions?.length) {
      generationStore.videoResolution = vidModel.config.resolutions[0].value
    }
  }
}

const isInputDragOver = ref(false)
const dragEnterCount = ref(0)
const handleInputDragEnter = () => {
  dragEnterCount.value += 1
  isInputDragOver.value = true
}
const handleInputDragLeave = () => {
  dragEnterCount.value = Math.max(0, dragEnterCount.value - 1)
  if (dragEnterCount.value === 0) isInputDragOver.value = false
}

const handleInputDrop = (event) => {
  const dt = event.dataTransfer
  let url = ''
  try {
    url = dt.getData('text/uri-list') || dt.getData('text/plain') || ''
  } catch (e) { void e }
  if (url) {
    if (!generationStore.referenceImages.includes(url)) {
      generationStore.referenceImages.push(url)
    }
    dragEnterCount.value = 0
    isInputDragOver.value = false
    return
  }
  const files = dt.files ? Array.from(dt.files) : []
  if (files.length) {
    generationStore.uploadReferences(files)
  }
  dragEnterCount.value = 0
  isInputDragOver.value = false
}

const handleInputPaste = (event) => {
  const dt = event.clipboardData
  if (!dt) return
  const files = []
  if (dt.items && dt.items.length) {
    for (const item of dt.items) {
      if (item.kind === 'file' && item.type && item.type.startsWith('image/')) {
        const f = item.getAsFile()
        if (f) files.push(f)
      }
    }
  } else if (dt.files && dt.files.length) {
    for (const f of dt.files) {
      if (f.type && f.type.startsWith('image/')) files.push(f)
    }
  }
  if (files.length) {
    event.preventDefault()
    generationStore.uploadReferences(files)
    return
  }
  let txt = ''
  try {
    txt = dt.getData('text/plain') || ''
  } catch (e) { void e }
  const maybeUrl = txt && /^(https?:\/\/|data:image)/i.test(txt) ? txt : ''
  if (maybeUrl) {
    event.preventDefault()
    if (!generationStore.referenceImages.includes(maybeUrl)) {
      generationStore.referenceImages.push(maybeUrl)
    }
  }
}

// 模型选择即可，无需风格和尺寸控件

const openImage = (url) => {
  if (!url) return
  window.open(url, '_blank')
}

const getSkeletonStyle = (pending) => {
  const cardW = 220
  let w = 0
  let h = 0
  if (pending && pending.width && pending.height) { w = pending.width; h = pending.height }
  else if (pending && typeof pending.aspectRatio === 'string') {
    const p = pending.aspectRatio.split(':')
    if (p.length === 2) { w = parseInt(p[0]) || 1; h = parseInt(p[1]) || 1 }
  }
  if (!w || !h) { w = 1; h = 1 }
  const heightPx = Math.round(cardW * (h / w))
  return { height: heightPx + 'px' }
}

const isStreamingMsg = (msg) => {
  if (!chatStore.isStreaming) return false
  if (msg.role !== 'assistant') return false
  const lastMsg = chatStore.messages[chatStore.messages.length - 1]
  return lastMsg && lastMsg.id === msg.id
}

const shouldShowBubble = (msg) => {
  if (msg.thinking) return true
  if (msg.thinkingDone) return true
  if (msg.content && typeof msg.content === 'string' && msg.content.trim().length > 0) return true
  return false
}

const shouldShowMessage = (msg) => {
  if (msg.role === 'tool' || msg.role === 'system') return false
  if (msg.role === 'user') return true
  if (msg.isInsufficientPoints) return true
  if (shouldShowBubble(msg)) return true
  if (msg.pendingTool) return true
  if (msg.toolResult) return true
  return false
}

const getMessageSegments = (content) => {
  let text = typeof content === 'string' ? content : ''
  
  // Hide thinking process (DeepSeek/GLM)
  // 1. Remove complete think blocks
  text = text.replace(/<think>[\s\S]*?<\/think>/gi, '')
  // 2. Remove unclosed think block at the end (streaming)
  text = text.replace(/<think>[\s\S]*$/i, '')
  
  // Hide resolution info in UI: （参考图1分辨率1920x1080，...）
  text = text.replace(/\n?（参考图\d+分辨率\d+x\d+.*?）/g, '')
  
  // Hide the raw mode prefix from UI
  const rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：'
  if (text.startsWith(rawPrefix)) {
    text = text.substring(rawPrefix.length)
  }

  // 排除常见的中文标点符号，防止URL粘连
  const regex = /(https?:\/\/[^\s`，。！？（）【】《》：；“”‘’]+)/gi
  const segments = []
  let lastIndex = 0
  let match
  const isImageUrl = (u) => {
    if (!u || typeof u !== 'string') return false
    if (/^data:image\//i.test(u)) return true
    if (/\.(png|jpe?g|webp|gif|bmp|svg)(\?.*)?$/i.test(u)) return true
    if (/(\/storage\/|\/refs\/|\/generated\/)/i.test(u)) return true
    return false
  }
  while ((match = regex.exec(text)) !== null) {
    const url = match[1]
    const start = match.index
    const end = regex.lastIndex
    if (start > lastIndex) {
      segments.push({ type: 'text', value: text.slice(lastIndex, start) })
    }
    const val = url.replace(/^`|`$/g, '')
    const t = isImageUrl(val) ? 'image' : 'text'
    segments.push({ type: t, value: val })
    lastIndex = end
  }
  if (lastIndex < text.length) {
    segments.push({ type: 'text', value: text.slice(lastIndex) })
  }
  return segments
}

const loadedSet = ref(new Set())
const onImageLoad = (id) => {
  loadedSet.value.add(id)
}
const isLoaded = (id) => {
  return loadedSet.value.has(id)
}

const getThumbUrl = (url) => {
  if (!url) return ''
  const u = String(url)
  if (u.includes('x-oss-process=style/w400')) return u
  return u.includes('?') ? `${u}&x-oss-process=style/w400` : `${u}?x-oss-process=style/w400`
}

watch(() => chatStore.messages, scrollToBottom, { deep: true })
watch(() => chatStore.streamTicker, scrollToBottom)
watch(() => chatStore.currentConversationId, scrollToBottom)
watch(isProcessing, (newVal, oldVal) => {
  scrollToBottom()
  // When processing ends (indicator hidden), ensure we scroll to bottom
  // Retry a few times to handle layout shifts/animations
  if (!newVal && oldVal) {
    setTimeout(scrollToBottom, 200)
    setTimeout(scrollToBottom, 500)
  }
})
</script>

<style lang="scss">
.marker-chip {
  display: inline-flex;
  align-items: center;
  background: #e6f4ff;
  border: 1px solid #91caff;
  border-radius: 16px;
  padding: 2px 8px;
  margin: 0 4px;
  font-size: 12px;
  color: #1967d2;
  user-select: none;
  cursor: pointer;
  vertical-align: middle;
  gap: 6px;
  
  .chip-icon {
    font-weight: bold;
    background: #1967d2;
    color: white;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
  }

  .chip-thumbnail {
    width: 24px;
    height: 24px;
    border-radius: 4px;
    object-fit: cover;
    border: 1px solid #dcdfe6;
  }
}

.model-selector-popover {
  padding: 8px !important;
  border-radius: 12px !important;

  .model-list {
    display: flex;
    flex-direction: column;
    padding: 0;
  }

  .model-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    cursor: pointer;
    transition: background-color 0.2s;
    color: #1f1f1f;
    border-radius: 8px;
    margin-bottom: 2px;
    
    &:hover {
      background-color: #f5f5f5;
    }
    
    &.active {
      background-color: #f0f7ff;
      color: #1967d2;
      
      .model-name {
        font-weight: 500;
      }
      
      .model-icon {
        color: #1967d2;
      }
    }
  }
  
  .model-info {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    flex: 1;
  }

  .model-icon {
    font-size: 18px;
    color: #5f6368;
    flex-shrink: 0;
  }
  
  .model-name {
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  
  .check-icon {
    font-size: 16px;
    color: #1967d2;
  }
}
</style>

<style scoped lang="scss">
.chat-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  background-color: #fff;
  font-family: 'Roboto', sans-serif;
  
  .model-btn-wrapper {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .chat-header {
    padding: 16px 24px;
    border-bottom: 1px solid #f0f0f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    position: relative;
    overflow: visible;
    
    h2 {
      margin: 0;
      font-size: 16px;
      font-weight: 500;
      color: #1f1f1f;
    }

    .model-selector {
      display: flex;
      align-items: center;
    }
  }

  .launch-bar {
    background-color: #f8faff;
    border-bottom: 1px dashed #eef2f6;
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in-out;
    opacity: 0;
    
    &.is-open {
      max-height: 120px;
      opacity: 1;
      border-bottom: 1px dashed #dce5f4;
    }

    .launch-bar-content {
      padding: 12px 24px;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .launch-bar-header {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      font-weight: 500;
      color: #1967d2;
    }

    .launch-bar-body {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 12px;
      color: #5f6368;
    }

    .launch-hint {
      color: #80868b;
    }
  }
  
  .processing-icon {
    overflow: visible;
    path:nth-child(1) {
       transform-origin: center;
       transform-box: fill-box;
       animation: star-rotate-scale 2s ease-in-out infinite;
    }
  }

  .processing-icon.done {
    path:nth-child(1) {
      animation: none;
    }
  }

  @keyframes star-rotate-scale {
    0% { transform: scale(1) rotate(0deg); }
    50% { transform: scale(1.2) rotate(180deg); }
    100% { transform: scale(1) rotate(360deg); }
  }


  
  .messages-container {
    flex: 1;
    overflow-y: auto;
    padding: 24px 24px 120px 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    
    .message-item {
      display: flex;
      gap: 12px;
      max-width: 90%;
      animation: slideIn 0.3s cubic-bezier(0.25, 0.8, 0.5, 1);
      
      /* Make sure the last message is visible */
      &:last-child {
        margin-bottom: 24px;
      }
      
      &.is-user {
        align-self: flex-end;
        justify-content: flex-end;
        
        .message-content {
          align-items: flex-end;

          .bubble {
            background-color: #f2f2f2;
            color: #1f1f1f;
            padding: 10px 12px;
            border-radius: 10px;
          }

          .message-actions {
            justify-content: flex-end;
          }
        }
      }
      
      .message-avatar {
        flex-shrink: 0;
        margin-top: 4px;
        
        .avatar-circle {
          width: 28px;
          height: 28px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 14px;
          
          &.ai {
            background-color: transparent;
          }
          
          &.user {
            background-color: #1967d2;
            color: #fff;
          }
        }
      }
    }
    
    .insufficient-points-card {
      display: flex;
      gap: 12px;
      padding: 16px;
      background-color: #fff8e6;
      border: 1px solid #ffe0b2;
      border-radius: 12px;
      max-width: 450px;
      
      .card-icon {
        flex-shrink: 0;
        color: #f59e0b;
        font-size: 24px;
        margin-top: 2px;
      }
      
      .card-info {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 8px;
        
        .card-title {
          font-size: 14px;
          font-weight: 600;
          color: #92400e;
          line-height: 1.4;
        }
        
        .card-desc {
          font-size: 13px;
          color: #b45309;
          line-height: 1.5;
        }
        
        .upgrade-btn {
          align-self: flex-start;
          margin-top: 4px;
          background-color: #f59e0b;
          border-color: #f59e0b;
          
          &:hover {
            background-color: #d97706;
            border-color: #d97706;
          }
        }
      }
    }

    .inspiration-empty-block {
      display: flex;
      flex-direction: column;
      gap: 12px;
      max-width: 100%;
      padding: 0;

      .inspiration-empty-header {
        display: flex;
        align-items: center;
        justify-content: space-between;

        .inspiration-empty-title {
          font-size: 14px;
          font-weight: 600;
          color: #3c4043;
        }
      }

      .inspiration-cards {
        display: flex;
        flex-direction: column;
        gap: 10px;
      }

      .inspiration-card {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 16px;
        border-radius: 12px;
        background: #f5f7fa;
        border: none;
        cursor: pointer;
        transition: box-shadow 0.15s ease, transform 0.15s ease;

        &:hover {
          box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
          transform: translateY(-1px);
        }

        &.is-skeleton {
          cursor: default;
          transform: none;
          box-shadow: none;

          &:hover {
            box-shadow: none;
            transform: none;
          }
        }

        .inspiration-card-left {
          flex: 1;
          min-width: 0;
          display: flex;
          flex-direction: column;
          gap: 6px;
        }

        .inspiration-card-title {
          font-size: 15px;
          font-weight: 600;
          color: #1f1f1f;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .inspiration-card-desc {
          font-size: 11px;
          color: #80868b;
          overflow: hidden;
          text-overflow: ellipsis;
          white-space: nowrap;
        }

        .inspiration-card-right {
          flex-shrink: 0;
        }

        .inspiration-thumb,
        .skeleton-thumb {
          width: 86px;
          height: 60px;
          border-radius: 10px;
          background-color: #f3f4f6;
          background-size: cover;
          background-position: center;
          border: none;
        }

        .inspiration-thumb.is-empty {
          background: linear-gradient(135deg, #f3f4f6 0%, #eef2ff 100%);
        }

        .skeleton-line {
          height: 12px;
          border-radius: 8px;
          background: linear-gradient(90deg, #f3f3f3 25%, #eaeaea 37%, #f3f3f3 63%);
          background-size: 400% 100%;
          animation: shimmer 2.4s ease-in-out infinite;

          &.title {
            width: 56%;
            height: 14px;
          }

          &.desc {
            width: 80%;
          }
        }
      }

      .inspiration-empty-switch {
        align-self: flex-start;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #5f6368;
        cursor: pointer;
        user-select: none;
        padding: 6px 10px;
        border-radius: 10px;

        &:hover {
          background: rgba(0, 0, 0, 0.04);
          color: #1f1f1f;
        }
      }
    }
  }

      .message-content {
        display: flex;
        flex-direction: column;
        min-width: 0;
        
        .sender-name {
          font-size: 12px;
          color: #5f6368;
          margin-bottom: 4px;
          display: flex;
          align-items: center;
          gap: 6px;
          height: 20px;
        }

        .bubble {
          padding: 12px 16px;
          background-color: #f8f9fa;
          border-radius: 0 12px 12px 12px;
          color: #3c4043;
          font-size: 14px;
          line-height: 1.6;
          position: relative;
          word-break: break-word;
          
          &.thinking {
             color: #6b7280;
             font-style: italic;
          }

          &.streaming-msg {
            .markdown-body > *:last-child::after {
              content: '';
              display: inline-block;
              width: 8px;
              height: 15px;
              background-color: #1f1f1f;
              animation: blink 1s step-end infinite;
              vertical-align: text-bottom;
              margin-left: 2px;
            }
          }
        }

        .message-actions {
          display: flex;
          align-items: center;
          gap: 2px;
          opacity: 0;
          transition: opacity 0.2s ease;
          margin-top: 2px;
          height: 24px;
          
          .action-btn {
            padding: 4px;
            height: 24px;
            width: 24px;
            color: #9ca3af;
            
            &:hover {
              color: #1f1f1f;
              background-color: rgba(0,0,0,0.05);
            }
          }
        }
        
        &:hover .message-actions {
          opacity: 1;
        }
      }
      
      .message-content {
        display: flex;
        flex-direction: column;
        gap: 8px;
        position: relative;
        
        .sender-name {
          font-size: 12px;
          font-weight: 500;
          color: #444746;
          margin-left: 0;
          display: flex;
          align-items: center;

          .ai-icon {
            color: #1f1f1f;
          }
        }
        
          .bubble {
            background-color: transparent;
            color: #1f1f1f;
            font-size: 14px;
            line-height: 1.6;
            padding: 0;
            /* white-space: pre-wrap; REMOVED for markdown compatibility */
            word-break: break-word;

            &.thinking {
              animation: pulse 1.8s ease-in-out infinite;
            }

          &.loading-bubble {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
            padding: 12px 0;
            
            .processing-text {
                font-size: 12px;
                font-weight: 700;
                color: #1f1f1f;
                margin-right: 2px;
                line-height: 16px;
             }

            .dot {
              animation: bounce 1.4s infinite ease-in-out both;
              font-size: 16px;
              line-height: 16px;
            }
            
            .dot:nth-child(1) { animation-delay: -0.32s; }
            .dot:nth-child(2) { animation-delay: -0.16s; }

            &.thinking-done {
              .dot {
                animation: none;
              }
            }

            .thinking-action {
              flex: 1;
              font-size: 12px;
              color: #6b7280;
              line-height: 16px;
              font-weight: 500;
              letter-spacing: 0.2px;
              min-width: 0;
              word-break: break-word;
            }

            .thinking-steps {
              width: 100%;
              margin-top: 2px;
              padding-top: 8px;
              display: flex;
              flex-direction: column;
              gap: 6px;
              font-size: 12px;
              color: #4b5563;
              line-height: 16px;
              white-space: pre-wrap;
              border-top: 1px solid rgba(0, 0, 0, 0.06);
            }

            .thinking-trace-block {
              width: 100%;
              margin-top: 6px;
              display: flex;
              flex-direction: column;
              gap: 6px;
              padding-left: 10px;
              border-left: 2px solid rgba(0, 0, 0, 0.08);
            }

            .thinking-action-row {
              width: 100%;
              display: flex;
              align-items: center;
              justify-content: flex-start;
              gap: 8px;

              &.clickable {
                cursor: pointer;
                user-select: none;
              }
            }

            .thinking-steps-toggle-icon {
              flex: none;
              padding: 0;
              border: none;
              background: transparent;
              cursor: pointer;
              width: 18px;
              height: 18px;
              display: inline-flex;
              align-items: center;
              justify-content: center;
              transition: transform 0.15s ease;

              &::before {
                content: '';
                width: 7px;
                height: 7px;
                border-right: 2px solid rgba(55, 65, 81, 0.7);
                border-bottom: 2px solid rgba(55, 65, 81, 0.7);
                transform: rotate(-135deg);
                transform-origin: center;
                transition: transform 0.15s ease;
              }

              &.collapsed::before {
                transform: rotate(45deg);
              }

              &:hover {
                &::before {
                  border-right-color: rgba(17, 24, 39, 0.85);
                  border-bottom-color: rgba(17, 24, 39, 0.85);
                }
              }

              &:active {
                transform: translateY(1px);
              }
            }

            .thinking-step {
              width: 100%;
              word-break: break-word;
              position: relative;
              padding-left: 16px;

              &::before {
                content: '·';
                position: absolute;
                left: 0;
                top: 0;
                width: 10px;
                text-align: center;
                color: rgba(107, 114, 128, 0.9);
                font-variant-numeric: tabular-nums;
              }
              
              .thinking-step-loading {
                margin-left: 2px;
              }

              &.is-last-animated {
                .thinking-step-text {
                  display: inline-block;
                  animation: thinking-step-wave 1.2s linear infinite;
                  -webkit-mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0.28) 0%, rgba(0, 0, 0, 1) 18%, rgba(0, 0, 0, 0.28) 36%, rgba(0, 0, 0, 0.28) 100%);
                  mask-image: linear-gradient(90deg, rgba(0, 0, 0, 0.28) 0%, rgba(0, 0, 0, 1) 18%, rgba(0, 0, 0, 0.28) 36%, rgba(0, 0, 0, 0.28) 100%);
                  -webkit-mask-size: 220% 100%;
                  mask-size: 220% 100%;
                  -webkit-mask-position: 220% 0;
                  mask-position: 220% 0;
                }
              }
            }
          }
        }
        .tool-result {
          margin-top: 8px;
            .image-card {
            width: 220px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            overflow: hidden;
            cursor: pointer;
            background: #fff;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            &.loading {
              animation: pulse 1.8s ease-in-out infinite;
              cursor: default;
            }
            .el-image { width: 100%; }
            :deep(.el-image__inner) { width: 100%; height: auto; object-fit: cover; display: block; }
            .image-skeleton {
              width: 100%;
              height: 140px;
              background: linear-gradient(90deg, #f3f3f3 25%, #eaeaea 37%, #f3f3f3 63%);
              background-size: 400% 100%;
              animation: shimmer 2.4s ease-in-out infinite;
            }
            .meta {
              padding: 8px 10px;
              .name { font-size: 13px; color: #1f1f1f; }
              .spec { font-size: 12px; color: #5f6368; }
              .timeout-countdown {
                font-size: 11px;
                color: #909399;
                margin-top: 2px;
                font-family: monospace;
              }
            }
          }
        }
        
        .msg-delete {
          position: absolute;
          top: 0;
          right: 0;
          opacity: 0;
          transition: opacity 0.2s;
        }
      }
      
      &:hover .msg-delete {
        opacity: 1;
      }
    }
  
.chat-input-area {
    padding: 24px;

    .prompt-container {
      display: flex;
      flex-direction: column;
      gap: 8px;

      .reference-section {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 0;

        .ref-items {
          display: flex;
          gap: 8px;
          flex-wrap: wrap;
          
          .ref-item {
            position: relative;
            width: 48px;
            height: 48px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #e0e0e0;
            background: #fff;
            
            .el-image { width: 100%; height: 100%; }
            :deep(.el-image__inner) { width: 100%; height: 100%; object-fit: cover; display: block; }
            
            .remove-ref {
              position: absolute;
              top: 0;
              right: 0;
              width: 16px;
              height: 16px;
              background: rgba(0,0,0,0.5);
              color: #fff;
              display: flex;
              align-items: center;
              justify-content: center;
              cursor: pointer;
              font-size: 10px;
              opacity: 0;
              transition: opacity 0.2s;
            }
            
            &:hover .remove-ref {
              opacity: 1;
            }
          }
        }

        .ref-uploader {
          display: flex;
          
          .add-ref-btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            color: #606266;
            cursor: pointer;
            background: #f5f7fa;
            transition: background 0.2s;
            border: 1px solid transparent;
            
            &:hover {
              background: #e6e8eb;
              color: #303133;
            }
            
            &.has-items {
              width: 48px;
              height: 48px;
              padding: 0;
              justify-content: center;
              border: 1px dashed #dcdfe6;
              background: #fff;
              
              &:hover {
                border-color: #409eff;
                color: #409eff;
              }
            }
          }
        }
      }

        .input-box-container {
          position: relative;
          background: #ffffff;
          border-radius: 16px;
          padding: 8px 16px;
          box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
          border: 1px solid #e0e0e0;
          display: flex;
          flex-direction: column;
          gap: 8px;
          transition: border-color 0.15s ease, background-color 0.15s ease;

          &.drag-over {
            border-color: #409eff;
            background-color: #f0f7ff;
          }

        .input-area {
          display: flex;
          flex-direction: column;
          gap: 0;
          padding-top: 0;
          border: 1px solid transparent;
          border-radius: 12px;
          
          .marker-tags-area {
            padding: 8px 0 8px 0;
            margin-bottom: 0;
            border-bottom: 1px solid #f0f0f0;
            width: 100%;
            
            .tags-wrapper {
              display: flex;
              flex-wrap: nowrap;
              gap: 8px;
              padding: 2px 0;
            }
            
            .marker-tag {
              cursor: pointer;
              user-select: none;
              display: flex;
              align-items: center;
              
              .marker-idx {
                 display: inline-flex;
                 align-items: center;
                 justify-content: center;
                 width: 16px;
                 height: 16px;
                 background: rgba(0,0,0,0.1);
                 border-radius: 50%;
                 font-size: 10px;
                 margin-right: 6px;
                 color: inherit;
              }
              
              .tag-content {
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                display: inline-block;
                vertical-align: middle;
              }
              
              &:hover {
                opacity: 0.9;
              }
            }
          }

          .prompt-textarea {
            :deep(.el-textarea__inner) {
              box-shadow: none;
              border: none;
              padding: 0;
              font-size: 15px;
              color: #333;
              resize: none;
              background: transparent;
              &::placeholder { color: #a8abb2; }
              &:focus { box-shadow: none; }
            }
          }
        }

        .controls-bar {
          position: relative;
          display: flex;
          justify-content: space-between;
          align-items: center;
          padding-top: 8px;
          border-top: 1px solid #f0f0f0;
          margin-top: 4px;

          .left-controls {
            display: flex;
            gap: 12px;
            align-items: center;

            .control-btn {
              display: flex;
              align-items: center;
              gap: 6px;
              font-size: 13px;
              color: #606266;
              cursor: pointer;
              height: 32px;
              padding: 0 10px;
              box-sizing: border-box;
              border-radius: 6px;
              transition: background 0.2s;
              background: #f5f7fa;
              white-space: nowrap;
              line-height: 1;
            }
            .control-btn:hover { background: #e6e8eb; color: #303133; }
            .btn-icon { font-size: 14px; }
            .el-icon--right { font-size: 12px; margin-left: 2px; }
          }

          .right-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 5px;

            .polish-switch {
              --el-switch-on-color: #1967d2;
              --el-switch-off-color: #1f1f1f;

              :deep(.el-switch__core .el-switch__inner span) {
                font-size: 10px;
              }
            }

            .send-btn {
              width: auto;
              min-width: 32px;
              height: 32px;
              padding: 0 10px;
              border-radius: 16px;
              display: flex;
              align-items: center;
              justify-content: center;
              background-color: #1967d2;
              border: none;
              color: #fff;
              transition: all 0.2s ease;
              
              &:hover:not(:disabled) {
                background-color: #1557b0;
                transform: translateY(-1px);
                box-shadow: 0 2px 4px rgba(0,0,0,0.2);
              }
              
              &:disabled {
                background-color: #e3e3e3;
                color: #a8a8a8;
                cursor: not-allowed;
              }
              
              .send-btn-content {
                display: flex;
                align-items: center;
                gap: 6px;
                
                .cost-tag {
                  display: flex;
                  align-items: center;
                  gap: 2px;
                  font-size: 11px;
                  opacity: 0.9;
                  font-weight: 500;
                  background: rgba(255, 255, 255, 0.2);
                  padding: 1px 6px;
                  border-radius: 10px;
                  
                  .el-icon {
                    font-size: 11px;
                  }
                }
              }
            }
          }
        }
      }
    }
  }
  
  @keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }
  @keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
  }
  @keyframes bounce {
    0%, 80%, 100% { transform: scale(0); }
    40% { transform: scale(1); }
  }
  @keyframes pulse {
    0% { filter: brightness(100%); }
    50% { filter: brightness(92%); }
    100% { filter: brightness(100%); }
  }
  @keyframes thinking-step-wave {
    0% { -webkit-mask-position: 220% 0; mask-position: 220% 0; }
    100% { -webkit-mask-position: -220% 0; mask-position: -220% 0; }
  }
  @keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
  }
</style>
<style scoped lang="scss">
.inline-image {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  margin: 0 6px;
  vertical-align: middle;
}

/* Launch Bar CSS - REMOVED */

  .vg-pill-btn {
    display: flex;
    align-items: center;
    gap: 6px;
    background-color: #f5f7fa;
    border-radius: 6px;
    height: 32px;
    padding: 0 10px;
    box-sizing: border-box;
    font-size: 13px;
    color: #606266;
    cursor: pointer;
    transition: background 0.2s;
    white-space: nowrap;
    line-height: 1;
    
    &:hover {
      background: #e6e8eb;
      color: #303133;
    }
  }

  /* Popover CSS for global scope or deep selector */
  :deep(.vg-settings-popper) {
    padding: 12px !important;
  }
  
  .video-settings-popover {
    display: flex;
    flex-direction: column;
    gap: 12px;
    
    .setting-row {
      display: flex;
      flex-direction: column;
      gap: 6px;
      
      .label {
        font-size: 12px;
        color: #909399;
      }
    }
  }

  .markdown-body {
    font-size: 14px;
    line-height: 1.6;

    :deep(p) {
      margin: 0 0 10px 0;
      &:last-child { margin-bottom: 0; }
    }
    
    :deep(ul), :deep(ol) {
      margin: 0 0 10px 0;
      padding-left: 20px;
    }
    
    :deep(li) {
      margin-bottom: 4px;
    }
    
    :deep(h1), :deep(h2), :deep(h3), :deep(h4) {
      margin: 16px 0 8px 0;
      font-weight: 600;
      line-height: 1.4;
      font-size: 1.1em;
      
      &:first-child { margin-top: 0; }
    }
    
    :deep(blockquote) {
      margin: 10px 0;
      padding: 8px 12px;
      border-left: 4px solid #e0e0e0;
      background-color: #f9f9f9;
      color: #666;
    }
    
    :deep(code) {
      font-family: Consolas, Monaco, 'Andale Mono', monospace;
      background-color: rgba(0,0,0,0.05);
      padding: 2px 4px;
      border-radius: 4px;
    }
    
    :deep(pre) {
      background-color: #f6f8fa;
      padding: 12px;
      border-radius: 6px;
      overflow-x: auto;
      
      code {
        background-color: transparent;
        padding: 0;
      }
    }
    
    :deep(hr) {
      border: none;
      border-top: 1px solid #e0e0e0;
      margin: 16px 0;
    }
    
    :deep(a) {
      color: #1967d2;
      text-decoration: none;
      &:hover { text-decoration: underline; }   
    }
  }

  .rich-input-wrapper {
    width: 100%;
    border: none;
    background-color: transparent;
    cursor: text;
    margin-bottom: 0;
  }

  .rich-input {
    width: 100%;
    min-height: 60px;
    max-height: 150px;
    overflow-y: auto;
    padding: 0;
    font-size: 15px;
    line-height: 1.5;
    color: #333;
    outline: none;
    white-space: pre-wrap;
    word-break: break-word;
    
    &:empty::before {
      content: attr(placeholder);
      color: #a8abb2;
      pointer-events: none;
    }
  }

  :deep(.marker-chip) {
    display: inline-flex;
    align-items: center;
    background: #ecf5ff;
    border: 1px solid #d9ecff;
    color: #409eff;
    border-radius: 4px;
    padding: 0 4px;
    margin: 0 2px;
    font-size: 12px;
    cursor: pointer;
    user-select: none;
    vertical-align: middle;
    
    .chip-icon {
      display: inline-flex;
      justify-content: center;
      align-items: center;
      width: 16px;
      height: 16px;
      background: #409eff;
      color: white;
      border-radius: 50%;
      margin-right: 4px;
      font-size: 10px;
    }
    
    .chip-content {
      max-width: 150px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    
    &:hover {
      background: #d9ecff;
    }
  }

  :deep(.marker-edit-popper) {
    padding: 0 !important;
    border-radius: 16px !important;
    overflow: hidden;
  }

  .marker-edit-panel {
    padding: 14px 14px 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .marker-edit-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .marker-edit-item {
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 10px;
    padding: 10px 10px;
    cursor: pointer;
    user-select: none;
    outline: none;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid rgba(0, 0, 0, 0.04);

    &:hover {
      background: rgba(0, 0, 0, 0.04);
    }

    &.active {
      background: rgba(64, 158, 255, 0.12);
      border-color: rgba(64, 158, 255, 0.2);
    }
  }

  .marker-edit-thumb {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    background-size: cover;
    background-position: center;
    border: 1px solid rgba(0, 0, 0, 0.08);
    flex: 0 0 auto;

    &.is-empty {
      background: rgba(0, 0, 0, 0.06);
    }
  }

  .marker-edit-label {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 14px;
    color: #1f2328;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .marker-edit-check {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.08);
    position: relative;
    flex: 0 0 auto;

    &::after {
      content: '';
      position: absolute;
      left: 6px;
      top: 3px;
      width: 5px;
      height: 9px;
      border-right: 2px solid rgba(0, 0, 0, 0.55);
      border-bottom: 2px solid rgba(0, 0, 0, 0.55);
      transform: rotate(40deg);
    }
  }

  .marker-edit-custom {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .marker-edit-custom-input {
    flex: 1 1 auto;
    min-width: 0;
  }

  :deep(.marker-edit-custom-input .el-input__wrapper) {
    min-height: 40px;
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.02);
    border: 1px solid rgba(0, 0, 0, 0.04);
    box-shadow: none;
    padding: 0 12px;
  }

  :deep(.marker-edit-custom-input .el-input__wrapper:hover) {
    background: rgba(0, 0, 0, 0.04);
  }

  :deep(.marker-edit-custom-input .el-input__inner) {
    font-size: 14px;
  }
</style>
