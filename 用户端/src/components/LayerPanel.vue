<template>
  <div class="layer-panel">
    
    <div 
      class="layer-list"
      @dragover.prevent
      @drop="handlePanelDrop"
    >
      <div v-if="elements.length === 0" class="empty-tip">
        暂无图层
      </div>
      
      <div 
        v-for="(element, index) in reversedElements" 
        :key="element.id"
      >
        <layer-item 
          :element="element" 
          :selected-ids="selectedIds"
          :depth="0"
          @select="handleSelect"
          @hover="handleHover"
          @toggle-visibility="handleToggleVisibility"
          @toggle-lock="handleToggleLock"
          @rename="handleRename"
          @toggle-expand="handleToggleExpand"
          @move-layer="handleMoveLayer"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import LayerItem from './LayerItem.vue'

const props = defineProps({
  elements: {
    type: Array,
    default: () => []
  },
  selectedIds: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['select', 'hover', 'toggle-visibility', 'toggle-lock', 'rename', 'toggle-expand', 'move-layer'])

// Layers are usually displayed top-to-bottom (reverse of rendering order)
const reversedElements = computed(() => {
  return [...props.elements].reverse()
})

const handleSelect = (id, multi) => emit('select', id, multi)
const handleHover = (id) => emit('hover', id)
const handleToggleVisibility = (id) => emit('toggle-visibility', id)
const handleToggleLock = (id) => emit('toggle-lock', id)
const handleRename = (id, name) => emit('rename', id, name)
const handleToggleExpand = (id) => emit('toggle-expand', id)
const handleMoveLayer = (dragId, targetId) => emit('move-layer', dragId, targetId)

const handlePanelDrop = (e) => {
  const dragId = e.dataTransfer.getData('text/plain')
  if (dragId) {
    // Drop to root
    emit('move-layer', dragId, null)
  }
}
</script>

<style scoped lang="scss">
.layer-panel {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-left: 1px solid #eee;

  .layer-list {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
  }

  .empty-tip {
    padding: 20px;
    text-align: center;
    color: #999;
    font-size: 12px;
  }
}
</style>
