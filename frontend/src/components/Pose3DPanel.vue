<template>
  <div class="pose-panel" :class="{ 'is-fullscreen': fullscreen }" @mousedown.stop @click.stop @wheel.stop>
    <div class="pose-header" @mousedown.stop="handleHeaderMouseDown">
      <div class="title">动作</div>
      <div class="actions">
        <el-button v-if="allowUpload" size="small" :loading="isProcessing" @click="triggerFileInput">加载VRM</el-button>
        <el-button size="small" :disabled="!currentVrm || isProcessing" @click="resetPose">重置</el-button>
        <el-button size="small" :disabled="isProcessing" @click.stop="toggleFullscreen">{{ fullscreen ? '退出全屏' : '全屏' }}</el-button>
        <el-button size="small" text @click="emit('close')">
          <el-icon><Close /></el-icon>
        </el-button>
      </div>
      <input
        ref="fileInput"
        type="file"
        accept=".vrm"
        style="display: none"
        @change="handleFileChange"
      />
    </div>

    <div class="pose-body">
      <div class="pose-stage">
        <div v-if="isPanelLoading" class="pose-loading-mask" @mousedown.stop @click.stop @wheel.stop>
          <div class="pose-loading-inner">加载中...</div>
        </div>
        <div class="pose-tools-float">
          <el-button size="small" :disabled="isProcessing" @click="addCharacter">添加人物</el-button>
          <el-button size="small" type="danger" :disabled="!activeCharacterId || isProcessing" @click="deleteCharacter">删除人物</el-button>
        </div>
        <div class="pose-apply-float" @mousedown.stop @click.stop>
          <el-button size="default" type="primary" color="#333639" class="pose-apply-btn" :disabled="!rendererReady || isProcessing" @click="applyPose">应用到图片</el-button>
        </div>
        <div ref="container" class="three-container" @contextmenu.prevent></div>
        <div v-if="characters.length === 0" class="empty-tip">请加载一个VRM模型</div>
        <div class="pose-history" :class="{ 'is-collapsed': isHistoryCollapsed }">
          <div class="pose-history-title" @click.stop="toggleHistory">
            <span class="pose-history-title-text">{{ isHistoryCollapsed ? '历史' : '动作历史' }}</span>
            <el-button size="small" text class="pose-history-toggle-btn" @click.stop="toggleHistory">
              {{ isHistoryCollapsed ? '展开' : '收起' }}
            </el-button>
          </div>
          <div v-show="!isHistoryCollapsed" class="pose-history-list" @wheel.stop @scroll="handleHistoryScroll">
            <div v-if="isHistoryLoading && poseHistory.length === 0" class="pose-history-loading">加载中...</div>
            <div v-else-if="poseHistory.length === 0" class="pose-history-empty">暂无记录</div>
            <div
              v-else
              v-for="item in poseHistory"
              :key="item.id"
              class="pose-history-item"
            >
              <el-image class="pose-history-thumb" :src="item.thumbnail_url" fit="cover" lazy />
              <div class="pose-history-meta">
                <div class="pose-history-time">{{ item.created_at || '' }}</div>
                <el-button size="small" class="pose-history-use-btn" title="恢复" @click.stop="applyHistory(item)">恢复</el-button>
              </div>
            </div>
            <div v-if="isHistoryLoading && poseHistory.length > 0" class="pose-history-loading">加载中...</div>
          </div>
        </div>
      </div>
    </div>

    <div class="pose-footer">
      <span class="status">人物：{{ activeCharacterLabel }}　|　当前选中：{{ selectedBoneName }}</span>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Close } from '@element-plus/icons-vue'
import { useGenerationStore } from '@/stores/generation'

const emit = defineEmits(['close', 'apply', 'drag', 'toggle-fullscreen'])

const props = defineProps({
  defaultVrmUrl: {
    type: String,
    default: '/models/888.vrm'
  },
  autoLoad: {
    type: Boolean,
    default: true
  },
  allowUpload: {
    type: Boolean,
    default: false
  },
  fullscreen: {
    type: Boolean,
    default: false
  }
})

const fullscreen = computed(() => props.fullscreen)

const container = ref(null)
const fileInput = ref(null)

const selectedBoneName = ref('未选中')
const isProcessing = ref(false)
const rendererReady = ref(false)

const generationStore = useGenerationStore()
const poseHistory = ref([])
const isHistoryLoading = ref(false)
const isHistoryCollapsed = ref(true)
const isBooting = ref(true)
const historyPage = ref(1)
const historyPageSize = ref(10)
const historyHasMore = ref(true)
const lastAppliedHistoryId = ref(0)
const lastAppliedHistoryComparable = ref('')

const isPanelLoading = computed(() => isBooting.value || isProcessing.value)

const characters = ref([])
const activeCharacterId = ref(null)
const activeCharacterLabel = computed(() => {
  const total = characters.value.length
  if (total === 0) return '-'
  const idx = characters.value.findIndex((c) => c.id === activeCharacterId.value)
  if (idx < 0) return `- / ${total}`
  return `${idx + 1} / ${total}`
})

let THREE = null
let GLTFLoader = null
let OrbitControls = null
let VRMLoaderPlugin = null
let VRMUtils = null

let scene = null
let camera = null
let renderer = null
let orbitControls = null
let clock = null
let animationId = 0
let resizeObserver = null

let currentVrm = null
let vrmRoot = null
let nextCharacterId = 1

let raycaster = null
let mouse = null

let rotateBone = null
let rotatePointerId = null
let rotateStartMouse = null
let rotateStartQuat = null
let rotateAxisUp = null
let rotateAxisRight = null

let ikEffector = null
let ikPointerId = null
let ikPlane = null
let ikTarget = null
let ikChain = []
let ikTargetHelper = null

let pointerDownHandler = null
let pointerMoveHandler = null
let pointerUpHandler = null
let gesturePointerDownHandler = null
let gesturePointerMoveHandler = null
let gesturePointerUpHandler = null
let gestureWheelHandler = null

let disposed = false

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

const serializePoseData = () => {
  const view = (() => {
    if (!camera) return null
    const p = camera.position
    const q = camera.quaternion
    const target = orbitControls?.target
    return {
      camera: {
        p: [p.x, p.y, p.z],
        q: [q.x, q.y, q.z, q.w],
        fov: camera.fov,
        near: camera.near,
        far: camera.far
      },
      controls: target ? { target: [target.x, target.y, target.z] } : null
    }
  })()

  const items = []
  for (const c of characters.value) {
    const vrm = c?.vrm
    const bones = {}
    const map = vrm?.humanoid?.humanBones || {}
    for (const name of Object.keys(map)) {
      const node = map?.[name]?.node
      if (!node) continue
      const q = node.quaternion
      const p = node.position
      bones[name] = {
        q: [q.x, q.y, q.z, q.w],
        p: [p.x, p.y, p.z]
      }
    }
    items.push({ bones })
  }
  const activeIndex = characters.value.findIndex((c) => c.id === activeCharacterId.value)
  return { version: 2, activeIndex, view, characters: items }
}

const restorePoseFromData = (data) => {
  const chars = Array.isArray(data?.characters) ? data.characters : []
  for (let i = 0; i < chars.length; i++) {
    const target = characters.value[i]
    const vrm = target?.vrm
    if (!vrm) continue
    const bones = chars[i]?.bones || {}
    const map = vrm?.humanoid?.humanBones || {}
    for (const name of Object.keys(bones)) {
      const node = map?.[name]?.node
      if (!node) continue
      const q = bones[name]?.q
      const p = bones[name]?.p
      if (Array.isArray(q) && q.length === 4) node.quaternion.set(q[0], q[1], q[2], q[3])
      if (Array.isArray(p) && p.length === 3) node.position.set(p[0], p[1], p[2])
    }
  }
  const idx = Number.isFinite(data?.activeIndex) ? data.activeIndex : -1
  if (idx >= 0 && idx < characters.value.length) setActiveCharacter(characters.value[idx].id)

  try {
    const view = data?.view
    const cam = view?.camera
    if (camera && cam) {
      const p = cam?.p
      const q = cam?.q
      if (Array.isArray(p) && p.length === 3) camera.position.set(p[0], p[1], p[2])
      if (Array.isArray(q) && q.length === 4) camera.quaternion.set(q[0], q[1], q[2], q[3])
      if (typeof cam.fov === 'number') camera.fov = cam.fov
      if (typeof cam.near === 'number') camera.near = cam.near
      if (typeof cam.far === 'number') camera.far = cam.far
      camera.updateProjectionMatrix()
    }
    const target = view?.controls?.target
    if (orbitControls && target && Array.isArray(target) && target.length === 3) {
      orbitControls.target.set(target[0], target[1], target[2])
      orbitControls.update()
    }
  } catch {}

  resetDragState()
  if (renderer && scene && camera) renderer.render(scene, camera)
}

const loadPoseHistory = async (page = 1, append = false) => {
  if (!generationStore?.fetchPoseHistory) return
  if (isHistoryLoading.value) return
  isHistoryLoading.value = true
  try {
    const data = await generationStore.fetchPoseHistory(page, historyPageSize.value)
    const items = Array.isArray(data?.items) ? data.items : []
    const hasMore = typeof data?.has_more === 'boolean' ? data.has_more : items.length >= historyPageSize.value
    historyHasMore.value = hasMore
    historyPage.value = page
    if (append) poseHistory.value = [...poseHistory.value, ...items]
    else poseHistory.value = items
  } catch {
    if (!append) poseHistory.value = []
    historyHasMore.value = false
  } finally {
    isHistoryLoading.value = false
  }
}

const toggleHistory = () => {
  isHistoryCollapsed.value = !isHistoryCollapsed.value
}

const handleHistoryScroll = (e) => {
  if (isHistoryLoading.value || !historyHasMore.value) return
  const el = e?.target
  if (!el) return
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 10) {
    void loadPoseHistory(historyPage.value + 1, true)
  }
}

const applyHistory = (item) => {
  const raw = item?.pose_json
  if (!raw) return
  const idNum = Number(item?.id)
  lastAppliedHistoryId.value = Number.isFinite(idNum) && idNum > 0 ? Math.trunc(idNum) : 0
  lastAppliedHistoryComparable.value = comparablePoseJson(raw)
  let data = null
  try {
    data = typeof raw === 'string' ? JSON.parse(raw) : raw
  } catch {
    data = null
  }
  if (!data) return
  restorePoseFromData(data)
}

const handleHeaderMouseDown = (e) => {
  if (fullscreen.value) return
  const t = e?.target
  if (t?.closest?.('.actions')) return
  emit('drag', e)
}

const toggleFullscreen = () => {
  emit('toggle-fullscreen', !fullscreen.value)
}

const stableStringify = (value) => {
  if (value === null) return 'null'
  const t = typeof value
  if (t !== 'object') return JSON.stringify(value)
  if (Array.isArray(value)) return '[' + value.map(stableStringify).join(',') + ']'
  const keys = Object.keys(value).sort()
  return '{' + keys.map((k) => JSON.stringify(k) + ':' + stableStringify(value[k])).join(',') + '}'
}

const normalizePoseJson = (raw) => {
  if (!raw) return ''
  try {
    const obj = typeof raw === 'string' ? JSON.parse(raw) : raw
    return stableStringify(obj)
  } catch {
    return ''
  }
}

const comparablePoseJson = (raw) => {
  if (!raw) return ''
  try {
    const obj = typeof raw === 'string' ? JSON.parse(raw) : raw
    const chars = Array.isArray(obj?.characters) ? obj.characters : []
    const core = {
      characters: chars.map((c) => {
        const bones = c?.bones && typeof c.bones === 'object' ? c.bones : {}
        return { bones }
      })
    }
    return stableStringify(core)
  } catch {
    return ''
  }
}

const savePoseSnapshot = async (poseJson, thumbnailFile) => {
  if (!generationStore?.savePoseHistory) return
  const normalized = normalizePoseJson(poseJson)
  if (normalized) {
    const duplicated = poseHistory.value.some((item) => normalizePoseJson(item?.pose_json) === normalized)
    if (duplicated) return
  }
  let thumbnailUrl = ''
  try {
    if (thumbnailFile && generationStore?.uploadReferences) {
      const uploaded = await generationStore.uploadReferences([thumbnailFile], false, false)
      thumbnailUrl = Array.isArray(uploaded) && uploaded.length ? uploaded[0] : ''
    }
  } catch {}
  try {
    await generationStore.savePoseHistory({ poseJson, thumbnailUrl })
  } catch {}
  try {
    await loadPoseHistory()
  } catch {}
}

const ensureLibs = async () => {
  if (THREE) return
  const threeMod = await import('three')
  THREE = threeMod
  const loaderMod = await import('three/addons/loaders/GLTFLoader.js')
  GLTFLoader = loaderMod.GLTFLoader
  const controlsMod = await import('three/addons/controls/OrbitControls.js')
  OrbitControls = controlsMod.OrbitControls
  const vrmMod = await import('@pixiv/three-vrm')
  VRMLoaderPlugin = vrmMod.VRMLoaderPlugin
  VRMUtils = vrmMod.VRMUtils
}

const initThree = async () => {
  await ensureLibs()
  if (!container.value || disposed) return

  scene = new THREE.Scene()
  scene.background = new THREE.Color(0xffffff)

  const width = container.value.clientWidth
  const height = container.value.clientHeight
  camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 100)
  camera.position.set(0, 1.5, 3)

  renderer = new THREE.WebGLRenderer({ antialias: true, preserveDrawingBuffer: true })
  renderer.setSize(width, height)
  renderer.setPixelRatio(window.devicePixelRatio)
  container.value.appendChild(renderer.domElement)
  renderer.domElement.style.touchAction = 'none'
  rendererReady.value = true
  renderer.domElement.addEventListener('contextmenu', (e) => {
    e.preventDefault()
    e.stopPropagation()
  })

  const directionalLight = new THREE.DirectionalLight(0xffffff, 1.0)
  directionalLight.position.set(1, 1, 1).normalize()
  scene.add(directionalLight)

  const ambientLight = new THREE.AmbientLight(0xffffff, 0.5)
  scene.add(ambientLight)

  const gridHelper = new THREE.GridHelper(10, 10)
  scene.add(gridHelper)

  orbitControls = new OrbitControls(camera, renderer.domElement)
  orbitControls.target.set(0, 1, 0)
  orbitControls.mouseButtons = {
    LEFT: THREE.MOUSE.ROTATE,
    MIDDLE: THREE.MOUSE.PAN,
    RIGHT: -1
  }
  orbitControls.enablePan = true
  orbitControls.update()

  raycaster = new THREE.Raycaster()
  raycaster.params.Mesh.threshold = 0.1
  raycaster.params.Line.threshold = 0.1
  mouse = new THREE.Vector2()
  rotateStartMouse = new THREE.Vector2()
  rotateStartQuat = new THREE.Quaternion()
  rotateAxisUp = new THREE.Vector3()
  rotateAxisRight = new THREE.Vector3()
  ikPlane = new THREE.Plane()
  ikTarget = new THREE.Vector3()
  ikChain = []

  clock = new THREE.Clock()

  resizeObserver = new ResizeObserver(() => onResize())
  resizeObserver.observe(container.value)

  attachPointerEvents()
  animate()
}

const onResize = () => {
  if (!container.value || !camera || !renderer) return
  const width = container.value.clientWidth
  const height = container.value.clientHeight
  if (width <= 0 || height <= 0) return
  camera.aspect = width / height
  camera.updateProjectionMatrix()
  renderer.setSize(width, height)
}

const animate = () => {
  if (disposed) return
  animationId = requestAnimationFrame(animate)
  const delta = clock?.getDelta?.() ?? 0
  for (const c of characters.value) c?.vrm?.update?.(delta)
  if (renderer && scene && camera) renderer.render(scene, camera)
}

const resetDragState = () => {
  rotateBone = null
  rotatePointerId = null
  ikEffector = null
  ikPointerId = null
  ikChain = []
  orbitControls && (orbitControls.enabled = true)
  if (ikTargetHelper) ikTargetHelper.visible = false
}

const setActiveCharacter = (id) => {
  const found = characters.value.find((c) => c.id === id) ?? null
  activeCharacterId.value = found ? id : null
  currentVrm = found?.vrm ?? null
  vrmRoot = found?.root ?? null
  selectedBoneName.value = '未选中'
  resetDragState()
}

const clearAllCharacters = () => {
  for (const c of characters.value) {
    if (c?.root && scene) scene.remove(c.root)
    if (c?.root && VRMUtils) {
      try {
        VRMUtils.deepDispose(c.root)
      } catch {}
    }
  }
  characters.value = []
  activeCharacterId.value = null
  currentVrm = null
  vrmRoot = null
  selectedBoneName.value = '未选中'
  resetDragState()
}

const getSpawnXForIndex = (idx) => {
  if (idx === 0) return 0
  const step = 0.9
  const side = idx % 2 === 1 ? -1 : 1
  const k = Math.ceil(idx / 2)
  return side * step * k
}

const detachCharacterFromScene = (id) => {
  if (!scene) return []
  const hits = []
  scene.traverse((obj) => {
    if (obj?.userData?.characterId === id) hits.push(obj)
  })
  const roots = hits.filter((obj) => !(obj?.parent?.userData?.characterId === id))
  for (const obj of roots) {
    try {
      obj.removeFromParent?.()
    } catch {}
  }
  return roots
}

const addVrmFromGltf = (gltf) => {
  const vrm = gltf?.userData?.vrm
  if (!vrm || !scene || !THREE) return

  if (vrm?.humanoid) vrm.humanoid.autoUpdateHumanBones = false

  VRMUtils.removeUnnecessaryVertices(vrm.scene)
  VRMUtils.combineSkeletons(vrm.scene)

  vrm.scene.rotation.y = 0

  const root = new THREE.Group()
  const id = String(nextCharacterId++)
  root.userData.characterId = id
  vrm.scene.userData.characterId = id
  root.position.set(getSpawnXForIndex(characters.value.length), 0, 0)
  root.add(vrm.scene)
  scene.add(root)

  characters.value.push({ id, vrm, root })
  setActiveCharacter(id)
}

const loadVrm = async (file) => {
  await ensureLibs()
  if (disposed) return

  isProcessing.value = true
  const loader = new GLTFLoader()
  loader.register((parser) => new VRMLoaderPlugin(parser))

  const url = URL.createObjectURL(file)
  loader.load(
    url,
    (gltf) => {
      URL.revokeObjectURL(url)
      if (disposed) return
      addVrmFromGltf(gltf)
      isProcessing.value = false
    },
    undefined,
    () => {
      URL.revokeObjectURL(url)
      isProcessing.value = false
    }
  )
}

const loadVrmFromUrl = async (url) => {
  await ensureLibs()
  if (disposed) return
  if (!scene) return

  isProcessing.value = true
  const loader = new GLTFLoader()
  loader.register((parser) => new VRMLoaderPlugin(parser))

  loader.load(
    url,
    (gltf) => {
      if (disposed) return
      addVrmFromGltf(gltf)
      isProcessing.value = false
    },
    undefined,
    () => {
      isProcessing.value = false
    }
  )
}

const handleFileChange = async (event) => {
  const input = event.target
  const file = input?.files?.[0]
  if (!file) return
  await loadVrm(file)
  input.value = ''
}

const triggerFileInput = () => {
  fileInput.value?.click()
}

const addCharacter = async () => {
  if (!props.defaultVrmUrl) return
  if (!scene) return
  await loadVrmFromUrl(props.defaultVrmUrl)
}

const deleteCharacter = () => {
  const id = activeCharacterId.value
  if (!id) return
  const idx = characters.value.findIndex((c) => c.id === id)
  if (idx < 0) return
  const c = characters.value[idx]

  resetDragState()
  const detached = detachCharacterFromScene(id)
  const disposeTargets = detached.length ? detached : (c?.root ? [c.root] : [])
  for (const obj of disposeTargets) {
    if (!obj || !VRMUtils) continue
    try {
      VRMUtils.deepDispose(obj)
    } catch {}
  }

  characters.value.splice(idx, 1)

  const next = characters.value[idx]?.id ?? characters.value[idx - 1]?.id ?? null
  if (next) setActiveCharacter(next)
  else setActiveCharacter(null)
}

const resetPose = () => {
  const humanoid = currentVrm?.humanoid
  if (humanoid?.resetPose) humanoid.resetPose()
}

const capturePngDataUrl = () => {
  if (!renderer) return ''
  const grid = scene?.children?.find((c) => c instanceof THREE.GridHelper)
  const originalIkTargetVisible = ikTargetHelper?.visible
  if (ikTargetHelper) ikTargetHelper.visible = false
  renderer.render(scene, camera)
  const url = renderer.domElement.toDataURL('image/png')
  if (ikTargetHelper) ikTargetHelper.visible = originalIkTargetVisible ?? false
  return url
}

const applyPose = () => {
  if (!rendererReady.value || isProcessing.value) return
  const thumbDataUrl = capturePngDataUrl()
  if (!thumbDataUrl) return

  try {
    const poseData = serializePoseData()
    const currentComparable = comparablePoseJson(poseData)
    if (generationStore?.setPendingPoseHistoryLastUsed) {
      if (lastAppliedHistoryId.value && currentComparable && currentComparable === lastAppliedHistoryComparable.value) {
        generationStore.setPendingPoseHistoryLastUsed(lastAppliedHistoryId.value)
      } else {
        generationStore.setPendingPoseHistoryLastUsed(0)
      }
    }
    const poseJson = JSON.stringify(poseData)
    const thumbFile = dataUrlToFile(thumbDataUrl, `pose_thumb_${Date.now()}.png`)
    void savePoseSnapshot(poseJson, thumbFile)
  } catch {}

  emit('apply', thumbDataUrl)
  emit('close')
}

const getHumanoidBoneNode = (name) => {
  const humanoid = currentVrm?.humanoid
  return humanoid?.humanBones?.[name]?.node ?? null
}

const closestFromNodes = (hitPoint, nodes) => {
  let closest = null
  let minDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const node of nodes) {
    if (!node) continue
    node.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < minDistSq) {
      minDistSq = d
      closest = node
    }
  }
  return { node: closest, distSq: minDistSq }
}

const pickClosestFromNames = (hitPoint, names, maxDist) => {
  let closest = null
  let minDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const name of names) {
    const node = getHumanoidBoneNode(name)
    if (!node) continue
    node.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < minDistSq) {
      minDistSq = d
      closest = node
    }
  }
  if (!closest) return null
  if (minDistSq > maxDist * maxDist) return null
  return closest
}

const pickClosestFromNamesWithDist = (hitPoint, names, maxDist) => {
  let closest = null
  let minDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const name of names) {
    const node = getHumanoidBoneNode(name)
    if (!node) continue
    node.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < minDistSq) {
      minDistSq = d
      closest = node
    }
  }
  if (!closest) return { node: null, distSq: Infinity }
  if (minDistSq > maxDist * maxDist) return { node: null, distSq: Infinity }
  return { node: closest, distSq: minDistSq }
}

const pickFingerRotationBone = (hitPoint) => {
  const leftFingerNames = [
    'leftThumbMetacarpal', 'leftThumbProximal', 'leftThumbDistal',
    'leftIndexProximal', 'leftIndexIntermediate', 'leftIndexDistal',
    'leftMiddleProximal', 'leftMiddleIntermediate', 'leftMiddleDistal',
    'leftRingProximal', 'leftRingIntermediate', 'leftRingDistal',
    'leftLittleProximal', 'leftLittleIntermediate', 'leftLittleDistal'
  ]
  const rightFingerNames = [
    'rightThumbMetacarpal', 'rightThumbProximal', 'rightThumbDistal',
    'rightIndexProximal', 'rightIndexIntermediate', 'rightIndexDistal',
    'rightMiddleProximal', 'rightMiddleIntermediate', 'rightMiddleDistal',
    'rightRingProximal', 'rightRingIntermediate', 'rightRingDistal',
    'rightLittleProximal', 'rightLittleIntermediate', 'rightLittleDistal'
  ]
  const groups = [
    { names: leftFingerNames, maxDist: 0.25 },
    { names: rightFingerNames, maxDist: 0.25 }
  ]
  let bestBone = null
  let bestDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const group of groups) {
    const candidate = pickClosestFromNames(hitPoint, group.names, group.maxDist)
    if (!candidate) continue
    candidate.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < bestDistSq) {
      bestDistSq = d
      bestBone = candidate
    }
  }
  return bestBone
}

const pickFingerSideForIk = (hitPoint) => {
  const leftFingerNames = [
    'leftThumbMetacarpal', 'leftThumbProximal', 'leftThumbDistal',
    'leftIndexProximal', 'leftIndexIntermediate', 'leftIndexDistal',
    'leftMiddleProximal', 'leftMiddleIntermediate', 'leftMiddleDistal',
    'leftRingProximal', 'leftRingIntermediate', 'leftRingDistal',
    'leftLittleProximal', 'leftLittleIntermediate', 'leftLittleDistal'
  ]
  const rightFingerNames = [
    'rightThumbMetacarpal', 'rightThumbProximal', 'rightThumbDistal',
    'rightIndexProximal', 'rightIndexIntermediate', 'rightIndexDistal',
    'rightMiddleProximal', 'rightMiddleIntermediate', 'rightMiddleDistal',
    'rightRingProximal', 'rightRingIntermediate', 'rightRingDistal',
    'rightLittleProximal', 'rightLittleIntermediate', 'rightLittleDistal'
  ]
  const left = pickClosestFromNamesWithDist(hitPoint, leftFingerNames, 0.28)
  const right = pickClosestFromNamesWithDist(hitPoint, rightFingerNames, 0.28)
  if (!left.node && !right.node) return { side: null, distSq: Infinity }
  if (left.distSq <= right.distSq) return { side: 'left', distSq: left.distSq }
  return { side: 'right', distSq: right.distSq }
}

const pickFootFineRotationBone = (hitPoint) => {
  const toeNames = ['leftToes', 'rightToes']
  const ankleNames = ['leftFoot', 'rightFoot']
  const hasToes = !!getHumanoidBoneNode('leftToes') || !!getHumanoidBoneNode('rightToes')
  const groups = [
    ...(hasToes ? [{ names: toeNames, maxDist: 0.3 }] : []),
    { names: ankleNames, maxDist: 0.35 }
  ]
  let bestBone = null
  let bestDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const group of groups) {
    const candidate = pickClosestFromNames(hitPoint, group.names, group.maxDist)
    if (!candidate) continue
    candidate.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < bestDistSq) {
      bestDistSq = d
      bestBone = candidate
    }
  }
  return bestBone
}

const pickRotationBone = (hitPoint) => {
  const groups = [
    { names: ['leftUpperArm', 'leftLowerArm', 'leftHand'], maxDist: 0.8 },
    { names: ['rightUpperArm', 'rightLowerArm', 'rightHand'], maxDist: 0.8 },
    { names: ['leftUpperLeg', 'leftLowerLeg', 'leftFoot'], maxDist: 0.9 },
    { names: ['rightUpperLeg', 'rightLowerLeg', 'rightFoot'], maxDist: 0.9 },
    { names: ['neck', 'head'], maxDist: 0.9 },
    { names: ['hips', 'spine', 'chest', 'upperChest'], maxDist: 1.0 }
  ]
  let bestBone = null
  let bestDistSq = Infinity
  const tmp = new THREE.Vector3()
  for (const group of groups) {
    const candidate = pickClosestFromNames(hitPoint, group.names, group.maxDist)
    if (!candidate) continue
    candidate.getWorldPosition(tmp)
    const d = hitPoint.distanceToSquared(tmp)
    if (d < bestDistSq) {
      bestDistSq = d
      bestBone = candidate
    }
  }
  return bestBone
}

const beginRotateDrag = (bone, event) => {
  rotateBone = bone
  rotatePointerId = event.pointerId
  rotateStartMouse.set(event.clientX, event.clientY)
  rotateStartQuat.copy(bone.quaternion)

  const parentQ = new THREE.Quaternion()
  bone.parent?.getWorldQuaternion(parentQ)
  const invParentQ = parentQ.invert()

  rotateAxisUp.copy(camera.up).normalize().applyQuaternion(invParentQ).normalize()
  const cameraRight = new THREE.Vector3(1, 0, 0).applyQuaternion(camera.quaternion).normalize()
  rotateAxisRight.copy(cameraRight).applyQuaternion(invParentQ).normalize()

  ikEffector = null
  ikPointerId = null
  ikChain = []
  if (ikTargetHelper) ikTargetHelper.visible = false

  orbitControls.enabled = false
  renderer.domElement.setPointerCapture(event.pointerId)
  selectedBoneName.value = bone.name || '未命名骨骼'
}

const updateRotateDrag = (event) => {
  if (!rotateBone) return
  if (rotatePointerId !== event.pointerId) return
  const dx = event.clientX - rotateStartMouse.x
  const dy = event.clientY - rotateStartMouse.y
  const speed = 0.01
  const qUp = new THREE.Quaternion().setFromAxisAngle(rotateAxisUp, -dx * speed)
  const qRight = new THREE.Quaternion().setFromAxisAngle(rotateAxisRight, -dy * speed)
  const q = qUp.multiply(qRight)
  rotateBone.quaternion.copy(rotateStartQuat).premultiply(q)
  rotateBone.updateMatrixWorld(true)
}

const endRotateDrag = (event) => {
  if (!rotateBone) return
  if (rotatePointerId !== event.pointerId) return
  try {
    renderer.domElement.releasePointerCapture(event.pointerId)
  } catch {}
  rotateBone = null
  rotatePointerId = null
  orbitControls.enabled = true
}

const buildIkChain = (effector) => {
  const chain = []
  const humanoid = currentVrm?.humanoid
  const effectorName =
    humanoid?.humanBones?.leftHand?.node === effector ? 'leftHand'
      : humanoid?.humanBones?.rightHand?.node === effector ? 'rightHand'
        : humanoid?.humanBones?.leftToes?.node === effector ? 'leftToes'
          : humanoid?.humanBones?.rightToes?.node === effector ? 'rightToes'
            : humanoid?.humanBones?.leftFoot?.node === effector ? 'leftFoot'
              : humanoid?.humanBones?.rightFoot?.node === effector ? 'rightFoot'
                : null

  const chainNames =
    effectorName === 'leftHand' ? ['leftLowerArm', 'leftUpperArm']
      : effectorName === 'rightHand' ? ['rightLowerArm', 'rightUpperArm']
        : effectorName === 'leftToes' ? ['leftFoot', 'leftLowerLeg', 'leftUpperLeg']
          : effectorName === 'rightToes' ? ['rightFoot', 'rightLowerLeg', 'rightUpperLeg']
            : effectorName === 'leftFoot' ? ['leftLowerLeg', 'leftUpperLeg']
              : effectorName === 'rightFoot' ? ['rightLowerLeg', 'rightUpperLeg']
                : []

  for (const name of chainNames) {
    const node = getHumanoidBoneNode(name)
    if (node) chain.push(node)
  }

  return chain
}

const ensureIkTargetHelper = () => {
  if (ikTargetHelper) return
  const geometry = new THREE.SphereGeometry(0.03, 12, 12)
  const material = new THREE.MeshBasicMaterial({ color: 0xff3333, depthTest: false, depthWrite: false })
  ikTargetHelper = new THREE.Mesh(geometry, material)
  ikTargetHelper.renderOrder = 9999
  ikTargetHelper.visible = false
  ikTargetHelper.userData.isIkTargetHelper = true
  scene.add(ikTargetHelper)
}

const solveIkCCD = (effector, chain, targetWorld) => {
  if (chain.length === 0) return

  const effectorPos = new THREE.Vector3()
  const bonePos = new THREE.Vector3()
  const vEff = new THREE.Vector3()
  const vTar = new THREE.Vector3()
  const axis = new THREE.Vector3()
  const parentWorldQ = new THREE.Quaternion()
  const parentWorldQInv = new THREE.Quaternion()
  const deltaWorldQ = new THREE.Quaternion()
  const deltaLocalQ = new THREE.Quaternion()

  const maxIterations = 12
  const angleStepLimit = 0.35
  const epsilon = 1e-5

  for (let iter = 0; iter < maxIterations; iter++) {
    effector.getWorldPosition(effectorPos)
    if (effectorPos.distanceToSquared(targetWorld) < 0.002 * 0.002) break

    for (const bone of chain) {
      bone.getWorldPosition(bonePos)
      effector.getWorldPosition(effectorPos)

      vEff.copy(effectorPos).sub(bonePos)
      vTar.copy(targetWorld).sub(bonePos)

      const lenEff = vEff.length()
      const lenTar = vTar.length()
      if (lenEff < epsilon || lenTar < epsilon) continue

      vEff.multiplyScalar(1 / lenEff)
      vTar.multiplyScalar(1 / lenTar)

      axis.crossVectors(vEff, vTar)
      const axisLen = axis.length()
      if (axisLen < epsilon) continue
      axis.multiplyScalar(1 / axisLen)

      let dot = vEff.dot(vTar)
      dot = Math.min(1, Math.max(-1, dot))
      let angle = Math.acos(dot)
      if (angle > angleStepLimit) angle = angleStepLimit
      if (!Number.isFinite(angle) || angle < 1e-4) continue

      deltaWorldQ.setFromAxisAngle(axis, angle)

      bone.parent?.getWorldQuaternion(parentWorldQ)
      parentWorldQInv.copy(parentWorldQ).invert()
      deltaLocalQ.copy(parentWorldQInv).multiply(deltaWorldQ).multiply(parentWorldQ)

      bone.quaternion.premultiply(deltaLocalQ)
      bone.updateMatrixWorld(true)
    }
  }
}

const beginIkDrag = (effector, event) => {
  ikEffector = effector
  ikPointerId = event.pointerId
  ikChain = buildIkChain(effector)

  ensureIkTargetHelper()
  const effectorPos = new THREE.Vector3()
  effector.getWorldPosition(effectorPos)
  ikTarget.copy(effectorPos)

  const normal = new THREE.Vector3()
  camera.getWorldDirection(normal).normalize()
  ikPlane.setFromNormalAndCoplanarPoint(normal, effectorPos)

  rotateBone = null
  rotatePointerId = null

  orbitControls.enabled = false
  renderer.domElement.setPointerCapture(event.pointerId)
  selectedBoneName.value = effector.name || '未命名骨骼'

  if (ikTargetHelper) {
    ikTargetHelper.position.copy(ikTarget)
    ikTargetHelper.visible = true
  }
}

const updateIkDrag = (event) => {
  if (!ikEffector) return
  if (ikPointerId !== event.pointerId) return

  const rect = renderer.domElement.getBoundingClientRect()
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1
  mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1
  raycaster.setFromCamera(mouse, camera)

  const hit = new THREE.Vector3()
  const ok = raycaster.ray.intersectPlane(ikPlane, hit)
  if (!ok) return

  ikTarget.copy(hit)
  if (ikTargetHelper) ikTargetHelper.position.copy(ikTarget)
  solveIkCCD(ikEffector, ikChain, ikTarget)
}

const endIkDrag = (event) => {
  if (!ikEffector) return
  if (ikPointerId !== event.pointerId) return
  try {
    renderer.domElement.releasePointerCapture(event.pointerId)
  } catch {}
  ikEffector = null
  ikPointerId = null
  ikChain = []
  orbitControls.enabled = true
  if (ikTargetHelper) ikTargetHelper.visible = false
}

const attachPointerEvents = () => {
  if (!renderer?.domElement) return

  gesturePointerDownHandler = (e) => {
    if (e.button !== 2 && e.button !== 1) return
    e.preventDefault()
    try {
      renderer.domElement.setPointerCapture(e.pointerId)
    } catch {}
    e.stopPropagation()
  }

  gesturePointerMoveHandler = (e) => {
    if (!(e.buttons & 2) && !(e.buttons & 4)) return
    e.preventDefault()
    e.stopPropagation()
  }

  gesturePointerUpHandler = (e) => {
    if (e.button !== 2 && e.button !== 1) return
    try {
      renderer.domElement.releasePointerCapture(e.pointerId)
    } catch {}
    e.stopPropagation()
  }

  gestureWheelHandler = (e) => {
    const isHorizontal = Math.abs(e.deltaX) > Math.abs(e.deltaY)
    if (isHorizontal || e.ctrlKey) e.preventDefault()
    e.stopPropagation()
  }

  renderer.domElement.addEventListener('pointerdown', gesturePointerDownHandler)
  renderer.domElement.addEventListener('pointermove', gesturePointerMoveHandler)
  renderer.domElement.addEventListener('pointerup', gesturePointerUpHandler)
  renderer.domElement.addEventListener('pointercancel', gesturePointerUpHandler)
  renderer.domElement.addEventListener('wheel', gestureWheelHandler, { passive: false })

  const onPointerDownMain = (e) => {
    if (characters.value.length === 0) return
    if (e.button !== 0) return

    const rect = renderer.domElement.getBoundingClientRect()
    mouse.x = ((e.clientX - rect.left) / rect.width) * 2 - 1
    mouse.y = -((e.clientY - rect.top) / rect.height) * 2 + 1
    raycaster.setFromCamera(mouse, camera)

    const intersects = raycaster.intersectObjects(scene.children, true)
    const meshHit = intersects.find((hit) => (hit.object.type === 'SkinnedMesh' || hit.object.type === 'Mesh') && !(hit.object instanceof THREE.GridHelper) && !hit.object.userData.isIkTargetHelper)
    if (!meshHit) {
      selectedBoneName.value = '未选中'
      return
    }

    let p = meshHit.object
    let hitCharacterId = null
    while (p) {
      if (p.userData?.characterId) {
        hitCharacterId = p.userData.characterId
        break
      }
      p = p.parent
    }
    if (hitCharacterId) setActiveCharacter(hitCharacterId)
    if (!currentVrm) {
      selectedBoneName.value = '未选中'
      return
    }

    if (e.altKey) {
      const fingerBone = pickFingerRotationBone(meshHit.point)
      if (fingerBone) {
        beginRotateDrag(fingerBone, e)
        return
      }
      const footFineBone = pickFootFineRotationBone(meshHit.point)
      if (footFineBone) {
        beginRotateDrag(footFineBone, e)
        return
      }
    }

    const leftHand = getHumanoidBoneNode('leftHand')
    const rightHand = getHumanoidBoneNode('rightHand')
    const { node: handNode, distSq: handDistSq } = closestFromNodes(meshHit.point, [leftHand, rightHand])
    if (handNode && handDistSq <= 0.22 * 0.22 && !e.altKey) {
      beginRotateDrag(handNode, e)
      return
    }

    const fingerSide = pickFingerSideForIk(meshHit.point)
    if (fingerSide.side) {
      const effectorHand = fingerSide.side === 'left' ? leftHand : rightHand
      if (effectorHand) {
        beginIkDrag(effectorHand, e)
        return
      }
    }

    const leftToes = getHumanoidBoneNode('leftToes')
    const rightToes = getHumanoidBoneNode('rightToes')
    const leftFoot = getHumanoidBoneNode('leftFoot')
    const rightFoot = getHumanoidBoneNode('rightFoot')
    const hasToes = !!leftToes || !!rightToes
    const { node: toeNode, distSq: toeDistSq } = closestFromNodes(meshHit.point, [leftToes, rightToes])
    const { node: footNode, distSq: footDistSq } = closestFromNodes(meshHit.point, [leftFoot, rightFoot])

    if (hasToes && toeNode && toeDistSq <= 0.28 * 0.28) {
      beginIkDrag(toeNode, e)
      return
    }

    if (footNode && footDistSq <= 0.3 * 0.3 && (!hasToes || footDistSq <= toeDistSq)) {
      beginRotateDrag(footNode, e)
      return
    }

    if (!hasToes && footNode && footDistSq <= 0.24 * 0.24) {
      beginIkDrag(footNode, e)
      return
    }

    const bone = pickRotationBone(meshHit.point)
    if (bone) {
      beginRotateDrag(bone, e)
      return
    }

    selectedBoneName.value = '未选中'
  }

  const onPointerMoveMain = (e) => {
    if (ikEffector) {
      updateIkDrag(e)
      return
    }
    updateRotateDrag(e)
  }

  const onPointerUpMain = (e) => {
    if (ikEffector) endIkDrag(e)
    if (rotateBone) endRotateDrag(e)
  }

  pointerDownHandler = onPointerDownMain
  pointerMoveHandler = onPointerMoveMain
  pointerUpHandler = onPointerUpMain

  renderer.domElement.addEventListener('pointerdown', pointerDownHandler)
  renderer.domElement.addEventListener('pointermove', pointerMoveHandler)
  renderer.domElement.addEventListener('pointerup', pointerUpHandler)
  renderer.domElement.addEventListener('pointercancel', pointerUpHandler)
}

const detachPointerEvents = () => {
  if (!renderer?.domElement) return
  if (gesturePointerDownHandler) renderer.domElement.removeEventListener('pointerdown', gesturePointerDownHandler)
  if (gesturePointerMoveHandler) renderer.domElement.removeEventListener('pointermove', gesturePointerMoveHandler)
  if (gesturePointerUpHandler) renderer.domElement.removeEventListener('pointerup', gesturePointerUpHandler)
  if (gesturePointerUpHandler) renderer.domElement.removeEventListener('pointercancel', gesturePointerUpHandler)
  if (gestureWheelHandler) renderer.domElement.removeEventListener('wheel', gestureWheelHandler)
  if (pointerDownHandler) renderer.domElement.removeEventListener('pointerdown', pointerDownHandler)
  if (pointerMoveHandler) renderer.domElement.removeEventListener('pointermove', pointerMoveHandler)
  if (pointerUpHandler) renderer.domElement.removeEventListener('pointerup', pointerUpHandler)
  if (pointerUpHandler) renderer.domElement.removeEventListener('pointercancel', pointerUpHandler)
  pointerDownHandler = null
  pointerMoveHandler = null
  pointerUpHandler = null
  gesturePointerDownHandler = null
  gesturePointerMoveHandler = null
  gesturePointerUpHandler = null
  gestureWheelHandler = null
}

onMounted(() => {
  loadPoseHistory(1, false)
  ;(async () => {
    isBooting.value = true
    try {
      await initThree()
      if (!props.autoLoad) return
      if (props.defaultVrmUrl) await loadVrmFromUrl(props.defaultVrmUrl)
    } finally {
      isBooting.value = false
    }
  })()
})

onBeforeUnmount(() => {
  disposed = true
  if (animationId) cancelAnimationFrame(animationId)
  detachPointerEvents()
  if (resizeObserver && container.value) resizeObserver.unobserve(container.value)
  resizeObserver = null
  clearAllCharacters()
  try {
    orbitControls?.dispose?.()
  } catch {}
  try {
    renderer?.dispose?.()
  } catch {}
  if (renderer?.domElement?.parentNode) renderer.domElement.parentNode.removeChild(renderer.domElement)
  scene = null
  camera = null
  renderer = null
  orbitControls = null
  clock = null
})
</script>

<style scoped lang="scss">
.pose-panel {
  width: 820px;
  height: 660px;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.2);
  border: 1px solid #eee;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.pose-panel.is-fullscreen {
  width: 100%;
  height: 100%;
  border-radius: 0;
  box-shadow: none;
  border: 0;
}

.pose-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 12px;
  background: #f8f9fa;
  border-bottom: 1px solid #eee;
  gap: 12px;

  .title {
    font-size: 13px;
    font-weight: 700;
    color: #303133;
    white-space: nowrap;
  }

  .actions {
    display: flex;
    align-items: center;
    gap: 8px;
  }
}

.pose-panel:not(.is-fullscreen) .pose-header {
  cursor: move;
}

.pose-body {
  position: relative;
  flex: 1;
  background: #fff;
}

.pose-stage {
  position: absolute;
  inset: 0;
  background: #fff;
}

.pose-loading-mask {
  position: absolute;
  inset: 0;
  z-index: 20;
  background: rgba(255, 255, 255, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
}

.pose-loading-inner {
  font-size: 13px;
  color: #303133;
  font-weight: 600;
}

.pose-tools-float {
  position: absolute;
  top: 12px;
  left: 12px;
  z-index: 10;
  display: flex;
  gap: 8px;
}

.pose-apply-float {
  position: absolute;
  left: 12px;
  bottom: 12px;
  z-index: 13;
  display: flex;
  gap: 8px;
  pointer-events: auto;
}

.pose-apply-btn {
  padding: 10px 18px;
  font-size: 14px;
  border-radius: 8px;
}

.three-container {
  position: absolute;
  inset: 0;
  touch-action: none;
  overscroll-behavior: none;
}

.empty-tip {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  color: #909399;
  pointer-events: none;
}

.pose-history {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 220px;
  max-height: calc(100% - 24px);
  background: rgba(255, 255, 255, 0.96);
  border: 1px solid #eee;
  border-radius: 10px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
  z-index: 12;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.pose-history.is-collapsed {
  top: 50%;
  right: 0;
  width: 34px;
  max-height: 200px;
  transform: translateY(-50%);
  border-radius: 10px 0 0 10px;
}

.pose-history-title {
  padding: 8px 10px;
  font-size: 12px;
  font-weight: 700;
  color: #303133;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  user-select: none;
}

.pose-history-title-text {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pose-history-toggle-btn {
  flex: 0 0 auto;
}

.pose-history.is-collapsed .pose-history-title {
  padding: 10px 6px;
  border-bottom: 0;
  flex-direction: column;
  justify-content: center;
  gap: 6px;
  height: 100%;
}

.pose-history.is-collapsed .pose-history-title-text {
  writing-mode: vertical-rl;
  letter-spacing: 2px;
}

.pose-history.is-collapsed .pose-history-toggle-btn {
  writing-mode: vertical-rl;
  padding: 0;
}

.pose-history-list {
  padding: 10px;
  overflow: auto;
  overscroll-behavior: contain;
  touch-action: pan-y;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pose-history-loading,
.pose-history-empty {
  padding: 8px 0;
  text-align: center;
  font-size: 12px;
  color: #909399;
}

.pose-history-item {
  display: flex;
  gap: 10px;
  padding: 8px;
  border: 1px solid rgba(0, 0, 0, 0.06);
  border-radius: 10px;
  background: #fff;
  cursor: pointer;
}

.pose-history-item:hover {
  border-color: rgba(64, 158, 255, 0.4);
}

.pose-history-thumb {
  width: 64px;
  height: 64px;
  border-radius: 8px;
  overflow: hidden;
  flex: 0 0 auto;
  background: #f5f7fa;
}

.pose-history-meta {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  align-items: flex-end;
  gap: 8px;
}

.pose-history-use-btn {
  background: #111;
  border-color: #111;
  color: #fff;
}

.pose-history-use-btn:hover,
.pose-history-use-btn:focus {
  background: #000;
  border-color: #000;
  color: #fff;
}

.pose-history-use-btn:active {
  background: #000;
  border-color: #000;
  color: #fff;
}

.pose-history-time {
  width: 100%;
  text-align: right;
  font-size: 12px;
  color: #606266;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pose-footer {
  padding: 8px 12px;
  border-top: 1px solid #eee;
  background: #fff;

  .status {
    font-size: 12px;
    color: #606266;
  }
}
</style>
