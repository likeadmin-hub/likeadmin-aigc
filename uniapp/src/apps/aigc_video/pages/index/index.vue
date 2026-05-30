<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn nav-btn--back" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">AIGC视频</view>
                <view class="nav-placeholder" :style="navPlaceholderStyle"></view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view v-if="maxReferenceCount > 0" class="section">
                <view class="label-row">
                    <view class="label-icon">
                        <u-icon name="photo" color="#ffffff" size="30"></u-icon>
                    </view>
                    <view class="label-text">参考素材：</view>
                </view>
                <view class="reference-box" @click="chooseReferenceImages">
                    <template v-if="referenceAssets.length">
                        <view class="reference-list">
                            <view
                                v-for="(asset, index) in referenceAssets"
                                :key="`${asset.type}-${asset.uri}`"
                                class="reference-item"
                                @click.stop="previewReference(index)"
                            >
                                <image
                                    v-if="asset.type === 'image'"
                                    class="reference-image"
                                    :src="getImageUrl(asset.uri)"
                                    mode="aspectFill"
                                />
                                <view v-else class="reference-video">视频</view>
                                <view
                                    class="reference-delete"
                                    @click.stop="removeReferenceAsset(index)"
                                    >×</view
                                >
                            </view>
                            <view
                                v-if="referenceAssets.length < maxReferenceCount"
                                class="reference-add"
                                @click.stop="chooseReferenceImages"
                            >
                                <u-icon name="plus" color="#ffffff" size="58"></u-icon>
                                <view>添加图片</view>
                            </view>
                            <view
                                v-if="supportVideoAssets && referenceAssets.length < maxReferenceCount"
                                class="reference-add"
                                @click.stop="chooseReferenceVideo"
                            >
                                <u-icon name="plus" color="#ffffff" size="58"></u-icon>
                                <view>添加视频</view>
                            </view>
                        </view>
                    </template>
                    <template v-else>
                        <view class="reference-list reference-list--empty">
                            <view
                                class="reference-add reference-add--empty"
                                @click.stop="chooseReferenceImages"
                            >
                                <u-icon name="plus" color="#ffffff" size="58"></u-icon>
                                <view>添加图片</view>
                            </view>
                            <view
                                v-if="supportVideoAssets"
                                class="reference-add reference-add--empty"
                                @click.stop="chooseReferenceVideo"
                            >
                                <u-icon name="plus" color="#ffffff" size="58"></u-icon>
                                <view>添加视频</view>
                            </view>
                        </view>
                    </template>
                </view>
            </view>

            <view class="section">
                <view class="label-row label-row--between">
                    <view class="label-left">
                        <view class="label-icon">
                            <u-icon name="edit-pen" color="#ffffff" size="30"></u-icon>
                        </view>
                        <view class="label-text">创作内容描述：</view>
                    </view>
                    <view class="script-action" @click="openScriptPopup">
                        <u-icon name="file-text" color="#ffffff" size="26"></u-icon>
                        <text>分镜脚本撰写</text>
                    </view>
                </view>
                <view class="prompt-box">
                    <textarea
                        v-model="form.prompt"
                        class="prompt-input"
                        maxlength="800"
                        placeholder="简单描述你要生成的视频画面、镜头和风格..."
                        placeholder-class="prompt-placeholder"
                    />
                    <view v-if="form.prompt" class="clear-btn" @click="form.prompt = ''">清空</view>
                </view>
            </view>

            <view v-if="channels.length > 1" class="section">
                <view class="label-row">
                    <view class="label-icon">
                        <u-icon name="server-man" color="#ffffff" size="30"></u-icon>
                    </view>
                    <view class="label-text">通道选择</view>
                </view>
                <view class="segmented">
                    <view
                        v-for="item in channels"
                        :key="item.value"
                        class="segment"
                        :class="{ 'is-active': form.channel === item.value }"
                        @click="selectChannel(item.value)"
                    >
                        {{ item.label }}
                    </view>
                </view>
            </view>

            <view v-if="qualities.length > 1" class="section">
                <view class="label-row">
                    <view class="label-icon label-icon--text">HD</view>
                    <view class="label-text">清晰度：</view>
                </view>
                <view class="segmented">
                    <view
                        v-for="item in qualities"
                        :key="item.value"
                        class="segment"
                        :class="{ 'is-active': form.quality === item.value }"
                        @click="selectQuality(item.value)"
                    >
                        {{ item.label }}
                    </view>
                </view>
            </view>

            <view v-if="durations.length > 1" class="section">
                <view class="label-row">
                    <view class="label-icon label-icon--text">SEC</view>
                    <view class="label-text">视频时长：</view>
                </view>
                <view class="segmented">
                    <view
                        v-for="item in durations"
                        :key="item"
                        class="segment"
                        :class="{ 'is-active': form.duration === item }"
                        @click="selectDuration(item)"
                    >
                        {{ item }}秒
                    </view>
                </view>
            </view>

            <view v-if="ratios.length > 1" class="section">
                <view class="label-row">
                    <view class="label-icon">
                        <u-icon name="grid" color="#ffffff" size="30"></u-icon>
                    </view>
                    <view class="label-text">视频比例：</view>
                </view>
                <view class="ratio-grid">
                    <view
                        v-for="item in ratios"
                        :key="`${form.quality}-${item.value}`"
                        class="ratio-card"
                        :class="{ 'is-active': form.ratio === item.value }"
                        @click="selectRatio(item.value)"
                    >
                        <view class="ratio-shape" :class="`ratio-shape--${item.key}`"></view>
                        <view>{{ item.label }}</view>
                    </view>
                </view>
            </view>

            <view v-if="quantities.length > 1" class="section">
                <view class="label-row">
                    <view class="label-icon">
                        <u-icon name="plus-circle" color="#ffffff" size="30"></u-icon>
                    </view>
                    <view class="label-text">生成数量：</view>
                </view>
                <view class="count-row">
                    <view
                        v-for="item in quantities"
                        :key="item"
                        class="count-chip"
                        :class="{ 'is-active': form.quantity === item }"
                        @click="selectQuantity(item)"
                    >
                        {{ item }}条
                    </view>
                </view>
            </view>

            <view class="section">
                <view class="label-row label-row--between">
                    <view class="label-left">
                        <view class="label-icon">
                            <u-icon name="star" color="#ffffff" size="30"></u-icon>
                        </view>
                        <view class="label-text">示例同款</view>
                    </view>
                    <view class="section-link" @click="goResults">历史视频</view>
                </view>
                <scroll-view class="example-scroll" scroll-x>
                    <view class="example-list">
                        <view
                            v-for="item in examples"
                            :key="item.id"
                            class="example-card"
                            @click="applyExample(item)"
                        >
                            <image
                                class="example-image"
                                :src="getImageUrl(item.cover)"
                                mode="aspectFill"
                            />
                            <view class="example-mask"></view>
                            <view class="example-info">
                                <view class="example-title">{{ item.title }}</view>
                                <view class="example-meta"
                                    >{{ item.ratio }} · {{ qualityLabel(item.quality) }} ·
                                    {{ item.quantity }}条</view
                                >
                                <view class="example-use">一键同款</view>
                            </view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view v-if="generatingTaskId && !results.length" class="pending-panel">
                <view class="pending-panel__title">作品生成中</view>
                <view class="pending-panel__desc">生成完成后会自动出现在最近生成和作品列表</view>
            </view>

            <view v-if="results.length" class="section">
                <view class="label-row label-row--between">
                    <view class="label-left">
                        <view class="label-icon label-icon--text">VID</view>
                        <view class="label-text">最近生成</view>
                    </view>
                    <view class="section-link" @click="goResults">全部</view>
                </view>
                <view class="result-grid">
                    <view v-for="item in results.slice(0, 4)" :key="item.id" class="result-card">
                        <video
                            class="result-video"
                            :src="item.video_url"
                            :controls="true"
                            :show-center-play-btn="true"
                            object-fit="cover"
                        />
                        <view class="result-meta">
                            <text>{{ item.width || 1024 }}×{{ item.height || 1024 }}</text>
                            <text>{{ item.prompt ? '提示词' : '作品' }}</text>
                        </view>
                        <view class="result-actions">
                            <view class="result-action" @click="reuseResult(item)">
                                <u-icon name="reload" color="#ffffff" size="22"></u-icon>
                                <text>复用</text>
                            </view>
                            <view
                                class="result-action result-action--danger"
                                @click="handleDelete(item.task_id || item.id)"
                            >
                                <u-icon
                                    name="trash"
                                    color="rgba(255,255,255,0.72)"
                                    size="22"
                                ></u-icon>
                            </view>
                        </view>
                    </view>
                </view>
            </view>
        </scroll-view>

        <view class="bottom-bar">
            <button class="history-btn" @click="goResults">
                <u-icon name="play-circle" color="#ffffff" size="38"></u-icon>
                <view>历史视频</view>
            </button>
            <button
                class="generate-btn"
                :class="{ 'is-disabled': submitting }"
                :loading="submitting"
                :disabled="submitting"
                @click="handleGenerate"
            >
                <view>{{ submitting ? '生成中...' : '立即生成' }}</view>
                <text>本次消耗 {{ estimatedCost }} 创作点数</text>
            </button>
        </view>

        <view v-if="submitting" class="submit-mask" @click.stop @touchmove.stop.prevent>
            <view class="submit-loading">
                <u-loading mode="circle" color="#ffffff" size="52"></u-loading>
                <view class="submit-loading__title">正在提交生成任务</view>
                <view class="submit-loading__desc">任务创建成功后将进入作品列表</view>
            </view>
        </view>

        <view v-if="scriptPopupVisible" class="popup-mask" @click="closeScriptPopup">
            <view class="script-popup" @click.stop>
                <view class="popup-head">
                    <view class="popup-title">
                        <u-icon name="edit-pen-fill" color="#ffffff" size="34"></u-icon>
                        <text>分镜脚本撰写</text>
                    </view>
                    <view class="popup-close" @click="closeScriptPopup">
                        <u-icon name="close" color="rgba(255,255,255,0.72)" size="30"></u-icon>
                    </view>
                </view>
                <view class="popup-input-box">
                    <textarea
                        v-model="scriptInput"
                        class="popup-textarea"
                        maxlength="500"
                        placeholder="输入你想要的画面、产品、镜头数量、文案风格..."
                        placeholder-class="popup-placeholder"
                    />
                    <view class="script-example" @click="useScriptExample">
                        脚本示例：帮我写一段9个分镜的广告片脚本，内容是一个篮球运动员投失进球后，喝下一瓶功能饮料后进球打赢比赛，最后是定版，产品logo和口号清晰呈现，文字简短些
                    </view>
                </view>
                <view v-if="scriptResult" class="script-result">
                    <view class="script-result__title">生成文案</view>
                    <view class="script-result__text">{{ scriptResult }}</view>
                </view>
                <button
                    class="popup-primary"
                    :loading="scriptGenerating"
                    @click="handleScriptPrimary"
                >
                    {{ scriptResult ? '确认使用' : '生成文案' }}
                </button>
            </view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onHide, onShow, onUnload } from '@dcloudio/uni-app'
import { uploadImage, uploadVideo } from '@/api/app'
import { getMembershipAppAccess } from '@/api/membership'
import {
    deleteAigcVideoResult,
    generateAigcVideo,
    getAigcVideoConfig,
    getAigcVideoResults
} from '@/apps/aigc_video/api'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'

type Quality = string
type Channel = string

interface RatioOption {
    label: string
    value: string
    key: string
    width?: number
    height?: number
    tenant_unit_price?: string | number
}

interface QualityOption {
    label: string
    value: string
    ratios: RatioOption[]
}

interface ChannelOption {
    label: string
    value: string
    max_reference_images?: number
    max_reference_videos?: number
    max_reference_assets?: number
    supported_asset_types?: ReferenceAssetType[]
    quantity_options?: number[]
    duration_options?: number[]
    videoedit_duration_options?: number[]
    specs?: any[]
    qualities: QualityOption[]
}

type ReferenceAssetType = 'image' | 'video' | 'audio'
interface ReferenceAsset {
    type: ReferenceAssetType
    uri: string
    url: string
    name: string
}

interface ExampleItem {
    id: number
    title: string
    prompt: string
    ratio: string
    quality: Quality
    quantity: number
    duration?: number
    channel: Channel
    cover: string
    reference_images: string[]
}

const appStore = useAppStore()
const userStore = useUserStore()
const submitting = ref(false)
const generatingTaskId = ref(0)
let refreshTimer: ReturnType<typeof setInterval> | null = null
const uploading = ref(false)
const scriptPopupVisible = ref(false)
const scriptGenerating = ref(false)
const scriptInput = ref('')
const scriptResult = ref('')
const results = ref<any[]>([])
const configLoaded = ref(false)
const membershipAccess = ref<any>({
    need_membership: 0,
    allowed: 1,
    member_status: 'none'
})
const optionConfig = ref<any>({
    channels: [],
    defaults: {
        channel: 'grok_video_xaiq',
        quality: '6',
        ratio: '16:9',
        quantity: 1,
        duration: 5
    },
    quantity_options: [1],
    max_reference_images: 7
})
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})

const exampleImage = 'resource/image/tenantapi/default/banner001.png'
const examples: ExampleItem[] = [
    {
        id: 1,
        title: '产品广告片',
        prompt: '透明玻璃质感的智能耳机悬浮在黑色镜面平台上，镜头缓慢环绕，冷白色轮廓光扫过产品边缘，极简高端商业广告质感',
        ratio: '16:9',
        quality: '6',
        quantity: 1,
        channel: 'grok_video_xaiq',
        cover: exampleImage,
        reference_images: [exampleImage]
    },
    {
        id: 2,
        title: '电影人像',
        prompt: '夜晚霓虹街头，一位年轻人回头看向镜头，雨后地面反光，浅景深，镜头轻微推进，真实皮肤质感和电影级光影',
        ratio: '9:16',
        quality: '10',
        quantity: 1,
        channel: 'grok_video_xaiq',
        cover: exampleImage,
        reference_images: [exampleImage]
    },
    {
        id: 3,
        title: '国潮短片',
        prompt: '国潮风山海主题动态短片，层叠云雾缓慢流动，金色线条装饰发光，镜头穿过云层推向主体，东方神话氛围',
        ratio: '9:16',
        quality: '6',
        quantity: 1,
        channel: 'grok_video_xaiq',
        cover: exampleImage,
        reference_images: []
    }
]

const form = reactive<{
    prompt: string
    reference_images: string[]
    reference_assets: ReferenceAsset[]
    ratio: string
    quality: Quality
    quantity: number
    duration: number
    channel: Channel
    negative_prompt: string
}>({
    prompt: '',
    reference_images: [],
    reference_assets: [],
    ratio: '16:9',
    quality: '6',
    quantity: 1,
    duration: 5,
    channel: 'grok_video_xaiq',
    negative_prompt: ''
})

const normalizeRatioKey = (value: string) => value.replace(/[^0-9a-z]/gi, '-')

const channels = computed<ChannelOption[]>(() =>
    (optionConfig.value.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        value: channel.value || channel.code,
        max_reference_images: Number(
            channel.max_reference_images || optionConfig.value.max_reference_images || 4
        ),
        max_reference_videos: Number(channel.max_reference_videos || 0),
        max_reference_assets: Number(channel.max_reference_assets || 0),
        supported_asset_types: Array.isArray(channel.supported_asset_types)
            ? channel.supported_asset_types.filter((item: string) => ['image', 'video', 'audio'].includes(item))
            : ['image'],
        quantity_options: (channel.quantity_options || optionConfig.value.quantity_options || [1])
            .map((item: any) => Number(item))
            .filter(Boolean),
        duration_options: normalizeNumberOptions(channel.duration_options || [5]),
        videoedit_duration_options: normalizeNumberOptions(channel.videoedit_duration_options || []),
        specs: channel.specs || [],
        qualities: (channel.qualities || []).map((quality: any) => ({
            label:
                quality.label || quality.quality_label || String(quality.value || '').toUpperCase(),
            value: quality.value || quality.quality,
            ratios: (quality.ratios || []).map((ratio: any) => ({
                ...ratio,
                label: ratio.label || ratio.ratio || ratio.value,
                value: ratio.value || ratio.ratio,
                key: normalizeRatioKey(ratio.value || ratio.ratio || '1:1')
            }))
        }))
    }))
)
const currentChannel = computed(
    () => channels.value.find((item) => item.value === form.channel) || channels.value[0]
)
const qualities = computed<QualityOption[]>(() => currentChannel.value?.qualities || [])
const currentQuality = computed(
    () => qualities.value.find((item) => item.value === form.quality) || qualities.value[0]
)
const ratios = computed<RatioOption[]>(() => currentQuality.value?.ratios || [])
const currentSpec = computed(
    () => ratios.value.find((item) => item.value === form.ratio) || ratios.value[0]
)
const quantities = computed<number[]>(() => {
    const configured = (
        currentChannel.value?.quantity_options ||
        optionConfig.value.quantity_options ||
        []
    )
        .map((item: any) => Number(item))
        .filter(Boolean)
    return Array.from(new Set(configured.length ? configured : [1])).sort((a, b) => a - b)
})
const normalizeNumberOptions = (options: any[]) =>
    Array.from(new Set((options || []).map((item: any) => Number(item)).filter(Boolean))).sort((a, b) => a - b)
const supportedAssetTypes = computed<ReferenceAssetType[]>(() => currentChannel.value?.supported_asset_types?.length ? currentChannel.value.supported_asset_types : ['image'])
const supportVideoAssets = computed(() => supportedAssetTypes.value.includes('video') && Number(currentChannel.value?.max_reference_videos || 0) > 0)
const videoReferenceCount = computed(() => referenceAssets.value.filter((item) => item.type === 'video').length)
const durations = computed(() => {
    const options = videoReferenceCount.value > 0 && currentChannel.value?.videoedit_duration_options?.length
        ? currentChannel.value.videoedit_duration_options
        : currentChannel.value?.duration_options
    return normalizeNumberOptions(options || [5])
})
const currentPricingSpec = computed(() =>
    (currentChannel.value?.specs || []).find((spec: any) =>
        String(spec.ratio || spec.value) === String(form.ratio)
        && String(spec.resolution || '').toUpperCase() === String(currentQuality.value?.label || '').toUpperCase()
        && Number.parseInt(String(spec.duration || spec.quality_label || spec.quality || ''), 10) === Number(form.duration)
    ) || currentSpec.value
)
const maxReferenceCount = computed(() => {
    const configured = Number(currentChannel.value?.max_reference_assets || optionConfig.value.max_reference_assets || 0)
    if (configured > 0) return configured
    return Number(currentChannel.value?.max_reference_images || optionConfig.value.max_reference_images || 4)
        + Number(currentChannel.value?.max_reference_videos || 0)
})
const estimatedCost = computed(() => {
    const unitPrice = Number(currentPricingSpec.value?.tenant_unit_price || 0)
    return Number((form.quantity * unitPrice).toFixed(2))
})
const previewImages = computed(() =>
    form.reference_images.map((item) => appStore.getImageUrl(item))
)
const referenceAssets = computed<ReferenceAsset[]>(() => {
    if (form.reference_assets.length) return form.reference_assets
    return form.reference_images.map((uri) => ({ type: 'image', uri, url: uri, name: '参考图' }))
})
const topbarStyle = computed(() => ({ height: `${navMetrics.navHeight}px` }))
const navRowStyle = computed(() => ({
    top: `${navMetrics.menuTop}px`,
    height: `${navMetrics.menuHeight}px`
}))
const pageTitleStyle = computed(() => ({
    height: `${navMetrics.menuHeight}px`,
    lineHeight: `${navMetrics.menuHeight}px`,
    left: `${navMetrics.menuWidth + 8}px`,
    right: `${navMetrics.menuWidth + 8}px`
}))
const navPlaceholderStyle = computed(() => ({
    width: `${navMetrics.menuWidth}px`,
    height: `${navMetrics.menuHeight}px`
}))

const getImageUrl = (url: string) => appStore.getImageUrl(url)

const qualityLabel = (value: string) =>
    qualities.value.find((item) => item.value === value)?.label || `${value}秒`

const getData = async () => {
    const list = await getAigcVideoResults()
    results.value = (list || []).filter((item: any) => item.status === 'success')
    const hasRunning = (list || []).some((item: any) => item.status === 'running')
    if (!hasRunning && refreshTimer) {
        stopResultPolling()
    }
}

const stopResultPolling = () => {
    if (!refreshTimer) return
    clearInterval(refreshTimer)
    refreshTimer = null
}

const startResultPolling = () => {
    stopResultPolling()
    refreshTimer = setInterval(() => {
        getData()
    }, 5000)
}

const syncSelection = () => {
    if (!channels.value.length) return
    if (!channels.value.some((item) => item.value === form.channel)) {
        form.channel = channels.value[0].value
    }
    if (!qualities.value.some((item) => item.value === form.quality)) {
        form.quality = qualities.value[0]?.value || ''
    }
    if (!ratios.value.some((item) => item.value === form.ratio)) {
        form.ratio = ratios.value[0]?.value || ''
    }
    if (!quantities.value.includes(form.quantity)) {
        form.quantity = quantities.value[0] || 1
    }
    if (!durations.value.includes(Number(form.duration))) {
        form.duration = durations.value[0] || 5
    }
    form.reference_assets = referenceAssets.value
        .filter((item) => supportedAssetTypes.value.includes(item.type))
        .slice(0, maxReferenceCount.value)
    form.reference_images = form.reference_assets.filter((item) => item.type === 'image').map((item) => item.uri)
    if (form.reference_assets.length > maxReferenceCount.value) {
        form.reference_assets = form.reference_assets.slice(0, maxReferenceCount.value)
    }
}

const getConfig = async (useDefaults = false) => {
    const config: any = await getAigcVideoConfig()
    optionConfig.value = config?.option_config || optionConfig.value
    const defaults = optionConfig.value.defaults || {}
    if (useDefaults) {
        form.channel = defaults.channel || form.channel
        form.quality = defaults.quality || form.quality
        form.ratio = defaults.ratio || form.ratio
        form.quantity = Number(defaults.quantity || form.quantity || 1)
        form.duration = Number(defaults.duration || form.duration || 5)
    }
    syncSelection()
    configLoaded.value = true
}

const initNavMetrics = () => {
    const systemInfo = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = systemInfo.statusBarHeight || navMetrics.statusBarHeight
    // #ifdef MP-WEIXIN
    const menuButton = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = menuButton.top
    navMetrics.menuHeight = menuButton.height
    navMetrics.menuWidth = systemInfo.windowWidth - menuButton.left
    navMetrics.navHeight = menuButton.top + menuButton.height + 10
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 8
    navMetrics.menuHeight = 34
    navMetrics.menuWidth = 88
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 10
    // #endif
}

const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) {
        uni.navigateBack()
        return
    }
    uni.switchTab({ url: '/pages/index/index' })
}

const chooseReferenceImages = async () => {
    if (uploading.value) return
    const count = maxReferenceCount.value - referenceAssets.value.length
    if (count <= 0) {
        uni.$u.toast(`最多添加${maxReferenceCount.value}个参考素材`)
        return
    }
    try {
        const chooseRes: any = await uni.chooseImage({
            count,
            sizeType: ['compressed'],
            sourceType: ['album', 'camera']
        })
        const paths = chooseRes?.tempFilePaths || []
        if (!paths.length) return
        uploading.value = true
        uni.showLoading({ title: '上传中...' })
        for (const path of paths) {
            const res: any = await uploadImage(path)
            const uri = res?.uri || res?.url || res?.path
            if (uri && referenceAssets.value.length < maxReferenceCount.value) {
                form.reference_assets.push({ type: 'image', uri, url: uri, name: '参考图' })
                form.reference_images = form.reference_assets.filter((item) => item.type === 'image').map((item) => item.uri)
            }
        }
    } catch (error: any) {
        if (error?.errMsg && error.errMsg.indexOf('cancel') > -1) return
        uni.$u.toast(error || '上传失败')
    } finally {
        uploading.value = false
        uni.hideLoading()
    }
}

const chooseReferenceVideo = async () => {
    if (uploading.value) return
    if (!supportVideoAssets.value) {
        uni.$u.toast('当前通道不支持视频参考')
        return
    }
    if (referenceAssets.value.length >= maxReferenceCount.value) {
        uni.$u.toast(`最多添加${maxReferenceCount.value}个参考素材`)
        return
    }
    if (videoReferenceCount.value >= Number(currentChannel.value?.max_reference_videos || 0)) {
        uni.$u.toast(`最多添加${currentChannel.value?.max_reference_videos || 0}个参考视频`)
        return
    }
    try {
        const chooseRes: any = await uni.chooseVideo({
            sourceType: ['album', 'camera'],
            compressed: true,
            maxDuration: 60
        })
        const path = chooseRes?.tempFilePath
        if (!path) return
        uploading.value = true
        uni.showLoading({ title: '上传中...' })
        const res: any = await uploadVideo(path)
        const uri = res?.uri || res?.url || res?.path
        if (uri) {
            form.reference_assets.push({ type: 'video', uri, url: uri, name: '参考视频' })
        }
    } catch (error: any) {
        if (error?.errMsg && error.errMsg.indexOf('cancel') > -1) return
        uni.$u.toast(error || '上传失败')
    } finally {
        uploading.value = false
        uni.hideLoading()
    }
}

const previewReference = (index: number) => {
    const asset = referenceAssets.value[index]
    if (asset?.type !== 'image') return
    const urls = referenceAssets.value.filter((item) => item.type === 'image').map((item) => appStore.getImageUrl(item.uri))
    uni.previewImage({
        urls,
        current: appStore.getImageUrl(asset.uri)
    })
}

const removeReferenceAsset = (index: number) => {
    form.reference_assets.splice(index, 1)
    form.reference_images = form.reference_assets.filter((item) => item.type === 'image').map((item) => item.uri)
}

const openScriptPopup = () => {
    scriptPopupVisible.value = true
    scriptInput.value = form.prompt
    scriptResult.value = ''
}

const closeScriptPopup = () => {
    if (scriptGenerating.value) return
    scriptPopupVisible.value = false
}

const useScriptExample = () => {
    scriptInput.value =
        '帮我写一段9个分镜的广告片脚本，内容是一个篮球运动员投失进球后，喝下一瓶功能饮料后进球打赢比赛，最后是定版，产品logo和口号清晰呈现，文字简短些'
}

const buildScriptCopy = (input: string) => {
    const subject = input.trim() || '基于参考图创作一支高级感广告片'
    return [
        `${subject}`,
        '分镜1：建立场景，主体入画，环境干净，氛围明确。',
        '分镜2：特写主体状态，突出情绪和动作细节。',
        '分镜3：展示关键转折，加入产品或核心道具。',
        '分镜4：用快速切换镜头强化节奏，画面保持高级质感。',
        '分镜5：突出产品细节、材质、光影和使用瞬间。',
        '分镜6：主体完成关键动作，情绪由低到高。',
        '分镜7：给出结果画面，成功感和冲击力清晰。',
        '分镜8：定版海报构图，产品、logo、口号清晰呈现。',
        '分镜9：收尾画面干净，文字简短有力，整体电影感、高级商业广告风格。'
    ].join('\n')
}

const handleScriptPrimary = async () => {
    if (scriptResult.value) {
        form.prompt = scriptResult.value
        scriptPopupVisible.value = false
        uni.$u.toast('已填入创作描述')
        return
    }
    if (!scriptInput.value.trim()) {
        uni.$u.toast('请输入脚本需求')
        return
    }
    scriptGenerating.value = true
    try {
        await new Promise((resolve) => setTimeout(resolve, 450))
        scriptResult.value = buildScriptCopy(scriptInput.value)
    } finally {
        scriptGenerating.value = false
    }
}

const applyExample = (item: ExampleItem) => {
    form.prompt = item.prompt
    form.reference_images = [...item.reference_images]
    form.reference_assets = form.reference_images.map((uri) => ({ type: 'image', uri, url: uri, name: '参考图' }))
    form.ratio = item.ratio
    form.quality = item.quality
    form.quantity = item.quantity
    form.duration = item.duration || form.duration || 5
    form.channel = item.channel
    syncSelection()
    uni.pageScrollTo({ scrollTop: 0, duration: 180 })
    uni.$u.toast('已填充示例参数')
}

const applyReuseCache = () => {
    const cached = uni.getStorageSync('aigc_video_reuse')
    if (!cached) return
    form.prompt = cached.prompt || form.prompt || '参考这条作品继续生成同风格视频'
    form.reference_images = cached.reference_images || []
    form.reference_assets = cached.reference_assets || form.reference_images.map((uri) => ({ type: 'image', uri, url: uri, name: '参考图' }))
    form.ratio = cached.ratio || form.ratio
    form.quality = cached.quality || form.quality
    form.quantity = cached.quantity || form.quantity
    form.duration = cached.duration || form.duration
    form.channel = cached.channel || form.channel
    syncSelection()
    uni.removeStorageSync('aigc_video_reuse')
}

const selectChannel = (value: string) => {
    form.channel = value
    form.quality = currentChannel.value?.qualities?.[0]?.value || form.quality
    form.ratio = currentQuality.value?.ratios?.[0]?.value || form.ratio
    syncSelection()
}

const selectQuality = (value: string) => {
    const nextQuality = qualities.value.find((item) => item.value === value)
    form.quality = value
    form.ratio = nextQuality?.ratios?.[0]?.value || form.ratio
    syncSelection()
}

const selectRatio = (value: string) => {
    form.ratio = value
    syncSelection()
}

const selectDuration = (value: number) => {
    form.duration = value
    syncSelection()
}

const selectQuantity = (value: number) => {
    form.quantity = value
    syncSelection()
}

const handleGenerate = async () => {
    if (!(await ensureMembershipAccess())) return
    if (!form.prompt.trim()) {
        uni.$u.toast('请输入创作内容描述')
        return
    }
    if (submitting.value) return
    syncSelection()
    if (userStore.isLogin && Object.keys(userStore.userInfo || {}).length === 0) {
        await userStore.getUser()
    }
    const balance = Number(userStore.userInfo?.user_money || 0)
    if (userStore.isLogin && balance < estimatedCost.value) {
        uni.$u.toast('点数不足，请充值')
        return
    }
    submitting.value = true
    uni.showLoading({ title: '提交中...', mask: true })
    try {
        const submitCount = quantities.value.includes(Number(form.quantity))
            ? Number(form.quantity)
            : 1
        let hasRunningTask = false
        for (let index = 0; index < submitCount; index += 1) {
            const res: any = await generateAigcVideo({
                prompt: form.prompt.trim(),
                reference_images: form.reference_images,
                reference_assets: referenceAssets.value,
                ratio: form.ratio,
                quality: form.quality,
                duration: form.duration,
                quantity: 1,
                channel: form.channel,
                negative_prompt: form.negative_prompt
            })
            generatingTaskId.value = Number(res?.task_id || generatingTaskId.value || 0)
            hasRunningTask = hasRunningTask || res?.status === 'running'
        }
        if (hasRunningTask) {
            uni.$u.toast(`已提交${submitCount}条任务，生成中`)
            startResultPolling()
        }
        await userStore.getUser()
        stopResultPolling()
        uni.navigateTo({ url: '/apps/aigc_video/pages/results/results' })
    } finally {
        submitting.value = false
        uni.hideLoading()
    }
}

const reuseResult = (item: any) => {
    form.prompt = item.prompt || form.prompt || '参考最近作品继续生成同风格视频'
    form.reference_images = []
    form.reference_assets = []
    form.channel = item.channel || form.channel
    form.quality = item.quality || form.quality
    form.ratio = item.ratio || form.ratio
    form.duration = Number(item.duration || form.duration || 5)
    syncSelection()
    uni.pageScrollTo({ scrollTop: 0, duration: 180 })
}

const handleDelete = async (id: number) => {
    await deleteAigcVideoResult({ id, task_id: id })
    uni.$u.toast('删除成功')
    getData()
}

const goResults = () => {
    uni.navigateTo({ url: '/apps/aigc_video/pages/results/results' })
}

const ensureMembershipAccess = async () => {
    try {
        const data = await getMembershipAppAccess({ app_code: 'aigc_video' })
        membershipAccess.value = data || membershipAccess.value
        if (Number(data?.need_membership || 0) === 1 && Number(data?.allowed || 0) !== 1) {
            uni.showModal({
                title: '会员专享应用',
                content: '该应用需开通会员后使用',
                confirmText: '去开通',
                success: (res) => {
                    if (res.confirm) {
                        uni.navigateTo({ url: '/packages/pages/membership/membership' })
                    }
                }
            })
            return false
        }
    } catch (error) {
        return false
    }
    return true
}

initNavMetrics()
onShow(async () => {
    userStore.getUser()
    ensureMembershipAccess()
    await getConfig(!configLoaded.value)
    applyReuseCache()
    getData()
})

onHide(stopResultPolling)
onUnload(stopResultPolling)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #ffffff;
}

.page-bg,
.page-lines {
    position: fixed;
    inset: 0;
    pointer-events: none;
}

.page-bg {
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        radial-gradient(circle at 10% 2%, rgba(255, 255, 255, 0.035), transparent 34%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
}

.page-lines {
    opacity: 0.34;
    background: repeating-radial-gradient(
        circle at 52% -8%,
        transparent 0 42rpx,
        rgba(88, 112, 180, 0.18) 44rpx 46rpx,
        transparent 48rpx 78rpx
    );
}

.topbar {
    position: relative;
    z-index: 2;
    height: 92rpx;
    box-sizing: border-box;
}

.nav-row {
    position: absolute;
    left: 28rpx;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.nav-btn--back {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    width: 72rpx;
    height: 100%;
}

.page-title {
    position: absolute;
    top: 0;
    bottom: 0;
    text-align: center;
    font-size: 32rpx;
    font-weight: 700;
    color: #ffffff;
    letter-spacing: 0;
}

.nav-placeholder {
    width: 180rpx;
    height: 64rpx;
}

.content {
    position: relative;
    z-index: 1;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 18rpx 32rpx 226rpx;
    box-sizing: border-box;
}

.section {
    margin-bottom: 42rpx;
}

.pending-panel {
    margin-bottom: 42rpx;
    padding: 30rpx 32rpx;
    border-radius: 24rpx;
    background: rgba(255, 255, 255, 0.08);
    border: 1rpx solid rgba(255, 255, 255, 0.1);
}

.pending-panel__title {
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 700;
    line-height: 1.4;
}

.pending-panel__desc {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 24rpx;
    line-height: 1.5;
}

.label-row,
.label-left {
    display: flex;
    align-items: center;
    gap: 14rpx;
}

.label-row {
    margin-bottom: 20rpx;
}

.label-row--between {
    justify-content: space-between;
}

.label-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 38rpx;
    height: 38rpx;
    flex: 0 0 34rpx;
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 700;
    line-height: 1;
}

.label-icon--text {
    font-size: 20rpx;
}

.label-text {
    font-size: 29rpx;
    line-height: 1.3;
    font-weight: 700;
}

.script-action,
.section-link {
    display: flex;
    align-items: center;
    gap: 8rpx;
    color: rgba(255, 255, 255, 0.78);
    font-size: 25rpx;
    font-weight: 700;
}

.reference-box {
    min-height: 250rpx;
    border-radius: 26rpx;
    background: rgba(34, 34, 34, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.06);
    box-sizing: border-box;
}

.reference-list {
    display: flex;
    align-items: center;
    gap: 20rpx;
    min-height: 250rpx;
    padding: 22rpx;
    flex-wrap: wrap;
    box-sizing: border-box;
}

.reference-list--empty {
    justify-content: flex-start;
}

.reference-item,
.reference-add {
    position: relative;
    width: 224rpx;
    height: 224rpx;
    border-radius: 18rpx;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.08);
    border: 1rpx solid rgba(255, 255, 255, 0.08);
}

.reference-image {
    width: 100%;
    height: 100%;
}

.reference-video {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 700;
    background: rgba(255, 255, 255, 0.1);
}

.reference-delete {
    position: absolute;
    right: 14rpx;
    top: 14rpx;
    width: 58rpx;
    height: 58rpx;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.44);
    backdrop-filter: blur(8rpx);
    color: #ffffff;
    font-size: 48rpx;
    font-weight: 300;
    line-height: 52rpx;
    text-align: center;
}

.reference-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14rpx;
    border: 2rpx dashed rgba(255, 255, 255, 0.42);
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 800;
}

.reference-add--empty {
    width: 224rpx;
    height: 224rpx;
}

.prompt-box {
    position: relative;
    min-height: 500rpx;
    border-radius: 20rpx;
    background: rgba(255, 255, 255, 0.02);
    border: 1rpx solid rgba(255, 255, 255, 0.04);
}

.prompt-input {
    width: 100%;
    height: 500rpx;
    padding: 32rpx;
    box-sizing: border-box;
    color: #ffffff;
    font-size: 28rpx;
    line-height: 1.7;
}

.prompt-placeholder {
    color: rgba(255, 255, 255, 0.42);
    font-weight: 700;
}

.clear-btn {
    position: absolute;
    right: 24rpx;
    bottom: 24rpx;
    color: #ffffff;
    font-size: 26rpx;
    font-weight: 700;
}

.segmented {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10rpx;
    padding: 8rpx;
    border-radius: 18rpx;
    background: rgba(34, 34, 34, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.06);
}

.segment {
    position: relative;
    overflow: hidden;
    height: 74rpx;
    border-radius: 14rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 27rpx;
    line-height: 74rpx;
    text-align: center;
    font-weight: 700;
    transition: color 0.22s ease, transform 0.22s ease, background 0.22s ease, box-shadow 0.22s ease;
}

.segment::after,
.count-chip::after,
.ratio-card::after {
    content: '';
    position: absolute;
    inset: -40%;
    background: linear-gradient(
        120deg,
        transparent 32%,
        rgba(255, 255, 255, 0.52),
        transparent 66%
    );
    transform: translateX(-130%) rotate(18deg);
    opacity: 0;
}

.segment.is-active::after,
.count-chip.is-active::after,
.ratio-card.is-active::after {
    animation: activeShine 0.58s ease;
}

.segment:active,
.count-chip:active,
.ratio-card:active {
    transform: scale(0.96);
}

.segment.is-active,
.count-chip.is-active,
.ratio-card.is-active {
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
    box-shadow: 0 0 0 1rpx rgba(255, 255, 255, 0.32), 0 14rpx 28rpx rgba(0, 0, 0, 0.26),
        inset 0 1rpx 0 rgba(255, 255, 255, 0.12);
    animation: activePop 0.28s cubic-bezier(0.2, 1.4, 0.4, 1);
}

.ratio-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12rpx;
}

.ratio-card {
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6rpx;
    height: 88rpx;
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
    color: rgba(255, 255, 255, 0.68);
    font-size: 21rpx;
    line-height: 1.1;
    font-weight: 700;
    box-sizing: border-box;
}

.ratio-shape {
    width: 24rpx;
    height: 24rpx;
    flex: 0 0 auto;
    border: 3rpx solid currentColor;
    border-radius: 4rpx;
    box-sizing: border-box;
}

.ratio-shape--3-4 {
    width: 22rpx;
    height: 30rpx;
}

.ratio-shape--4-3 {
    width: 30rpx;
    height: 22rpx;
}

.ratio-shape--9-16 {
    width: 18rpx;
    height: 32rpx;
}

.ratio-shape--16-9 {
    width: 32rpx;
    height: 18rpx;
}

.count-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10rpx;
}

.count-chip {
    position: relative;
    overflow: hidden;
    height: 74rpx;
    border-radius: 16rpx;
    background: rgba(34, 34, 34, 0.96);
    color: rgba(255, 255, 255, 0.68);
    font-size: 26rpx;
    line-height: 74rpx;
    text-align: center;
    font-weight: 700;
}

.example-scroll {
    width: 100%;
    white-space: nowrap;
}

.example-list {
    display: inline-flex;
    gap: 16rpx;
}

.example-card {
    position: relative;
    width: 220rpx;
    height: 280rpx;
    border-radius: 18rpx;
    overflow: hidden;
    background: #111214;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
}

.example-image {
    width: 100%;
    height: 100%;
}

.example-mask {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, transparent 36%, rgba(0, 0, 0, 0.78));
}

.example-info {
    position: absolute;
    left: 16rpx;
    right: 16rpx;
    bottom: 16rpx;
}

.example-title {
    font-size: 24rpx;
    font-weight: 700;
}

.example-meta {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.62);
    font-size: 20rpx;
    font-weight: 600;
}

.example-use {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 42rpx;
    margin-top: 12rpx;
    padding: 0 16rpx;
    border-radius: 999rpx;
    color: #ffffff;
    font-size: 21rpx;
    font-weight: 700;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}

.result-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16rpx;
}

.result-card {
    overflow: hidden;
    border-radius: 18rpx;
    background: rgba(34, 34, 34, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.08);
}

.result-video {
    width: 100%;
    height: 280rpx;
}

.result-meta {
    display: flex;
    justify-content: space-between;
    padding: 14rpx 16rpx 0;
    color: rgba(255, 255, 255, 0.48);
    font-size: 21rpx;
}

.result-actions {
    display: flex;
    justify-content: space-between;
    padding: 14rpx 16rpx 16rpx;
    color: rgba(255, 255, 255, 0.76);
    font-size: 24rpx;
}

.result-action {
    display: flex;
    align-items: center;
    gap: 6rpx;
    color: rgba(255, 255, 255, 0.82);
}

.result-action--danger {
    color: rgba(255, 255, 255, 0.72);
}

.bottom-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 5;
    display: grid;
    grid-template-columns: 1fr 1.8fr;
    gap: 22rpx;
    padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
    border-top: 1rpx solid rgba(255, 255, 255, 0.08);
    background: rgba(9, 10, 15, 0.98);
}

.history-btn,
.generate-btn {
    height: 104rpx;
    margin: 0;
    border-radius: 16rpx;
    color: #ffffff;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    font-size: 27rpx;
    line-height: 1.15;
    font-weight: 700;
}

.history-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
    background: rgba(255, 255, 255, 0.08);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
}

.generate-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
}

.generate-btn text {
    color: rgba(255, 255, 255, 0.72);
    font-size: 22rpx;
}

.generate-btn.is-disabled,
.generate-btn[disabled] {
    color: #ffffff;
    background: linear-gradient(180deg, rgba(58, 59, 60, 0.72) 0%, rgba(47, 48, 49, 0.72) 100%);
    opacity: 0.86;
}

.submit-mask {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 48rpx;
    background: rgba(0, 0, 0, 0.52);
    backdrop-filter: blur(6rpx);
    box-sizing: border-box;
}

.submit-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 420rpx;
    padding: 46rpx 34rpx;
    border-radius: 24rpx;
    background: rgba(20, 21, 24, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 28rpx 72rpx rgba(0, 0, 0, 0.38);
    box-sizing: border-box;
}

.submit-loading__title {
    margin-top: 24rpx;
    color: #ffffff;
    font-size: 29rpx;
    font-weight: 700;
    line-height: 1.4;
}

.submit-loading__desc {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.6);
    font-size: 23rpx;
    line-height: 1.4;
    text-align: center;
}

.popup-mask {
    position: fixed;
    inset: 0;
    z-index: 20;
    display: flex;
    align-items: flex-end;
    background: rgba(0, 0, 0, 0.62);
}

.script-popup {
    width: 100%;
    padding: 34rpx 32rpx calc(34rpx + env(safe-area-inset-bottom));
    border-radius: 28rpx 28rpx 0 0;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    background: #101218;
    box-sizing: border-box;
}

.popup-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24rpx;
}

.popup-title {
    display: flex;
    align-items: center;
    gap: 12rpx;
    color: #ffffff;
    font-size: 34rpx;
    font-weight: 700;
}

.popup-close {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48rpx;
    height: 48rpx;
    border-radius: 50%;
    color: rgba(255, 255, 255, 0.72);
    border: 2rpx solid rgba(255, 255, 255, 0.24);
}

.popup-input-box {
    border: 1rpx solid rgba(255, 255, 255, 0.14);
    border-radius: 18rpx;
    background: #0c0e14;
    overflow: hidden;
}

.popup-textarea {
    width: 100%;
    height: 300rpx;
    padding: 28rpx;
    color: #ffffff;
    font-size: 28rpx;
    line-height: 1.55;
    box-sizing: border-box;
}

.popup-placeholder {
    color: rgba(255, 255, 255, 0.42);
}

.script-example {
    padding: 0 28rpx 24rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
    line-height: 1.6;
}

.script-result {
    margin-top: 20rpx;
    padding: 22rpx;
    border-radius: 16rpx;
    background: #171a23;
}

.script-result__title {
    margin-bottom: 10rpx;
    color: rgba(255, 255, 255, 0.72);
    font-size: 24rpx;
    font-weight: 700;
}

.script-result__text {
    color: rgba(255, 255, 255, 0.86);
    font-size: 25rpx;
    line-height: 1.7;
    white-space: pre-wrap;
}

.popup-primary {
    height: 92rpx;
    margin: 28rpx 0 0;
    border-radius: 16rpx;
    color: #ffffff;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    font-size: 31rpx;
    font-weight: 700;
}

@keyframes activePop {
    0% {
        transform: scale(0.96);
        filter: brightness(0.86);
    }

    62% {
        transform: scale(1.045);
        filter: brightness(1.18);
    }

    100% {
        transform: scale(1);
        filter: brightness(1);
    }
}

@keyframes activeShine {
    0% {
        opacity: 0;
        transform: translateX(-130%) rotate(18deg);
    }

    30% {
        opacity: 1;
    }

    100% {
        opacity: 0;
        transform: translateX(130%) rotate(18deg);
    }
}
</style>
