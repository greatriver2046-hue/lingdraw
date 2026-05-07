<template>
  <div 
    class="canvas-element"
    :id="'element-' + element.id"
    v-show="!element.hidden"

    :class="{ 
      selected: isSelected,
      highlighted: isHighlighted,
      'is-group': element.type === 'group'
    }"
    :style="{ 
      left: element.x + 'px', 
      top: element.y + 'px', 
      width: element.width + 'px', 
      height: element.height + 'px',
      zIndex: element.zIndex,
      '--scale-factor': viewport.scale
    }"
    @mousedown.stop="$emit('element-mousedown', $event, element)"
    @contextmenu.stop.prevent="$emit('element-contextmenu', $event, element)"
    @dblclick.stop="handleElementDblClick"
  >
    <!-- Group Content -->
    <template v-if="element.type === 'group'">
      <CanvasNode 
        v-for="child in element.children" 
        :key="child.id" 
        :element="child"
        :selected-ids="selectedIds"
        :highlighted-id="highlightedId"
        :is-brush-mode="isBrushMode"
        :is-text-mode="isTextMode"
        :brush-size="brushSize"
        :brush-color="brushColor"
        :text-size="textSize"
        :text-color="textColor"
        :brush-colors="brushColors"
        :viewport="viewport"
        :generation-store="generationStore"
        :mask-canvas-refs="maskCanvasRefs"
        :drawing-box="drawingBox"
        @element-mousedown="(e, el) => $emit('element-mousedown', e, el)"
        @element-contextmenu="(e, el) => $emit('element-contextmenu', e, el)"
        @element-dblclick="(el) => $emit('element-dblclick', el)"
        @mask-mousedown="(e, el) => $emit('mask-mousedown', e, el)"
        @mask-mousemove="(e, el) => $emit('mask-mousemove', e, el)"
        @mask-mouseup="(e, el) => $emit('mask-mouseup', e, el)"
        @text-mousedown="(e, el, text) => $emit('text-mousedown', e, el, text)"
        @update-text="(e, el, text) => $emit('update-text', e, el, text)"
        @remove-text="(el, id) => $emit('remove-text', el, id)"
        @text-layer-mousedown="(e, el) => $emit('text-layer-mousedown', e, el)"
        @video-click="(e) => $emit('video-click', e)"
        @remove-element="(id) => $emit('remove-element', id)"
        @resize-start="(e, el, handle) => $emit('resize-start', e, el, handle)"
        @toggle-brush="(e) => $emit('toggle-brush', e)"
        @toggle-text="(e) => $emit('toggle-text', e)"
        @undo-stroke="(el) => $emit('undo-stroke', el)"
        @clear-mask="(el) => $emit('clear-mask', el)"
        @register-mask-canvas="(id, el) => $emit('register-mask-canvas', id, el)"
        @submit-ocr="(el) => $emit('submit-ocr', el)"
        @submit-watermark="(el) => $emit('submit-watermark', el)"
        @close-ocr="(el) => $emit('close-ocr', el)"
        @close-watermark="(el) => $emit('close-watermark', el)"
        @video-model-change="(cmd, el) => $emit('video-model-change', cmd, el)"
        @generate-video="(el) => $emit('generate-video', el)"
        @move-video="(el) => $emit('move-video', el)"
        @trigger-video-upload="(el, type) => $emit('trigger-video-upload', el, type)"
        @update-brush-size="(val) => $emit('update-brush-size', val)"
        @update-brush-color="(val) => $emit('update-brush-color', val)"
        @update-text-size="(val) => $emit('update-text-size', val)"
        @update-text-color="(val) => $emit('update-text-color', val)"
        @request-viewport-pan="(payload) => $emit('request-viewport-pan', payload)"
      />
    </template>

    <!-- Image Content -->
    <template v-else-if="element.type === 'image'">
      <img 
        :src="element.src" 
        class="element-content"
        loading="lazy"
        decoding="async"
        draggable="false"
        @load="handleImageLoaded"
        @error="handleImageErrored"
        :style="{ opacity: element.imageLoading === true ? 0 : 1 }"
      />

      <div v-if="element.imageLoading === true" class="image-placeholder" aria-hidden="true">
        <div class="image-placeholder-spinner"></div>
      </div>
      
      <!-- Loading Overlay -->
      <div v-if="element.matting || element.ocrLoading || element.ocrProcessing || element.watermarkProcessing || element.reversePrompting || element.upscaling" class="matting-overlay">
        <div class="matting-spinner"></div>
        <span class="matting-text">
          {{ element.matting ? '智能抠图中...' : (element.ocrLoading ? '文字识别中...' : (element.watermarkProcessing ? '水印去除中...' : (element.reversePrompting ? '正在解析...' : (element.upscaling ? '图片高清中...' : '图片生成中...')))) }}
        </span>
      </div>

      <!-- OCR Results Panel -->
      <div v-if="element.ocrTexts && element.ocrTexts.length > 0" 
        class="ocr-panel" 
        :style="{ 
          left: (element.width + 10 / viewport.scale) + 'px', 
          transform: `scale(${1 / viewport.scale})`,
          transformOrigin: 'left top'
        }" 
        @mousedown.stop>
        <div class="ocr-panel-header">
          <span>修改文字</span>
          <el-icon class="ocr-close" @click="$emit('close-ocr', element)"><Close /></el-icon>
        </div>
        <div class="ocr-panel-body">
          <div v-for="(text, index) in element.ocrTexts" :key="index" class="ocr-item">
            <el-input 
              v-model="element.ocrTexts[index].new" 
              size="small" 
              type="textarea" 
              :autosize="{ minRows: 1, maxRows: 4 }"
            />
          </div>
        </div>
        <div class="ocr-panel-footer">
          <el-button type="primary" color="#333639" size="small" :loading="element.ocrProcessing" @click="$emit('submit-ocr', element)">确定修改</el-button>
        </div>
      </div>

      <!-- Watermark Removal Panel -->
      <div v-if="element.showWatermarkPanel" 
        class="ocr-panel" 
        :style="{ 
          left: (element.width + 10 / viewport.scale) + 'px', 
          transform: `scale(${1 / viewport.scale})`,
          transformOrigin: 'left top'
        }" 
        @mousedown.stop>
        <div class="ocr-panel-header">
          <span>去除水印</span>
          <el-icon class="ocr-close" @click="$emit('close-watermark', element)"><Close /></el-icon>
        </div>
        <div class="ocr-panel-body">
          <div class="ocr-item" style="font-size: 12px; color: #666; padding: 4px;">
            将使用AI模型尝试去除图片中的水印。
          </div>
        </div>
        <div class="ocr-panel-footer">
          <el-button type="primary" size="small" :loading="element.watermarkProcessing" @click="$emit('submit-watermark', element)">确定去除</el-button>
        </div>
      </div>

      <div class="marker-layer" 
        v-if="element.type === 'image'"
        :class="{ active: isMarkerMode }"
        @mousedown="handleMarkerLayerMouseDown"
        @mousemove="$emit('marker-layer-mousemove', $event, element)"
        @mouseup="$emit('marker-layer-mouseup', $event, element)"
        @mouseleave="$emit('marker-layer-mouseup', $event, element)">
        <div v-for="marker in element.markers" 
          :key="marker.id" 
          class="marker-item"
          :class="{ 'is-pin': marker && marker.display === 'pin' }"
          :style="markerItemStyle(marker)"
          @mousedown.stop="$emit('element-mousedown', $event, element)">
          <template v-if="marker && marker.display === 'pin'">
            <div class="marker-pin">
              <div class="marker-pin-number">{{ marker.number }}</div>
            </div>
          </template>
          <template v-else>
            <div class="marker-label">{{ marker.number }}</div>
          </template>
          <div class="marker-delete-btn" @click.stop="$emit('remove-marker', element, marker.id)">
            <el-icon><Close /></el-icon>
          </div>
        </div>
        
        <!-- Temporary Drawing Box -->
        <div v-if="drawingBox" 
          class="marker-item drawing-box"
          :style="{ 
            left: drawingBox.x + 'px', 
            top: drawingBox.y + 'px',
            width: drawingBox.width + 'px',
            height: drawingBox.height + 'px',
            pointerEvents: 'none',
            background: 'rgba(64, 158, 255, 0.1)',
            borderColor: '#409eff',
            borderStyle: 'solid',
            borderWidth: '2px',
            zIndex: 100,
            position: 'absolute'
          }"
        ></div>
      </div>

      <!-- Mask Layer (for Inpainting) -->
      <canvas
        :ref="dom => $emit('register-mask-canvas', element.id, dom)"
        class="mask-layer"
        :class="{ active: isBrushMode && isSelected }"
        :width="element.naturalWidth || 1024"
        :height="element.naturalHeight || 1024"
        @mousedown.stop="$emit('mask-mousedown', $event, element)"
        @mousemove.stop="$emit('mask-mousemove', $event, element)"
        @mouseup.stop="$emit('mask-mouseup', $event, element)"
        @mouseleave.stop="$emit('mask-mouseup', $event, element)"
      ></canvas>

      <canvas
        :ref="dom => $emit('register-erase-canvas', element.id, dom)"
        class="erase-layer"
        :class="{ active: isEraseMode && isSelected }"
        :width="element.naturalWidth || 1024"
        :height="element.naturalHeight || 1024"
        @mousedown.stop="$emit('erase-mousedown', $event, element)"
        @mousemove.stop="$emit('erase-mousemove', $event, element)"
        @mouseup.stop="$emit('erase-mouseup', $event, element)"
        @mouseleave.stop="$emit('erase-mouseup', $event, element)"
      ></canvas>

      <canvas
        :ref="dom => $emit('register-erase-outline-canvas', element.id, dom)"
        class="erase-outline-layer"
        :width="element.naturalWidth || 1024"
        :height="element.naturalHeight || 1024"
      ></canvas>
      
      <div class="text-layer">
          <div 
            v-for="text in (element.texts || [])" 
            :key="text.id"
            class="text-item"
            :style="{
              left: (text.xPercent !== undefined ? (text.xPercent * 100) + '%' : text.x + 'px'),
              top: (text.yPercent !== undefined ? (text.yPercent * 100) + '%' : text.y + 'px'),
              color: text.color,
              fontSize: text.size + 'px',
              lineHeight: 1.2
            }"
            @mousedown.stop="$emit('text-mousedown', $event, element, text)"
          >
            <div 
              :id="'text-content-' + text.id"
              contenteditable 
              class="text-content"
              @blur="$emit('update-text', $event, element, text)"
            >{{ text.content }}</div>
            <div class="text-delete-btn" @click.stop="$emit('remove-text', element, text.id)" v-if="isSelected">
              <el-icon><Close /></el-icon>
            </div>
            <div class="text-resize-handle" @mousedown.stop="$emit('text-resize-start', $event, element, text)" v-if="isSelected"></div>
          </div>
      </div>

      <div 
        v-if="isSingleSelection && isTextMode && isSelected"
        class="text-interaction-layer"
        @mousedown.stop="$emit('text-layer-mousedown', $event, element)"
      ></div>
    </template>

    <!-- Video Content -->
    <template v-else-if="element.type === 'video'">
      <video 
        :src="element.src" 
        class="element-content"
        controls
        draggable="false"
        @click.capture="$emit('video-click', $event)"
      ></video>
    </template>

    <!-- Video Generator Content -->
    <template v-else-if="element.type === 'video-generator'">
       <div class="video-generator-card">
          <div class="vg-header">
             <span class="vg-title"><el-icon><VideoPlay /></el-icon> Video Generator</span>
             <el-button link class="vg-close-btn" @click.stop="$emit('remove-element', element.id)">
                 <el-icon><Close /></el-icon>
             </el-button>
          </div>
          <div class="vg-preview">
             <div v-if="element.loading" class="vg-loading">
                <div class="loading-spinner"></div>
                <span>视频生成中... {{ element.progress ? `(${element.progress}%)` : '' }}</span>
                <div v-if="element.progress" class="vg-progress-bar">
                   <div class="vg-progress-inner" :style="{ width: element.progress + '%' }"></div>
                </div>
             </div>
             <div v-else-if="element.error" class="vg-error">
                <el-icon class="error-icon"><WarningFilled /></el-icon>
                <span class="error-msg">{{ element.error }}</span>
                <el-button link type="primary" size="small" @click.stop="element.error = null">关闭</el-button>
             </div>
             <video 
                v-else-if="element.generatedVideoUrl" 
                :src="element.generatedVideoUrl" 
                controls 
                style="width: 100%; height: 100%; object-fit: contain;"
                @mousedown.stop
             ></video>
             <el-icon v-else class="play-icon"><VideoPlay /></el-icon>
             
             <div v-if="element.generatedVideoUrl" class="vg-floating-action">
                 <el-button type="primary" size="small" @click.stop="$emit('move-video', element)">
                   移入画布
                 </el-button>
             </div>
          </div>
          <div class="vg-input-area" @mousedown.stop :class="{ disabled: element.loading }">
             <el-input 
               v-model="element.prompt"
               type="textarea"
               placeholder="今天我们要创作什么"
               :rows="3"
               resize="none"
               class="vg-prompt-input"
             />
             
             <!-- Video Settings Controls -->
             <div class="vg-attachments" v-if="element.videoSettings.referenceMode">
                 <template v-if="element.videoSettings.referenceMode === 'first_last'">
                    <div class="vg-btn square" @click="$emit('trigger-video-upload', element, 'firstFrame')">
                        <el-image v-if="element.videoSettings.firstFrame" :src="element.videoSettings.firstFrame" fit="cover" style="width:100%;height:100%;border-radius:4px;" lazy />
                        <template v-else><el-icon><Plus /></el-icon><span style="font-size:10px;">首帧</span></template>
                    </div>
                    <div class="vg-btn square" @click="$emit('trigger-video-upload', element, 'lastFrame')">
                         <el-image v-if="element.videoSettings.lastFrame" :src="element.videoSettings.lastFrame" fit="cover" style="width:100%;height:100%;border-radius:4px;" lazy />
                        <template v-else><el-icon><Plus /></el-icon><span style="font-size:10px;">尾帧</span></template>
                    </div>
                 </template>
             </div>

             <div class="vg-controls-row">
                  <el-button type="primary" class="vg-generate-btn" :loading="element.loading" :disabled="element.loading" @click="$emit('generate-video', element)">
                     生成
                  </el-button>
             </div>
          </div>
       </div>
    </template>

    <!-- Info Overlay -->
    <div 
      v-if="isSelected && ['image', 'video'].includes(element.type)" 
      class="element-info-overlay"
    >
       <span class="info-text">{{ element.name || (element.type === 'video' ? 'Video' : 'Image') }}</span>
       <span class="info-res">{{ Math.round(element.naturalWidth || element.width) }} x {{ Math.round(element.naturalHeight || element.height) }}</span>
    </div>

    <!-- Selection/Resize Overlay -->
    <div v-if="isSelected" class="element-controls">
      <!-- Top Toolbar (Attached to Element) -->
      <div 
        v-if="isSingleSelection && ['image', 'video'].includes(element.type)" 
        class="element-toolbar-wrapper"
        ref="toolbarWrapperRef"
        :style="{ 
          transform: `translateX(-50%) scale(${1 / viewport.scale})`,
          transformOrigin: 'bottom center'
        }"
        @mousedown.stop
        @click.stop
      >
        <CanvasToolbar 
          :active-tool="activeTool"
          :show-action="element.type === 'image'"
          @tool-click="handleToolClick"
        />
      </div>

      <!-- Delete Confirmation -->
      <div 
        v-if="isSingleSelection && showDeleteConfirm && ['image', 'video'].includes(element.type)" 
        class="delete-confirm-panel" 
        ref="deleteConfirmRef"
        :style="arrowStyle('delete')"
        @mousedown.stop
        @click.stop
      >
         <span class="label">确定要删除吗？</span>
         <el-button size="small" type="danger" @click="confirmDelete">删除</el-button>
         <el-button size="small" @click="showDeleteConfirm = false">取消</el-button>
      </div>

      <!-- Upscale Confirmation -->
      <div 
        v-if="isSingleSelection && showUpscaleConfirm && ['image'].includes(element.type)" 
        class="delete-confirm-panel" 
        ref="upscaleConfirmRef"
        :style="[
          { transform: 'translateX(calc(-50% - 105px / var(--scale-factor))) scale(calc(1 / var(--scale-factor)))' },
          arrowStyle('upscale')
        ]"
        @mousedown.stop
        @click.stop
      >
         <span class="label">确定要进行高清处理吗？</span>
         <el-button size="small" type="primary" @click="confirmUpscale">确定</el-button>
         <el-button size="small" @click="showUpscaleConfirm = false">取消</el-button>
      </div>

      <div 
        v-if="isSingleSelection && showMarkerModePanel && element.type === 'image'" 
        class="marker-settings" 
        ref="markerSettingsRef"
        :style="[arrowStyle('marker'), panelStyle('marker')]"
        @mousedown.stop
        @click.stop
      >
        <span class="marker-tip">按Esc键可退出标注</span>
        <div class="marker-mode-actions">
          <el-button size="small" class="mode-btn" :class="{ active: currentMarkerModeType === 'box' }" @click="selectMarkerMode('box')">框选</el-button>
          <el-button size="small" class="mode-btn" :class="{ active: currentMarkerModeType === 'point' }" @click="selectMarkerMode('point')">点选</el-button>
        </div>
        <div class="panel-arrow" aria-hidden="true"></div>
      </div>

      <!-- Brush Settings -->
      <div 
        v-if="isSingleSelection && isBrushMode && element.type === 'image'" 
        class="brush-settings" 
        ref="brushSettingsRef"
        :style="arrowStyle('brush')"
        @mousedown.stop
      >
         <!-- Brush controls UI -->
         <span class="label">大小</span>
         <el-slider 
            :model-value="brushSize" 
            @update:model-value="(val) => $emit('update-brush-size', val)" 
            :min="1" 
            :max="100" 
            size="small" 
            style="width: 100px; margin: 0 8px" 
         />
         <span class="label">颜色</span>
         <div class="color-palette">
            <div 
              v-for="color in brushColors" 
              :key="color"
              class="color-dot"
              :class="{ active: brushColor === color }"
              :style="{ backgroundColor: color }"
              @click="$emit('update-brush-color', color)"
            ></div>
         </div>
         <el-divider direction="vertical" />
         <el-button size="small" link @click="$emit('undo-stroke', element)" title="撤销"><el-icon><RefreshLeft /></el-icon></el-button>
         <el-button size="small" link @click="$emit('clear-mask', element)">清除</el-button>
      </div>

      <div 
        v-if="isSingleSelection && isEraseMode && element.type === 'image'" 
        class="erase-settings" 
        ref="eraseSettingsRef"
        :style="arrowStyle('erase')"
        @mousedown.stop
      >
         <span class="label">大小</span>
         <el-slider 
            :model-value="eraseSize" 
            @update:model-value="(val) => $emit('update-erase-size', val)" 
            :min="1" 
            :max="100" 
            size="small" 
            style="width: 100px; margin: 0 8px" 
         />
         <el-button size="small" link @click="$emit('clear-erase', element)">清除</el-button>
         <el-divider direction="vertical" />
         <el-button class="erase-submit-btn" type="primary" size="small" :disabled="!element.hasEraseStrokes" :loading="element.eraseProcessing" @click="$emit('submit-erase', element)">擦除</el-button>
      </div>

      <!-- Text Settings -->
      <div 
        v-if="isSingleSelection && isTextMode && element.type === 'image'" 
        class="text-settings" 
        ref="textSettingsRef"
        @mousedown.stop
        :style="[
          { transform: `translateX(-50%) scale(${1 / viewport.scale})`, transformOrigin: 'bottom center' },
          arrowStyle('text')
        ]"
      >
         <span class="label">大小</span>
         <el-slider 
            :model-value="textSize" 
            @update:model-value="(val) => $emit('update-text-size', val)" 
            :min="10" 
            :max="100" 
            size="small" 
            style="width: 100px; margin: 0 8px" 
         />
         <span class="label">颜色</span>
         <div class="color-palette">
            <div 
              v-for="color in brushColors" 
              :key="color"
              class="color-dot"
              :class="{ active: textColor === color }"
              :style="{ backgroundColor: color }"
              @click="$emit('update-text-color', color)"
            ></div>
         </div>
      </div>

      <!-- Corners -->
      <div class="resize-handle nw" @mousedown.stop="$emit('resize-start', $event, element, 'nw')"></div>
      <div class="resize-handle ne" @mousedown.stop="$emit('resize-start', $event, element, 'ne')"></div>
      <div class="resize-handle sw" @mousedown.stop="$emit('resize-start', $event, element, 'sw')"></div>
      <div class="resize-handle se" @mousedown.stop="$emit('resize-start', $event, element, 'se')"></div>
      <!-- Edges -->
      <div class="resize-handle n" @mousedown.stop="$emit('resize-start', $event, element, 'n')"></div>
      <div class="resize-handle e" @mousedown.stop="$emit('resize-start', $event, element, 'e')"></div>
      <div class="resize-handle s" @mousedown.stop="$emit('resize-start', $event, element, 's')"></div>
      <div class="resize-handle w" @mousedown.stop="$emit('resize-start', $event, element, 'w')"></div>
      
      <el-tooltip content="删除图层" placement="top" :show-after="500" v-if="element.type !== 'video-generator' && !['image', 'video'].includes(element.type)">
        <div class="delete-btn" @mousedown.stop="$emit('remove-element', element.id)">
          <el-icon><Close /></el-icon>
        </div>
      </el-tooltip>
    </div>

    <teleport to=".canvas-area" :disabled="!isPosePanelFullscreen">
      <div
        v-if="showActionPanel && element.type === 'image'"
        ref="actionPanelWrapperRef"
        class="action-panel-wrapper"
        :class="{ 'is-fullscreen': isPosePanelFullscreen }"
        :style="actionPanelStyle"
        @mousedown.stop
        @click.stop
      >
        <Pose3DPanel
          :fullscreen="isPosePanelFullscreen"
          @toggle-fullscreen="handleTogglePosePanelFullscreen"
          @drag="handlePosePanelDrag"
          @close="showActionPanel = false"
          @apply="applyPoseToImage"
        />
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { ElMessage } from 'element-plus'
import { useAppStore } from '@/stores/app'
import { useChatStore } from '@/stores/chat'
import CanvasToolbar from './CanvasToolbar.vue'
import Pose3DPanel from './Pose3DPanel.vue'
import { 
  Close, Plus, WarningFilled, VideoPlay, VideoCamera, 
  RefreshLeft, Brush, EditPen, Location
} from '@element-plus/icons-vue'

const props = defineProps({
  element: Object,
  selectedIds: Array,
  highlightedId: String,
  isBrushMode: Boolean,
  isEraseMode: Boolean,
  isTextMode: Boolean,
  isMarkerMode: Boolean,
  markerModeType: String,
  brushSize: Number,
  brushColor: String,
  eraseSize: Number,
  textSize: Number,
  textColor: String,
  brushColors: Array,
  viewport: Object,
  generationStore: Object,
  maskCanvasRefs: Object,
  eraseCanvasRefs: Object,
  eraseOutlineCanvasRefs: Object,
  drawingBox: Object
})

const emit = defineEmits([
  'element-mousedown', 'element-contextmenu', 'element-dblclick',
  'mask-mousedown', 'mask-mousemove', 'mask-mouseup',
  'erase-mousedown', 'erase-mousemove', 'erase-mouseup',
  'text-mousedown', 'update-text', 'remove-text', 'text-layer-mousedown',
  'video-click', 'remove-element', 'resize-start', 'text-resize-start',
  'toggle-brush', 'toggle-erase', 'toggle-text', 'toggle-marker', 'undo-stroke', 'clear-mask', 'clear-erase', 'submit-erase',
  'register-mask-canvas', 'register-erase-canvas', 'register-erase-outline-canvas', 'submit-ocr', 'submit-watermark', 'close-ocr', 'close-watermark',
  'video-model-change', 'generate-video', 'move-video', 'trigger-video-upload',
  'update-brush-size', 'update-brush-color', 'update-erase-size', 'update-text-size', 'update-text-color', 'marker-layer-mousedown', 'marker-layer-mousemove', 'marker-layer-mouseup', 'remove-marker',
  'upscale-image',
  'add-element',
  'request-viewport-pan'
])

const isSelected = computed(() => props.selectedIds && props.selectedIds.includes(props.element.id))
const isHighlighted = computed(() => props.highlightedId === props.element.id)
const isSingleSelection = computed(() => Array.isArray(props.selectedIds) && props.selectedIds.length === 1)

const handleImageLoaded = (e) => {
  if (!props.element || props.element.type !== 'image') return
  props.element.imageLoading = false
  const img = e?.target
  const nw = Number(img?.naturalWidth || img?.width)
  const nh = Number(img?.naturalHeight || img?.height)
  if (Number.isFinite(nw) && nw > 0) props.element.naturalWidth = nw
  if (Number.isFinite(nh) && nh > 0) props.element.naturalHeight = nh
}

const handleImageErrored = () => {
  if (!props.element || props.element.type !== 'image') return
  props.element.imageLoading = false
}

const handleElementDblClick = (e) => {
  const target = e?.target
  if (target?.closest?.('.element-controls')) return
  emit('element-dblclick', props.element)
}

const markerItemStyle = (marker) => {
  const isPin = marker && marker.display === 'pin'

  if (isPin) {
    const pinSize = 28
    const leftPercent = marker.pointXPercent !== undefined
      ? marker.pointXPercent
      : marker.xPercent !== undefined
        ? (marker.xPercent + (marker.widthPercent || 0) / 2)
        : null

    const topPercent = marker.pointYPercent !== undefined
      ? marker.pointYPercent
      : marker.yPercent !== undefined
        ? (marker.yPercent + (marker.heightPercent || 0) / 2)
        : null

    if (leftPercent !== null && topPercent !== null) {
      return {
        left: (leftPercent * 100) + '%',
        top: (topPercent * 100) + '%',
        width: pinSize + 'px',
        height: pinSize + 'px'
      }
    }

    const x = (marker.x || 0) + (marker.width || 0) / 2
    const y = (marker.y || 0) + (marker.height || 0) / 2

    return {
      left: x + 'px',
      top: y + 'px',
      width: pinSize + 'px',
      height: pinSize + 'px'
    }
  }

  return {
    left: (marker.xPercent !== undefined ? (marker.xPercent * 100) + '%' : marker.x + 'px'),
    top: (marker.yPercent !== undefined ? (marker.yPercent * 100) + '%' : marker.y + 'px'),
    width: (marker.widthPercent ? marker.widthPercent * 100 : 5) + '%',
    height: (marker.heightPercent ? marker.heightPercent * 100 : 5) + '%'
  }
}

const activeTool = computed(() => {
  if (showMarkerModePanel.value) return 'marker'
  if (props.isMarkerMode) return 'marker'
  if (props.isTextMode) return 'text'
  if (props.isBrushMode) return 'brush'
  if (props.isEraseMode) return 'erase'
  if (showDeleteConfirm.value) return 'delete'
  if (showUpscaleConfirm.value) return 'upscale'
  if (showActionPanel.value) return 'action'
  return ''
})

const showDeleteConfirm = ref(false)
const showUpscaleConfirm = ref(false)
const showMarkerModePanel = ref(false)
const showActionPanel = ref(false)
const isPosePanelFullscreen = ref(false)
const isApplyingPose = ref(false)
const actionPanelWrapperRef = ref(null)
const toolbarWrapperRef = ref(null)
const brushSettingsRef = ref(null)
const eraseSettingsRef = ref(null)
const textSettingsRef = ref(null)
const markerSettingsRef = ref(null)
const deleteConfirmRef = ref(null)
const upscaleConfirmRef = ref(null)
const arrowOffsetMap = ref({})
const panelOffsetMap = ref({})
const chatStore = useChatStore()
const appStore = useAppStore()

const arrowStyle = (tool) => {
  const v = Number(arrowOffsetMap.value?.[tool])
  if (!Number.isFinite(v) || Math.abs(v) < 0.5) return {}
  return { '--arrow-offset': `${v}px` }
}

const panelStyle = (tool) => {
  const v = Number(panelOffsetMap.value?.[tool])
  if (!Number.isFinite(v) || Math.abs(v) < 0.5) return {}
  return { '--panel-offset': `${v}px` }
}

const getToolbarToolCenterX = (tool) => {
  const wrapper = toolbarWrapperRef.value
  const toolbar = wrapper?.querySelector?.('.canvas-toolbar')
  const item = toolbar?.querySelector?.(`.tool-item[data-tool="${tool}"]`)
  if (!item) return null
  const rect = item.getBoundingClientRect()
  return (rect.left + rect.right) / 2
}

const syncArrowFor = (tool, panelEl) => {
  if (!panelEl) return
  const toolCenterX = getToolbarToolCenterX(tool)
  if (!Number.isFinite(toolCenterX)) return
  const rect = panelEl.getBoundingClientRect()
  const panelCenterX = rect.left + rect.width / 2
  const offset = toolCenterX - panelCenterX
  arrowOffsetMap.value = { ...arrowOffsetMap.value, [tool]: offset }
}

const syncPanelToToolFor = (tool, panelEl) => {
  if (!panelEl) return
  const toolCenterX = getToolbarToolCenterX(tool)
  if (!Number.isFinite(toolCenterX)) return
  const rect = panelEl.getBoundingClientRect()
  const panelCenterX = rect.left + rect.width / 2
  const offsetScreenPx = toolCenterX - panelCenterX
  const zoom = Number(props.viewport?.scale) || 1
  const currentLocal = Number(panelOffsetMap.value?.[tool])
  const safeCurrentLocal = Number.isFinite(currentLocal) ? currentLocal : 0
  const deltaLocalPx = offsetScreenPx / zoom
  const nextLocal = safeCurrentLocal + deltaLocalPx
  panelOffsetMap.value = { ...panelOffsetMap.value, [tool]: nextLocal }
  arrowOffsetMap.value = { ...arrowOffsetMap.value, [tool]: 0 }
}

const syncToolPanelArrows = async () => {
  await nextTick()
  if (!isSelected.value) return

  if (showMarkerModePanel.value) syncPanelToToolFor('marker', markerSettingsRef.value)
  if (props.isBrushMode) syncArrowFor('brush', brushSettingsRef.value)
  if (props.isEraseMode) syncArrowFor('erase', eraseSettingsRef.value)
  if (props.isTextMode) syncArrowFor('text', textSettingsRef.value)
  if (showDeleteConfirm.value) syncArrowFor('delete', deleteConfirmRef.value)
  if (showUpscaleConfirm.value) syncArrowFor('upscale', upscaleConfirmRef.value)
}

watch(
  [
    activeTool,
    () => props.viewport?.scale,
    isSelected,
    showDeleteConfirm,
    showUpscaleConfirm,
    showMarkerModePanel,
    () => props.isBrushMode,
    () => props.isEraseMode,
    () => props.isTextMode
  ],
  () => syncToolPanelArrows(),
  { immediate: true }
)

onMounted(() => {
  window.addEventListener('resize', syncToolPanelArrows)
})

onUnmounted(() => {
  window.removeEventListener('resize', syncToolPanelArrows)
})

const currentMarkerModeType = computed(() => {
  const v = String(props.markerModeType || '').trim()
  return v === 'point' ? 'point' : 'box'
})

const selectMarkerMode = (mode) => {
  const nextMode = mode === 'point' ? 'point' : 'box'
  emit('toggle-marker', props.element, nextMode)
}

const handleMarkerLayerMouseDown = (e) => {
  if (!props.isMarkerMode) return
  e.stopPropagation()
  emit('marker-layer-mousedown', e, props.element)
}

const actionPanelStyle = computed(() => {
  if (isPosePanelFullscreen.value) {
    return {
      left: '0px',
      top: '0px',
      right: '0px',
      bottom: '0px',
      transform: 'none',
      transformOrigin: 'left top'
    }
  }
  return {
    left: (props.element.width + 12 / props.viewport.scale) + 'px',
    top: '0px',
    transform: `scale(${1 / props.viewport.scale})`,
    transformOrigin: 'left top'
  }
})

const handlePosePanelDrag = (e) => {
  if (isPosePanelFullscreen.value) return
  emit('element-mousedown', e, props.element)
}

const handleTogglePosePanelFullscreen = (val) => {
  const next = !!val
  isPosePanelFullscreen.value = next
  if (!next && showActionPanel.value) ensureActionPanelVisible()
}

watch(showActionPanel, (v) => {
  if (!v) isPosePanelFullscreen.value = false
})

watch(isSelected, (v) => {
  if (v) return
  if (showActionPanel.value) showActionPanel.value = false
})

// Close delete confirm when clicking outside
const handleGlobalClick = (e) => {
  if (showDeleteConfirm.value) {
    // Check if click target is inside the delete panel or the delete button in toolbar
    // We can rely on event bubbling and .stop modifiers on the panel itself.
    // However, since we are attaching a global listener, we need to be careful.
    // But actually, the delete panel has @mousedown.stop, which prevents mousedown from reaching window?
    // Wait, window click listener fires on click (mouseup phase usually).
    // If we stop propagation on the panel, it won't reach window.
    // So any click reaching window means it was OUTSIDE the panel (assuming panel stops prop).
    showDeleteConfirm.value = false
  }
  if (showUpscaleConfirm.value) {
    showUpscaleConfirm.value = false
  }
}

onMounted(() => {
  window.addEventListener('click', handleGlobalClick)
})

onUnmounted(() => {
  window.removeEventListener('click', handleGlobalClick)
})

const ensureActionPanelVisible = async () => {
  if (!showActionPanel.value) return
  if (isPosePanelFullscreen.value) return
  await nextTick()

  const wrapper = actionPanelWrapperRef.value
  if (!wrapper) return

  const canvasArea = wrapper.closest('.canvas-area')
  const container = canvasArea?.querySelector?.('.infinite-canvas-container')
  if (!container) return

  const panelRect = wrapper.getBoundingClientRect()
  const containerRect = container.getBoundingClientRect()

  const padding = 12
  const containerLeft = containerRect.left + padding
  const containerRight = containerRect.right - padding
  const containerTop = containerRect.top + padding
  const containerBottom = containerRect.bottom - padding

  const panelW = panelRect.width
  const panelH = panelRect.height
  const containerW = Math.max(0, containerRight - containerLeft)
  const containerH = Math.max(0, containerBottom - containerTop)

  let dx = 0
  let dy = 0

  if (panelW > containerW) {
    dx = containerLeft - panelRect.left
  } else if (panelRect.left < containerLeft) {
    dx = containerLeft - panelRect.left
  } else if (panelRect.right > containerRight) {
    dx = containerRight - panelRect.right
  }

  if (panelH > containerH) {
    dy = containerTop - panelRect.top
  } else if (panelRect.top < containerTop) {
    dy = containerTop - panelRect.top
  } else if (panelRect.bottom > containerBottom) {
    dy = containerBottom - panelRect.bottom
  }

  if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) return
  emit('request-viewport-pan', { dx, dy })
}

const handleToolClick = (tool) => {
  if (tool === 'action') {
    if (props.element.type !== 'image') return
    showDeleteConfirm.value = false
    showUpscaleConfirm.value = false
    const next = !showActionPanel.value
    showActionPanel.value = next
    if (next) isPosePanelFullscreen.value = false
    if (next) {
      if (props.isBrushMode) emit('toggle-brush', props.element)
      if (props.isEraseMode) emit('toggle-erase', props.element)
      if (props.isTextMode) emit('toggle-text', props.element)
      if (props.isMarkerMode) emit('toggle-marker', props.element)
      ensureActionPanelVisible()
    }
    return
  }
  if (tool === 'delete') {
    // Stop propagation to prevent immediate closing by global listener
    // Actually, since this is a click handler, it might bubble to window if we don't stop it.
    // But the toolbar item click usually needs stop propagation?
    // Let's ensure we stop propagation here or in the template.
    // In CanvasToolbar, the emit is from a div.
    // In CanvasNode, we handle it.
    // We should ensure the event doesn't bubble to window.
    // But wait, the tool click is an emitted event, not a native DOM event here.
    // We need to ensure the original click didn't bubble?
    // CanvasToolbar uses @click on tool items. We should add .stop there or here.
    // It's safer to use a timeout or ensure propagation is stopped at source.
    // Let's assume the user clicks the button, we set state.
    // If the click event bubbles to window, handleGlobalClick will see it and set it to false immediately.
    // So we need to ensure the click on the tool button doesn't trigger the global close.
    // One way is to check e.target, but we don't have the event object here easily from the emit payload unless passed.
    // Better way: use setTimeout to set the state, or ensure stopPropagation in CanvasToolbar.
    // Let's use setTimeout(..., 0) to allow current event loop to finish before setting state? 
    // Or better, just rely on the fact that we will add .stop to the toolbar click in CanvasToolbar.vue if not already there.
    // Looking at CanvasToolbar.vue, it just has @click="$emit...".
    // We should probably update CanvasToolbar to use @click.stop.
    
    // For now, let's use a small timeout to avoid immediate closure if bubbling happens.
    setTimeout(() => {
      showDeleteConfirm.value = true
    }, 0)
    
    if (props.isBrushMode) emit('toggle-brush', props.element)
    if (props.isEraseMode) emit('toggle-erase', props.element)
    if (props.isTextMode) emit('toggle-text', props.element)
    return
  }
  showDeleteConfirm.value = false
  
  if (tool === 'upscale') {
    if (showActionPanel.value) showActionPanel.value = false
    if (showMarkerModePanel.value) showMarkerModePanel.value = false
    if (props.isBrushMode) emit('toggle-brush', props.element)
    if (props.isEraseMode) emit('toggle-erase', props.element)
    if (props.isTextMode) emit('toggle-text', props.element)
    if (props.isMarkerMode) emit('toggle-marker', props.element)
    setTimeout(() => {
      showUpscaleConfirm.value = true
    }, 0)
    return
  }
  showUpscaleConfirm.value = false

  if (tool === 'marker') {
    showDeleteConfirm.value = false
    showUpscaleConfirm.value = false
    if (props.isMarkerMode) {
      emit('toggle-marker', props.element)
      return
    }
    showMarkerModePanel.value = true
    emit('toggle-marker', props.element, currentMarkerModeType.value)
    return
  }

  switch (tool) {
    case 'marker':
      emit('toggle-marker', props.element)
      break
    case 'text':
      emit('toggle-text', props.element)
      break
    case 'brush':
      emit('toggle-brush', props.element)
      break
    case 'erase':
      emit('toggle-erase', props.element)
      break
  }
}

watch(() => props.isMarkerMode, (v) => {
  showMarkerModePanel.value = !!v
})

const confirmDelete = () => {
  emit('remove-element', props.element.id)
  showDeleteConfirm.value = false
}

const confirmUpscale = () => {
  emit('upscale-image', props.element)
  showUpscaleConfirm.value = false
}

const dataUrlToFile = (dataUrl, filename) => {
  const parts = String(dataUrl || '').split(',')
  if (parts.length < 2) return null
  const header = parts[0]
  const base64 = parts[1]
  const match = header.match(/data:(.*?);base64/i)
  const mime = match?.[1] || 'image/png'
  const binary = atob(base64)
  const len = binary.length
  const bytes = new Uint8Array(len)
  for (let i = 0; i < len; i++) bytes[i] = binary.charCodeAt(i)
  return new File([bytes], filename, { type: mime })
}

const applyPoseToImage = async (poseRef) => {
  if (isApplyingPose.value) return
  if (!props?.generationStore?.submitImageTask && !props?.generationStore?.generateImage) return
  if (!props?.element || props.element.type !== 'image') return
  if (!poseRef) return

  const sourceUrl = props.element.src
  if (!sourceUrl) {
    ElMessage.error('当前图片无有效地址，无法应用动作')
    return
  }

  isApplyingPose.value = true
  try {
    const isDataUrl = typeof poseRef === 'string' && poseRef.startsWith('data:image')
    let poseUrl = ''
    if (isDataUrl) {
      if (!props?.generationStore?.uploadReferences) return
      const file = dataUrlToFile(poseRef, `pose_reference_${Date.now()}.png`)
      if (!file) throw new Error('动作参考图生成失败')
      const uploaded = await props.generationStore.uploadReferences([file], false, false)
      poseUrl = Array.isArray(uploaded) && uploaded.length ? uploaded[0] : ''
    } else if (typeof poseRef === 'string') {
      poseUrl = poseRef
    } else if (poseRef && typeof poseRef === 'object') {
      poseUrl = String(poseRef.pose_image_url || poseRef.poseImageUrl || poseRef.poseUrl || '').trim()
    }
    if (!poseUrl) throw new Error('动作参考图无效')

    let prompt = ''
    try {
      prompt = typeof props.generationStore.getPoseEditPrompt === 'function'
        ? await props.generationStore.getPoseEditPrompt()
        : ''
    } catch {}
    if (!prompt) prompt = '将人物姿势调整为参考图中的动作，其它内容保持不变。'

    let targetW = props.element.naturalWidth || 0
    let targetH = props.element.naturalHeight || 0
    if (!targetW || !targetH) {
      try {
        const img = new Image()
        img.crossOrigin = 'anonymous'
        await new Promise((resolve, reject) => {
          img.onload = resolve
          img.onerror = reject
          img.src = sourceUrl
        })
        targetW = img.naturalWidth || img.width || targetW
        targetH = img.naturalHeight || img.height || targetH
      } catch {}
    }

    const options = {
      generation_mode: 'image_to_image',
      reference_images: [sourceUrl, poseUrl],
      is_pose_edit: true,
      use_param_adapt: true,
      ...(targetW && targetH ? { width: targetW, height: targetH, size: `${targetW}x${targetH}` } : {})
    }

    const closingText = '图片生成任务已经完成，需要任何修改或者生成新的图片请告诉我'
    const imageName = props.element.name ? `${props.element.name}-动作` : '人物动作修改'

    const cfgPoseModel = String(appStore?.siteConfig?.pose_edit_model || '').trim()
    if (!cfgPoseModel) {
      throw new Error('未配置人物动作修改模型，请联系管理员配置')
    }
    const mdlId = cfgPoseModel
    let mdlLabel = '图片模型'
    if (mdlId && Array.isArray(props.generationStore?.imageModels)) {
      const mdl = props.generationStore.imageModels.find(m => m.model_identity === mdlId)
      mdlLabel = (mdl && (mdl.name || mdl.model_identity)) ? (mdl.name || mdl.model_identity) : mdlId
    }

    let newUrl = ''
    if (typeof props.generationStore.submitImageTask === 'function') {
      const submitted = await props.generationStore.submitImageTask(prompt, options, mdlId || null)
      if (submitted?.direct) {
        const img = Array.isArray(submitted.images) ? submitted.images[0] : null
        newUrl = img?.url || (img?.b64 ? `data:image/png;base64,${img.b64}` : '')
        if (newUrl) {
          chatStore.appendToolResultMessage({
            image_url: newUrl,
            image_name: imageName,
            resolution: (targetW && targetH) ? `${targetW}x${targetH}` : '',
            no_auto_add_to_canvas: true,
            model_identity: mdlId || '',
            model_name: mdlLabel
          })
          chatStore.appendAssistantMessage(closingText)
        }
      } else if (submitted?.taskId) {
        const tracked = await chatStore.trackExternalImageTask({
          taskId: submitted.taskId,
          imageName,
          modelIdentity: mdlId,
          modelName: mdlLabel,
          width: targetW || undefined,
          height: targetH || undefined,
          resolution: (targetW && targetH) ? `${targetW}x${targetH}` : '',
          toolResultExtra: { no_auto_add_to_canvas: true },
          closingText
        })
        newUrl = tracked?.imageUrl || tracked?.videoUrl || ''
      }
    } else {
      const result = await props.generationStore.generateImage(prompt, options, mdlId || null, false)
      newUrl = result?.url || ''
      if (newUrl) {
        chatStore.appendToolResultMessage({
          image_url: newUrl,
          image_name: imageName,
          resolution: (targetW && targetH) ? `${targetW}x${targetH}` : '',
          no_auto_add_to_canvas: true,
          model_identity: mdlId || '',
          model_name: mdlLabel
        })
        chatStore.appendAssistantMessage(closingText)
      }
    }
    if (!newUrl) throw new Error('动作生成失败')

    let nw = targetW || props.element.naturalWidth || props.element.width
    let nh = targetH || props.element.naturalHeight || props.element.height
    try {
      const img = new Image()
      img.crossOrigin = 'anonymous'
      await new Promise((resolve, reject) => {
        img.onload = resolve
        img.onerror = reject
        img.src = newUrl
      })
      nw = img.naturalWidth || img.width || nw
      nh = img.naturalHeight || img.height || nh
    } catch {}

    let w = Number(props.element.width) || 0
    let h = Number(props.element.height) || 0
    if (!w || !h) {
      w = Number(nw) || 0
      h = Number(nh) || 0
    }

    const minLongSide = 600
    const currentLongSide = Math.max(w, h)
    if (currentLongSide > 0 && currentLongSide < minLongSide) {
      const scale = minLongSide / currentLongSide
      w = Math.max(1, Math.round(w * scale))
      h = Math.max(1, Math.round(h * scale))
    }
    if (!w || !h) {
      w = minLongSide
      h = minLongSide
    }

    emit('add-element', {
      type: 'image',
      src: newUrl,
      x: (props.element.x ?? 0) + 50,
      y: (props.element.y ?? 0) + 50,
      width: w,
      height: h,
      naturalWidth: nw,
      naturalHeight: nh,
      name: imageName || `动作-${Date.now().toString().slice(-4)}`
    })
    ElMessage.success('动作已应用到图片')
  } catch (e) {
    console.error(e)
    ElMessage.error(e?.message || '动作应用失败')
  } finally {
    isApplyingPose.value = false
  }
}

</script>

<style scoped lang="scss">
.action-panel-wrapper {
  position: absolute;
  z-index: 3000;
  pointer-events: auto;
}

.element-toolbar-wrapper {
  position: absolute;
  top: -60px; /* Position above the element */
  left: 50%;
  transform: translateX(-50%); /* Start centered, but scale will override this if not handled carefully */
  /* Actually, with transform scale, we need to be careful. 
     If we use transform: scale(), we can't easily use translate for centering in the same property unless we combine them.
     The inline style uses scale. Let's adjust the inline style to include translate if needed, or handle centering differently.
     Better approach: Use a wrapper for positioning/centering, and inner for scaling? 
     Or just combine in inline style: `translateX(-50%) scale(...)`.
     But the inline style I wrote only has scale. Let's fix the inline style in the template or here.
     Wait, transformOrigin 'bottom center' helps, but 'left' needs to be 50%.
     If I set left: 50%, the element starts at center.
     If I want it centered, I usually do transform: translateX(-50%).
     If I also scale, I need `transform: translateX(-50%) scale(...)`.
     Let's update the template style to include translateX(-50%).
  */
  display: flex;
  justify-content: center;
  z-index: 1001;
  width: max-content;
  /* Since we can't easily edit the inline style I just wrote without another tool call, 
     let's check if I can just use margin-left to center it roughly or if I should update the template.
     Actually, let's just update the template to be correct.
  */
}

.canvas-element {
    position: absolute;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    /* transition: box-shadow 0.2s; Remove transition to prevent ghosting during drag */
    will-change: left, top, width, height; 
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

  .image-placeholder {
    position: absolute;
    inset: 0;
    background: #f5f7fa;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 1;
    pointer-events: none;
  }

  .image-placeholder-spinner {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    border: 2px solid rgba(0, 0, 0, 0.12);
    border-top-color: rgba(64, 158, 255, 0.9);
    animation: imagePlaceholderSpin 0.9s linear infinite;
  }

  @keyframes imagePlaceholderSpin {
    to {
      transform: rotate(360deg);
    }
  }
  
  .marker-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 7;
    pointer-events: none;

    &.active {
      pointer-events: auto;
      cursor: crosshair;
    }

    .marker-item {
      position: absolute;
      border: 2px solid #409eff;
      background: rgba(64, 158, 255, 0.2);
      pointer-events: auto;
      cursor: pointer;
      z-index: 10;
      transition: background-color 0.2s;

      .marker-delete-btn {
        position: absolute;
        background: #f56c6c;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        opacity: 0;
        transition: opacity 0.2s;
        color: white;
        box-shadow: 0 calc(2px / var(--scale-factor)) calc(4px / var(--scale-factor)) rgba(0,0,0,0.2);
        pointer-events: none;
        z-index: 2;
      }

      &:hover {
        background: rgba(64, 158, 255, 0.4);
        z-index: 11;
        border-color: #66b1ff;
      }

      &.is-pin {
        border: none;
        background: transparent;
        transform: translate(-50%, -100%) scale(calc(1 / var(--scale-factor)));
        transform-origin: 50% 100%;
        transition: none;

        &:hover {
          background: transparent;
          border-color: transparent;
        }

        .marker-delete-btn {
          top: 0;
          right: 0;
          width: 20px;
          height: 20px;
          font-size: 12px;
          transform-origin: top right;
          transform: scale(var(--scale-factor)) translate(50%, -50%);
        }

        .marker-pin {
          width: 28px;
          height: 28px;
          background: #409eff;
          border-radius: 50% 50% 50% 0;
          transform: rotate(-45deg);
          position: relative;
          box-shadow: 0 2px 6px rgba(0,0,0,0.2);
        }

        .marker-pin::after {
          content: '';
          position: absolute;
          width: 18px;
          height: 18px;
          background: white;
          border-radius: 50%;
          top: 5px;
          left: 5px;
        }

        .marker-pin-number {
          position: absolute;
          width: 18px;
          height: 18px;
          top: 5px;
          left: 5px;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #409eff;
          font-weight: 700;
          font-size: 14px;
          z-index: 1;
          transform: rotate(45deg);
          user-select: none;
        }
      }

      &:not(.is-pin) .marker-label {
        position: absolute;
        top: calc(-24px / var(--scale-factor));
        left: calc(-2px / var(--scale-factor));
        height: calc(24px / var(--scale-factor));
        padding: 0 calc(8px / var(--scale-factor));
        background: #409eff;
        color: white;
        border-radius: calc(4px / var(--scale-factor)) calc(4px / var(--scale-factor)) 0 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: calc(14px / var(--scale-factor));
        font-weight: bold;
        box-shadow: 0 calc(-2px / var(--scale-factor)) calc(4px / var(--scale-factor)) rgba(0,0,0,0.1);
        white-space: nowrap;
      }
      
      &:not(.is-pin) .marker-delete-btn {
        top: calc(-10px / var(--scale-factor));
        right: calc(-10px / var(--scale-factor));
        width: calc(20px / var(--scale-factor));
        height: calc(20px / var(--scale-factor));
        font-size: calc(12px / var(--scale-factor));
      }
      
      &:hover .marker-delete-btn {
        opacity: 1;
        pointer-events: auto;
      }
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
          border: calc(1px / var(--scale-factor)) dashed transparent;
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

       .text-resize-handle {
          position: absolute;
          bottom: calc(-12px / var(--scale-factor));
          right: calc(-12px / var(--scale-factor));
          width: 12px;
          height: 12px;
          background: white;
          border: 1px solid #409eff;
          border-radius: 50%;
          cursor: nwse-resize;
          transform: scale(calc(1 / var(--scale-factor)));
          opacity: 0;
          transition: opacity 0.2s;
          pointer-events: auto;
       }

       &:hover .text-delete-btn,
       &:hover .text-resize-handle {
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

  .erase-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 6;
    pointer-events: none;
    opacity: 0.45;
    
    &.active {
      pointer-events: auto;
      cursor: crosshair;
    }
  }

  .erase-outline-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 7;
    pointer-events: none;
  }

  .element-controls {
    position: absolute;
    inset: 0;
    pointer-events: none;
    
    .brush-settings {
      position: absolute;
      top: auto;
      bottom: calc(100% + 75px / var(--scale-factor));
      left: 50%;
      right: auto;
      transform: translateX(-50%) scale(calc(1 / var(--scale-factor)));
      transform-origin: bottom center;
      background: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      gap: 8px;
      pointer-events: auto;
      z-index: 2000;
      white-space: nowrap;

      &::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(calc(-50% + var(--arrow-offset, 0px)));
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #fff;
        transition: transform 0.2s ease-in-out;
      }
      
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

    .erase-settings {
      position: absolute;
      top: auto;
      bottom: calc(100% + 75px / var(--scale-factor));
      left: 50%;
      right: auto;
      transform: translateX(-50%) scale(calc(1 / var(--scale-factor)));
      transform-origin: bottom center;
      background: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      gap: 8px;
      pointer-events: auto;
      z-index: 2000;
      white-space: nowrap;

      &::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(calc(-50% + var(--arrow-offset, 0px)));
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #fff;
        transition: transform 0.2s ease-in-out;
      }
      
      .label {
        font-size: 12px;
        color: #606266;
      }

      .erase-submit-btn:not(.is-disabled) {
        --el-button-bg-color: #000;
        --el-button-border-color: #000;
        --el-button-text-color: #fff;
        --el-button-hover-bg-color: #111;
        --el-button-hover-border-color: #111;
        --el-button-active-bg-color: #000;
        --el-button-active-border-color: #000;
      }
    }

    .marker-settings {
      position: absolute;
      top: auto;
      bottom: calc(100% + 75px / var(--scale-factor));
      left: 50%;
      right: auto;
      transform: translateX(calc(-50% + var(--panel-offset, 0px))) scale(calc(1 / var(--scale-factor)));
      transform-origin: bottom center;
      background: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      gap: 8px;
      pointer-events: auto;
      z-index: 2000;
      white-space: nowrap;
      overflow: visible;

      &::after {
        content: none;
      }

      .panel-arrow {
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(calc(-50% + var(--arrow-offset, 0px)));
        width: 0;
        height: 0;
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #fff;
        z-index: 1;
        pointer-events: none;
      }

      .marker-tip {
        font-size: 12px;
        color: #606266;
      }

      .marker-mode-actions {
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .mode-btn {
        --el-button-bg-color: #fff;
        --el-button-border-color: #dcdfe6;
        --el-button-text-color: #303133;
        --el-button-hover-bg-color: #f5f5f5;
        --el-button-hover-border-color: #dcdfe6;
        --el-button-hover-text-color: #303133;
        --el-button-active-bg-color: #f5f5f5;
        --el-button-active-border-color: #dcdfe6;
        --el-button-active-text-color: #303133;
      }

      .mode-btn.active {
        --el-button-bg-color: #000;
        --el-button-border-color: #000;
        --el-button-text-color: #fff;
        --el-button-hover-bg-color: #111;
        --el-button-hover-border-color: #111;
        --el-button-hover-text-color: #fff;
        --el-button-active-bg-color: #000;
        --el-button-active-border-color: #000;
        --el-button-active-text-color: #fff;
      }
    }

    .text-settings {
      position: absolute;
      top: auto;
      bottom: calc(100% + 75px / var(--scale-factor));
      left: 50%;
      right: auto;
      transform: translateX(-50%) scale(calc(1 / var(--scale-factor)));
      transform-origin: bottom center;
      background: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      gap: 8px;
      pointer-events: auto;
      z-index: 2000;
      white-space: nowrap;

      &::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(calc(-50% + var(--arrow-offset, 0px)));
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #fff;
        transition: transform 0.2s ease-in-out;
      }
      
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

    .delete-confirm-panel {
      position: absolute;
      top: auto;
      bottom: calc(100% + 75px / var(--scale-factor));
      left: 50%;
      right: auto;
      transform: translateX(calc(-50% + 105px / var(--scale-factor))) scale(calc(1 / var(--scale-factor)));
      transform-origin: bottom center;
      background: #fff;
      padding: 8px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      display: flex;
      align-items: center;
      gap: 8px;
      pointer-events: auto;
      z-index: 2000;
      white-space: nowrap;

      &::after {
        content: '';
        position: absolute;
        bottom: -6px;
        left: 50%;
        transform: translateX(calc(-50% + var(--arrow-offset, 0px)));
        border-left: 6px solid transparent;
        border-right: 6px solid transparent;
        border-top: 6px solid #fff;
      }
      
      .label {
        font-size: 12px;
        color: #606266;
        font-weight: 500;
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

    .marker-btn {
      position: absolute;
      top: auto;
      bottom: calc(100% + 12px / var(--scale-factor));
      right: calc(96px / var(--scale-factor));
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
     top: calc(100% + 8px / var(--scale-factor));
     bottom: auto;
     left: 0;
     transform: scale(calc(1 / var(--scale-factor)));
     transform-origin: top left;
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
</style>
