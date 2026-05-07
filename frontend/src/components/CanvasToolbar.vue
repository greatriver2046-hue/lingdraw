<template>
  <div class="canvas-toolbar">
    <div 
      class="tool-item" 
      data-tool="upscale"
      @click.stop="$emit('tool-click', 'upscale')"
      title="图片高清"
    >
      <el-icon>
        <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true">
          <rect x="3" y="6" width="18" height="12" rx="2" ry="2" stroke="currentColor" stroke-width="2" fill="none" />
          <text x="12" y="12.5" text-anchor="middle" dominant-baseline="middle" font-size="7" font-family="Arial, sans-serif" fill="currentColor" font-weight="700">4K</text>
        </svg>
      </el-icon>
      <span class="tool-name">高清</span>
    </div>

    <div 
      class="tool-item" 
      data-tool="marker"
      :class="{ active: activeTool === 'marker' }"
      @click.stop="$emit('tool-click', 'marker')"
      title="标注"
    >
      <el-icon><Location /></el-icon>
      <span class="tool-name">标注</span>
    </div>
    
    <div 
      class="tool-item" 
      data-tool="text"
      :class="{ active: activeTool === 'text' }"
      @click.stop="$emit('tool-click', 'text')"
      title="文字"
    >
      <el-icon><EditPen /></el-icon>
      <span class="tool-name">文字</span>
    </div>
    
    <div 
      class="tool-item" 
      data-tool="brush"
      :class="{ active: activeTool === 'brush' }"
      @click.stop="$emit('tool-click', 'brush')"
      title="笔刷"
    >
      <el-icon><Brush /></el-icon>
      <span class="tool-name">笔刷</span>
    </div>

    <div 
      class="tool-item" 
      data-tool="erase"
      :class="{ active: activeTool === 'erase' }"
      @click.stop="$emit('tool-click', 'erase')"
      title="擦除"
    >
      <el-icon>
        <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" fill="none">
          <path d="M9.5 20h9" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          <path d="M4.6 15.3l7.9-7.9a2.2 2.2 0 0 1 3.1 0l2.9 2.9a2.2 2.2 0 0 1 0 3.1l-5.4 5.4a2 2 0 0 1-1.4.6H7.2a2 2 0 0 1-1.4-.6l-1.2-1.2a2.2 2.2 0 0 1 0-3.1Z" stroke="currentColor" stroke-width="2" stroke-linejoin="round" />
          <path d="M7.6 17.5l8.4-8.4" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      </el-icon>
      <span class="tool-name">擦除</span>
    </div>

    <div
      v-if="showAction"
      class="tool-item"
      data-tool="action"
      :class="{ active: activeTool === 'action' }"
      @click.stop="$emit('tool-click', 'action')"
      title="动作"
    >
      <el-icon>
        <svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true" fill="none">
          <circle cx="12" cy="5" r="2.2" stroke="currentColor" stroke-width="2" />
          <path d="M6 10.5l4-2.5h4l4 2.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M10 8v4.5l-3 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M14 8v4.5l3 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M10 12.5h4" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
      </el-icon>
      <span class="tool-name">动作</span>
    </div>
    
    <div class="divider"></div>
    
    <div 
      class="tool-item delete" 
      data-tool="delete"
      @click.stop="$emit('tool-click', 'delete')"
      title="删除"
      style="margin-left: auto;"
    >
      <el-icon><Delete /></el-icon>
      <span class="tool-name">删除</span>
    </div>
  </div>
</template>

<script setup>
import { Location, EditPen, Brush, Delete } from '@element-plus/icons-vue'

defineProps({
  activeTool: {
    type: String,
    default: ''
  },
  showAction: {
    type: Boolean,
    default: false
  }
})

defineEmits(['tool-click'])
</script>

<style scoped lang="scss">
.canvas-toolbar {
  display: flex;
  align-items: center;
  background: white;
  padding: 6px 12px;
  border-radius: 8px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
  gap: 8px;
  user-select: none;
  pointer-events: auto;
  min-width: 320px;
  
  .tool-item {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s ease-in-out;
    color: #333;
    gap: 4px;
    
    .el-icon {
      font-size: 16px;
    }
    
    .tool-name {
      font-size: 12px;
      font-weight: 500;
    }
    
    &:hover {
      background: #f5f5f5;
    }
    
    &:active {
    }
    
    &.active {
      background: #000;
      color: white;
      
      &:hover {
        background: #333;
      }
    }
    
    &.delete {
      &:hover {
        background: #f5f5f5;
      }
    }
  }
  
  .divider {
    width: 1px;
    height: 24px;
    background: #eee;
    margin: 0 4px;
    flex-grow: 1;
  }
}
</style>
