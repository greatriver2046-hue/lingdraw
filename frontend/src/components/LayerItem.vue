<template>
  <div class="layer-item-wrapper">
    <div 
      class="layer-item"
      :class="{ 
        selected: isSelected,
        'is-group': isGroup,
        'drag-over': isDragOver
      }"
      :style="{ paddingLeft: (depth * 20 + 12) + 'px' }"
      draggable="true"
      @click.stop="handleClick"
      @mouseenter="handleMouseEnter"
      @mouseleave="handleMouseLeave"
      @dragstart="handleDragStart"
      @dragover="handleDragOver"
      @dragleave="handleDragLeave"
      @drop="handleDrop"
    >
      <div class="layer-expand-icon" @click.stop="toggleExpand" v-if="isGroup">
        <el-icon :class="{ expanded: element.expanded }"><CaretRight /></el-icon>
      </div>
      <div class="layer-expand-placeholder" v-else></div>

      <div class="layer-icon">
        <el-icon v-if="isGroup"><Folder /></el-icon>
        <el-icon v-else-if="element.type === 'image'"><Picture /></el-icon>
        <el-icon v-else-if="element.type === 'video'"><VideoCamera /></el-icon>
        <el-icon v-else><Document /></el-icon>
      </div>

      <div class="layer-name">
        <span v-if="!isRenaming" @dblclick.stop="startRename">{{ element.name || '未命名图层' }}</span>
        <el-input 
          v-else 
          ref="renameInput"
          v-model="renameValue" 
          size="small" 
          @blur="finishRename"
          @keyup.enter="finishRename"
          @click.stop
        />
      </div>

      <div class="layer-actions">
        <el-tooltip :content="element.locked ? '解锁图层' : '锁定图层'" placement="top" :show-after="500">
          <div class="action-btn" @click.stop="$emit('toggle-lock', element.id)">
            <el-icon v-if="element.locked"><Lock /></el-icon>
            <el-icon v-else class="hover-show"><Unlock /></el-icon>
          </div>
        </el-tooltip>
        <el-tooltip :content="element.hidden ? '显示图层' : '隐藏图层'" placement="top" :show-after="500">
          <div class="action-btn" @click.stop="$emit('toggle-visibility', element.id)">
            <el-icon v-if="!element.hidden"><View /></el-icon>
            <el-icon v-else><Hide /></el-icon>
          </div>
        </el-tooltip>
      </div>
    </div>

    <!-- Children (Recursive) -->
    <div v-if="isGroup && element.expanded && element.children && element.children.length > 0" class="layer-children">
      <layer-item
        v-for="child in reversedChildren"
        :key="child.id"
        :element="child"
        :selected-ids="selectedIds"
        :depth="depth + 1"
        @select="(id, m) => $emit('select', id, m)"
        @hover="(id) => $emit('hover', id)"
        @toggle-visibility="(id) => $emit('toggle-visibility', id)"
        @toggle-lock="(id) => $emit('toggle-lock', id)"
        @rename="(id, n) => $emit('rename', id, n)"
        @toggle-expand="(id) => $emit('toggle-expand', id)"
        @move-layer="(dId, tId) => $emit('move-layer', dId, tId)"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, ref, nextTick } from 'vue'
import { CaretRight, Folder, Picture, VideoCamera, Document, View, Hide, Lock, Unlock } from '@element-plus/icons-vue'

const props = defineProps({
  element: Object,
  selectedIds: Array,
  depth: { type: Number, default: 0 }
})

const emit = defineEmits(['select', 'hover', 'toggle-visibility', 'toggle-lock', 'rename', 'toggle-expand', 'move-layer'])

const isGroup = computed(() => props.element.type === 'group')
const isSelected = computed(() => props.selectedIds.includes(props.element.id))
const reversedChildren = computed(() => props.element.children ? [...props.element.children].reverse() : [])

const isRenaming = ref(false)
const renameValue = ref('')
const renameInput = ref(null)
const isDragOver = ref(false)

const handleDragStart = (e) => {
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', props.element.id)
  e.stopPropagation()
}

const handleDragOver = (e) => {
  if (isGroup.value) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    isDragOver.value = true
  }
}

const handleDragLeave = () => {
  isDragOver.value = false
}

const handleDrop = (e) => {
  if (isGroup.value) {
    e.preventDefault()
    e.stopPropagation()
    isDragOver.value = false
    const dragId = e.dataTransfer.getData('text/plain')
    if (dragId && dragId !== props.element.id) {
      emit('move-layer', dragId, props.element.id)
    }
  }
}

const handleClick = (e) => {
  emit('select', props.element.id, e.ctrlKey || e.metaKey)
}

const handleMouseEnter = () => {
  if (props.element?.hidden) {
    emit('hover', null)
    return
  }
  emit('hover', props.element.id)
}

const handleMouseLeave = () => {
  emit('hover', null)
}

const toggleExpand = () => {
  emit('toggle-expand', props.element.id)
}

const startRename = () => {
  renameValue.value = props.element.name
  isRenaming.value = true
  nextTick(() => {
    renameInput.value?.focus()
  })
}

const finishRename = () => {
  if (isRenaming.value) {
    isRenaming.value = false
    if (renameValue.value.trim()) {
      emit('rename', props.element.id, renameValue.value)
    }
  }
}
</script>

<style scoped lang="scss">
.layer-item {
  display: flex;
  align-items: center;
  padding: 8px 12px 8px 0;
  cursor: pointer;
  user-select: none;
  border-bottom: 1px solid transparent;
  transition: background-color 0.2s;

  &:hover {
    background-color: #f5f7fa;
    
    .layer-actions .hover-show {
      opacity: 1;
    }
  }

  &.drag-over {
    background-color: #e6f4ff;
    border: 1px dashed #1677ff;
  }

  &.selected {
    background-color: #e6f4ff;
    color: #1677ff;
  }

  .layer-expand-icon {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #909399;
    
    .el-icon {
      transition: transform 0.2s;
      &.expanded {
        transform: rotate(90deg);
      }
    }
  }

  .layer-expand-placeholder {
    width: 20px;
  }

  .layer-icon {
    margin-right: 8px;
    color: #909399;
    display: flex;
    align-items: center;
  }

  .layer-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
    padding-right: 8px;
  }

  .layer-actions {
    display: flex;
    gap: 4px;

    .action-btn {
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #909399;
      
      &:hover {
        color: #333;
      }

      .hover-show {
        opacity: 0;
      }
    }
  }
}
</style>
