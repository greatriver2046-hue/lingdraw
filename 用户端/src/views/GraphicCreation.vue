<template>
  <div class="graphic-creation-container">
    <div v-if="!isGraphicCreationEnabled" class="feature-disabled">功能未开启</div>
    <template v-else>
    <div class="left-panel">
      <div class="panel-header">
        <div class="tabs">
          <span class="tab" :class="{ active: activeTab === 'works' }" @click="activeTab = 'works'">作品</span>
          <span class="tab" :class="{ active: activeTab === 'styles' }" @click="activeTab = 'styles'">风格</span>
        </div>
      </div>

      <div v-if="activeTab === 'works'" class="file-list">
        <div
          v-for="item in filteredWorks"
          :key="item.id"
          class="file-item"
          :class="{ active: activeWorkId === item.id }"
          @click="selectWork(item.id)"
        >
          <div class="file-icon">
            <el-icon><component :is="getWorkIcon(item.type)" /></el-icon>
          </div>
          <div class="file-info">
            <div class="file-name">{{ item.name }}</div>
            <div class="file-meta">{{ item.updatedAtText }}</div>
          </div>
        </div>
      </div>

      <div v-else class="file-list">
        <div
          v-for="item in styleOptions"
          :key="item.style_id"
          class="file-item"
          :class="{ active: generationStore.style === item.style_id }"
          @click="generationStore.style = item.style_id"
        >
          <div class="file-icon">
            <el-icon><MagicStick /></el-icon>
          </div>
          <div class="file-info">
            <div class="file-name">{{ item.name }}</div>
          </div>
          <el-dropdown trigger="click" @command="(cmd) => handleStyleCommand(cmd, item)" @click.stop>
            <el-icon class="style-item-more"><MoreFilled /></el-icon>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="rename">重命名</el-dropdown-item>
                <el-dropdown-item command="delete">删除</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </div>
  </div>

    <div class="panel-gap"></div>

    <!-- Middle Panel: Editor -->
    <div class="main-editor">
      <div class="editor-header">
        <div class="header-left">
          <el-icon v-if="activeTab === 'styles' && resourceViewMode === 'editor'" @click="exitResourceEditor"><ArrowLeft /></el-icon>
          <el-icon><Menu /></el-icon>
          <el-popover
            v-if="activeTab === 'styles'"
            v-model:visible="resourceAddVisible"
            trigger="click"
            placement="bottom-start"
            :width="200"
            popper-class="resource-add-popper"
          >
            <template #reference>
              <el-icon class="header-action"><Plus /></el-icon>
            </template>
            <div class="resource-add-menu">
              <div class="resource-add-item" @click="handleCreateNote">
                <el-icon class="resource-add-icon"><DocumentAdd /></el-icon>
                <div class="resource-add-text">新建笔记</div>
              </div>
              <div class="resource-add-item" @click="handleAddLink">
                <el-icon class="resource-add-icon"><Paperclip /></el-icon>
                <div class="resource-add-text">添加链接</div>
              </div>
              <div class="resource-add-item" @click="handleBatchAddLink">
                <el-icon class="resource-add-icon"><Paperclip /></el-icon>
                <div class="resource-add-text">批量添加链接</div>
              </div>
              <div class="resource-add-item" @click="handleAddFile">
                <el-icon class="resource-add-icon"><Files /></el-icon>
                <div class="resource-add-text">添加文件</div>
              </div>
              <div class="resource-add-item" @click="handleCreateGroup">
                <el-icon class="resource-add-icon"><FolderAdd /></el-icon>
                <div class="resource-add-text">新建分组</div>
              </div>
            </div>
          </el-popover>
          <el-icon v-else class="header-action" @click="createBlankWork"><Plus /></el-icon>
        </div>
        <div class="header-center">
          {{ activeTab === 'works' ? (activeWork ? (activeWork.name || '未命名') : '作品') : (resourceViewMode === 'editor' ? (resourceEditorTitle || '未命名') : '资料列表') }}
        </div>
        <div class="header-right">
          <el-icon><Microphone /></el-icon>
          <el-icon><EditPen /></el-icon>
          <el-icon><Share /></el-icon>
          <el-icon><MoreFilled /></el-icon>
        </div>
      </div>

      <div ref="editorContentWrapperRef" class="editor-content-wrapper" :class="{ 'is-style': activeTab === 'styles' && resourceViewMode !== 'editor' }">
        <template v-if="activeTab === 'works'">
          <!-- Image Sidebar Wrapper - Moved to root of wrapper for correct sticky positioning -->
          <div class="work-image-sidebar-wrapper">
            <div
                  v-if="activeWork"
                  ref="workImageSidebarRef"
                  class="work-image-sidebar"
                  :class="{
                    'is-collapsed': !sidebarExpanded,
                    'panel-neutral': sidebarExpanded && activeSidebarPanel === 'neutral',
                    'panel-references': sidebarExpanded && activeSidebarPanel === 'references'
                  }"
                  :style="workImageSidebarStyle"
                  @transitionend="onWorkImageSidebarTransitionEnd"
                >
              <div class="work-image-sidebar-content">
                <template v-if="sidebarExpanded">
                  <div class="work-image-header">
                    <div class="work-image-title">{{ sidebarTitle }}</div>
                    <div class="work-image-toggle" @click.stop="toggleSidebar">
                      <el-icon><ArrowLeft /></el-icon>
                    </div>
                  </div>
                  
                  <div class="sidebar-panel-container" v-show="activeSidebarPanel === 'images'">
                    <div class="work-image-list">
                      <div v-for="img in workFetchedImageItems" :key="img.key" class="work-image-card">
                        <div class="work-image-thumb-box" @click="openWorkFetchedImage(img.openUrl)">
                          <div v-if="isWorkFetchedImageLoadFailed(img.key)" class="work-image-thumb-error">
                            <el-icon class="work-image-thumb-error-icon"><Picture /></el-icon>
                            <div class="work-image-thumb-error-text">打开失败</div>
                          </div>
                          <img
                            v-else
                            class="work-image-thumb"
                            :src="getWorkFetchedImagePreviewSrc(img)"
                            :alt="img.alt"
                            loading="lazy"
                            @error="() => markWorkFetchedImageLoadFailed(img.key)"
                          />
                          <div v-show="workImageDeleteConfirmKey !== img.key" class="work-image-actions">
                            <el-button size="small" type="primary" class="action-btn action-btn-insert" @click.stop="insertWorkFetchedImage(img.insertUrl)">插入</el-button>
                          </div>
                          <el-icon
                            v-show="workImageDeleteConfirmKey !== img.key"
                            class="work-image-delete-icon"
                            @click.stop="requestDeleteWorkFetchedImage(img)"
                          >
                            <Delete />
                          </el-icon>
                          <div
                            v-if="workImageDeleteConfirmKey === img.key"
                            class="work-image-delete-confirm"
                            @click.stop
                          >
                            <div class="work-image-delete-confirm-text">确定删除该图片？</div>
                            <div class="work-image-delete-confirm-actions">
                              <el-button size="small" @click.stop="cancelWorkFetchedImageDelete">取消</el-button>
                              <el-button size="small" type="danger" @click.stop="confirmDeleteWorkFetchedImage(img)">确认</el-button>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div v-if="workFetchedImageItems.length === 0" class="panel-empty">暂无图片</div>
                    </div>
                  </div>

                  <div class="sidebar-panel-container" v-show="activeSidebarPanel === 'neutral'">
                    <div class="neutral-draft-panel">
                      <div v-if="workArtifactsLoading" class="panel-loading">加载中...</div>
                      <div v-else-if="!workArtifactsNeutralDraft" class="panel-empty">暂无中性稿</div>
                      <transition name="neutral-draft-reveal">
                        <div v-if="showNeutralDraftContent" class="neutral-draft-content">{{ workArtifactsNeutralDraft }}</div>
                      </transition>
                      <div v-if="workArtifactsNeutralDraft" class="neutral-draft-count">字数 {{ neutralDraftCharCount }}</div>
                    </div>
                  </div>

                  <div class="sidebar-panel-container" v-show="activeSidebarPanel === 'references'">
                    <div class="references-panel">
                      <div v-if="workArtifactsLoading" class="panel-loading">加载中...</div>
                      <div v-else class="references-list">
                        <div v-if="workArtifactsFetchedSources.length === 0" class="panel-empty">暂无参考资料</div>
                        <div
                          v-for="item in workArtifactsFetchedSources"
                          :key="item.key"
                          class="reference-card"
                          :class="{ 'is-expanded': isReferenceExpanded(item) }"
                        >
                          <div class="reference-main" @click="toggleReferenceExpanded(item)">
                            <div class="reference-title" :title="item.title">{{ item.title || '无标题' }}</div>
                            <div class="reference-host">{{ item.host }}</div>
                          </div>
                          <transition name="reference-expand">
                            <div v-show="isReferenceExpanded(item)" class="reference-content-wrap" @click.stop>
                              <div class="reference-content-inner">{{ item.content || '暂无正文' }}</div>
                            </div>
                          </transition>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
                
                <div class="sidebar-collapsed-icons" v-else @click.stop>
                  <div class="collapsed-icon-item" :class="{ active: activeSidebarPanel === 'images' }" @click.stop="switchSidebarPanel('images')">
                    <el-tooltip content="图片列表" placement="left">
                      <el-icon><Picture /></el-icon>
                    </el-tooltip>
                    <div v-if="workFetchedImageItems.length" class="icon-badge"></div>
                  </div>
                  <div class="collapsed-icon-item" :class="{ active: activeSidebarPanel === 'neutral' }" @click.stop="switchSidebarPanel('neutral')">
                    <el-tooltip content="中性稿" placement="left">
                      <el-icon><Document /></el-icon>
                    </el-tooltip>
                  </div>
                  <div class="collapsed-icon-item" :class="{ active: activeSidebarPanel === 'references' }" @click.stop="switchSidebarPanel('references')">
                    <el-tooltip content="参考资料" placement="left">
                      <el-icon><Collection /></el-icon>
                    </el-tooltip>
                  </div>
                  <div class="collapsed-toggle" @click.stop="toggleSidebar">
                    <el-icon><ArrowRight /></el-icon>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="editor-content">
            <template v-if="activeWork">
              <el-input
                class="resource-title-input"
                :model-value="activeWork.name"
                placeholder="标题"
                @update:model-value="(v) => updateActiveWorkField(activeWork.id, 'name', v)"
              />
              <div class="work-meta-bar">
                <div class="work-meta-left">
                  <el-tag size="small" type="info">{{ getStyleLabel(activeWork.style_id) }}</el-tag>
                  <el-tag v-if="activeWork.style_profile_id" size="small" type="success">Style Profile #{{ activeWork.style_profile_id }}</el-tag>
                </div>
                <div class="work-meta-right">
                  <el-button v-if="activeWork.task_id && !activeWork.generating && (!activeWork.style_id || !activeWork.style_profile_id)" size="small" @click="openApplyStyleDialog">应用风格</el-button>
                  <el-button v-if="activeWork.task_id && isWorkTaskRunning(activeWork)" size="small" @click="cancelActiveWorkTask">取消</el-button>
                  <el-button size="small" class="work-regenerate-btn" :loading="activeWork.generating" :disabled="activeWork.generating" @click="regenerateActiveWork">重新生成</el-button>
                </div>
              </div>

              <div v-if="activeWork.task_status" class="style-task-status">
                <div class="style-task-line">
                  <span class="style-task-label">状态</span>
                  <span class="style-task-value">{{ workTaskLabel(activeWork.task_status) }}</span>
                </div>
                <div class="style-task-line" v-if="activeWork.task_status.stage">
                  <span class="style-task-label">阶段</span>
                  <span class="style-task-value">{{ workTaskStageLabel(activeWork.task_status.stage) }}</span>
                </div>
                <div class="style-task-progress">
                  <el-progress
                    :percentage="workTaskProgress(activeWork.task_status)"
                    :stroke-width="8"
                    :status="activeWork.task_status.status === 'FAILED' ? 'exception' : (activeWork.task_status.status === 'SUCCEEDED' ? 'success' : undefined)"
                  />
                </div>
                <div class="style-task-line" v-if="activeWork.task_status.status === 'FAILED' && activeWork.task_status.error_message">
                  <span class="style-task-label">错误</span>
                  <span class="style-task-value">{{ activeWork.task_status.error_message }}</span>
                </div>
              </div>

              <div class="work-editor-layout">
                <div class="work-editor-card">
                  <div class="work-editor-toolbar">
                    <el-button-group>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('bold')">B</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('italic')">I</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('underline')">U</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('strikeThrough')">S</el-button>
                    </el-button-group>
                    <div class="work-editor-toolbar-gap"></div>
                    <el-button-group>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('formatBlock', '<h2>')">H2</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('formatBlock', '<h3>')">H3</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('insertUnorderedList')">• List</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('insertOrderedList')">1. List</el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('formatBlock', '<blockquote>')">Quote</el-button>
                    </el-button-group>
                    <div class="work-editor-toolbar-gap"></div>
                    <el-button size="small" @mousedown.prevent @click="openWorkEditorLinkPrompt">
                      <el-icon><Link /></el-icon>
                    </el-button>
                    <el-button size="small" @mousedown.prevent @click="triggerWorkEditorImagePick">
                      <el-icon><Picture /></el-icon>
                    </el-button>

                    <div class="work-editor-toolbar-gap"></div>
                    <el-button-group>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('undo')">
                        <el-icon><RefreshLeft /></el-icon>
                      </el-button>
                      <el-button size="small" @mousedown.prevent @click="applyWorkEditorCommand('redo')">
                        <el-icon><RefreshRight /></el-icon>
                      </el-button>
                    </el-button-group>
                  </div>
                  <div
                    ref="workEditorRef"
                    class="work-rich-editor"
                    contenteditable="true"
                    data-placeholder="开始写作..."
                    @input="onWorkEditorInput"
                    @paste="onWorkEditorPaste"
                    @click="onWorkEditorClick"
                    @focus="onWorkEditorFocus"
                    @blur="onWorkEditorBlur"
                  ></div>
                </div>
              </div>
            </template>
            <el-empty v-else description="暂无作品，点击 + 新建作品" />
          </div>

          <div class="editor-floating-bar">
            <div class="feedback-text">{{ workEditorFooterText }}</div>
            <div class="bar-actions">
              <el-button circle size="small" @click="openCreateWorkDialog"><el-icon><Plus /></el-icon></el-button>
              <el-button circle size="small" class="work-regenerate-icon-btn" @click="regenerateActiveWork"><el-icon><RefreshRight /></el-icon></el-button>
            </div>
          </div>
        </template>

        <template v-else>
          <template v-if="resourceViewMode !== 'editor'">
            <div class="style-articles">
              <div class="style-actions">
                <el-button
                  type="primary"
                  :loading="styleProfileGenerating"
                  :disabled="styleProfileGenerating"
                  @click="startStyleProfileAnalysis"
                >
                  开始分析
                </el-button>
                <el-button
                  v-if="latestStyleProfile"
                  @click="openStyleProfile"
                >
                  查看结果
                </el-button>
                <el-button
                  v-if="latestStyleProfile"
                  @click="downloadStyleProfile"
                >
                  下载 JSON
                </el-button>
                <div v-if="styleProfileTask" class="style-task-status">
                  <div class="style-task-line">
                    <span class="style-task-label">状态</span>
                    <span class="style-task-value">{{ styleProfileTaskLabel }}</span>
                  </div>
                  <div class="style-task-line" v-if="styleProfileTaskPhaseLabel">
                    <span class="style-task-label">阶段</span>
                    <span class="style-task-value">{{ styleProfileTaskPhaseLabel }}</span>
                  </div>
                  <div class="style-task-progress">
                    <el-progress
                      :percentage="styleProfileTaskProgress"
                      :stroke-width="8"
                      :status="styleProfileTask?.status === 'failed' ? 'exception' : (styleProfileTask?.status === 'success' ? 'success' : undefined)"
                    />
                  </div>
                  <div class="style-task-line" v-if="styleProfileTask?.status === 'failed' && styleProfileTask?.error">
                    <span class="style-task-label">错误</span>
                    <span class="style-task-value">{{ styleProfileTask.error }}</span>
                  </div>
                </div>
              </div>
              <div class="articles-list">
                <div
                  v-for="a in currentStyleArticles"
                  :key="a.id"
                  class="article-row"
                  :class="{ active: activeArticleId === a.id }"
                  @click="handleResourceClick(a)"
                >
                  <div class="article-left">
                    <el-icon class="article-icon"><component :is="getResourceIcon(a)" /></el-icon>
                  </div>
                  <div class="article-body">
                    <div class="article-title-row">
                      <div class="article-title">{{ a.title }}</div>
                      <el-icon class="article-more"><MoreFilled /></el-icon>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
          <template v-else>
            <div v-if="resourceEditorLoading" class="resource-loading">{{ resourceEditorLoadingText }}</div>
            <div v-else class="editor-content">
              <el-input v-model="resourceEditorTitle" class="resource-title-input" placeholder="标题" />
              <div
                ref="resourceEditorRef"
                class="resource-rich-editor"
                contenteditable="true"
                data-placeholder="开始写作..."
                @input="onResourceEditorInput"
                @paste="onResourceEditorPaste"
                @click="onResourceEditorClick"
                @focus="onResourceEditorFocus"
                @blur="onResourceEditorBlur"
              ></div>
            </div>
            <div class="editor-floating-bar">
              <div class="feedback-text">{{ resourceEditorFooterText }}</div>
              <div class="bar-actions">
                <el-button size="small" type="primary" :loading="resourceSaving" @click="saveResource">保存</el-button>
              </div>
            </div>
          </template>
        </template>
      </div>
    </div>

    <el-dialog class="create-work-dialog" v-model="createWorkDialogVisible" title="新建作品" width="560px" align-center>
      <el-form label-position="top">
        <el-form-item label="主题/要点">
          <el-input
            v-model="createWorkForm.topic"
            :disabled="createWorkSubmitting"
            type="textarea"
            :autosize="{ minRows: 4, maxRows: 8 }"
            placeholder="写给谁、写什么、要包含哪些点"
          />
        </el-form-item>
        <div class="create-work-row">
          <el-form-item label="字数" class="create-work-item">
            <el-input v-model="createWorkForm.word_count" :disabled="createWorkSubmitting" placeholder="例如：1200" />
          </el-form-item>
        </div>
        <el-form-item label="风格（Style）">
          <el-select v-model="createWorkForm.style_id" :disabled="createWorkSubmitting" placeholder="不选择风格（可选）" filterable clearable @change="handleCreateWorkStyleChange">
            <el-option v-for="s in styleOptions" :key="s.style_id" :label="s.name" :value="s.style_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Style Profile" v-if="createWorkForm.style_id">
          <el-select
            v-model="createWorkForm.style_profile_id"
            :disabled="createWorkSubmitting || styleProfileOptionsLoading"
            placeholder="选择已生成的 Style Profile"
            filterable
          >
            <el-option v-for="p in styleProfileOptions" :key="p.id" :label="p.label" :value="p.id" />
          </el-select>
          <div v-if="!styleProfileOptionsLoading && styleProfileOptions.length === 0" class="create-work-hint">
            该风格暂无已生成的 Style Profile，请先在“风格”页开始分析
          </div>
        </el-form-item>
        <el-form-item label="Style Profile" v-else>
          <div class="create-work-hint">未选择风格：中性稿完成后可选择是否进行风格迁移</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="createWorkSubmitting" @click="createWorkDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="createWorkSubmitting" @click="confirmCreateWork">开始创作</el-button>
      </template>
    </el-dialog>

    <el-dialog class="apply-style-dialog" v-model="applyStyleDialogVisible" title="应用风格" width="560px" align-center>
      <el-form label-position="top">
        <el-form-item label="风格（Style）">
          <el-select v-model="applyStyleForm.style_id" :disabled="applyStyleSubmitting" placeholder="请选择风格" filterable clearable @change="handleApplyStyleStyleChange">
            <el-option v-for="s in styleOptions" :key="s.style_id" :label="s.name" :value="s.style_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="Style Profile" v-if="applyStyleForm.style_id">
          <el-select
            v-model="applyStyleForm.style_profile_id"
            :disabled="applyStyleSubmitting || applyStyleProfileOptionsLoading"
            placeholder="选择已生成的 Style Profile"
            filterable
          >
            <el-option v-for="p in applyStyleProfileOptions" :key="p.id" :label="p.label" :value="p.id" />
          </el-select>
          <div v-if="!applyStyleProfileOptionsLoading && applyStyleProfileOptions.length === 0" class="create-work-hint">
            该风格暂无已生成的 Style Profile，请先在“风格”页开始分析
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="applyStyleSubmitting" @click="confirmSkipStyleTransfer">直接使用中性稿</el-button>
        <el-button :disabled="applyStyleSubmitting" @click="applyStyleDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="applyStyleSubmitting" @click="confirmApplyStyle">开始风格迁移</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="linkDialogVisible" title="添加链接" width="460px" align-center>
      <el-form label-position="top">
        <el-form-item label="链接">
          <el-input v-model="linkForm.url" :disabled="linkSubmitting" placeholder="https://example.com" />
        </el-form-item>
        <el-form-item label="标题（可选）">
          <el-input v-model="linkForm.title" :disabled="linkSubmitting" placeholder="链接标题" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button :disabled="linkSubmitting" @click="linkDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="linkSubmitting" @click="confirmAddLink">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="batchLinkDialogVisible" title="批量添加链接" width="640px" align-center>
      <el-form label-position="top">
        <el-form-item label="链接列表（一行一个）">
          <el-input
            v-model="batchLinkForm.urls"
            :disabled="batchLinkSubmitting"
            type="textarea"
            :autosize="{ minRows: 6, maxRows: 12 }"
            placeholder="https://example.com/a&#10;https://example.com/b"
          />
        </el-form-item>
        <el-form-item label="标题前缀（可选）">
          <el-input v-model="batchLinkForm.titlePrefix" :disabled="batchLinkSubmitting" placeholder="例如：竞品-" />
        </el-form-item>

        <div v-if="batchLinkProgress.total > 0" class="batch-link-progress">
          <div class="batch-link-progress-row">
            <div>总数：{{ batchLinkProgress.total }}</div>
            <div>成功：{{ batchLinkProgress.success }}</div>
            <div>失败：{{ batchLinkProgress.failed }}</div>
            <div>进度：{{ batchLinkProgress.done }}/{{ batchLinkProgress.total }}</div>
          </div>
          <div class="batch-link-results">
            <div v-for="r in batchLinkResults" :key="r.key" class="batch-link-result-row" :class="r.status">
              <div class="batch-link-result-url" :title="r.url">{{ r.url }}</div>
              <div class="batch-link-result-status">{{ r.statusText }}</div>
            </div>
          </div>
        </div>
      </el-form>
      <template #footer>
        <el-button :disabled="batchLinkSubmitting" @click="batchLinkDialogVisible = false">关闭</el-button>
        <el-button type="primary" :loading="batchLinkSubmitting" @click="confirmBatchAddLink">开始抓取</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="groupDialogVisible" title="新建分组" width="460px" align-center>
      <el-form label-position="top">
        <el-form-item label="分组名称">
          <el-input v-model="groupForm.name" placeholder="例如：竞品参考" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="groupDialogVisible = false">取消</el-button>
        <el-button type="primary" @click="confirmCreateGroup">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="styleProfileDialogVisible" title="Style Profile" width="820px" align-center destroy-on-close>
      <div class="style-profile-dialog-body">
        <el-tabs v-model="styleProfileTab" class="style-profile-tabs">
          <el-tab-pane label="可视化" name="visual">
            <div class="style-profile-visual">
              <el-tree
                v-if="styleProfileTreeData.length"
                :data="styleProfileTreeData"
                node-key="id"
                :expand-on-click-node="false"
                :default-expand-all="true"
                class="style-profile-tree"
              />
              <el-empty v-else description="暂无可展示内容" />
            </div>
          </el-tab-pane>
          <el-tab-pane label="原始 JSON" name="raw">
            <el-input
              v-model="styleProfileJsonText"
              type="textarea"
              :autosize="{ minRows: 18, maxRows: 28 }"
              readonly
            />
          </el-tab-pane>
        </el-tabs>
      </div>
      <template #footer>
        <el-button @click="styleProfileDialogVisible = false">关闭</el-button>
        <el-button v-if="latestStyleProfile" type="primary" @click="downloadStyleProfile">下载 JSON</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="workArtifactsDialogVisible" title="阶段产物" width="920px" align-center destroy-on-close>
      <div class="work-artifacts-body">
        <div v-if="workArtifactsLoading" class="work-artifacts-loading">加载中...</div>
        <template v-else>
          <el-tabs v-model="workArtifactsTab" class="work-artifacts-tabs">
            <el-tab-pane label="中性稿" name="neutral_draft">
              <template v-if="workArtifactsNeutralDraft">
                <el-input
                  v-model="workArtifactsNeutralDraft"
                  type="textarea"
                  :autosize="{ minRows: 18, maxRows: 28 }"
                  readonly
                />
              </template>
              <el-empty v-else description="暂无中性稿" />
            </el-tab-pane>
            <el-tab-pane label="参考资料" name="references">
              <div class="work-artifacts-section">
                <div class="work-artifacts-section-title">搜索结果</div>
                <template v-if="workArtifactsSources.length">
                  <div class="work-status-source-list">
                    <div
                      v-for="(it, idx) in workArtifactsSources"
                      :key="it.key || it.url || idx"
                      class="work-status-source-item"
                    >
                      <div class="work-status-source-row">
                        <span class="work-status-source-index">{{ idx + 1 }}.</span>
                        <a
                          v-if="it.url"
                          class="work-status-source-title"
                          :href="it.url"
                          target="_blank"
                          rel="noreferrer"
                        >{{ it.title || it.url }}</a>
                        <span v-else class="work-status-source-title">{{ it.title }}</span>
                        <span v-if="it.host" class="work-status-source-host">{{ it.host }}</span>
                      </div>
                    </div>
                  </div>
                </template>
                <el-empty v-else description="暂无搜索结果" />
              </div>
              <div class="work-artifacts-section">
                <div class="work-artifacts-section-title">抓取完成</div>
                <template v-if="workArtifactsFetchedSources.length">
                  <div class="work-status-source-list">
                    <div
                      v-for="(it, idx) in workArtifactsFetchedSources"
                      :key="it.key || it.url || idx"
                      class="work-status-source-item"
                    >
                      <div class="work-status-source-row">
                        <span class="work-status-source-index">{{ idx + 1 }}.</span>
                        <a
                          v-if="it.url"
                          class="work-status-source-title"
                          :href="it.url"
                          target="_blank"
                          rel="noreferrer"
                        >{{ it.title || it.url }}</a>
                        <span v-else class="work-status-source-title">{{ it.title }}</span>
                        <span v-if="it.host" class="work-status-source-host">{{ it.host }}</span>
                      </div>
                      <div v-if="it.excerpt" class="work-status-source-excerpt">{{ it.excerpt }}</div>
                    </div>
                  </div>
                </template>
                <el-empty v-else description="暂无抓取资料" />
              </div>
            </el-tab-pane>
          </el-tabs>
        </template>
      </div>
      <template #footer>
        <el-button @click="workArtifactsDialogVisible = false">关闭</el-button>
      </template>
    </el-dialog>

    <el-image-viewer
      v-if="showWorkImageViewer"
      :url-list="workImageViewerUrlList"
      @close="closeWorkImageViewer"
      :hide-on-click-modal="true"
    />

    <input ref="fileInputRef" class="hidden-file-input" type="file" @change="handleFilePicked" />
    <input ref="workImageInputRef" class="hidden-file-input" type="file" accept="image/*" multiple @change="handleWorkEditorImagePicked" />

    <div v-if="activeTab === 'works'" class="resize-handle" @mousedown="startResize"></div>

    <!-- Right Sidebar: Chat -->
    <div v-if="activeTab === 'works'" class="right-panel" :style="{ width: sidebarWidth + 'px' }">
      <div class="chat-panel">
        <div class="chat-header">
          <h2>对话</h2>
          <div class="header-actions">
            <el-button
              v-if="chatStore.currentConversationId.value"
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
            v-for="msg in chatStore.messages.value"
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
                    <path d="M6 5C6 5.8 6.8 6.5 8 6.5C6.8 6.5 6 7.2 6 8C6 7.2 5.2 6.5 4 6.5C5.2 6 6 5.8 6 5Z" fill="#D1D5DB" stroke="#D1D5DB" stroke-width="2.5" stroke-linejoin="round"/>
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
                    <el-image v-else :src="seg.value" :preview-src-list="[seg.value]" :hide-on-click-modal="true" fit="cover" class="inline-image" />
                  </template>
                </template>
              </div>
              <div v-if="msg.styleTransferCard" class="style-transfer-card">
                <div class="card-title-row">
                  <div class="card-title">
                    <el-icon><MagicStick /></el-icon>
                    <span>风格选择</span>
                  </div>
                  <div v-if="msg.styleTransferCard.workTitle" class="card-subtitle">{{ msg.styleTransferCard.workTitle }}</div>
                </div>
                <div class="card-desc">中性稿已完成，是否继续生成风格稿？</div>
                <div v-if="msg.styleTransferCard.decidedText" class="card-decision">{{ msg.styleTransferCard.decidedText }}</div>
                <div v-if="(msg.styleTransferCard.choices || []).length" class="card-controls">
                  <el-select
                    v-model="msg.styleTransferCard.selectedKey"
                    filterable
                    placeholder="选择一个 Style Profile"
                    size="small"
                    class="card-select"
                    :disabled="applyStyleSubmitting || msg.styleTransferCard.decided"
                  >
                    <el-option
                      v-for="it in msg.styleTransferCard.choices"
                      :key="it.key"
                      :label="it.label"
                      :value="it.key"
                    />
                  </el-select>
                  <div class="card-actions">
                    <el-button
                      size="small"
                      type="primary"
                      :loading="applyStyleSubmitting && msg.styleTransferCard.loadingAction === 'apply'"
                      :disabled="applyStyleSubmitting || msg.styleTransferCard.decided || !msg.styleTransferCard.selectedKey"
                      @click="applyStyleFromCard(msg)"
                    >生成风格稿</el-button>
                    <el-button
                      size="small"
                      :loading="applyStyleSubmitting && msg.styleTransferCard.loadingAction === 'skip'"
                      :disabled="applyStyleSubmitting || msg.styleTransferCard.decided"
                      @click="skipStyleFromCard(msg)"
                    >直接使用中性稿</el-button>
                    <el-button
                      size="small"
                      link
                      :disabled="applyStyleSubmitting"
                      @click="openApplyStyleDialogFromCard(msg)"
                    >更多设置</el-button>
                  </div>
                </div>
                <div v-else class="card-empty">
                  <div class="card-empty-text">暂无可选 Style Profile</div>
                  <div class="card-actions">
                    <el-button
                      size="small"
                      :loading="applyStyleSubmitting && msg.styleTransferCard.loadingAction === 'skip'"
                      :disabled="applyStyleSubmitting || msg.styleTransferCard.decided"
                      @click="skipStyleFromCard(msg)"
                    >直接使用中性稿</el-button>
                    <el-button
                      size="small"
                      link
                      :disabled="applyStyleSubmitting"
                      @click="openApplyStyleDialogFromCard(msg)"
                    >去生成 Style Profile</el-button>
                  </div>
                </div>
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
                <div class="image-card loading">
                  <div class="image-skeleton" :style="getSkeletonStyle(msg.pendingTool)"></div>
                  <div class="meta">
                    <div class="name">{{ msg.pendingTool.imageName || '生成图像' }}</div>
                    <div class="spec">
                      模型：{{ msg.pendingTool.modelName }} · {{ msg.pendingTool.statusText || '生成中…' }}
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
                  <el-image :src="msg.toolResult.image_url" :preview-src-list="[msg.toolResult.image_url]" :hide-on-click-modal="true" fit="contain" @load="onImageLoad(msg.id)" />
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

          <div v-if="isProcessing" class="message-item">
            <div class="message-content">
              <div class="bubble loading-bubble">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" class="ai-icon processing-icon">
                  <path d="M16 4C16 9 19 12 23 12C19 12 16 15 16 20C16 15 13 12 9 12C13 12 16 9 16 4Z" fill="#1f1f1f" stroke="#1f1f1f" stroke-width="2.5" stroke-linejoin="round"/>
                  <path d="M6 14C6 16.5 8 18 10 18C8 18 6 19.5 6 22C6 19.5 4 18 2 18C4 18 6 16.5 6 14Z" fill="#9CA3AF" stroke="#9CA3AF" stroke-width="2.5" stroke-linejoin="round"/>
                  <path d="M6 5C6 5.8 6.8 6.5 8 6.5C6.8 6.5 6 7.2 6 8C6 7.2 5.2 6.5 4 6.5C5.2 6 6 5.8 6 5Z" fill="#D1D5DB" stroke="#D1D5DB" stroke-width="2.5" stroke-linejoin="round"/>
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
            <div
              class="input-box-container"
              :class="{ 'drag-over': isInputDragOver }"
              @dragenter="handleInputDragEnter"
              @dragleave="handleInputDragLeave"
              @dragover.prevent
              @drop.prevent="handleInputDrop"
            >
              <div class="input-area">
                <div
                  class="rich-input-wrapper"
                  @click="focusRichInput"
                >
                  <div
                    ref="richInputRef"
                    class="rich-input"
                    contenteditable="true"
                    :placeholder="isPolishEnabled ? '描述你想写的文章需求' : '请输入文章主题或写作要求'"
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
                    :content="isPolishEnabled ? '智能体理解你的需求' : '请直接输入提示词'"
                    placement="top"
                  >
                    <el-switch
                      v-model="isPolishEnabled"
                      class="polish-switch"
                      inline-prompt
                      active-text="智能体"
                      inactive-text="提示词"
                      width="55"
                    />
                  </el-tooltip>
                  <el-button
                    class="send-btn"
                    circle
                    @click="handleSend"
                    :disabled="!canSend"
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
    </div>
    <el-dialog v-model="editMarkerVisible" title="编辑标记内容" width="400px" append-to-body>
      <el-input v-model="editMarkerContent" type="textarea" :rows="3" />
      <template #footer>
        <el-button @click="editMarkerVisible = false">取消</el-button>
        <el-button type="primary" @click="saveMarkerContent">确定</el-button>
      </template>
    </el-dialog>
    </template>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, computed, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { 
  Document, Files, Plus, ArrowLeft,
  Menu, Microphone, EditPen, Share, MoreFilled, 
  RefreshRight, RefreshLeft, Link, Picture,
  Delete, ArrowDown, Top, Warning, CopyDocument,
  MagicStick,
  DocumentAdd,
  Paperclip,
  FolderAdd
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox, ElImageViewer } from 'element-plus'
import MarkdownIt from 'markdown-it'
import { useGenerationStore } from '@/stores/generation'
import { useAppStore } from '@/stores/app'
import request from '@/utils/request'
import { config } from '@/config'
import { wsManager } from '@/utils/websocket'

const generationStore = useGenerationStore()
const appStore = useAppStore()
const route = useRoute()
const router = useRouter()

const isGraphicCreationEnabled = computed(() => {
  const v = appStore.siteConfig?.graphic_creation_enabled
  if (v === 0 || v === '0' || v === false) return false
  if (v === 1 || v === '1' || v === true) return true
  return true
})

const createGraphicChatStore = () => {
  const buildInitialMessages = () => ([
    {
      id: Date.now(),
      role: 'assistant',
      content: '你好！为了开始图文创作，请先提供文章主题。',
      timestamp: Date.now()
    }
  ])
  const messages = ref(buildInitialMessages())
  const currentConversationId = ref(null)
  const loadingCount = ref(0)
  const streamTicker = ref(0)
  const isLoading = computed(() => loadingCount.value > 0)
  const streamingCount = ref(0)
  const isQueueActive = ref(false)
  const isStreaming = computed(() => streamingCount.value > 0 || isQueueActive.value)
  const streamQueue = {}
  let streamTimer = null
  const startStreamProcessor = () => {
    isQueueActive.value = true
    if (streamTimer) return
    streamTimer = setInterval(() => {
      let active = false
      for (const id in streamQueue) {
        if (streamQueue[id] && streamQueue[id].length > 0) {
          active = true
          const q = streamQueue[id]
          const step = q.length > 200 ? 5 : (q.length > 50 ? 2 : 1)
          const chunk = q.slice(0, step)
          streamQueue[id] = q.slice(step)
          const m = messages.value.find(x => String(x.id) === String(id))
          if (m) {
            m.content = (m.content || '') + chunk
            streamTicker.value++
          } else {
            delete streamQueue[id]
          }
        } else {
          delete streamQueue[id]
        }
      }
      if (!active) {
        isQueueActive.value = false
        if (streamingCount.value === 0) {
          clearInterval(streamTimer)
          streamTimer = null
        }
      } else {
        isQueueActive.value = true
      }
    }, 30)
  }
  const newMessageId = () => Date.now() + Math.floor(Math.random() * 1000)
  const ensureConversation = () => {
    if (!currentConversationId.value) {
      let workId = ''
      try { workId = String(activeWorkId?.value || '').trim() } catch { workId = '' }
      currentConversationId.value = workId ? `graphic_work_${workId}` : `graphic_${Date.now()}`
    }
  }
  const replaceConversation = (conversationId, nextMessages) => {
    currentConversationId.value = conversationId ? String(conversationId) : null
    const list = Array.isArray(nextMessages) && nextMessages.length ? nextMessages : buildInitialMessages()
    messages.value = list
    streamTicker.value++
  }
  const resetConversation = () => {
    currentConversationId.value = null
    messages.value = buildInitialMessages()
    streamTicker.value++
  }
  const setConversationId = (conversationId) => {
    currentConversationId.value = conversationId ? String(conversationId) : null
    streamTicker.value++
  }
  const pushMessage = (msg) => {
    messages.value.push(msg)
    streamTicker.value++
  }
  const addUserMessage = (content) => {
    ensureConversation()
    pushMessage({ id: newMessageId(), role: 'user', content, timestamp: Date.now() })
  }
  const addAssistantMessage = (content, extra = {}) => {
    const id = newMessageId()
    pushMessage({ id, role: 'assistant', content, timestamp: Date.now(), ...extra })
    return id
  }
  const updateMessage = (id, patch) => {
    const idx = messages.value.findIndex(m => String(m.id) === String(id))
    if (idx < 0) return
    messages.value.splice(idx, 1, { ...messages.value[idx], ...patch })
    streamTicker.value++
  }
  const enqueueStream = (id, txt) => {
    const key = id !== undefined && id !== null ? String(id) : ''
    const t = typeof txt === 'string' ? txt : (txt?.toString() || '')
    if (!key || !t) return
    streamQueue[key] = (streamQueue[key] || '') + t
    startStreamProcessor()
  }
  const deleteMessage = (id) => {
    messages.value = messages.value.filter(m => String(m.id) !== String(id))
    streamTicker.value++
  }
  const clearConversationMessages = () => {
    if (!currentConversationId.value) return
    messages.value = buildInitialMessages()
    streamTicker.value++
  }
  const deleteConversation = () => {
    resetConversation()
  }
  const setLoading = (val) => {
    if (val) {
      loadingCount.value++
    } else {
      loadingCount.value = Math.max(0, loadingCount.value - 1)
    }
  }
  const setStreaming = (val) => {
    if (val) {
      streamingCount.value++
    } else {
      streamingCount.value = Math.max(0, streamingCount.value - 1)
    }
  }
  return {
    messages,
    currentConversationId,
    isLoading,
    isStreaming,
    streamTicker,
    replaceConversation,
    resetConversation,
    setConversationId,
    addUserMessage,
    addAssistantMessage,
    updateMessage,
    enqueueStream,
    deleteMessage,
    clearConversationMessages,
    deleteConversation,
    setLoading,
    setStreaming
  }
}

const chatStore = createGraphicChatStore()

const md = new MarkdownIt({
  html: false,
  breaks: true,
  linkify: true
})

const htmlToPlainText = (html) => {
  const s = String(html ?? '')
  if (!s) return ''
  try {
    const div = document.createElement('div')
    div.innerHTML = s
    return String(div.textContent || '')
  } catch {
    return s.replace(/<[^>]*>/g, ' ')
  }
}

const markdownToPlainText = (markdown) => {
  let s = String(markdown ?? '')
  if (!s) return ''
  s = s.replace(/!\[[^\]]*\]\([^)]*\)/g, '')
  s = s.replace(/\[([^\]]+)\]\(([^)]*)\)/g, '$1')
  s = s.replace(/```/g, '')
  s = s.replace(/~~~/g, '')
  s = s.replace(/^\s{0,3}#{1,6}\s+/gm, '')
  s = s.replace(/^\s{0,3}>\s?/gm, '')
  s = s.replace(/^\s{0,3}([-*+])\s+/gm, '')
  s = s.replace(/^\s{0,3}\d+\.\s+/gm, '')
  return s
}

const countChars = (text) => {
  const raw = String(text ?? '')
  if (!raw.trim()) return 0

  const cjkRe = /[\u3400-\u4DBF\u4E00-\u9FFF\uF900-\uFAFF\u3040-\u309F\u30A0-\u30FF\uAC00-\uD7AF]/g
  const cjkMatches = raw.match(cjkRe)
  const cjkCount = cjkMatches ? cjkMatches.length : 0

  const noCjk = raw.replace(cjkRe, ' ')
  const wordRe = /[A-Za-z0-9]+(?:['-][A-Za-z0-9]+)*/g
  const wordMatches = noCjk.match(wordRe)
  const wordCount = wordMatches ? wordMatches.length : 0

  const rest = noCjk.replace(wordRe, ' ').replace(/\s+/g, '')
  const restCount = rest ? Array.from(rest).length : 0
  return cjkCount + wordCount + restCount
}

const sidebarWidth = ref(Number(localStorage.getItem('app_graphic_chat_sidebar_width')) || 450)
watch(sidebarWidth, (val) => {
  localStorage.setItem('app_graphic_chat_sidebar_width', String(val))
})

const activeTab = ref('works')

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

const renderMarkdown = (text) => {
  if (!text) return ''
  let html = md.render(text)
  html = html.replace(/\[标记点(\d+)[：:]\s*(.*?)\]/g, (match, numStr, content) => {
    const num = parseInt(numStr)
    const marker = generationStore.markerPrompts.find(m => m.number === num)
    let thumbnailHtml = ''
    if (marker && marker.thumbnail) {
      thumbnailHtml = `<img src="${marker.thumbnail}" class="chip-thumbnail" />`
    }
    return `<span class="marker-chip">
      <span class="chip-icon">${num}</span>
      ${thumbnailHtml}
      <span class="chip-content">${content}</span>
    </span>`
  })
  return html
}

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
  const list = chatStore.messages.value || []
  if (list.some(m => m.thinking)) {
    return false
  }
  return chatStore.isLoading.value || list.some(m => m.pendingTool && !m.toolResult && !m.isError)
})

const controlsBarRef = ref(null)
const leftControlsRef = ref(null)
const rightControlsRef = ref(null)
let resizeObserver = null

const handleWidthExpand = (neededWidth) => {
  if (neededWidth > sidebarWidth.value) {
    const maxAllowed = window.innerWidth * 0.65
    sidebarWidth.value = Math.min(neededWidth, maxAllowed)
  }
}

const checkToolbarWidth = () => {
  if (!controlsBarRef.value || !leftControlsRef.value || !rightControlsRef.value) return
  const containerW = controlsBarRef.value.offsetWidth
  const leftW = leftControlsRef.value.scrollWidth
  const rightW = rightControlsRef.value.scrollWidth
  const minGap = 20
  const padding = 60
  if (leftW + rightW + minGap > containerW) {
    handleWidthExpand(leftW + rightW + minGap + padding)
  }
}

onMounted(() => {
  if (controlsBarRef.value) {
    resizeObserver = new ResizeObserver(() => {
      checkToolbarWidth()
    })
    resizeObserver.observe(controlsBarRef.value)
    if (leftControlsRef.value) resizeObserver.observe(leftControlsRef.value)
    if (rightControlsRef.value) resizeObserver.observe(rightControlsRef.value)
    checkToolbarWidth()
  }
  scrollToBottom()
  nextTick(() => {
    if (chatStore.currentConversationId.value) {
      restoreDraft(loadDraftFor(chatStore.currentConversationId.value))
    }
  })
})

onUnmounted(() => {
  const wid = String(activeWorkId.value || '').trim()
  if (wid) {
    persistWorkChatNow(wid)
  }
  if (persistWorkChatTimer) {
    clearTimeout(persistWorkChatTimer)
    persistWorkChatTimer = null
  }
  saveDraftFor(chatStore.currentConversationId.value)
  if (resizeObserver) resizeObserver.disconnect()
})

const handleDelete = () => {
  if (!chatStore.currentConversationId.value) return
  ElMessageBox.confirm(
    '确定要清空当前对话吗？',
    '清空确认',
    {
      confirmButtonText: '清空',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(() => {
      chatStore.clearConversationMessages()
      resetComposer()
    })
    .catch(() => {})
}

const confirmDeleteMessage = (msg) => {
  ElMessageBox.confirm(
    '确定要删除此消息吗？',
    '删除确认',
    {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning',
    }
  )
    .then(() => {
      chatStore.deleteMessage(msg.id)
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

const currentVideoModel = computed(() => {
  return generationStore.videoModels.find(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
})

const isVideoModelSelected = computed(() => {
  return generationStore.videoModels.some(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
})

const isImageModelSelected = computed(() => {
  return generationStore.imageModels.some(m => m.model_identity === generationStore.selectedImageModel)
})

const currentImageModel = computed(() => {
  return generationStore.imageModels.find(m => m.model_identity === generationStore.selectedImageModel)
})

const estimatedPoints = computed(() => {
  let cost = 0
  if (isImageModelSelected.value && currentImageModel.value) {
    const model = currentImageModel.value
    if (model.cost_per_request) {
      cost += parseInt(model.cost_per_request)
    }
    if (generationStore.imageResolution) {
      const config = currentResolutionConfig.value
      const resConfig = config.find(c => c.value === generationStore.imageResolution)
      if (resConfig && resConfig.price) {
        cost += parseInt(resConfig.price)
      }
    }
  }
  if (isVideoModelSelected.value && currentVideoModel.value) {
    const model = currentVideoModel.value
    if (model.cost_per_request) {
      cost += parseInt(model.cost_per_request)
    }
    const config = model.config || {}
    if (generationStore.videoAspectRatio && config.aspect_ratios) {
      const opt = config.aspect_ratios.find(o => o.value === generationStore.videoAspectRatio)
      if (opt && opt.price) cost += parseInt(opt.price)
    }
    if (generationStore.videoDuration && config.durations) {
      const opt = config.durations.find(o => o.value === generationStore.videoDuration)
      if (opt && opt.price) cost += parseInt(opt.price)
    }
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
    } catch {
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

watch(
  [currentSettingsLabel],
  () => {
    nextTick(() => {
      checkToolbarWidth()
    })
  }
)

onMounted(() => {
  generationStore.referenceImages = []
  generationStore.fetchImageModels()
  generationStore.fetchVideoModels()
})

const scrollToBottom = () => {
  const doScroll = () => {
    if (bottomAnchor.value) {
      bottomAnchor.value.scrollIntoView({ behavior: 'smooth', block: 'end' })
    } else if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight + 500
    }
  }
  nextTick(doScroll)
  setTimeout(doScroll, 100)
}

const isPolishEnabled = ref(localStorage.getItem('app_chat_polish_enabled') !== 'false')
watch(isPolishEnabled, (val) => {
  localStorage.setItem('app_chat_polish_enabled', val)
})

watch(
  () => generationStore.prompt,
  (newVal) => {
    if (newVal !== undefined && newVal !== '') {
      inputValue.value = newVal
      if (richInputRef.value) {
        richInputRef.value.innerText = newVal
      }
      generationStore.prompt = ''
    }
  }
)

watch(
  () => chatStore.messages.value,
  () => {
    scrollToBottom()
  },
  { deep: true }
)

const editMarkerVisible = ref(false)
const editMarkerContent = ref('')
const currentEditingMarkerNum = ref(null)

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
  } catch {
    return raw
  }
  return raw
}

const resetComposer = () => {
  inputValue.value = ''
  if (richInputRef.value) {
    richInputRef.value.innerHTML = ''
  }
  insertedMarkerIds.value = new Set()
  generationStore.referenceImages = []
  generationStore.clearMarkerPrompts()
}

watch(
  () => chatStore.currentConversationId.value,
  (newVal, oldVal) => {
    if (String(newVal || '') !== String(oldVal || '')) {
      saveDraftFor(oldVal)
      resetComposer()
      if (newVal) {
        nextTick(() => {
          restoreDraft(loadDraftFor(newVal))
        })
      }
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
  if (!range.collapsed) return
  let prevNode = null
  if (range.startContainer.nodeType === Node.TEXT_NODE && range.startOffset === 0) {
    prevNode = range.startContainer.previousSibling
  } else if (range.startContainer.nodeType === Node.ELEMENT_NODE && range.startOffset > 0) {
    prevNode = range.startContainer.childNodes[range.startOffset - 1]
  }
  if (prevNode && prevNode.nodeType === Node.ELEMENT_NODE && prevNode.classList.contains('marker-chip')) {
    e.preventDefault()
    prevNode.remove()
    onRichInput()
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

watch(() => generationStore.markerPrompts, (newMarkers) => {
  if (!richInputRef.value) return
  newMarkers.forEach(m => {
    if (!insertedMarkerIds.value.has(m.id)) {
      insertMarkerChip(m)
      insertedMarkerIds.value.add(m.id)
    } else {
      updateMarkerChipDOM(m)
    }
  })
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
    thumbnailHtml = `<img src="${marker.thumbnail}" class="chip-thumbnail" />`
  }
  chip.innerHTML = `
    <span class="chip-icon">${marker.number}</span>
    ${thumbnailHtml}
    <span class="chip-content">${marker.status === 'loading' ? '识别中...' : (marker.content || '无内容')}</span>
  `
  chip.addEventListener('click', (e) => {
    e.stopPropagation()
    handleEditMarker(marker)
  })
  richInputRef.value.appendChild(chip)
  richInputRef.value.appendChild(document.createTextNode('\u00A0'))
  richInputRef.value.scrollTop = richInputRef.value.scrollHeight
  triggerInput()
}

const updateMarkerChipDOM = (marker) => {
  const chip = richInputRef.value.querySelector(`.marker-chip[data-id="${marker.id}"]`)
  if (chip) {
    const contentSpan = chip.querySelector('.chip-content')
    if (contentSpan) {
      const newText = marker.status === 'loading' ? '识别中...' : (marker.content || '无内容')
      if (contentSpan.innerText !== newText) {
        contentSpan.innerText = newText
        triggerInput()
      }
    }
    if (marker.thumbnail) {
      let img = chip.querySelector('.chip-thumbnail')
      if (!img) {
        const icon = chip.querySelector('.chip-icon')
        img = document.createElement('img')
        img.className = 'chip-thumbnail'
        img.src = marker.thumbnail
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

const handleEditMarker = (marker) => {
  if (marker.status === 'loading') return
  currentEditingMarkerNum.value = marker.number
  editMarkerContent.value = marker.content
  editMarkerVisible.value = true
}

const saveMarkerContent = () => {
  if (currentEditingMarkerNum.value !== null) {
    generationStore.updateMarkerPrompt(currentEditingMarkerNum.value, editMarkerContent.value)
    editMarkerVisible.value = false
  }
}

const normalizeText = (value) => String(value || '').trim()
const awaitWithTimeout = (promise, ms) => Promise.race([
  Promise.resolve(promise).catch(() => null),
  new Promise(resolve => setTimeout(resolve, ms))
])

const canSend = computed(() => {
  const text = normalizeText(inputValue.value || '')
  return text.length > 0 && !chatStore.isLoading.value
})


const writingStepLabelToStage = {
  '写前评估': 'STAGE_NEED_ASSESS',
  '检索计划': 'STAGE_QUERY_PLAN',
  '联网搜索': 'STAGE_WEB_SEARCH',
  '抓取资料': 'STAGE_FETCH_SOURCES',
  '标题筛选': 'STAGE_TITLE_FILTER',
  '标题筛除': 'STAGE_TITLE_FILTER',
  '清洗资料': 'STAGE_CLEAN_SOURCES',
  '事实包': 'STAGE_FACT_PACK',
  '大纲': 'STAGE_OUTLINE',
  '中性稿': 'STAGE_NEUTRAL_DRAFT',
  '风格编译': 'STAGE_COMPILE_STYLE',
  '风格迁移': 'STAGE_STYLE_TRANSFER',
  '风格自检': 'STAGE_STYLE_QA',
  '相似度风控': 'STAGE_RISK_CHECK',
  '段落重写': 'STAGE_REWRITE_SEGMENTS',
  '最终整理': 'STAGE_FINALIZE'
}

const resolveWritingTaskIdFromPayload = (obj) => {
  const direct = obj?.task_id || obj?.taskId
  const nested = obj?.data?.task_id || obj?.data?.taskId || obj?.meta?.task_id || obj?.meta?.taskId
  return String(direct || nested || '').trim()
}

const resolveWritingStageFromStep = (obj, line) => {
  const raw = obj?.stage || obj?.stage_key || obj?.stageKey || obj?.status
  const stage = String(raw || '').trim()
  if (stage && stage.startsWith('STAGE_')) return stage
  const m = /进入阶段：(.+)$/.exec(String(line || '').trim())
  const label = m ? String(m[1] || '').trim() : ''
  return label && writingStepLabelToStage[label] ? writingStepLabelToStage[label] : ''
}

const resolveWorkIdByTaskId = (taskId) => {
  const tid = String(taskId || '').trim()
  if (!tid) return ''
  const match = works.value.find(w => String(w?.task_id || '').trim() === tid)
  if (match) return String(match.id || '').trim()
  return String(activeWorkId.value || '').trim()
}

const writingStageListLabel = {
  query_plan: '检索计划',
  sources: '搜索结果',
  fetched_sources: '抓取完成',
  title_filtered_out_sources: '标题筛除',
  cleaned_sources: '清洗完成',
  fact_pack: '事实包'
}

const resolveSourceItemsFromStepPayload = (obj) => {
  if (!obj || typeof obj !== 'object') return []
  const preview = obj.preview || obj.data?.preview || null
  const itemsFromPreview = Array.isArray(preview?.items) ? preview.items : null
  if (itemsFromPreview && itemsFromPreview.length) return itemsFromPreview
  const items = obj.items || obj.sources || obj.results || obj.data?.items || obj.data?.sources || obj.data?.results
  if (Array.isArray(items)) return items
  if (items && typeof items === 'object') return Object.values(items)
  return []
}

const resolvePreviewFromStepPayload = (type, obj) => {
  if (!obj || typeof obj !== 'object') return { totalCount: 0, items: [], moreCount: 0 }
  const preview = obj.preview || obj.data?.preview || null
  if (preview && typeof preview === 'object') {
    const rawItems = Array.isArray(preview.items) ? preview.items : resolveSourceItemsFromStepPayload(obj)
    const normalized = toPreviewSourceItems(type, rawItems)
    const total = Number(preview.totalCount || preview.total || normalized.totalCount || 0)
    return { ...normalized, totalCount: total }
  }
  const items = resolveSourceItemsFromStepPayload(obj)
  return toPreviewSourceItems(type, items)
}

const pushSearchListFromStep = (workId, type, preview) => {
  const label = writingStageListLabel[type] || '搜索结果'
  const msg = formatSearchResultListMessage(preview, label, type)
  if (!msg) return
  const safeWorkId = String(workId || '').trim() || '__global__'
  const seenKey = `__chat_sources__step__${type}__${preview.totalCount}:${preview.items.map(x => x.url || x.title || '').join('|')}`
  const prevSeen = workChatStepSeenByWorkId.value?.[safeWorkId] || {}
  if (prevSeen[seenKey]) return
  workChatStepSeenByWorkId.value = {
    ...(workChatStepSeenByWorkId.value || {}),
    [safeWorkId]: { ...prevSeen, [seenKey]: true }
  }
  const lines = String(msg || '').split(/\r?\n/).map(x => String(x || '').trim()).filter(Boolean)
  for (const line of lines) appendWorkChatStep(workId, line)
}

const pushDetailListFromText = (workId, type, msg, signature = '') => {
  if (!workId) return
  const text = String(msg || '').trim()
  if (!text) return
  const safeWorkId = String(workId || '').trim() || '__global__'
  const sig = String(signature || '').trim() || String(text).slice(0, 240)
  const seenKey = `__chat_detail__${String(type || '').trim()}__${sig}`
  const prevSeen = workChatStepSeenByWorkId.value?.[safeWorkId] || {}
  if (prevSeen[seenKey]) return
  workChatStepSeenByWorkId.value = {
    ...(workChatStepSeenByWorkId.value || {}),
    [safeWorkId]: { ...prevSeen, [seenKey]: true }
  }
  const lines = text.split(/\r?\n/).map(x => String(x || '').trim()).filter(Boolean)
  for (const line of lines) appendWorkChatStep(workId, line)
}

const handleSend = async () => {
  if (!richInputRef.value) return
  const waitingWork = activeWork.value
  const isWaitingStyleTransfer = !!waitingWork && String(waitingWork?.task_status?.status || '').trim() === 'WAIT_STYLE_TRANSFER'
  if (isWaitingStyleTransfer) {
    if (applyStyleSubmitting.value) {
      ElMessage.warning('正在处理，请稍后再试')
      return
    }
    const raw = String(richInputRef.value.innerText || richInputRef.value.textContent || '')
    const userInput = normalizeText(raw)
    if (!userInput.trim()) return

    chatStore.addUserMessage(userInput)
    richInputRef.value.innerHTML = ''
    inputValue.value = ''
    saveDraftFor(chatStore.currentConversationId.value)
    generationStore.clearMarkerPrompts()
    scrollToBottom()

    const workId = String(waitingWork?.id || '').trim()
    const normalized = String(userInput || '').trim()
    const shouldSkip = /直接使用中性稿|直接用中性稿|中性稿|跳过|不生成|不要风格|不需要风格|不用风格/i.test(normalized)
    if (shouldSkip) {
      pendingStyleTransferChoicesByWorkId.value = { ...(pendingStyleTransferChoicesByWorkId.value || {}), [workId]: null }
      savePendingStyleChoiceToStorage(workId, null)
      await confirmSkipStyleTransfer()
      return
    }

    const pending = pendingStyleTransferChoicesByWorkId.value?.[workId] || null
    const mNum = normalized.match(/^\s*(\d{1,3})\s*$/)
    if (mNum && pending && Array.isArray(pending.items)) {
      const idx = Number(mNum[1]) - 1
      const picked = pending.items[idx]
      if (picked) {
        applyStyleForm.value.style_id = String(picked.style_id || '').trim()
        applyStyleForm.value.style_profile_id = String(picked.style_profile_id || '').trim()
        pendingStyleTransferChoicesByWorkId.value = { ...(pendingStyleTransferChoicesByWorkId.value || {}), [workId]: null }
        savePendingStyleChoiceToStorage(workId, null)
        await confirmApplyStyle()
        return
      }
    }

    const mProfile = normalized.match(/#\s*(\d{1,10})\s*$/) || normalized.match(/^\s*(\d{1,10})\s*$/)
    if (mProfile) {
      const profileId = Number(mProfile[1])
      if (Number.isFinite(profileId) && profileId > 0) {
        try {
          const res = await request.get('/api/v1/style_profile/detail', { params: { id: profileId } })
          if (res.data?.code === 200) {
            const styleId = String(res.data.data?.style_id || '').trim()
            if (styleId) {
              applyStyleForm.value.style_id = styleId
              applyStyleForm.value.style_profile_id = String(profileId)
              pendingStyleTransferChoicesByWorkId.value = { ...(pendingStyleTransferChoicesByWorkId.value || {}), [workId]: null }
              savePendingStyleChoiceToStorage(workId, null)
              await confirmApplyStyle()
              return
            }
          }
        } catch {
          void 0
        }
      }
    }

    chatStore.addAssistantMessage('请回复一个可选 Style Profile 的序号或 #ID，或回复“直接使用中性稿”。')
    return
  }
  const uploadTimeoutMs = 8000
  await Promise.all([
    awaitWithTimeout(generationStore.uploadMarkerReferenceImages(), uploadTimeoutMs),
    awaitWithTimeout(generationStore.uploadMarkerThumbnails(), uploadTimeoutMs)
  ])
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
  const refImages = []
  const refMap = new Map()
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
      } catch {
        resolution = ''
      }
      refImages.push({ url, index: idx, resolution })
    }
  }
  let prefix = ''
  if (refImages.length > 0) {
    prefix = refImages.map(r => `参考图${r.index}${r.resolution}：${r.url}`).join('\n') + '\n\n'
  }
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
  const userInput = normalizeText(finalPrompt)
  chatStore.addUserMessage(userInput)
  const thinkingId = chatStore.addAssistantMessage('', {
    thinking: true,
    thinkingDone: false,
    thinkingAction: '',
    thinkingSteps: [],
    isThinkingTrace: true
  })
  const apiMessages = (chatStore.messages.value || [])
    .filter(m => {
      if (m.isThinkingTrace) return false
      if (m.content) return true
      if (m.role === 'assistant' && m.tool_calls && m.tool_calls.length > 0) return true
      if (m.role === 'tool') return true
      return false
    })
    .map(m => {
      const item = { role: m.role, content: m.content || '' }
      if (m.tool_calls) item.tool_calls = m.tool_calls
      if (m.tool_call_id) item.tool_call_id = m.tool_call_id
      return item
    })

  chatStore.setLoading(true)
  chatStore.setStreaming(true)
  let requestActive = true
  const finishRequest = () => {
    if (!requestActive) return
    requestActive = false
    chatStore.setLoading(false)
    chatStore.setStreaming(false)
  }

  const chatInteractionId = 'chat_' + Date.now()
  let streamTargetId = null
  const handleChatUpdate = (payload) => {
    const incomingChatId = payload.chat_id || payload.data?.chat_id
    if (incomingChatId !== chatInteractionId) return
    const obj = payload.data
    if (!obj) return

    const thinkingMsg = chatStore.messages.value.find(m => String(m.id) === String(thinkingId))
    const targetMsg = streamTargetId ? chatStore.messages.value.find(m => String(m.id) === String(streamTargetId)) : null

    if (obj.type === 'sm_status') {
      const text = (typeof obj.content === 'string' ? obj.content : (obj.content?.toString() || '')).trim()
      if (!text) return
      if (thinkingMsg) {
        thinkingMsg.thinking = true
        thinkingMsg.thinkingAction = text
        if (!Array.isArray(thinkingMsg.thinkingSteps)) thinkingMsg.thinkingSteps = []
        chatStore.streamTicker.value++
      }
      return
    }
    if (obj.type === 'sm_step') {
      const title = (typeof obj.title === 'string' ? obj.title : (obj.title?.toString() || '')).trim()
      const summary = (typeof obj.summary === 'string' ? obj.summary : (obj.summary?.toString() || '')).trim()
      const line = title && summary ? `${title}：${summary}` : (title || summary)
      if (!line) return
      if (thinkingMsg) {
        if (!Array.isArray(thinkingMsg.thinkingSteps)) thinkingMsg.thinkingSteps = []
        thinkingMsg.thinkingSteps.push(line)
        chatStore.streamTicker.value++
      }
      const taskId = resolveWritingTaskIdFromPayload(obj)
      const stage = resolveWritingStageFromStep(obj, line)
      const workId = resolveWorkIdByTaskId(taskId)
      if (stage === 'STAGE_QUERY_PLAN') {
        const qp = obj.preview || obj.data?.preview || obj.data || null
        const msg = qp && typeof qp === 'object' ? formatQueryPlanListMessage(qp) : ''
        if (msg) pushDetailListFromText(workId, 'query_plan', msg, `sm_step:query_plan:${taskId}:${String(title || '').trim()}`)
      } else if (stage === 'STAGE_FACT_PACK') {
        const fp = obj.preview || obj.data?.preview || obj.data || null
        const msg = fp && typeof fp === 'object' ? formatFactPackListMessage(fp) : ''
        if (msg) pushDetailListFromText(workId, 'fact_pack', msg, `sm_step:fact_pack:${taskId}:${String(title || '').trim()}`)
      } else if (stage === 'STAGE_WEB_SEARCH' || stage === 'STAGE_FETCH_SOURCES' || stage === 'STAGE_TITLE_FILTER' || stage === 'STAGE_CLEAN_SOURCES') {
        const type = stageToDetailArtifactType(stage)
        if (type) {
          const preview = resolvePreviewFromStepPayload(type, obj)
          if (preview && (preview.totalCount > 0 || preview.items.length > 0)) {
            pushSearchListFromStep(workId, type, preview)
          }
        }
      }
      return
    }

    if (obj.type === 'article_task') {
      const data = obj.data || {}
      const taskId = String(data.task_id || '').trim()
      const workResourceId = String(data.work_resource_id || '').trim()
      const topic = String(data.topic || '').trim()
      const genre = String(data.genre || '').trim()
      const wordCount = Number(data.word_count || 0) || 0
      const styleId = String(data.style_id || '').trim()
      const styleProfileId = String(data.style_profile_id || '').trim()

      if (taskId && workResourceId) {
        const workId = workResourceId
        const existing = works.value.find(w => String(w.id) === String(workId))
        if (!existing) {
          const newWork = {
            id: workId,
            type: 'document',
            categoryId: 'document',
            name: (topic || '未命名作品').slice(0, 30),
            topic,
            genre,
            word_count: wordCount > 0 ? wordCount : 0,
            updatedAtText: '刚刚',
            task_id: taskId,
            style_id: styleId,
            style_profile_id: styleProfileId,
            task_status: { status: 'QUEUED', stage: 'QUEUED', progress: 0 },
            generating: true,
            content: '',
            contentLoaded: true
          }
          works.value = [newWork, ...works.value]
        }
        activeTab.value = 'works'
        activeWorkId.value = workId
        ensureWorkChatStatusMessageId(workId, '队列中 0%')
        appendWorkChatStep(workId, '任务已创建，队列中')
      }
      return
    }

    if (obj.type === 'content_delta') {
      const txt = (typeof obj.content === 'string' ? obj.content : '')
      if (!txt) return
      if (!streamTargetId) {
        const newId = Date.now() + Math.floor(Math.random() * 1000)
        chatStore.addAssistantMessage('', { id: newId, content: '', timestamp: Date.now() })
        streamTargetId = newId
      }
      const msg = chatStore.messages.value.find(m => String(m.id) === String(streamTargetId))
      if (msg) {
        msg.thinking = false
        chatStore.enqueueStream(msg.id, txt)
      }
      return
    }

    if (obj.type === 'assistant' || obj.type === 'done') {
      if (obj.type === 'done') {
        wsManager.off('chat_update', handleChatUpdate)
        if (thinkingMsg) {
          thinkingMsg.thinkingAction = thinkingMsg.thinkingAction || '思考结束'
          thinkingMsg.thinkingDone = true
          thinkingMsg.thinking = false
        }
        finishRequest()
      }
      const finalText = (typeof obj.content === 'string' ? obj.content : (obj.content?.toString() || ''))
      if (finalText) {
        if (!streamTargetId) {
          const newId = Date.now() + Math.floor(Math.random() * 1000)
          chatStore.addAssistantMessage(finalText, { id: newId, timestamp: Date.now() })
          streamTargetId = newId
        } else {
          const msg = targetMsg || chatStore.messages.value.find(m => String(m.id) === String(streamTargetId))
          if (msg && (!msg.content || String(msg.content).trim() === '')) {
            msg.content = finalText
            chatStore.streamTicker.value++
          }
        }
      }
      return
    }

    if (obj.type === 'error') {
      wsManager.off('chat_update', handleChatUpdate)
      const em = obj.msg || obj.message || '请求失败'
      if (thinkingMsg) {
        thinkingMsg.thinking = false
        thinkingMsg.thinkingDone = true
        thinkingMsg.isError = true
        thinkingMsg.thinkingAction = em
        chatStore.streamTicker.value++
      } else {
        chatStore.addAssistantMessage(em, { isError: true })
      }
      finishRequest()
    }
  }

  wsManager.on('chat_update', handleChatUpdate)
  const currentWorkId = String(activeWorkId.value || '').trim()
  const extractTextFromHtml = (html) => {
    const s = String(html ?? '')
    if (!s.trim()) return ''
    const div = document.createElement('div')
    div.innerHTML = s
    return String(div.textContent || div.innerText || '').replace(/\u00A0/g, ' ')
  }
  let editorHtml = ''
  if (currentWorkId) {
    const w = works.value.find(x => String(x.id) === String(currentWorkId))
    editorHtml = String(w?.content || '')
    if (activeTab.value === 'works' && String(activeWorkId.value || '').trim() === currentWorkId && workEditorRef.value) {
      editorHtml = String(workEditorRef.value.innerHTML || editorHtml)
    }
  }
  const editorIsEmpty = extractTextFromHtml(editorHtml).trim().length === 0
  wsManager.send({
    type: 'chat',
    chat_id: chatInteractionId,
    messages: apiMessages,
    options: {
      conversation_id: chatStore.currentConversationId.value,
      stream: true,
      agent_mode: 'article_sm',
      is_polish_enabled: isPolishEnabled.value,
      use_system_key: 'article_sm_s0_intent_system_prompt',
      ...(currentWorkId ? { work_resource_id: currentWorkId, editor_is_empty: editorIsEmpty } : {})
    }
  })

  setTimeout(() => {
    wsManager.off('chat_update', handleChatUpdate)
    finishRequest()
  }, 600000)
  richInputRef.value.innerHTML = ''
  inputValue.value = ''
  saveDraftFor(chatStore.currentConversationId.value)
  generationStore.clearMarkerPrompts()
  scrollToBottom()
}

const resolveStyleNameById = (styleId) => {
  const sid = String(styleId || '').trim()
  if (!sid) return ''
  const list = Array.isArray(styleOptions.value) ? styleOptions.value : []
  const matched = list.find(s => String(s?.style_id || '').trim() === sid)
  return String(matched?.name || '').trim()
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
  dragEnterCount.value = 0
  isInputDragOver.value = false
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
  if (!chatStore.isStreaming.value) return false
  if (msg.role !== 'assistant') return false
  const list = chatStore.messages.value || []
  const lastMsg = list[list.length - 1]
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
  if (msg.styleTransferCard) return true
  if (shouldShowBubble(msg)) return true
  if (msg.pendingTool) return true
  if (msg.toolResult) return true
  return false
}

const getMessageSegments = (content) => {
  let text = typeof content === 'string' ? content : ''
  text = text.replace(/<think>[\s\S]*?<\/think>/gi, '')
  text = text.replace(/<think>[\s\S]*$/i, '')
  text = text.replace(/\n?（参考图\d+分辨率\d+x\d+.*?）/g, '')
  const rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：'
  if (text.startsWith(rawPrefix)) {
    text = text.substring(rawPrefix.length)
  }
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

watch(() => chatStore.messages.value, scrollToBottom, { deep: true })
watch(chatStore.streamTicker, scrollToBottom)
watch(() => chatStore.currentConversationId.value, scrollToBottom)
watch(isProcessing, (newVal, oldVal) => {
  scrollToBottom()
  if (!newVal && oldVal) {
    setTimeout(scrollToBottom, 200)
    setTimeout(scrollToBottom, 500)
  }
})

const workEditorRef = ref(null)
const workEditorFocused = ref(false)
const workEditorSelectionRange = ref(null)
const workImageInputRef = ref(null)
const workImageSidebarRef = ref(null)

const editorContentWrapperRef = ref(null)

const workStatusBottomRef = ref(null)

const workEventsByWorkId = ref({})
const workLastTaskSnapshotByWorkId = ref({})
const workChatStatusMessageIdByWorkId = ref({})
const workLastChatSnapshotByWorkId = ref({})
const workChatStepSeenByWorkId = ref({})
const pendingStyleTransferChoicesByWorkId = ref({})

const ensuredNeutralDraftTaskIds = new Set()
const ensuredResearchDetailTaskKeys = new Set()
const ensuredWaitStyleTransferTaskKeys = new Set()

const workImageDeleteConfirmKey = ref('')

const worksLoading = ref(false)
const works = ref([])
const activeWorkId = ref('')
const activeWork = computed(() => {
  const id = activeWorkId.value
  if (!id) return null
  return works.value.find(w => w.id === id) || null
})
const getRouteWorkId = () => {
  const p = route?.params?.work_id
  const q = route?.query?.work_id
  const id = (p !== undefined && p !== null && String(p).trim() !== '')
    ? String(p).trim()
    : (q !== undefined && q !== null ? String(q).trim() : '')
  return id
}

const syncWorkUrl = (workId) => {
  const id = String(workId || '').trim()
  if (!id) {
    if (String(route?.params?.work_id || '').trim()) {
      router.replace({ name: 'graphic-creation', params: { work_id: undefined }, query: { ...(route?.query || {}) } })
    }
    return
  }
  if (String(route?.params?.work_id || '').trim() === id) return
  router.replace({ name: 'graphic-creation', params: { work_id: id }, query: { ...(route?.query || {}) } })
}

const selectWork = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  activeTab.value = 'works'
  activeWorkId.value = id
}

const workChatStorageKey = (workId) => `app_graphic_work_chat_v1:${String(workId || '').trim()}`
let persistWorkChatTimer = null
const workDraftStorageKey = (workId) => `app_graphic_work_draft_v1:${String(workId || '').trim()}`
let persistWorkDraftTimer = null
const workPendingStyleChoiceStorageKey = (workId) => `app_graphic_pending_style_choice_v1:${String(workId || '').trim()}`

const savePendingStyleChoiceToStorage = (workId, choice) => {
  const id = String(workId || '').trim()
  if (!id) return
  if (!choice) {
    try {
      localStorage.removeItem(workPendingStyleChoiceStorageKey(id))
    } catch {
      void 0
    }
    return
  }
  try {
    localStorage.setItem(workPendingStyleChoiceStorageKey(id), JSON.stringify(choice))
  } catch {
    void 0
  }
}

const loadPendingStyleChoiceFromStorage = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return null
  try {
    const raw = localStorage.getItem(workPendingStyleChoiceStorageKey(id))
    if (!raw) return null
    const parsed = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object') return null
    const taskId = String(parsed.task_id || parsed.taskId || '').trim()
    const items = Array.isArray(parsed.items) ? parsed.items : []
    const normalizedItems = items
      .map((x) => ({
        style_id: String(x?.style_id || '').trim(),
        style_profile_id: String(x?.style_profile_id || '').trim(),
        label: String(x?.label || '').trim()
      }))
      .filter(x => x.style_id && x.style_profile_id)
      .slice(0, 30)
    if (!taskId || normalizedItems.length === 0) return null
    return { task_id: taskId, items: normalizedItems }
  } catch {
    return null
  }
}

const restoreStyleTransferCardFromPendingChoice = (workId, taskId) => {
  const wid = String(workId || '').trim()
  const tid = String(taskId || '').trim()
  if (!wid || !tid) return false
  const pending = loadPendingStyleChoiceFromStorage(wid)
  if (!pending) return false
  const items = Array.isArray(pending.items) ? pending.items : []
  if (items.length === 0) return false

  pendingStyleTransferChoicesByWorkId.value = {
    ...(pendingStyleTransferChoicesByWorkId.value || {}),
    [wid]: { task_id: pending.task_id, items }
  }

  const existingMsg = findLatestUndecidedStyleTransferCardByWorkId(wid)
  const title = getWorkDisplayName(wid)
  const cardPayload = {
    type: 'style_transfer',
    workId: wid,
    taskId: pending.task_id,
    workTitle: title ? `【中性稿已完成】${title}` : '【中性稿已完成】',
    choices: items.map((c) => ({
      key: `${String(c.style_id || '').trim()}::${String(c.style_profile_id || '').trim()}`,
      style_id: String(c.style_id || '').trim(),
      style_profile_id: String(c.style_profile_id || '').trim(),
      label: String(c.label || '').trim()
    })),
    selectedKey: '',
    decided: false,
    decidedText: '',
    loadingAction: ''
  }
  if (existingMsg) {
    const existingCard = existingMsg?.styleTransferCard || {}
    if (!existingCard.decided) {
      chatStore.updateMessage(existingMsg.id, { styleTransferCard: { ...cardPayload, selectedKey: String(existingCard.selectedKey || '').trim(), decided: false, decidedText: String(existingCard.decidedText || '').trim(), loadingAction: String(existingCard.loadingAction || '').trim() } })
    }
  } else {
    chatStore.addAssistantMessage('', { styleTransferCard: cardPayload })
  }
  return true
}

const compactChatMessage = (m) => {
  if (!m || typeof m !== 'object') return null
  const out = {
    id: m.id,
    role: m.role,
    content: typeof m.content === 'string' ? m.content : (m.content?.toString?.() || ''),
    timestamp: m.timestamp
  }
  if (m.thinking !== undefined) out.thinking = !!m.thinking
  if (m.thinkingDone !== undefined) out.thinkingDone = !!m.thinkingDone
  if (m.thinkingAction !== undefined) out.thinkingAction = typeof m.thinkingAction === 'string' ? m.thinkingAction : (m.thinkingAction?.toString?.() || '')
  if (m.thinkingLoadingIndex !== undefined) out.thinkingLoadingIndex = m.thinkingLoadingIndex
  if (Array.isArray(m.thinkingSteps)) out.thinkingSteps = m.thinkingSteps.slice(0, 500).map(x => typeof x === 'string' ? x : (x?.toString?.() || '')).filter(Boolean)
  if (m.isThinkingTrace !== undefined) out.isThinkingTrace = !!m.isThinkingTrace
  if (m.isError !== undefined) out.isError = !!m.isError
  if (m.pendingTool !== undefined) out.pendingTool = m.pendingTool
  if (m.toolResult !== undefined) out.toolResult = m.toolResult
  if (m.styleTransferCard && typeof m.styleTransferCard === 'object') {
    const c = m.styleTransferCard
    const rawChoices = Array.isArray(c.choices) ? c.choices : []
    const choices = rawChoices
      .slice(0, 60)
      .map((x) => ({
        key: String(x?.key || '').trim(),
        style_id: String(x?.style_id || '').trim(),
        style_profile_id: String(x?.style_profile_id || '').trim(),
        label: String(x?.label || '').trim()
      }))
      .filter(x => x.style_id && x.style_profile_id)
      .slice(0, 30)
    out.styleTransferCard = {
      type: String(c.type || '').trim(),
      workId: String(c.workId || '').trim(),
      taskId: String(c.taskId || '').trim(),
      workTitle: String(c.workTitle || '').trim(),
      choices,
      selectedKey: String(c.selectedKey || '').trim(),
      decided: !!c.decided,
      decidedText: String(c.decidedText || '').trim(),
      loadingAction: String(c.loadingAction || '').trim()
    }
  }
  if (out.content && out.content.length > 20000) out.content = out.content.slice(0, 20000)
  return out
}

const persistWorkChatNow = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  const msgs = Array.isArray(chatStore.messages.value) ? chatStore.messages.value : []
  const compacted = msgs.slice(-200).map(compactChatMessage).filter(Boolean)
  let statusMsgId = workChatStatusMessageIdByWorkId.value?.[id] || null
  if (!statusMsgId) {
    const lastTrace = [...compacted].reverse().find(x => x.role === 'assistant' && x.isThinkingTrace)
    if (lastTrace) statusMsgId = lastTrace.id
  }
  const payload = {
    work_id: id,
    conversation_id: chatStore.currentConversationId.value || `graphic_work_${id}`,
    messages: compacted,
    status_message_id: statusMsgId,
    step_seen: workChatStepSeenByWorkId.value?.[id] || {},
    last_task_snapshot: workLastTaskSnapshotByWorkId.value?.[id] || null,
    last_chat_snapshot: workLastChatSnapshotByWorkId.value?.[id] || null
  }
  try {
    localStorage.setItem(workChatStorageKey(id), JSON.stringify(payload))
  } catch {
    void 0
  }
}

const schedulePersistWorkChat = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  if (persistWorkChatTimer) clearTimeout(persistWorkChatTimer)
  persistWorkChatTimer = setTimeout(() => {
    persistWorkChatNow(id)
  }, 400)
}

const loadWorkDraftFromStorage = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return null
  try {
    const raw = localStorage.getItem(workDraftStorageKey(id))
    if (!raw) return null
    const parsed = JSON.parse(raw)
    if (!parsed || typeof parsed !== 'object') return null
    return {
      title: typeof parsed.title === 'string' ? parsed.title : '',
      content: typeof parsed.content === 'string' ? parsed.content : '',
      updated_ts: Number(parsed.updated_ts || 0) || 0
    }
  } catch {
    return null
  }
}

const persistWorkDraftNow = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  const w = works.value.find(x => String(x.id) === String(id))
  if (!w) return
  const payload = {
    work_id: id,
    title: String(w.name ?? ''),
    content: String(w.content ?? ''),
    updated_ts: Date.now()
  }
  try {
    localStorage.setItem(workDraftStorageKey(id), JSON.stringify(payload))
  } catch {
    void 0
  }
}

const schedulePersistWorkDraft = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  if (persistWorkDraftTimer) clearTimeout(persistWorkDraftTimer)
  persistWorkDraftTimer = setTimeout(() => {
    persistWorkDraftNow(id)
  }, 400)
}

const loadWorkChatFromStorage = (workId) => {
  const id = String(workId || '').trim()
  if (!id) {
    chatStore.resetConversation()
    return
  }
  let parsed = null
  try {
    const raw = localStorage.getItem(workChatStorageKey(id))
    if (raw) parsed = JSON.parse(raw)
  } catch {
    parsed = null
  }

  if (parsed && Array.isArray(parsed.messages) && parsed.messages.length) {
    const w = works.value.find(x => String(x.id) === String(id))
    const realStatus = w?.task_status?.status
    const persistedStatus = parsed.last_task_snapshot?.status
    const isFinished = (realStatus === 'SUCCEEDED' || realStatus === 'FAILED') || (persistedStatus === 'SUCCEEDED' || persistedStatus === 'FAILED')

    if (isFinished) {
      parsed.messages.forEach(m => {
        if (m.pendingTool) delete m.pendingTool
      })
    }

    chatStore.replaceConversation(parsed.conversation_id || `graphic_work_${id}`, parsed.messages)
    const msgId = parsed.status_message_id
    if (msgId) {
      workChatStatusMessageIdByWorkId.value = { ...(workChatStatusMessageIdByWorkId.value || {}), [id]: msgId }
    }
    if (parsed.step_seen && typeof parsed.step_seen === 'object') {
      workChatStepSeenByWorkId.value = { ...(workChatStepSeenByWorkId.value || {}), [id]: parsed.step_seen }
    } else {
      const trace = [...parsed.messages].reverse().find(x => x.role === 'assistant' && x.isThinkingTrace)
      const seen = {}
      if (trace && Array.isArray(trace.thinkingSteps)) {
        for (const s of trace.thinkingSteps) {
          const k = String(s || '').trim()
          if (k) seen[k] = true
        }
      }
      workChatStepSeenByWorkId.value = { ...(workChatStepSeenByWorkId.value || {}), [id]: seen }
    }
    if (parsed.last_task_snapshot) {
      workLastTaskSnapshotByWorkId.value = { ...(workLastTaskSnapshotByWorkId.value || {}), [id]: parsed.last_task_snapshot }
    }
    if (parsed.last_chat_snapshot) {
      workLastChatSnapshotByWorkId.value = { ...(workLastChatSnapshotByWorkId.value || {}), [id]: parsed.last_chat_snapshot }
    }

    const latestCard = [...parsed.messages].reverse().find(m => {
      const c = m?.styleTransferCard
      if (!c || typeof c !== 'object') return false
      if (String(c.type || '').trim() !== 'style_transfer') return false
      if (String(c.workId || '').trim() !== id) return false
      return true
    })
    if (latestCard && latestCard.styleTransferCard && !latestCard.styleTransferCard.decided) {
      const c = latestCard.styleTransferCard
      const items = Array.isArray(c.choices)
        ? c.choices.map((x) => ({
          style_id: String(x?.style_id || '').trim(),
          style_profile_id: String(x?.style_profile_id || '').trim(),
          label: String(x?.label || '').trim()
        })).filter(x => x.style_id && x.style_profile_id)
        : []
      pendingStyleTransferChoicesByWorkId.value = {
        ...(pendingStyleTransferChoicesByWorkId.value || {}),
        [id]: { task_id: String(c.taskId || '').trim(), items }
      }
    }

    const isWaitingStyle = realStatus === 'WAIT_STYLE_TRANSFER' || persistedStatus === 'WAIT_STYLE_TRANSFER'
    if (isWaitingStyle) {
      const msgId = parsed.status_message_id
      const list = chatStore.messages.value || []
      const target = msgId ? list.find(x => String(x?.id) === String(msgId)) : null
      const fallback = target ? null : [...list].reverse().find(x => x?.role === 'assistant' && x?.isThinkingTrace)
      const picked = target || fallback
      if (picked) {
        chatStore.updateMessage(picked.id, { thinking: false, thinkingDone: true, thinkingAction: '等待风格选择' })
      }
    }
    return
  }

  chatStore.replaceConversation(`graphic_work_${id}`, null)
  workChatStatusMessageIdByWorkId.value = { ...(workChatStatusMessageIdByWorkId.value || {}), [id]: null }
  workChatStepSeenByWorkId.value = { ...(workChatStepSeenByWorkId.value || {}), [id]: {} }
}

const hydrateWorkTaskSnapshotsFromCurrent = (workId) => {
  const id = String(workId || '').trim()
  if (!id) return
  const w = works.value.find(x => String(x.id) === String(id))
  if (!w || !w.task_status) return
  const stage = String(w.task_status.stage || w.task_status.status || '')
  const status = String(w.task_status.status || '')
  const raw = w.task_status.progress
  const parsed = Number(raw)
  const progress = Number.isFinite(parsed) ? Math.max(0, Math.min(100, Math.round(parsed))) : 0
  workLastTaskSnapshotByWorkId.value = { ...(workLastTaskSnapshotByWorkId.value || {}), [id]: { stage, status, progress } }
  workLastChatSnapshotByWorkId.value = { ...(workLastChatSnapshotByWorkId.value || {}), [id]: { stage, status, progress } }
}

watch(
  () => route?.params?.work_id,
  (val) => {
    const id = val !== undefined && val !== null ? String(val).trim() : ''
    if (!id) return
    if (String(activeWorkId.value || '').trim() === id) return
    activeTab.value = 'works'
    activeWorkId.value = id
  },
  { immediate: true }
)

watch(activeWorkId, (id, prev) => {
  const wid = String(id || '').trim()
  const pid = String(prev || '').trim()
  if (wid === pid) return
  if (pid) {
    persistWorkChatNow(pid)
    persistWorkDraftNow(pid)
  }
  if (wid) {
    localStorage.setItem('app_graphic_last_work_id', wid)
    loadWorkChatFromStorage(wid)
    hydrateWorkTaskSnapshotsFromCurrent(wid)
    syncWorkUrl(wid)
  } else {
    chatStore.resetConversation()
  }
}, { immediate: true })

watch(chatStore.streamTicker, () => {
  const wid = String(activeWorkId.value || '').trim()
  if (!wid) return
  schedulePersistWorkChat(wid)
})

const filteredWorks = computed(() => {
  return works.value
})

const getWorkIcon = (type) => {
  if (type === 'page') return Files
  return Document
}

const styleOptions = ref([])
const stylesLoading = ref(false)

const fetchStyleOptions = async () => {
  stylesLoading.value = true
  try {
    const res = await request.get('/api/v1/styles/list')
    if (res.data.code !== 200) return
    const items = Array.isArray(res.data.data?.items) ? res.data.data.items : []
    const merged = items
      .map((x) => ({
        style_id: String(x?.style_id || '').trim(),
        name: String(x?.name || '').trim()
      }))
      .filter(x => x.style_id)
    styleOptions.value = merged

    const cur = String(generationStore.style || '').trim()
    if (cur && styleOptions.value.some(s => s.style_id === cur)) return
    generationStore.style = styleOptions.value[0]?.style_id || 'default'
  } catch {
    return
  } finally {
    stylesLoading.value = false
  }
}

const handleStyleCommand = async (command, item) => {
  const styleId = String(item?.style_id || '').trim()
  if (!styleId) return
  if (command === 'rename') {
    try {
      const { value } = await ElMessageBox.prompt('请输入新的风格名称', '重命名风格', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        inputValue: String(item?.name || ''),
        inputValidator: (v) => (String(v || '').trim() ? true : '请输入风格名称')
      })
      const name = String(value || '').trim()
      if (!name) return
      const res = await request.post('/api/v1/styles/rename', { style_id: styleId, name })
      if (res.data.code !== 200) {
        ElMessage.error(res.data.msg || '重命名失败')
        return
      }
      await fetchStyleOptions()
      ElMessage.success('重命名成功')
    } catch {
      return
    }
    return
  }
  if (command === 'delete') {
    try {
      await ElMessageBox.confirm('确定删除该风格？删除前请确保该风格下没有资料和 Style Profile。', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
      const res = await request.post('/api/v1/styles/delete', { style_id: styleId })
      if (res.data.code !== 200) {
        ElMessage.error(res.data.msg || '删除失败')
        return
      }
      if ((generationStore.style || 'default') === styleId) generationStore.style = 'default'
      await fetchStyleOptions()
      ElMessage.success('删除成功')
    } catch {
      return
    }
  }
}

const getStyleLabel = (styleId) => {
  const key = String(styleId || '').trim()
  if (!key) return '未选择'
  const matched = styleOptions.value.find(s => s.style_id === key)
  if (matched) return matched.name
  if (key === 'default') return '默认'
  return key
}

const updateActiveWorkField = (workId, field, value) => {
  const idx = works.value.findIndex(w => w.id === workId)
  if (idx < 0) return
  const prev = works.value[idx]
  works.value[idx] = {
    ...prev,
    [field]: value,
    updatedAtText: '刚刚'
  }
  schedulePersistWorkDraft(workId)

  if (field === 'name') {
    scheduleSaveWork(workId, { title: String(value ?? '') })
  } else if (field === 'content') {
    scheduleSaveWork(workId, { content: String(value ?? '') })
  }
}

const workPendingSaveById = ref({})
const workSaveTimerById = ref({})

const scheduleSaveWork = (workId, patch) => {
  if (!workId || !patch || typeof patch !== 'object') return
  workPendingSaveById.value = {
    ...workPendingSaveById.value,
    [workId]: {
      ...(workPendingSaveById.value[workId] || {}),
      ...patch
    }
  }

  const prevTimer = workSaveTimerById.value[workId]
  if (prevTimer) clearTimeout(prevTimer)

  workSaveTimerById.value = {
    ...workSaveTimerById.value,
    [workId]: setTimeout(async () => {
      const pending = workPendingSaveById.value[workId]
      if (!pending) return

      workPendingSaveById.value = { ...workPendingSaveById.value, [workId]: null }

      const payload = { resource_id: workId }
      if (pending.title !== undefined) payload.title = pending.title
      if (pending.content !== undefined) payload.content = pending.content

      try {
        const res = await request.post('/api/v1/resources/update', payload)
        if (res.data.code !== 200) return
        const item = res.data.data?.item
        if (!item) return
        const idx = works.value.findIndex(w => w.id === workId)
        if (idx < 0) return
        works.value[idx] = {
          ...works.value[idx],
          name: String(item.title || works.value[idx].name || ''),
          updatedAtText: String(item.updated_at || '刚刚')
        }
      } catch {
        return
      }
    }, 800)
  }
}

const fetchWorksList = async () => {
  worksLoading.value = true
  try {
    const res = await request.get('/api/v1/resources/list')
    if (res.data.code !== 200) return
    const items = res.data.data?.items || []
    const mapped = items
      .filter((it) => String(it?.type || '') === 'work')
      .map((it) => {
        const id = String(it?.resource_id || '').trim()
        const title = String(it?.title || '').trim()
        const updated = String(it?.updated_at || it?.created_at || '').trim()
        const styleId = String(it?.style_id || it?.styleId || '').trim()
        const styleProfileId = String(it?.style_profile_id || it?.styleProfileId || '').trim()
        const taskId = String(it?.task_id || it?.taskId || '').trim()
        const taskStatus = parseJsonSafely(it?.task_status ?? it?.taskStatus ?? it?.task_status_json) || null
        const topic = String(it?.topic || '').trim()
        const genre = String(it?.genre || '').trim()
        const wordCount = Number(it?.word_count || it?.wordCount || 0) || 0
        const generating = isWorkTaskRunning({ task_status: taskStatus })
        const draft = loadWorkDraftFromStorage(id)
        const draftTitle = String(draft?.title || '').trim()
        const draftContent = String(draft?.content || '')
        return {
          id,
          type: 'document',
          categoryId: 'document',
          name: title || draftTitle || '未命名',
          updatedAtText: updated || '',
          topic,
          genre,
          word_count: wordCount || undefined,
          task_id: taskId || undefined,
          style_id: styleId,
          style_profile_id: styleProfileId || undefined,
          task_status: taskStatus || undefined,
          generating,
          content: draftContent,
          contentLoaded: false
        }
      })
      .filter((x) => x.id)

    works.value = mapped
    const fromRoute = getRouteWorkId()
    const hasFromRoute = fromRoute && works.value.some(w => String(w.id) === String(fromRoute))
    if (hasFromRoute) {
      activeWorkId.value = fromRoute
    } else {
      const fromStorage = String(localStorage.getItem('app_graphic_last_work_id') || '').trim()
      const hasFromStorage = fromStorage && works.value.some(w => String(w.id) === String(fromStorage))
      if (hasFromStorage) {
        activeWorkId.value = fromStorage
      } else if (activeWorkId.value && !works.value.some(w => String(w.id) === String(activeWorkId.value))) {
        activeWorkId.value = works.value.length ? works.value[0].id : ''
      } else if (!activeWorkId.value && works.value.length) {
        activeWorkId.value = works.value[0].id
      }
    }
  } catch {
    return
  } finally {
    worksLoading.value = false
  }
}

const resourceDetailInflightById = ref({})
const resourceDetailLastAtById = ref({})
const resourceDetailHitsById = ref({})
const resourceDetailNextAllowedAtById = ref({})
const resourceDetailScheduledTimerById = ref({})
const resourceDetailDeferredById = ref({})

const makeDeferredPromise = () => {
  let resolve = null
  let reject = null
  const promise = new Promise((res, rej) => {
    resolve = res
    reject = rej
  })
  return { promise, resolve, reject }
}

const fetchResourceDetailItem = async (resourceId, options = {}) => {
  const rid = String(resourceId || '').trim()
  if (!rid) return null

  const force = !!options.force
  const now = Date.now()
  const minIntervalMs = 600
  const windowMs = 10_000
  const maxInWindow = 5

  const inflight = resourceDetailInflightById.value?.[rid]
  if (inflight) return inflight

  const lastAt = Number(resourceDetailLastAtById.value?.[rid] || 0) || 0
  const nextAllowedAt = Number(resourceDetailNextAllowedAtById.value?.[rid] || 0) || 0
  const tooSoon = !force && lastAt > 0 && (now - lastAt) < minIntervalMs
  const blockedByWindow = nextAllowedAt > 0 && now < nextAllowedAt

  if (tooSoon || blockedByWindow) {
    const scheduledAt = Math.max(tooSoon ? (lastAt + minIntervalMs) : 0, blockedByWindow ? nextAllowedAt : 0)
    const existingDeferred = resourceDetailDeferredById.value?.[rid]
    const deferred = existingDeferred && existingDeferred.promise ? existingDeferred : makeDeferredPromise()
    if (!existingDeferred || existingDeferred !== deferred) {
      resourceDetailDeferredById.value = { ...(resourceDetailDeferredById.value || {}), [rid]: deferred }
    }

    if (!resourceDetailScheduledTimerById.value?.[rid]) {
      const delay = Math.max(0, scheduledAt - now)
      const timer = setTimeout(async () => {
        resourceDetailScheduledTimerById.value = { ...(resourceDetailScheduledTimerById.value || {}), [rid]: null }
        try {
          const item = await fetchResourceDetailItem(rid, { force: true })
          const d = resourceDetailDeferredById.value?.[rid]
          if (d?.resolve) {
            resourceDetailDeferredById.value = { ...(resourceDetailDeferredById.value || {}), [rid]: null }
            d.resolve(item)
          }
        } catch {
          const d = resourceDetailDeferredById.value?.[rid]
          if (d?.resolve) {
            resourceDetailDeferredById.value = { ...(resourceDetailDeferredById.value || {}), [rid]: null }
            d.resolve(null)
          }
        }
      }, delay)
      resourceDetailScheduledTimerById.value = { ...(resourceDetailScheduledTimerById.value || {}), [rid]: timer }
    }

    return deferred.promise
  }

  const p = (async () => {
    resourceDetailLastAtById.value = { ...(resourceDetailLastAtById.value || {}), [rid]: now }
    const prevHitsRaw = resourceDetailHitsById.value?.[rid]
    const prevHits = Array.isArray(prevHitsRaw) ? prevHitsRaw : []
    const pruned = prevHits.filter(ts => (now - Number(ts || 0)) < windowMs)
    const hits = [...pruned, now]
    resourceDetailHitsById.value = { ...(resourceDetailHitsById.value || {}), [rid]: hits }
    if (hits.length > maxInWindow) {
      const earliest = Number(hits[0] || now) || now
      resourceDetailNextAllowedAtById.value = { ...(resourceDetailNextAllowedAtById.value || {}), [rid]: earliest + windowMs }
    } else if (resourceDetailNextAllowedAtById.value?.[rid]) {
      resourceDetailNextAllowedAtById.value = { ...(resourceDetailNextAllowedAtById.value || {}), [rid]: 0 }
    }

    try {
      const res = await request.get('/api/v1/resources/detail', { params: { resource_id: rid } })
      if (res.data.code !== 200) return null
      return res.data.data?.item || null
    } catch {
      return null
    }
  })()

  resourceDetailInflightById.value = { ...(resourceDetailInflightById.value || {}), [rid]: p }
  try {
    return await p
  } finally {
    resourceDetailInflightById.value = { ...(resourceDetailInflightById.value || {}), [rid]: null }
  }
}

const loadWorkDetail = async (workId, options = {}) => {
  if (!workId) return
  const force = !!options.force
  const idx = works.value.findIndex(w => w.id === workId)
  if (idx < 0) return
  if (!force && works.value[idx]?.contentLoaded) return
  const currentContent = String(works.value[idx]?.content || '')
  const localDraft = loadWorkDraftFromStorage(workId)
  try {
    const item = await fetchResourceDetailItem(workId, { force })
    if (!item) return
    const styleId = String(item?.style_id || item?.styleId || works.value[idx]?.style_id || '').trim()
    const styleProfileId = String(item?.style_profile_id || item?.styleProfileId || works.value[idx]?.style_profile_id || '').trim()
    const taskId = String(item?.task_id || item?.taskId || works.value[idx]?.task_id || '').trim()
    const taskStatus = parseJsonSafely(item?.task_status ?? item?.taskStatus ?? item?.task_status_json) || works.value[idx]?.task_status || null
    const topic = String(item?.topic || works.value[idx]?.topic || '').trim()
    const genre = String(item?.genre || works.value[idx]?.genre || '').trim()
    const wordCount = Number(item?.word_count || item?.wordCount || works.value[idx]?.word_count || 0) || 0
    const serverContent = String(item.content || '')
    let mergedContent = serverContent
    if (!mergedContent.trim()) {
      const cachedNeutralText = String(workArtifactsByWorkId.value?.[workId]?.['neutral_draft']?.text || '')
      if (cachedNeutralText.trim()) {
        mergedContent = cachedNeutralText
      } else if (taskId) {
        const art = await fetchWritingArtifact(taskId, 'neutral_draft')
        if (art) cacheWorkArtifact(workId, 'neutral_draft', art)
        const t = String(art?.text || '')
        if (t.trim()) mergedContent = t
      }
    }
    if (!mergedContent.trim() && currentContent.trim()) {
      mergedContent = currentContent
    }
    if (!mergedContent.trim() && String(localDraft?.content || '').trim()) {
      mergedContent = String(localDraft.content || '')
    }
    const draftTitle = String(localDraft?.title || '').trim()
    const mergedTitle = String(item.title || works.value[idx].name || '').trim() || draftTitle

    works.value[idx] = {
      ...works.value[idx],
      name: mergedTitle,
      updatedAtText: String(item.updated_at || works.value[idx].updatedAtText || ''),
      content: mergedContent,
      contentLoaded: true,
      topic,
      genre,
      word_count: wordCount || works.value[idx]?.word_count,
      task_id: taskId || works.value[idx]?.task_id,
      style_id: styleId,
      style_profile_id: styleProfileId || works.value[idx]?.style_profile_id,
      task_status: taskStatus || works.value[idx]?.task_status,
      generating: isWorkTaskRunning({ task_status: taskStatus || works.value[idx]?.task_status })
    }
  } catch {
    return
  }
}

const formatWorkEventTime = (d = new Date()) => {
  const pad = (n) => String(n).padStart(2, '0')
  return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

const scrollWorkStatusToBottom = () => {
  nextTick(() => {
    const el = workStatusBottomRef.value
    if (el && typeof el.scrollIntoView === 'function') {
      el.scrollIntoView({ behavior: 'smooth', block: 'end' })
    }
  })
}

const pushWorkEvent = (workId, text, data = {}) => {
  if (!workId || !text) return
  const prev = workEventsByWorkId.value[workId] || []
  const item = {
    id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
    time: formatWorkEventTime(),
    text: String(text),
    progress: data.progress ?? null,
    kind: data.kind ?? null,
    items: data.items ?? null,
    moreCount: data.moreCount ?? 0
  }
  workEventsByWorkId.value = {
    ...workEventsByWorkId.value,
    [workId]: [...prev, item].slice(-400)
  }
  if (activeWork.value?.id === workId) scrollWorkStatusToBottom()
}

const parseJsonSafely = (raw) => {
  if (raw === null || raw === undefined) return null
  if (typeof raw === 'object') return raw
  const s = String(raw)
  if (!s.trim()) return null
  try {
    return JSON.parse(s)
  } catch {
    return null
  }
}

const summarizeMarkdown = (text) => {
  const s = String(text || '')
  const lines = s.split(/\r?\n/)
  const headings = []
  for (const line of lines) {
    const m = /^\s{0,3}(#{1,4})\s+(.+?)\s*$/.exec(line)
    if (!m) continue
    headings.push(m[2])
    if (headings.length >= 3) break
  }
  return {
    chars: s.length,
    lines: lines.length,
    headings
  }
}

const workArtifactsByWorkId = ref({})

const cacheWorkArtifact = (workId, type, artifact) => {
  if (!workId || !type || !artifact) return
  const prev = workArtifactsByWorkId.value[workId] || {}
  workArtifactsByWorkId.value = {
    ...workArtifactsByWorkId.value,
    [workId]: { ...prev, [type]: artifact }
  }
}

const getHostFromUrl = (url) => {
  const s = String(url || '').trim()
  if (!s) return ''
  try {
    const u = new URL(s)
    return String(u.host || '').replace(/^www\./i, '')
  } catch {
    return ''
  }
}

const toPreviewSourceItems = (type, payloadItems) => {
  const raw = payloadItems
  const items = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' ? Object.values(raw) : [])
  const limit = 5
  const sliced = items.slice(0, limit)
  const mapped = sliced.map((x, idx) => {
    const url = String(x?.url || '').trim()
    const title = String(x?.title || '').trim()
    const excerptRaw = type === 'sources' ? '' : String(x?.content_excerpt || '').trim()
    const excerpt = excerptRaw ? excerptRaw.slice(0, 180) : ''
    const reason = String(x?.reason || '').trim()
    return {
      key: `${idx}-${url}`,
      url,
      title,
      host: getHostFromUrl(url),
      excerpt,
      reason
    }
  })
  return {
    totalCount: items.length,
    items: mapped,
    moreCount: Math.max(0, items.length - mapped.length)
  }
}

const formatSearchResultListMessage = (preview, label = '搜索结果', type = '') => {
  if (!preview || typeof preview !== 'object') return ''
  const total = Number(preview.totalCount || 0)
  const items = Array.isArray(preview.items) ? preview.items : []
  if (total <= 0 || items.length === 0) return `${label}：暂无`
  const lines = items.flatMap((x, idx) => {
    const title = String(x?.title || '').trim()
    const url = String(x?.url || '').trim()
    const reason = String(x?.reason || '').trim()
    const excerptRaw = String(x?.excerpt || x?.content_excerpt || '').trim()
    const excerpt = excerptRaw ? excerptRaw.slice(0, 180) : ''
    const head = (() => {
      if (url && title) return `${idx + 1}. [${title}](${url})`
      if (url) return `${idx + 1}. ${url}`
      return `${idx + 1}. ${title || '未命名来源'}`
    })()
    const extra = []
    if (reason) extra.push(`   原因：${reason}`)
    if (type && type !== 'sources' && excerpt) extra.push(`   摘录：${excerpt}`)
    return extra.length ? [head, ...extra] : [head]
  })
  let text = `${label}（共 ${total} 条）：\n${lines.join('\n')}`
  if (preview.moreCount > 0) text += `\n还有 ${preview.moreCount} 条未展示`
  return text
}

const formatQueryPlanListMessage = (payload) => {
  if (!payload || typeof payload !== 'object') return ''
  const subtopics = Array.isArray(payload?.subtopics) ? payload.subtopics : []
  const queries = Array.isArray(payload?.search_queries) ? payload.search_queries : []
  const head = `检索计划（子主题 ${subtopics.length} / 查询 ${queries.length}）：`
  const lines = []
  if (subtopics.length) {
    const st = subtopics.slice(0, 6).map((x, i) => {
      const id = String(x?.id || '').trim()
      const goal = String(x?.goal || '').trim()
      const t = [id ? `#${id}` : '', goal].filter(Boolean).join(' ')
      return `${i + 1}. ${t || '（空）'}`
    })
    lines.push('子主题：')
    lines.push(...st)
  }
  if (queries.length) {
    const qs = queries.slice(0, 10).map((q, i) => {
      const sid = String(q?.subtopic_id || '').trim()
      const query = String(q?.query || '').trim()
      const inc = Array.isArray(q?.include) ? q.include.filter(Boolean).slice(0, 4) : []
      const exc = Array.isArray(q?.exclude) ? q.exclude.filter(Boolean).slice(0, 4) : []
      const tr = String(q?.time_range || '').trim()
      const parts = []
      if (sid) parts.push(`子主题 ${sid}`)
      if (tr) parts.push(`时间 ${tr}`)
      if (inc.length) parts.push(`包含：${inc.join('、')}`)
      if (exc.length) parts.push(`排除：${exc.join('、')}`)
      const suffix = parts.length ? `（${parts.join('，')}）` : ''
      return `${i + 1}. ${query || '（空）'}${suffix}`
    })
    lines.push('查询：')
    lines.push(...qs)
  }
  if (!lines.length) return head + '\n暂无'
  const more = (subtopics.length > 6 || queries.length > 10) ? '\n还有部分未展示' : ''
  return head + '\n' + lines.join('\n') + more
}

const formatFactPackListMessage = (payload) => {
  if (!payload || typeof payload !== 'object') return ''
  const facts = Array.isArray(payload?.facts) ? payload.facts : []
  const stats = Array.isArray(payload?.stats) ? payload.stats : []
  const cases = Array.isArray(payload?.cases) ? payload.cases : []
  const defs = Array.isArray(payload?.definitions) ? payload.definitions : []
  const quotes = Array.isArray(payload?.quotes) ? payload.quotes : []
  const conflicts = Array.isArray(payload?.conflicts) ? payload.conflicts : []
  const opens = Array.isArray(payload?.open_questions) ? payload.open_questions : []
  const head = `事实包（facts ${facts.length} / stats ${stats.length} / cases ${cases.length} / definitions ${defs.length} / quotes ${quotes.length} / conflicts ${conflicts.length} / open_questions ${opens.length}）：`
  const lines = []
  const pushTop = (label, arr, mapLine, limit) => {
    if (!arr.length) return
    lines.push(label + '：')
    for (const [idx, item] of arr.slice(0, limit).entries()) {
      const line = mapLine(item, idx)
      if (line) lines.push(line)
    }
    if (arr.length > limit) lines.push('还有部分未展示')
  }
  pushTop('Facts', facts, (f, i) => {
    const s = String(f?.statement || '').trim()
    const srcIds = Array.isArray(f?.source_ids) ? f.source_ids.filter(x => x !== null && x !== undefined && String(x).trim() !== '').slice(0, 6) : []
    const date = f?.date ? String(f.date) : ''
    const conf = Number(f?.confidence)
    const extra = []
    if (srcIds.length) extra.push(`来源 ${srcIds.join(',')}`)
    if (date) extra.push(`日期 ${date}`)
    if (Number.isFinite(conf)) extra.push(`置信 ${Math.max(0, Math.min(1, conf)).toFixed(2)}`)
    const suffix = extra.length ? `（${extra.join('，')}）` : ''
    return `${i + 1}. ${s || '（空）'}${suffix}`
  }, 10)
  pushTop('Stats', stats, (x, i) => {
    const s = String(x?.statement || x?.stat || '').trim()
    const srcIds = Array.isArray(x?.source_ids) ? x.source_ids.filter(Boolean).slice(0, 6) : []
    const suffix = srcIds.length ? `（来源 ${srcIds.join(',')}）` : ''
    return `${i + 1}. ${s || '（空）'}${suffix}`
  }, 6)
  pushTop('Cases', cases, (x, i) => {
    const s = String(x?.case || x?.title || x?.summary || '').trim()
    const srcIds = Array.isArray(x?.source_ids) ? x.source_ids.filter(Boolean).slice(0, 6) : []
    const suffix = srcIds.length ? `（来源 ${srcIds.join(',')}）` : ''
    return `${i + 1}. ${s || '（空）'}${suffix}`
  }, 6)
  pushTop('Open questions', opens, (x, i) => {
    const s = String(x?.question || x?.q || x || '').trim()
    return `${i + 1}. ${s || '（空）'}`
  }, 6)
  if (!lines.length) return head + '\n暂无'
  return head + '\n' + lines.join('\n')
}

const hydrateActiveWorkReferencesPanel = async (workId, taskId) => {
  if (String(activeWorkId.value || '').trim() !== String(workId || '').trim()) return
  if (!workId || !taskId) return
  try {
    const sourcesArt = await fetchWorkArtifactCached(workId, taskId, 'sources')
    const fetchedArt = await fetchWorkArtifactCached(workId, taskId, 'fetched_sources')
    const sourcesPayload = parseJsonSafely(sourcesArt?.payload_json)
    const fetchedPayload = parseJsonSafely(fetchedArt?.payload_json)
    const sourcesItems = Array.isArray(sourcesPayload?.items) ? sourcesPayload.items : []
    const fetchedItems = Array.isArray(fetchedPayload?.items) ? fetchedPayload.items : []

    workArtifactsSources.value = sourcesItems.map((x, idx) => {
      const url = String(x?.url || '').trim()
      return {
        key: `${idx}-${url}`,
        url,
        title: String(x?.title || '').trim(),
        host: getHostFromUrl(url)
      }
    })
    const itemsWithBody = fetchedItems.length ? fetchedItems : sourcesItems
    workArtifactsFetchedSources.value = itemsWithBody.map((x, idx) => {
      const url = String(x?.url || '').trim()
      const excerptRaw = String(x?.content_excerpt || '').trim()
      return {
        key: `${idx}-${url}`,
        url,
        title: String(x?.title || '').trim(),
        host: getHostFromUrl(url),
        excerpt: excerptRaw ? excerptRaw.slice(0, 260) : '',
        content: excerptRaw
      }
    })
    if (expandedReferenceKey.value) {
      const exists = workArtifactsFetchedSources.value.some(x => String(x?.key || '') === String(expandedReferenceKey.value))
      if (!exists) expandedReferenceKey.value = ''
    }
  } catch {
    return
  }
}

const fetchWritingArtifact = async (taskId, type) => {
  if (!taskId || !type) return null
  try {
    const res = await request.get('/api/v1/writing/result', { params: { task_id: taskId, type } })
    if (res.data.code !== 200) return null
    return res.data.data || null
  } catch {
    return null
  }
}

const fetchWritingArtifactWithRetry = async (taskId, type, opts = {}) => {
  const attempts = Number.isFinite(Number(opts?.attempts)) ? Math.max(1, Math.round(Number(opts.attempts))) : 5
  const delayMs = Number.isFinite(Number(opts?.delayMs)) ? Math.max(0, Math.round(Number(opts.delayMs))) : 800
  for (let i = 0; i < attempts; i++) {
    const art = await fetchWritingArtifact(taskId, type)
    const text = String(art?.text || '')
    const payload = String(art?.payload_json || '')
    if (text.trim() || payload.trim()) return art
    if (i < attempts - 1 && delayMs > 0) {
      await new Promise(r => setTimeout(r, delayMs))
    }
  }
  return null
}

const stageToDetailArtifactType = (stage) => {
  if (!stage) return ''
  if (stage === 'STAGE_NEED_ASSESS') return 'need_assess'
  if (stage === 'STAGE_QUERY_PLAN') return 'query_plan'
  if (stage === 'STAGE_WEB_SEARCH') return 'sources'
  if (stage === 'STAGE_FETCH_SOURCES') return 'fetched_sources'
  if (stage === 'STAGE_TITLE_FILTER') return 'title_filtered_out_sources'
  if (stage === 'STAGE_CLEAN_SOURCES') return 'fetched_sources'
  if (stage === 'STAGE_FACT_PACK') return 'fact_pack'
  if (stage === 'STAGE_OUTLINE') return 'outline'
  if (stage === 'STAGE_NEUTRAL_DRAFT') return 'neutral_draft'
  if (stage === 'STAGE_COMPILE_STYLE') return 'style_runtime_config'
  if (stage === 'STAGE_STYLE_TRANSFER') return 'styled_draft'
  if (stage === 'STAGE_STYLE_QA') return 'style_report'
  if (stage === 'STAGE_RISK_CHECK') return 'risk_report'
  if (stage === 'STAGE_REWRITE_SEGMENTS') return 'styled_draft'
  if (stage === 'STAGE_FINALIZE') return 'final_article'
  return ''
}

const workStageRank = {
  STAGE_NEED_ASSESS: 10,
  STAGE_QUERY_PLAN: 20,
  STAGE_WEB_SEARCH: 30,
  STAGE_FETCH_SOURCES: 40,
  STAGE_TITLE_FILTER: 50,
  STAGE_CLEAN_SOURCES: 60,
  STAGE_FACT_PACK: 70,
  STAGE_OUTLINE: 80,
  STAGE_NEUTRAL_DRAFT: 90,
  STAGE_COMPILE_STYLE: 95,
  STAGE_STYLE_TRANSFER: 100,
  STAGE_STYLE_QA: 110,
  STAGE_RISK_CHECK: 120,
  STAGE_REWRITE_SEGMENTS: 130,
  STAGE_FINALIZE: 140,
  SUCCEEDED: 200,
  FAILED: 200,
  CANCELLED: 200
}
const normalizeWorkContentToHtml = (raw) => {
  const s = String(raw ?? '')
  if (!s.trim()) return ''
  if (/<[a-z][\s\S]*>/i.test(s)) return s
  return md.render(s)
}

const workEditorCharCount = computed(() => {
  const w = activeWork.value
  if (!w) return 0
  const html = normalizeWorkContentToHtml(w.content || '')
  return countChars(htmlToPlainText(html))
})

const workEditorFooterText = computed(() => {
  const w = activeWork.value
  if (!w) return ''
  const parts = [`字数：${workEditorCharCount.value}`]
  const t = String(w.updatedAtText || '').trim()
  if (t) parts.push(t)
  return parts.join(' · ')
})

const renderWorkContentToEditor = (raw) => {
  const el = workEditorRef.value
  if (!el) return
  const html = normalizeWorkContentToHtml(raw)
  el.innerHTML = html
}

const renderWorkEditorFromState = async () => {
  if (activeTab.value !== 'works') return
  if (workEditorFocused.value) return
  await nextTick()
  if (workEditorFocused.value) return
  const w = activeWork.value
  if (!w) return
  if (!workEditorRef.value) return
  renderWorkContentToEditor(w.content || '')
}

const onWorkEditorInput = () => {
  const el = workEditorRef.value
  const w = activeWork.value
  if (!el || !w?.id) return
  updateActiveWorkField(w.id, 'content', el.innerHTML)
}

const onWorkEditorPaste = (e) => {
  const text = e?.clipboardData?.getData?.('text/plain')
  if (typeof text !== 'string') return
  e.preventDefault()
  try {
    document.execCommand('insertText', false, text)
  } catch {
    const el = workEditorRef.value
    if (!el) return
    const sel = window.getSelection()
    if (!sel || sel.rangeCount === 0) return
    sel.deleteFromDocument()
    sel.getRangeAt(0).insertNode(document.createTextNode(text))
    sel.collapseToEnd()
  }
  onWorkEditorInput()
}

const onWorkEditorClick = (e) => {
  const target = e?.target
  if (!(target instanceof HTMLElement)) return
  if (target.tagName === 'A') {
    e.preventDefault()
    const href = target.getAttribute('href')
    if (href) window.open(href, '_blank')
  }
}

const onWorkEditorFocus = () => {
  workEditorFocused.value = true
}

const onWorkEditorBlur = () => {
  const sel = window.getSelection()
  if (sel && sel.rangeCount > 0) {
    workEditorSelectionRange.value = sel.getRangeAt(0).cloneRange()
  }
  workEditorFocused.value = false
}

const applyWorkEditorCommand = (cmd, value) => {
  if (!workEditorRef.value) return
  workEditorRef.value.focus()
  try {
    document.execCommand(cmd, false, value)
  } catch {
    void 0
  }
  onWorkEditorInput()
}

const sanitizeWorkUrl = (url) => {
  const u = String(url || '').trim()
  if (!u) return ''
  if (/^https?:\/\//i.test(u)) return u
  if (/^mailto:/i.test(u)) return u
  return `https://${u}`
}

const openWorkEditorLinkPrompt = async () => {
  try {
    const { value } = await ElMessageBox.prompt('请输入链接', '添加链接', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      inputPlaceholder: 'https://example.com'
    })
    const href = sanitizeWorkUrl(value)
    if (!href) return
    workEditorRef.value?.focus?.()
    const sel = window.getSelection()
    const text = sel && sel.toString() ? sel.toString() : href
    const html = `<a href="${href}" target="_blank" rel="noopener noreferrer">${text}</a>`
    applyWorkEditorCommand('insertHTML', html)
  } catch {
    return
  }
}

const triggerWorkEditorImagePick = () => {
  workImageInputRef.value?.click?.()
}

const handleWorkEditorImagePicked = async (e) => {
  const input = e?.target
  const files = input?.files
  if (!files || files.length === 0) return
  const formData = new FormData()
  for (let i = 0; i < files.length; i += 1) {
    formData.append('files[]', files[i])
  }
  try {
    const res = await request.post('/api/v1/image/upload', formData)
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '上传失败')
      return
    }
    const urls = res.data.data?.urls || []
    if (!Array.isArray(urls) || urls.length === 0) return
    workEditorRef.value?.focus?.()
    for (const url of urls) {
      const safe = String(url || '')
      if (!safe) continue
      applyWorkEditorCommand('insertHTML', `<p><img src="${safe}" alt="" /></p>`)
    }
    onWorkEditorInput()
  } catch {
    ElMessage.error('上传失败')
  } finally {
    if (input) input.value = ''
  }
}

const createWorkDialogVisible = ref(false)
const createWorkSubmitting = ref(false)
const createBlankWorkSubmitting = ref(false)
const createWorkForm = ref({
  topic: '',
  word_count: '',
  style_id: '',
  style_profile_id: ''
})
const styleProfileOptions = ref([])
const styleProfileOptionsLoading = ref(false)

const applyStyleDialogVisible = ref(false)
const applyStyleSubmitting = ref(false)
const applyStyleForm = ref({
  style_id: '',
  style_profile_id: ''
})
const applyStyleProfileOptions = ref([])
const applyStyleProfileOptionsLoading = ref(false)

const openCreateWorkDialog = async () => {
  createWorkDialogVisible.value = true
  createWorkForm.value.style_id = ''
  createWorkForm.value.style_profile_id = ''
  styleProfileOptions.value = []
}

const openApplyStyleDialog = async () => {
  const w = activeWork.value
  if (!w?.task_id) return
  applyStyleDialogVisible.value = true
  applyStyleForm.value.style_id = String(w.style_id || '').trim()
  applyStyleForm.value.style_profile_id = String(w.style_profile_id || '').trim()
  applyStyleProfileOptions.value = []
  if (applyStyleForm.value.style_id) {
    await fetchApplyStyleProfileOptions(applyStyleForm.value.style_id)
  }
}

const createBlankWork = async () => {
  if (createBlankWorkSubmitting.value) return
  createBlankWorkSubmitting.value = true
  try {
    const res = await request.post('/api/v1/resources/work', { title: '未命名作品' })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '创建作品失败')
      return
    }
    const item = res.data.data?.item
    const id = String(item?.resource_id || '').trim()
    if (!id) {
      ElMessage.error('创建作品失败')
      return
    }

    const title = String(item?.title || '').trim()
    const updated = String(item?.updated_at || item?.created_at || '').trim()
    const styleId = String(item?.style_id || item?.styleId || '').trim()
    const styleProfileId = String(item?.style_profile_id || item?.styleProfileId || '').trim()
    const taskId = String(item?.task_id || item?.taskId || '').trim()
    const taskStatus = parseJsonSafely(item?.task_status ?? item?.taskStatus ?? item?.task_status_json) || null
    const topic = String(item?.topic || '').trim()
    const genre = String(item?.genre || '').trim()
    const wordCount = Number(item?.word_count || item?.wordCount || 0) || 0
    const content = typeof item?.content === 'string' ? item.content : ''
    const generating = isWorkTaskRunning({ task_status: taskStatus })

    const newWork = {
      id,
      type: 'document',
      categoryId: 'document',
      name: title || '未命名',
      updatedAtText: updated || '刚刚',
      topic,
      genre,
      word_count: wordCount || undefined,
      task_id: taskId || undefined,
      style_id: styleId,
      style_profile_id: styleProfileId || undefined,
      task_status: taskStatus || undefined,
      generating,
      content,
      contentLoaded: true
    }

    works.value = [newWork, ...works.value.filter(w => String(w.id) !== String(id))]
    activeTab.value = 'works'
    activeWorkId.value = id
  } catch {
    ElMessage.error('创建作品失败')
  } finally {
    createBlankWorkSubmitting.value = false
  }
}

const handleCreateWorkStyleChange = async (styleId) => {
  if (!styleId) {
    styleProfileOptions.value = []
    createWorkForm.value.style_profile_id = ''
    return
  }
  await fetchStyleProfileOptions(styleId)
}

const handleApplyStyleStyleChange = async (styleId) => {
  if (!styleId) {
    applyStyleProfileOptions.value = []
    applyStyleForm.value.style_profile_id = ''
    return
  }
  await fetchApplyStyleProfileOptions(styleId)
}

const styleProfileListCacheByStyleId = ref({})
const styleProfileListInflightByStyleId = ref({})

const fetchStyleProfileListCached = async (styleId, limit = 20, options = {}) => {
  const sid = String(styleId || '').trim()
  if (!sid) return []

  const force = !!options.force
  const ttlMs = 90_000
  const now = Date.now()

  const cached = styleProfileListCacheByStyleId.value?.[sid]
  if (!force && cached && Array.isArray(cached.items) && (now - Number(cached.at || 0)) < ttlMs) {
    return cached.items
  }

  const inflight = styleProfileListInflightByStyleId.value?.[sid]
  if (!force && inflight) return inflight

  const p = (async () => {
    try {
      const res = await request.get('/api/v1/style_profile/list', { params: { style_id: sid, limit } })
      if (res.data.code !== 200) return []
      const items = Array.isArray(res.data.data?.items) ? res.data.data.items : []
      styleProfileListCacheByStyleId.value = {
        ...(styleProfileListCacheByStyleId.value || {}),
        [sid]: { at: now, items }
      }
      return items
    } catch {
      return []
    } finally {
      styleProfileListInflightByStyleId.value = { ...(styleProfileListInflightByStyleId.value || {}), [sid]: null }
    }
  })()

  styleProfileListInflightByStyleId.value = { ...(styleProfileListInflightByStyleId.value || {}), [sid]: p }
  return p
}

const fetchStyleProfileOptions = async (styleId) => {
  const key = String(styleId || '').trim()
  if (!key) {
    styleProfileOptions.value = []
    styleProfileOptionsLoading.value = false
    createWorkForm.value.style_profile_id = ''
    return
  }
  styleProfileOptionsLoading.value = true
  styleProfileOptions.value = []
  createWorkForm.value.style_profile_id = ''
  try {
    const items = await fetchStyleProfileListCached(key, 20)
    styleProfileOptions.value = items.map((it) => {
      const ts = it.created_at || it.updated_at || ''
      const suffix = ts ? `（${ts}）` : ''
      return { id: it.id, label: `#${it.id}${suffix}` }
    })
    if (styleProfileOptions.value.length) {
      createWorkForm.value.style_profile_id = styleProfileOptions.value[0].id
    }
  } catch {
    return
  } finally {
    styleProfileOptionsLoading.value = false
  }
}

const fetchApplyStyleProfileOptions = async (styleId) => {
  const key = String(styleId || '').trim()
  if (!key) {
    applyStyleProfileOptions.value = []
    applyStyleProfileOptionsLoading.value = false
    applyStyleForm.value.style_profile_id = ''
    return
  }
  applyStyleProfileOptionsLoading.value = true
  applyStyleProfileOptions.value = []
  applyStyleForm.value.style_profile_id = ''
  try {
    const items = await fetchStyleProfileListCached(key, 20)
    applyStyleProfileOptions.value = items.map((it) => {
      const ts = it.created_at || it.updated_at || ''
      const suffix = ts ? `（${ts}）` : ''
      return { id: it.id, label: `#${it.id}${suffix}` }
    })
    if (applyStyleProfileOptions.value.length) {
      applyStyleForm.value.style_profile_id = applyStyleProfileOptions.value[0].id
    }
  } catch {
    return
  } finally {
    applyStyleProfileOptionsLoading.value = false
  }
}

const isWorkTaskRunning = (work) => {
  const status = String(work?.task_status?.status || '').trim()
  const stage = String(work?.task_status?.stage || '').trim()
  const progress = workTaskProgress(work?.task_status)
  const styleProfileIdRaw = work?.style_profile_id
  const styleProfileIdNum = Number(styleProfileIdRaw)
  const hasStyleProfile = Number.isFinite(styleProfileIdNum) && styleProfileIdNum > 0

  if (status === 'WAIT_STYLE_TRANSFER' || stage === 'WAIT_STYLE_TRANSFER') return false
  if (status === 'SUCCEEDED' || status === 'FAILED' || status === 'CANCELLED') return false
  if (stage === 'SUCCEEDED' || stage === 'FAILED' || stage === 'CANCELLED') return false
  if (!hasStyleProfile && (status === 'STAGE_NEUTRAL_DRAFT' || stage === 'STAGE_NEUTRAL_DRAFT') && progress >= 75) return false

  if (status === 'QUEUED' || stage === 'QUEUED') return true
  if (status.startsWith('STAGE_') || stage.startsWith('STAGE_')) return true
  return false
}

const workTaskLabel = (task) => {
  const st = task?.status
  if (st === 'QUEUED') return '队列中'
  if (st === 'WAIT_STYLE_TRANSFER') return '等待风格选择'
  if (st === 'SUCCEEDED') return '已完成'
  if (st === 'FAILED') return '失败'
  if (st === 'CANCELLED') return '已取消'
  if (st && String(st).startsWith('STAGE_')) return '生成中'
  return st ? String(st) : ''
}

const workTaskStageLabel = (stage) => {
  if (!stage) return ''
  if (stage === 'QUEUED') return '队列中'
  if (stage === 'WAIT_STYLE_TRANSFER') return '等待风格选择'
  if (stage === 'SUCCEEDED') return '完成'
  if (stage === 'FAILED') return '失败'
  if (stage === 'CANCELLED') return '已取消'
  if (stage === 'STAGE_COMPILE_STYLE') return '风格编译'
  if (stage === 'STAGE_NEED_ASSESS') return '写前评估'
  if (stage === 'STAGE_QUERY_PLAN') return '检索计划'
  if (stage === 'STAGE_WEB_SEARCH') return '联网搜索'
  if (stage === 'STAGE_FETCH_SOURCES') return '抓取资料'
  if (stage === 'STAGE_TITLE_FILTER') return '标题筛选'
  if (stage === 'STAGE_CLEAN_SOURCES') return '清洗资料'
  if (stage === 'STAGE_FACT_PACK') return '事实包'
  if (stage === 'STAGE_OUTLINE') return '大纲'
  if (stage === 'STAGE_NEUTRAL_DRAFT') return '中性稿'
  if (stage === 'STAGE_STYLE_TRANSFER') return '风格迁移'
  if (stage === 'STAGE_STYLE_QA') return '风格自检'
  if (stage === 'STAGE_RISK_CHECK') return '相似度风控'
  if (stage === 'STAGE_REWRITE_SEGMENTS') return '段落重写'
  if (stage === 'STAGE_FINALIZE') return '最终整理'
  return String(stage)
}

const workTaskProgress = (task) => {
  const raw = task?.progress
  const parsed = Number(raw)
  if (Number.isFinite(parsed)) {
    const n = Math.round(parsed)
    return Math.max(0, Math.min(100, n))
  }
  return 0
}

const getWorkDisplayName = (workId) => {
  if (!workId) return ''
  const w = works.value.find(x => String(x.id) === String(workId))
  const topic = String(w?.topic || '').trim()
  const name = String(w?.name || '').trim()
  if (topic) return topic
  if (name) return name
  return ''
}

const ensureWorkChatStatusMessageId = (workId, initialAction) => {
  if (!workId) return null
  const existingId = workChatStatusMessageIdByWorkId.value?.[workId]
  if (existingId) {
    const m = (chatStore.messages.value || []).find(x => String(x.id) === String(existingId))
    if (m) return existingId
  }
  const id = chatStore.addAssistantMessage('', {
    thinking: true,
    thinkingDone: false,
    thinkingAction: String(initialAction || '队列中'),
    thinkingSteps: [],
    isThinkingTrace: true,
    timestamp: Date.now()
  })
  workChatStatusMessageIdByWorkId.value = { ...(workChatStatusMessageIdByWorkId.value || {}), [workId]: id }
  return id
}

const appendWorkChatStep = (workId, line) => {
  if (!workId) return
  const text = String(line || '').trim()
  if (!text) return
  const msgId = ensureWorkChatStatusMessageId(workId, '队列中')
  if (!msgId) return
  const msg = (chatStore.messages.value || []).find(m => String(m.id) === String(msgId))
  if (!msg) return

  const seenKey = String(text)
  const prevSeen = workChatStepSeenByWorkId.value?.[workId] || {}
  if (prevSeen[seenKey]) return
  workChatStepSeenByWorkId.value = {
    ...(workChatStepSeenByWorkId.value || {}),
    [workId]: { ...prevSeen, [seenKey]: true }
  }

  if (!Array.isArray(msg.thinkingSteps)) msg.thinkingSteps = []
  msg.thinkingSteps.push(text)
  msg.thinkingLoadingIndex = msg.thinkingSteps.length - 1
  msg.thinking = true
  msg.thinkingDone = false
  chatStore.streamTicker.value++
}

const updateWorkChatStatus = (workId, task, options = {}) => {
  if (!workId || !task) return
  const force = !!options.force
  const stage = String(task?.stage || '')
  const status = String(task?.status || '')
  const progress = workTaskProgress(task)
  const prev = workLastChatSnapshotByWorkId.value?.[workId]
  const progressDelta = prev ? Math.abs(progress - (Number(prev.progress) || 0)) : 999
  const shouldUpdate = force || !prev || stage !== prev.stage || status !== prev.status || progress === 100 || progressDelta >= 5

  const stageOrStatus = stage || status
  const label = workTaskStageLabel(stageOrStatus)
  const action = (status === 'WAIT_STYLE_TRANSFER')
    ? '等待风格选择'
    : (status === 'SUCCEEDED')
      ? '任务已完成'
      : (status === 'FAILED')
        ? '写作失败'
        : (status === 'CANCELLED')
          ? '写作已取消'
          : `${label || '处理中'} ${progress}%`

  const msgId = ensureWorkChatStatusMessageId(workId, action)
  if (!msgId) return

  if (!prev && (status === 'QUEUED' || stageOrStatus === 'QUEUED')) {
    appendWorkChatStep(workId, '任务已创建，队列中')
  }
  if (prev && stageOrStatus && stageOrStatus !== prev.stage) {
    appendWorkChatStep(workId, `进入阶段：${workTaskStageLabel(stageOrStatus)}`)
  }
  if (status === 'SUCCEEDED' && (!prev || prev.status !== 'SUCCEEDED')) {
    appendWorkChatStep(workId, '任务已完成')
  }
  if (status === 'FAILED' && (!prev || prev.status !== 'FAILED')) {
    const em = String(task?.error_message || '').trim()
    appendWorkChatStep(workId, em ? `任务失败：${em}` : '任务失败')
  }
  if (status === 'CANCELLED' && (!prev || prev.status !== 'CANCELLED')) {
    appendWorkChatStep(workId, '任务已取消')
  }

  if (shouldUpdate) {
    const waiting = status === 'WAIT_STYLE_TRANSFER'
    chatStore.updateMessage(msgId, { thinking: !waiting, thinkingDone: waiting, thinkingAction: action })
  }

  if (status === 'SUCCEEDED' || status === 'FAILED' || status === 'CANCELLED') {
    chatStore.updateMessage(msgId, { thinking: false, thinkingDone: true, thinkingAction: action })
  }
  workLastChatSnapshotByWorkId.value = {
    ...(workLastChatSnapshotByWorkId.value || {}),
    [workId]: { stage: stageOrStatus, status, progress }
  }
}

const recordWorkTaskEvent = (workId, task) => {
  if (!workId || !task) return
  const stage = String(task.stage || task.status || '')
  const status = String(task.status || '')
  const progress = workTaskProgress(task)
  const prev = workLastTaskSnapshotByWorkId.value[workId]

  if (!prev) {
    if (status === 'QUEUED') pushWorkEvent(workId, '任务已创建，队列中', { progress })
    else if (status === 'SUCCEEDED') pushWorkEvent(workId, '任务已完成', { progress })
    else if (status === 'FAILED') pushWorkEvent(workId, `任务失败：${task.error_message || ''}`.trim(), { progress })
    else if (status === 'CANCELLED') pushWorkEvent(workId, '任务已取消', { progress })
    else if (stage) pushWorkEvent(workId, `进入阶段：${workTaskStageLabel(stage)}`, { progress })
    updateWorkChatStatus(workId, task, { force: true })
    workLastTaskSnapshotByWorkId.value = { ...workLastTaskSnapshotByWorkId.value, [workId]: { stage, status, progress } }
    return
  }

  if (stage && stage !== prev.stage) {
    pushWorkEvent(workId, `进入阶段：${workTaskStageLabel(stage)}`, { progress })
    updateWorkChatStatus(workId, task)
  } else if (progress !== prev.progress) {
    pushWorkEvent(workId, `进度更新：${progress}%`, { progress })
    updateWorkChatStatus(workId, task)
  }

  if (status === 'SUCCEEDED' && prev.status !== 'SUCCEEDED') {
    pushWorkEvent(workId, '任务已完成', { progress })
    updateWorkChatStatus(workId, task, { force: true })
  }
  if (status === 'FAILED' && prev.status !== 'FAILED') {
    const msg = String(task.error_message || '').trim()
    pushWorkEvent(workId, msg ? `任务失败：${msg}` : '任务失败', { progress })
    updateWorkChatStatus(workId, task, { force: true })
  }
  if (status === 'CANCELLED' && prev.status !== 'CANCELLED') {
    pushWorkEvent(workId, '任务已取消', { progress })
    updateWorkChatStatus(workId, task, { force: true })
  }

  workLastTaskSnapshotByWorkId.value = { ...workLastTaskSnapshotByWorkId.value, [workId]: { stage, status, progress } }
}

const ensureResearchDetailLists = async (workId, taskId, keyHint = '') => {
  const wid = String(workId || '').trim()
  const tid = String(taskId || '').trim()
  if (!wid || !tid) return
  const key = `${wid}:${tid}:${String(keyHint || '').trim() || 'default'}`
  if (ensuredResearchDetailTaskKeys.has(key)) return
  ensuredResearchDetailTaskKeys.add(key)

  const loadAndPushList = async (type) => {
    const art = await fetchWorkArtifactCached(wid, tid, type)
    const payload = parseJsonSafely(art?.payload_json)
    if (!payload) return

    if (type === 'query_plan') {
      const msg = formatQueryPlanListMessage(payload)
      pushDetailListFromText(wid, type, msg, `ensure:${type}:${tid}`)
      return
    }
    if (type === 'fact_pack') {
      const msg = formatFactPackListMessage(payload)
      pushDetailListFromText(wid, type, msg, `ensure:${type}:${tid}`)
      return
    }

    const rawItems = Array.isArray(payload?.items)
      ? payload.items
      : (payload?.items && typeof payload.items === 'object' ? Object.values(payload.items) : [])
    if (!rawItems.length) return
    const preview = toPreviewSourceItems(type, rawItems)
    pushSearchListFromStep(wid, type, preview)
  }

  await loadAndPushList('query_plan')
  await loadAndPushList('sources')
  await loadAndPushList('fetched_sources')
  await loadAndPushList('fact_pack')
}

const findStyleTransferCardMessage = (workId, taskId) => {
  const wid = String(workId || '').trim()
  const tid = String(taskId || '').trim()
  if (!wid || !tid) return null
  const list = chatStore.messages.value || []
  for (let i = list.length - 1; i >= 0; i--) {
    const m = list[i]
    const c = m?.styleTransferCard
    if (!c || typeof c !== 'object') continue
    if (String(c.type || '').trim() !== 'style_transfer') continue
    if (String(c.workId || '').trim() !== wid) continue
    if (String(c.taskId || '').trim() !== tid) continue
    return m
  }
  return null
}

const findLatestUndecidedStyleTransferCardByWorkId = (workId) => {
  const wid = String(workId || '').trim()
  if (!wid) return null
  const list = chatStore.messages.value || []
  for (let i = list.length - 1; i >= 0; i--) {
    const m = list[i]
    const c = m?.styleTransferCard
    if (!c || typeof c !== 'object') continue
    if (String(c.type || '').trim() !== 'style_transfer') continue
    if (String(c.workId || '').trim() !== wid) continue
    if (c.decided) continue
    return m
  }
  return null
}

const ensureWaitStyleTransferUI = async (workId, taskId) => {
  const wid = String(workId || '').trim()
  const tid = String(taskId || '').trim()
  if (!wid || !tid) return

  const key = `${wid}:${tid}`
  if (ensuredWaitStyleTransferTaskKeys.has(key)) return
  ensuredWaitStyleTransferTaskKeys.add(key)

  try {
    activeTab.value = 'works'
    activeWorkId.value = wid

    const idx0 = works.value.findIndex(w => String(w?.id) === String(wid))
    if (idx0 >= 0) {
      const prevTs = works.value[idx0]?.task_status || null
      const prevProgress = workTaskProgress(prevTs)
      const nextTs = {
        ...(prevTs && typeof prevTs === 'object' ? prevTs : {}),
        status: 'WAIT_STYLE_TRANSFER',
        stage: 'STAGE_NEUTRAL_DRAFT',
        progress: Math.max(75, prevProgress || 0)
      }
      works.value[idx0] = {
        ...works.value[idx0],
        task_id: tid || works.value[idx0]?.task_id,
        task_status: nextTs,
        generating: false
      }
    }

    let neutralText = ''
    try {
      await loadWorkDetail(wid, { force: true })
      neutralText = String(works.value.find(w => String(w.id) === String(wid))?.content || '')
    } catch {
      neutralText = ''
    }
    let neutralArt = null
    if (!neutralText.trim()) {
      neutralArt = await fetchWorkArtifactCached(wid, tid, 'neutral_draft')
      neutralText = String(neutralArt?.text || '')
    }
    if (!neutralText.trim()) {
      const retryArt = await fetchWritingArtifactWithRetry(tid, 'neutral_draft', { attempts: 6, delayMs: 900 })
      if (retryArt) {
        cacheWorkArtifact(wid, 'neutral_draft', retryArt)
        neutralArt = retryArt
        neutralText = String(retryArt?.text || '')
      }
    }
    if (neutralText.trim()) {
      const idx2 = works.value.findIndex(w => String(w.id) === String(wid))
      if (idx2 >= 0) {
        works.value[idx2] = {
          ...works.value[idx2],
          content: neutralText,
          contentLoaded: true
        }
      }
      workArtifactsNeutralDraft.value = neutralText
      sidebarExpanded.value = true
      activeSidebarPanel.value = 'neutral'
      await nextTick()
      updateHalfSidebarWidth()
      scheduleNeutralDraftReveal()
      if (activeWorkId.value === wid && workEditorRef.value) renderWorkContentToEditor(neutralText)
    }

    const pendingKey = `${String(tid || '').trim()}`
    const pendingLocal = pendingStyleTransferChoicesByWorkId.value?.[wid] || loadPendingStyleChoiceFromStorage(wid)
    const pendingTaskId = String(pendingLocal?.task_id || '').trim()
    const pendingItems = Array.isArray(pendingLocal?.items) ? pendingLocal.items : []

    let limitedChoices = []
    if (pendingTaskId && pendingTaskId === pendingKey && pendingItems.length) {
      limitedChoices = pendingItems.slice(0, 30)
      pendingStyleTransferChoicesByWorkId.value = {
        ...(pendingStyleTransferChoicesByWorkId.value || {}),
        [wid]: { task_id: pendingTaskId, items: limitedChoices }
      }
    } else {
      if (!Array.isArray(styleOptions.value) || styleOptions.value.length === 0) {
        await fetchStyleOptions()
      }
      const styleList = (styleOptions.value || []).filter(s => s && s.style_id).slice(0, 8)
      const choices = []
      for (const s of styleList) {
        if (choices.length >= 30) break
        const sid = String(s?.style_id || '').trim()
        if (!sid) continue
        const sname = String(s?.name || sid).trim() || sid
        const items = await fetchStyleProfileListCached(sid, 20)
        for (const it of items) {
          if (choices.length >= 30) break
          const pid = Number(it?.id)
          if (!Number.isFinite(pid) || pid <= 0) continue
          const ts = String(it?.created_at || it?.updated_at || '').trim()
          const suffix = ts ? `（${ts}）` : ''
          choices.push({
            style_id: sid,
            style_name: sname,
            style_profile_id: String(pid),
            label: `${sname} / #${pid}${suffix}`
          })
        }
      }
      limitedChoices = choices.slice(0, 30)
      pendingStyleTransferChoicesByWorkId.value = {
        ...(pendingStyleTransferChoicesByWorkId.value || {}),
        [wid]: { task_id: pendingKey, items: limitedChoices }
      }
      if (limitedChoices.length) {
        savePendingStyleChoiceToStorage(wid, { task_id: pendingKey, items: limitedChoices })
      }
    }

    const title = getWorkDisplayName(wid)
    const cardPayload = {
      type: 'style_transfer',
      workId: String(wid || '').trim(),
      taskId: String(tid || '').trim(),
      workTitle: title ? `【中性稿已完成】${title}` : '【中性稿已完成】',
      choices: limitedChoices.map((c) => ({
        key: `${String(c.style_id || '').trim()}::${String(c.style_profile_id || '').trim()}`,
        style_id: String(c.style_id || '').trim(),
        style_profile_id: String(c.style_profile_id || '').trim(),
        label: String(c.label || '').trim()
      })),
      selectedKey: '',
      decided: false,
      decidedText: '',
      loadingAction: ''
    }

    const existingMsg = findLatestUndecidedStyleTransferCardByWorkId(wid) || findStyleTransferCardMessage(wid, tid)
    if (existingMsg) {
      const existingCard = existingMsg?.styleTransferCard || {}
      if (!existingCard.decided) {
        chatStore.updateMessage(existingMsg.id, { styleTransferCard: { ...cardPayload, selectedKey: String(existingCard.selectedKey || '').trim(), decided: false, decidedText: String(existingCard.decidedText || '').trim(), loadingAction: String(existingCard.loadingAction || '').trim() } })
      }
    } else {
      chatStore.addAssistantMessage('', { styleTransferCard: cardPayload })
    }

    appendWorkChatStep(wid, '中性稿已完成，等待选择风格')
    const w = works.value.find(x => String(x?.id) === String(wid))
    const ts = w?.task_status || { status: 'WAIT_STYLE_TRANSFER', stage: 'WAIT_STYLE_TRANSFER', progress: 75 }
    updateWorkChatStatus(wid, ts, { force: true })
    await nextTick()
  } finally {
    const hasCard = !!findLatestUndecidedStyleTransferCardByWorkId(wid)
    if (!hasCard) ensuredWaitStyleTransferTaskKeys.delete(key)
  }
}

const handleWritingTaskUpdate = async (payload) => {
  const taskId = String(payload?.task_id || '').trim()
  if (!taskId) return

  const workIdFromPayload = String(payload?.work_id || payload?.work_resource_id || '').trim()
  let work = workIdFromPayload
    ? works.value.find(w => String(w.id) === String(workIdFromPayload))
    : works.value.find(w => String(w.task_id || '') === String(taskId))

  if (!work) {
    let resolvedWorkId = workIdFromPayload
    if (!resolvedWorkId) {
      try {
        const res = await request.get('/api/v1/writing/task', { params: { task_id: taskId } })
        if (res.data?.code === 200) {
          resolvedWorkId = String(res.data?.data?.work_resource_id || '').trim()
        }
      } catch {
        void 0
      }
    }
    if (!resolvedWorkId) return

    const placeholder = {
      id: resolvedWorkId,
      type: 'document',
      categoryId: 'document',
      name: '未命名作品',
      updatedAtText: '刚刚',
      task_id: taskId,
      style_id: String(payload?.style_id || '').trim(),
      style_profile_id: String(payload?.style_profile_id || '').trim() || undefined,
      task_status: undefined,
      generating: true,
      content: '',
      contentLoaded: false
    }
    works.value = [placeholder, ...works.value]
    work = placeholder
    activeTab.value = 'works'
    activeWorkId.value = resolvedWorkId
  }

  const workId = String(work.id)
  const prevStage = String(workLastTaskSnapshotByWorkId.value?.[workId]?.stage || '')
  const prevStatus = String(workLastTaskSnapshotByWorkId.value?.[workId]?.status || '')

  const status = String(payload?.status || '').trim()
  const stage = String(payload?.stage || '').trim()
  const nextTask = {
    status: status || stage,
    stage: stage || status,
    progress: payload?.progress ?? 0,
    error_message: payload?.error_message || ''
  }
  if (!nextTask.status && !nextTask.stage) return

  if (nextTask.status === 'WAIT_STYLE_TRANSFER') {
    nextTask.stage = 'STAGE_NEUTRAL_DRAFT'
    const p = Number(nextTask.progress)
    nextTask.progress = Number.isFinite(p) ? Math.max(75, p) : 75
  }
  if (nextTask.status === 'SUCCEEDED' || nextTask.status === 'FAILED' || nextTask.status === 'CANCELLED') {
    nextTask.stage = nextTask.status
    if (nextTask.status === 'SUCCEEDED') nextTask.progress = 100
  }

  recordWorkTaskEvent(workId, nextTask)

  const stageNow = String(nextTask?.stage || nextTask?.status || '')
  if (stageNow === 'STAGE_QUERY_PLAN') {
    const p = payload?.preview || null
    const ready = p?.artifact_ready === true || p?.artifactReady === true || p?.ready === true
    const msg = p && typeof p === 'object' ? formatQueryPlanListMessage(p) : ''
    if (msg) {
      pushDetailListFromText(workId, 'query_plan', msg, `preview:query_plan:${taskId}`)
    } else if (ready) {
      void ensureResearchDetailLists(workId, taskId, 'query_plan_ready')
    }
  }
  if (stageNow && (stageNow === 'STAGE_WEB_SEARCH' || stageNow === 'STAGE_FETCH_SOURCES' || stageNow === 'STAGE_TITLE_FILTER' || stageNow === 'STAGE_CLEAN_SOURCES')) {
    const type = stageToDetailArtifactType(stageNow)
    if (type) {
      const preview = resolvePreviewFromStepPayload(type, payload)
      if (preview && (preview.totalCount > 0 || preview.items.length > 0)) {
        pushSearchListFromStep(workId, type, preview)
      }
    }
  }
  if (stageNow === 'STAGE_FACT_PACK') {
    const p = payload?.preview || null
    const summary = String(p?.summary || p?.text || '').trim()
    const topFacts = Array.isArray(p?.topFacts) ? p.topFacts : (Array.isArray(p?.top_facts) ? p.top_facts : [])
    const ready = p?.artifact_ready === true || p?.artifactReady === true || p?.ready === true
    if (summary) appendWorkChatStep(workId, summary)
    if (topFacts.length) {
      for (const line of topFacts.slice(0, 10)) {
        const text = String(line || '').trim()
        if (text) appendWorkChatStep(workId, text)
      }
    }
    if (ready) {
      void ensureResearchDetailLists(workId, taskId, 'fact_pack_ready')
    }
  }

  if (stageNow && stageNow !== prevStage) {
    const prevToArtifactType = {
      STAGE_QUERY_PLAN: 'query_plan',
      STAGE_WEB_SEARCH: 'sources',
      STAGE_FETCH_SOURCES: 'fetched_sources',
      STAGE_TITLE_FILTER: 'title_filtered_out_sources',
      STAGE_CLEAN_SOURCES: 'cleaned_sources',
      STAGE_FACT_PACK: 'fact_pack'
    }
    const finishedType = prevToArtifactType[prevStage] || ''
    if (finishedType) {
      const art = await fetchWorkArtifactCached(workId, taskId, finishedType)
      const payload = parseJsonSafely(art?.payload_json)
      if (finishedType === 'query_plan') {
        const msg = formatQueryPlanListMessage(payload)
        pushDetailListFromText(workId, finishedType, msg, `${prevStage}:${taskId}`)
      } else if (finishedType === 'fact_pack') {
        const msg = formatFactPackListMessage(payload)
        pushDetailListFromText(workId, finishedType, msg, `${prevStage}:${taskId}`)
      } else {
        const rawItems = Array.isArray(payload?.items) ? payload.items : (payload?.items && typeof payload.items === 'object' ? Object.values(payload.items) : [])
        if (rawItems.length) {
          const preview = toPreviewSourceItems(finishedType, rawItems)
          pushSearchListFromStep(workId, finishedType, preview)
        }
        if (finishedType === 'sources' || finishedType === 'cleaned_sources') {
          void hydrateActiveWorkReferencesPanel(workId, taskId)
        }
      }
    }
  }
  const idx = works.value.findIndex(w => String(w.id) === String(workId))
  if (idx >= 0) {
    works.value[idx] = {
      ...works.value[idx],
      task_id: taskId,
      task_status: nextTask,
      generating: isWorkTaskRunning({ task_status: nextTask }),
      updatedAtText: '刚刚'
    }
  }

  const stageRankNow = workStageRank[stageNow] || 0
  if (stageRankNow >= (workStageRank.STAGE_OUTLINE || 80)) {
    void ensureResearchDetailLists(workId, taskId, 'outline_or_later')
  }
  if (stageRankNow >= (workStageRank.STAGE_NEUTRAL_DRAFT || 90) && !ensuredNeutralDraftTaskIds.has(taskId)) {
    let neutralText = ''
    try {
      await loadWorkDetail(workId, { force: true })
      neutralText = String(works.value.find(w => String(w.id) === String(workId))?.content || '')
    } catch {
      neutralText = ''
    }
    let neutralArt = null
    if (!neutralText.trim()) {
      neutralArt = await fetchWorkArtifactCached(workId, taskId, 'neutral_draft')
      neutralText = String(neutralArt?.text || '')
    }
    if (!neutralText.trim()) {
      const retryArt = await fetchWritingArtifactWithRetry(taskId, 'neutral_draft', { attempts: 6, delayMs: 900 })
      if (retryArt) {
        cacheWorkArtifact(workId, 'neutral_draft', retryArt)
        neutralArt = retryArt
        neutralText = String(retryArt?.text || '')
      }
    }
    if (neutralText.trim()) {
      ensuredNeutralDraftTaskIds.add(taskId)

      if (activeWorkId.value === workId) {
        workArtifactsNeutralDraft.value = neutralText
        if (sidebarExpanded.value && activeSidebarPanel.value === 'neutral') scheduleNeutralDraftReveal()
      }

      const editorBusy = activeWorkId.value === workId && workEditorFocused.value
      if (!editorBusy) {
        const idx4 = works.value.findIndex(w => String(w.id) === String(workId))
        if (idx4 >= 0) {
          works.value[idx4] = {
            ...works.value[idx4],
            content: neutralText,
            contentLoaded: true
          }
          schedulePersistWorkDraft(workId)
        }
        if (activeWorkId.value === workId && workEditorRef.value) {
          renderWorkContentToEditor(neutralText)
        }
      }
    }
  }

  const styleProfileIdNow = String(works.value.find(x => String(x?.id) === String(workId))?.style_profile_id || payload?.style_profile_id || '').trim()
  const hasStyleProfileNow = Number(styleProfileIdNow) > 0
  const progressNow = workTaskProgress(nextTask)
  const shouldWaitStyleTransfer = nextTask.status === 'WAIT_STYLE_TRANSFER'
    || (!hasStyleProfileNow && stageNow === 'STAGE_NEUTRAL_DRAFT' && progressNow >= 75)
  if (shouldWaitStyleTransfer && prevStatus !== 'WAIT_STYLE_TRANSFER') {
    await ensureWaitStyleTransferUI(workId, taskId)
  }

  if (nextTask.status === 'SUCCEEDED' && prevStatus !== 'SUCCEEDED') {
    await fetchWritingResult(taskId, workId)
  }
}

const fetchWritingResult = async (taskId, workId) => {
  let attempts = 0
  const maxAttempts = 5
  const hasStyleProfile = !!String(works.value.find(x => String(x?.id) === String(workId))?.style_profile_id || '').trim()
  try {
    for (let i = 0; i < 5; i++) {
      const res = await request.get('/api/v1/writing/result', { params: { task_id: taskId, type: 'final_article' } })
      if (res.data.code !== 200) {
        if (i < 4) await new Promise(r => setTimeout(r, 1200))
        continue
      }
      const hitType = String(res.data.data?.type || '').trim()
      const finalText = String(res.data.data?.text || '')
      if (finalText.trim() && (!hasStyleProfile || hitType !== 'neutral_draft')) {
      try {
          await request.post('/api/v1/resources/update', { resource_id: workId, content: finalText })
      } catch {
          void 0
      }
      const idx = works.value.findIndex(w => w.id === workId)
      if (idx >= 0) {
        works.value[idx] = {
          ...works.value[idx],
          content: finalText,
          contentLoaded: true,
          generating: false,
          updatedAtText: '刚刚',
          task_status: { ...works.value[idx].task_status, status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }
        }
      }
      if (activeWorkId.value === workId && workEditorRef.value) {
        renderWorkContentToEditor(finalText)
      }
      const meta = summarizeMarkdown(finalText)
      pushWorkEvent(workId, `已拉取最终文章，长度 ${meta.chars}`, { progress: 100 })
      appendWorkChatStep(workId, '任务已完成')
      updateWorkChatStatus(workId, { status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }, { force: true })
      return
      }
      if (i < 4) await new Promise(r => setTimeout(r, 1200))
    }
  } catch {
    void 0
  }

  try {
    await loadWorkDetail(workId, { force: true })
    const fromWork = String(works.value.find(w => String(w.id) === String(workId))?.content || '')
    if (fromWork.trim()) {
      const idx = works.value.findIndex(w => w.id === workId)
      if (idx >= 0) {
        works.value[idx] = {
          ...works.value[idx],
          content: fromWork,
          contentLoaded: true,
          generating: false,
          updatedAtText: '刚刚',
          task_status: { ...works.value[idx].task_status, status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }
        }
      }
      if (activeWorkId.value === workId && workEditorRef.value) {
        renderWorkContentToEditor(fromWork)
      }
      const meta = summarizeMarkdown(fromWork)
      pushWorkEvent(workId, `已拉取最终文章，长度 ${meta.chars}`, { progress: 100 })
      appendWorkChatStep(workId, '任务已完成')
      updateWorkChatStatus(workId, { status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }, { force: true })
      return
    }
  } catch {
    void 0
  }
  while (attempts < maxAttempts) {
    attempts++
    try {
      const res = await request.get('/api/v1/writing/result', { params: { task_id: taskId, type: 'final_article' } })
      let text = ''
      const hitType = res.data.code === 200 ? String(res.data.data?.type || '').trim() : ''
      if (res.data.code === 200) {
        text = res.data.data?.text || ''
      }

      if (String(text || '').trim() && hasStyleProfile && hitType === 'neutral_draft' && attempts < maxAttempts) {
        await new Promise(r => setTimeout(r, 2000))
        continue
      }
      
      if (!String(text || '').trim()) {
         if (attempts < maxAttempts) {
             await new Promise(r => setTimeout(r, 2000))
             continue
         }
         // Fallback to neutral draft
         try {
            const res2 = await request.get('/api/v1/writing/result', { params: { task_id: taskId, type: 'neutral_draft' } })
            if (res2.data.code === 200) {
              text = res2.data.data?.text || ''
            }
         } catch { void 0 }
         if (!String(text || '').trim()) {
           try {
             await loadWorkDetail(workId, { force: true })
             text = String(works.value.find(w => w.id === workId)?.content || '')
           } catch { void 0 }
         }
         if (!String(text || '').trim()) {
           const local = works.value.find(w => w.id === workId)?.content || ''
           if (String(local || '').trim()) {
             text = local
           }
         }
      }
      
      if (!String(text || '').trim()) {
        chatStore.addAssistantMessage('最终文章拉取失败，请稍后在作品中重试', { isError: true })
        return
      }

      try {
        await request.post('/api/v1/resources/update', { resource_id: workId, content: text })
      } catch {
        void 0
      }
      const idx = works.value.findIndex(w => w.id === workId)
      if (idx >= 0) {
        works.value[idx] = {
          ...works.value[idx],
          content: text,
          contentLoaded: true,
          generating: false,
          updatedAtText: '刚刚',
          task_status: { ...works.value[idx].task_status, status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }
        }
      }
      if (activeWorkId.value === workId && workEditorRef.value) {
        renderWorkContentToEditor(text)
      }
      const meta = summarizeMarkdown(text)
      pushWorkEvent(workId, `已拉取最终文章，长度 ${meta.chars}`, { progress: 100 })
      appendWorkChatStep(workId, '任务已完成')
      updateWorkChatStatus(workId, { status: 'SUCCEEDED', stage: 'SUCCEEDED', progress: 100 }, { force: true })
      return
    } catch {
      if (attempts >= maxAttempts) {
          chatStore.addAssistantMessage('最终文章拉取失败，请稍后重试', { isError: true })
      }
      await new Promise(r => setTimeout(r, 2000))
    }
  }
}

const workArtifactsDialogVisible = ref(false)
const workArtifactsTab = ref('neutral_draft')
const workArtifactsLoading = ref(false)
const workArtifactsNeutralDraft = ref('')
const workArtifactsSources = ref([])
const workArtifactsFetchedSources = ref([])

const neutralDraftCharCount = computed(() => {
  const raw = String(workArtifactsNeutralDraft.value || '')
  if (!raw) return 0
  return raw.replace(/\s+/g, '').length
})

const expandedReferenceKey = ref('')

const fetchWorkArtifactCached = async (workId, taskId, type) => {
  const cached = workArtifactsByWorkId.value?.[workId]?.[type]
  if (cached) return cached
  const art = await fetchWritingArtifact(taskId, type)
  if (art) cacheWorkArtifact(workId, type, art)
  return art
}

const buildWorkProxyImageUrl = (rawUrl) => {
  const u = String(rawUrl || '').trim()
  if (!u) return ''
  return `/api/proxy/image?url=${encodeURIComponent(u)}`
}

const workFetchedImageHiddenKeysByWorkId = ref(parseJsonSafely(localStorage.getItem('app_graphic_work_fetched_image_hidden_keys_by_work_id')) || {})
const persistWorkFetchedImageHiddenKeysByWorkId = () => {
  localStorage.setItem('app_graphic_work_fetched_image_hidden_keys_by_work_id', JSON.stringify(workFetchedImageHiddenKeysByWorkId.value || {}))
}

const workFetchedImageLoadFailedKeys = ref({})
const isWorkFetchedImageLoadFailed = (key) => {
  const k = String(key || '').trim().toLowerCase()
  if (!k) return false
  return !!workFetchedImageLoadFailedKeys.value?.[k]
}
const markWorkFetchedImageLoadFailed = (key) => {
  const k = String(key || '').trim().toLowerCase()
  if (!k) return
  workFetchedImageLoadFailedKeys.value = { ...(workFetchedImageLoadFailedKeys.value || {}), [k]: true }
}
const clearWorkFetchedImageLoadFailed = (key) => {
  const k = String(key || '').trim().toLowerCase()
  if (!k) return
  const next = { ...(workFetchedImageLoadFailedKeys.value || {}) }
  delete next[k]
  workFetchedImageLoadFailedKeys.value = next
}

const workFetchedImageRetryTokenByKey = ref({})
const bumpWorkFetchedImageRetryToken = (key) => {
  const k = String(key || '').trim().toLowerCase()
  if (!k) return
  workFetchedImageRetryTokenByKey.value = { ...(workFetchedImageRetryTokenByKey.value || {}), [k]: Date.now() }
}

const appendQueryParam = (rawUrl, name, value) => {
  const u = String(rawUrl || '').trim()
  if (!u) return ''
  try {
    const url = new URL(u, window.location.origin)
    url.searchParams.set(String(name), String(value))
    return url.toString()
  } catch {
    const [base, hash = ''] = u.split('#')
    const join = base.includes('?') ? '&' : '?'
    return `${base}${join}${encodeURIComponent(String(name))}=${encodeURIComponent(String(value))}${hash ? `#${hash}` : ''}`
  }
}

const getWorkFetchedImagePreviewSrc = (img) => {
  const key = String(img?.key || '').trim().toLowerCase()
  const token = workFetchedImageRetryTokenByKey.value?.[key]
  const src = String(img?.previewUrl || '').trim()
  if (!src) return ''
  if (!token) return src
  return appendQueryParam(src, '_t', token)
}

const sidebarExpanded = ref(localStorage.getItem('app_graphic_sidebar_expanded') !== '0')
const activeSidebarPanel = ref('images')

const showNeutralDraftContent = ref(false)
let neutralDraftRevealTimer = 0
const clearNeutralDraftRevealTimer = () => {
  if (!neutralDraftRevealTimer) return
  window.clearTimeout(neutralDraftRevealTimer)
  neutralDraftRevealTimer = 0
}
const scheduleNeutralDraftReveal = () => {
  clearNeutralDraftRevealTimer()
  showNeutralDraftContent.value = false
  if (!sidebarExpanded.value) return
  if (activeSidebarPanel.value !== 'neutral') return
  neutralDraftRevealTimer = window.setTimeout(() => {
    neutralDraftRevealTimer = 0
    if (!sidebarExpanded.value) return
    if (activeSidebarPanel.value !== 'neutral') return
    showNeutralDraftContent.value = true
  }, 320)
}
const onWorkImageSidebarTransitionEnd = (e) => {
  const el = workImageSidebarRef.value
  if (!el) return
  if (e?.target !== el) return
  if (e?.propertyName !== 'width') return
  if (!sidebarExpanded.value) return
  if (activeSidebarPanel.value !== 'neutral') return
  clearNeutralDraftRevealTimer()
  showNeutralDraftContent.value = true
}

const halfSidebarWidthPx = ref(0)
const updateHalfSidebarWidth = () => {
  if (!(editorContentWrapperRef.value instanceof HTMLElement)) return
  const baseEl = editorContentWrapperRef.value.closest('.main-editor') || editorContentWrapperRef.value
  const rect = baseEl.getBoundingClientRect()
  const baseWidth = rect.width || 0
  if (!baseWidth) return
  const visibleTarget = baseWidth / 2
  const leftOffsetPx = 80
  halfSidebarWidthPx.value = Math.round(visibleTarget + leftOffsetPx)
}

const workImageSidebarStyle = computed(() => {
  if (!sidebarExpanded.value) return {}
  if (activeSidebarPanel.value !== 'neutral' && activeSidebarPanel.value !== 'references') return {}
  if (!halfSidebarWidthPx.value) return {}
  return { width: `${halfSidebarWidthPx.value}px` }
})

watch([activeSidebarPanel, sidebarExpanded], async () => {
  if (!sidebarExpanded.value) return
  if (activeSidebarPanel.value !== 'neutral' && activeSidebarPanel.value !== 'references') return
  await nextTick()
  updateHalfSidebarWidth()
}, { flush: 'post', immediate: true })

watch(sidebarExpanded, (v) => {
  localStorage.setItem('app_graphic_sidebar_expanded', v ? '1' : '0')
})

watch([sidebarExpanded, activeSidebarPanel], () => {
  if (!sidebarExpanded.value || activeSidebarPanel.value !== 'neutral') {
    clearNeutralDraftRevealTimer()
    showNeutralDraftContent.value = false
    return
  }
  scheduleNeutralDraftReveal()
}, { flush: 'post', immediate: true })

const toggleSidebar = () => {
  sidebarExpanded.value = !sidebarExpanded.value
}

const switchSidebarPanel = async (panel) => {
  activeSidebarPanel.value = panel
  if (!sidebarExpanded.value) {
    sidebarExpanded.value = true
  }
  await nextTick()
  if (panel === 'neutral' || panel === 'references') updateHalfSidebarWidth()
  if (panel === 'images') {
    const w = activeWork.value
    if (w?.id && w?.task_id) {
      await fetchWorkArtifactCached(w.id, w.task_id, 'fetched_sources')
    }
  } else if (panel === 'neutral') {
    await loadWorkArtifacts('neutral_draft')
  } else if (panel === 'references') {
    await loadWorkArtifacts('references')
  }
}


const workFetchedImageItems = computed(() => {
  const w = activeWork.value
  if (!w?.id) return []
  const art = workArtifactsByWorkId.value?.[w.id]?.['fetched_sources']
  const payload = parseJsonSafely(art?.payload_json)
  const items = Array.isArray(payload?.items) ? payload.items : []
  const hiddenKeys = Array.isArray(workFetchedImageHiddenKeysByWorkId.value?.[w.id]) ? workFetchedImageHiddenKeysByWorkId.value[w.id] : []
  const hiddenSet = new Set(hiddenKeys.map(x => String(x || '').trim().toLowerCase()).filter(Boolean))

  const out = []
  const seen = new Set()
  for (const it of items) {
    const storedArr = Array.isArray(it?.images_stored) ? it.images_stored : []
    const storedSet = new Set(storedArr.map(x => String(x || '').trim().toLowerCase()).filter(Boolean))
    const images = Array.isArray(it?.images) ? it.images : []
    for (const imgUrl of images) {
      const u = String(imgUrl || '').trim()
      if (!u) continue
      const key = u.toLowerCase()
      if (seen.has(key)) continue
      if (hiddenSet.has(key)) continue
      seen.add(key)
      const isStored = storedSet.has(key)
      const openUrl = isStored ? u : buildWorkProxyImageUrl(u)
      const insertUrl = isStored ? u : buildWorkProxyImageUrl(u)
      out.push({
        key,
        rawUrl: u,
        previewUrl: openUrl,
        insertUrl,
        openUrl,
        alt: String(it?.title || '').trim(),
        isStored
      })
      if (out.length >= 60) break
    }
    if (out.length >= 60) break
  }
  return out
})

const deleteWorkFetchedImage = async (img) => {
  const w = activeWork.value
  if (!w?.id) return
  const key = String(img?.key || '').trim().toLowerCase()
  if (!key) return

  const map = { ...(workFetchedImageHiddenKeysByWorkId.value || {}) }
  const arr = Array.isArray(map[w.id]) ? map[w.id] : []
  const set = new Set(arr.map(x => String(x || '').trim().toLowerCase()).filter(Boolean))
  set.add(key)
  map[w.id] = Array.from(set)
  workFetchedImageHiddenKeysByWorkId.value = map
  persistWorkFetchedImageHiddenKeysByWorkId()
  clearWorkFetchedImageLoadFailed(key)
  bumpWorkFetchedImageRetryToken(key)
  ElMessage.success('已删除')
}

const requestDeleteWorkFetchedImage = (img) => {
  const key = String(img?.key || '').trim()
  if (!key) return
  workImageDeleteConfirmKey.value = key
}

const cancelWorkFetchedImageDelete = () => {
  workImageDeleteConfirmKey.value = ''
}

const confirmDeleteWorkFetchedImage = async (img) => {
  const key = String(img?.key || '').trim()
  if (!key) return
  await deleteWorkFetchedImage(img)
  if (workImageDeleteConfirmKey.value === key) workImageDeleteConfirmKey.value = ''
}

const insertWorkFetchedImage = (url) => {
  const el = workEditorRef.value
  const u = String(url || '').trim()
  if (!el || !u) return
  
  // Restore selection BEFORE focusing to prevent jumping to top
  if (workEditorSelectionRange.value) {
    const sel = window.getSelection()
    sel.removeAllRanges()
    sel.addRange(workEditorSelectionRange.value)
  }
  
  // Use preventScroll to avoid jumping if the selection restoration didn't fully handle view
  el.focus({ preventScroll: true })

  try {
    document.execCommand('insertImage', false, u)
  } catch {
    try {
      const safe = u.replaceAll('"', '&quot;')
      document.execCommand('insertHTML', false, `<img src="${safe}" />`)
    } catch {
      void 0
    }
  }
  onWorkEditorInput()
}

const isReferenceExpanded = (item) => {
  if (!item) return false
  const k = String(item.key || '').trim()
  if (!k) return false
  return expandedReferenceKey.value === k
}

const toggleReferenceExpanded = (item) => {
  if (!item) return
  const k = String(item.key || '').trim()
  if (!k) return
  expandedReferenceKey.value = expandedReferenceKey.value === k ? '' : k
}

const sidebarTitle = computed(() => {
  if (activeSidebarPanel.value === 'neutral') return '中性稿'
  if (activeSidebarPanel.value === 'references') return '参考资料'
  return '图片列表'
})


const showWorkImageViewer = ref(false)
const workImageViewerUrlList = ref([])

const openWorkFetchedImage = (url) => {
  const u = String(url || '').trim()
  if (!u) return

  const all = workFetchedImageItems.value.map(x => String(x?.openUrl || '').trim()).filter(Boolean)
  const dedup = []
  const seen = new Set()
  for (const it of all) {
    const k = it.toLowerCase()
    if (seen.has(k)) continue
    seen.add(k)
    dedup.push(it)
  }

  const idx = dedup.findIndex(x => x.toLowerCase() === u.toLowerCase())
  if (idx < 0) {
    workImageViewerUrlList.value = [u, ...dedup]
  } else {
    workImageViewerUrlList.value = [...dedup.slice(idx), ...dedup.slice(0, idx)]
  }
  showWorkImageViewer.value = true
}

const closeWorkImageViewer = () => {
  showWorkImageViewer.value = false
  workImageViewerUrlList.value = []
}

const loadWorkArtifacts = async (tab) => {
  const w = activeWork.value
  if (!w?.id || !w?.task_id) return
  workArtifactsLoading.value = true
  try {
    if (tab === 'neutral_draft') {
      const art = await fetchWorkArtifactCached(w.id, w.task_id, 'neutral_draft')
      workArtifactsNeutralDraft.value = String(art?.text || '')
    } else if (tab === 'references') {
      const sourcesArt = await fetchWorkArtifactCached(w.id, w.task_id, 'sources')
      const fetchedArt = await fetchWorkArtifactCached(w.id, w.task_id, 'fetched_sources')
      const sourcesPayload = parseJsonSafely(sourcesArt?.payload_json)
      const fetchedPayload = parseJsonSafely(fetchedArt?.payload_json)
      const sourcesItems = Array.isArray(sourcesPayload?.items) ? sourcesPayload.items : []
      const fetchedItems = Array.isArray(fetchedPayload?.items) ? fetchedPayload.items : []
      workArtifactsSources.value = sourcesItems.map((x, idx) => {
        const url = String(x?.url || '').trim()
        return {
          key: `${idx}-${url}`,
          url,
          title: String(x?.title || '').trim(),
          host: getHostFromUrl(url)
        }
      })
      const itemsWithBody = fetchedItems.length ? fetchedItems : sourcesItems
      workArtifactsFetchedSources.value = itemsWithBody.map((x, idx) => {
        const url = String(x?.url || '').trim()
        const excerptRaw = String(x?.content_excerpt || '').trim()
        return {
          key: `${idx}-${url}`,
          url,
          title: String(x?.title || '').trim(),
          host: getHostFromUrl(url),
          excerpt: excerptRaw ? excerptRaw.slice(0, 260) : '',
          content: excerptRaw
        }
      })
    }
  } finally {
    workArtifactsLoading.value = false
  }
}

const confirmCreateWork = async () => {
  const topic = (createWorkForm.value.topic || '').trim()
  const styleId = (createWorkForm.value.style_id || '').trim()
  const styleProfileIdRaw = styleId ? String(createWorkForm.value.style_profile_id || '').trim() : ''
  const styleProfileId = Number(styleProfileIdRaw) > 0 ? styleProfileIdRaw : ''
  if (!topic) {
    ElMessage.warning('请填写主题/要点')
    return
  }
  if (styleId && !styleProfileId) {
    ElMessage.warning('请选择已生成的 Style Profile')
    return
  }
  createWorkSubmitting.value = true
  try {
    const wc = Number(createWorkForm.value.word_count)
    const wordCount = Number.isFinite(wc) && wc > 0 ? Math.round(wc) : 0
    const payload = {
      topic,
      word_count: wordCount
    }
    if (styleId) payload.style_id = styleId
    if (styleId && styleProfileId) payload.style_profile_id = styleProfileId
    const res = await request.post('/api/v1/writing/create', payload)
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '创建任务失败')
      return
    }
    const taskId = res.data.data?.task_id || ''
    const workResourceId = String(res.data.data?.work_resource_id || '').trim()
    if (!taskId) {
      ElMessage.error('创建任务失败')
      return
    }
    if (!workResourceId) {
      ElMessage.error('创建作品失败')
      return
    }
    const workId = workResourceId
    const newWork = {
      id: workId,
      type: 'document',
      categoryId: 'document',
      name: topic.slice(0, 30),
      topic,
      genre: '',
      word_count: wordCount,
      updatedAtText: '刚刚',
      task_id: taskId,
      style_id: styleId,
      style_profile_id: styleId ? styleProfileId : '',
      task_status: { status: 'QUEUED', stage: 'QUEUED', progress: 0 },
      generating: true,
      content: '',
      contentLoaded: false
    }
    works.value = [newWork, ...works.value]
    activeWorkId.value = workId
    createWorkDialogVisible.value = false
    ensureWorkChatStatusMessageId(workId, '队列中 0%')
    appendWorkChatStep(workId, '任务已创建，队列中')
    createWorkForm.value.topic = ''
    createWorkForm.value.word_count = ''
  } catch {
    ElMessage.error('创建任务失败')
  } finally {
    createWorkSubmitting.value = false
  }
}

const confirmApplyStyle = async () => {
  const w = activeWork.value
  if (!w?.id) {
    ElMessage.error('未找到作品，请刷新后重试')
    return false
  }
  await loadWorkDetail(w.id, { force: true })
  const w2 = activeWork.value
  if (!w2?.id) return false
  if (isWorkTaskRunning(w2)) {
    ElMessage.warning('任务进行中，请稍后再试')
    return false
  }
  const taskIdBeforeApply = String(w2?.task_id || w?.task_id || '').trim()

  const styleId = String(applyStyleForm.value.style_id || '').trim()
  const styleProfileIdRaw = styleId ? String(applyStyleForm.value.style_profile_id || '').trim() : ''
  const styleProfileId = Number(styleProfileIdRaw) > 0 ? styleProfileIdRaw : ''
  if (!styleId) {
    ElMessage.warning('请选择风格')
    return false
  }
  if (!styleProfileId) {
    ElMessage.warning('请选择已生成的 Style Profile')
    return false
  }

  applyStyleSubmitting.value = true
  try {
    const payload = {
      work_resource_id: w.id,
      style_id: styleId,
      style_profile_id: styleProfileId
    }
    if (w.task_id) payload.task_id = w.task_id
    const res = await request.post('/api/v1/writing/apply_style', payload)
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '启动风格迁移失败')
      return false
    }
    const dataRaw = res.data.data || null
    const data = (dataRaw && typeof dataRaw === 'object') ? dataRaw : null
    const nextTaskStatus = data || { status: 'STAGE_STYLE_TRANSFER', stage: 'STAGE_STYLE_TRANSFER', progress: 78 }
    const resolvedTaskId = String(data?.task_id || w.task_id || '').trim()

    const idx = works.value.findIndex(x => String(x.id) === String(w.id))
    if (idx >= 0) {
      works.value[idx] = {
        ...works.value[idx],
        task_id: resolvedTaskId || works.value[idx].task_id,
        style_id: styleId,
        style_profile_id: styleProfileId,
        task_status: nextTaskStatus,
        generating: isWorkTaskRunning({ task_status: nextTaskStatus }),
        updatedAtText: '刚刚'
      }
    }

    ensureWorkChatStatusMessageId(w.id, '风格迁移 0%')
    appendWorkChatStep(w.id, '已选择风格，开始风格迁移')
    updateWorkChatStatus(w.id, nextTaskStatus, { force: true })
    pendingStyleTransferChoicesByWorkId.value = { ...(pendingStyleTransferChoicesByWorkId.value || {}), [String(w.id)]: null }
    savePendingStyleChoiceToStorage(w.id, null)
    applyStyleDialogVisible.value = false
    const styleName = resolveStyleNameById(styleId) || styleId
    closeStyleTransferCardForWork(w.id, taskIdBeforeApply || w.task_id, `已选择：${styleName} / #${styleProfileId}`)
    return true
  } catch {
    ElMessage.error('启动风格迁移失败')
    return false
  } finally {
    applyStyleSubmitting.value = false
  }
}

const confirmSkipStyleTransfer = async () => {
  const w = activeWork.value
  if (!w?.id) {
    ElMessage.error('未找到作品，请刷新后重试')
    return false
  }
  await loadWorkDetail(w.id, { force: true })
  const w2 = activeWork.value
  if (!w2?.id) return false
  if (isWorkTaskRunning(w2)) {
    ElMessage.warning('任务进行中，请稍后再试')
    return false
  }
  const taskIdBeforeSkip = String(w2?.task_id || w?.task_id || '').trim()

  applyStyleSubmitting.value = true
  try {
    const payload = { work_resource_id: w.id }
    if (w.task_id) payload.task_id = w.task_id
    const res = await request.post('/api/v1/writing/skip_style', payload)
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '输出中性稿失败')
      return false
    }
    const dataRaw = res.data.data || null
    const data = (dataRaw && typeof dataRaw === 'object') ? dataRaw : null
    const nextTaskStatus = data || { status: 'STAGE_FINALIZE', stage: 'STAGE_FINALIZE', progress: 90 }
    const resolvedTaskId = String(data?.task_id || w.task_id || '').trim()

    const idx = works.value.findIndex(x => String(x.id) === String(w.id))
    if (idx >= 0) {
      works.value[idx] = {
        ...works.value[idx],
        task_id: resolvedTaskId || works.value[idx].task_id,
        task_status: nextTaskStatus,
        generating: isWorkTaskRunning({ task_status: nextTaskStatus }),
        updatedAtText: '刚刚'
      }
    }

    appendWorkChatStep(w.id, '已选择直接使用中性稿，开始输出到编辑器')
    updateWorkChatStatus(w.id, nextTaskStatus, { force: true })
    pendingStyleTransferChoicesByWorkId.value = { ...(pendingStyleTransferChoicesByWorkId.value || {}), [String(w.id)]: null }
    savePendingStyleChoiceToStorage(w.id, null)
    applyStyleDialogVisible.value = false
    closeStyleTransferCardForWork(w.id, taskIdBeforeSkip || w.task_id, '已选择：直接使用中性稿')
    if (resolvedTaskId) {
      await fetchWritingResult(resolvedTaskId, w.id)
    } else {
      await loadWorkDetail(w.id)
      renderWorkEditorFromState()
    }
    return true
  } catch {
    ElMessage.error('输出中性稿失败')
    return false
  } finally {
    applyStyleSubmitting.value = false
  }
}

const ensureActiveWorkFromStyleTransferCard = async (card) => {
  const workId = String(card?.workId || '').trim()
  if (!workId) {
    ElMessage.error('未找到作品信息')
    return false
  }
  const has = works.value.some(w => String(w.id) === String(workId))
  if (!has) {
    await fetchWorksList()
  }
  const stillHas = works.value.some(w => String(w.id) === String(workId))
  if (!stillHas) {
    ElMessage.error('作品不存在或已被删除，请刷新后重试')
    return false
  }
  activeTab.value = 'works'
  activeWorkId.value = workId
  await nextTick()
  await loadWorkDetail(workId, { force: true })
  return !!activeWork.value?.id
}

const closeStyleTransferCardForWork = (workId, taskId, decidedText) => {
  const wid = String(workId || '').trim()
  const tid = String(taskId || '').trim()
  if (!wid) return
  const list = chatStore.messages.value || []
  const closeOnce = (matchTaskId) => {
    let wroteContent = false
    let matched = false
    for (let i = list.length - 1; i >= 0; i--) {
      const m = list[i]
      const c = m?.styleTransferCard
      if (!c || typeof c !== 'object') continue
      if (String(c.type || '').trim() !== 'style_transfer') continue
      if (String(c.workId || '').trim() !== wid) continue
      if (matchTaskId && tid && String(c.taskId || '').trim() !== tid) continue
      matched = true
      if (!wroteContent) {
        const nextContent = String(decidedText || '').trim() || String(m?.content || '').trim()
        chatStore.updateMessage(m.id, { content: nextContent, styleTransferCard: null })
        wroteContent = true
      } else {
        chatStore.updateMessage(m.id, { styleTransferCard: null })
      }
    }
    return matched
  }
  const found = closeOnce(true)
  if (!found) closeOnce(false)
  if (String(activeWorkId.value || '').trim() === wid && sidebarExpanded.value && activeSidebarPanel.value === 'neutral') {
    sidebarExpanded.value = false
  }
}

const applyStyleFromCard = async (msg) => {
  const card = msg?.styleTransferCard || null
  if (!card || card.decided) return
  if (applyStyleSubmitting.value) return
  if (String(card.loadingAction || '').trim()) return
  const key = String(card.selectedKey || '').trim()
  if (!key) {
    ElMessage.warning('请选择 Style Profile')
    return
  }
  const parts = key.split('::')
  const styleId = String(parts[0] || '').trim()
  const styleProfileId = String(parts[1] || '').trim()
  if (!styleId || !styleProfileId) {
    ElMessage.warning('请选择 Style Profile')
    return
  }

  card.loadingAction = 'apply'
  try {
    const okSel = await ensureActiveWorkFromStyleTransferCard(card)
    if (!okSel) return
    applyStyleForm.value.style_id = styleId
    applyStyleForm.value.style_profile_id = styleProfileId
    const ok = await confirmApplyStyle()
    if (ok) {
      card.decided = true
      const picked = Array.isArray(card.choices) ? card.choices.find(x => String(x?.style_id || '').trim() === styleId && String(x?.style_profile_id || '').trim() === styleProfileId) : null
      const labelRaw = picked?.label ? String(picked.label) : ''
      const idx = labelRaw.indexOf(' / #')
      const nameFromLabel = idx >= 0 ? labelRaw.slice(0, idx).trim() : (labelRaw ? labelRaw.split('/')[0].trim() : '')
      const styleName = nameFromLabel || resolveStyleNameById(styleId) || styleId
      card.decidedText = `已选择：${styleName} / #${styleProfileId}`
      chatStore.updateMessage(msg.id, { content: card.decidedText, styleTransferCard: null })
      closeStyleTransferCardForWork(card.workId, card.taskId, card.decidedText)
      msg.styleTransferCard = null
    }
  } finally {
    card.loadingAction = ''
  }
}

const skipStyleFromCard = async (msg) => {
  const card = msg?.styleTransferCard || null
  if (!card || card.decided) return
  if (applyStyleSubmitting.value) return
  if (String(card.loadingAction || '').trim()) return
  card.loadingAction = 'skip'
  try {
    const okSel = await ensureActiveWorkFromStyleTransferCard(card)
    if (!okSel) return
    const ok = await confirmSkipStyleTransfer()
    if (ok) {
      card.decided = true
      card.decidedText = '已选择：直接使用中性稿'
      chatStore.updateMessage(msg.id, { content: card.decidedText, styleTransferCard: null })
      closeStyleTransferCardForWork(card.workId, card.taskId, card.decidedText)
      msg.styleTransferCard = null
    }
  } finally {
    card.loadingAction = ''
  }
}

const openApplyStyleDialogFromCard = async (msg) => {
  const card = msg?.styleTransferCard || null
  if (!card) return
  const okSel = await ensureActiveWorkFromStyleTransferCard(card)
  if (!okSel) return
  await openApplyStyleDialog()
}

const cancelActiveWorkTask = async () => {
  const w = activeWork.value
  if (!w?.task_id) return
  try {
    const res = await request.post('/api/v1/writing/cancel', { task_id: w.task_id })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '取消失败')
      return
    }
    ElMessage.success('已取消')
    const data = res.data.data || null
    if (data) {
      recordWorkTaskEvent(w.id, data)
      const idx = works.value.findIndex(x => String(x.id) === String(w.id))
      if (idx >= 0) {
        works.value[idx] = {
          ...works.value[idx],
          task_status: data,
          generating: isWorkTaskRunning({ task_status: data }),
          updatedAtText: '刚刚'
        }
      }
    }
  } catch {
    ElMessage.error('取消失败')
  }
}

const regenerateActiveWork = async () => {
  const w = activeWork.value
  if (!w) {
    openCreateWorkDialog()
    return
  }
  if (w.style_id && !w.style_profile_id) {
    ElMessage.warning('请先选择 Style Profile')
    return
  }
  createWorkForm.value = {
    topic: w.topic || w.name || '',
    word_count: (Number(w.word_count) > 0 ? String(w.word_count) : ''),
    style_id: w.style_id || '',
    style_profile_id: w.style_profile_id || ''
  }
  await confirmCreateWork()
}

const styleArticlesByStyle = ref({})

const currentStyleName = computed(() => {
  const matched = styleOptions.value.find(s => s.style_id === (generationStore.style || 'default'))
  return matched ? matched.name : '默认'
})

const currentStyleArticles = computed(() => {
  const key = generationStore.style || 'default'
  return styleArticlesByStyle.value[key] || styleArticlesByStyle.value.default || []
})

const styleProfileGenerating = ref(false)
const styleProfileTask = ref(null)
const styleProfileTaskId = ref('')
const styleProfilePollTimer = ref(null)
const latestStyleProfile = ref(null)
const styleProfileDialogVisible = ref(false)
const styleProfileJsonText = ref('')
const styleProfileTab = ref('visual')
const styleProfileTreeData = ref([])

const buildStyleProfileTree = (data) => {
  if (!data) return []
  const seen = new WeakSet()
  const maxDepth = 10

  const toLabel = (k, v) => {
    if (v === null) return `${k}: null`
    if (v === undefined) return `${k}: undefined`
    const t = typeof v
    if (t === 'string') {
      const s = v.length > 160 ? v.slice(0, 160) + '…' : v
      return `${k}: "${s}"`
    }
    if (t === 'number' || t === 'boolean') return `${k}: ${String(v)}`
    if (Array.isArray(v)) return `${k} [${v.length}]`
    if (t === 'object') return `${k}`
    return `${k}: ${String(v)}`
  }

  const buildNode = (key, value, path, depth) => {
    const id = path.join('.')
    if (value && typeof value === 'object') {
      if (seen.has(value)) {
        return { id, label: `${String(key)}: [Circular]`, children: [] }
      }
      seen.add(value)
    }

    if (depth >= maxDepth) {
      return { id, label: `${String(key)}: [MaxDepth]`, children: [] }
    }

    if (Array.isArray(value)) {
      const children = value.map((v, idx) => buildNode(`[${idx}]`, v, [...path, String(idx)], depth + 1))
      return { id, label: toLabel(String(key), value), children }
    }

    if (value && typeof value === 'object') {
      const keys = Object.keys(value)
      const children = keys.map((k) => buildNode(k, value[k], [...path, k], depth + 1))
      return { id, label: toLabel(String(key), value), children }
    }

    return { id, label: toLabel(String(key), value), children: [] }
  }

  return [buildNode('StyleProfile', data, ['StyleProfile'], 0)]
}

const styleProfileTaskLabel = computed(() => {
  const st = styleProfileTask.value?.status
  if (st === 'queued') return '队列中'
  if (st === 'processing') return '分析中'
  if (st === 'success') return '已完成'
  if (st === 'failed') return '失败'
  return st ? String(st) : ''
})

const styleProfileTaskPhaseLabel = computed(() => {
  const phase = styleProfileTask.value?.phase || styleProfileTask.value?.stage
  if (!phase) return ''
  if (phase === 'queued') return '等待执行'
  if (phase === 'loading_corpus') return '收集语料'
  if (phase === 'analyzing') return '风格分析'
  if (phase === 'saving') return '保存结果'
  if (phase === 'done') return '完成'
  if (phase === 'exception') return '异常'
  return String(phase)
})

const styleProfileTaskProgress = computed(() => {
  const raw = styleProfileTask.value?.progress
  const parsed = Number(raw)
  if (Number.isFinite(parsed)) {
    const n = Math.round(parsed)
    return Math.max(0, Math.min(100, n))
  }
  const phase = styleProfileTask.value?.phase || styleProfileTask.value?.stage
  if (phase === 'queued') return 0
  if (phase === 'loading_corpus') return 10
  if (phase === 'analyzing') return 60
  if (phase === 'saving') return 90
  if (phase === 'done') return 100
  const st = styleProfileTask.value?.status
  if (st === 'success') return 100
  if (st === 'failed') return 0
  return 0
})

const fetchLatestStyleProfile = async (styleId) => {
  const key = styleId || 'default'
  try {
    const res = await request.get('/api/v1/style_profile/latest', { params: { style_id: key } })
    if (res.data.code !== 200) return
    const data = res.data.data
    if (!data || !data.profile_json) {
      latestStyleProfile.value = null
      return
    }
    let parsed = null
    try {
      parsed = JSON.parse(data.profile_json)
    } catch {
      parsed = null
    }
    latestStyleProfile.value = {
      ...data,
      profile: parsed
    }
  } catch {
    return
  }
}

const stopStyleProfilePolling = () => {
  if (styleProfilePollTimer.value) {
    clearInterval(styleProfilePollTimer.value)
    styleProfilePollTimer.value = null
  }
}

const pollStyleProfileTask = (taskId) => {
  stopStyleProfilePolling()
  if (!taskId) return
  let errCount = 0
  const fetchOnce = async () => {
    try {
      const res = await request.get('/api/v1/style_profile/task', { params: { task_id: taskId } })
      if (res.data.code !== 200) return
      styleProfileTask.value = res.data.data
      const st = res.data.data?.status
      if (st === 'success' || st === 'failed') {
        stopStyleProfilePolling()
        styleProfileGenerating.value = false
        await fetchLatestStyleProfile(generationStore.style || 'default')
      }
    } catch {
      errCount += 1
      if (errCount >= 5) {
        stopStyleProfilePolling()
        styleProfileGenerating.value = false
      }
    }
  }
  fetchOnce()
  styleProfilePollTimer.value = setInterval(fetchOnce, 800)
}

const startStyleProfileAnalysis = async () => {
  const key = generationStore.style || 'default'
  if (styleProfileGenerating.value) return
  const usable = currentStyleArticles.value.filter(x => x.type === 'note' || x.type === 'link')
  if (usable.length < 1) {
    ElMessage.warning('请先添加笔记或链接作为语料')
    return
  }
  styleProfileGenerating.value = true
  styleProfileTask.value = { status: 'queued', stage: 'queued', phase: 'queued', progress: '0', style_id: key }
  try {
    const res = await request.post('/api/v1/style_profile/generate', { style_id: key })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '创建分析任务失败')
      styleProfileGenerating.value = false
      return
    }
    styleProfileTaskId.value = res.data.data?.task_id || ''
    pollStyleProfileTask(styleProfileTaskId.value)
  } catch {
    ElMessage.error('创建分析任务失败')
    styleProfileGenerating.value = false
  }
}

const openStyleProfile = () => {
  if (!latestStyleProfile.value?.profile_json) return
  styleProfileTab.value = 'visual'
  const raw = String(latestStyleProfile.value.profile_json || '')
  let parsed = latestStyleProfile.value?.profile
  if (!parsed) {
    try {
      parsed = JSON.parse(raw)
    } catch {
      parsed = null
    }
  }
  styleProfileTreeData.value = buildStyleProfileTree(parsed)
  if (parsed) {
    try {
      styleProfileJsonText.value = JSON.stringify(parsed, null, 2)
    } catch {
      styleProfileJsonText.value = raw
    }
  } else {
    styleProfileJsonText.value = raw
  }
  styleProfileDialogVisible.value = true
}

const downloadStyleProfile = () => {
  const text = latestStyleProfile.value?.profile_json
  if (!text) return
  const blob = new Blob([text], { type: 'application/json;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `style-profile-${generationStore.style || 'default'}.json`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 0)
}

const activeArticleId = ref('')
const resourceViewMode = ref('list')
const resourceEditorLoading = ref(false)
const resourceEditorLoadingText = ref('加载中...')
const resourceSaving = ref(false)
const resourceDirty = ref(false)
const resourceEditorTitle = ref('')
const resourceEditorContent = ref('')
const resourceEditorId = ref('')
const suppressDirty = ref(false)

const resourceEditorRef = ref(null)
const resourceEditorFocused = ref(false)

const resourceEditorCharCount = computed(() => {
  return countChars(markdownToPlainText(resourceEditorContent.value))
})

const resourceSaveStatusText = computed(() => {
  if (resourceSaving.value) return '保存中...'
  return resourceDirty.value ? '未保存' : '已保存'
})

const resourceEditorFooterText = computed(() => {
  return `字数：${resourceEditorCharCount.value} · ${resourceSaveStatusText.value}`
})

const serializeDomToMarkdown = (node) => {
  if (!node) return ''
  if (node.nodeType === Node.TEXT_NODE) return node.nodeValue || ''
  if (node.nodeType !== Node.ELEMENT_NODE) return ''

  const el = node

  if (el.classList?.contains('resource-image-chip')) {
    const url = String(el.dataset?.url || '')
    const alt = String(el.dataset?.alt || '')
    if (!url) return ''
    return `![${alt}](${url})`
  }

  if (el.classList?.contains('resource-image-chip-remove')) return ''

  const tag = (el.tagName || '').toUpperCase()
  if (tag === 'BR') return '\n'
  if (tag === 'IMG') {
    const url = String(el.getAttribute('src') || '')
    const alt = String(el.getAttribute('alt') || '')
    if (!url) return ''
    return `![${alt}](${url})`
  }

  let out = ''
  el.childNodes?.forEach?.((child) => {
    out += serializeDomToMarkdown(child)
  })

  const isBlock = tag === 'DIV' || tag === 'P' || tag === 'LI' || tag === 'BLOCKQUOTE' || /^H[1-6]$/.test(tag)
  if (isBlock && out && !out.endsWith('\n')) out += '\n'
  return out
}

const normalizeIncomingContentToMarkdown = (raw) => {
  const s = String(raw ?? '')
  if (!s.trim()) return ''
  if (/!\[[^\]]*]\([^)\s]+[^)]*\)/.test(s)) return s
  if (/<[a-z][\s\S]*>/i.test(s)) {
    const container = document.createElement('div')
    container.innerHTML = s
    let out = ''
    container.childNodes.forEach(n => {
      out += serializeDomToMarkdown(n)
    })
    return out.replace(/\n{3,}/g, '\n\n').trimEnd()
  }
  return s
}

const createImageChip = (url, alt) => {
  const chip = document.createElement('span')
  chip.className = 'resource-image-chip'
  chip.contentEditable = 'false'
  chip.dataset.url = url
  chip.dataset.alt = alt || ''

  const img = document.createElement('img')
  img.src = url
  img.alt = alt || ''
  img.draggable = false

  const remove = document.createElement('span')
  remove.className = 'resource-image-chip-remove'
  remove.textContent = '×'

  chip.appendChild(img)
  chip.appendChild(remove)
  return chip
}

const renderMarkdownToEditor = (markdown) => {
  const el = resourceEditorRef.value
  if (!el) return

  const content = String(markdown ?? '')
  el.innerHTML = ''
  if (!content) return

  const frag = document.createDocumentFragment()
  const re = /!\[([^\]]*)\]\(([^)\s]+[^)]*?)\)/g
  let lastIndex = 0
  let match = null

  while ((match = re.exec(content)) !== null) {
    const before = content.slice(lastIndex, match.index)
    if (before) frag.appendChild(document.createTextNode(before))
    const alt = match[1] || ''
    const url = match[2] || ''
    if (url) frag.appendChild(createImageChip(url, alt))
    lastIndex = match.index + match[0].length
  }

  const after = content.slice(lastIndex)
  if (after) frag.appendChild(document.createTextNode(after))

  el.appendChild(frag)
}

const setResourceEditorMarkdown = (raw) => {
  const markdown = normalizeIncomingContentToMarkdown(raw)
  resourceEditorContent.value = markdown
  renderResourceEditorFromState()
}

async function renderResourceEditorFromState () {
  if (resourceEditorFocused.value) return
  await nextTick()
  if (resourceEditorFocused.value) return
  if (resourceViewMode.value !== 'editor') return
  if (resourceEditorLoading.value) return
  if (!resourceEditorRef.value) return
  renderMarkdownToEditor(resourceEditorContent.value)
}

const onResourceEditorInput = () => {
  const el = resourceEditorRef.value
  if (!el) {
    resourceEditorContent.value = ''
    return
  }
  let out = ''
  el.childNodes.forEach(n => {
    out += serializeDomToMarkdown(n)
  })
  out = out
    .replace(/\r\n|\r/g, '\n')
    .replace(/\u00A0/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trimEnd()
  resourceEditorContent.value = out
}

const onResourceEditorPaste = (e) => {
  const text = e?.clipboardData?.getData?.('text/plain')
  if (typeof text !== 'string') return
  e.preventDefault()
  try {
    document.execCommand('insertText', false, text)
  } catch {
    const el = resourceEditorRef.value
    if (!el) return
    const sel = window.getSelection()
    if (!sel || sel.rangeCount === 0) return
    sel.deleteFromDocument()
    sel.getRangeAt(0).insertNode(document.createTextNode(text))
    sel.collapseToEnd()
  }
  onResourceEditorInput()
}

const onResourceEditorClick = (e) => {
  const target = e?.target
  if (!(target instanceof HTMLElement)) return
  if (!target.classList.contains('resource-image-chip-remove')) return
  const chip = target.closest('.resource-image-chip')
  if (!chip) return
  chip.remove()
  onResourceEditorInput()
}

const onResourceEditorFocus = () => {
  resourceEditorFocused.value = true
}

const onResourceEditorBlur = () => {
  resourceEditorFocused.value = false
  renderMarkdownToEditor(resourceEditorContent.value)
}

watch(
  [resourceViewMode, resourceEditorLoading, resourceEditorRef],
  () => {
    if (resourceViewMode.value !== 'editor') return
    if (resourceEditorLoading.value) return
    renderResourceEditorFromState()
  },
  { flush: 'post' }
)

const fetchStyleResources = async (styleId) => {
  const key = styleId || 'default'
  try {
    const res = await request.get('/api/v1/resources/list', { params: { style_id: key } })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '获取资料失败')
      return
    }
    const items = Array.isArray(res.data.data?.items) ? res.data.data.items : []
    const mapped = items
      .filter(x => String(x?.type || '') !== 'work')
      .map(x => ({
        id: x.resource_id,
        type: x.type,
        title: x.title,
        url: x.url
      }))
    styleArticlesByStyle.value = {
      ...styleArticlesByStyle.value,
      [key]: mapped
    }
    if (activeTab.value === 'styles' && resourceViewMode.value !== 'editor' && (generationStore.style || 'default') === key) {
      activeArticleId.value = mapped[0]?.id || ''
    }
  } catch {
    ElMessage.error('获取资料失败')
  }
}

watch(
  [activeTab, () => generationStore.style],
  async () => {
    if (activeTab.value !== 'styles') return
    resourceViewMode.value = 'list'
    styleProfileTask.value = null
    styleProfileTaskId.value = ''
    styleProfileGenerating.value = false
    stopStyleProfilePolling()
    await fetchStyleResources(generationStore.style || 'default')
    await fetchLatestStyleProfile(generationStore.style || 'default')
  },
  { immediate: true }
)

const resourceAddVisible = ref(false)
const linkDialogVisible = ref(false)
const linkSubmitting = ref(false)
const linkForm = ref({
  url: '',
  title: ''
})
const batchLinkDialogVisible = ref(false)
const batchLinkSubmitting = ref(false)
const batchLinkForm = ref({
  urls: '',
  titlePrefix: ''
})
const batchLinkProgress = ref({
  total: 0,
  done: 0,
  success: 0,
  failed: 0
})
const batchLinkResults = ref([])
const groupDialogVisible = ref(false)
const groupForm = ref({
  name: ''
})
const fileInputRef = ref(null)

const handleCreateNote = async () => {
  resourceAddVisible.value = false
  const key = generationStore.style || 'default'
  try {
    const res = await request.post('/api/v1/resources/note', {
      style_id: key,
      title: `New note（${currentStyleName.value}）`
    })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '新建笔记失败')
      return
    }
    await fetchStyleResources(key)
  } catch {
    ElMessage.error('新建笔记失败')
  }
}

const handleAddLink = () => {
  resourceAddVisible.value = false
  linkForm.value = { url: '', title: '' }
  linkDialogVisible.value = true
}

const resetBatchLinkState = () => {
  batchLinkForm.value = { urls: '', titlePrefix: '' }
  batchLinkProgress.value = { total: 0, done: 0, success: 0, failed: 0 }
  batchLinkResults.value = []
}

const handleBatchAddLink = () => {
  resourceAddVisible.value = false
  resetBatchLinkState()
  batchLinkDialogVisible.value = true
}

const handleAddFile = () => {
  resourceAddVisible.value = false
  fileInputRef.value?.click?.()
}

const handleFilePicked = async (e) => {
  const input = e?.target
  const file = input?.files?.[0]
  if (!file) return
  const key = generationStore.style || 'default'
  try {
    const formData = new FormData()
    formData.append('style_id', key)
    formData.append('file', file)
    const res = await request.post('/api/v1/resources/file', formData)
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '添加文件失败')
      return
    }
    await fetchStyleResources(key)
  } catch {
    ElMessage.error('添加文件失败')
  } finally {
    input.value = ''
  }
}

const handleCreateGroup = () => {
  resourceAddVisible.value = false
  groupForm.value = { name: '' }
  groupDialogVisible.value = true
}

const confirmCreateGroup = () => {
  const name = String(groupForm.value.name || '').trim()
  if (!name) {
    ElMessage.warning('请输入分组名称')
    return
  }
  const key = generationStore.style || 'default'
  request
    .post('/api/v1/resources/group', { style_id: key, title: name })
    .then(res => {
      if (res.data.code !== 200) {
        ElMessage.error(res.data.msg || '新建分组失败')
        return
      }
      groupDialogVisible.value = false
      return fetchStyleResources(key)
    })
    .catch(() => {
      ElMessage.error('新建分组失败')
    })
}

const normalizeUrl = (url) => {
  const trimmed = String(url || '').trim()
  if (!trimmed) return ''
  if (/^(https?:\/\/)/i.test(trimmed)) return trimmed
  return `https://${trimmed}`
}

const parseBatchUrls = (raw) => {
  const text = String(raw || '')
  if (!text.trim()) return []
  const lines = text.split(/\r\n|\r|\n/).map(s => s.trim()).filter(Boolean)
  const out = []
  const seen = new Set()
  for (const line of lines) {
    const normalized = normalizeUrl(line)
    if (!normalized) continue
    if (seen.has(normalized)) continue
    seen.add(normalized)
    out.push(normalized)
  }
  return out
}

const runWithConcurrency = async (items, limit, worker) => {
  const queue = [...items]
  const runners = Array.from({ length: Math.max(1, limit) }, async () => {
    while (queue.length) {
      const item = queue.shift()
      await worker(item)
    }
  })
  await Promise.all(runners)
}

const confirmAddLink = async () => {
  const url = normalizeUrl(linkForm.value.url)
  if (!url) {
    ElMessage.warning('请输入链接')
    return
  }
  const title = String(linkForm.value.title || '').trim()
  const key = generationStore.style || 'default'
  if (linkSubmitting.value) return

  linkSubmitting.value = true
  linkDialogVisible.value = false
  resourceViewMode.value = 'editor'
  resourceEditorLoadingText.value = '正在抓取链接内容...'
  resourceEditorLoading.value = true
  suppressDirty.value = true
  resourceEditorId.value = ''
  resourceEditorTitle.value = ''
  setResourceEditorMarkdown('')
  resourceDirty.value = false

  try {
    const res = await request.post('/api/v1/resources/link', { style_id: key, title, url })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '添加链接失败')
      exitResourceEditor()
      return
    }

    const item = res.data.data?.item
    const rid = item?.resource_id
    resourceEditorId.value = rid || ''
    resourceEditorTitle.value = item?.title || ''
    setResourceEditorMarkdown(item?.content || '')
    resourceDirty.value = false
    if (rid) activeArticleId.value = rid
    await fetchStyleResources(key)
  } catch {
    ElMessage.error('添加链接失败')
    exitResourceEditor()
  } finally {
    resourceEditorLoading.value = false
    suppressDirty.value = false
    linkSubmitting.value = false
  }
}

const confirmBatchAddLink = async () => {
  const key = generationStore.style || 'default'
  if (batchLinkSubmitting.value) return

  const urls = parseBatchUrls(batchLinkForm.value.urls)
  if (urls.length === 0) {
    ElMessage.warning('请输入链接列表')
    return
  }

  const prefix = String(batchLinkForm.value.titlePrefix || '').trim()
  batchLinkSubmitting.value = true
  batchLinkProgress.value = { total: urls.length, done: 0, success: 0, failed: 0 }
  batchLinkResults.value = urls.map((u, i) => ({
    key: `${i}-${u}`,
    url: u,
    status: 'pending',
    statusText: '等待中'
  }))

  const updateRow = (url, patch) => {
    const idx = batchLinkResults.value.findIndex(x => x.url === url)
    if (idx < 0) return
    batchLinkResults.value[idx] = { ...batchLinkResults.value[idx], ...patch }
  }

  try {
    await runWithConcurrency(urls, 3, async (url) => {
      updateRow(url, { status: 'running', statusText: '抓取中...' })
      try {
        const title = prefix ? `${prefix}${batchLinkProgress.value.done + 1}` : ''
        const res = await request.post('/api/v1/resources/link', { style_id: key, title, url })
        if (res.data.code !== 200) {
          batchLinkProgress.value.failed += 1
          updateRow(url, { status: 'failed', statusText: res.data.msg || '失败' })
        } else {
          batchLinkProgress.value.success += 1
          updateRow(url, { status: 'success', statusText: '成功' })
        }
      } catch (e) {
        batchLinkProgress.value.failed += 1
        updateRow(url, { status: 'failed', statusText: e?.message ? String(e.message) : '失败' })
      } finally {
        batchLinkProgress.value.done += 1
      }
    })

    await fetchStyleResources(key)
    ElMessage.success('批量抓取完成')
  } finally {
    batchLinkSubmitting.value = false
  }
}

const getResourceIcon = (a) => {
  const t = a?.type || 'note'
  if (t === 'link') return Paperclip
  if (t === 'file') return Files
  if (t === 'group') return FolderAdd
  return Document
}

const loadResourceDetail = async (resourceId) => {
  if (!resourceId) return
  resourceEditorLoadingText.value = '加载中...'
  resourceEditorLoading.value = true
  suppressDirty.value = true
  try {
    const item = await fetchResourceDetailItem(resourceId, { force: true })
    if (!item) {
      ElMessage.error('获取资料失败')
      return
    }
    resourceEditorId.value = item?.resource_id || resourceId
    resourceEditorTitle.value = item?.title || ''
    setResourceEditorMarkdown(item?.content || '')
    resourceDirty.value = false
  } catch {
    ElMessage.error('获取资料失败')
  } finally {
    resourceEditorLoading.value = false
    suppressDirty.value = false
  }
}

const exitResourceEditor = () => {
  resourceViewMode.value = 'list'
  resourceEditorId.value = ''
  resourceEditorTitle.value = ''
  resourceEditorContent.value = ''
  const el = resourceEditorRef.value
  if (el) el.innerHTML = ''
  resourceDirty.value = false
}

const saveResource = async () => {
  const rid = resourceEditorId.value
  if (!rid) return
  resourceSaving.value = true
  try {
    const res = await request.post('/api/v1/resources/update', {
      resource_id: rid,
      title: resourceEditorTitle.value,
      content: resourceEditorContent.value
    })
    if (res.data.code !== 200) {
      ElMessage.error(res.data.msg || '保存失败')
      return
    }
    resourceDirty.value = false
    const styleKey = generationStore.style || 'default'
    await fetchStyleResources(styleKey)
    activeArticleId.value = rid
  } catch {
    ElMessage.error('保存失败')
  } finally {
    resourceSaving.value = false
  }
}

watch([resourceEditorTitle, resourceEditorContent], () => {
  if (resourceViewMode.value !== 'editor') return
  if (suppressDirty.value) return
  resourceDirty.value = true
})

const handleResourceClick = (a) => {
  if (!a) return
  activeArticleId.value = a.id
  if (a.type === 'file' && a.url) {
    window.open(a.url, '_blank')
    return
  }
  resourceViewMode.value = 'editor'
  loadResourceDetail(a.id)
}

let isDraggingSidebar = false

const startResize = () => {
  isDraggingSidebar = true
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
}

const handleGlobalMouseMove = (e) => {
  if (!isDraggingSidebar) return
  const container = document.querySelector('.graphic-creation-container')
  if (!container) return

  const rect = container.getBoundingClientRect()
  const w = rect.right - e.clientX
  const maxW = window.innerWidth / 2

  if (w >= 300 && w <= maxW) sidebarWidth.value = w
}

const handleGlobalMouseUp = () => {
  if (!isDraggingSidebar) return
  isDraggingSidebar = false
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
}

const handleClickOutside = (event) => {
  if (!sidebarExpanded.value) return
  if (!(event?.target instanceof Node)) return

  const workEditorEl = workEditorRef.value
  if (workEditorEl && workEditorEl.contains(event.target)) return

  const resourceEditorEl = resourceEditorRef.value
  if (resourceEditorEl && resourceEditorEl.contains(event.target)) return

  if (workImageSidebarRef.value && !workImageSidebarRef.value.contains(event.target)) {
    sidebarExpanded.value = false
  }
}

const handleBeforeUnload = () => {
  const wid = String(activeWorkId.value || '').trim()
  if (wid) {
    persistWorkChatNow(wid)
    persistWorkDraftNow(wid)
  }
}

onMounted(() => {
  window.addEventListener('click', handleClickOutside)
  window.addEventListener('mousemove', handleGlobalMouseMove)
  window.addEventListener('mouseup', handleGlobalMouseUp)
  window.addEventListener('resize', updateHalfSidebarWidth)
  window.addEventListener('beforeunload', handleBeforeUnload)
  wsManager.on('writing_task_update', handleWritingTaskUpdate)
  fetchWorksList()
  fetchStyleOptions()
})

watch(
  () => [
    activeTab.value,
    String(activeWorkId.value || '').trim(),
    works.value.length,
    String(activeWork.value?.task_id || '').trim(),
    String(activeWork.value?.task_status?.status || '').trim()
  ],
  async () => {
    if (activeTab.value !== 'works') return
    const w = activeWork.value
    if (!w?.id || !w?.task_id) return
    const status = String(w?.task_status?.status || '').trim()
    if (status !== 'WAIT_STYLE_TRANSFER') return

    const wid = String(w.id).trim()
    const tid = String(w.task_id || '').trim()
    if (!tid) return

    const restored = restoreStyleTransferCardFromPendingChoice(wid, tid)
    if (restored) {
      const ts = w?.task_status || { status: 'WAIT_STYLE_TRANSFER', stage: 'WAIT_STYLE_TRANSFER', progress: 75 }
      updateWorkChatStatus(wid, ts, { force: true })
      return
    }
    if (!findLatestUndecidedStyleTransferCardByWorkId(wid)) {
      void ensureWaitStyleTransferUI(wid, tid)
    } else {
      updateWorkChatStatus(wid, w.task_status, { force: true })
    }
  },
  { immediate: true }
)

watch([activeTab, activeWorkId], () => {
  if (activeTab.value !== 'works') {
    return
  }
  loadWorkDetail(activeWorkId.value)
  renderWorkEditorFromState()
  const w = activeWork.value
  if (!w?.task_id) {
    return
  }
  const panel = activeSidebarPanel.value
  if (panel === 'images') {
    void fetchWorkArtifactCached(w.id, w.task_id, 'fetched_sources')
  } else if (sidebarExpanded.value && panel === 'neutral') {
    void loadWorkArtifacts('neutral_draft')
  } else if (sidebarExpanded.value && panel === 'references') {
    void loadWorkArtifacts('references')
  }
}, { immediate: true })

watch(
  () => activeWork.value?.content,
  () => {
    if (activeTab.value !== 'works') return
    const el = workEditorRef.value
    if (workEditorFocused.value && el) {
      const currentHtml = String(el.innerHTML || '').trim()
      if (currentHtml) return
    }
    renderWorkEditorFromState()
  },
  { flush: 'post' }
)

onUnmounted(() => {
  window.removeEventListener('click', handleClickOutside)
  window.removeEventListener('mousemove', handleGlobalMouseMove)
  window.removeEventListener('mouseup', handleGlobalMouseUp)
  window.removeEventListener('resize', updateHalfSidebarWidth)
  window.removeEventListener('beforeunload', handleBeforeUnload)
  const wid = String(activeWorkId.value || '').trim()
  if (wid) {
    persistWorkChatNow(wid)
    persistWorkDraftNow(wid)
  }
  if (persistWorkChatTimer) {
    clearTimeout(persistWorkChatTimer)
    persistWorkChatTimer = null
  }
  if (persistWorkDraftTimer) {
    clearTimeout(persistWorkDraftTimer)
    persistWorkDraftTimer = null
  }
  wsManager.off('writing_task_update', handleWritingTaskUpdate)
  stopStyleProfilePolling()
})
</script>

<style scoped lang="scss">
.graphic-creation-container {
  display: flex;
  height: 100%;
  background-color: #f7f7f7; /* Light gray background */
  color: #333;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
  width: 100%;
  overflow: hidden;
  position: relative;
  padding: 0; /* Remove padding to attach panels to edges */
  box-sizing: border-box;
}

.feature-disabled {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  color: #909399;
}

.panel-gap {
  width: 10px;
  background: transparent;
  flex-shrink: 0;
}

.resize-handle {
  width: 10px;
  cursor: col-resize;
  background: transparent;
  flex-shrink: 0;
}

/* Left Panel */
.left-panel {
  width: 260px;
  background-color: #fff;
  border-radius: 0 16px 16px 0; /* Rounded corners on right side only */
  display: flex;
  flex-direction: column;
  padding: 16px 10px 16px 16px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  height: 100%;
  overflow: hidden;
}

.panel-header {
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.tabs {
  display: flex;
  gap: 20px;
}

.tab {
  cursor: pointer;
  font-size: 14px;
  color: #666;
  padding-bottom: 4px;
}

.tab.active {
  color: #000;
  font-weight: 500;
  border-bottom: 2px solid #000;
}

.filter-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  font-size: 13px;
  color: #666;
}

.file-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior-y: contain;
}

.file-list,
.references-panel,
.neutral-draft-panel,
.work-image-list,
.editor-content-wrapper,
.reference-content-inner {
  scrollbar-width: thin;
  scrollbar-color: rgba(0, 0, 0, 0.16) transparent;
}

.file-list::-webkit-scrollbar,
.references-panel::-webkit-scrollbar,
.neutral-draft-panel::-webkit-scrollbar,
.work-image-list::-webkit-scrollbar,
.editor-content-wrapper::-webkit-scrollbar,
.reference-content-inner::-webkit-scrollbar {
  width: 6px;
}

.file-list::-webkit-scrollbar-track,
.references-panel::-webkit-scrollbar-track,
.neutral-draft-panel::-webkit-scrollbar-track,
.work-image-list::-webkit-scrollbar-track,
.editor-content-wrapper::-webkit-scrollbar-track,
.reference-content-inner::-webkit-scrollbar-track {
  background: transparent;
}

.file-list::-webkit-scrollbar-thumb,
.references-panel::-webkit-scrollbar-thumb,
.neutral-draft-panel::-webkit-scrollbar-thumb,
.work-image-list::-webkit-scrollbar-thumb,
.editor-content-wrapper::-webkit-scrollbar-thumb,
.reference-content-inner::-webkit-scrollbar-thumb {
  background-color: rgba(0, 0, 0, 0.12);
  border-radius: 999px;
}

.file-list::-webkit-scrollbar-thumb:hover,
.references-panel::-webkit-scrollbar-thumb:hover,
.neutral-draft-panel::-webkit-scrollbar-thumb:hover,
.work-image-list::-webkit-scrollbar-thumb:hover,
.editor-content-wrapper::-webkit-scrollbar-thumb:hover,
.reference-content-inner::-webkit-scrollbar-thumb:hover {
  background-color: rgba(0, 0, 0, 0.20);
}

.file-item {
  display: flex;
  align-items: center;
  padding: 10px;
  border-radius: 8px;
  cursor: pointer;
  transition: background-color 0.2s;
}

.file-item:hover {
  background-color: #f5f5f5;
}

.file-item.active {
  background-color: #f0f0f0;
}

.file-icon {
  margin-right: 12px;
  color: #666;
  font-size: 20px;
  display: flex;
  align-items: center;
}

.file-info {
  flex: 1;
  min-width: 0;
}

.file-name {
  font-size: 14px;
  font-weight: 500;
  margin-bottom: 2px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}

.file-meta {
  font-size: 12px;
  color: #999;
}

/* Main Editor */
.main-editor {
  flex: 1;
  display: flex;
  flex-direction: column;
  background-color: #fff;
  position: relative;
  border-radius: 16px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  overflow: hidden; /* Ensure content doesn't overflow corners */
}

.editor-header {
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  border-bottom: 1px solid transparent; /* Hidden border initially */
}

.header-left, .header-right {
  display: flex;
  align-items: center;
  gap: 16px;
  color: #666;
  font-size: 18px;
  cursor: pointer;
}

.header-action {
  color: #111;
}

.header-center {
  font-size: 14px;
  color: #999;
}

.editor-content-wrapper {
  flex: 1;
  overflow-y: auto;
  overflow-x: visible;
  padding: 40px 80px;
  position: relative;
}

.editor-content-wrapper.is-style {
  padding: 16px 22px;
}

.style-articles {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.style-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 4px;
}

.style-task-status {
  margin-left: auto;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.style-task-progress {
  width: 240px;
  margin-top: 4px;
}

.style-task-line {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: #606266;
}

.style-task-label {
  color: #909399;
}

.style-task-value {
  color: #303133;
  font-weight: 500;
}

.style-profile-dialog-body {
  padding: 8px 0;
}

.style-profile-tabs {
  width: 100%;
}

.style-profile-visual {
  height: 520px;
  overflow: auto;
  padding: 6px 2px;
}

.style-profile-tree {
  width: 100%;
}

.articles-list {
  margin-top: 0;
}

.article-row {
  display: flex;
  gap: 12px;
  padding: 12px 8px;
  cursor: pointer;
  border-radius: 10px;
}

.article-row:hover {
  background: #fafafa;
}

.article-row.active {
  background: #f5f5f5;
}

.article-left {
  width: 28px;
  flex-shrink: 0;
  display: flex;
  justify-content: center;
  padding-top: 2px;
}

.article-icon {
  color: #606266;
  font-size: 16px;
}

.article-body {
  flex: 1;
  min-width: 0;
}

.article-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.article-title {
  font-size: 14px;
  color: #303133;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.article-more {
  color: #909399;
  font-size: 16px;
  flex-shrink: 0;
}

.article-row.new .article-title {
  color: #606266;
}

:deep(.resource-add-popper) {
  padding: 10px 8px;
  border: none;
  border-radius: 18px;
  box-shadow: 0 18px 40px rgba(0,0,0,0.14);
}

:deep(.resource-add-popper .el-popper__arrow) {
  display: none;
}

:deep(.resource-add-popper .resource-add-menu) {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

:deep(.resource-add-popper .resource-add-item) {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 14px;
  cursor: pointer;
  user-select: none;
}

:deep(.resource-add-popper .resource-add-item:hover) {
  background: #f3f4f6;
}

:deep(.resource-add-popper .resource-add-icon) {
  font-size: 20px;
  color: #111;
}

:deep(.resource-add-popper .resource-add-text) {
  font-size: 16px;
  font-weight: 500;
  color: #111;
  line-height: 1.2;
}

.hidden-file-input {
  display: none;
}

.editor-content {
  max-width: 1120px;
  margin: 0 auto;
  padding-bottom: 100px;
}

.doc-title {
  font-size: 32px;
  color: #ccc; /* Placeholder style */
  margin-bottom: 30px;
  font-weight: 600;
}

.resource-loading {
  padding: 24px 8px;
  color: #999;
  font-size: 14px;
}

.resource-title-input {
  margin-bottom: 18px;
}

.work-meta-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.work-meta-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.work-meta-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.work-regenerate-btn,
.work-regenerate-icon-btn {
  background-color: #111827;
  border-color: transparent;
  color: #fff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  border: none;
}

.work-regenerate-btn:hover,
.work-regenerate-btn:focus,
.work-regenerate-icon-btn:hover,
.work-regenerate-icon-btn:focus {
  background-color: #000;
  border-color: transparent;
  color: #fff;
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.work-regenerate-btn:active,
.work-regenerate-icon-btn:active {
  background-color: #000;
  border-color: transparent;
  color: #fff;
  transform: translateY(0);
}

.work-regenerate-btn.is-disabled,
.work-regenerate-btn.is-disabled:hover,
.work-regenerate-btn.is-disabled:focus,
.work-regenerate-icon-btn.is-disabled,
.work-regenerate-icon-btn.is-disabled:hover,
.work-regenerate-icon-btn.is-disabled:focus {
  background-color: #f3f4f6;
  border-color: transparent;
  color: #9ca3af;
  box-shadow: none;
  transform: none;
  cursor: not-allowed;
}

@media (prefers-color-scheme: dark) {
  .work-regenerate-btn,
  .work-regenerate-icon-btn {
    background-color: #f9fafb;
    color: #111827;
  }
  
  .work-regenerate-btn:hover,
  .work-regenerate-btn:focus,
  .work-regenerate-icon-btn:hover,
  .work-regenerate-icon-btn:focus {
    background-color: #fff;
    box-shadow: 0 0 12px rgba(255, 255, 255, 0.15);
  }
  
  .work-regenerate-btn.is-disabled,
  .work-regenerate-btn.is-disabled:hover,
  .work-regenerate-btn.is-disabled:focus,
  .work-regenerate-icon-btn.is-disabled,
  .work-regenerate-icon-btn.is-disabled:hover,
  .work-regenerate-icon-btn.is-disabled:focus {
    background-color: #374151;
    color: #6b7280;
  }
}

.work-editor-layout {
  display: flex;
  align-items: stretch;
  gap: 14px;
}

.work-image-sidebar-wrapper {
  position: sticky;
  top: 0;
  left: 0;
  z-index: 100;
  height: 0;
  width: 0;
}

.work-image-sidebar {
  width: 220px;
  position: absolute;
  top: 0;
  left: -80px;
  height: calc(100vh - 140px);
  background: #fff;
  border: 1px solid #eef0f3;
  border-left: none;
  border-radius: 0 16px 16px 0;
  box-shadow: 4px 0 20px rgba(0, 0, 0, 0.08);
  transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.work-image-sidebar.panel-neutral {
  width: 480px;
}

.work-image-sidebar.panel-references {
  width: 360px;
}

.work-image-sidebar.is-collapsed {
  width: 48px;
  box-shadow: none;
}

.work-image-sidebar-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}

.work-image-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid #f2f4f7;
  flex-shrink: 0;
  height: 54px;
}

.sidebar-panel-container {
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.neutral-draft-panel {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  padding-bottom: 36px;
  background: #f5f7fa;
  margin: 12px;
  border-radius: 8px;
  box-sizing: border-box;
  overscroll-behavior-y: contain;
  position: relative;
}

.neutral-draft-content {
  font-size: 14px;
  line-height: 1.6;
  color: #333;
  white-space: pre-wrap;
  word-break: break-word;
}

.neutral-draft-count {
  position: absolute;
  right: 12px;
  bottom: 10px;
  font-size: 12px;
  color: #9aa0a6;
  user-select: none;
}

.neutral-draft-reveal-enter-active,
.neutral-draft-reveal-leave-active {
  transition: opacity 0.22s ease;
}

.neutral-draft-reveal-enter-from,
.neutral-draft-reveal-leave-to {
  opacity: 0;
}

.neutral-draft-reveal-enter-to,
.neutral-draft-reveal-leave-from {
  opacity: 1;
}

.references-panel {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  border-radius: 8px;
  box-sizing: border-box;
  overscroll-behavior-y: contain;
}

.references-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.reference-card {
  border: 1px solid #eef0f3;
  border-radius: 8px;
  background: #f5f7fa;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  transition: box-shadow 0.2s;
  cursor: pointer;
}

.reference-card:hover {
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.reference-card.is-expanded {
  border-color: #d9ecff;
}

.reference-main {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.reference-title {
  font-size: 14px;
  font-weight: 500;
  color: #111;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.reference-host {
  font-size: 12px;
  color: #909399;
}

.reference-content-wrap {
  overflow: hidden;
  max-height: 260px;
}

.reference-content-inner {
  font-size: 13px;
  line-height: 1.6;
  color: #303133;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 260px;
  overflow-y: auto;
  overscroll-behavior-y: contain;
}

.reference-expand-enter-active,
.reference-expand-leave-active {
  transition: max-height 0.22s ease, opacity 0.22s ease;
  will-change: max-height, opacity;
}

.reference-expand-enter-from,
.reference-expand-leave-to {
  max-height: 0;
  opacity: 0;
}

.reference-expand-enter-to,
.reference-expand-leave-from {
  max-height: 260px;
  opacity: 1;
}

.sidebar-collapsed-icons {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 16px 0;
  gap: 16px;
  height: 100%;
}

.collapsed-icon-item {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  color: #606266;
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
  font-size: 18px;
}

.collapsed-icon-item:hover {
  background: #f5f7fa;
  color: #409eff;
}

.collapsed-icon-item.active {
  background: #ecf5ff;
  color: #409eff;
}

.icon-badge {
  position: absolute;
  top: 6px;
  right: 6px;
  width: 6px;
  height: 6px;
  background: #f56c6c;
  border-radius: 50%;
}

.collapsed-toggle {
  margin-top: auto;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: #909399;
  border-radius: 8px;
  transition: all 0.2s;
}

.collapsed-toggle:hover {
  background: #f5f7fa;
  color: #303133;
}

.panel-loading {
  padding: 20px;
  text-align: center;
  color: #909399;
  font-size: 13px;
}

.panel-empty {
  padding: 40px 0;
  text-align: center;
  color: #909399;
  font-size: 13px;
}

.work-image-title {
  font-size: 14px;
  font-weight: 600;
  color: #111;
  white-space: nowrap;
}

.work-image-title-collapsed {
  font-size: 12px;
  font-weight: 600;
  color: #111;
  text-align: center;
  width: 100%;
}

.work-image-toggle {
  cursor: pointer;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  color: #666;
  transition: all 0.2s;
}

.work-image-toggle:hover {
  background: #f3f4f6;
  color: #111;
}

.work-image-toggle .is-rotated {
  transform: rotate(180deg);
}

.work-image-list {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  overscroll-behavior-y: contain;
}

.work-image-list-collapsed {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 16px 0;
  cursor: pointer;
  color: #606266;
  gap: 12px;
  transition: background-color 0.2s;
}

.work-image-list-collapsed:hover {
  background-color: #fafafa;
  color: #409eff;
}

.work-image-collapsed-icon {
  font-size: 20px;
}

.work-image-collapsed-count {
  font-size: 12px;
  font-weight: 500;
  background: #f0f2f5;
  padding: 2px 6px;
  border-radius: 10px;
  color: #909399;
}

.work-image-list-collapsed:hover .work-image-collapsed-count {
  background: #ecf5ff;
  color: #409eff;
}

.work-image-collapsed-toggle {
  margin-top: auto;
  font-size: 16px;
  color: #909399;
  padding-bottom: 8px;
}

.work-image-list-collapsed:hover .work-image-collapsed-toggle {
  color: #409eff;
}

.work-image-card {
  border-radius: 8px;
  overflow: hidden;
  border: 1px solid #eef0f3;
  background: #fff;
  flex-shrink: 0;
}

.work-image-thumb-box {
  width: 100%;
  position: relative;
  display: block;
  background: #fff;
  cursor: pointer;
  line-height: 0;
}

.work-image-thumb {
  width: 100%;
  height: auto;
  display: block;
  transition: transform 0.3s;
}

.work-image-thumb-box:hover .work-image-thumb {
  transform: scale(1.05);
}

.work-image-actions {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: block;
  padding: 8px;
  opacity: 0;
  transition: opacity 0.2s;
}

.work-image-thumb-box:hover .work-image-actions {
  opacity: 1;
}

.action-btn {
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.action-btn-insert {
  position: absolute;
  right: 8px;
  bottom: 8px;
}

.work-image-delete-icon {
  position: absolute;
  top: 8px;
  right: 8px;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  opacity: 0;
  transform: translateY(-2px);
  transition: opacity 0.2s, transform 0.2s, background-color 0.2s;
  z-index: 2;
}

.work-image-thumb-box:hover .work-image-delete-icon {
  opacity: 1;
  transform: translateY(0);
}

.work-image-delete-icon:hover {
  background: rgba(0, 0, 0, 0.75);
}

.work-image-delete-confirm {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 12px;
  z-index: 3;
}

.work-image-delete-confirm-text {
  font-size: 13px;
  color: #fff;
  text-align: center;
  margin-bottom: 10px;
  line-height: 1.4;
}

.work-image-delete-confirm-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.work-image-thumb-error {
  width: 100%;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 16px 10px;
  background: #f2f4f7;
  color: #909399;
}

.work-image-thumb-error-icon {
  font-size: 28px;
}

.work-image-thumb-error-text {
  font-size: 13px;
  line-height: 1.4;
}

.work-editor-card {
  border: 1px solid #eef0f3;
  border-radius: 16px;
  background: #fff;
  box-shadow: 0 8px 30px rgba(17, 24, 39, 0.06);
  overflow: hidden;
  flex: 1;
}

.work-editor-toolbar {
  position: sticky;
  top: 0;
  z-index: 3;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  background: rgba(255, 255, 255, 0.92);
  backdrop-filter: blur(10px);
  border-bottom: 1px solid #f2f4f7;
}

.work-editor-toolbar-gap {
  width: 8px;
  flex: 0 0 auto;
}

:deep(.work-editor-toolbar .el-button) {
  min-width: 34px;
}

.work-rich-editor {
  width: 100%;
  min-height: 520px;
  padding: 18px 22px;
  font-size: 16px;
  line-height: 1.85;
  color: #111827;
  white-space: pre-wrap;
  word-break: break-word;
  outline: none;
}

.work-rich-editor:empty::before {
  content: attr(data-placeholder);
  color: #a8abb2;
  pointer-events: none;
}

:deep(.work-rich-editor h2) {
  font-size: 22px;
  line-height: 1.35;
  margin: 20px 0 10px;
  font-weight: 700;
}

:deep(.work-rich-editor h3) {
  font-size: 18px;
  line-height: 1.45;
  margin: 18px 0 8px;
  font-weight: 700;
}

:deep(.work-rich-editor p) {
  margin: 10px 0;
}

:deep(.work-rich-editor ul),
:deep(.work-rich-editor ol) {
  margin: 10px 0 10px 22px;
}

:deep(.work-rich-editor blockquote) {
  margin: 14px 0;
  padding: 10px 14px;
  border-left: 4px solid #d1d5db;
  background: #f9fafb;
  border-radius: 10px;
  color: #374151;
}

:deep(.work-rich-editor img) {
  max-width: 100%;
  height: auto;
  border-radius: 10px;
  display: block;
  margin: 12px 0;
}

@media (max-width: 1100px) {
  .work-editor-layout {
    flex-direction: column;
  }

  .work-image-sidebar {
    width: 100%;
    flex: none;
    position: relative;
    top: 0;
    height: auto;
    max-height: 500px;
  }

  .work-image-sidebar.is-collapsed {
    width: 100%;
    flex: none;
  }

  .work-image-list-collapsed {
    flex-direction: row;
    padding: 10px;
    padding-top: 10px;
    justify-content: center;
  }
}

.work-status-panel {
  height: 100%;
  display: flex;
  flex-direction: column;
  padding: 14px 14px 16px;
  gap: 12px;
}

.work-status-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.work-status-title {
  font-size: 14px;
  font-weight: 600;
  color: #111;
}

.work-status-body {
  flex: 1;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.work-status-summary {
  border: 1px solid #eef0f3;
  border-radius: 14px;
  background: #fff;
  padding: 12px 12px 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.work-status-summary-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.work-status-summary-stage {
  color: #374151;
  font-size: 12px;
}

.work-status-summary-progress {
  color: #6b7280;
  font-size: 12px;
}

.work-status-error {
  color: #ef4444;
  font-size: 12px;
  line-height: 1.4;
  word-break: break-word;
}

.work-status-events {
  border: 1px solid #eef0f3;
  border-radius: 14px;
  background: #fff;
  padding: 10px 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.work-status-event {
  border-radius: 12px;
  padding: 10px 10px;
  background: #f9fafb;
  border: 1px solid #f2f4f7;
}

.work-status-event-meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 6px;
}

.work-status-event-time {
  font-size: 12px;
  color: #6b7280;
}

.work-status-event-progress {
  font-size: 12px;
  color: #111827;
  font-weight: 600;
}

.work-status-event-text {
  font-size: 13px;
  line-height: 1.5;
  color: #111827;
  word-break: break-word;
}

.work-status-event-text-main {
  font-weight: 500;
}

.work-status-source-list {
  margin-top: 8px;
  border: 1px solid #eef0f3;
  border-radius: 12px;
  background: #ffffff;
  padding: 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.work-status-source-item {
  padding-bottom: 10px;
  border-bottom: 1px dashed #eef0f3;
}

.work-status-source-item:last-child {
  padding-bottom: 0;
  border-bottom: none;
}

.work-status-source-row {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.work-status-source-index {
  font-size: 12px;
  color: #6b7280;
  flex: 0 0 auto;
}

.work-status-source-title {
  flex: 1 1 auto;
  color: #111827;
  text-decoration: none;
  font-size: 13px;
  line-height: 1.4;
}

.work-status-source-title:hover {
  text-decoration: underline;
}

.work-status-source-host {
  font-size: 12px;
  color: #6b7280;
  flex: 0 0 auto;
}

.work-status-source-excerpt {
  margin-top: 6px;
  font-size: 12px;
  line-height: 1.6;
  color: #4b5563;
  white-space: pre-wrap;
  word-break: break-word;
}

.work-status-source-more {
  font-size: 12px;
  color: #6b7280;
}

.work-artifacts-body {
  min-height: 520px;
}

.work-artifacts-loading {
  padding: 20px 8px;
  font-size: 14px;
  color: #6b7280;
}

.work-artifacts-section {
  margin-bottom: 16px;
}

.work-artifacts-section-title {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  margin-bottom: 10px;
}

.create-work-row {
  display: flex;
  gap: 12px;
}

.create-work-item {
  flex: 1;
}

.create-work-hint {
  margin-top: 8px;
  color: #999;
  font-size: 12px;
}

.resource-rich-editor {
  width: 100%;
  min-height: 320px;
  border: none;
  padding: 0;
  font-size: 16px;
  line-height: 1.8;
  color: #333;
  white-space: pre-wrap;
  word-break: break-word;
  outline: none;
}

.resource-rich-editor:empty::before {
  content: attr(data-placeholder);
  color: #a8abb2;
  pointer-events: none;
}

:deep(.resource-image-chip) {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px;
  margin: 6px 0;
  border-radius: 10px;
  background: #f6f8fa;
  border: 1px solid #e5e7eb;
  vertical-align: middle;
}

:deep(.resource-image-chip img) {
  max-width: min(520px, 60vw);
  max-height: 260px;
  height: auto;
  border-radius: 8px;
  display: block;
}

:deep(.resource-image-chip-remove) {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.08);
  color: #333;
  cursor: pointer;
  user-select: none;
  flex: 0 0 auto;
}

:deep(.resource-title-input .el-input__wrapper) {
  box-shadow: none;
  padding: 0;
  background: transparent;
}

:deep(.resource-title-input .el-input__inner) {
  font-size: 32px;
  font-weight: 600;
  color: #111;
  height: auto;
  line-height: 1.2;
  padding: 0;
}

:deep(.resource-content-input .el-textarea__inner) {
  border: none;
  box-shadow: none;
  padding: 0;
  font-size: 16px;
  line-height: 1.8;
  color: #333;
  resize: none;
}

.resource-content-input {
  width: 100%;
}

.resource-image-preview {
  margin: 20px 0 8px;
}

.resource-preview-html {
  font-size: 14px;
  line-height: 1.7;
  color: #333;
}

.resource-preview-html :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 8px;
  display: block;
  margin: 12px 0;
}

.batch-link-progress {
  margin-top: 8px;
  border: 1px solid #f0f0f0;
  border-radius: 10px;
  padding: 12px;
  background: #fafafa;
}

.batch-link-progress-row {
  display: flex;
  gap: 16px;
  font-size: 12px;
  color: #666;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.batch-link-results {
  max-height: 220px;
  overflow: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.batch-link-result-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 10px;
  border-radius: 8px;
  background: #fff;
  border: 1px solid #eee;
}

.batch-link-result-row.running {
  border-color: rgba(64, 158, 255, 0.35);
}

.batch-link-result-row.success {
  border-color: rgba(103, 194, 58, 0.35);
}

.batch-link-result-row.failed {
  border-color: rgba(245, 108, 108, 0.35);
}

.batch-link-result-url {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: #333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.batch-link-result-status {
  flex: 0 0 auto;
  font-size: 12px;
  color: #666;
  max-width: 180px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.intro-text {
  font-size: 16px;
  line-height: 1.6;
  color: #333;
  margin-bottom: 24px;
}

.article-preview {
  margin-top: 20px;
}

.preview-header {
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.preview-body {
  font-size: 15px;
  line-height: 1.8;
  color: #333;
}

.preview-title {
  font-weight: 600;
  margin-bottom: 12px;
}

.preview-structure ul, .preview-structure ol {
  padding-left: 20px;
  margin: 10px 0;
}

.preview-structure li {
  margin-bottom: 6px;
}

.highlights {
  margin-top: 16px;
}

/* Floating Bar */
.editor-floating-bar {
  position: sticky;
  bottom: 16px;
  left: 50%;
  transform: translateX(-50%);
  background-color: #fff;
  box-shadow: 0 4px 20px rgba(0,0,0,0.1);
  border-radius: 24px;
  padding: 8px 16px;
  display: inline-flex;
  align-items: center;
  gap: 16px;
  border: 1px solid #eee;
  white-space: nowrap;
  z-index: 2;
  max-width: calc(100% - 40px);
}

.feedback-text {
  font-size: 14px;
  color: #666;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 240px;
}

.bar-actions {
  display: flex;
  gap: 8px;
}

/* Right Panel: Chat */
.right-panel {
  background-color: #fff;
  border-radius: 16px 0 0 16px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
  display: flex;
  flex-direction: column;
  overflow: hidden; /* Ensure content doesn't overflow corners */
}
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

    .style-transfer-card {
      display: flex;
      flex-direction: column;
      gap: 10px;
      padding: 14px 14px;
      background-color: #f8f9fa;
      border: 1px solid #eef2f6;
      border-radius: 12px;
      max-width: 520px;

      .card-title-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        min-width: 0;
      }

      .card-title {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 14px;
        font-weight: 600;
        color: #1f1f1f;
      }

      .card-subtitle {
        font-size: 12px;
        color: #6b7280;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 260px;
      }

      .card-desc {
        font-size: 13px;
        color: #374151;
        line-height: 1.5;
      }

      .card-decision {
        font-size: 12px;
        color: #6b7280;
      }

      .card-controls {
        display: flex;
        flex-direction: column;
        gap: 10px;
      }

      .card-select {
        width: 100%;
      }

      .card-actions {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
      }

      .card-empty {
        display: flex;
        flex-direction: column;
        gap: 10px;
      }

      .card-empty-text {
        font-size: 12px;
        color: #6b7280;
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
          background-color: rgba(0, 0, 0, 0.05);
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

        .dot:nth-child(1) {
          animation-delay: -0.32s;
        }

        .dot:nth-child(2) {
          animation-delay: -0.16s;
        }

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
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.06);
        &.loading {
          animation: pulse 1.8s ease-in-out infinite;
          cursor: default;
        }
        .el-image {
          width: 100%;
        }
        :deep(.el-image__inner) {
          width: 100%;
          height: auto;
          object-fit: cover;
          display: block;
        }
        .image-skeleton {
          width: 100%;
          height: 140px;
          background: linear-gradient(90deg, #f3f3f3 25%, #eaeaea 37%, #f3f3f3 63%);
          background-size: 400% 100%;
          animation: shimmer 2.4s ease-in-out infinite;
        }
        .meta {
          padding: 8px 10px;
          .name {
            font-size: 13px;
            color: #1f1f1f;
          }
          .spec {
            font-size: 12px;
            color: #5f6368;
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

          .el-image {
            width: 100%;
            height: 100%;
          }
          :deep(.el-image__inner) {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
          }

          .remove-ref {
            position: absolute;
            top: 0;
            right: 0;
            width: 16px;
            height: 16px;
            background: rgba(0, 0, 0, 0.5);
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
              background: rgba(0, 0, 0, 0.1);
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
            &::placeholder {
              color: #a8abb2;
            }
            &:focus {
              box-shadow: none;
            }
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
          .control-btn:hover {
            background: #e6e8eb;
            color: #303133;
          }
          .btn-icon {
            font-size: 14px;
          }
          .el-icon--right {
            font-size: 12px;
            margin-left: 2px;
          }
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
              box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
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

.inline-image {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  margin: 0 6px;
  vertical-align: middle;
}

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
    &:last-child {
      margin-bottom: 0;
    }
  }

  :deep(ul),
  :deep(ol) {
    margin: 0 0 10px 0;
    padding-left: 20px;
  }

  :deep(li) {
    margin-bottom: 4px;
  }

  :deep(h1),
  :deep(h2),
  :deep(h3),
  :deep(h4) {
    margin: 16px 0 8px 0;
    font-weight: 600;
    line-height: 1.4;
    font-size: 1.1em;

    &:first-child {
      margin-top: 0;
    }
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
    background-color: rgba(0, 0, 0, 0.05);
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
    &:hover {
      text-decoration: underline;
    }
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

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes blink {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0;
  }
}

@keyframes bounce {
  0%,
  80%,
  100% {
    transform: scale(0);
  }
  40% {
    transform: scale(1);
  }
}

@keyframes pulse {
  0% {
    filter: brightness(100%);
  }
  50% {
    filter: brightness(92%);
  }
  100% {
    filter: brightness(100%);
  }
}

@keyframes thinking-step-wave {
  0% {
    -webkit-mask-position: 220% 0;
    mask-position: 220% 0;
  }
  100% {
    -webkit-mask-position: -220% 0;
    mask-position: -220% 0;
  }
}

@keyframes shimmer {
  0% {
    background-position: -200% 0;
  }
  100% {
    background-position: 200% 0;
  }
}
</style>

<style>
/* Custom "New Work" Dialog Styles */
.create-work-dialog {
  border-radius: 16px !important;
  box-shadow: 0 24px 48px rgba(0, 0, 0, 0.12) !important;
  overflow: hidden;
  font-family: inherit;
}

.create-work-dialog .el-dialog__header {
  margin: 0;
  padding: 24px 24px 16px;
  border-bottom: none;
  background: transparent;
}

.create-work-dialog .el-dialog__title {
  font-size: 20px;
  font-weight: 600;
  color: #1a1a1a;
  letter-spacing: -0.02em;
}

.create-work-dialog .el-dialog__headerbtn {
  top: 24px;
  right: 24px;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  transition: background-color 0.2s;
  display: flex;
  align-items: center;
  justify-content: center;
}

.create-work-dialog .el-dialog__headerbtn:hover {
  background-color: rgba(0, 0, 0, 0.05);
}

.create-work-dialog .el-dialog__body {
  padding: 8px 24px 32px;
}

.create-work-dialog .el-form-item {
  margin-bottom: 24px;
}

.create-work-dialog .el-form-item:last-child {
  margin-bottom: 0;
}

.create-work-dialog .el-form-item__label {
  font-size: 14px;
  font-weight: 500;
  color: #4b5563;
  padding-bottom: 8px;
  line-height: 1.4;
}

.create-work-dialog .el-input__wrapper,
.create-work-dialog .el-textarea__inner {
  box-shadow: none !important;
  background-color: #f3f4f6;
  border: 1px solid transparent;
  border-radius: 10px;
  padding: 8px 12px;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.create-work-dialog .el-input__wrapper:hover,
.create-work-dialog .el-textarea__inner:hover {
  background-color: #e5e7eb;
}

.create-work-dialog .el-input__wrapper.is-focus,
.create-work-dialog .el-textarea__inner:focus {
  background-color: #fff;
  border-color: var(--el-color-primary);
  box-shadow: 0 0 0 3px var(--el-color-primary-light-9) !important;
}

.create-work-dialog .el-textarea__inner {
  padding: 12px;
  min-height: 100px;
}

.create-work-dialog .create-work-row {
  display: flex;
  gap: 16px;
}

.create-work-dialog .create-work-hint {
  font-size: 13px;
  color: #9ca3af;
  margin-top: 6px;
}

.create-work-dialog .el-dialog__footer {
  padding: 20px 24px 24px;
  border-top: none;
  background-color: transparent;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.create-work-dialog .el-button {
  height: 40px;
  padding: 0 24px;
  border-radius: 10px;
  font-weight: 500;
  font-size: 14px;
  border: none;
  transition: all 0.2s;
}

.create-work-dialog .el-button--default {
  background-color: #f3f4f6;
  color: #4b5563;
}

.create-work-dialog .el-button--default:hover {
  background-color: #e5e7eb;
  color: #1f2937;
}

.create-work-dialog .el-button--primary {
  background-color: #111827; /* Dark premium primary */
  color: #fff;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.create-work-dialog .el-button--primary:hover {
  background-color: #000;
  transform: translateY(-1px);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

.create-work-dialog .el-button--primary:active {
  transform: translateY(0);
}

.create-work-dialog .el-button.is-disabled {
  opacity: 0.6;
  transform: none;
  box-shadow: none;
}

/* Dark Mode Adaptation */
@media (prefers-color-scheme: dark) {
  .create-work-dialog {
    background-color: #1f2937; /* Dark gray bg */
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.4) !important;
  }

  .create-work-dialog .el-dialog__title {
    color: #f9fafb;
  }

  .create-work-dialog .el-dialog__headerbtn:hover {
    background-color: rgba(255, 255, 255, 0.1);
  }

  .create-work-dialog .el-form-item__label {
    color: #d1d5db;
  }

  .create-work-dialog .el-input__wrapper,
  .create-work-dialog .el-textarea__inner {
    background-color: #374151;
    color: #f9fafb;
  }

  .create-work-dialog .el-input__wrapper:hover,
  .create-work-dialog .el-textarea__inner:hover {
    background-color: #4b5563;
  }

  .create-work-dialog .el-input__wrapper.is-focus,
  .create-work-dialog .el-textarea__inner:focus {
    background-color: #1f2937;
    border-color: #60a5fa; /* Blue accent for dark mode */
    box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.2) !important;
  }

  .create-work-dialog .el-button--default {
    background-color: #374151;
    color: #e5e7eb;
  }

  .create-work-dialog .el-button--default:hover {
    background-color: #4b5563;
    color: #fff;
  }

  .create-work-dialog .el-button--primary {
    background-color: #f9fafb;
    color: #111827;
  }

  .create-work-dialog .el-button--primary:hover {
    background-color: #fff;
    box-shadow: 0 0 12px rgba(255, 255, 255, 0.15);
  }
}
</style>
