<template>
  <div class="api-keys-page">
    <div class="page-header">
      <h1>API 密钥</h1>
      <div class="header-actions">
        <el-button class="quick-start-btn" text>
          <el-icon><Document /></el-icon>
          API 快速入门
        </el-button>
        <el-button type="primary" class="create-btn" round>
          <el-icon><Key /></el-icon>
          创建 API 密钥
        </el-button>
      </div>
    </div>

    <div class="filter-bar">
      <div class="left-group">
        <span class="label">分组依据</span>
        <div class="toggle-group">
          <span class="toggle-item active">• API 密钥</span>
          <span class="toggle-item">项目</span>
        </div>
      </div>
      <div class="right-group">
        <span class="label">过滤条件</span>
        <el-select v-model="filterValue" class="filter-select" size="small">
          <el-option label="所有项目" value="all" />
        </el-select>
      </div>
    </div>

    <div class="keys-table">
      <el-table :data="keysList" style="width: 100%" :header-cell-style="{ background: 'transparent', color: '#5f6368', fontWeight: '500' }">
        <el-table-column prop="key" label="密钥" width="180">
          <template #default="scope">
            <span class="key-text">{{ scope.row.key }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="project" label="项目">
          <template #default="scope">
            <div class="project-cell">
              <span class="project-link">{{ scope.row.project }}</span>
              <span class="project-id">{{ scope.row.projectId }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="date" label="创建日期" width="180" />
        <el-table-column prop="quota" label="配额层级">
          <template #default="scope">
            <div class="quota-cell">
              <span class="quota-link">{{ scope.row.quotaLink }}</span>
              <span class="quota-detail">{{ scope.row.quotaDetail }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column align="right">
          <template #default>
            <div class="action-icons">
              <el-icon class="action-icon"><CopyDocument /></el-icon>
              <el-icon class="action-icon"><Delete /></el-icon>
              <el-icon class="action-icon"><MoreFilled /></el-icon>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </div>

    <div class="empty-state">
      <div class="sparkle-icon">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M12 2L14.5 9.5L22 12L14.5 14.5L12 22L9.5 14.5L2 12L9.5 9.5L12 2Z" stroke="#e0e0e0" stroke-width="1"/>
        </svg>
      </div>
      <h3>这里找不到您的 API 密钥？</h3>
      <p class="empty-desc">
        此列表仅显示已导入到 Google AI Studio 的项目的 API 密钥。您可以导入其他项目以管理其关联的 API 密钥。您还可以在上方创建新的 API 密钥。<a href="#">了解详情</a>
      </p>
      <el-button class="import-btn" round>导入项目</el-button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Document, Key, CopyDocument, Delete, MoreFilled } from '@element-plus/icons-vue'

const filterValue = ref('all')

const keysList = [
  {
    key: '...4BuY',
    project: 'ceshixiangmu',
    projectId: 'gen-lang-client-0962291623',
    date: '2025年11月24日',
    quotaLink: '设置结算信息',
    quotaDetail: '免费层级'
  }
]
</script>

<style scoped lang="scss">
.api-keys-page {
  padding: 24px 48px;
  height: 100%;
  box-sizing: border-box;
  color: #1f1f1f;
  font-family: 'Roboto', sans-serif;

  .page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 32px;

    h1 {
      font-size: 24px;
      font-weight: 400;
      margin: 0;
    }

    .header-actions {
      display: flex;
      gap: 12px;

      .quick-start-btn {
        color: #444746;
        font-weight: 500;
        &:hover {
          background-color: #f0f1f3;
        }
      }

      .create-btn {
        background-color: #f0f1f3;
        border: none;
        color: #1f1f1f;
        font-weight: 500;
        &:hover {
          background-color: #e0e2e5;
        }
      }
    }
  }

  .filter-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    font-size: 14px;

    .label {
      color: #5f6368;
      margin-right: 12px;
    }

    .left-group {
      display: flex;
      align-items: center;

      .toggle-group {
        background-color: #f0f1f3;
        border-radius: 8px;
        padding: 2px;
        display: flex;

        .toggle-item {
          padding: 4px 12px;
          border-radius: 6px;
          cursor: pointer;
          color: #444746;
          
          &.active {
            background-color: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            font-weight: 500;
            color: #1f1f1f;
          }
        }
      }
    }

    .right-group {
      display: flex;
      align-items: center;
      
      .filter-select {
        width: 120px;
      }
    }
  }

  .keys-table {
    margin-bottom: 48px;

    .key-text {
      font-family: monospace;
      color: #0b57d0;
    }

    .project-cell {
      display: flex;
      flex-direction: column;
      
      .project-link {
        color: #0b57d0;
        cursor: pointer;
        &:hover { text-decoration: underline; }
      }
      
      .project-id {
        font-size: 12px;
        color: #5f6368;
      }
    }

    .quota-cell {
      display: flex;
      flex-direction: column;
      
      .quota-link {
        color: #0b57d0;
        cursor: pointer;
        &:hover { text-decoration: underline; }
      }
      
      .quota-detail {
        font-size: 12px;
        color: #5f6368;
      }
    }

    .action-icons {
      display: flex;
      gap: 16px;
      justify-content: flex-end;
      color: #5f6368;
      
      .action-icon {
        cursor: pointer;
        &:hover { color: #1f1f1f; }
      }
    }
  }

  .empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    max-width: 600px;
    margin: 0 auto;

    .sparkle-icon {
      margin-bottom: 16px;
      svg path {
        stroke: #c4c7c5;
      }
    }

    h3 {
      font-size: 16px;
      font-weight: 500;
      margin-bottom: 8px;
      color: #1f1f1f;
    }

    .empty-desc {
      font-size: 14px;
      color: #444746;
      line-height: 1.5;
      margin-bottom: 24px;

      a {
        color: #0b57d0;
        text-decoration: none;
        &:hover { text-decoration: underline; }
      }
    }

    .import-btn {
      border-color: #c4c7c5;
      color: #1f1f1f;
      &:hover {
        background-color: #f0f1f3;
        border-color: #c4c7c5;
      }
    }
  }
}
</style>
