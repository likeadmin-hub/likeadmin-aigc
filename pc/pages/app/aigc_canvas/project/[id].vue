<template>
    <div class="aigc-canvas-page" :class="{ 'is-dark': canvasDarkMode }">
        <header class="canvas-header">
            <div class="canvas-header__project">
                <NuxtLink class="icon-button icon-button--plain" to="/app/aigc_canvas" title="返回项目管理">
                    <el-icon><ArrowLeft /></el-icon>
                </NuxtLink>
                <div class="project-dropdown">
                    <button class="project-switch" type="button" @click.stop="showProjectMenu = !showProjectMenu">
                        <span>{{ currentProject?.name || '未命名项目' }}</span>
                        <el-icon><ArrowDown /></el-icon>
                    </button>
                    <div v-if="showProjectMenu" class="project-menu">
                        <button v-for="project in projects" :key="project.id" type="button" @click="switchProject(project.id)">
                            <span>{{ project.name }}</span>
                            <small>{{ formatProjectTime(project.updatedAt) }}</small>
                        </button>
                        <div class="project-menu__line"></div>
                        <button type="button" @click="createProjectAndSwitch">新建项目</button>
                        <button type="button" @click="renameCurrentProject">重命名项目</button>
                        <button type="button" @click="duplicateCurrentProject">复制项目</button>
                        <button type="button" class="danger" @click="deleteCurrentProject">删除项目</button>
                    </div>
                </div>
            </div>
            <div class="canvas-header__actions">
                <button class="icon-button" type="button" title="素材下载" :class="{ active: downloadableAssets.length }" @click="showDownloadDialog = true">
                    <el-icon><Download /></el-icon>
                </button>
                <button class="run-pill" type="button" :disabled="isProcessing" title="按依赖执行画布节点" @click="runCanvasWorkflow">执行画布</button>
                <button class="save-pill" type="button" @click="saveCanvas">已自动保存</button>
            </div>
        </header>

        <main class="canvas-body" @click="handlePaneUiClick">
            <ClientOnly>
                <VueFlow
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    v-model:viewport="viewport"
                    :node-types="nodeTypes"
                    :edge-types="edgeTypes"
                    :min-zoom="0.18"
                    :max-zoom="2.2"
                    :snap-to-grid="true"
                    :snap-grid="[20, 20]"
                    :default-edge-options="defaultEdgeOptions"
                    :default-viewport="defaultViewport"
                    :nodes-draggable="true"
                    :nodes-connectable="true"
                    :elements-selectable="true"
                    class="canvas-flow"
                    @connect="onConnect"
                    @pane-click="handlePaneClick"
                    @nodes-change="saveCanvasDebounced"
                    @edges-change="saveCanvasDebounced"
                    @viewport-change="saveCanvasDebounced"
                >
                    <Background :gap="20" :size="1" :pattern-color="canvasDarkMode ? '#313233' : '#d7d7d7'" />
                    <MiniMap position="bottom-right" pannable zoomable />
                </VueFlow>
            </ClientOnly>

            <aside class="canvas-toolbar">
                <button class="toolbar-button toolbar-button--primary" type="button" title="添加节点" data-tooltip="添加节点" @click.stop="showNodeMenu = !showNodeMenu">
                    <el-icon><Plus /></el-icon>
                </button>
                <button class="toolbar-button" type="button" title="工作流模板" data-tooltip="工作流模板" @click.stop="showWorkflowPanel = !showWorkflowPanel">
                    <el-icon><Grid /></el-icon>
                </button>
                <div class="toolbar-divider"></div>
                <button v-for="item in toolbarTools" :key="item.type" class="toolbar-button" type="button" :title="item.label" :data-tooltip="item.label" @mousedown.stop @click.stop="addNodeByType(item.type)">
                    <el-icon>
                        <component :is="item.icon" />
                    </el-icon>
                </button>
                <div class="toolbar-divider"></div>
                <button class="toolbar-button toolbar-button--muted" type="button" title="撤销" data-tooltip="撤销" :disabled="!canUndo" @mousedown.stop @click.stop="undo">
                    <el-icon><Back /></el-icon>
                </button>
                <button class="toolbar-button toolbar-button--muted" type="button" title="重做" data-tooltip="重做" :disabled="!canRedo" @mousedown.stop @click.stop="redo">
                    <el-icon><Right /></el-icon>
                </button>
            </aside>

            <div v-if="showNodeMenu" class="node-menu" @click.stop>
                <button v-for="item in palette" :key="item.type" type="button" @click="addNodeByType(item.type)">
                    <span class="node-menu__icon">
                        <el-icon>
                            <component :is="item.icon" />
                        </el-icon>
                    </span>
                    <span>
                        <strong>{{ item.label }}</strong>
                        <small>{{ item.description }}</small>
                    </span>
                </button>
            </div>

            <section v-if="showWorkflowPanel" class="workflow-panel" @click.stop>
                <header>
                    <div>
                        <button type="button" :class="{ active: workflowCategory === 'all' }" @click="workflowCategory = 'all'">全部</button>
                        <button v-for="category in workflowCategoryList" :key="category" type="button" :class="{ active: workflowCategory === category }" @click="workflowCategory = category">{{ categoryLabel(category) }}</button>
                    </div>
                    <button type="button" @click="showWorkflowPanel = false"><el-icon><Close /></el-icon></button>
                </header>
                <div class="workflow-grid">
                    <button v-for="workflow in visibleWorkflows" :key="workflow.id" type="button" @click="addWorkflow(workflow)">
                        <img v-if="workflow.cover" :src="workflow.cover" alt="" />
                        <strong>{{ workflow.name }}</strong>
                        <span>{{ workflow.description }}</span>
                    </button>
                </div>
            </section>

            <div class="zoom-panel">
                <button type="button" title="适应视图" @click="fitView({ padding: 0.22 })"><el-icon><Aim /></el-icon></button>
                <button type="button" title="缩小" @click="zoomOut"><el-icon><Minus /></el-icon></button>
                <span>{{ Math.round(viewport.zoom * 100) }}%</span>
                <button type="button" title="放大" @click="zoomIn"><el-icon><Plus /></el-icon></button>
            </div>

            <section class="prompt-composer" @click.stop>
                <div v-if="processingMessage" class="processing-banner">{{ processingMessage }}</div>
                <div class="prompt-composer__card">
                    <textarea v-model="prompt" rows="3" placeholder="你可以试着说“帮我生成一个二次元的卡通角色”" @keydown.enter.exact.prevent="sendPrompt" />
                    <div class="prompt-composer__actions">
                        <button class="prompt-composer__polish" type="button" :disabled="isProcessing || !prompt.trim()" title="AI 润色提示词" @click="polishPrompt">
                            <el-icon><MagicStick /></el-icon>
                            AI 润色
                        </button>
                        <div class="prompt-composer__run">
                            <label class="prompt-composer__switch">
                                <input v-model="autoExecute" type="checkbox" />
                                <span></span>
                                自动执行
                            </label>
                            <button class="prompt-composer__send" type="button" title="发送" :disabled="isProcessing" @click="sendPrompt"><el-icon><Promotion /></el-icon></button>
                        </div>
                    </div>
                </div>
                <div class="prompt-composer__footer">
                    <div>
                        <b>推荐：</b>
                        <button v-for="item in suggestions" :key="item" type="button" @click="prompt = item">{{ item }}</button>
                        <button type="button" title="换一批" @click="rotateSuggestions"><el-icon><Refresh /></el-icon></button>
                    </div>
                </div>
            </section>
        </main>

        <div v-if="showDownloadDialog" class="modal-mask" @click.self="showDownloadDialog = false">
            <section class="canvas-modal download-modal">
                <header>
                    <strong>素材下载</strong>
                    <button type="button" @click="showDownloadDialog = false"><el-icon><Close /></el-icon></button>
                </header>
                <div class="download-stats">
                    <span>图片：{{ downloadableImages.length }} 张</span>
                    <span>视频：{{ downloadableVideos.length }} 个</span>
                </div>
                <div v-if="downloadableImages.length" class="download-grid">
                    <button v-for="asset in downloadableImages" :key="asset.id" type="button" @click="downloadAsset(asset)">
                        <img :src="asset.url" alt="" />
                        <span>{{ asset.title }}</span>
                    </button>
                </div>
                <div v-if="downloadableVideos.length" class="download-list">
                    <button v-for="asset in downloadableVideos" :key="asset.id" type="button" @click="downloadAsset(asset)">
                        <el-icon><VideoCamera /></el-icon>
                        <span>{{ asset.title }}</span>
                    </button>
                </div>
                <div v-if="!downloadableAssets.length" class="workflow-empty">暂无可下载素材</div>
            </section>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, markRaw, nextTick, onMounted, provide, ref, watch } from 'vue'
import { Background } from '@vue-flow/background'
import { MiniMap } from '@vue-flow/minimap'
import { VueFlow, useVueFlow, type Connection, type Edge, type Node, type XYPosition } from '@vue-flow/core'
import {
    Aim,
    ArrowDown,
    ArrowLeft,
    Back,
    ChatDotRound,
    Close,
    Download,
    Grid,
    Files,
    MagicStick,
    Minus,
    Picture,
    PictureFilled,
    Plus,
    Promotion,
    Refresh,
    Right,
    VideoCamera,
    Notebook,
    Connection as ConnectionIcon
} from '@element-plus/icons-vue'
import CanvasNode from '@/components/aigc-canvas/canvas-node.vue'
import CanvasOrderEdge from '@/apps/aigc_canvas/components/edges/CanvasOrderEdge.vue'
import {
    getAigcCanvasConfig,
    proxyImage,
    proxyImageQuery,
    proxyVideo,
    proxyVideoQuery,
    streamCanvasText
} from '@/apps/aigc_canvas/api'
import { WORKFLOW_TEMPLATES, workflowCategories } from '@/apps/aigc_canvas/config/workflows'
import { canvasDarkMode, initCanvasSettings } from '@/apps/aigc_canvas/stores/settings'
import { cloneDeep, createCanvasProject, duplicateCanvasProject, ensureProjectCanvas, formatProjectTime, loadCanvasProjects, saveCanvasProjects, updateProjectThumbnail } from '@/apps/aigc_canvas/stores/projects'
import type { CanvasNodeVariant, CanvasProject, DownloadAsset, WorkflowTemplate } from '@/apps/aigc_canvas/types'
import { CANVAS_NODE_TYPE, collectConnectedImages, collectConnectedPrompt, createCanvasEdge, createCanvasNode, edgeFromConnection } from '@/apps/aigc_canvas/utils/graph'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { downloadPcAsset } from '@/utils/download'
import feedback from '@/utils/feedback'

definePageMeta({ layout: 'blank', auth: true })

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const defaultViewport = { x: 80, y: 30, zoom: 0.84 }
const REMOTE_PROJECT_ID = 0
const { fitView, viewport, zoomIn, zoomOut, updateNodeInternals } = useVueFlow()

const nodeTypes = { [CANVAS_NODE_TYPE]: markRaw(CanvasNode) }
const edgeTypes = {
    smoothstep: markRaw(CanvasOrderEdge),
    promptOrder: markRaw(CanvasOrderEdge),
    imageOrder: markRaw(CanvasOrderEdge),
    imageRole: markRaw(CanvasOrderEdge)
}
const defaultEdgeOptions = { type: 'smoothstep', style: { stroke: '#c9c9c9', strokeWidth: 2 } }

const projects = ref<CanvasProject[]>([])
const currentProjectId = ref('')
const nodes = ref<Node[]>([])
const edges = ref<Edge[]>([])
const canvasConfig = ref<any>({})
const history = ref<Array<{ nodes: Node[]; edges: Edge[] }>>([])
const historyIndex = ref(-1)
const isRestoring = ref(false)
const saveTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const nodeCounter = ref(0)
const imagePollOptions = { attempts: 240, interval: 30000 }
const videoPollOptions = { attempts: 240, interval: 30000 }

const showProjectMenu = ref(false)
const showNodeMenu = ref(false)
const showWorkflowPanel = ref(false)
const showDownloadDialog = ref(false)
const prompt = ref('')
const autoExecute = ref(false)
const isProcessing = ref(false)
const processingMessage = ref('')
const workflowCategory = ref('all')
const suggestionOffset = ref(0)
const WORKFLOW_TYPES = {
    TEXT_TO_IMAGE: 'text_to_image',
    TEXT_TO_IMAGE_TO_VIDEO: 'text_to_image_to_video',
    STORYBOARD: 'storyboard',
    MULTI_ANGLE_STORYBOARD: 'multi_angle_storyboard',
    PICTURE_BOOK: 'picture_book'
} as const

const LLM_OUTPUT_FORMAT_PROMPTS: Record<string, string> = {
    text: '输出格式要求：只输出正文，不要输出 JSON、Markdown 代码块、前言或解释。',
    json: '输出格式要求：只输出可被 JSON.parse 解析的 JSON，不要输出 Markdown 代码块、前言或解释。',
    markdown: '输出格式要求：输出 Markdown 正文，不要包裹在代码块中。',
    image_storyboard: `你是专业的图片分镜提示词生成助手。请根据用户输入和参考图片，生成一组可直接用于 AI 生图的分镜提示词。

输出要求：
1. 只输出图片分镜内容，不要输出解释、前言、总结或代码块。
2. 每张图片必须以“###第X张”开头，X 可以是中文数字或阿拉伯数字。
3. 每张图片下面必须给出完整、可直接用于生图的中文提示词，不能只写标题、短句或抽象概念。
4. 每张提示词必须包含主体、场景、动作/姿态、镜头景别、构图、光线、色彩、画风、情绪氛围、细节元素；如有角色或产品，需要保持前后一致。
5. 如果需要多张图片，按第三张、第四张继续递增。
6. 不要使用英文，除非用户明确要求英文。
7. 不要输出“标题：”“提示词：”等多余字段；每张只保留标题行和完整提示词正文。`
}

type WorkflowType = (typeof WORKFLOW_TYPES)[keyof typeof WORKFLOW_TYPES]

interface WorkflowAnalysisPlan {
    workflow_type: WorkflowType
    description: string
    image_prompt: string
    video_prompt: string
    character?: { name?: string; description?: string }
    shots?: Array<{ title: string; prompt: string }>
    multi_angle?: { character_description?: string }
    picture_book?: {
        title?: string
        style?: string
        character?: { name?: string; description?: string }
        pages?: Array<{ page_number: number; story_text: string; illustration_prompt: string }>
    }
}

const WORKFLOW_ANALYSIS_PROMPT = `你是无限画布的工作流编排助手。请根据用户输入，只返回纯 JSON，不要 markdown，不要解释。

可选 workflow_type:
- text_to_image: 单图生成
- text_to_image_to_video: 先生成图片，再生成视频
- storyboard: 分镜/多场景连续画面
- multi_angle_storyboard: 同一角色多角度分镜
- picture_book: 儿童绘本

输出格式：
{
  "workflow_type": "text_to_image | text_to_image_to_video | storyboard | multi_angle_storyboard | picture_book",
  "description": "简短描述",
  "image_prompt": "适合图片生成的提示词",
  "video_prompt": "适合视频生成的提示词",
  "character": {
    "name": "角色名",
    "description": "角色描述"
  },
  "shots": [
    {
      "title": "分镜标题",
      "prompt": "该分镜的详细画面描述"
    }
  ],
  "multi_angle": {
    "character_description": "多角度角色描述"
  },
  "picture_book": {
    "title": "绘本标题",
    "style": "绘本风格",
    "character": {
      "name": "主角名称",
      "description": "主角描述"
    },
    "pages": [
      {
        "page_number": 1,
        "story_text": "故事文字",
        "illustration_prompt": "插画提示词"
      }
    ]
  }
}

规则：
1. 默认优先返回 text_to_image。
2. 输入中包含“视频/动画/运镜/动起来”时返回 text_to_image_to_video。
3. 输入中包含“分镜/镜头/场景/连续故事”时返回 storyboard。
4. 输入中包含“多角度/正视/侧视/后视/俯视/四宫格”时返回 multi_angle_storyboard。
5. 输入中包含“绘本/童话/故事书/儿童故事”时返回 picture_book。
6. 所有 title、description、image_prompt、video_prompt、character.description、shots.prompt、pages.story_text、pages.illustration_prompt 必须使用用户输入的语言；用户用中文时必须输出中文。
7. 不要把用户输入翻译成英文，不要为了生图把提示词改成英文，除非用户明确要求英文。
8. 图片提示词要适合生图，视频提示词要描述镜头和运动。
9. 如果缺少 shots 或 pages，请补全为可直接执行的内容，并继续使用用户输入的语言。
`

const currentProject = computed(() => projects.value.find((item) => item.id === currentProjectId.value))
const canUndo = computed(() => historyIndex.value > 0)
const canRedo = computed(() => historyIndex.value < history.value.length - 1)
const workflowCategoryList = workflowCategories()
const visibleWorkflows = computed(() => (workflowCategory.value === 'all' ? WORKFLOW_TEMPLATES : WORKFLOW_TEMPLATES.filter((item) => item.category === workflowCategory.value)))
const allSuggestions = ['短剧角色设计', '产品电商主图', '多角度分镜', '儿童绘本故事', '雨中魔法森林', '图生视频镜头推进']
const suggestions = computed(() => allSuggestions.slice(suggestionOffset.value, suggestionOffset.value + 4).concat(allSuggestions.slice(0, Math.max(0, suggestionOffset.value + 4 - allSuggestions.length))))
const imageOptionConfig = computed(() => canvasConfig.value?.image?.option_config || { channels: [], defaults: {}, quantity_options: [1] })
const videoOptionConfig = computed(() => canvasConfig.value?.video?.option_config || { channels: [], defaults: {}, quantity_options: [1] })
const imageChannelModels = computed(() => channelModels(imageOptionConfig.value))
const videoChannelModels = computed(() => channelModels(videoOptionConfig.value))
const chatModelOptions = computed(() =>
    (canvasConfig.value?.text?.models || []).map((model: any) => ({
        label: model.name || model.code,
        key: model.code,
        disabled: Number(model.status ?? 1) !== 1
    }))
)

const palette: Array<{ type: CanvasNodeVariant; label: string; description: string; icon: any }> = [
    { type: 'text', label: '文本输入', description: '编写提示词、角色描述和创作需求', icon: Notebook },
    { type: 'llmConfig', label: 'LLM 文本', description: '结构化拆解、润色或生成提示词', icon: ChatDotRound },
    { type: 'imageConfig', label: '图片生成', description: '接收提示词并生成图片', icon: PictureFilled },
    { type: 'imagePrompt', label: '图生图提示词', description: '围绕参考图补充二创要求', icon: Files },
    { type: 'image', label: '图片节点', description: '上传图片或承接生成结果', icon: Picture },
    { type: 'videoConfig', label: '视频生成', description: '接收图片和提示词生成视频', icon: ConnectionIcon },
    { type: 'video', label: '视频节点', description: '展示视频生成结果', icon: VideoCamera }
]
const toolbarTools = palette.filter((item) => ['text', 'llmConfig', 'image', 'imageConfig', 'videoConfig'].includes(item.type))

const downloadableImages = computed<DownloadAsset[]>(() =>
    nodes.value
        .filter((node) => ['image', 'imageResult'].includes(String(node.data?.variant)) && (node.data?.image || node.data?.url))
        .map((node) => ({ id: node.id, title: String(node.data?.title || '图片'), url: String(node.data?.image || node.data?.url), type: 'image' }))
)
const downloadableVideos = computed<DownloadAsset[]>(() =>
    nodes.value
        .filter((node) => node.data?.variant === 'video' && node.data?.url)
        .map((node) => ({ id: node.id, title: String(node.data?.title || '视频'), url: String(node.data?.url), type: 'video' }))
)
const downloadableAssets = computed(() => [...downloadableImages.value, ...downloadableVideos.value])

function channelModels(optionConfig: any) {
    return (optionConfig?.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        key: channel.value || channel.code
    }))
}

function qualityOptions(optionConfig: any, channelCode = '') {
    const channel = findChannel(optionConfig, channelCode) || optionConfig?.channels?.[0]
    return (channel?.qualities || []).map((quality: any) => ({
        label: quality.label || quality.quality_label || quality.value,
        key: quality.value || quality.quality
    }))
}

function currentRatioOptions(optionConfig: any, channelCode = '', qualityValue = '') {
    const channel = findChannel(optionConfig, channelCode) || optionConfig?.channels?.[0]
    const qualities = channel?.qualities || []
    const quality = qualities.find((item: any) => String(item.value || item.quality) === String(qualityValue)) || qualities[0]
    return (quality?.ratios || []).map((ratio: any) => ({
        label: ratio.label || ratio.ratio || ratio.value,
        key: ratio.value || ratio.ratio
    }))
}

function findChannel(optionConfig: any, channelCode = '') {
    return (optionConfig?.channels || []).find((channel: any) => String(channel.value || channel.code) === String(channelCode))
}

function normalizeSelection(optionConfig: any, payload: { channel?: string; quality?: string; ratio?: string }) {
    const channels = optionConfig?.channels || []
    const defaults = optionConfig?.defaults || {}
    const channel = channels.find((item: any) => String(item.value || item.code) === String(payload.channel || defaults.channel)) || channels[0]
    const channelCode = String(channel?.value || channel?.code || payload.channel || defaults.channel || '')
    const qualities = channel?.qualities || []
    const quality = qualities.find((item: any) => String(item.value || item.quality) === String(payload.quality || defaults.quality)) || qualities[0]
    const qualityCode = String(quality?.value || quality?.quality || payload.quality || defaults.quality || '')
    const ratios = quality?.ratios || []
    const ratio = ratios.find((item: any) => String(item.value || item.ratio) === String(payload.ratio || defaults.ratio)) || ratios[0]
    const ratioCode = String(ratio?.value || ratio?.ratio || payload.ratio || defaults.ratio || '')
    return { channel: channelCode, quality: qualityCode, ratio: ratioCode }
}

function normalizeImageNodeData(data: Record<string, any> = {}) {
    const selection = normalizeSelection(imageOptionConfig.value, {
        channel: String(data.model || ''),
        quality: String(data.quality || ''),
        ratio: String(data.size || data.ratio || '')
    })
    if (!selection.channel) return data
    return { ...data, model: selection.channel, quality: selection.quality, size: selection.ratio }
}

function normalizeVideoNodeData(data: Record<string, any> = {}) {
    const selection = normalizeSelection(videoOptionConfig.value, {
        channel: String(data.model || ''),
        quality: String(data.duration || data.quality || ''),
        ratio: String(data.ratio || data.size || '')
    })
    if (!selection.channel) return data
    return { ...data, model: selection.channel, duration: selection.quality, ratio: selection.ratio }
}

function normalizeLlmNodeData(data: Record<string, any> = {}) {
    const models = canvasConfig.value?.text?.models || []
    const defaults = canvasConfig.value?.text?.defaults || {}
    const current = String(data.model || defaults.model || '')
    const model = models.find((item: any) => String(item.code) === current) || models[0]
    const outputFormat = LLM_OUTPUT_FORMAT_PROMPTS[data.outputFormat] ? data.outputFormat : 'text'
    const imageSelection = normalizeSelection(imageOptionConfig.value, {
        channel: String(data.imageModel || ''),
        quality: String(data.imageQuality || ''),
        ratio: String(data.imageSize || data.imageRatio || '')
    })
    const normalized = imageSelection.channel
        ? { ...data, outputFormat, imageModel: imageSelection.channel, imageQuality: imageSelection.quality, imageSize: imageSelection.ratio }
        : { ...data, outputFormat }
    return model ? { ...normalized, model: model.code } : normalized
}

function normalizeConfigNodeDefaults(node: Node): Node {
    if (node.data?.variant === 'imageConfig') {
        return { ...node, data: normalizeImageNodeData(node.data || {}) }
    }
    if (node.data?.variant === 'videoConfig') {
        return { ...node, data: normalizeVideoNodeData(node.data || {}) }
    }
    if (node.data?.variant === 'llmConfig') {
        return { ...node, data: normalizeLlmNodeData(node.data || {}) }
    }
    return node
}

function applyDefaultSelectionsToNodes(save = false) {
    if (!imageOptionConfig.value?.channels?.length && !videoOptionConfig.value?.channels?.length && !canvasConfig.value?.text?.models?.length) return
    let changed = false
    nodes.value = nodes.value.map((node) => {
        const normalized = normalizeConfigNodeDefaults(node)
        if (JSON.stringify(normalized.data) !== JSON.stringify(node.data)) changed = true
        return normalized
    })
    if (changed) {
        refreshConfigCounts()
        if (save) saveCanvasDebounced()
    }
}

async function loadCanvasConfig() {
    try {
        canvasConfig.value = await getAigcCanvasConfig()
        applyDefaultSelectionsToNodes(true)
    } catch (error: any) {
        processingMessage.value = error?.msg || error?.message || '通道配置加载失败'
        setTimeout(() => (processingMessage.value = ''), 1800)
    }
}

function persistProjects() {
    saveCanvasProjects(projects.value)
}

async function saveCanvas() {
    if (!userStore.isLogin) return
    const project = currentProject.value
    if (!project) return
    project.nodes = cloneDeep(nodes.value)
    project.edges = cloneDeep(edges.value)
    project.viewport = cloneDeep(viewport.value)
    project.updatedAt = Date.now()
    updateProjectThumbnail(project, nodes.value)
    projects.value = [project, ...projects.value.filter((item) => item.id !== project.id)].sort((a, b) => b.updatedAt - a.updatedAt)
    persistProjects()
}

function saveCanvasDebounced() {
    if (isRestoring.value) return
    if (saveTimer.value) clearTimeout(saveTimer.value)
    saveTimer.value = setTimeout(saveCanvas, 420)
}

async function switchProject(id: string, saveCurrent = true) {
    if (!ensurePcLogin()) return
    if (saveCurrent) await saveCanvas()
    const project = projects.value.find((item) => item.id === id)
    if (!project) return
    currentProjectId.value = id
    const target = ensureProjectCanvas(project)
    nodes.value = cloneDeep(target.nodes || [])
    edges.value = cloneDeep(target.edges || [])
    viewport.value = target.viewport || defaultViewport
    applyDefaultSelectionsToNodes(true)
    resetHistory()
    showProjectMenu.value = false
    if (String(route.params.id) !== id) router.replace(`/app/aigc_canvas/project/${id}`)
    nextTick(() => nodes.value.forEach((node) => updateNodeInternals(node.id)))
}

async function createProjectAndSwitch() {
    if (!ensurePcLogin()) return
    const name = await inputProjectName('新建项目', '未命名项目')
    if (!name) return
    const project = createCanvasProject(name)
    projects.value = [project, ...projects.value]
    persistProjects()
    switchProject(project.id)
}

async function renameCurrentProject() {
    if (!ensurePcLogin()) return
    if (!currentProject.value) return
    const name = await inputProjectName('重命名项目', currentProject.value.name)
    if (!name) return
    currentProject.value.name = name
    currentProject.value.updatedAt = Date.now()
    projects.value = [currentProject.value, ...projects.value.filter((item) => item.id !== currentProject.value?.id)]
    persistProjects()
    showProjectMenu.value = false
}

async function duplicateCurrentProject() {
    if (!ensurePcLogin()) return
    if (!currentProject.value) return
    const copy = duplicateCanvasProject(currentProject.value)
    projects.value = [copy, ...projects.value]
    persistProjects()
    switchProject(copy.id)
}

async function deleteCurrentProject() {
    if (!ensurePcLogin()) return
    if (!currentProject.value) return
    try {
        await feedback.confirm(`确定删除「${currentProject.value.name}」吗？`)
    } catch {
        return
    }
    projects.value = projects.value.filter((item) => item.id !== currentProjectId.value)
    if (!projects.value.length) {
        projects.value = [createCanvasProject('未命名项目')]
    }
    persistProjects()
    switchProject(projects.value[0].id, false)
}

function pushHistory() {
    if (isRestoring.value) return
    if (historyIndex.value < history.value.length - 1) history.value = history.value.slice(0, historyIndex.value + 1)
    history.value.push({ nodes: cloneDeep(nodes.value), edges: cloneDeep(edges.value) })
    if (history.value.length > 50) history.value.shift()
    historyIndex.value = history.value.length - 1
    saveCanvas()
}

async function inputProjectName(title: string, value: string) {
    try {
        const result: any = await feedback.prompt('请输入项目名称', title, {
            inputValue: value,
            inputPlaceholder: '请输入项目名称',
            inputValidator(input: string) {
                return input.trim() ? true : '项目名称不能为空'
            },
            customClass: 'aigc-canvas-message-box'
        })
        return String(result?.value || '').trim()
    } catch {
        return ''
    }
}

function resetHistory() {
    history.value = [{ nodes: cloneDeep(nodes.value), edges: cloneDeep(edges.value) }]
    historyIndex.value = 0
}

function restoreHistory(index: number) {
    const state = history.value[index]
    if (!state) return
    isRestoring.value = true
    nodes.value = cloneDeep(state.nodes)
    edges.value = cloneDeep(state.edges)
    historyIndex.value = index
    nextTick(() => {
        isRestoring.value = false
        saveCanvas()
    })
}

function undo() {
    if (canUndo.value) restoreHistory(historyIndex.value - 1)
}

function redo() {
    if (canRedo.value) restoreHistory(historyIndex.value + 1)
}

function viewportCenter() {
    return {
        x: -viewport.value.x / viewport.value.zoom + window.innerWidth / 2 / viewport.value.zoom,
        y: -viewport.value.y / viewport.value.zoom + window.innerHeight / 2 / viewport.value.zoom
    }
}

async function addNodeByType(type: CanvasNodeVariant, data: Record<string, any> = {}) {
    const center = viewportCenter()
    const id = `${type}_${Date.now()}_${nodeCounter.value++}`
    const node = normalizeConfigNodeDefaults(createCanvasNode(type, { x: center.x - 140 + nodeCounter.value * 18, y: center.y - 90 + nodeCounter.value * 10 }, data, id))
    nodes.value = [...nodes.value, node]
    showNodeMenu.value = false
    await nextTick()
    updateNodeInternals(id)
    pushHistory()
}

function addWorkflow(workflow: WorkflowTemplate) {
    const center = viewportCenter()
    const created = workflow.create({ x: center.x - 420, y: center.y - 260 })
    nodes.value = [...nodes.value, ...(created.nodes as Node[]).map(normalizeConfigNodeDefaults)]
    edges.value = [...edges.value, ...(created.edges as Edge[])]
    showWorkflowPanel.value = false
    nextTick(() => created.nodes.forEach((node) => updateNodeInternals(node.id)))
    pushHistory()
}

function onConnect(params: Connection) {
    const edge = edgeFromConnection(params, nodes.value, edges.value)
    if (!edge) return
    edges.value = [...edges.value, edge]
    refreshConfigCounts()
    pushHistory()
}

function handlePaneClick() {
    showNodeMenu.value = false
    showWorkflowPanel.value = false
}

function handlePaneUiClick() {
    showProjectMenu.value = false
}

function updateNodeData(id: string, data: Record<string, any>, save = true) {
    const current = nodes.value.find((node) => node.id === id)
    if (current?.data?.variant === 'imageConfig') {
        if (data.model) {
            const nextQuality = qualityOptions(imageOptionConfig.value, String(data.model))[0]?.key || ''
            data.quality = nextQuality
            data.size = currentRatioOptions(imageOptionConfig.value, String(data.model), String(nextQuality))[0]?.key || ''
        }
        if (data.quality) {
            data.size = currentRatioOptions(imageOptionConfig.value, data.model || current.data?.model || '', String(data.quality))[0]?.key || data.size
        }
    }
    if (current?.data?.variant === 'videoConfig') {
        if (data.model) {
            const nextQuality = qualityOptions(videoOptionConfig.value, String(data.model))[0]?.key || ''
            data.duration = nextQuality
            data.ratio = currentRatioOptions(videoOptionConfig.value, String(data.model), String(nextQuality))[0]?.key || ''
        }
        if (data.duration) {
            data.ratio = currentRatioOptions(videoOptionConfig.value, data.model || current.data?.model || '', String(data.duration))[0]?.key || data.ratio
        }
    }
    if (current?.data?.variant === 'llmConfig') {
        if (data.imageModel) {
            const nextQuality = qualityOptions(imageOptionConfig.value, String(data.imageModel))[0]?.key || ''
            data.imageQuality = nextQuality
            data.imageSize = currentRatioOptions(imageOptionConfig.value, String(data.imageModel), String(nextQuality))[0]?.key || ''
        }
        if (data.imageQuality) {
            data.imageSize = currentRatioOptions(imageOptionConfig.value, data.imageModel || current.data?.imageModel || '', String(data.imageQuality))[0]?.key || data.imageSize
        }
    }
    nodes.value = nodes.value.map((node) => (node.id === id ? { ...node, data: { ...node.data, ...data, updatedAt: Date.now() } } : node))
    if (save) pushHistory()
    else saveCanvasDebounced()
}

function updateEdgeData(id: string, data: Record<string, any>) {
    edges.value = edges.value.map((edge) => (edge.id === id ? { ...edge, data: { ...edge.data, ...data }, label: data.promptOrder || edge.data?.promptOrder ? String(data.promptOrder || edge.data?.promptOrder) : data.imageOrder || edge.data?.imageOrder ? String(data.imageOrder || edge.data?.imageOrder) : '' } : edge))
    refreshConfigCounts()
    pushHistory()
}

function removeEdge(id: string) {
    edges.value = edges.value.filter((edge) => edge.id !== id)
    refreshConfigCounts()
    pushHistory()
}

function duplicateNode(id: string) {
    const source = nodes.value.find((node) => node.id === id)
    if (!source) return
    const copy = cloneDeep(source)
    copy.id = `${source.data?.variant || 'node'}_${Date.now()}_${nodeCounter.value++}`
    copy.position = { x: source.position.x + 46, y: source.position.y + 46 }
    nodes.value = [...nodes.value, normalizeConfigNodeDefaults(copy)]
    pushHistory()
}

function removeNode(id: string) {
    nodes.value = nodes.value.filter((node) => node.id !== id)
    edges.value = edges.value.filter((edge) => edge.source !== id && edge.target !== id)
    refreshConfigCounts()
    pushHistory()
}

function addConnectedNode(sourceId: string, type: CanvasNodeVariant) {
    const source = nodes.value.find((node) => node.id === sourceId)
    if (!source) return
    const createdNodes: Node[] = []
    const createdEdges: Edge[] = []
    const createNode = (variant: CanvasNodeVariant, position: XYPosition, data: Record<string, any> = {}) => {
        const id = `${variant}_${Date.now()}_${nodeCounter.value++}`
        const node = normalizeConfigNodeDefaults(createCanvasNode(variant, position, data, id))
        createdNodes.push(node)
        return node
    }
    const createSmartEdge = (sourceNodeId: string, targetNodeId: string, baseNodes: Node[], data?: Record<string, any>, typeOverride?: string) => {
        if (typeOverride || data) return createCanvasEdge(sourceNodeId, targetNodeId, typeOverride || 'smoothstep', data || {})
        return edgeFromConnection({ source: sourceNodeId, target: targetNodeId, sourceHandle: 'right', targetHandle: 'left' }, baseNodes, [...edges.value, ...createdEdges]) || createCanvasEdge(sourceNodeId, targetNodeId)
    }
    const sourceVariant = String(source.data?.variant || '')

    if (['image', 'imageResult'].includes(sourceVariant) && type === 'imagePrompt') {
        const promptNode = createNode('imagePrompt', { x: source.position.x + 300, y: source.position.y - 100 }, { title: '图生图提示词', content: '' })
        createdEdges.push(createCanvasEdge(source.id, promptNode.id))
    } else if (['image', 'imageResult'].includes(sourceVariant) && type === 'imageConfig') {
        const promptNode = createNode('imagePrompt', { x: source.position.x + 300, y: source.position.y - 100 }, { title: '图生图提示词', content: '' })
        const configNode = createNode('imageConfig', { x: source.position.x + 900, y: source.position.y }, { title: '图片生成' })
        const baseNodes = [...nodes.value, ...createdNodes]
        createdEdges.push(createSmartEdge(source.id, configNode.id, baseNodes))
        createdEdges.push(createSmartEdge(promptNode.id, configNode.id, baseNodes))
    } else if (['image', 'imageResult'].includes(sourceVariant) && type === 'videoConfig') {
        const promptNode = createNode('imagePrompt', { x: source.position.x + 300, y: source.position.y - 100 }, { title: '视频提示词', content: '' })
        const configNode = createNode('videoConfig', { x: source.position.x + 600, y: source.position.y }, { title: '视频生成' })
        const baseNodes = [...nodes.value, ...createdNodes]
        createdEdges.push(createCanvasEdge(source.id, configNode.id, 'imageRole', { imageRole: 'first_frame_image', imageOrder: 1 }))
        createdEdges.push(createSmartEdge(promptNode.id, configNode.id, baseNodes))
    } else if (sourceVariant === 'video' && type === 'videoConfig') {
        const promptNode = createNode('text', { x: source.position.x + 300, y: source.position.y - 100 }, { title: '视频提示词', content: '' })
        const configNode = createNode('videoConfig', { x: source.position.x + 600, y: source.position.y }, { title: '视频生成' })
        const baseNodes = [...nodes.value, ...createdNodes]
        createdEdges.push(createSmartEdge(promptNode.id, configNode.id, baseNodes))
    } else {
        const node = createNode(type, { x: source.position.x + 380, y: source.position.y }, {})
        const baseNodes = [...nodes.value, ...createdNodes]
        createdEdges.push(createSmartEdge(sourceId, node.id, baseNodes))
    }

    nodes.value = [...nodes.value, ...createdNodes]
    edges.value = [...edges.value, ...createdEdges]
    refreshConfigCounts()
    nextTick(() => createdNodes.forEach((node) => updateNodeInternals(node.id)))
    pushHistory()
}

async function runNode(id: string) {
    const node = nodes.value.find((item) => item.id === id)
    if (!node) return
    if (node.data?.variant === 'imageConfig') await runImageConfig(node)
    if (node.data?.variant === 'videoConfig') await runVideoConfig(node)
    if (node.data?.variant === 'llmConfig') await runLlmConfig(node)
}

async function runImageConfig(node: Node, options: { quiet?: boolean } = {}) {
    updateNodeData(node.id, { status: 'running', error: '' }, false)
    if (!options.quiet) processingMessage.value = '正在生成图片...'
    try {
        const promptText = collectConnectedPrompt(node.id, nodes.value, edges.value) || node.data?.prompt || prompt.value
        if (!promptText) throw new Error('请先连接或输入提示词')
        const refs = collectConnectedImages(node.id, nodes.value, edges.value).map((item) => item.url)
        const selection = normalizeSelection(imageOptionConfig.value, {
            channel: String(node.data?.model || ''),
            quality: String(node.data?.quality || ''),
            ratio: String(node.data?.size || '')
        })
        if (!selection.channel) throw new Error('暂无可用生图通道')
        const result: any = await proxyImage({
            project_id: REMOTE_PROJECT_ID,
            node_id: node.id,
            channel: selection.channel,
            prompt: promptText,
            ratio: selection.ratio,
            quality: selection.quality,
            reference_images: refs,
            quantity: 1,
            negative_prompt: ''
        })
        let images = result?.images || result?.results || []
        if (!images.length && result?.taskId) {
            if (!options.quiet) processingMessage.value = '图片任务已创建，正在轮询结果...'
            updateNodeData(node.id, { taskId: result.taskId }, false)
            images = await pollImageResult(result.taskId)
        }
        if (!images.length && result?.status === 'failed') throw new Error(result.error || '图片生成失败')
        const imageUrl = images?.[0]?.url || ''
        if (!imageUrl) throw new Error('未返回图片地址')
        const resultId = ensureOutputNode(node, 'image', {
            title: '图片生成结果',
            subtitle: selection.channel,
            image: imageUrl,
            url: imageUrl,
            public: true,
            publicName: '生成图',
            status: 'success',
            taskId: result?.taskId || ''
        })
        updateNodeData(node.id, { status: 'success', outputNodeId: resultId, taskId: result?.taskId || '' }, false)
        nextTick(() => updateNodeInternals(resultId))
        pushHistory()
    } catch (error: any) {
        const message = error?.message || String(error) || '生成失败'
        updateNodeData(node.id, { status: message.includes('仍在排队') ? 'running' : 'failed', error: message }, false)
    } finally {
        if (!options.quiet) processingMessage.value = ''
        saveCanvasDebounced()
    }
}

async function runVideoConfig(node: Node) {
    updateNodeData(node.id, { status: 'running', error: '' }, false)
    processingMessage.value = '正在创建视频任务...'
    try {
        const promptText = collectConnectedPrompt(node.id, nodes.value, edges.value) || node.data?.prompt || prompt.value
        if (!promptText) throw new Error('请先连接或输入提示词')
        const images = collectConnectedImages(node.id, nodes.value, edges.value)
        const first = images.find((item) => item.role === 'first_frame_image')?.url || images[0]?.url
        const last = images.find((item) => item.role === 'last_frame_image')?.url
        const refs = images.map((item) => item.url)
        const selection = normalizeSelection(videoOptionConfig.value, {
            channel: String(node.data?.model || ''),
            quality: String(node.data?.duration || ''),
            ratio: String(node.data?.ratio || '')
        })
        if (!selection.channel) throw new Error('暂无可用视频通道')
        const result: any = await proxyVideo({
            project_id: REMOTE_PROJECT_ID,
            node_id: node.id,
            channel: selection.channel,
            prompt: promptText,
            first_frame_image: first,
            last_frame_image: last,
            reference_images: refs,
            ratio: selection.ratio,
            quality: selection.quality,
            quantity: 1,
            negative_prompt: ''
        })
        let url = result.url || ''
        if (!url && result.taskId) {
            processingMessage.value = '视频任务已创建，正在轮询结果...'
            updateNodeData(node.id, { taskId: result.taskId }, false)
            url = await pollVideoResult(result.taskId)
        }
        const videoId = ensureOutputNode(node, 'video', {
            title: '视频生成结果',
            url,
            poster: first || '',
            duration: Number(selection.quality) || node.data?.duration || 5,
            taskId: result.taskId,
            status: url ? 'success' : 'running'
        })
        updateNodeData(node.id, { status: 'success', outputNodeId: videoId, taskId: result.taskId || '' }, false)
        pushHistory()
    } catch (error: any) {
        const message = error?.message || String(error) || '视频生成失败'
        updateNodeData(node.id, { status: message.includes('仍在排队') ? 'running' : 'failed', error: message }, false)
    } finally {
        processingMessage.value = ''
        saveCanvasDebounced()
    }
}

function ensureOutputNode(source: Node, type: 'image' | 'video', data: Record<string, any>) {
    const allowed = type === 'image' ? ['image', 'imageResult'] : ['video']
    const edge = edges.value.find((item) => item.source === source.id && allowed.includes(String(nodes.value.find((node) => node.id === item.target)?.data?.variant)))
    const existing = edge ? nodes.value.find((node) => node.id === edge.target) : undefined
    if (existing) {
        nodes.value = nodes.value.map((node) => (node.id === existing.id ? { ...node, data: { ...node.data, ...data, updatedAt: Date.now() } } : node))
        return existing.id
    }
    const id = `${type}_${Date.now()}_${nodeCounter.value++}`
    nodes.value = [...nodes.value, normalizeConfigNodeDefaults(createCanvasNode(type, { x: source.position.x + 380, y: source.position.y }, data, id))]
    edges.value = [...edges.value, createCanvasEdge(source.id, id)]
    return id
}

async function runCanvasWorkflow(targetNodeIds: string[] = []) {
    if (isProcessing.value && !targetNodeIds.length) return
    const runnable = nodes.value
        .filter((node) => ['imageConfig', 'videoConfig', 'llmConfig'].includes(String(node.data?.variant)))
        .filter((node) => !targetNodeIds.length || targetNodeIds.includes(node.id))
        .sort((a, b) => a.position.x - b.position.x || a.position.y - b.position.y)
    if (!runnable.length) {
        processingMessage.value = '当前画布没有可执行节点'
        setTimeout(() => (processingMessage.value = ''), 1600)
        return
    }
    isProcessing.value = true
    try {
        for (const node of runnable) {
            const current = nodes.value.find((item) => item.id === node.id)
            if (!current) continue
            processingMessage.value = `正在执行：${current.data?.title || '节点'}`
            await runNode(current.id)
        }
    } finally {
        processingMessage.value = ''
        isProcessing.value = false
        saveCanvasDebounced()
    }
}

async function runImageConfigsBatch(nodeIds: string[]) {
    const uniqueIds = Array.from(new Set(nodeIds))
    const imageConfigs = uniqueIds
        .map((id) => nodes.value.find((node) => node.id === id))
        .filter((node): node is Node => Boolean(node && node.data?.variant === 'imageConfig'))
    if (!imageConfigs.length) return

    isProcessing.value = true
    processingMessage.value = `正在提交 ${imageConfigs.length} 个图片任务...`
    try {
        let submittedCount = 0
        await Promise.all(
            imageConfigs.map(async (node) => {
                const current = nodes.value.find((item) => item.id === node.id)
                if (!current) return
                submittedCount += 1
                processingMessage.value = `正在提交图片任务 ${submittedCount}/${imageConfigs.length}`
                await runImageConfig(current, { quiet: true })
            })
        )
    } finally {
        processingMessage.value = ''
        isProcessing.value = false
        saveCanvasDebounced()
    }
}

async function pollVideoResult(taskId: string) {
    for (let index = 0; index < videoPollOptions.attempts; index++) {
        const result: any = await proxyVideoQuery(taskId)
        if (result.url) return result.url
        if (result.status === 'failed') throw new Error(result.error || '视频生成失败')
        await new Promise((resolve) => setTimeout(resolve, videoPollOptions.interval))
    }
    throw new Error('视频生成仍在排队，请稍后刷新查看')
}

async function pollImageResult(taskId: string) {
    for (let index = 0; index < imagePollOptions.attempts; index++) {
        const result: any = await proxyImageQuery(taskId)
        const images = result.images || result.results || []
        if (images.length) return images
        if (result.status === 'failed') throw new Error(result.error || '图片生成失败')
        await new Promise((resolve) => setTimeout(resolve, imagePollOptions.interval))
    }
    throw new Error('图片生成仍在排队，请稍后刷新查看')
}

async function resumePolling() {
    const runningImageConfigs = nodes.value.filter((node) => node.data?.variant === 'imageConfig' && node.data?.status === 'running' && node.data?.taskId)
    for (const config of runningImageConfigs) {
        pollImageNode(config.id, String(config.data?.taskId)).catch(() => undefined)
    }
    const runningConfigs = nodes.value.filter((node) => node.data?.variant === 'videoConfig' && node.data?.status === 'running' && node.data?.taskId)
    for (const config of runningConfigs) {
        pollVideoNode(config.id, String(config.data?.taskId)).catch(() => undefined)
    }
    const runningVideos = nodes.value.filter((node) => node.data?.variant === 'video' && node.data?.status === 'running' && node.data?.taskId)
    for (const video of runningVideos) {
        pollVideoNode(video.id, String(video.data?.taskId), true).catch(() => undefined)
    }
}

async function pollImageNode(nodeId: string, taskId: string) {
    try {
        const images = await pollImageResult(taskId)
        const imageUrl = images?.[0]?.url || ''
        if (!imageUrl) throw new Error('未返回图片地址')
        const config = nodes.value.find((node) => node.id === nodeId)
        if (!config) return
        const imageId = config.data?.outputNodeId || `image_${Date.now()}_${nodeCounter.value++}`
        const existing = nodes.value.find((node) => node.id === imageId)
        if (existing) {
            updateNodeData(imageId, { image: imageUrl, url: imageUrl, status: 'success', taskId }, false)
        } else {
            nodes.value = [
                ...nodes.value,
                createCanvasNode('image', { x: config.position.x + 380, y: config.position.y }, { title: '图片生成结果', image: imageUrl, url: imageUrl, taskId, status: 'success' }, imageId)
            ]
            edges.value = [...edges.value, createCanvasEdge(config.id, imageId)]
        }
        updateNodeData(config.id, { status: 'success', outputNodeId: imageId }, false)
        pushHistory()
    } catch (error: any) {
        const message = error?.message || '图片生成失败'
        updateNodeData(nodeId, { status: message.includes('仍在排队') ? 'running' : 'failed', error: message }, false)
    }
}

async function pollVideoNode(nodeId: string, taskId: string, isVideoNode = false) {
    try {
        const url = await pollVideoResult(taskId)
        if (isVideoNode) {
            updateNodeData(nodeId, { url, status: 'success' }, false)
            return
        }
        const config = nodes.value.find((node) => node.id === nodeId)
        if (!config) return
        const videoId = config.data?.outputNodeId || `video_${Date.now()}_${nodeCounter.value++}`
        const existing = nodes.value.find((node) => node.id === videoId)
        if (existing) {
            updateNodeData(videoId, { url, status: 'success' }, false)
        } else {
            nodes.value = [
                ...nodes.value,
                createCanvasNode('video', { x: config.position.x + 380, y: config.position.y }, { title: '视频生成结果', url, taskId, status: 'success' }, videoId)
            ]
            edges.value = [...edges.value, createCanvasEdge(config.id, videoId)]
        }
        updateNodeData(config.id, { status: 'success', outputNodeId: videoId }, false)
        pushHistory()
    } catch (error: any) {
        const message = error?.message || '视频生成失败'
        updateNodeData(nodeId, { status: message.includes('仍在排队') ? 'running' : 'failed', error: message }, false)
    }
}

async function runLlmConfig(node: Node) {
    const normalizedNode = normalizeConfigNodeDefaults(node)
    if (JSON.stringify(normalizedNode.data) !== JSON.stringify(node.data)) {
        updateNodeData(node.id, normalizedNode.data || {}, false)
        node = normalizedNode
    }
    updateNodeData(node.id, { status: 'running', error: '', splitStatus: '', splitMessage: '', splitCount: 0 }, false)
    processingMessage.value = '正在生成文本...'
    try {
        const referenceImages = collectConnectedImages(node.id, nodes.value, edges.value).map((item) => item.url)
        const instruction = String(node.data?.systemPrompt || '').trim()
        const systemPrompt = buildLlmSystemPrompt(instruction, String(node.data?.outputFormat || 'text'))
        const inputText = String(collectConnectedPrompt(node.id, nodes.value, edges.value) || node.data?.prompt || prompt.value || '').trim()
        const promptText = inputText || (referenceImages.length ? instruction || '请根据参考图片生成内容。' : instruction)
        if (!promptText) throw new Error('请填写系统提示词或补充输入，或连接文本节点/参考图')
        updateNodeData(node.id, { outputContent: '', billingTip: '' }, false)
        let output = ''
        let donePayload: any = null
        let streamError: Error | null = null
        await streamCanvasText({
            project_id: REMOTE_PROJECT_ID,
            node_id: node.id,
            content: promptText,
            prompt: promptText,
            system_prompt: systemPrompt,
            model_code: String(node.data?.model || ''),
            reference_images: referenceImages,
            source_type: 'canvas_llm_node'
        }, {
            onEvent: ({ event, data }) => {
                if (event === 'delta') {
                    output += String(data?.delta || '')
                    updateNodeData(node.id, { outputContent: output }, false)
                } else if (event === 'done') {
                    donePayload = data
                    output = String(data?.content || data?.text || output)
                    updateNodeData(node.id, {
                        outputContent: output,
                        status: output.trim() ? 'success' : 'running',
                        billingTip: formatBillingTip(data?.usage, data?.billing),
                        referenceCount: referenceImages.length
                    }, false)
                    nextTick(() => updateNodeInternals(node.id))
                } else if (event === 'error') {
                    streamError = new Error(String(data?.message || '文本生成失败'))
                }
            },
            onError: (error) => {
                streamError = error
            }
        })
        if (streamError) throw streamError
        output = output.trim()
        if (!output) throw new Error('未返回文本内容')
        updateNodeData(
            node.id,
            {
                status: 'success',
                outputContent: output,
                executed: true,
                billingTip: formatBillingTip(donePayload?.usage, donePayload?.billing),
                referenceCount: referenceImages.length,
                error: ''
            },
            false
        )
        await nextTick()
        updateNodeInternals(node.id)
        pushHistory()
    } catch (error: any) {
        const message = error?.message || String(error) || '文本生成失败'
        updateNodeData(node.id, { status: 'failed', error: message }, false)
    } finally {
        processingMessage.value = ''
        saveCanvasDebounced()
    }
}

function buildLlmSystemPrompt(instruction: string, outputFormat: string) {
    const formatPrompt = LLM_OUTPUT_FORMAT_PROMPTS[outputFormat] || LLM_OUTPUT_FORMAT_PROMPTS.text
    return [instruction, formatPrompt].filter(Boolean).join('\n\n')
}

function parseImageStoryboard(content: string) {
    const source = String(content || '').replace(/\r\n/g, '\n').trim()
    const matcher = /^#{3}\s*第\s*([0-9０-９一二三四五六七八九十百]+)\s*张\s*$/gm
    const matches = Array.from(source.matchAll(matcher))
    if (!matches.length) return []
    return matches
        .map((match, index) => {
            const start = (match.index || 0) + match[0].length
            const end = index + 1 < matches.length ? matches[index + 1].index || source.length : source.length
            const title = match[0].replace(/^#{3}\s*/, '').trim()
            const promptText = source.slice(start, end).trim()
            return { title, prompt: promptText }
        })
        .filter((item) => item.prompt)
}

function connectedImageReferenceEdges(targetId: string) {
    return edges.value
        .filter((edge) => edge.target === targetId && (edge.data?.imageOrder || edge.data?.imageRole))
        .map((edge) => ({
            edge,
            node: nodes.value.find((node) => node.id === edge.source)
        }))
        .filter(({ node }) => ['image', 'imageResult'].includes(String(node?.data?.variant)) && (node?.data?.image || node?.data?.url))
        .sort((a, b) => Number(a.edge.data?.imageOrder || 0) - Number(b.edge.data?.imageOrder || 0))
}

function setSplitFailure(nodeId: string, message: string) {
    updateNodeData(nodeId, {
        splitStatus: 'failed',
        splitMessage: message,
        splitCount: 0
    }, false)
    processingMessage.value = message
    setTimeout(() => {
        if (processingMessage.value === message) {
            processingMessage.value = ''
        }
    }, 2200)
    nextTick(() => updateNodeInternals(nodeId))
}

async function splitImageStoryboardNode(nodeId: string) {
    const source = nodes.value.find((node) => node.id === nodeId)
    if (!source) return
    if (source.data?.outputFormat !== 'image_storyboard') return
    try {
        const shots = parseImageStoryboard(String(source.data?.outputContent || ''))
        if (!shots.length) {
            setSplitFailure(nodeId, '未识别到图片分镜，请检查是否以 ###第X张 开头')
            return
        }

        const createdNodes: Node[] = []
        const createdEdges: Edge[] = []
        const imageConfigIds: string[] = []
        const baseX = source.position.x + 420
        const baseY = source.position.y
        const imageSelection = normalizeSelection(imageOptionConfig.value, {
            channel: String(source.data?.imageModel || ''),
            quality: String(source.data?.imageQuality || ''),
            ratio: String(source.data?.imageSize || '')
        })
        if (!imageSelection.channel) {
            setSplitFailure(nodeId, '暂无可用生图通道，请先配置图片模型')
            return
        }
        const referenceEdges = connectedImageReferenceEdges(source.id)

        shots.forEach((shot, index) => {
            const rowY = baseY + index * 280
            const textId = `text_${Date.now()}_${nodeCounter.value++}`
            const configId = `imageConfig_${Date.now()}_${nodeCounter.value++}`
            const imageId = `image_${Date.now()}_${nodeCounter.value++}`
            const textNode = normalizeConfigNodeDefaults(createCanvasNode('text', { x: baseX, y: rowY }, {
                title: `${shot.title}提示词`,
                content: shot.prompt
            }, textId))
            const imageConfig = normalizeConfigNodeDefaults(createCanvasNode('imageConfig', { x: baseX + 380, y: rowY }, {
                title: shot.title,
                prompt: shot.prompt,
                model: imageSelection.channel,
                quality: imageSelection.quality,
                size: imageSelection.ratio,
                promptCount: 1,
                referenceCount: referenceEdges.length
            }, configId))
            const imageNode = normalizeConfigNodeDefaults(createCanvasNode('image', { x: baseX + 760, y: rowY }, {
                title: shot.title,
                image: '',
                url: '',
                public: true,
                publicName: shot.title,
                status: 'idle'
            }, imageId))
            createdNodes.push(textNode, imageConfig, imageNode)
            createdEdges.push(createCanvasEdge(source.id, textNode.id))
            createdEdges.push(createCanvasEdge(textNode.id, imageConfig.id, 'promptOrder', { promptOrder: 1 }))
            referenceEdges.forEach(({ node: referenceNode }, referenceIndex) => {
                if (!referenceNode) return
                createdEdges.push(createCanvasEdge(referenceNode.id, imageConfig.id, 'imageOrder', { imageOrder: referenceIndex + 1 }))
            })
            createdEdges.push(createCanvasEdge(imageConfig.id, imageNode.id))
            imageConfigIds.push(imageConfig.id)
        })

        nodes.value = [...nodes.value, ...createdNodes]
        edges.value = [...edges.value, ...createdEdges]
        refreshConfigCounts()
        const successMessage = `已拆分 ${shots.length} 个图文节点，并开始生图`
        updateNodeData(nodeId, {
            splitStatus: 'success',
            splitMessage: successMessage,
            splitCount: shots.length
        }, false)
        await nextTick()
        ;[source.id, ...createdNodes.map((node) => node.id)].forEach((id) => updateNodeInternals(id))
        pushHistory()
        await nextTick()
        fitView({ nodes: createdNodes.map((node) => node.id), padding: 0.24, duration: 420 })
        processingMessage.value = successMessage
        saveCanvas().catch(() => undefined)
        if (!imageConfigIds.length) {
            setSplitFailure(nodeId, '未找到可提交的图片生成节点')
            return
        }
        await runImageConfigsBatch(imageConfigIds)
        processingMessage.value = successMessage
        setTimeout(() => {
            if (processingMessage.value === successMessage) {
                processingMessage.value = ''
            }
        }, 1800)
    } catch (error: any) {
        setSplitFailure(nodeId, error?.message || String(error) || '拆分图文失败')
    }
}

async function polishPrompt() {
    if (!ensurePcLogin()) return
    if (!prompt.value.trim()) return
    isProcessing.value = true
    processingMessage.value = '正在润色提示词...'
    try {
        let output = ''
        let streamError: Error | null = null
        await streamCanvasText({
            project_id: REMOTE_PROJECT_ID,
            content: prompt.value.trim(),
            prompt: prompt.value.trim(),
            system_prompt: '你是专业提示词优化助手。请把用户输入润色为适合AI图像或视频生成的中文提示词，只输出润色后的提示词。',
            model_code: canvasConfig.value?.text?.defaults?.model || '',
            source_type: 'canvas_prompt_polish'
        }, {
            onEvent: ({ event, data }) => {
                if (event === 'delta') {
                    output += String(data?.delta || '')
                    prompt.value = output
                } else if (event === 'done') {
                    output = String(data?.content || data?.text || output)
                } else if (event === 'error') {
                    streamError = new Error(String(data?.message || '润色失败'))
                }
            },
            onError: (error) => {
                streamError = error
            }
        })
        if (streamError) throw streamError
        output = output.trim()
        if (output) prompt.value = output
    } catch (error: any) {
        processingMessage.value = error?.message || '润色失败'
        setTimeout(() => (processingMessage.value = ''), 1800)
    } finally {
        isProcessing.value = false
        if (processingMessage.value === '正在润色提示词...') processingMessage.value = ''
    }
}

function formatBillingTip(usage: any = {}, billing: any = {}) {
    const promptTokens = Number(usage?.prompt_tokens || 0)
    const completionTokens = Number(usage?.completion_tokens || 0)
    const points = Number(billing?.user_charge_points || 0)
    if (!promptTokens && !completionTokens && !points) return ''
    return `输入 ${promptTokens} tokens · 输出 ${completionTokens} tokens · 消耗 ${points.toFixed(2)} 点`
}

function parseWorkflowAnalysis(content: string): WorkflowAnalysisPlan {
    const payload = unwrapWorkflowPayload(content)
    const fallback: WorkflowAnalysisPlan = {
        workflow_type: WORKFLOW_TYPES.TEXT_TO_IMAGE,
        description: '单图生成',
        image_prompt: payload || content,
        video_prompt: payload || content,
    }
    const trimmed = String(payload || content || '').trim()
    if (!trimmed) return fallback
    const cleaned = trimmed.replace(/```json\s*/g, '').replace(/```\s*/g, '')
    const start = cleaned.indexOf('{')
    const end = cleaned.lastIndexOf('}')
    const rawJson = start >= 0 && end > start ? cleaned.slice(start, end + 1) : cleaned
    try {
        const parsed = JSON.parse(rawJson)
        return normalizeWorkflowAnalysis(parsed, content)
    } catch {
        return fallback
    }
}

function unwrapWorkflowPayload(content: string): string {
    const trimmed = String(content || '').trim()
    if (!trimmed) return ''
    try {
        const parsed = JSON.parse(trimmed)
        if (parsed && typeof parsed === 'object') {
            const inner = parsed.content ?? parsed.text ?? ''
            if (typeof inner === 'string' && inner.trim()) {
                return inner
            }
        }
    } catch {}
    return trimmed
}

function normalizeWorkflowAnalysis(input: any, sourceText: string): WorkflowAnalysisPlan {
    const base: WorkflowAnalysisPlan = {
        workflow_type: WORKFLOW_TYPES.TEXT_TO_IMAGE,
        description: '单图生成',
        image_prompt: sourceText,
        video_prompt: sourceText,
    }
    const workflowType = String(input?.workflow_type || base.workflow_type) as WorkflowType
    const normalized: WorkflowAnalysisPlan = {
        ...base,
        workflow_type: Object.values(WORKFLOW_TYPES).includes(workflowType) ? workflowType : WORKFLOW_TYPES.TEXT_TO_IMAGE,
        description: String(input?.description || base.description || '自动生成画布'),
        image_prompt: String(input?.image_prompt || sourceText || ''),
        video_prompt: String(input?.video_prompt || input?.image_prompt || sourceText || ''),
    }
    if (input?.character && typeof input.character === 'object') {
        normalized.character = {
            name: String(input.character.name || ''),
            description: String(input.character.description || ''),
        }
    }
    if (Array.isArray(input?.shots)) {
        normalized.shots = input.shots
            .map((item: any, index: number) => ({
                title: String(item?.title || `分镜${index + 1}`),
                prompt: String(item?.prompt || ''),
            }))
            .filter((item: any) => item.prompt)
    }
    if (input?.multi_angle && typeof input.multi_angle === 'object') {
        normalized.multi_angle = {
            character_description: String(input.multi_angle.character_description || input.multi_angle.description || ''),
        }
    }
    if (input?.picture_book && typeof input.picture_book === 'object') {
        const pages = Array.isArray(input.picture_book.pages)
            ? input.picture_book.pages.map((item: any, index: number) => ({
                page_number: Number(item?.page_number || index + 1),
                story_text: String(item?.story_text || ''),
                illustration_prompt: String(item?.illustration_prompt || ''),
            })).filter((item: any) => item.story_text || item.illustration_prompt)
            : []
        normalized.picture_book = {
            title: String(input.picture_book.title || ''),
            style: String(input.picture_book.style || ''),
            character: input.picture_book.character && typeof input.picture_book.character === 'object'
                ? {
                    name: String(input.picture_book.character.name || ''),
                    description: String(input.picture_book.character.description || ''),
                }
                : undefined,
            pages,
        }
    }
    if (normalized.workflow_type === WORKFLOW_TYPES.STORYBOARD && (!normalized.shots || !normalized.shots.length)) {
        normalized.shots = [
            { title: '分镜1', prompt: normalized.image_prompt || sourceText },
            { title: '分镜2', prompt: normalized.video_prompt || sourceText },
        ]
    }
    if (normalized.workflow_type === WORKFLOW_TYPES.PICTURE_BOOK && (!normalized.picture_book?.pages || !normalized.picture_book.pages.length)) {
        normalized.picture_book = {
            title: normalized.picture_book?.title || '绘本故事',
            style: normalized.picture_book?.style || '温暖绘本风',
            character: normalized.picture_book?.character || {
                name: '主角',
                description: sourceText,
            },
            pages: [
                { page_number: 1, story_text: sourceText, illustration_prompt: normalized.image_prompt || sourceText },
            ],
        }
    }
    if (normalized.workflow_type === WORKFLOW_TYPES.MULTI_ANGLE_STORYBOARD && !normalized.multi_angle?.character_description) {
        normalized.multi_angle = {
            character_description: sourceText,
        }
    }
    return normalized
}

function workflowEntryPoint() {
    const zoom = Math.max(0.01, Number(viewport.value.zoom || 1))
    return {
        x: -viewport.value.x / zoom + window.innerWidth / (2 * zoom),
        y: -viewport.value.y / zoom + window.innerHeight / (2 * zoom),
    }
}

function createWorkflowNode(
    variant: 'text' | 'imageConfig' | 'image' | 'videoConfig' | 'video' | 'llmConfig',
    position: { x: number; y: number },
    data: Record<string, any> = {}
) {
    return normalizeConfigNodeDefaults(createCanvasNode(variant as any, position, data))
}

function createWorkflowGraph(plan: WorkflowAnalysisPlan, sourceText: string) {
    const start = workflowEntryPoint()
    const nodesToAdd: any[] = []
    const edgesToAdd: any[] = []
    const baseText = plan.image_prompt || sourceText

    const pushText = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('text', position, data)
        nodesToAdd.push(node)
        return node
    }
    const pushLlm = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('llmConfig', position, data)
        nodesToAdd.push(node)
        return node
    }
    const pushImageConfig = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('imageConfig', position, data)
        nodesToAdd.push(node)
        return node
    }
    const pushImage = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('image', position, data)
        nodesToAdd.push(node)
        return node
    }
    const pushVideoConfig = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('videoConfig', position, data)
        nodesToAdd.push(node)
        return node
    }
    const pushVideo = (position: { x: number; y: number }, data: Record<string, any>) => {
        const node = createWorkflowNode('video', position, data)
        nodesToAdd.push(node)
        return node
    }

    if (plan.workflow_type === WORKFLOW_TYPES.TEXT_TO_IMAGE_TO_VIDEO) {
        const imageText = pushText({ x: start.x - 180, y: start.y - 120 }, {
            title: '图片提示词',
            content: baseText,
        })
        const imageConfig = pushImageConfig({ x: start.x + 200, y: start.y - 120 }, {
            title: '生成首图',
            prompt: baseText,
            promptCount: 1,
            referenceCount: 0,
        })
        const imageNode = pushImage({ x: start.x + 580, y: start.y - 120 }, {
            title: '首图结果',
            image: '',
            url: '',
            public: true,
            publicName: '首图',
            status: 'idle',
        })
        const videoText = pushText({ x: start.x - 180, y: start.y + 160 }, {
            title: '视频提示词',
            content: plan.video_prompt || baseText,
        })
        const videoConfig = pushVideoConfig({ x: start.x + 200, y: start.y + 160 }, {
            title: '生成视频',
            prompt: plan.video_prompt || baseText,
            ratio: '16:9',
            duration: 5,
            status: 'idle',
        })
        const videoNode = pushVideo({ x: start.x + 580, y: start.y + 160 }, {
            title: '视频结果',
            url: '',
            poster: '',
            duration: 5,
            status: 'idle',
        })
        edgesToAdd.push(createCanvasEdge(imageText.id, imageConfig.id, 'promptOrder', { promptOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(imageConfig.id, imageNode.id))
        edgesToAdd.push(createCanvasEdge(videoText.id, videoConfig.id, 'promptOrder', { promptOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(imageNode.id, videoConfig.id, 'imageRole', { imageRole: 'first_frame_image', imageOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(videoConfig.id, videoNode.id))
        return { nodes: nodesToAdd, edges: edgesToAdd, title: plan.description }
    }

    if (plan.workflow_type === WORKFLOW_TYPES.STORYBOARD) {
        const character = plan.character || { name: '角色', description: baseText }
        const characterText = pushText({ x: start.x - 180, y: start.y - 120 }, {
            title: '角色描述',
            content: `角色名称：${character.name || '角色'}\n角色描述：${character.description || baseText}`,
        })
        const characterConfig = pushImageConfig({ x: start.x + 200, y: start.y - 120 }, {
            title: '角色参考图',
            prompt: character.description || baseText,
            promptCount: 1,
            referenceCount: 0,
        })
        const characterImage = pushImage({ x: start.x + 580, y: start.y - 120 }, {
            title: '角色参考图',
            image: '',
            url: '',
            public: true,
            publicName: character.name || '角色参考',
            status: 'idle',
        })
        edgesToAdd.push(createCanvasEdge(characterText.id, characterConfig.id, 'promptOrder', { promptOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(characterConfig.id, characterImage.id))

        (plan.shots || []).forEach((shot, index) => {
            const rowY = start.y + 220 + index * 260
            const shotText = pushText({ x: start.x - 180, y: rowY }, {
                title: `分镜${index + 1}提示词`,
                content: shot.prompt,
            })
            const shotConfig = pushImageConfig({ x: start.x + 200, y: rowY }, {
                title: shot.title || `分镜${index + 1}`,
                prompt: shot.prompt,
                promptCount: 1,
                referenceCount: 1,
            })
            const shotImage = pushImage({ x: start.x + 580, y: rowY }, {
                title: shot.title || `分镜${index + 1}`,
                image: '',
                url: '',
                public: true,
                publicName: shot.title || `分镜${index + 1}`,
                status: 'idle',
            })
            edgesToAdd.push(createCanvasEdge(shotText.id, shotConfig.id, 'promptOrder', { promptOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(characterImage.id, shotConfig.id, 'imageOrder', { imageOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(shotConfig.id, shotImage.id))
        })
        return { nodes: nodesToAdd, edges: edgesToAdd, title: plan.description }
    }

    if (plan.workflow_type === WORKFLOW_TYPES.MULTI_ANGLE_STORYBOARD) {
        const characterDescription = plan.multi_angle?.character_description || baseText
        const characterText = pushText({ x: start.x - 180, y: start.y - 120 }, {
            title: '角色描述',
            content: characterDescription,
        })
        const characterConfig = pushImageConfig({ x: start.x + 200, y: start.y - 120 }, {
            title: '角色参考图',
            prompt: characterDescription,
            promptCount: 1,
            referenceCount: 0,
        })
        const characterImage = pushImage({ x: start.x + 580, y: start.y - 120 }, {
            title: '角色参考图',
            image: '',
            url: '',
            public: true,
            publicName: '角色参考',
            status: 'idle',
        })
        edgesToAdd.push(createCanvasEdge(characterText.id, characterConfig.id, 'promptOrder', { promptOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(characterConfig.id, characterImage.id))
        const angles = [
            { key: 'front', label: '正视' },
            { key: 'side', label: '侧视' },
            { key: 'back', label: '后视' },
            { key: 'top', label: '俯视' },
        ]
        angles.forEach((angle, index) => {
            const rowY = start.y + 220 + index * 240
            const prompt = `使用提供的图片，生成${angle.label}角度的四宫格分镜，包含远景、中景、近景和局部特写，保持角色一致性。画面内如需文字标注，全部使用中文。\n\n角色参考：${characterDescription}`
            const angleText = pushText({ x: start.x - 180, y: rowY }, {
                title: `${angle.label}提示词`,
                content: prompt,
            })
            const angleConfig = pushImageConfig({ x: start.x + 200, y: rowY }, {
                title: `${angle.label}分镜`,
                prompt,
                promptCount: 1,
                referenceCount: 1,
            })
            const angleImage = pushImage({ x: start.x + 580, y: rowY }, {
                title: `${angle.label}四宫格`,
                image: '',
                url: '',
                public: true,
                publicName: `${angle.label}四宫格`,
                status: 'idle',
            })
            edgesToAdd.push(createCanvasEdge(angleText.id, angleConfig.id, 'promptOrder', { promptOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(characterImage.id, angleConfig.id, 'imageOrder', { imageOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(angleConfig.id, angleImage.id))
        })
        return { nodes: nodesToAdd, edges: edgesToAdd, title: plan.description }
    }

    if (plan.workflow_type === WORKFLOW_TYPES.PICTURE_BOOK) {
        const character = plan.picture_book?.character || { name: '主角', description: baseText }
        const characterText = pushText({ x: start.x - 180, y: start.y - 120 }, {
            title: '绘本故事',
            content: `标题：${plan.picture_book?.title || '绘本故事'}\n风格：${plan.picture_book?.style || '温暖绘本风'}\n角色：${character.name || '主角'}\n描述：${character.description || baseText}`,
        })
        const characterConfig = pushImageConfig({ x: start.x + 200, y: start.y - 120 }, {
            title: '角色参考图',
            prompt: `${character.name || '主角'}：${character.description || baseText}\n风格：${plan.picture_book?.style || '温暖绘本风'}`,
            promptCount: 1,
            referenceCount: 0,
        })
        const characterImage = pushImage({ x: start.x + 580, y: start.y - 120 }, {
            title: '角色参考图',
            image: '',
            url: '',
            public: true,
            publicName: character.name || '角色参考',
            status: 'idle',
        })
        edgesToAdd.push(createCanvasEdge(characterText.id, characterConfig.id, 'promptOrder', { promptOrder: 1 }))
        edgesToAdd.push(createCanvasEdge(characterConfig.id, characterImage.id))

        (plan.picture_book?.pages || []).forEach((page, index) => {
            const rowY = start.y + 220 + index * 280
            const storyText = pushText({ x: start.x - 180, y: rowY - 90 }, {
                title: `第${page.page_number || index + 1}页故事`,
                content: page.story_text,
            })
            const promptText = pushText({ x: start.x - 180, y: rowY + 20 }, {
                title: `第${page.page_number || index + 1}页提示词`,
                content: page.illustration_prompt,
            })
            const pageConfig = pushImageConfig({ x: start.x + 200, y: rowY }, {
                title: `绘本第${page.page_number || index + 1}页`,
                prompt: page.illustration_prompt,
                promptCount: 1,
                referenceCount: 1,
            })
            const pageImage = pushImage({ x: start.x + 580, y: rowY }, {
                title: `绘本第${page.page_number || index + 1}页`,
                image: '',
                url: '',
                public: true,
                publicName: `第${page.page_number || index + 1}页`,
                status: 'idle',
            })
            edgesToAdd.push(createCanvasEdge(promptText.id, pageConfig.id, 'promptOrder', { promptOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(characterImage.id, pageConfig.id, 'imageOrder', { imageOrder: 1 }))
            edgesToAdd.push(createCanvasEdge(pageConfig.id, pageImage.id))
            // Keep the story text close to the generation chain for readability.
            edgesToAdd.push(createCanvasEdge(storyText.id, promptText.id, 'smoothstep', {}))
        })
        return { nodes: nodesToAdd, edges: edgesToAdd, title: plan.description }
    }

    const textNode = pushText({ x: start.x - 180, y: start.y - 120 }, {
        title: '提示词',
        content: baseText,
    })
    const imageConfig = pushImageConfig({ x: start.x + 200, y: start.y - 120 }, {
        title: '文生图',
        prompt: baseText,
        promptCount: 1,
        referenceCount: 0,
    })
    const imageNode = pushImage({ x: start.x + 580, y: start.y - 120 }, {
        title: '生成结果',
        image: '',
        url: '',
        public: true,
        publicName: '生成图',
        status: 'idle',
    })
    edgesToAdd.push(createCanvasEdge(textNode.id, imageConfig.id, 'promptOrder', { promptOrder: 1 }))
    edgesToAdd.push(createCanvasEdge(imageConfig.id, imageNode.id))
    return { nodes: nodesToAdd, edges: edgesToAdd, title: plan.description }
}

async function sendPrompt() {
    if (!ensurePcLogin()) return
    const content = prompt.value.trim()
    if (!content) return
    await generateCanvasFromPrompt(content, { clearInput: true, autoRun: autoExecute.value })
}

async function generateCanvasFromPrompt(content: string, options: { clearInput?: boolean; autoRun?: boolean } = {}) {
    isProcessing.value = true
    try {
        processingMessage.value = '正在分析并生成画布...'
        let analysisText = ''
        let streamError: Error | null = null
        await streamCanvasText({
            project_id: REMOTE_PROJECT_ID,
            content,
            prompt: content,
            system_prompt: WORKFLOW_ANALYSIS_PROMPT,
            model_code: String(canvasConfig.value?.text?.defaults?.model || ''),
            source_app_code: 'aigc_canvas',
            source_type: 'canvas_intent',
            source_id: '',
        }, {
            onEvent: ({ event, data }) => {
                if (event === 'delta') {
                    analysisText += String(data?.delta || '')
                } else if (event === 'done') {
                    analysisText = String(data?.content || data?.text || analysisText)
                } else if (event === 'error') {
                    streamError = new Error(String(data?.message || '画布分析失败'))
                }
            },
            onError: (error) => {
                streamError = error
            }
        })
        if (streamError) throw streamError
        const analysis = parseWorkflowAnalysis(analysisText || content)
        const graph = createWorkflowGraph(analysis, content)
        if (!graph.nodes.length) {
            throw new Error('未能生成画布流程')
        }
        nodes.value = [...nodes.value, ...graph.nodes]
        edges.value = [...edges.value, ...graph.edges]
        refreshConfigCounts()
        showNodeMenu.value = false
        showWorkflowPanel.value = false
        await nextTick()
        graph.nodes.forEach((node) => updateNodeInternals(node.id))
        pushHistory()
        await nextTick()
        fitView({ nodes: graph.nodes.map((node) => node.id), padding: 0.24, duration: 420 })
        await saveCanvas()
        if (options.autoRun) {
            processingMessage.value = '正在自动执行画布流程...'
            await runCanvasWorkflow(graph.nodes.filter((node) => ['imageConfig', 'videoConfig', 'llmConfig'].includes(String(node.data?.variant))).map((node) => node.id))
        }
        if (options.clearInput) prompt.value = ''
    } catch (error: any) {
        processingMessage.value = error?.message || String(error) || '画布生成失败'
        setTimeout(() => {
            if (processingMessage.value === (error?.message || String(error) || '画布生成失败')) {
                processingMessage.value = ''
            }
        }, 2200)
    } finally {
        if (processingMessage.value === '正在分析并生成画布...' || processingMessage.value === '正在自动执行画布流程...') {
            processingMessage.value = ''
        }
        isProcessing.value = false
    }
}

function refreshConfigCounts() {
    nodes.value = nodes.value.map((node) => {
        if (!['imageConfig', 'videoConfig', 'llmConfig'].includes(String(node.data?.variant))) return node
        const incoming = edges.value.filter((edge) => edge.target === node.id)
        return {
            ...node,
            data: {
                ...node.data,
                promptCount: incoming.filter((edge) => edge.data?.promptOrder).length,
                referenceCount: incoming.filter((edge) => edge.data?.imageOrder || edge.data?.imageRole).length
            }
        }
    })
}

function rotateSuggestions() {
    suggestionOffset.value = (suggestionOffset.value + 1) % allSuggestions.length
}

function categoryLabel(category: string) {
    return ({ storyboard: '分镜', ecommerce: '电商', drama: '短剧', creative: '创意' } as Record<string, string>)[category] || category
}

function downloadAsset(asset: DownloadAsset) {
    if (!ensurePcLogin()) return
    downloadPcAsset(asset.url, `${asset.title}.${asset.type === 'video' ? 'mp4' : 'png'}`)
}

async function loadInitialProject() {
    if (!userStore.isLogin) {
        projects.value = []
        return
    }
    const initialPrompt = sessionStorage.getItem('ai-canvas-initial-prompt') || ''
    if (initialPrompt) sessionStorage.removeItem('ai-canvas-initial-prompt')
    let needsPersist = false
    projects.value = loadCanvasProjects().map((project) => {
        const normalized = ensureProjectCanvas(project)
        if (normalized !== project) needsPersist = true
        return normalized
    })
    if (!projects.value.length) {
        projects.value = [createCanvasProject(initialPrompt ? initialPrompt.slice(0, 18) : '未命名项目')]
        needsPersist = true
    }
    const routeId = String(route.params.id || '')
    const target = projects.value.find((project) => project.id === routeId)?.id || projects.value[0].id
    await switchProject(target, false)
    const targetProject = currentProject.value
    if (needsPersist) persistProjects()
    saveCanvasDebounced()
    resumePolling()
    if (initialPrompt && targetProject && !targetProject.nodes.length && !targetProject.edges.length) {
        prompt.value = initialPrompt
        await nextTick()
        await generateCanvasFromPrompt(initialPrompt, { clearInput: true, autoRun: autoExecute.value })
    }
}

provide('aigcCanvasNodeActions', {
    update: updateNodeData,
    duplicate: duplicateNode,
    remove: removeNode,
    addConnected: addConnectedNode,
    run: runNode,
    splitStoryboard: splitImageStoryboardNode,
    imageOptionConfig,
    videoOptionConfig,
    chatModels: chatModelOptions,
    imageModels: imageChannelModels,
    videoModels: videoChannelModels,
    publicImages: computed(() => nodes.value)
})

provide('aigcCanvasEdgeActions', {
    update: updateEdgeData,
    remove: removeEdge
})

watch(nodes, () => saveCanvasDebounced(), { deep: true })
watch(edges, () => saveCanvasDebounced(), { deep: true })

onMounted(() => {
    initCanvasSettings()
    loadCanvasConfig()
    loadInitialProject()
})

watch(() => userStore.isLogin, (loggedIn) => {
    if (!loggedIn) {
        projects.value = []
        return
    }
    loadInitialProject()
})
</script>

<style lang="scss">
@import '@vue-flow/core/dist/style.css';
@import '@vue-flow/core/dist/theme-default.css';
@import '@vue-flow/minimap/dist/style.css';
@import '@vue-flow/controls/dist/style.css';

.aigc-canvas-page {
    --canvas-bg: #050505;
    --header-bg: rgba(16, 16, 18, 0.96);
    --panel-bg: #171719;
    --panel-soft: #222222;
    --border: #313233;
    --text: #f4f4f5;
    --text-soft: #a3a3a3;
    --shadow: 0 18px 42px rgba(0, 0, 0, 0.34);
    width: 100vw;
    height: 100vh;
    overflow: hidden;
    background: var(--canvas-bg);
    color: var(--text);
}

.aigc-canvas-page.is-dark {
    --canvas-bg: #050505;
    --header-bg: rgba(16, 16, 18, 0.96);
    --panel-bg: #171719;
    --panel-soft: #222222;
    --border: #313233;
    --text: #f4f4f5;
    --text-soft: #a3a3a3;
    --shadow: 0 18px 42px rgba(0, 0, 0, 0.34);
}

.canvas-header {
    position: relative;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 64px;
    padding: 0 24px;
    border-bottom: 1px solid var(--border);
    background: var(--header-bg);
    box-shadow: 0 1px 12px rgba(17, 24, 39, 0.04);
}

.canvas-header__project,
.canvas-header__actions {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.project-switch,
.icon-button,
.save-pill,
.run-pill {
    border: 1px solid var(--border);
    background: var(--panel-bg);
    color: var(--text);
}

.icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border-radius: 11px;
    cursor: pointer;
}

.icon-button.active {
    border-color: #10b981;
}

.icon-button--plain {
    width: 32px;
    height: 32px;
    border-color: transparent;
    box-shadow: none;
    text-decoration: none;
}

.project-dropdown {
    position: relative;
}

.project-switch {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 38px;
    padding: 0 10px;
    border-color: transparent;
    box-shadow: none;
    font-size: 15px;
    cursor: pointer;
}

.project-switch span {
    max-width: min(360px, 42vw);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-menu {
    position: absolute;
    top: 46px;
    left: 0;
    display: grid;
    gap: 4px;
    width: 270px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--panel-bg);
    box-shadow: var(--shadow);
}

.project-menu button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
}

.project-menu button:hover {
    background: var(--panel-soft);
}

.project-menu small {
    color: var(--text-soft);
}

.project-menu .danger {
    color: #ef4444;
}

.project-menu__line {
    height: 1px;
    background: var(--border);
}

.save-pill {
    height: 36px;
    padding: 0 14px;
    border-radius: 11px;
    color: var(--text-soft);
    cursor: pointer;
}

.run-pill {
    height: 36px;
    padding: 0 14px;
    border-radius: 11px;
    border-color: #10b981;
    background: #10b981;
    color: #fff;
    cursor: pointer;
}

.run-pill:disabled {
    opacity: 0.58;
    cursor: not-allowed;
}

.canvas-body {
    position: relative;
    width: 100vw;
    height: calc(100vh - 64px);
    overflow: hidden;
}

.canvas-flow {
    width: 100%;
    height: 100%;
    background: var(--canvas-bg);
}

.canvas-flow .vue-flow__edge-path {
    stroke: #c9c9c9;
    stroke-width: 2;
}

.canvas-flow .vue-flow__minimap {
    right: 24px !important;
    bottom: 128px !important;
    width: 178px;
    height: 96px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--panel-bg);
    box-shadow: var(--shadow);
}

.canvas-toolbar {
    position: absolute;
    z-index: 60;
    left: 24px;
    top: 50%;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 64px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 16px;
    background: var(--panel-bg);
    box-shadow: var(--shadow);
    transform: translateY(-50%);
}

.toolbar-button {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 12px;
    background: transparent;
    color: var(--text);
    font-size: 18px;
    cursor: pointer;
    pointer-events: auto;
}

.toolbar-button .el-icon {
    font-size: 20px;
}

.toolbar-button:hover {
    background: var(--panel-soft);
}

.toolbar-button::after {
    position: absolute;
    z-index: 80;
    left: calc(100% + 12px);
    top: 50%;
    min-width: max-content;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--panel-bg);
    color: var(--text);
    box-shadow: var(--shadow);
    content: attr(data-tooltip);
    font-size: 12px;
    line-height: 1;
    opacity: 0;
    pointer-events: none;
    transform: translate(-4px, -50%);
    transition: opacity 0.14s ease, transform 0.14s ease;
}

.toolbar-button::before {
    position: absolute;
    z-index: 81;
    left: calc(100% + 7px);
    top: 50%;
    width: 8px;
    height: 8px;
    border-left: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
    background: var(--panel-bg);
    content: '';
    opacity: 0;
    pointer-events: none;
    transform: translate(-4px, -50%) rotate(45deg);
    transition: opacity 0.14s ease, transform 0.14s ease;
}

.toolbar-button:hover::after,
.toolbar-button:hover::before {
    opacity: 1;
    transform: translate(0, -50%) rotate(0);
}

.toolbar-button:hover::before {
    transform: translate(0, -50%) rotate(45deg);
}

.toolbar-button--primary {
    background: #0d0d0d;
    color: #fff;
}

.toolbar-button:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.toolbar-divider {
    height: 1px;
    margin: 4px 6px;
    background: var(--border);
}

.node-menu,
.workflow-panel,
.zoom-panel,
.prompt-composer__footer button,
.canvas-modal {
    border: 1px solid var(--border);
    background: var(--panel-bg);
    box-shadow: var(--shadow);
}

.node-menu {
    position: absolute;
    z-index: 25;
    left: 100px;
    top: calc(50% - 210px);
    display: grid;
    gap: 8px;
    width: 280px;
    padding: 10px;
    border-radius: 14px;
}

.node-menu button {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    padding: 10px;
    border: 0;
    border-radius: 11px;
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
}

.node-menu button:hover {
    background: var(--panel-soft);
}

.node-menu__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: #111;
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}

.node-menu strong,
.node-menu small {
    display: block;
}

.node-menu small {
    margin-top: 3px;
    color: var(--text-soft);
    font-size: 12px;
}

.workflow-panel {
    position: absolute;
    z-index: 26;
    left: 100px;
    top: 76px;
    width: 640px;
    max-height: 74vh;
    border-radius: 16px;
    overflow: auto;
}

.workflow-panel header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}

.workflow-panel header div {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 14px;
}

.workflow-panel header button,
.settings-tabs button {
    border: 0;
    background: transparent;
    color: var(--text-soft);
    cursor: pointer;
}

.workflow-panel header button.active,
.settings-tabs button.active {
    color: var(--text);
    font-weight: 700;
}

.workflow-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    padding: 16px;
}

.workflow-grid button {
    display: grid;
    gap: 8px;
    border: 0;
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
}

.workflow-grid img {
    width: 100%;
    aspect-ratio: 1;
    border-radius: 12px;
    object-fit: cover;
    background: var(--panel-soft);
}

.workflow-grid span {
    color: var(--text-soft);
    font-size: 12px;
    line-height: 1.4;
}

.workflow-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 160px;
    color: var(--text-soft);
}

.zoom-panel {
    position: absolute;
    z-index: 20;
    left: 16px;
    bottom: 14px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 40px;
    padding: 0 10px;
    border-radius: 10px;
}

.zoom-panel button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--text);
    cursor: pointer;
}

.zoom-panel span {
    width: 42px;
    color: var(--text);
    font-size: 12px;
    font-weight: 700;
    text-align: center;
}

.prompt-composer {
    position: absolute;
    z-index: 20;
    left: 50%;
    bottom: 24px;
    width: min(720px, calc(100vw - 420px));
    transform: translateX(-50%);
}

.processing-banner {
    margin-bottom: 8px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--panel-bg);
    color: var(--text);
    font-size: 13px;
}

.prompt-composer__card {
    display: flex;
    flex-direction: column;
    gap: 12px;
    min-height: 112px;
    padding: 16px 18px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: rgba(16, 16, 18, 0.96);
    box-shadow: var(--shadow);
}

.prompt-composer textarea {
    width: 100%;
    min-height: 44px;
    max-height: 88px;
    border: 0;
    outline: none;
    resize: none;
    background: transparent;
    color: var(--text);
    font-size: 14px;
    line-height: 1.5;
}

.prompt-composer textarea::placeholder {
    color: var(--text-soft);
}

.prompt-composer__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.prompt-composer__polish,
.prompt-composer__send {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    cursor: pointer;
}

.prompt-composer__polish {
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--panel-soft);
    color: var(--text-soft);
    font-size: 13px;
}

.prompt-composer__run {
    display: inline-flex;
    align-items: center;
    gap: 14px;
}

.prompt-composer__switch {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: var(--text-soft);
    font-size: 13px;
    white-space: nowrap;
    cursor: pointer;
}

.prompt-composer__switch input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}

.prompt-composer__switch span {
    position: relative;
    width: 38px;
    height: 22px;
    border-radius: 999px;
    background: #3f4450;
    transition: background 0.16s ease;
}

.prompt-composer__switch span::after {
    position: absolute;
    left: 3px;
    top: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    content: '';
    transition: transform 0.16s ease;
}

.prompt-composer__switch input:checked + span {
    background: #2ecc71;
}

.prompt-composer__switch input:checked + span::after {
    transform: translateX(16px);
}

.prompt-composer__send {
    width: 34px;
    height: 34px;
    border-radius: 11px;
    background: #35d977;
    color: #fff;
    font-size: 18px;
}

.prompt-composer__polish:disabled,
.prompt-composer__send:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.prompt-composer__footer {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 8px;
    color: var(--text-soft);
    font-size: 12px;
}

.prompt-composer__footer div {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
}

.prompt-composer__footer button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 26px;
    padding: 0 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: transparent;
    color: var(--text);
    font-size: 12px;
    cursor: pointer;
}

.prompt-composer__footer button:hover {
    background: var(--panel-soft);
}

.modal-mask {
    position: fixed;
    z-index: 80;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.44);
}

.canvas-modal {
    width: 760px;
    max-width: 92vw;
    max-height: 84vh;
    border-radius: 16px;
    overflow: auto;
    color: var(--text);
}

.canvas-modal > header,
.canvas-modal > footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 18px;
    border-bottom: 1px solid var(--border);
}

.canvas-modal > footer {
    border-top: 1px solid var(--border);
    border-bottom: 0;
    justify-content: flex-end;
    gap: 10px;
}

.canvas-modal header button,
.canvas-modal footer button {
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--panel-bg);
    color: var(--text);
    padding: 8px 14px;
    cursor: pointer;
}

.canvas-modal footer .primary {
    border-color: #111;
    background: #111;
    color: #fff;
}

.settings-tabs {
    display: flex;
    gap: 18px;
    padding: 14px 18px 0;
}

.settings-form {
    display: grid;
    gap: 14px;
    padding: 18px;
}

.settings-form label {
    display: grid;
    gap: 7px;
    color: var(--text-soft);
    font-size: 13px;
}

.settings-form input,
.settings-form select,
.model-column input,
.model-column select {
    height: 38px;
    padding: 0 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--panel-soft);
    color: var(--text);
    outline: none;
}

.settings-form p {
    margin: 0;
    color: var(--text-soft);
    font-size: 12px;
    line-height: 1.6;
}

.model-settings {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    padding: 18px;
}

.model-column {
    display: grid;
    gap: 10px;
    align-content: start;
}

.model-tags {
    display: grid;
    gap: 6px;
    max-height: 180px;
    overflow: auto;
}

.model-tags span {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 7px 9px;
    border-radius: 9px;
    background: var(--panel-soft);
    color: var(--text-soft);
    font-size: 12px;
}

.model-tags button,
.model-column > button {
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--panel-bg);
    color: var(--text);
    cursor: pointer;
}

.model-column > button {
    height: 34px;
}

.download-modal {
    padding-bottom: 16px;
}

.download-stats {
    display: flex;
    gap: 16px;
    padding: 14px 18px 0;
    color: var(--text-soft);
    font-size: 13px;
}

.download-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    padding: 16px 18px;
}

.download-grid button,
.download-list button {
    border: 0;
    border-radius: 12px;
    background: var(--panel-soft);
    color: var(--text);
    cursor: pointer;
    overflow: hidden;
}

.download-grid img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
}

.download-grid span {
    display: block;
    padding: 8px;
    font-size: 12px;
    text-align: left;
}

.download-list {
    display: grid;
    gap: 8px;
    padding: 0 18px 16px;
}

.download-list button {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    text-align: left;
}

@media (max-width: 1100px) {
    .canvas-header {
        padding: 0 14px;
    }

    .prompt-composer {
        width: min(680px, calc(100vw - 140px));
    }

    .prompt-composer__footer,
    .model-settings {
        align-items: flex-start;
        grid-template-columns: 1fr;
        flex-direction: column;
    }
}

@media (max-width: 900px) {
    .canvas-header {
        align-items: flex-start;
        flex-direction: column;
        justify-content: center;
        gap: 8px;
        height: 112px;
        padding: 12px 14px;
    }

    .canvas-header__project,
    .canvas-header__actions {
        width: 100%;
        min-width: 0;
    }

    .canvas-header__actions {
        flex-wrap: wrap;
    }

    .project-dropdown {
        min-width: 0;
    }

    .project-switch {
        max-width: 100%;
    }

    .project-switch span {
        max-width: calc(100vw - 132px);
    }

    .canvas-body {
        height: calc(100vh - 112px);
    }

    .canvas-toolbar {
        left: 12px;
        width: 56px;
        padding: 8px;
    }

    .toolbar-button {
        width: 38px;
        height: 38px;
    }

    .node-menu {
        left: 76px;
        top: 16px;
        width: min(280px, calc(100vw - 104px));
        max-height: calc(100vh - 164px);
        overflow-y: auto;
    }

    .workflow-panel {
        left: 76px;
        right: 12px;
        top: 16px;
        width: auto;
        max-height: calc(100vh - 164px);
    }

    .workflow-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }

    .prompt-composer {
        width: calc(100vw - 48px);
    }

    .prompt-composer__actions {
        align-items: flex-start;
        flex-direction: column;
    }

    .prompt-composer__run {
        width: 100%;
        justify-content: space-between;
    }

    .canvas-flow .vue-flow__minimap {
        right: 12px !important;
        bottom: 164px !important;
        width: 136px;
        height: 74px;
    }

    .download-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }
}
</style>

<style lang="scss">
.aigc-canvas-message-box {
    --el-bg-color: #171719;
    --el-bg-color-overlay: #171719;
    --el-text-color-primary: #f4f4f5;
    --el-text-color-regular: #d4d4d8;
    --el-border-color-light: #313233;
    --el-fill-color-blank: #111113;
    width: min(420px, calc(100vw - 40px));
    border: 1px solid #313233;
    border-radius: 14px;
    background: #171719;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
}

.aigc-canvas-message-box .el-message-box__title,
.aigc-canvas-message-box .el-message-box__message {
    color: #f4f4f5;
}

.aigc-canvas-message-box .el-input__wrapper {
    background: #111113;
    box-shadow: 0 0 0 1px #313233 inset;
}

.aigc-canvas-message-box .el-input__inner {
    color: #f4f4f5;
}
</style>
