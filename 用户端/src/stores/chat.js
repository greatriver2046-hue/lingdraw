import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useGenerationStore } from './generation'
import request from '@/utils/request'
import router from '@/router'
import { wsManager } from '@/utils/websocket'
import { config } from '@/config'

const loadImage = (src) => new Promise((resolve, reject) => {
  const i = new Image()
  i.crossOrigin = 'anonymous'
  i.src = src
  i.onload = () => resolve(i)
  i.onerror = reject
})

export const resolutionCache = new Map()

export const resolveImageResolution = async (u) => {
  if (!u) return null
  if (resolutionCache.has(u)) return resolutionCache.get(u)
  
  try {
    const img = await loadImage(u)
    const res = { w: img.naturalWidth || img.width || 0, h: img.naturalHeight || img.height || 0 }
    resolutionCache.set(u, res)
    return res
  } catch {
    try {
      const proxy = `${config.API_BASE_URL}/api/proxy/image?url=` + encodeURIComponent(u)
      const img = await loadImage(proxy)
      const res = { w: img.naturalWidth || img.width || 0, h: img.naturalHeight || img.height || 0 }
      resolutionCache.set(u, res)
      return res
    } catch {
      return null
    }
  }
}

const formatThinkingStepLine = (title, summary) => {
  const t = (typeof title === 'string' ? title : (title?.toString() || '')).trim()
  const s = (typeof summary === 'string' ? summary : (summary?.toString() || '')).trim()
  if (!t) return s
  if (!s) return t
  return `${t}：${s}`
}

export const useChatStore = defineStore('chat', () => {
  const messages = ref([
    {
      id: 1,
      role: 'assistant',
      content: '你好！我是你的 AI 助手，有什么可以帮你的吗？',
      timestamp: Date.now()
    }
  ])
  const currentConversationId = ref(null)
  const currentCanvasState = ref(null)
  const conversations = ref([])
  const currentLlmModelIdentity = ref('')
  
  const loadingCount = ref(0)
  const isLoading = computed(() => loadingCount.value > 0)
  const streamingCount = ref(0)
  const isQueueActive = ref(false)
  const isStreaming = computed(() => streamingCount.value > 0 || isQueueActive.value)
  const streamTicker = ref(0)

  // Smooth streaming queue
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
          // Linear speed for typewriter effect
          // Only speed up if falling behind significantly
          const step = q.length > 200 ? 5 : (q.length > 50 ? 2 : 1)
          const chunk = q.slice(0, step)
          streamQueue[id] = q.slice(step)
          
          const m = messages.value.find(x => String(x.id) === String(id))
          if (m) {
             m.content += chunk
             streamTicker.value++
          } else {
             delete streamQueue[id]
             continue
          }
        } else {
          delete streamQueue[id]
        }
      }
      
      if (active) {
        isQueueActive.value = true
      } else {
        isQueueActive.value = false
        if (streamingCount.value === 0) {
           clearInterval(streamTimer)
           streamTimer = null
        }
      }
    }, 30)
  }
  
  // Pagination state
  const currentPage = ref(1)
  const hasMore = ref(true)
  const isLoadingMore = ref(false)

  const upsertConversationItem = (id, payloadOrUpdater) => {
    const idx = conversations.value.findIndex(it => String(it.id) === String(id))
    if (idx >= 0) {
      const old = conversations.value[idx]
      const next = typeof payloadOrUpdater === 'function' ? payloadOrUpdater({ ...old }) : { ...old, ...payloadOrUpdater }
      conversations.value.splice(idx, 1, next)
    } else {
      const base = typeof payloadOrUpdater === 'function' ? payloadOrUpdater({ id }) : { id, ...payloadOrUpdater }
      conversations.value.unshift(base)
    }
  }

  const buildToolResultSummary = (toolResult) => {
    if (!toolResult || typeof toolResult !== 'object') return ''
    const name = (toolResult.image_name || '').trim() || (toolResult.video_url ? '生成视频' : '生成图像')
    const type = toolResult.video_url ? '视频' : '图片'
    const resolution = (toolResult.resolution || '').trim()
    const count = (toolResult.count !== undefined && toolResult.count !== null && String(toolResult.count).trim() !== '') ? String(toolResult.count).trim() : ''
    const model = (toolResult.model_name || toolResult.model_identity || toolResult.model || toolResult.model_id || '').toString().trim()

    const lines = [`名称：${name}`, `类型：${type}`]
    if (resolution) lines.push(`分辨率：${resolution}`)
    if (model) lines.push(`模型：${model}`)
    if (type === '图片' && count && count !== '1') lines.push(`数量：${count}`)
    return lines.join('\n')
  }

  const appendAssistantMessage = (content) => {
    const id = Date.now() + Math.floor(Math.random() * 1000)
    messages.value.push({
      id,
      role: 'assistant',
      content: typeof content === 'string' ? content : (content?.toString() || ''),
      timestamp: Date.now()
    })
    streamTicker.value++
    return id
  }

  const appendToolResultMessage = (toolResult) => {
    const id = Date.now() + Math.floor(Math.random() * 1000)
    const tr = toolResult && typeof toolResult === 'object' ? toolResult : {}
    const msg = {
      id,
      role: 'assistant',
      content: '',
      timestamp: Date.now(),
      toolResult: tr
    }
    const summary = buildToolResultSummary(tr)
    if (summary) msg.content = summary
    messages.value.push(msg)
    const url = tr.image_url || tr.video_url || ''
    if (url) {
      resolveImageResolution(url).then(dim => {
        const m = messages.value.find(mm => String(mm.id) === String(id))
        if (dim && m && m.toolResult) {
          m.toolResult.resolution = (dim.w && dim.h) ? `${dim.w}x${dim.h}` : (m.toolResult.resolution || '')
          const nextSummary = buildToolResultSummary(m.toolResult)
          if (nextSummary) m.content = nextSummary
          streamTicker.value++
        }
      })
    }
    streamTicker.value++
    return id
  }

  const trackExternalImageTask = async ({
    taskId,
    imageName,
    modelIdentity,
    modelName,
    width,
    height,
    aspectRatio,
    resolution,
    toolResultExtra,
    closingText
  } = {}) => {
    const generationStore = useGenerationStore()
    const id = taskId ? String(taskId) : ''
    if (!id) throw new Error('缺少任务ID')

    const mdlId = modelIdentity ?? generationStore.selectedImageModel
    let mdlLabel = modelName || '图片模型'
    if (!modelName) {
      if (mdlId) {
        const mdl = generationStore.imageModels.find(m => m.model_identity === mdlId)
        mdlLabel = (mdl && (mdl.name || mdl.model_identity)) ? (mdl.name || mdl.model_identity) : String(mdlId)
      }
    }

    const msgId = 'msg_image_' + id
    messages.value.push({
      id: msgId,
      role: 'assistant',
      content: '',
      timestamp: Date.now(),
      pendingTool: {
        name: 'image_generate',
        modelName: mdlLabel,
        width: typeof width === 'number' ? width : undefined,
        height: typeof height === 'number' ? height : undefined,
        aspectRatio: typeof aspectRatio === 'string' ? aspectRatio : undefined,
        imageName: imageName || '生成图像',
        loading: true,
        taskId: id,
        resolution: typeof resolution === 'string' ? resolution : '',
        isVideo: false,
        statusText: '队列中…',
        progress: null,
        createdAt: Date.now()
      }
    })
    streamTicker.value++

    try {
      const result = await generationStore.waitForTask(id, (p) => {
        const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(id))
        if (!currentMsg || !currentMsg.pendingTool) return
        const st = p && typeof p === 'object' ? (p.status || '') : ''
        const prog = p && typeof p === 'object' ? p.progress : null
        currentMsg.pendingTool.progress = (typeof prog === 'number' ? prog : null)
        if (st === 'queued') {
          currentMsg.pendingTool.statusText = '队列中…'
        } else if (st === 'processing') {
          if (typeof prog === 'number' && isFinite(prog)) {
            const pct = Math.max(0, Math.min(100, Math.round(prog)))
            currentMsg.pendingTool.statusText = `生成中 ${pct}%…`
          } else {
            currentMsg.pendingTool.statusText = '生成中…'
          }
        }
        streamTicker.value++
      })

      const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(id))
      if (currentMsg) {
        const firstVideo = (Array.isArray(result.videos) && result.videos.length > 0) ? result.videos[0] : null
        const videoUrl = firstVideo?.url || ''
        const imageUrls = Array.isArray(result.images)
          ? result.images.map(it => (it?.url || (it?.b64 ? `data:image/png;base64,${it.b64}` : ''))).filter(Boolean)
          : []
        const uniqImageUrls = Array.from(new Set(imageUrls))
        const imgUrl = uniqImageUrls[0] || ''
        const finalUrl = videoUrl || imgUrl

        const pendingName = currentMsg.pendingTool?.imageName
        currentMsg.thinking = false
        currentMsg.pendingTool = null
        currentMsg.toolResult = {
          image_url: imgUrl,
          video_url: videoUrl,
          image_name: result.image_name || pendingName || (videoUrl ? '生成视频' : '生成图像'),
          aspect_ratio: typeof aspectRatio === 'string' ? aspectRatio : (generationStore.aspectRatio || ''),
          resolution: (typeof width === 'number' && typeof height === 'number') ? `${width}x${height}` : (result.size || ''),
          count: videoUrl ? 1 : 1,
          model_identity: result.model_identity || result.model || result.model_id || (mdlId ? String(mdlId) : ''),
          model_name: result.model_name || ''
        }
        if (toolResultExtra && typeof toolResultExtra === 'object') {
          Object.assign(currentMsg.toolResult, toolResultExtra)
        }
        const summary = buildToolResultSummary(currentMsg.toolResult)
        if (summary) currentMsg.content = summary
        streamTicker.value++

        if (finalUrl) {
          resolveImageResolution(finalUrl).then(dim => {
            const m = messages.value.find(mm => String(mm.id) === String(currentMsg.id))
            if (dim && m && m.toolResult) {
              m.toolResult.resolution = (dim.w && dim.h) ? `${dim.w}x${dim.h}` : (m.toolResult.resolution || '')
              const nextSummary = buildToolResultSummary(m.toolResult)
              if (nextSummary) m.content = nextSummary
              streamTicker.value++
            }
          })
        }

        if (finalUrl && currentConversationId.value) {
          upsertConversationItem(currentConversationId.value, (old) => ({
            ...old,
            last_image_url: finalUrl,
            last_image_thumb_url: finalUrl,
            updated_at: new Date().toISOString().replace('T',' ').slice(0,19)
          }))
          fetchConversations()
        }

        if (closingText && String(closingText).trim()) {
          appendAssistantMessage(String(closingText))
        }

        return { result, imageUrl: imgUrl, videoUrl, imageUrls: uniqImageUrls }
      }

      if (closingText && String(closingText).trim()) {
        appendAssistantMessage(String(closingText))
      }
      return { result, imageUrl: '', videoUrl: '', imageUrls: [] }
    } catch (err) {
      const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(id))
      if (currentMsg) {
        currentMsg.thinking = false
        currentMsg.pendingTool = null
        currentMsg.isError = true
        currentMsg.content = err?.message || '生成任务失败'
        streamTicker.value++
      }
      throw err
    }
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

  const toPreviewSourceItems = (payloadItems) => {
    const raw = payloadItems
    const items = Array.isArray(raw) ? raw : (raw && typeof raw === 'object' ? Object.values(raw) : [])
    const limit = 5
    const sliced = items.slice(0, limit)
    const mapped = sliced.map((x, idx) => {
      const url = String(x?.url || '').trim()
      const title = String(x?.title || '').trim()
      return {
        key: `${idx}-${url}`,
        url,
        title,
        host: getHostFromUrl(url)
      }
    })
    return {
      totalCount: items.length,
      items: mapped,
      moreCount: Math.max(0, items.length - mapped.length)
    }
  }

  const formatSourceListMessage = (preview, label) => {
    if (!preview || typeof preview !== 'object') return ''
    const total = Number(preview.totalCount || 0)
    const items = Array.isArray(preview.items) ? preview.items : []
    const lbl = String(label || '').trim() || '搜索结果'
    if (total <= 0 || items.length === 0) return `${lbl}：暂无`
    const lines = items.map((x, idx) => {
      const title = String(x?.title || '').trim()
      const url = String(x?.url || '').trim()
      if (url && title) return `${idx + 1}. [${title}](${url})`
      if (url) return `${idx + 1}. ${url}`
      return `${idx + 1}. ${title || '未命名来源'}`
    })
    let text = `${lbl}（共 ${total} 条）：\n${lines.join('\n')}`
    if (preview.moreCount > 0) text += `\n还有 ${preview.moreCount} 条未展示`
    return text
  }

  const summarizeArtifact = (type, artifact) => {
    if (!type || !artifact) return ''
    const payload = parseJsonSafely(artifact.payload_json)
    if (type === 'fact_pack') {
      const facts = Array.isArray(payload?.facts) ? payload.facts.length : 0
      const stats = Array.isArray(payload?.stats) ? payload.stats.length : 0
      const cases = Array.isArray(payload?.cases) ? payload.cases.length : 0
      const conflicts = Array.isArray(payload?.conflicts) ? payload.conflicts.length : 0
      const opens = Array.isArray(payload?.open_questions) ? payload.open_questions.length : 0
      let msg = `事实包：facts ${facts} / stats ${stats} / cases ${cases} / conflicts ${conflicts} / open_questions ${opens}`
      const topFacts = Array.isArray(payload?.facts) ? payload.facts.slice(0, 5) : []
      if (topFacts.length > 0) {
        msg += '\n' + topFacts.map((f, i) => `${i + 1}. ${String(f.statement || '').slice(0, 50)}`).join('\n')
      }
      return msg
    }
    if (type === 'style_report') {
      const pass = payload?.pass !== false
      const violations = Array.isArray(payload?.violations) ? payload.violations.length : 0
      const weak = Array.isArray(payload?.weak_segments) ? payload.weak_segments.length : 0
      const rewrite = Array.isArray(payload?.rewrite_tasks) ? payload.rewrite_tasks.length : 0
      return `风格评审：${pass ? '通过' : '未通过'}，violations ${violations}，weak ${weak}，rewrite_tasks ${rewrite}`
    }
    if (type === 'risk_report') {
      const rewriteRequired = payload?.rewrite_required === true
      const flagged = Array.isArray(payload?.flagged_segments) ? payload.flagged_segments.length : 0
      return `风控：${rewriteRequired ? '需要改写' : '无需改写'}，flagged ${flagged}`
    }
    if (type === 'outline' || type === 'neutral_draft' || type === 'styled_draft' || type === 'final_article') {
      const text = String(artifact.text || '')
      const lines = text.split(/\r?\n/)
      const headings = []
      for (const line of lines) {
        const m = /^\s{0,3}(#{1,4})\s+(.+?)\s*$/.exec(line)
        if (!m) continue
        headings.push(m[2])
        if (headings.length >= 3) break
      }
      const parts = []
      if (type === 'outline') parts.push('大纲已生成')
      else if (type === 'neutral_draft') parts.push('中性稿已生成')
      else if (type === 'styled_draft') parts.push('风格稿已生成')
      else parts.push('最终稿已生成')
      parts.push(`长度 ${text.length}`)
      if (headings.length) parts.push(`小节：${headings.join(' / ')}`)
      return parts.join('，')
    }
    return ''
  }

  const writingStageToArtifactType = {
    STAGE_WEB_SEARCH: 'sources',
    STAGE_FETCH_SOURCES: 'fetched_sources',
    STAGE_TITLE_FILTER: 'title_filtered_out_sources',
    STAGE_CLEAN_SOURCES: 'cleaned_sources',
    STAGE_FACT_PACK: 'fact_pack',
    STAGE_OUTLINE: 'outline',
    STAGE_NEUTRAL_DRAFT: 'neutral_draft',
    STAGE_STYLE_TRANSFER: 'styled_draft',
    STAGE_STYLE_QA: 'style_report',
    STAGE_RISK_CHECK: 'risk_report',
    STAGE_FINALIZE: 'final_article'
  }

  const writingStageLabelToArtifactType = {
    '联网搜索': 'sources',
    '抓取资料': 'fetched_sources',
    '标题筛选': 'title_filtered_out_sources',
    '标题筛除': 'title_filtered_out_sources',
    '清洗资料': 'cleaned_sources',
    '事实包': 'fact_pack',
    '大纲': 'outline',
    '中性稿': 'neutral_draft',
    '风格迁移': 'styled_draft',
    '风格自检': 'style_report',
    '相似度风控': 'risk_report',
    '最终整理': 'final_article'
  }

  const writingListLabelByType = {
    sources: '搜索结果',
    fetched_sources: '抓取完成',
    cleaned_sources: '清洗完成',
    title_filtered_out_sources: '标题筛除'
  }

  const writingArtifactSeen = new Set()

  const pushAssistantMessage = (content, extra = {}) => {
    const text = String(content || '').trim()
    if (!text) return
    const id = Date.now() + Math.floor(Math.random() * 1000)
    messages.value.push({ id, role: 'assistant', content: text, timestamp: Date.now(), ...extra })
    streamTicker.value++
  }

  const resolveWritingArtifactType = (obj, line) => {
    const stage = String(obj?.stage || obj?.stage_key || obj?.stageKey || obj?.status || '').trim()
    if (stage && writingStageToArtifactType[stage]) return writingStageToArtifactType[stage]
    const m = /进入阶段：(.+)$/.exec(String(line || '').trim())
    const label = m ? String(m[1] || '').trim() : ''
    if (label && writingStageLabelToArtifactType[label]) return writingStageLabelToArtifactType[label]
    return ''
  }

  const resolveWritingTaskId = (obj) => {
    const direct = obj?.task_id || obj?.taskId
    const nested = obj?.data?.task_id || obj?.data?.taskId || obj?.meta?.task_id || obj?.meta?.taskId
    const tid = String(direct || nested || '').trim()
    return tid || ''
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

  const resolvePreviewFromStepPayload = (obj) => {
    if (!obj || typeof obj !== 'object') return { totalCount: 0, items: [], moreCount: 0 }
    const preview = obj.preview || obj.data?.preview || null
    if (preview && typeof preview === 'object') {
      const rawItems = Array.isArray(preview.items) ? preview.items : resolveSourceItemsFromStepPayload(obj)
      const normalized = toPreviewSourceItems(rawItems)
      const total = Number(preview.totalCount || preview.total || normalized.totalCount || 0)
      return { ...normalized, totalCount: total }
    }
    const items = resolveSourceItemsFromStepPayload(obj)
    return toPreviewSourceItems(items)
  }

  const handleWritingArtifactFromStep = async (target, obj, line) => {
    const taskId = resolveWritingTaskId(obj)
    if (!taskId) return
    if (!target) return
    const type = resolveWritingArtifactType(obj, line)
    if (!type) return
    const key = `${taskId}:${type}`
    if (writingArtifactSeen.has(key)) return
    writingArtifactSeen.add(key)
    const addThinkingLine = (text) => {
      const t = String(text || '').trim()
      if (!t) return
      if (!Array.isArray(target.thinkingSteps)) target.thinkingSteps = []
      if (target.thinkingSteps.some(s => String(s).trim() === t)) return
      target.thinkingSteps.push(t)
    }
    const addThinkingLines = (text) => {
      const lines = String(text || '').split(/\r?\n/).map(x => String(x || '').trim()).filter(Boolean)
      for (const it of lines) addThinkingLine(it)
    }
    if (type === 'sources' || type === 'fetched_sources' || type === 'cleaned_sources' || type === 'title_filtered_out_sources') {
      const preview = resolvePreviewFromStepPayload(obj)
      const label = writingListLabelByType[type] || '搜索结果'
      const msg = formatSourceListMessage(preview, label)
      if (msg) addThinkingLines(msg)
      return
    }
    const payloadJson = obj?.payload_json || obj?.data?.payload_json
    const text = obj?.text || obj?.data?.text
    if (!payloadJson && !text) return
    const summary = summarizeArtifact(type, { payload_json: payloadJson, text })
    if (summary) addThinkingLines(summary)
  }

  const sendMessage = async (content, isPolishEnabled = false, extraOptions = null) => {
    // Auto-create conversation if none
    if (!currentConversationId.value) {
      try {
        const rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：'
        let titleContent = content
        if (titleContent.startsWith(rawPrefix)) {
          titleContent = titleContent.substring(rawPrefix.length)
        }
        const resp = await request.post('/api/v1/conversation/create', {
          title: titleContent.slice(0, 10)
        })
        const json = resp.data
        if (json.code === 200) {
          currentConversationId.value = json.data?.conversation_id
          const initMsg = json.data?.initial_message
          if (initMsg && initMsg.id) {
            messages.value = [{
              id: initMsg.id,
              role: initMsg.role || 'assistant',
              content: typeof initMsg.content === 'string' ? initMsg.content : (initMsg.content?.toString() || ''),
              timestamp: initMsg.timestamp || Date.now()
            }]
            streamTicker.value++
          }
          upsertConversationItem(currentConversationId.value, {
            title: titleContent.slice(0, 10),
            created_at: new Date().toISOString().replace('T',' ').slice(0,19),
            updated_at: new Date().toISOString().replace('T',' ').slice(0,19),
            last_image_url: '',
            last_image_thumb_url: ''
          })
          await fetchConversations()
        }
      } catch (e) { /* ignore */ }
    }
    const userMsgId = Date.now()
    messages.value.push({
      id: userMsgId,
      role: 'user',
      content,
      timestamp: Date.now()
    })

    if (currentConversationId.value) {
      const idx = conversations.value.findIndex(it => String(it.id) === String(currentConversationId.value))
      if (idx >= 0) {
        const t = (conversations.value[idx].title || '').trim()
        if (!t || t === '新的对话' || t === 'New Conversation') {
          const rawPrefix = '直接将以下内容作为prompt传入工具不要做任何解析修改：'
          let titleContent = content
          if (titleContent.startsWith(rawPrefix)) {
            titleContent = titleContent.substring(rawPrefix.length)
          }
          upsertConversationItem(currentConversationId.value, {
            title: titleContent.slice(0, 10),
            updated_at: new Date().toISOString().replace('T',' ').slice(0,19)
          })
        }
      }
    }

    loadingCount.value++
    streamingCount.value++
    let requestActive = true
    const finishRequest = () => {
      if (requestActive) {
        loadingCount.value--
        streamingCount.value--
        requestActive = false
      }
    }

    const assistantId = Date.now() + 1
    let streamTargetId = assistantId
    messages.value.push({
      id: assistantId,
      role: 'assistant',
      content: '',
      thinking: true,
      thinkingAction: '',
      thinkingSteps: [],
      isThinkingTrace: true,
      timestamp: Date.now()
    })
    
    try {
      const token = localStorage.getItem('token')
      // Construct message history for the API
      // Map store messages to API format { role, content }
      const generationStore = useGenerationStore()
      // Upload any local File references once at submit time
      await generationStore.uploadPendingReferences()
      
          const refUrls = (generationStore.referenceImages || []).filter(x => typeof x === 'string')
          if (refUrls.length > 0) {
            const userMsg = messages.value.find(m => m.id === userMsgId)
            if (userMsg) {
              const refsText = refUrls.join('\n')
              
              const resolutions = []
              for (let i = 0; i < refUrls.length; i++) {
                const url = refUrls[i]
                const dim = await resolveImageResolution(url)
                if (dim && dim.w && dim.h) {
                  resolutions.push(`参考图${i + 1}(${dim.w}x${dim.h})`)
                }
              }
              let resolutionText = ''
              if (resolutions.length > 0) {
                resolutionText = `（${resolutions.join('，')}）`
              }

              userMsg.content = `${userMsg.content}\n${refsText}\n${resolutionText}`
            }
          }
      if (generationStore.selectedImageModel) {
        const mdl = generationStore.imageModels.find(m => m.model_identity === generationStore.selectedImageModel)
        const mdlLabel = (mdl && (mdl.name || mdl.model_identity)) ? (mdl.name || mdl.model_identity) : generationStore.selectedImageModel
        const userMsg = messages.value.find(m => m.id === userMsgId)
        if (userMsg) {
          userMsg.content = `${userMsg.content}，使用${mdlLabel}`

          // Check if it is a video model and append video params
          const vidModel = generationStore.videoModels.find(m => (m.name === generationStore.selectedImageModel || m.model_identity === generationStore.selectedImageModel))
          if (vidModel) {
            const params = []
            if (generationStore.videoAspectRatio) params.push(`画面比例 ${generationStore.videoAspectRatio}`)
            if (generationStore.videoDuration) params.push(`时长 ${generationStore.videoDuration}`)
            if (generationStore.videoResolution) params.push(`分辨率 ${generationStore.videoResolution}`)
            
            if (params.length > 0) {
              userMsg.content += `，${params.join('，')}`
            }
          } else {
            // Append image resolution parameter for image models
            if (generationStore.imageResolution) {
              userMsg.content += `，分辨率 ${generationStore.imageResolution}`
            }
          }
        }
      }
      const apiMessages = messages.value
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

      const options = {
        reference_images: refUrls,
        image_model: generationStore.selectedImageModel,
        conversation_id: currentConversationId.value,
        stream: true,
        client_user_message_id: userMsgId,
        client_assistant_message_id: assistantId,
        // Video params
        video_aspect_ratio: generationStore.videoAspectRatio,
        video_duration: generationStore.videoDuration,
        video_resolution: generationStore.videoResolution,
        is_polish_enabled: isPolishEnabled
      }
      if (extraOptions && typeof extraOptions === 'object') {
        Object.assign(options, extraOptions)
      }

      // Unique interaction ID
      const chatInteractionId = 'chat_' + Date.now()

      const handleChatUpdate = async (payload) => {
        // Only handle updates for this specific chat interaction
        const incomingChatId = payload.chat_id || payload.data?.chat_id
        if (incomingChatId !== chatInteractionId) return

        const obj = payload.data
        if (!obj) return
        const incomingModel = payload.model_identity || payload.model_id || payload.model || obj.model_identity || obj.model_id || obj.model
        if (incomingModel) currentLlmModelIdentity.value = incomingModel

        const msg = streamTargetId ? messages.value.find(m => m.id === streamTargetId) : null

        if (obj.type === 'content_delta') {
          if (!msg) {
            const newId = Date.now() + Math.floor(Math.random() * 1000)
            messages.value.push({ id: newId, role: 'assistant', content: '', timestamp: Date.now() })
            streamTargetId = newId
            // const newMsg = messages.value.find(m => m.id === newId)
            // newMsg.content += (typeof obj.content === 'string' ? obj.content : '')
            const txt = (typeof obj.content === 'string' ? obj.content : '')
            if (txt) {
              streamQueue[newId] = (streamQueue[newId] || '') + txt
              startStreamProcessor()
            }
          } else {
            msg.thinking = false
            if (msg.content === '思考中……') msg.content = ''
            // msg.content += (typeof obj.content === 'string' ? obj.content : '')
            const txt = (typeof obj.content === 'string' ? obj.content : '')
            if (txt) {
              streamQueue[msg.id] = (streamQueue[msg.id] || '') + txt
              startStreamProcessor()
            }
          }
          // streamTicker.value++
        } else if (obj.type === 'assistant' || obj.type === 'done') {
          if (obj.type === 'done') {
            wsManager.off('chat_update', handleChatUpdate)
            finishRequest()
          }
          const target = msg || messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
          if (!target) {
            const newId = Date.now() + Math.floor(Math.random() * 1000)
            messages.value.push({ 
              id: newId, 
              role: 'assistant', 
              content: typeof obj.content === 'string' ? obj.content : (obj.content?.toString() || ''), 
              timestamp: Date.now() 
            })
            streamTargetId = newId
          } else {
            const awaitingTask = !!target.awaitingTask
            if (!awaitingTask) target.thinking = false
            if (!awaitingTask && target.content === '思考中……' && (obj.content || obj.type === 'done')) target.content = ''
            if (obj.type === 'done') {
              if (awaitingTask) return
            }
            if (obj.content) {
              // If streaming is active, don't snap to final content immediately to preserve animation
              // unless the queue is empty
              if (!streamQueue[target.id] || streamQueue[target.id].length === 0) {
                target.content = typeof obj.content === 'string' ? obj.content : (obj.content?.toString() || '')
              } else {
                 // Optional: verify if we need to append missing parts? 
                 // For now, trust the stream queue to finish.
              }
            }
            if (obj.type === 'done' && (!obj.content || String(obj.content).trim() === '')) {
              if (Array.isArray(target.thinkingSteps) && target.thinkingSteps.length > 0) {
                const joined = target.thinkingSteps.join('\n')
                if (!target.content || String(target.content).trim() === '') {
                  target.content = joined
                }
              }
            }
          }
          if (obj.type === 'done') {
             const finalMsg = streamTargetId ? messages.value.find(m => m.id === streamTargetId) : null
             if (finalMsg) finalMsg.thinking = false
          }
          streamTicker.value++
        } else if (obj.type === 'sm_status') {
          const text = (typeof obj.content === 'string' ? obj.content : (obj.content?.toString() || '')).trim()
          if (!text) return
          const target = msg || messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
          if (target) {
            target.thinking = true
            target.thinkingAction = text
            if (!Array.isArray(target.thinkingSteps)) target.thinkingSteps = []
            target.thinkingPendingLoadingText = text
            const existingIdx = target.thinkingSteps.findIndex(s => String(s).trim() === text)
            target.thinkingLoadingIndex = existingIdx >= 0 ? existingIdx : -1
            streamTicker.value++
          }
        } else if (obj.type === 'sm_step') {
          const line = formatThinkingStepLine(obj.title, obj.summary)
          if (!line) return
          const target = msg || messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
          if (target) {
            if (!Array.isArray(target.thinkingSteps)) target.thinkingSteps = []
            let idx = target.thinkingSteps.findIndex(s => String(s).trim() === line)
            if (idx < 0) {
              target.thinkingSteps.push(line)
              idx = target.thinkingSteps.length - 1
            }
            if (target.thinkingPendingLoadingText && String(target.thinkingPendingLoadingText).trim() === line) {
              target.thinkingLoadingIndex = idx
              target.thinkingPendingLoadingText = ''
            }
            streamTicker.value++
          }
          handleWritingArtifactFromStep(target, obj, line)
        } else if (obj.type === 'tool_result') {
          const tr = obj.tool_result || {}
          const url = tr.image_url || tr.video_url || ''
          const targetMsg = tr.message_id
            ? messages.value.find(m => String(m.id) === String(tr.message_id))
            : messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(tr.task_id))
          
          if (targetMsg) {
            targetMsg.thinking = false
            if (targetMsg.content === '思考中……') targetMsg.content = ''
            targetMsg.pendingTool = null
            targetMsg.toolResult = tr
            const summary = buildToolResultSummary(targetMsg.toolResult)
            if (summary) targetMsg.content = summary
            const thinkingMsg = messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
            if (thinkingMsg) {
              thinkingMsg.thinkingAction = '任务已经完成'
              thinkingMsg.awaitingTask = false
              thinkingMsg.thinkingDone = true
              thinkingMsg.thinking = false
              const joined = Array.isArray(thinkingMsg.thinkingSteps) ? thinkingMsg.thinkingSteps.join('\n') : ''
              if (!thinkingMsg.thinkingPersisted && joined && currentConversationId.value) {
                thinkingMsg.thinkingPersisted = true
                request.post('/api/v1/conversation/thinking/save', {
                  conversation_id: currentConversationId.value,
                  message_id: thinkingMsg.id,
                  thinking_action: thinkingMsg.thinkingAction,
                  thinking_steps: thinkingMsg.thinkingSteps
                }).catch(() => {})
              }
            }
            if (url) {
              resolveImageResolution(url).then(dim => {
                const m = messages.value.find(mm => String(mm.id) === String(targetMsg.id))
                if (dim && m && m.toolResult) {
                  m.toolResult.resolution = (dim.w && dim.h) ? `${dim.w}x${dim.h}` : (m.toolResult.resolution || '')
                  const nextSummary = buildToolResultSummary(m.toolResult)
                  if (nextSummary) m.content = nextSummary
                }
              })
            }
          }
          streamTargetId = null
          
          if (url) {
            if (tr.image_url) generationStore.generatedImage = url
            upsertConversationItem(currentConversationId.value, (old) => ({
              ...old,
              last_image_url: url,
              last_image_thumb_url: url,
              updated_at: new Date().toISOString().replace('T',' ').slice(0,19)
            }))
            fetchConversations()
          }
          streamTicker.value++
        } else if (obj.type === 'tool_task') {
          const task = obj.data || {}
          const taskId = task.task_id || ''
          const msgId = task.message_id || ('msg_image_' + taskId)
          const mdlId = generationStore.selectedImageModel
          let mdlLabel = '图片模型'
          if (mdlId) {
            const mdl = generationStore.imageModels.find(m => m.model_identity === mdlId)
            mdlLabel = (mdl && (mdl.name || mdl.model_identity)) ? (mdl.name || mdl.model_identity) : mdlId
          }
          
          finishRequest()
          const thinkingMsg = messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
          if (thinkingMsg) {
            thinkingMsg.thinkingAction = '等待生成结果…'
            thinkingMsg.thinking = true
            thinkingMsg.awaitingTask = true
            thinkingMsg.thinkingDone = false
          }
          const isVideoTask = (task.tool_meta && task.tool_meta.task_type === 'TEXT_TO_VIDEO') || 
                              (task.tool_meta && task.tool_meta.task_type === 'IMAGE_TO_VIDEO')

          streamTargetId = null
          messages.value.push({
            id: msgId,
            role: 'assistant',
            content: '',
            timestamp: Date.now(),
            pendingTool: {
              name: 'image_generate',
              modelName: mdlLabel,
              width: generationStore.width,
              height: generationStore.height,
              aspectRatio: generationStore.aspectRatio,
              imageName: task.image_name || '生成图像',
              loading: true,
              taskId: taskId,
              resolution: task.resolution || '',
              isVideo: isVideoTask,
              statusText: '队列中…',
              progress: null,
              createdAt: Date.now()
            }
          })

          // Use WebSocket to wait for task
          generationStore.waitForTask(taskId, (p) => {
            const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(taskId))
            if (!currentMsg || !currentMsg.pendingTool) return
            const st = p && typeof p === 'object' ? (p.status || '') : ''
            const prog = p && typeof p === 'object' ? p.progress : null
            currentMsg.pendingTool.progress = (typeof prog === 'number' ? prog : null)
            if (st === 'queued') {
              currentMsg.pendingTool.statusText = '队列中…'
            } else if (st === 'processing') {
              if (typeof prog === 'number' && isFinite(prog)) {
                const pct = Math.max(0, Math.min(100, Math.round(prog)))
                currentMsg.pendingTool.statusText = `生成中 ${pct}%…`
              } else {
                currentMsg.pendingTool.statusText = '生成中…'
              }
            }
          }).then(result => {
            const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(taskId))
            if (!currentMsg) return

            const firstVideo = (Array.isArray(result.videos) && result.videos.length > 0) ? result.videos[0] : null
            const videoUrl = firstVideo?.url || ''
            const imageUrls = Array.isArray(result.images)
              ? result.images.map(it => (it?.url || (it?.b64 ? `data:image/png;base64,${it.b64}` : ''))).filter(Boolean)
              : []
            const uniqImageUrls = Array.from(new Set(imageUrls))
            const imgUrl = uniqImageUrls[0] || ''
            const finalUrl = videoUrl || imgUrl
            const imagesCount = uniqImageUrls.length

            const pendingName = currentMsg.pendingTool?.imageName
            currentMsg.thinking = false
            currentMsg.pendingTool = null
            currentMsg.toolResult = {
              image_url: imgUrl,
              video_url: videoUrl,
              image_name: result.image_name || pendingName || (messages.value.find(m => m.role === 'user')?.content || (videoUrl ? '生成视频' : '生成图像')).slice(0, 20),
              aspect_ratio: generationStore.aspectRatio || '',
              resolution: videoUrl ? (generationStore.videoResolution || '') : ((generationStore.width && generationStore.height) ? `${generationStore.width}x${generationStore.height}` : (result.size || '')),
              count: videoUrl ? 1 : 1,
              model_identity: result.model_identity || result.model || result.model_id || '',
              model_name: result.model_name || ''
            }
            const summary = buildToolResultSummary(currentMsg.toolResult)
            if (summary) currentMsg.content = summary
            const thinkingMsg = messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
            if (thinkingMsg) {
              thinkingMsg.thinkingAction = '任务已经完成'
              thinkingMsg.awaitingTask = false
              thinkingMsg.thinkingDone = true
              thinkingMsg.thinking = false
              const joined = Array.isArray(thinkingMsg.thinkingSteps) ? thinkingMsg.thinkingSteps.join('\n') : ''
              if (!thinkingMsg.thinkingPersisted && joined && currentConversationId.value) {
                thinkingMsg.thinkingPersisted = true
                request.post('/api/v1/conversation/thinking/save', {
                  conversation_id: currentConversationId.value,
                  message_id: thinkingMsg.id,
                  thinking_action: thinkingMsg.thinkingAction,
                  thinking_steps: thinkingMsg.thinkingSteps
                }).catch(() => {})
              }
            }
            if (imgUrl) {
              resolveImageResolution(imgUrl).then(dim => {
                const m = messages.value.find(mm => String(mm.id) === String(currentMsg.id))
                if (dim && m && m.toolResult) {
                  m.toolResult.resolution = (dim.w && dim.h) ? `${dim.w}x${dim.h}` : (m.toolResult.resolution || '')
                  const nextSummary = buildToolResultSummary(m.toolResult)
                  if (nextSummary) m.content = nextSummary
                }
              })
            }

            if (!videoUrl && imagesCount > 1) {
              const baseId = String(currentMsg.id)
              const baseName = currentMsg.toolResult?.image_name || '生成图像'
              const baseResolution = currentMsg.toolResult?.resolution || ''
              const baseAspectRatio = currentMsg.toolResult?.aspect_ratio || ''
              for (let i = 1; i < imagesCount; i++) {
                const u = uniqImageUrls[i]
                const nextId = `${baseId}_${i + 1}`
                const nextMsg = {
                  id: nextId,
                  role: 'assistant',
                  content: '',
                  timestamp: Date.now() + i,
                  toolResult: {
                    image_url: u,
                    image_name: baseName,
                    aspect_ratio: baseAspectRatio,
                    resolution: baseResolution
                  }
                }
                const s = buildToolResultSummary(nextMsg.toolResult)
                if (s) nextMsg.content = s
                messages.value.push(nextMsg)
                if (u) {
                  resolveImageResolution(u).then(dim => {
                    const m = messages.value.find(mm => String(mm.id) === String(nextId))
                    if (dim && m && m.toolResult) {
                      m.toolResult.resolution = (dim.w && dim.h) ? `${dim.w}x${dim.h}` : (m.toolResult.resolution || '')
                      const nextSummary = buildToolResultSummary(m.toolResult)
                      if (nextSummary) m.content = nextSummary
                    }
                  })
                }
              }
            }

            const organized = (typeof result.organized_reply === 'string' ? result.organized_reply : '').trim()
            if (organized) {
              const existed = messages.value.some(m => m && m.s5TaskId && String(m.s5TaskId) === String(taskId))
              if (!existed) {
                messages.value.push({
                  id: `s5_${taskId}`,
                  role: 'assistant',
                  content: organized,
                  timestamp: Date.now(),
                  s5TaskId: taskId
                })
              }
            }

            if (finalUrl) {
              if (imgUrl) generationStore.generatedImage = imgUrl
              const lastUrl = (!videoUrl && imagesCount > 0) ? uniqImageUrls[imagesCount - 1] : finalUrl
              upsertConversationItem(currentConversationId.value, (old) => ({
                ...old,
                last_image_url: lastUrl,
                last_image_thumb_url: lastUrl,
                updated_at: new Date().toISOString().replace('T',' ').slice(0,19)
              }))
              fetchConversations()
            }
            streamTicker.value++
          }).catch(err => {
            const currentMsg = messages.value.find(m => m.pendingTool && String(m.pendingTool.taskId) === String(taskId))
            if (currentMsg) {
              currentMsg.thinking = false
              currentMsg.pendingTool = null
              currentMsg.isError = true
              currentMsg.content = err.message || '生成任务失败'
              streamTicker.value++
            }
            
            // Fix: Also reset the thinking state of the main assistant message when task fails
            const thinkingMsg = messages.value.find(m => m.id === assistantId) || messages.value.find(m => m.role === 'assistant' && m.thinking)
            if (thinkingMsg) {
              thinkingMsg.thinkingAction = '任务失败'
              thinkingMsg.awaitingTask = false
              thinkingMsg.thinkingDone = true
              thinkingMsg.thinking = false
              streamTicker.value++
            }
          })
        } else if (obj.type === 'error') {
          wsManager.off('chat_update', handleChatUpdate)
          finishRequest()
          const target = msg || messages.value.find(m => m.id === assistantId)
          if (target) {
            target.thinking = false
            target.pendingTool = null
            target.isError = true
            target.content = obj.msg || obj.message || '请求失败'
            
            // Check for insufficient points
            if (target.content && target.content.includes('点数不足')) {
                target.isInsufficientPoints = true
            }
            
            streamTicker.value++
          }
        }
      }

      wsManager.on('chat_update', handleChatUpdate)
      const payload = {
        type: 'chat',
        chat_id: chatInteractionId,
        messages: apiMessages,
        options
      }
      wsManager.send(payload)
      generationStore.setReferences([])

      // Timeout safety
      setTimeout(() => {
        wsManager.off('chat_update', handleChatUpdate)
        finishRequest()
      }, 600000)


    } catch (error) {
      console.error('Chat error:', error)
      const msg = messages.value.find(m => m.id === assistantId)
      const em = (error && error.message) ? error.message : (error && error.response && error.response.data && error.response.data.msg ? error.response.data.msg : '')
      if (msg) {
        msg.content = em || '请求失败'
        msg.isError = true
        if (msg.content && msg.content.includes('点数不足')) {
            msg.isInsufficientPoints = true
        }
        streamTicker.value++
      } else {
        messages.value.push({
          id: Date.now() + 2,
          role: 'assistant',
          content: em || '请求失败',
          timestamp: Date.now(),
          isError: true,
          isInsufficientPoints: (em && typeof em === 'string' && em.includes('点数不足'))
        })
        streamTicker.value++
      }
      ElMessage.error(em || '发送消息失败')
      finishRequest()
    }
  }

  const fetchConversations = async (loadMore = false) => {
    if (loadMore && !hasMore.value) return
    if (isLoadingMore.value) return

    isLoadingMore.value = true
    try {
      const page = loadMore ? currentPage.value + 1 : 1
      const resp = await request.get('/api/v1/conversation/list', {
        params: { page, page_size: 20 }
      })
      const json = resp.data
      if (json.code === 200) {
        const items = (json.data?.items || []).map(it => ({
          ...it,
          id: it.conversation_id || it.id
        }))
        
        if (loadMore) {
          // Use spread to ensure reactivity triggers properly and create a new array reference
          conversations.value = [...conversations.value, ...items]
          currentPage.value = page
        } else {
          conversations.value = items
          currentPage.value = 1
        }
        
        if (json.data && typeof json.data.has_more !== 'undefined') {
            hasMore.value = !!json.data.has_more
        } else {
            // Fallback if backend doesn't return has_more
            hasMore.value = items.length >= 20
        }
      }
    } catch (e) { 
        console.error('Fetch conversations error:', e)
    } finally {
        isLoadingMore.value = false
    }
  }

  const recoverPendingTasks = () => {
    const generationStore = useGenerationStore()
    
    // Iterate through current reactive messages
    for (const msg of messages.value) {
        if (msg.role === 'assistant' && msg.pendingTool && msg.pendingTool.taskId) {
            const taskId = msg.pendingTool.taskId
            
            generationStore.waitForTask(taskId, (p) => {
                if (!msg.pendingTool) return
                const st = p && typeof p === 'object' ? (p.status || '') : ''
                const prog = p && typeof p === 'object' ? p.progress : null
                msg.pendingTool.progress = (typeof prog === 'number' ? prog : null)
                if (st === 'queued') {
                  msg.pendingTool.statusText = '队列中…'
                } else if (st === 'processing') {
                  if (typeof prog === 'number' && isFinite(prog)) {
                    const pct = Math.max(0, Math.min(100, Math.round(prog)))
                    msg.pendingTool.statusText = `生成中 ${pct}%…`
                  } else {
                    msg.pendingTool.statusText = '生成中…'
                  }
                }
            }).then(res => {
                // Now we are updating the REACTIVE msg object directly
                const firstVideo = (Array.isArray(res.videos) && res.videos.length > 0) ? res.videos[0] : null
                const videoUrl = firstVideo?.url || ''
                const first = (Array.isArray(res.images) && res.images.length > 0) ? res.images[0] : null
                const imgUrl = first?.url || (first?.b64 ? (`data:image/png;base64,${first.b64}`) : '')
                
                const pendingInfo = { ...msg.pendingTool }
                msg.thinking = false
                msg.pendingTool = null
                msg.toolResult = {
                  image_url: imgUrl,
                  video_url: videoUrl,
                  image_name: pendingInfo.imageName || (videoUrl ? '生成视频' : '生成图像'),
                  aspect_ratio: pendingInfo.aspectRatio || '',
                  resolution: (res.size) ? res.size : '',
                  count: videoUrl ? 1 : (Array.isArray(res.images) ? res.images.length : 1),
                  model_identity: res.model_identity || res.model || res.model_id || '',
                  model_name: res.model_name || ''
                }
                const summary = buildToolResultSummary(msg.toolResult)
                if (summary) msg.content = summary

                const organized = (typeof res.organized_reply === 'string' ? res.organized_reply : '').trim()
                if (organized) {
                  const existed = messages.value.some(m => m && m.s5TaskId && String(m.s5TaskId) === String(taskId))
                  if (!existed) {
                    messages.value.push({
                      id: `s5_${taskId}`,
                      role: 'assistant',
                      content: organized,
                      timestamp: Date.now(),
                      s5TaskId: taskId
                    })
                  }
                }
                
                // Update conversation cover
                if (videoUrl || imgUrl) {
                    const finalUrl = videoUrl || imgUrl
                    upsertConversationItem(currentConversationId.value, (old) => ({
                        ...old,
                        last_image_url: finalUrl,
                        last_image_thumb_url: finalUrl,
                        updated_at: new Date().toISOString().replace('T',' ').slice(0,19)
                    }))
                }

                // Sync to Canvas
                if (currentCanvasState.value) {
                    const canvas = [...currentCanvasState.value]
                    const idx = canvas.findIndex(el => el.taskId === taskId)
                    if (idx >= 0) {
                        const el = canvas[idx]
                        if (pendingInfo.isVideo) {
                            el.generatedVideoUrl = videoUrl
                            el.src = videoUrl
                            if (el.type === 'video-generator') el.type = 'video' 
                        } else {
                            el.src = imgUrl
                            if (el.type === 'image-generator') el.type = 'image'
                        }
                        el.loading = false
                        el.taskId = null
                        currentCanvasState.value = canvas
                    }
                }
            }).catch(err => {
                // Check if the next message OR PREVIOUS message is already an error/insufficient points message
                // If so, remove this pending message to avoid duplication
                const msgIdx = messages.value.findIndex(m => m.id === msg.id)
                if (msgIdx !== -1) {
                    // Check Next
                    if (msgIdx < messages.value.length - 1) {
                        const nextMsg = messages.value[msgIdx + 1]
                        const isNextError = nextMsg.role === 'assistant' && (
                            nextMsg.isInsufficientPoints || 
                            (typeof nextMsg.content === 'string' && (nextMsg.content.includes('点数不足') || nextMsg.content.includes('失败')))
                        )
                        if (isNextError) {
                            messages.value.splice(msgIdx, 1)
                            return
                        }
                    }
                    // Check Previous
                    if (msgIdx > 0) {
                        const prevMsg = messages.value[msgIdx - 1]
                        const isPrevError = prevMsg.role === 'assistant' && (
                            prevMsg.isInsufficientPoints || 
                            (typeof prevMsg.content === 'string' && (prevMsg.content.includes('点数不足') || prevMsg.content.includes('失败')))
                        )
                        if (isPrevError) {
                            messages.value.splice(msgIdx, 1)
                            return
                        }
                    }
                }

                msg.thinking = false
                msg.pendingTool = null
                msg.isError = true
                msg.content = `(任务失败: ${err.message})`
                if (msg.content && msg.content.includes('点数不足')) {
                    msg.isInsufficientPoints = true
                }
            })
        }
    }
  }

  const processLoadedMessages = (msgs) => {
    if (!Array.isArray(msgs)) return []
    const processed = []
    const toolCallMap = {} // map tool_call_id to assistant message

    for (const m of msgs) {
      // Create a reactive copy
      const msg = { ...m }
      
      // If assistant has tool_calls, map them
      if (msg.role === 'assistant' && Array.isArray(msg.tool_calls)) {
        for (const tc of msg.tool_calls) {
           if (tc.id !== undefined && tc.id !== null) toolCallMap[tc.id] = msg
        }
      }
      
      // If tool result, try to attach to assistant message
      if (msg.role === 'tool' && msg.tool_call_id !== undefined && msg.tool_call_id !== null && toolCallMap[msg.tool_call_id]) {
         const assistantMsg = toolCallMap[msg.tool_call_id]
         // Find the specific tool call definition
         let toolDef = assistantMsg.tool_calls && Array.isArray(assistantMsg.tool_calls) 
            ? assistantMsg.tool_calls.find(tc => tc.id === msg.tool_call_id)
            : null
            
         // Fallback: If tool definition is missing (e.g. truncated history), create a dummy one to allow task recovery
         if (!toolDef) {
             toolDef = {
                 function: {
                     name: 'unknown',
                     arguments: '{}'
                 }
             }
         }

         let args = {}
         try { args = toolDef.function && toolDef.function.arguments ? JSON.parse(toolDef.function.arguments) : {} } catch (e) {}
         
         let result = null
         try { result = typeof msg.content === 'string' ? JSON.parse(msg.content) : msg.content } catch (e) { result = { error: msg.content } }
         
         // If queued, set pendingTool
         if (result && result.status === 'queued' && result.task_id) {
            // Check if assistant message already contains error/insufficient points
            // If so, do not set pendingTool to avoid duplication
            if (assistantMsg.content && (assistantMsg.content.includes('点数不足') || assistantMsg.content.includes('失败'))) {
                // Skip
            } else {
                const taskId = result.task_id
                const isVideo = (args.task_type === 'TEXT_TO_VIDEO' || args.task_type === 'IMAGE_TO_VIDEO')
                
                // Avoid overwriting if multiple tools (though usually 1 per msg for generation)
                assistantMsg.pendingTool = {
                  name: toolDef.function.name,
                  modelName: args.model_identity || 'Model',
                  imageName: args.image_name || (isVideo ? '生成视频' : '生成图像'),
                  loading: true,
                  taskId: taskId,
                  isVideo: isVideo,
                  // Try to recover settings
                  width: args.width || 1024,
                  height: args.height || 1024,
                  aspectRatio: args.aspectRatio || '1:1'
                }
                
                // We do NOT call waitForTask here. We let recoverPendingTasks do it after messages are reactive.
            }
         } 
         // If already success/failed (historical), set toolResult
         else if (result && (result.status === 'success' || result.images || result.videos)) {
            const firstVideo = (Array.isArray(result.videos) && result.videos.length > 0) ? result.videos[0] : null
            const videoUrl = firstVideo?.url || ''
            const first = (Array.isArray(result.images) && result.images.length > 0) ? result.images[0] : null
            const imgUrl = first?.url || (first?.b64 ? (`data:image/png;base64,${first.b64}`) : '')
            
            if (imgUrl || videoUrl) {
                assistantMsg.toolResult = {
                  image_url: imgUrl,
                  video_url: videoUrl,
                  image_name: args.image_name || (videoUrl ? '生成视频' : '生成图像'),
                  aspect_ratio: args.aspect_ratio || '',
                }
            }
         }
         // We do NOT push the 'tool' message to the display list because ChatPanel merges it into Assistant bubble
         // UNLESS you want to debug.
         // Standard ChatPanel logic seems to hide tool messages? 
         // No, ChatPanel iterates all messages.
         // If we attach result to Assistant, we should probably SKIP adding the tool message to 'processed'
         // to avoid duplicate bubbles or empty bubbles.
         // BUT, we must keep the original structure for subsequent saves?
         // Actually, ChatPanel filters what to show.
         // Lines 23: v-show="shouldShowMessage(msg)"
         // Let's check shouldShowMessage in ChatPanel.
         // It's not visible in my previous read (it was <template>).
         // I'll assume we should keep it but maybe mark it hidden?
         // For now, I'll keep it.
      }
      
      processed.push(msg)
    }
    return processed
  }

  const loadConversation = async (id) => {
    loadingCount.value++
    let loadingActive = true
    const finishLoading = () => {
      if (!loadingActive) return
      loadingActive = false
      loadingCount.value--
    }
    try {
      // Clear current state to avoid bleeding into new conversation
      // But we need to be careful not to trigger "save empty" in the component
      // The component handles this via isRemoteUpdate logic hopefully, or we rely on the component clearing its timer
      currentConversationId.value = id
      currentCanvasState.value = null // Reset canvas immediately
      messages.value = [
        { id: Date.now(), role: 'assistant', content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！', timestamp: Date.now() }
      ]
      streamTicker.value++
      
      const resp = await request.get('/api/v1/conversation/messages', {
        params: { conversation_id: id }
      })
      const json = resp.data
      if (json.code === 200) {
        if (json.data?.is_deleted) {
           ElMessageBox.confirm(
             '此对话已删除，是否创建新会话？',
             '提示',
             {
               confirmButtonText: '创建新会话',
               cancelButtonText: '取消',
               type: 'warning',
             }
           )
             .then(() => {
               currentConversationId.value = null
               messages.value = [
                 { id: Date.now(), role: 'assistant', content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！', timestamp: Date.now() }
               ]
               streamTicker.value++
             })
             .catch(() => {
               currentConversationId.value = null
               messages.value = []
               streamTicker.value++
             })
           return
        }
        const msgs = Array.isArray(json.data?.messages) ? json.data.messages : []
        
        // Process messages to restore pending tasks and tool results
        let processed = msgs.length ? processLoadedMessages(msgs) : [
          { id: Date.now(), role: 'assistant', content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！', timestamp: Date.now() }
        ]

        // Deduplicate consecutive insufficient points messages
        processed = processed.filter((m, i) => {
            if (i === 0) return true
            const prev = processed[i-1]
            const isErr = (msg) => msg.role === 'assistant' && typeof msg.content === 'string' && msg.content.includes('点数不足')
            if (isErr(m) && isErr(prev)) return false
            return true
        })

        messages.value = processed

        try {
          const thResp = await request.get('/api/v1/conversation/thinking', { params: { conversation_id: id } })
          const thJson = thResp.data
          if (thJson && thJson.code === 200) {
            const items = Array.isArray(thJson.data?.items) ? thJson.data.items : []
            if (items.length > 0) {
              for (const it of items) {
                const msgId = it?.message_id
                if (!msgId) continue
                const exists = messages.value.find(m => String(m.id) === String(msgId))
                if (exists) {
                  exists.thinkingSteps = Array.isArray(it.thinking_steps) ? it.thinking_steps : []
                  exists.thinkingAction = typeof it.thinking_action === 'string' ? it.thinking_action : ''
                  exists.thinkingDone = true
                  exists.isThinkingTrace = true
                  continue
                }
                const steps = Array.isArray(it.thinking_steps) ? it.thinking_steps : []
                const action = typeof it.thinking_action === 'string' ? it.thinking_action : ''
                const msgIdNum = isFinite(Number(msgId)) ? Number(msgId) : NaN
                const tsFromId = isFinite(msgIdNum) ? (msgIdNum < 1000000000000 ? msgIdNum * 1000 : msgIdNum) : NaN
                const createdAt = it.created_at ? Date.parse(String(it.created_at).replace(' ', 'T')) : NaN
                const updatedAt = it.updated_at ? Date.parse(String(it.updated_at).replace(' ', 'T')) : NaN
                const ts = [tsFromId, createdAt, updatedAt].find(v => isFinite(v)) ?? Date.now()
                const toMs = (v) => {
                  const n = typeof v === 'number' ? v : (isFinite(Number(v)) ? Number(v) : NaN)
                  if (!isFinite(n)) return NaN
                  return n < 1000000000000 ? n * 1000 : n
                }
                const synth = {
                  id: msgId,
                  role: 'assistant',
                  content: '',
                  thinking: false,
                  thinkingDone: true,
                  thinkingAction: action,
                  thinkingSteps: steps,
                  isThinkingTrace: true,
                  timestamp: ts
                }
                const toolIndices = []
                for (let i = 0; i < messages.value.length; i++) {
                  const m = messages.value[i]
                  if (m.role === 'assistant' && m.toolResult) {
                    const mt = toMs(m.timestamp)
                    if (isFinite(mt)) toolIndices.push({ i, t: mt })
                  }
                }
                if (toolIndices.length > 0) {
                  const afterIdx = toolIndices.findIndex(x => x.t >= ts)
                  if (afterIdx >= 0) {
                    messages.value.splice(toolIndices[afterIdx].i, 0, synth)
                  } else {
                    const last = toolIndices[toolIndices.length - 1]
                    messages.value.splice(last.i + 1, 0, synth)
                  }
                } else {
                  messages.value.push(synth)
                }
              }
            }
          }
        } catch (e) { /* ignore */ }

        // Start polling for any pending tasks found
        recoverPendingTasks()
        
        if (json.data?.canvas) {
           currentCanvasState.value = json.data.canvas
        } else {
           currentCanvasState.value = null
        }

        streamTicker.value++

        // Set prompt to last user message for convenience
        // try {
        //   const generationStore = useGenerationStore()
        //   generationStore.resetWorkspace() 
          
        //   for (let i = messages.value.length - 1; i >= 0; i--) {
        //     const m = messages.value[i]
        //     if (m.role === 'user' && typeof m.content === 'string' && m.content.trim()) {
        //       generationStore.prompt = m.content.trim()
        //       break
        //     }
        //   }
        //   streamTicker.value++
        // } catch (e) { /* ignore */ }
      }
      return true
    } catch (e) {
      console.error('Load conversation error:', e)
      return false
    } finally {
      finishLoading()
    }
  }

  const saveCanvasState = async (id, canvasData) => {
    try {
       await request.post('/api/v1/conversation/save_canvas', {
         conversation_id: id,
         canvas_json: canvasData
       })
    } catch (e) { console.error('Failed to save canvas', e) }
  }

  const clearConversationMessages = async (id) => {
    const convId = id ?? currentConversationId.value
    if (!convId) return false
    try {
      const resp = await request.post('/api/v1/conversation/clear_messages', {
        conversation_id: convId
      })
      const json = resp.data
      if (json.code === 200) {
        if (String(currentConversationId.value) === String(convId)) {
          messages.value = [
            { id: Date.now(), role: 'assistant', content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！', timestamp: Date.now() }
          ]
          streamTicker.value++
        }
        return true
      } else {
        ElMessage.error(json.msg || '清空失败')
        return false
      }
    } catch (e) {
      ElMessage.error('清空请求失败')
      return false
    }
  }

  const deleteConversation = async (id) => {
    try {
      const resp = await request.post('/api/v1/conversation/delete', {
        conversation_id: id
      })
      const json = resp.data
      if (json.code === 200) {
        const idx = conversations.value.findIndex(it => String(it.id) === String(id))
        if (idx >= 0) conversations.value.splice(idx, 1)
        if (String(currentConversationId.value) === String(id)) {
          currentConversationId.value = null
          messages.value = [
            { id: Date.now(), role: 'assistant', content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！', timestamp: Date.now() }
          ]
          streamTicker.value++
        }
        ElMessage.success('对话已删除')
        return true
      } else {
        ElMessage.error(json.msg || '删除失败')
        return false
      }
    } catch (e) {
      ElMessage.error('删除请求失败')
      return false
    }
  }
  
  const deleteMessage = async (messageId) => {
    const convId = currentConversationId.value
    if (!convId) {
      const idx = messages.value.findIndex(m => String(m.id) === String(messageId))
      if (idx >= 0) {
        messages.value.splice(idx, 1)
        streamTicker.value++
      }
      return true
    }
    try {
      const resp = await request.post('/api/v1/conversation/delete_message', {
        conversation_id: convId,
        message_id: messageId
      })
      const json = resp.data
      if (json.code === 200) {
        const idx = messages.value.findIndex(m => String(m.id) === String(messageId))
        if (idx >= 0) {
          messages.value.splice(idx, 1)
          streamTicker.value++
        }
        ElMessage.success('消息已删除')
        return true
      } else {
        ElMessage.error(json.msg || '删除消息失败')
        return false
      }
    } catch (e) {
      ElMessage.error('删除请求失败')
      return false
    }
  }

  const deleteThinking = async (messageId) => {
    const convId = currentConversationId.value
    if (!convId) {
      const idx = messages.value.findIndex(m => String(m.id) === String(messageId))
      if (idx >= 0) {
        messages.value.splice(idx, 1)
        streamTicker.value++
      }
      return true
    }
    try {
      const resp = await request.post('/api/v1/conversation/thinking/delete', {
        conversation_id: convId,
        message_id: messageId
      })
      const json = resp.data
      if (json.code === 200) {
        const idx = messages.value.findIndex(m => String(m.id) === String(messageId))
        if (idx >= 0) {
          messages.value.splice(idx, 1)
          streamTicker.value++
        }
        ElMessage.success('思考步骤已删除')
        return true
      } else {
        ElMessage.error(json.msg || '删除思考步骤失败')
        return false
      }
    } catch (e) {
      ElMessage.error('删除请求失败')
      return false
    }
  }

  const resetCurrentState = () => {
    currentConversationId.value = null
    currentCanvasState.value = null
    messages.value = [
      {
        id: Date.now(),
        role: 'assistant',
        content: 'Hi，我是你的专属设计师，现在你可以告诉我你的需求，我会尽我所能帮你完成！',
        timestamp: Date.now()
      }
    ]
    currentPage.value = 1
    hasMore.value = true
  }

  return {
    messages,
    currentConversationId,
    currentCanvasState,
    conversations,
    currentPage,
    hasMore,
    isLoadingMore,
    isLoading,
    isStreaming,
    streamTicker,
    sendMessage,
    appendAssistantMessage,
    appendToolResultMessage,
    trackExternalImageTask,
    fetchConversations,
    loadConversation,
    saveCanvasState,
    clearConversationMessages,
    deleteConversation,
    deleteMessage,
    deleteThinking,
    resetCurrentState,
    upsertConversationItem
  }
}) 
