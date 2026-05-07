import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'
import { ElMessage } from 'element-plus'
import { wsManager } from '@/utils/websocket'
import { config } from '@/config'

export const useGenerationStore = defineStore('generation', () => {
  const prompt = ref('')
  const isGenerating = ref(false)
  const isGeneratingVideo = ref(false)
  const generatedImage = ref('')
  const error = ref(null)
  const selectedImageModel = ref('')
  const imageModels = ref([])
  const videoModels = ref([])
  
  // 初始化 WebSocket
  const initWebSocket = (token) => {
    const wsUrl = config.WS_URL
    wsManager.connect(wsUrl, token)
  }

  // 等待任务完成的通用方法
  const waitForTask = async (taskId, onProgress) => {
    // 1. First, check status immediately via API
    let apiData = null
    try {
        // Try image task endpoint first
        let res = await request.get(`/api/v1/image/task/${taskId}`).catch(() => ({ data: { code: 404 } }))
        
        // If not found, try video task endpoint
        if (!res.data || res.data.code === 404) {
            res = await request.get(`/api/v1/video/task/${taskId}`).catch(() => ({ data: { code: 404 } }))
        }

        if (res.data && res.data.code === 200) {
            apiData = res.data.data
        }
    } catch (e) {
        // Ignore API errors and fallback to WebSocket
        console.warn('Task status check failed, falling back to WS', e)
    }

    if (apiData) {
        if ((apiData.status === 'queued' || apiData.status === 'processing') && onProgress) {
            onProgress({ status: apiData.status, progress: apiData.progress ?? null })
        }
        if (apiData.status === 'success') {
            return apiData.result
        } else if (apiData.status === 'failed') {
            throw new Error(apiData.error || '生成失败')
        }
        // If queued or processing, continue to WebSocket
    }

    return new Promise((resolve, reject) => {
      const handler = (data) => {
        if (data.task_id === taskId) {
          if ((data.status === 'queued' || data.status === 'processing') && onProgress) {
            onProgress({ status: data.status, progress: data.progress ?? null })
          }
          
          if (data.status === 'success') {
            wsManager.off('task_update', handler)
            resolve(data.result)
          } else if (data.status === 'failed') {
            wsManager.off('task_update', handler)
            reject(new Error(data.error || '生成失败'))
          }
        }
      }
      wsManager.on('task_update', handler)
      
      // 超时处理
      setTimeout(() => {
        wsManager.off('task_update', handler)
        reject(new Error('生成超时，请在历史记录中查看'))
      }, 600000)
    })
  }
  
  // Video settings
  const videoDuration = ref('5s')
  const videoResolution = ref('720p')
  const videoAspectRatio = ref('16:9')

  // Image settings
  const imageResolution = ref('')

  const poseEditPrompt = ref('')
  const eraseToolPrompt = ref('')
  const systemPromptsFetchedAt = ref(0)

  try {
    const raw = localStorage.getItem('app_system_prompts')
    if (raw) {
      const data = JSON.parse(raw)
      if (data && typeof data === 'object') {
        if (typeof data.pose_edit_prompt === 'string') poseEditPrompt.value = data.pose_edit_prompt
        if (typeof data.erase_tool_prompt === 'string') eraseToolPrompt.value = data.erase_tool_prompt
        if (typeof data.fetched_at === 'number') systemPromptsFetchedAt.value = data.fetched_at
      }
    }
  } catch {}

  const fetchSystemPrompts = async (force = false) => {
    const now = Date.now()
    if (!force && systemPromptsFetchedAt.value && (now - systemPromptsFetchedAt.value) < 5 * 60 * 1000) {
      return { pose_edit_prompt: poseEditPrompt.value || '', erase_tool_prompt: eraseToolPrompt.value || '' }
    }
    try {
      const res = await request.get('/api/public/system_prompts')
      if (res.data?.code === 200) {
        const data = res.data.data || {}
        poseEditPrompt.value = typeof data.pose_edit_prompt === 'string' ? data.pose_edit_prompt : ''
        eraseToolPrompt.value = typeof data.erase_tool_prompt === 'string' ? data.erase_tool_prompt : ''
        systemPromptsFetchedAt.value = now
        try {
          localStorage.setItem('app_system_prompts', JSON.stringify({
            pose_edit_prompt: poseEditPrompt.value,
            erase_tool_prompt: eraseToolPrompt.value,
            fetched_at: systemPromptsFetchedAt.value
          }))
        } catch {}
        return { pose_edit_prompt: poseEditPrompt.value || '', erase_tool_prompt: eraseToolPrompt.value || '' }
      }
    } catch {}
    return { pose_edit_prompt: poseEditPrompt.value || '', erase_tool_prompt: eraseToolPrompt.value || '' }
  }

  const getPoseEditPrompt = async () => {
    if (poseEditPrompt.value) return poseEditPrompt.value
    const data = await fetchSystemPrompts(false)
    return data.pose_edit_prompt || ''
  }

  const getEraseToolPrompt = async () => {
    if (eraseToolPrompt.value) return eraseToolPrompt.value
    const data = await fetchSystemPrompts(false)
    return data.erase_tool_prompt || ''
  }

  const savePoseHistory = async ({ poseJson, thumbnailUrl } = {}) => {
    const pose_json = typeof poseJson === 'string' ? poseJson : ''
    const thumbnail_url = typeof thumbnailUrl === 'string' ? thumbnailUrl : ''
    if (!pose_json.trim()) return false
    try {
      const res = await request.post('/api/v1/pose-history/save', { pose_json, thumbnail_url })
      return res.data?.code === 200
    } catch {
      return false
    }
  }

  const fetchPoseHistory = async (page = 1, pageSize = 20) => {
    try {
      const res = await request.get('/api/v1/pose-history/list', {
        params: { page, page_size: pageSize }
      })
      if (res.data?.code === 200) return res.data?.data || { items: [] }
    } catch {}
    return { items: [] }
  }

  const pendingPoseHistoryLastUsedId = ref(0)

  const setPendingPoseHistoryLastUsed = (id) => {
    const n = Number(id)
    pendingPoseHistoryLastUsedId.value = Number.isFinite(n) && n > 0 ? Math.trunc(n) : 0
  }

  const updatePoseHistoryLastUsed = async (id) => {
    const n = Number(id)
    const poseId = Number.isFinite(n) && n > 0 ? Math.trunc(n) : 0
    if (!poseId) return false
    try {
      const res = await request.post('/api/v1/pose-history/update-last-used', { id: poseId })
      return res.data?.code === 200 && !!res.data?.data?.updated
    } catch {
      return false
    }
  }

  const consumePendingPoseHistoryLastUsed = async () => {
    const id = pendingPoseHistoryLastUsedId.value
    if (!id) return false
    const ok = await updatePoseHistoryLastUsed(id)
    if (ok) pendingPoseHistoryLastUsedId.value = 0
    return ok
  }

  // Advanced settings
  const generationMode = ref('single') // 'single' | 'group'
  const quality = ref('2k') // '2k' | '4k'
  const aspectRatio = ref('1:1')
  const width = ref(2048)
  const height = ref(2048)
  const imageCount = ref(1)
  const style = ref('default')
  const referenceImages = ref([])
  const lastSnapshot = ref(null)
  const historyPage = ref(1)
  const historyPageSize = ref(20)
  
  // Marker Prompts
  const markerPrompts = ref([]) // Array of { id, number, content, status: 'loading' | 'done' }
  
  const addMarkerPrompt = (marker) => {
    // Check if exists
    const index = markerPrompts.value.findIndex(m => m.number === marker.number)
    if (index > -1) {
      markerPrompts.value[index] = { ...markerPrompts.value[index], ...marker }
    } else {
      markerPrompts.value.push(marker)
    }
    markerPrompts.value.sort((a, b) => a.number - b.number)
  }
  
  const removeMarkerPrompt = (number) => {
    markerPrompts.value = markerPrompts.value.filter(m => m.number !== number)
  }
  
  const updateMarkerPrompt = (number, content) => {
     const marker = markerPrompts.value.find(m => m.number === number)
     if (marker) {
       marker.content = content
       marker.status = 'done'
     }
  }

  const updateMarkerPromptDetails = (number, patch) => {
    const marker = markerPrompts.value.find(m => m.number === number)
    if (!marker || !patch || typeof patch !== 'object') return
    Object.assign(marker, patch)
    if (Object.prototype.hasOwnProperty.call(patch, 'content')) {
      marker.status = marker.status || 'done'
      if (marker.status === 'loading') marker.status = 'done'
    }
  }

  const updateMarkerThumbnail = (number, thumbnail) => {
    const marker = markerPrompts.value.find(m => m.number === number)
    if (marker) {
      marker.thumbnail = thumbnail
    }
  }

  const clearMarkerPrompts = () => {
    markerPrompts.value = []
  }

  const setPrompt = (val) => {
    prompt.value = val
  }
  const historyHasMore = ref(true)
  const historyLoading = ref(false)

  const history = ref([
    { id: 1, prompt: 'A futuristic city with flying cars', imageUrl: 'https://picsum.photos/seed/1/800/800', timestamp: Date.now() },
    { id: 2, prompt: 'A cute cat sitting on a robot', imageUrl: 'https://picsum.photos/seed/2/800/800', timestamp: Date.now() - 100000 },
    { id: 3, prompt: 'Abstract painting of emotions', imageUrl: 'https://picsum.photos/seed/3/800/800', timestamp: Date.now() - 200000 },
  ])

  const submitImageTask = async (newPrompt, customOptions = {}, modelIdentity = null) => {
    await uploadPendingReferences()
    const options = {
      n: imageCount.value,
      quality: quality.value,
      style: style.value,
      reference_images: referenceImages.value.filter(x => typeof x === 'string'),
      sequential_image_generation: referenceImages.value.filter(x => typeof x === 'string').length > 1 ? 'auto' : undefined,
      response_format: 'url',
      resolution: imageResolution.value || undefined,
      ...customOptions
    }

    const res = await new Promise((resolve, reject) => {
      const handler = (r) => {
        wsManager.off('image_generate_res', handler)
        wsManager.off('error', errorHandler)
        if (r.data && r.data.task_id) {
          resolve(r.data.task_id)
        } else if (r.data && r.data.images && r.data.images.length > 0) {
          resolve({ direct: true, images: r.data.images })
        } else {
          reject(new Error(r.msg || '生成任务异常'))
        }
      }
      const errorHandler = (err) => {
        wsManager.off('image_generate_res', handler)
        wsManager.off('error', errorHandler)
        reject(new Error(err.msg || '生成失败'))
      }
      wsManager.on('image_generate_res', handler)
      wsManager.on('error', errorHandler)
      
      wsManager.send({
        type: 'image_generate',
        prompt: newPrompt,
        model_identity: modelIdentity ?? selectedImageModel.value,
        options: options,
        ocr_modifications: customOptions.ocr_modifications
      })

      setTimeout(() => {
        wsManager.off('image_generate_res', handler)
        wsManager.off('error', errorHandler)
        reject(new Error('提交任务超时'))
      }, 30000)
    })

    if (res && typeof res === 'object' && res.direct) {
      return { direct: true, images: Array.isArray(res.images) ? res.images : [], options }
    }
    return { taskId: res, options }
  }

  const generateImage = async (newPrompt, customOptions = {}, modelIdentity = null, updateState = true) => {
    lastSnapshot.value = {
      prompt: prompt.value,
      referenceImages: [...referenceImages.value],
      generationMode: generationMode.value,
      quality: quality.value,
      aspectRatio: aspectRatio.value,
      width: width.value,
      height: height.value,
      imageCount: imageCount.value,
      style: style.value,
      selectedImageModel: selectedImageModel.value,
      generatedImage: generatedImage.value
    }
    // 不修改输入框中的提示词，以保证“无感知”追加
    if (updateState) {
      isGenerating.value = true
      error.value = null
    }
    
    try {
      const submitted = await submitImageTask(newPrompt, customOptions, modelIdentity)

      if (submitted.direct) {
        const img = submitted.images[0]
        const newUrl = img.url || (img.b64 ? `data:image/png;base64,${img.b64}` : null)
        if (newUrl) {
          if (updateState) {
            generatedImage.value = newUrl
          }
          try {
            await consumePendingPoseHistoryLastUsed()
          } catch {}
          return { url: newUrl }
        }
      } else {
        ElMessage.success('任务已入队，开始生成')
        const result = await waitForTask(submitted.taskId)
        if (result && result.images && result.images.length > 0) {
          const img = result.images[0]
          const newUrl = img.url || (img.b64 ? `data:image/png;base64,${img.b64}` : null)
          if (newUrl) {
            if (updateState) {
              generatedImage.value = newUrl
              history.value.unshift({
                id: Date.now(),
                prompt: newPrompt,
                imageUrl: newUrl,
                timestamp: Date.now(),
                settings: {
                  selectedImageModel: modelIdentity || selectedImageModel.value,
                  generationMode: generationMode.value,
                  quality: quality.value,
                  aspectRatio: aspectRatio.value,
                  width: width.value,
                  height: height.value,
                  imageCount: imageCount.value,
                  style: style.value,
                  referenceImages: [...referenceImages.value]
                }
              })
              referenceImages.value = [newUrl]
            }
            try {
              await consumePendingPoseHistoryLastUsed()
            } catch {}
            return { url: newUrl }
          }
        }
      }
    } catch (err) {
      console.error(err)
      const uiMsg = err.message || '生成失败'
      error.value = uiMsg
      ElMessage.error(uiMsg)
      throw err
    } finally {
      isGenerating.value = false
    }
  }

  const generateVideo = async (params, onTaskCreated, onProgress) => {
    isGeneratingVideo.value = true
    try {
      const options = {
        aspect_ratio: params.aspectRatio,
        duration: params.duration,
        resolution: params.resolution,
        reference_mode: params.referenceMode,
        first_frame: params.firstFrame,
        last_frame: params.lastFrame,
        reference_images: params.referenceImages,
        reference_video: params.referenceVideo
      }
      
      // 使用 WebSocket 提交视频任务
      const taskId = await new Promise((resolve, reject) => {
        const handler = (res) => {
          wsManager.off('video_generate_res', handler)
          wsManager.off('error', errorHandler)
          if (res.data && res.data.task_id) {
            resolve(res.data.task_id)
          } else {
            reject(new Error(res.msg || '提交视频任务失败'))
          }
        }
        const errorHandler = (err) => {
          wsManager.off('video_generate_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error(err.msg || '提交失败'))
        }
        wsManager.on('video_generate_res', handler)
        wsManager.on('error', errorHandler)

        wsManager.send({
          type: 'video_generate',
          prompt: params.prompt,
          model_identity: params.model,
          options: options
        })

        setTimeout(() => {
          wsManager.off('video_generate_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error('提交视频任务超时'))
        }, 30000)
      })

      ElMessage.success('视频生成任务已提交，请耐心等待')
      if (onTaskCreated) {
        onTaskCreated(taskId)
      }
      return await waitForTask(taskId, onProgress)
    } catch (err) {
      console.error(err)
      const msg = err.message || '生成失败'
      ElMessage.error(msg)
      throw err
    } finally {
      isGeneratingVideo.value = false
    }
  }

  const recoverVideoTask = async (taskId, onProgress) => {
    return await waitForTask(taskId, onProgress)
  }

  const fetchImageModels = async () => {
    try {
      const res = await request.get('/api/v1/models', {
        params: { type: 'image' }
      })
      if (res.data?.code === 200) {
        imageModels.value = res.data.data || []
        // 默认保持“自动”状态：不主动选择具体模型
        // 当 selectedImageModel 为空字符串时，后端将使用默认激活图片模型
      }
    } catch (e) {
      console.error('Fetch image models failed', e)
    }
  }

  const fetchVideoModels = async () => {
    try {
      const res = await request.get('/api/v1/models', {
        params: { type: 'video' }
      })
      if (res.data?.code === 200) {
        videoModels.value = res.data.data || []
      }
    } catch (e) {
      console.error('Fetch video models failed', e)
    }
  }

  const setImageModel = (identity) => {
    if (!identity || identity === '__auto__') {
      selectedImageModel.value = ''
      return
    }
    selectedImageModel.value = identity
  }

  const uploadReferences = async (fileList, prepend = false, addToState = true) => {
    try {
      const form = new FormData()
      fileList.forEach(f => form.append('files[]', f))
      const res = await request.post('/api/v1/image/upload', form)
      if (res.data?.code === 200) {
        const urls = res.data?.data?.urls || []
        if (addToState) {
          if (prepend) {
            referenceImages.value = [...urls, ...referenceImages.value]
          } else {
            referenceImages.value.push(...urls)
          }
        }
        return urls
      }
    } catch (e) {
      console.error('Upload references failed', e)
      ElMessage.error('参考图上传失败')
    }
  }

  const insertLocalReference = (file, prepend = false) => {
    if (!(file instanceof File)) return
    if (prepend) {
      if (referenceImages.value.length === 0) {
        referenceImages.value = [file]
      } else {
        referenceImages.value.splice(0, 1, file)
      }
    } else {
      referenceImages.value.push(file)
    }
  }

  const uploadPendingReferences = async () => {
    const uploadQueue = []
    
    referenceImages.value.forEach((item, index) => {
      if (item instanceof File) {
        uploadQueue.push({ index, file: item })
      } else if (typeof item === 'string' && item.startsWith('data:image')) {
        try {
          const arr = item.split(',')
          const mime = arr[0].match(/:(.*?);/)[1]
          const bstr = atob(arr[1])
          let n = bstr.length
          const u8arr = new Uint8Array(n)
          while(n--){
              u8arr[n] = bstr.charCodeAt(n)
          }
          const ext = mime.split('/')[1] || 'png'
          const filename = `upload_${Date.now()}_${index}.${ext}`
          const file = new File([u8arr], filename, { type: mime })
          uploadQueue.push({ index, file })
        } catch (e) {
          console.error('Failed to convert base64 to file', e)
        }
      }
    })

    if (!uploadQueue.length) return []

    try {
      const form = new FormData()
      uploadQueue.forEach(item => form.append('files[]', item.file))
      
      const res = await request.post('/api/v1/image/upload', form)
      
      if (res.data?.code === 200) {
        const urls = res.data?.data?.urls || []
        
        // Update references with URLs
        uploadQueue.forEach((item, i) => {
            const url = urls[i]
            if (url) {
                referenceImages.value[item.index] = url
            }
        })
        
        return urls
      } else {
        throw new Error(res.data?.msg || '参考图上传失败')
      }
    } catch (e) {
      console.error('Upload pending references failed', e)
      throw e
    }
  }

  const removeReference = (url) => {
    referenceImages.value = referenceImages.value.filter(u => u !== url)
  }

  const removeReferenceAt = (index) => {
    if (index < 0 || index >= referenceImages.value.length) return
    referenceImages.value.splice(index, 1)
  }

  const setReferences = (urls) => {
    referenceImages.value = Array.isArray(urls) ? [...urls] : []
  }

  const resetWorkspace = () => {
    prompt.value = ''
    isGenerating.value = false
    error.value = null
    generatedImage.value = ''
    generationMode.value = 'single'
    quality.value = '2k'
    aspectRatio.value = '1:1'
    width.value = 2048
    height.value = 2048
    imageCount.value = 1
    style.value = 'default'
    referenceImages.value = []
    lastSnapshot.value = null
  }

  const fetchHistory = async (page = 1, pageSize = 20, append = false) => {
    try {
      historyLoading.value = true
      const res = await request.get('/api/v1/image/history', {
        params: { page, page_size: pageSize }
      })
      if (res.data?.code === 200) {
        const items = res.data?.data?.items || []
        const mapped = items.map(it => {
          const url = it.image_url ? it.image_url : (it.image_b64 ? `data:image/png;base64,${it.image_b64}` : '')
          const ts = it.created_at ? new Date(it.created_at).getTime() : Date.now()
          let opts = {}
          try {
            if (typeof it.options === 'string') {
              opts = JSON.parse(it.options)
            } else if (it.options && typeof it.options === 'object') {
              opts = it.options
            }
          } catch (e) {
            opts = {}
          }
          const parseSize = (s) => {
            if (typeof s !== 'string') return { w: undefined, h: undefined }
            const parts = s.split('x')
            const w = Number(parts[0])
            const h = Number(parts[1])
            return { w: isNaN(w) ? undefined : w, h: isNaN(h) ? undefined : h }
          }
          const gcd = (a, b) => {
            a = Math.abs(a || 0); b = Math.abs(b || 0)
            if (!a || !b) return 1
            while (b) { const t = b; b = a % b; a = t }
            return a
          }
          const toArray = (v) => {
            if (!v) return []
            if (Array.isArray(v)) return v
            if (typeof v === 'string') {
              return v.split(/[,;\s]+/).filter(Boolean)
            }
            return []
          }
          const b64ToUrls = (arr) => arr.map(x => typeof x === 'string' && x.startsWith('data:image') ? x : (typeof x === 'string' ? `data:image/png;base64,${x}` : '')).filter(Boolean)
          const collectRefs = () => {
            const candidates = [
              it.reference_images,
              opts.reference_images,
              it.references,
              opts.references,
              it.ref_images,
              opts.ref_images,
              it.reference_urls,
              opts.reference_urls,
              it.refs,
              opts.refs
            ]
            let refs = []
            for (const c of candidates) {
              refs = refs.concat(toArray(c))
            }
            const b64Candidates = [
              it.reference_image_b64_list,
              opts.reference_image_b64_list
            ]
            for (const bc of b64Candidates) {
              refs = refs.concat(b64ToUrls(toArray(bc)))
            }
            // dedupe
            const seen = new Set()
            const out = []
            for (const r of refs) {
              if (!r) continue
              if (!seen.has(r)) { seen.add(r); out.push(r) }
            }
            return out
          }
          const sizeParsed = parseSize(it.size || opts.size)
          const widthVal = it.width ?? opts.width ?? sizeParsed.w ?? 2048
          const heightVal = it.height ?? opts.height ?? sizeParsed.h ?? 2048
          const g = gcd(widthVal, heightVal)
          const ratioLabel = it.aspect_ratio || opts.aspect_ratio || `${Math.round(widthVal / g)}:${Math.round(heightVal / g)}`
          const settings = {
            selectedImageModel: it.model_identity || opts.model_identity || selectedImageModel.value,
            generationMode: it.generation_mode || opts.generation_mode || 'single',
            quality: it.quality || opts.quality || '2k',
            aspectRatio: ratioLabel,
            width: widthVal,
            height: heightVal,
            imageCount: it.image_count || it.n || opts.n || 1,
            style: it.style || opts.style || 'default',
            referenceImages: collectRefs()
          }
          return { id: it.id, prompt: it.prompt || '', imageUrl: url, timestamp: ts, settings }
        })
        if (append) {
          history.value = [...history.value, ...mapped]
          historyPage.value = page
        } else {
          history.value = mapped
          historyPage.value = 1
        }
        historyPageSize.value = pageSize
        historyHasMore.value = mapped.length === pageSize
      }
    } catch (e) {
      console.error('Fetch history failed', e)
    } finally {
      historyLoading.value = false
    }
  }

  const removeBackground = async (imageUrl) => {
    try {
      // 使用 WebSocket 提交抠图任务
      const res = await new Promise((resolve, reject) => {
        const handler = (res) => {
          wsManager.off('image_matting_res', handler)
          wsManager.off('error', errorHandler)
          if (res.data) {
            resolve(res.data)
          } else {
            reject(new Error(res.msg || '去除背景任务异常'))
          }
        }
        const errorHandler = (err) => {
          wsManager.off('image_matting_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error(err.msg || '去除背景失败'))
        }
        wsManager.on('image_matting_res', handler)
        wsManager.on('error', errorHandler)
        
        wsManager.send({
          type: 'image_matting',
          image_url: imageUrl
        })

        // 提交超时
        setTimeout(() => {
          wsManager.off('image_matting_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error('提交任务超时'))
        }, 30000)
      })

      if (res.task_id) {
        // 异步任务
        return await waitForTask(res.task_id)
      }
      // 同步任务
      return res
    } catch (e) {
      console.error('Remove background error:', e)
      throw e
    }
  }

  const extractText = async (imageUrl, modelIdentity = null) => {
    try {
      // 使用 WebSocket 提交 OCR 任务
      const res = await new Promise((resolve, reject) => {
        const handler = (res) => {
          wsManager.off('image_ocr_res', handler)
          wsManager.off('error', errorHandler)
          if (res.data) {
            resolve(res.data)
          } else {
            reject(new Error(res.msg || '文字识别任务异常'))
          }
        }
        const errorHandler = (err) => {
          wsManager.off('image_ocr_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error(err.msg || '文字识别失败'))
        }
        wsManager.on('image_ocr_res', handler)
        wsManager.on('error', errorHandler)
        
        wsManager.send({
          type: 'image_ocr',
          image_url: imageUrl,
          model_identity: modelIdentity
        })

        // 提交超时
        setTimeout(() => {
          wsManager.off('image_ocr_res', handler)
          wsManager.off('error', errorHandler)
          reject(new Error('提交任务超时'))
        }, 30000)
      })

      return res
    } catch (e) {
      console.error('OCR error:', e)
      throw e
    }
  }

  const reversePrompt = async (imageUrl) => {
    try {
      const res = await request.post('/api/v1/image/reverse-prompt', {
        image_url: imageUrl
      })

      if (res.code !== 200) {
        throw new Error(res.msg || 'Failed to start reverse prompt task')
      }

      const { data } = res
      if (data.task_id) {
        // Async task
        return await waitForTask(data.task_id)
      }
      // Sync result
      return data
    } catch (e) {
      console.error('Reverse prompt error:', e)
      throw e
    }
  }

  const uploadMarkerThumbnails = async () => {
    const uploadQueue = []
    
    // Find markers that need upload (have thumbnail but no uploadedUrl)
    markerPrompts.value.forEach((marker) => {
      if (marker.thumbnail && !marker.uploadedUrl && marker.thumbnail.startsWith('data:image')) {
        try {
          const arr = marker.thumbnail.split(',')
          const mime = arr[0].match(/:(.*?);/)[1]
          const bstr = atob(arr[1])
          let n = bstr.length
          const u8arr = new Uint8Array(n)
          while(n--){
              u8arr[n] = bstr.charCodeAt(n)
          }
          const ext = mime.split('/')[1] || 'jpeg'
          const filename = `marker_${marker.id}_${marker.number}.${ext}`
          const file = new File([u8arr], filename, { type: mime })
          uploadQueue.push({ id: marker.id, file })
        } catch (e) {
          console.error('Failed to convert marker thumbnail base64 to file', e)
        }
      }
    })

    if (!uploadQueue.length) return

    try {
      const form = new FormData()
      uploadQueue.forEach(item => form.append('files[]', item.file))
      
      const res = await request.post('/api/v1/image/upload', form)
      
      if (res.data?.code === 200) {
        const urls = res.data?.data?.urls || []
        
        // Update markers with URLs
        uploadQueue.forEach((item, i) => {
            const url = urls[i]
            if (url) {
                const marker = markerPrompts.value.find(m => m.id === item.id)
                if (marker) {
                    marker.uploadedUrl = url
                }
            }
        })
      } else {
        console.error('Marker thumbnail upload failed', res.data?.msg)
      }
    } catch (e) {
      console.error('Upload marker thumbnails failed', e)
      // Don't throw, just log error so chat can still proceed without images if necessary
    }
  }

  const uploadMarkerReferenceImages = async () => {
    // Identify unique images that need uploading
    const uniqueImages = new Map() // src -> { file: File | null, url: string | null }
    
    markerPrompts.value.forEach(m => {
      if (m.elementSrc) {
        if (!uniqueImages.has(m.elementSrc)) {
           uniqueImages.set(m.elementSrc, { src: m.elementSrc, file: null, url: null })
        }
      }
    })

    const uploadQueue = []
    
    // Process each unique image
    for (const [src, data] of uniqueImages.entries()) {
       if (src.startsWith('data:image')) {
          // Convert to file
          try {
             const arr = src.split(',')
             const mime = arr[0].match(/:(.*?);/)[1]
             const bstr = atob(arr[1])
             let n = bstr.length
             const u8arr = new Uint8Array(n)
             while(n--){
                 u8arr[n] = bstr.charCodeAt(n)
             }
             const ext = mime.split('/')[1] || 'png'
             const filename = `ref_layer_${Date.now()}_${Math.random().toString(36).substr(2, 5)}.${ext}`
             const file = new File([u8arr], filename, { type: mime })
             data.file = file
             uploadQueue.push(data)
          } catch (e) {
             console.error('Failed to convert base64 to file', e)
          }
       } else if (src.startsWith('blob:')) {
          // Blob URL - fetch and convert
          try {
             const res = await fetch(src)
             const blob = await res.blob()
             const ext = blob.type.split('/')[1] || 'png'
             const filename = `ref_layer_${Date.now()}_${Math.random().toString(36).substr(2, 5)}.${ext}`
             const file = new File([blob], filename, { type: blob.type })
             data.file = file
             uploadQueue.push(data)
          } catch(e) {
             console.error('Failed to fetch blob', e)
          }
       } else {
          // Already a remote URL
          data.url = src
       }
    }

    if (uploadQueue.length > 0) {
        try {
            const form = new FormData()
            uploadQueue.forEach(item => form.append('files[]', item.file))
            const res = await request.post('/api/v1/image/upload', form)
            if (res.data?.code === 200) {
                 const urls = res.data?.data?.urls || []
                 uploadQueue.forEach((item, i) => {
                     if (urls[i]) item.url = urls[i]
                 })
            }
        } catch (e) {
            console.error('Upload reference images failed', e)
        }
    }
    
    // Update markers with the final URL
    markerPrompts.value.forEach(m => {
        if (m.elementSrc && uniqueImages.has(m.elementSrc)) {
            const data = uniqueImages.get(m.elementSrc)
            if (data.url) {
                m.referenceImageUrl = data.url
            }
        }
    })
  }

  return {
    getPoseEditPrompt,
    getEraseToolPrompt,
    savePoseHistory,
    fetchPoseHistory,
    setPendingPoseHistoryLastUsed,
    updatePoseHistoryLastUsed,
    uploadMarkerReferenceImages,
    prompt,
    markerPrompts,
    addMarkerPrompt,
    removeMarkerPrompt,
    updateMarkerPrompt,
    updateMarkerPromptDetails,
    updateMarkerThumbnail,
    clearMarkerPrompts,
    uploadMarkerThumbnails,
    setPrompt,
    reversePrompt,
    isGenerating,
    generatedImage,
    history,
    historyPage,
    historyPageSize,
    historyHasMore,
    historyLoading,
    error,
    selectedImageModel,
    imageModels,
    videoModels,
    fetchImageModels,
    fetchVideoModels,
    initWebSocket,
    waitForTask,
    videoDuration,
    videoResolution,
    videoAspectRatio,
    imageResolution,
    generateVideo,
    recoverVideoTask,
    isGeneratingVideo,
    fetchHistory,
    loadMoreHistory: () => {
      if (historyLoading.value || !historyHasMore.value) return
      const nextPage = historyPage.value + 1
      return fetchHistory(nextPage, historyPageSize.value, true)
    },
    setImageModel,
    submitImageTask,
    generateImage,
    // Advanced settings
    generationMode,
    quality,
    aspectRatio,
    width,
    height,
    imageCount,
    style,
    referenceImages,
    uploadReferences,
    removeReference,
    removeReferenceAt,
    insertLocalReference,
    uploadPendingReferences,
    setReferences,
    resetWorkspace,
    undoLast: () => {
      if (!lastSnapshot.value) {
        ElMessage.warning('无可撤回的设置')
        return
      }
      const s = lastSnapshot.value
      prompt.value = s.prompt
      referenceImages.value = [...s.referenceImages]
      generationMode.value = s.generationMode
      quality.value = s.quality
      aspectRatio.value = s.aspectRatio
      width.value = s.width
      height.value = s.height
      imageCount.value = s.imageCount
      style.value = s.style
      selectedImageModel.value = s.selectedImageModel
      generatedImage.value = s.generatedImage || ''
      ElMessage.success('已撤回到上一次设置')
    },
    removeBackground,
    extractText,
    waitForTask
  }
})
