<template>
        <div ref="pageScrollRef" class="ai-app-page">
        <div class="ai-app-page__background" :style="backgroundStyle"></div>
        <div class="ai-app-page__noise"></div>
        <div class="ai-app-page__stars ai-app-page__stars--near"></div>
        <div class="ai-app-page__stars ai-app-page__stars--far"></div>

        <AiWorkspaceChrome
            :active-sidebar="activeSidebar"
            :remaining-credits="remainingCredits"
            :membership-enabled="membershipEnabled"
            :active-popover="activePopover"
            :popover-content="chromePopoverContent"
            @toggle-popover="togglePopover"
            @increment-credits="refreshCredits"
            @toggle-membership="refreshCredits"
            @go-home="goHome"
            @navigate="activateSidebar"
        />

        <main class="app-main">
            <section class="hero-panel">
                <div class="hero-panel__heading">
                    <h1>{{ heroTitle }}</h1>
                    <p>{{ heroSubtitle }}</p>
                </div>

                <div v-if="quickTags.length" class="hero-panel__tags">
                    <button
                        v-for="tag in quickTags"
                        :key="tag"
                        :class="['hero-tag', { 'is-active': prompt.includes(tag) }]"
                        type="button"
                        @click="appendTag(tag)"
                    >
                        {{ tag }}
                    </button>
                </div>

                <div ref="promptCardRef" class="hero-composer">
                    <AiCreateComposer
                        v-model="prompt"
                        v-model:mode="generationMode"
                        :option-state="optionState"
                        :config-options="configOptions"
                        :option-values="optionValues"
                        :uploaded-assets="uploadedAssets"
                        :placeholder="currentPlaceholder"
                        :can-generate="canGenerate"
                        :can-submit="canSubmit"
                        :uploading="uploading"
                        :unit-price-label="unitPriceLabel"
                        menu-placement="bottom"
                        @upload="triggerUpload"
                        @paste-images="addUploadedFiles"
                        @remove-asset="removeUploadedAsset"
                        @update:option-state="setComposerOption"
                        @submit="submitPromptLock"
                    />
                </div>
            </section>

            <section ref="inspirationBoardRef" class="inspiration-board">
                <div class="inspiration-board__toolbar">
                    <div class="inspiration-tabs">
                        <button
                            v-for="tab in inspirationTabs"
                            :key="tab.key"
                            :class="['inspiration-tabs__item', { 'is-active': activeInspirationTab === tab.key }]"
                            type="button"
                            @click="activeInspirationTab = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <label class="inspiration-search">
                        <span class="inspiration-search__icon" aria-hidden="true"></span>
                        <input v-model.trim="inspirationQuery" type="text" :placeholder="activeInspirationPlaceholder" />
                    </label>
                </div>

                <div class="inspiration-board__scroller">
                    <div v-if="inspirationColumns.some((column) => column.length)" class="inspiration-grid" :style="inspirationGridStyle">
                        <div
                            v-for="(column, columnIndex) in inspirationColumns"
                            :key="`inspiration-column-${columnIndex}`"
                            class="inspiration-grid__column"
                        >
                            <article
                                v-for="item in column"
                                :key="item.uniqueId"
                                :class="[
                                    'inspiration-card',
                                    {
                                        'is-selected': selectedCardKey === item.uniqueId,
                                        'is-video': item.category === 'video',
                                        'is-ratio-card': Boolean(item.aspectRatio),
                                        'is-video-ready': item.videoReady
                                    }
                                ]"
                                :style="getInspirationCardStyle(item)"
                                @mouseenter="playInspirationVideo"
                                @mouseleave="pauseInspirationVideo"
                                @click="openWorkDetail(item)"
                            >
                                <video
                                    v-if="item.category === 'video' && item.mediaUrl"
                                    class="inspiration-card__video"
                                    :src="item.mediaUrl"
                                    :poster="item.image || undefined"
                                    muted
                                    loop
                                    playsinline
                                    preload="metadata"
                                    @loadedmetadata="primeInspirationVideoFrame"
                                    @loadeddata="markInspirationVideoReady(item)"
                                    @canplay="markInspirationVideoReady(item)"
                                ></video>
                                <img v-else-if="item.image" :src="item.image" :alt="item.title" @error="handleCaseImageError(item)" />
                                <span v-else class="inspiration-card__placeholder" aria-hidden="true"></span>
                                <div class="inspiration-card__overlay"></div>
                                <div class="inspiration-card__info">
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ getCaseTypeLabel(item) }}</span>
                                </div>
                                <button class="inspiration-card__action" type="button" @click.stop="copyCardPrompt(item)">
                                    一键同款
                                </button>
                            </article>
                        </div>
                    </div>
                    <div v-else class="inspiration-empty">
                        <strong>暂无灵感作品</strong>
                        <span>换个关键词，或者稍后再来看看。</span>
                    </div>
                </div>
            </section>
        </main>

        <Teleport to="body">
            <transition name="detail-fade">
                <div v-if="detailOpen && activeDetailCard" class="work-detail" @click.self="closeWorkDetail">
                    <div class="work-detail__panel">
                        <div class="work-detail__media">
                            <button class="work-detail__close" type="button" aria-label="关闭" @click="closeWorkDetail">
                                <img :src="closeSmallIcon" alt="" />
                            </button>
                            <div class="work-detail__media-frame">
                                <video
                                    v-if="activeDetailCard.category === 'video' && activeDetailCard.mediaUrl"
                                    ref="detailVideoRef"
                                    :src="activeDetailCard.mediaUrl"
                                    :poster="activeDetailCard.image || undefined"
                                    autoplay
                                    controls
                                    muted
                                    playsinline
                                    @loadedmetadata="playDetailVideo"
                                    @canplay="playDetailVideo"
                                ></video>
                                <img
                                    v-else-if="activeDetailCard.image"
                                    :src="activeDetailCard.image"
                                    :alt="activeDetailCard.title"
                                    @error="handleCaseImageError(activeDetailCard)"
                                />
                                <span v-else class="work-detail__placeholder" aria-hidden="true"></span>
                            </div>
                        </div>

                        <div class="work-detail__content">
                            <div class="work-detail__header">
                                <div class="work-detail__author-row">
                                    <div class="work-detail__author-group">
                                        <div class="work-detail__author">
                                            <span class="work-detail__avatar">
                                                <img :src="displayAvatarUrl" :alt="activeDetailAuthor" />
                                            </span>
                                            <div class="work-detail__author-meta">
                                                <strong>{{ activeDetailAuthor }}</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="work-detail__social">
                                        <a
                                            v-if="activeDetailDownloadHref"
                                            :href="activeDetailDownloadHref"
                                            :download="activeDetailDownloadName"
                                            class="work-detail__download-link"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label="下载作品"
                                            @click.stop
                                        >
                                            <img :src="downloadIcon" alt="" />
                                        </a>
                                    </div>
                                </div>

                                <div class="work-detail__subline">
                                    <span>{{ activeDetailDate }}</span>
                                    <span>内容由 AI 生成</span>
                                </div>
                            </div>

                            <div class="work-detail__prompt">
                                <div class="work-detail__prompt-head">
                                    <span>{{ activeDetailPromptTitle }}</span>
                                </div>
                                <div class="work-detail__prompt-body">
                                    <p>{{ activeDetailPromptText }}</p>
                                </div>
                                <div v-if="activeDetailConfigFields.length" class="work-detail__config">
                                    <span v-for="field in activeDetailConfigFields" :key="field" class="work-detail__config-text">
                                        {{ field }}
                                    </span>
                                </div>
                            </div>

                            <div class="work-detail__footer">
                                <button class="work-detail__ghost" type="button" @click="applyDetailPrompt">做同款</button>
                                <button class="work-detail__primary" type="button" @click="applyDetailReference">用作参考图</button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <transition name="floating-prompt-fade">
            <div v-if="showFloatingComposer && !detailOpen" class="floating-prompt">
                <AiCreateComposer
                    ref="floatingComposerRef"
                    v-model="prompt"
                    v-model:mode="generationMode"
                    :option-state="optionState"
                    :config-options="configOptions"
                    :option-values="optionValues"
                    :uploaded-assets="uploadedAssets"
                    :placeholder="currentPlaceholder"
                    :can-generate="canGenerate"
                    :can-submit="canSubmit"
                    :uploading="uploading"
                    :unit-price-label="unitPriceLabel"
                    collapsed
                    menu-placement="top"
                    @upload="triggerUpload"
                    @paste-images="addUploadedFiles"
                    @remove-asset="removeUploadedAsset"
                    @update:option-state="setComposerOption"
                    @submit="submitPromptLock"
                />
            </div>
        </transition>

        <div
            v-if="pageScrollThumbVisible && !detailOpen"
            class="page-scroll-thumb"
            :class="{ 'is-dragging': pageScrollThumbDragging }"
            :style="pageScrollThumbStyle"
            @pointerdown="handlePageScrollThumbPointerDown"
        ></div>

        <input ref="fileInputRef" type="file" class="sr-only" accept="image/*" multiple @change="handleUpload" />
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import type {
    AiCreateDraft,
    AiCreateOptionKey,
    AiCreateOptionState,
    AiCreateOptionValues,
    AiGenerationMode
} from '~/composables/useAiCreateWorks'
import {
    createAiCreateOptionState,
    useAiCreateWorks
} from '~/composables/useAiCreateWorks'
import { getAigcImageCases, getAigcImageConfig, generateAigcImage } from '@/apps/aigc_image/api'
import { getAigcVideoCases, getAigcVideoConfig, generateAigcVideo } from '@/apps/aigc_video/api'
import { getAigcDigitalHumanCases } from '@/apps/aigc_digital_human/api'
import { uploadImage } from '@/api/app'
import { extractListData } from '@/utils/pc-adapters'
import { normalizeFileUrl } from '@/utils/file-url'
import { getPcDownloadExtension, resolvePcDownloadUrl } from '@/utils/download'
import { getApiUrl } from '@/utils/env'
import feedback from '@/utils/feedback'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import { useAiUserDisplay } from '~/composables/useAiUserDisplay'
import closeSmallIcon from '@/assets/images/icon/Close-small.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'

type GenerationMode = AiGenerationMode
type FeedTabKey = 'all' | 'image' | 'video'
type OptionKey = AiCreateOptionKey
type PopoverKey = '' | 'share' | 'api' | 'notice'
const imageExtensionPattern = /\.(avif|bmp|gif|jpe?g|png|svg|webp)(\?.*)?(#.*)?$/i
const videoExtensionPattern = /\.(mp4|webm|ogg|mov|m4v)(\?.*)?(#.*)?$/i
const assetPathPattern = /^(https?:\/\/|data:image\/|blob:|\/|uploads\/|storage\/|static\/|public\/)/i

type ConfigOption = {
    key: OptionKey
    value: string
}

interface RatioOption {
    label: string
    value: string
    tenant_unit_price?: string | number
    width?: number
    height?: number
}

interface QualityOption {
    label: string
    value: string
    ratios: RatioOption[]
    resolution?: string
    duration?: string
}

interface ChannelOption {
    label: string
    value: string
    max_reference_images?: number
    duration_options?: number[]
    videoedit_duration_options?: number[]
    qualities: QualityOption[]
}

interface CaseGenerationOptions {
    model?: string
    channel?: string
    count?: string
    ratio?: string
    resolution?: string
    duration?: string
    quality?: string
}

interface UploadedAsset {
    id: string
    name: string
    url: string
    uri?: string
    isObjectUrl: boolean
    uploading?: boolean
}

interface CardItem {
    id: number
    uniqueId: string
    title: string
    category: Exclude<FeedTabKey, 'all'>
    appCode: 'aigc_image' | 'aigc_video' | 'aigc_digital_human'
    image: string
    mediaUrl?: string
    imageHeight: number
    aspectRatio?: string
    videoReady?: boolean
    prompt?: string
    configFields?: string[]
    generationOptions?: CaseGenerationOptions
    referenceAssetUrl?: string
    referenceAssetName?: string
    hasReferenceAsset?: boolean
    authorName?: string
    createdAt?: number
}

const router = useRouter()
const route = useRoute()
const { ensurePcLogin } = usePcLoginGate()
const userStore = useUserStore()
const { displayAvatarUrl, displayNickname } = useAiUserDisplay()
const { setDraft } = useAiCreateWorks()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()

const fileInputRef = ref<HTMLInputElement | null>(null)
const pageScrollRef = ref<HTMLElement | null>(null)
const promptCardRef = ref<HTMLElement | null>(null)
const inspirationBoardRef = ref<HTMLElement | null>(null)
const floatingComposerRef = ref<{ collapseIfEmpty: () => void; focusTextarea: () => Promise<void> } | null>(null)
const detailVideoRef = ref<HTMLVideoElement | null>(null)

const prompt = ref('')
const generationMode = ref<GenerationMode>('image')
const optionState = ref<AiCreateOptionState>(createAiCreateOptionState())
const uploadedAssets = ref<UploadedAsset[]>([])
const activeSidebar = ref<SidebarKey>('inspiration')
const activePopover = ref<PopoverKey>('')
const activeInspirationTab = ref<FeedTabKey>('all')
const inspirationQuery = ref('')
const inspirationColumnCount = ref(6)
const selectedCardKey = ref('')
const caseCards = ref<CardItem[]>([])
const detailOpen = ref(false)
const activeDetailCard = ref<CardItem | null>(null)
const showFloatingComposer = ref(false)
const submitting = ref(false)
const uploading = ref(false)
const pageScrollThumbTop = ref(0)
const pageScrollThumbHeight = ref(64)
const pageScrollThumbVisible = ref(false)
const pageScrollThumbDragging = ref(false)
const pageScrollThumbPointerOffset = ref(0)

const quickTags = ['电影感', '写实质感', '高级光影', '细节丰富']
const cardAuthorPool = ['清澈灵感', '南汐', '可可', '安安', '林琼', '朝野', '浮光', '念初']
const cardModelPool = ['AIGC 图片生成', 'Seed 2.0 Pro', '写实人像引擎', '叙事镜头引擎']
const inspirationTabs: Array<{ key: FeedTabKey; label: string }> = [
    { key: 'all', label: '全部' },
    { key: 'image', label: '图片' },
    { key: 'video', label: '视频' }
]

const aigcOptionConfig = ref<any>({
    channels: [],
    defaults: {
        channel: '',
        quality: '1k',
        ratio: '1:1',
        quantity: 1
    }
})
const selectedChannelCode = ref('')
const aigcVideoOptionConfig = ref<any>({
    channels: [],
    defaults: {
        channel: '',
        quality: '5s',
        ratio: '16:9',
        quantity: 1
    },
    quantity_options: [1],
    max_reference_images: 7
})
const selectedVideoChannelCode = ref('')

const chromePopoverContent = computed(() => ({
    share: {
        title: '邀请好友',
        text: '分享创作链接，双方各得 10 张生成额度。'
    },
    api: {
        title: 'API 配额',
        text: `当前体验额度 ${remainingCredits.value} 次，可查看调用模型说明。`
    },
    notice: {
        title: '消息中心',
        text: '你的创作任务完成后会在这里提醒。',
        compact: true
    }
}))

const backgroundStyle = computed(() => ({
    backgroundImage: 'linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%)'
}))
const heroTitle = computed(() => (generationMode.value === 'image' ? '图片生成' : '视频生成'))
const heroSubtitle = computed(() =>
    generationMode.value === 'image' ? '超多模型供你选择' : '一键生成动态镜头与叙事短片'
)
const currentPlaceholder = computed(() =>
    generationMode.value === 'image'
        ? '输入图片生成的提示词，例如：浩瀚的银河中一艘宇宙飞船驶过'
        : '输入视频生成的提示词，例如：镜头缓慢推进，角色在霓虹街道中回眸'
)
const activeInspirationPlaceholder = computed(() => {
    if (activeInspirationTab.value === 'image') return '搜索图片作品'
    if (activeInspirationTab.value === 'video') return '搜索视频作品'
    return '搜索作品'
})
const canGenerate = computed(() => Boolean(prompt.value.trim()) && !submitting.value && !uploading.value)
const canSubmit = computed(() => canGenerate.value)

const channels = computed<ChannelOption[]>(() =>
    (aigcOptionConfig.value.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        value: channel.value || channel.code,
        max_reference_images: Number(channel.max_reference_images || aigcOptionConfig.value.max_reference_images || 4),
        qualities: (channel.qualities || []).map((quality: any) => ({
            label: quality.label || quality.quality_label || String(quality.value || quality.quality || '').toUpperCase(),
            value: quality.value || quality.quality,
            ratios: (quality.ratios || []).map((ratio: any) => ({
                ...ratio,
                label: ratio.label || ratio.ratio || ratio.value,
                value: ratio.value || ratio.ratio
            }))
        }))
    }))
)
const currentChannel = computed(() => channels.value.find((item) => item.value === selectedChannelCode.value) || channels.value[0])
const qualities = computed<QualityOption[]>(() => currentChannel.value?.qualities || [])
const currentQuality = computed(() => qualities.value.find((item) => item.value === optionState.value.resolution) || qualities.value[0])
const ratios = computed<RatioOption[]>(() => currentQuality.value?.ratios || [])
const currentRatio = computed(() => ratios.value.find((item) => item.value === optionState.value.ratio) || ratios.value[0])
const selectedUnitPrice = computed(() => Number(currentRatio.value?.tenant_unit_price || 0).toString())

const normalizeVideoResolution = (value: unknown) => {
    const raw = String(value || '').trim().toUpperCase()
    const matched = raw.match(/1080P|720P|4K|2K|1K|\d+K/)
    return matched?.[0] || '默认'
}
const normalizeVideoDuration = (value: unknown) => {
    const raw = String(value || '').trim()
    const matched = raw.match(/(\d+)(?:\s*S|秒)?/i)
    return matched ? `${Number(matched[1])}秒` : raw || '默认'
}
const normalizeNumberOptions = (options: any[]) =>
    Array.from(new Set((options || []).map((item: any) => Number(item)).filter(Boolean))).sort((a, b) => a - b)
const durationLabel = (value: unknown) => {
    const duration = Number(value)
    return duration > 0 ? `${duration}秒` : ''
}
const durationValue = (value: unknown) => Number.parseInt(String(value || ''), 10) || 0
const getVideoQualityResolution = (quality: any) =>
    normalizeVideoResolution(quality.resolution || quality.provider_params_json?.resolution || quality.label || quality.quality_label || quality.value || quality.quality)
const getVideoQualityDuration = (quality: any) =>
    normalizeVideoDuration(quality.duration || quality.provider_params_json?.duration || quality.label || quality.quality_label || quality.value || quality.quality)

const videoChannels = computed<ChannelOption[]>(() =>
    (aigcVideoOptionConfig.value.channels || []).map((channel: any) => {
        const durationOptions = normalizeNumberOptions(channel.duration_options || [])
        const dynamicDuration = durationOptions.length > 0
        return {
            label: channel.label || channel.name || channel.code,
            value: channel.value || channel.code,
            max_reference_images: Number(channel.max_reference_images || aigcVideoOptionConfig.value.max_reference_images || 7),
            duration_options: durationOptions,
            videoedit_duration_options: normalizeNumberOptions(channel.videoedit_duration_options || []),
            qualities: (channel.qualities || []).map((quality: any) => ({
                label: dynamicDuration ? getVideoQualityResolution(quality) : getVideoQualityDuration(quality),
                value: String(quality.value || quality.quality),
                resolution: getVideoQualityResolution(quality),
                duration: dynamicDuration ? '' : getVideoQualityDuration(quality),
                ratios: (quality.ratios || []).map((ratio: any) => ({
                    ...ratio,
                    label: ratio.label || ratio.ratio || ratio.value,
                    value: ratio.value || ratio.ratio
                }))
            }))
        }
    })
)
const currentVideoChannel = computed(() => videoChannels.value.find((item) => item.value === selectedVideoChannelCode.value) || videoChannels.value[0])
const currentVideoChannelHasDynamicDuration = computed(() => Boolean(currentVideoChannel.value?.duration_options?.length))
const videoQualities = computed<QualityOption[]>(() => currentVideoChannel.value?.qualities || [])
const videoResolutions = computed(() => Array.from(new Set(videoQualities.value.map((item) => item.resolution || '默认'))))
const videoHasResolutionOptions = computed(() => videoResolutions.value.some((item) => item !== '默认'))
const videoQualitiesByResolution = computed(() =>
    videoQualities.value.filter((item) => String(item.resolution || '默认') === String(optionState.value.resolution || videoResolutions.value[0] || '默认'))
)
const videoDurations = computed(() => {
    if (currentVideoChannelHasDynamicDuration.value) {
        return normalizeNumberOptions(currentVideoChannel.value?.duration_options || []).map((item) => durationLabel(item)).filter(Boolean)
    }
    return Array.from(new Set(videoQualitiesByResolution.value.map((item) => item.duration || item.label || item.value)))
})
const currentVideoQuality = computed(() =>
    (currentVideoChannelHasDynamicDuration.value
        ? videoQualitiesByResolution.value[0]
        : videoQualitiesByResolution.value.find((item) => String(item.duration || item.label || item.value) === String(optionState.value.duration))) ||
    videoQualitiesByResolution.value[0] ||
    videoQualities.value[0]
)
const videoRatios = computed<RatioOption[]>(() => currentVideoQuality.value?.ratios || [])
const currentVideoRatio = computed(() => videoRatios.value.find((item) => item.value === optionState.value.ratio) || videoRatios.value[0])
const selectedVideoUnitPrice = computed(() => Number(currentVideoRatio.value?.tenant_unit_price || 0).toString())
const findVideoQualityByValue = (value: unknown) => videoQualities.value.find((item) => String(item.value) === String(value))
const getVideoDisplayResolution = (value: unknown) => {
    const quality = findVideoQualityByValue(value)
    const resolution = quality?.resolution || normalizeVideoResolution(value)
    return resolution === '默认' ? '' : resolution
}
const getVideoDisplayDuration = (value: unknown) => {
    const quality = findVideoQualityByValue(value)
    return quality?.duration || normalizeVideoDuration(value)
}

const selectedQuantity = computed(() => {
    const parsed = Number.parseInt(optionState.value.count, 10)
    return Number.isFinite(parsed) && parsed > 0 ? Math.min(parsed, 4) : 1
})
const maxReferenceCount = computed(() => generationMode.value === 'video'
    ? Number(currentVideoChannel.value?.max_reference_images || aigcVideoOptionConfig.value.max_reference_images || 7)
    : Number(currentChannel.value?.max_reference_images || aigcOptionConfig.value.max_reference_images || 4)
)
const optionValues = computed<AiCreateOptionValues>(() => ({
    model: generationMode.value === 'image' ? channels.value.map((item) => item.label) : videoChannels.value.map((item) => item.label),
    count: generationMode.value === 'image' ? ['1张', '2张', '3张', '4张'] : ['1条'],
    ratio: generationMode.value === 'image' ? ratios.value.map((item) => item.value) : videoRatios.value.map((item) => item.value),
    resolution: generationMode.value === 'image' ? qualities.value.map((item) => item.value) : videoResolutions.value,
    duration: generationMode.value === 'image' ? [] : videoDurations.value,
    quality: []
}))
const configOptions = computed<ConfigOption[]>(() =>
    generationMode.value === 'video'
        ? [
            { key: 'model', value: currentVideoChannel.value?.label || '视频模型' },
            ...(videoHasResolutionOptions.value ? [{ key: 'resolution' as OptionKey, value: optionState.value.resolution || '分辨率' }] : []),
            { key: 'ratio', value: optionState.value.ratio || '比例' },
            { key: 'duration', value: optionState.value.duration || '时长' }
        ]
        : [
            { key: 'model', value: currentChannel.value?.label || '图片模型' },
            { key: 'count', value: optionState.value.count || '1张' },
            { key: 'ratio', value: optionState.value.ratio || '比例' },
            { key: 'resolution', value: optionState.value.resolution || '清晰度' }
        ]
)
const unitPriceLabel = computed(() =>
    generationMode.value === 'image' ? `${selectedUnitPrice.value}/张` : `${selectedVideoUnitPrice.value}/次`
)

const filteredCards = computed(() => {
    const keyword = inspirationQuery.value.trim().toLowerCase()
    return caseCards.value.filter((item) => {
        const matchTab = activeInspirationTab.value === 'all' || item.category === activeInspirationTab.value
        if (!matchTab) return false
        if (!keyword) return true
        return [item.title, item.prompt || ''].join(' ').toLowerCase().includes(keyword)
    })
})
const inspirationGridStyle = computed(() => ({ '--inspiration-columns': String(inspirationColumnCount.value) }))
const inspirationColumns = computed(() => {
    const count = Math.max(1, inspirationColumnCount.value)
    const columns: CardItem[][] = Array.from({ length: count }, () => [])
    const heights = Array.from({ length: count }, () => 0)
    filteredCards.value.forEach((item, index) => {
        const targetIndex = index < count ? index : heights.indexOf(Math.min(...heights))
        columns[targetIndex].push(item)
        heights[targetIndex] += getCardWeight(item)
    })
    return columns
})

const activeDetailAuthor = computed(() => {
    if (!activeDetailCard.value) return ''
    return activeDetailCard.value.authorName || cardAuthorPool[(activeDetailCard.value.id - 1) % cardAuthorPool.length] || displayNickname.value
})
const activeDetailDate = computed(() => {
    if (!activeDetailCard.value) return ''
    if (activeDetailCard.value.createdAt) {
        return new Date(activeDetailCard.value.createdAt * 1000).toLocaleDateString('zh-CN', {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit'
        }).replaceAll('/', '-')
    }
    const day = String(((activeDetailCard.value.id + 2) % 6) + 1).padStart(2, '0')
    return `2026-04-${day}`
})
const activeDetailPromptTitle = computed(() => activeDetailCard.value?.category === 'video' ? '视频提示词' : '图片提示词')
const activeDetailPromptText = computed(() => {
    if (!activeDetailCard.value) return ''
    const promptText = getCardPrompt(activeDetailCard.value)
    return [
        `超近景，${promptText}。`,
        '要求主体清晰、结构自然、色彩干净，强调光影层次与材质细节，画面整体统一且具有高级感。',
        activeDetailCard.value.category === 'video'
            ? '镜头运动保持平稳流畅，节奏克制，前后景关系清晰，适合直接用于内容创作与灵感延展。'
            : '人物或主体边缘过渡自然，背景信息不过度抢占视觉中心，整体构图完整耐看，适合直接用于创作参考。'
    ].join('')
})
const activeDetailConfigFields = computed(() => {
    if (!activeDetailCard.value) return []
    if (activeDetailCard.value.configFields?.length) return activeDetailCard.value.configFields
    const poolIndex = activeDetailCard.value.id - 1
    const model = cardModelPool[poolIndex % cardModelPool.length]
    return activeDetailCard.value.category === 'video'
        ? [model, '16:9', '5s', '720p']
        : [model, '1张', '3:4', '1k']
})
const activeDetailDownloadUrl = computed(() => {
    const item = activeDetailCard.value
    if (!item) return ''
    return item.mediaUrl || item.image || ''
})
const activeDetailDownloadHref = computed(() => (
    getSameOriginUploadUrl(activeDetailDownloadUrl.value) ||
    resolvePcDownloadUrl(activeDetailDownloadUrl.value)
))
const activeDetailDownloadName = computed(() => {
    const item = activeDetailCard.value
    const href = activeDetailDownloadHref.value
    if (!item || !href) return 'download'
    const extension = item.category === 'video'
        ? getPcDownloadExtension(href, 'mp4')
        : getPcDownloadExtension(href, 'png')
    return `${item.title || '作品'}.${extension}`
})
const pageScrollThumbStyle = computed(() => ({
    height: `${pageScrollThumbHeight.value}px`,
    transform: `translateY(${pageScrollThumbTop.value}px)`
}))

const goHome = () => router.push('/')
const togglePopover = (key: Exclude<PopoverKey, ''>) => {
    activePopover.value = activePopover.value === key ? '' : key
}
const activateSidebar = (key: SidebarKey) => {
    activeSidebar.value = key
    if (key === 'inspiration') return
    if ((key === 'create' || key === 'assets') && !ensurePcLogin({ redirect: buildSidebarRouteLocation(key).path || route.fullPath })) {
        activeSidebar.value = 'inspiration'
        return
    }
    if (key === 'create') setDraft(null)
    router.push(buildSidebarRouteLocation(key))
}
const appendTag = (tag: string) => {
    prompt.value = prompt.value ? `${prompt.value}，${tag}` : tag
}

const getAdaptiveInspirationColumns = (width: number) => {
    if (width < 760) return 2
    if (width < 980) return 3
    if (width < 1180) return 4
    if (width < 1420) return 5
    return 6
}
const updateInspirationColumnCount = () => {
    if (typeof window === 'undefined') return
    const width = inspirationBoardRef.value?.getBoundingClientRect().width || window.innerWidth
    inspirationColumnCount.value = getAdaptiveInspirationColumns(width)
}
const updateFloatingComposer = () => {
    if (typeof window === 'undefined' || !promptCardRef.value || !inspirationBoardRef.value) return
    const promptRect = promptCardRef.value.getBoundingClientRect()
    const scrollTop = pageScrollRef.value?.scrollTop ?? 0
    showFloatingComposer.value = promptRect.bottom <= 96 && scrollTop > 180
}
const updatePageScrollThumb = () => {
    if (typeof window === 'undefined' || !pageScrollRef.value) return
    const viewportHeight = pageScrollRef.value.clientHeight
    const scrollHeight = pageScrollRef.value.scrollHeight
    const scrollTop = pageScrollRef.value.scrollTop
    const maxScrollTop = Math.max(scrollHeight - viewportHeight, 0)
    if (maxScrollTop <= 0) {
        pageScrollThumbVisible.value = false
        return
    }
    const thumbHeight = Math.max((viewportHeight * viewportHeight) / scrollHeight, 56)
    const maxThumbOffset = Math.max(viewportHeight - thumbHeight - 8, 0)
    const ratio = scrollTop / maxScrollTop
    pageScrollThumbVisible.value = true
    pageScrollThumbHeight.value = thumbHeight
    pageScrollThumbTop.value = 4 + maxThumbOffset * ratio
}
const syncPageScrollUi = () => {
    updateInspirationColumnCount()
    updateFloatingComposer()
    updatePageScrollThumb()
    floatingComposerRef.value?.collapseIfEmpty()
}
const syncPageScrollFromThumb = (clientY: number) => {
    if (!pageScrollRef.value) return
    const viewportHeight = pageScrollRef.value.clientHeight
    const scrollHeight = pageScrollRef.value.scrollHeight
    const maxScrollTop = Math.max(scrollHeight - viewportHeight, 0)
    const thumbHeight = pageScrollThumbHeight.value
    const maxThumbOffset = Math.max(viewportHeight - thumbHeight - 8, 0)
    if (maxScrollTop <= 0 || maxThumbOffset <= 0) return
    const nextTop = Math.min(Math.max(clientY - pageScrollThumbPointerOffset.value, 4), 4 + maxThumbOffset)
    pageScrollRef.value.scrollTop = ((nextTop - 4) / maxThumbOffset) * maxScrollTop
    syncPageScrollUi()
}
const handlePageScrollThumbPointerMove = (event: PointerEvent) => {
    if (!pageScrollThumbDragging.value) return
    syncPageScrollFromThumb(event.clientY)
}
const stopPageScrollThumbDrag = () => {
    if (typeof window === 'undefined' || !pageScrollThumbDragging.value) return
    pageScrollThumbDragging.value = false
    document.body.style.userSelect = ''
    window.removeEventListener('pointermove', handlePageScrollThumbPointerMove)
    window.removeEventListener('pointerup', stopPageScrollThumbDrag)
    window.removeEventListener('pointercancel', stopPageScrollThumbDrag)
}
const handlePageScrollThumbPointerDown = (event: PointerEvent) => {
    if (typeof window === 'undefined') return
    event.preventDefault()
    pageScrollThumbDragging.value = true
    pageScrollThumbPointerOffset.value = event.clientY - pageScrollThumbTop.value
    document.body.style.userSelect = 'none'
    window.addEventListener('pointermove', handlePageScrollThumbPointerMove)
    window.addEventListener('pointerup', stopPageScrollThumbDrag)
    window.addEventListener('pointercancel', stopPageScrollThumbDrag)
}

const revokeUploadedAsset = (asset: UploadedAsset) => {
    if (asset.isObjectUrl) URL.revokeObjectURL(asset.url)
}
const triggerUpload = () => {
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    fileInputRef.value?.click()
}
const removeUploadedAsset = (id: string) => {
    const target = uploadedAssets.value.find((item) => item.id === id)
    if (!target) return
    revokeUploadedAsset(target)
    uploadedAssets.value = uploadedAssets.value.filter((item) => item.id !== id)
}
const uploadFiles = async (files: File[]) => {
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    const availableCount = maxReferenceCount.value - uploadedAssets.value.length
    if (availableCount <= 0) {
        feedback.msgWarning(`最多上传 ${maxReferenceCount.value} 张参考图`)
        return
    }
    const uploadList = files.slice(0, availableCount)
    if (uploadList.length < files.length) {
        feedback.msgWarning(`最多上传 ${maxReferenceCount.value} 张参考图`)
    }
    uploading.value = true
    const pendingAssets = uploadList.map((file, index) => ({
        file,
        asset: {
            id: `uploading-${Date.now()}-${index}-${Math.random().toString(36).slice(2, 8)}`,
            name: file.name,
            url: URL.createObjectURL(file),
            isObjectUrl: true,
            uploading: true
        } as UploadedAsset
    }))
    uploadedAssets.value = [
        ...uploadedAssets.value,
        ...pendingAssets.map((item) => item.asset)
    ]
    try {
        for (const { file, asset: placeholderAsset } of pendingAssets) {
            try {
                const res: any = await uploadImage({ file })
                const uri = res?.uri || res?.url || res?.path
                if (!uri) throw new Error('参考图上传失败')
                uploadedAssets.value = uploadedAssets.value.map((asset) => (
                    asset.id === placeholderAsset.id
                        ? { ...asset, uri, uploading: false }
                        : asset
                ))
            } catch (error) {
                URL.revokeObjectURL(placeholderAsset.url)
                uploadedAssets.value = uploadedAssets.value.filter((asset) => asset.id !== placeholderAsset.id)
                throw error
            }
        }
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图上传失败')
    } finally {
        uploading.value = false
    }
}
const handleUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    const files = Array.from(target.files || [])
    if (files.length) await uploadFiles(files)
    target.value = ''
}
const addUploadedFiles = async (files: File[]) => {
    await uploadFiles(files)
}
const replaceUploadedAssets = (assets: UploadedAsset[]) => {
    uploadedAssets.value.forEach(revokeUploadedAsset)
    uploadedAssets.value = assets
}

const syncAigcSelection = () => {
    if (!channels.value.length) return
    if (!channels.value.some((item) => item.value === selectedChannelCode.value)) {
        selectedChannelCode.value = aigcOptionConfig.value.defaults?.channel || channels.value[0].value
    }
    if (!qualities.value.some((item) => item.value === optionState.value.resolution)) {
        optionState.value.resolution = aigcOptionConfig.value.defaults?.quality || qualities.value[0]?.value || optionState.value.resolution
    }
    if (!ratios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = aigcOptionConfig.value.defaults?.ratio || ratios.value[0]?.value || optionState.value.ratio
    }
    optionState.value.model = currentChannel.value?.label || optionState.value.model
    optionState.value.count = `${selectedQuantity.value}张`
}
const syncAigcVideoSelection = () => {
    if (!videoChannels.value.length) return
    if (!videoChannels.value.some((item) => item.value === selectedVideoChannelCode.value)) {
        selectedVideoChannelCode.value = aigcVideoOptionConfig.value.defaults?.channel || videoChannels.value[0].value
    }
    if (!videoDurations.value.includes(optionState.value.duration)) {
        const defaultQuality = videoQualities.value.find((item) => item.value === aigcVideoOptionConfig.value.defaults?.quality)
        optionState.value.resolution = defaultQuality?.resolution || videoResolutions.value[0] || optionState.value.resolution
        optionState.value.duration = durationLabel(aigcVideoOptionConfig.value.defaults?.duration) || defaultQuality?.duration || videoDurations.value[0] || optionState.value.duration
    }
    if (!videoResolutions.value.includes(optionState.value.resolution)) {
        optionState.value.resolution = videoResolutions.value[0] || optionState.value.resolution
    }
    if (!videoDurations.value.includes(optionState.value.duration)) {
        optionState.value.duration = videoDurations.value[0] || optionState.value.duration
    }
    if (!videoRatios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = aigcVideoOptionConfig.value.defaults?.ratio || videoRatios.value[0]?.value || optionState.value.ratio
    }
    optionState.value.model = currentVideoChannel.value?.label || optionState.value.model
    optionState.value.quality = currentVideoQuality.value?.value || optionState.value.quality
    optionState.value.count = '1条'
}
const loadAigcConfig = async () => {
    try {
        const config: any = await getAigcImageConfig()
        aigcOptionConfig.value = config?.option_config || config || aigcOptionConfig.value
        const defaults = aigcOptionConfig.value.defaults || {}
        selectedChannelCode.value = defaults.channel || selectedChannelCode.value
        optionState.value.resolution = defaults.quality || optionState.value.resolution
        optionState.value.ratio = defaults.ratio || optionState.value.ratio
        optionState.value.count = `${Number(defaults.quantity || 1)}张`
        syncAigcSelection()
    } catch (error) {
        console.warn('load aigc image config failed', error)
    }
}
const loadAigcVideoConfig = async () => {
    try {
        const config: any = await getAigcVideoConfig()
        aigcVideoOptionConfig.value = config?.option_config || config || aigcVideoOptionConfig.value
        const defaults = aigcVideoOptionConfig.value.defaults || {}
        selectedVideoChannelCode.value = defaults.channel || selectedVideoChannelCode.value
        const defaultQuality = videoQualities.value.find((item) => item.value === defaults.quality)
        optionState.value.resolution = defaultQuality?.resolution || optionState.value.resolution
        optionState.value.duration = durationLabel(defaults.duration) || defaultQuality?.duration || optionState.value.duration
        optionState.value.ratio = defaults.ratio || optionState.value.ratio
        syncAigcVideoSelection()
    } catch (error) {
        console.warn('load aigc video config failed', error)
    }
}

const setComposerOption = (nextState: AiCreateOptionState) => {
    const changedKey = (Object.keys(nextState) as OptionKey[]).find((key) => nextState[key] !== optionState.value[key])
    optionState.value = nextState
    if (!changedKey) return
    if (generationMode.value === 'image') {
        if (changedKey === 'model') {
            const nextChannel = channels.value.find((item) => item.label === nextState.model || item.value === nextState.model)
            if (nextChannel) selectedChannelCode.value = nextChannel.value
        }
        if (changedKey === 'resolution') {
            optionState.value.ratio = qualities.value.find((item) => item.value === nextState.resolution)?.ratios?.[0]?.value || optionState.value.ratio
        }
        syncAigcSelection()
        return
    }
    if (changedKey === 'model') {
        const nextChannel = videoChannels.value.find((item) => item.label === nextState.model || item.value === nextState.model)
        if (nextChannel) selectedVideoChannelCode.value = nextChannel.value
    }
    if (changedKey === 'resolution') {
        optionState.value.duration = videoDurations.value[0] || optionState.value.duration
        optionState.value.ratio = videoQualitiesByResolution.value[0]?.ratios?.[0]?.value || optionState.value.ratio
    }
    if (changedKey === 'duration' && !currentVideoChannelHasDynamicDuration.value) {
        optionState.value.ratio = videoQualitiesByResolution.value.find((item) => (item.duration || item.label || item.value) === nextState.duration)?.ratios?.[0]?.value || optionState.value.ratio
    }
    syncAigcVideoSelection()
}

const normalizeCaseConfigFields = (value: any) => {
    if (Array.isArray(value)) return value.map((item) => String(item || '').trim()).filter(Boolean)
    if (typeof value === 'string') {
        const trimmed = value.trim()
        if (!trimmed) return []
        if (trimmed.startsWith('[')) {
            try {
                const parsed = JSON.parse(trimmed)
                if (Array.isArray(parsed)) return normalizeCaseConfigFields(parsed)
            } catch {
                // fall through to split parsing
            }
        }
        return trimmed
            .split(/[|,，、\n\r]+/)
            .map((item) => item.trim())
            .filter(Boolean)
    }
    return []
}

const getCaseGenerationOptions = (item: any, mediaType: Exclude<FeedTabKey, 'all'>): CaseGenerationOptions => {
    const configFields = normalizeCaseConfigFields(item.config_fields || item.configFields)
    const ratio = String(item.ratio || item.aspect_ratio || item.size || configFields.find((field) => /^\d+(\.\d+)?\s*[:/：]\s*\d+(\.\d+)?$/.test(field)) || '').replace('/', ':').replace('：', ':')
    const quantity = Number(item.quantity || item.count || item.num || 0)
    const countField = configFields.find((field) => /\d+\s*(张|条)/.test(field))
    const qualityField = configFields.find((field) => /(\d+k|高清|标清|720p|1080p)/i.test(field))
    const durationField = configFields.find((field) => /\d+\s*(s|秒)/i.test(field))
    const modelField = configFields.find((field) => (
        field &&
        field !== ratio &&
        field !== countField &&
        field !== qualityField &&
        field !== durationField
    ))
    const rawModel = String(item.channel || item.channel_name || item.model || item.model_name || modelField || '').trim()
    return {
        model: rawModel,
        channel: String(item.channel || item.channel_code || item.channel_value || '').trim(),
        count: mediaType === 'video'
            ? `${Number(quantity || 1)}条`
            : (countField || `${Number(quantity || 1)}张`),
        ratio,
        resolution: String(item.quality || item.resolution || item.size_label || (mediaType === 'image' ? qualityField : '') || '').trim(),
        duration: String(item.duration || (mediaType === 'video' ? item.quality : '') || durationField || '').trim(),
        quality: String(item.video_quality || item.definition || (mediaType === 'video' ? qualityField : '') || '').trim()
    }
}

const normalizeCaseCard = (item: any, index: number): CardItem | null => {
    const mediaType = getCaseMediaType(item)
    const rawAppCode = String(item.app_code || (mediaType === 'video' ? 'aigc_video' : 'aigc_image'))
    const appCode: CardItem['appCode'] = rawAppCode === 'aigc_video'
        ? 'aigc_video'
        : rawAppCode === 'aigc_digital_human'
          ? 'aigc_digital_human'
          : 'aigc_image'
    const mediaUrl = getCaseMediaUrl(item)
    const image = resolveCaseImage(item)
    if (!image && !mediaUrl) return null
    return {
        id: Number(item.id || index + 1),
        uniqueId: `${appCode}-${item.id || index}`,
        title: item.title || `作品灵感 ${index + 1}`,
        category: mediaType,
        appCode,
        image,
        mediaUrl,
        imageHeight: getCaseCardHeight(item, index),
        aspectRatio: getCaseAspectRatio(item),
        prompt: item.prompt || item.desc || item.description || '',
        configFields: normalizeCaseConfigFields(item.config_fields || item.configFields),
        generationOptions: getCaseGenerationOptions(item, mediaType),
        referenceAssetUrl: resolveCaseReferenceAssetUrl(item, image),
        referenceAssetName: item.title || '案例参考图',
        hasReferenceAsset: hasCaseReferenceAsset(item),
        authorName: item.author_name || item.author || '',
        createdAt: Number(item.create_time || 0) || undefined
    }
}
const refreshCaseCards = async () => {
    try {
        const [imageCases, videoCases, digitalHumanCases] = await Promise.all([
            getAigcImageCases({ limit: 60, media_type: 'image' }, { withToken: false }).catch(() => []),
            getAigcVideoCases({ limit: 60, media_type: 'video' }, { withToken: false }).catch(() => []),
            getAigcDigitalHumanCases({ limit: 60, media_type: 'video' }, { withToken: false }).catch(() => [])
        ])
        const list = [
            ...extractListData(imageCases).map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_image' })),
            ...extractListData(videoCases).map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_video' })),
            ...extractListData(digitalHumanCases).map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_digital_human' }))
        ]
        caseCards.value = list
            .map(normalizeCaseCard)
            .filter((item): item is CardItem => Boolean(item))
        if (caseCards.value.length && !caseCards.value.some((item) => item.uniqueId === selectedCardKey.value)) {
            selectedCardKey.value = caseCards.value[0].uniqueId
        }
    } catch (error) {
        console.warn('refresh aigc cases failed', error)
    }
}
const getCaseCardHeight = (item: any, index: number) => {
    const ratio = String(item.ratio || item.aspect_ratio || item.size || '').trim()
    const appCode = String(item.app_code || '')
    const mediaType = getCaseMediaType(item)
    if (ratio === '16:9' || ratio === '4:3') return 226
    if (ratio === '9:16' || appCode === 'aigc_digital_human') return 430
    if (ratio === '1:1') return 310
    if (mediaType === 'video') return 340
    const heights = [420, 286, 356, 500, 318, 388, 452, 332]
    return heights[index % heights.length]
}
const getCaseAspectRatio = (item: any) => {
    const ratio = String(item.ratio || item.aspect_ratio || item.size || '').trim()
    if (/^\d+(\.\d+)?:\d+(\.\d+)?$/.test(ratio)) return ratio.replace(':', ' / ')
    if (/^\d+(\.\d+)?\s*\/\s*\d+(\.\d+)?$/.test(ratio)) return ratio
    return ''
}

const hasAssetUrlShape = (value: string) => {
    const normalized = value.trim().replace(/\\/g, '/')
    return assetPathPattern.test(normalized) || imageExtensionPattern.test(normalized) || videoExtensionPattern.test(normalized)
}

const extractCaseAssetUrl = (value: any): string => {
    if (value == null) return ''
    if (Array.isArray(value)) {
        for (const item of value) {
            const url = extractCaseAssetUrl(item)
            if (url) return url
        }
        return ''
    }
    if (typeof value === 'object') {
        return extractCaseAssetUrl(
            value.url ||
                value.uri ||
                value.path ||
                value.src ||
                value.href ||
                value.cover ||
                value.image ||
                value.thumbnail ||
                value.thumb ||
                value.poster
        )
    }

    const raw = String(value).trim()
    if (!raw) return ''

    if (raw.startsWith('{') || raw.startsWith('[')) {
        try {
            return extractCaseAssetUrl(JSON.parse(raw))
        } catch {
            return ''
        }
    }

    const normalized = raw.replace(/\\/g, '/')
    if (hasAssetUrlShape(normalized)) return normalized

    const candidates = normalized
        .split(/[\s,，]+/)
        .map((item) => item.trim().replace(/^["']|["']$/g, ''))
        .filter(Boolean)
    return candidates.find(hasAssetUrlShape) || ''
}

const normalizeCaseAssetUrl = (url: any) => {
    const assetUrl = extractCaseAssetUrl(url)
    if (!assetUrl) return ''
    if (/^https?:\/\//i.test(assetUrl) || assetUrl.startsWith('data:image/') || assetUrl.startsWith('blob:')) {
        return assetUrl
    }
    const normalized = normalizeFileUrl(assetUrl)
    if (/^\/uploads\//i.test(normalized)) {
        const apiUrl = String(getApiUrl() || '').replace(/\/+$/, '')
        return apiUrl ? `${apiUrl}${normalized}` : normalized
    }
    return normalized
}

const normalizeCaseFetchUrl = (url: any) => {
    const normalized = normalizeCaseAssetUrl(url)
    if (!normalized) return ''
    return resolvePcDownloadUrl(normalized) || normalized
}

const getSameOriginUploadUrl = (url: any) => {
    const assetUrl = extractCaseAssetUrl(url)
    if (!assetUrl) return ''
    const normalized = assetUrl.trim().replace(/\\/g, '/')
    if (!normalized) return ''

    if (/^https?:\/\//i.test(normalized) || /^\/\//.test(normalized)) {
        try {
            const baseUrl = typeof window !== 'undefined' && window.location?.origin
                ? window.location.origin
                : 'https://aigc.likeadmin.cn'
            const parsed = new URL(
                normalized,
                baseUrl
            )
            if (/^\/uploads\//i.test(parsed.pathname)) {
                return `${parsed.pathname}${parsed.search || ''}${parsed.hash || ''}`
            }
        } catch {
            return ''
        }
    }

    const fileUrl = normalizeFileUrl(normalized)
    return /^\/uploads\//i.test(fileUrl) ? fileUrl : ''
}

const getCaseFetchUrlCandidates = (url: any) => {
    const candidates = [
        getSameOriginUploadUrl(url),
        normalizeCaseFetchUrl(url)
    ].filter(Boolean)
    return Array.from(new Set(candidates))
}

const getReferenceAssetUri = (url: any) => {
    const sameOriginUrl = getSameOriginUploadUrl(url)
    if (!sameOriginUrl) return ''
    return sameOriginUrl.split('#')[0].split('?')[0]
}

const getFirstCaseUrl = (...values: any[]) => {
    for (const value of values) {
        const url = normalizeCaseAssetUrl(value)
        if (url) return url
    }
    return ''
}

const isVideoUrl = (url: string) => videoExtensionPattern.test(String(url || ''))

const getRawCaseMediaUrl = (item: any) => getFirstCaseUrl(
    item.media_url,
    item.media_uri,
    item.media_path,
    item.video_url,
    item.video,
    item.file_url,
    item.url,
    item.path
)

const getRawCaseCoverUrl = (item: any) => getFirstCaseUrl(
    item.cover_url,
    item.cover_uri,
    item.cover_path,
    item.cover,
    item.poster,
    item.poster_url,
    item.image_url,
    item.image,
    item.thumbnail,
    item.thumb
)

const getCaseMediaType = (item: any): Exclude<FeedTabKey, 'all'> => {
    const explicitType = String(item.media_type || item.type || item.category || '').toLowerCase()
    const appCode = String(item.app_code || '').toLowerCase()
    const mediaUrl = getRawCaseMediaUrl(item)
    const coverUrl = getRawCaseCoverUrl(item)
    if (explicitType.includes('image')) return 'image'
    if (explicitType.includes('video') || appCode.includes('video') || appCode.includes('digital_human') || isVideoUrl(mediaUrl) || isVideoUrl(coverUrl)) {
        return 'video'
    }
    return 'image'
}

const getCaseMediaUrl = (item: any) => {
    const media = getRawCaseMediaUrl(item)
    if (media) return media

    const cover = getRawCaseCoverUrl(item)
    return isVideoUrl(cover) ? cover : ''
}

const getCaseCoverUrl = (item: any) => {
    const cover = getRawCaseCoverUrl(item)
    if (!cover) return ''

    const mediaType = getCaseMediaType(item)
    const media = getCaseMediaUrl(item)
    if (mediaType === 'video' && (isVideoUrl(cover) || (media && cover === media))) {
        return ''
    }

    return cover
}

const resolveCaseImage = (item: any) => {
    const cover = getCaseCoverUrl(item)
    if (cover) return cover
    if (getCaseMediaType(item) === 'video') return ''
    return getCaseMediaUrl(item)
}
const resolveCaseReferenceAssetUrl = (item: any, previewImage: string) => {
    const firstReference = getRawCaseReferenceUrls(item)[0] || ''
    const cover = getCaseCoverUrl(item)
    const media = getCaseMediaType(item) === 'video' ? '' : getCaseMediaUrl(item)
    return normalizeCaseAssetUrl(firstReference || cover || media || previewImage)
}
const getRawCaseReferenceUrls = (item: any) => {
    const list = [
        ...(Array.isArray(item.reference_images) ? item.reference_images : []),
        item.reference_image,
        item.reference_image_url,
        item.reference_url,
        item.reference_asset_url,
        item.referenceAssetUrl
    ]
    return list
        .map((url) => normalizeCaseAssetUrl(url))
        .filter(Boolean)
}
const hasCaseReferenceAsset = (item: any) => {
    return getRawCaseReferenceUrls(item).length > 0
}
const getInspirationCardStyle = (item: CardItem) => (
    item.aspectRatio ? { aspectRatio: item.aspectRatio } : undefined
)
const getCardWeight = (item: CardItem) => {
    if (item.aspectRatio) {
        const [width, height] = item.aspectRatio.split('/').map((value) => Number(value.trim()))
        if (width > 0 && height > 0) return height / width
    }
    return Number(item.imageHeight || 320) / 220
}
const getCardPrompt = (item: CardItem) => item.prompt || `${item.title}，高清，写实风格，主体突出，光影细腻`
const getCaseTypeLabel = (item: CardItem) => {
    if (item.appCode === 'aigc_digital_human') return '数字人案例'
    return item.category === 'video' ? '视频灵感' : '图片灵感'
}
const openWorkDetail = (item: CardItem) => {
    selectedCardKey.value = item.uniqueId
    activeDetailCard.value = item
    detailOpen.value = true
}
const closeWorkDetail = () => {
    detailOpen.value = false
    detailVideoRef.value?.pause()
}
const playInspirationVideo = (event: MouseEvent) => {
    const video = (event.currentTarget as HTMLElement).querySelector('video')
    video?.play().catch(() => undefined)
}
const pauseInspirationVideo = (event: MouseEvent) => {
    const video = (event.currentTarget as HTMLElement).querySelector('video')
    if (!video) return
    video.pause()
}
const primeInspirationVideoFrame = (event: Event) => {
    const video = event.currentTarget as HTMLVideoElement
    video.currentTime = Math.min(video.duration || 0, 0.01)
}
const markInspirationVideoReady = (item: CardItem) => {
    if (item.videoReady) return
    item.videoReady = true
}
const handleCaseImageError = (item: CardItem | null) => {
    if (!item) return
    item.image = ''
    if (item.category === 'video') item.videoReady = false
}
const playDetailVideo = () => {
    detailVideoRef.value?.play().catch(() => undefined)
}
const focusActiveComposer = async () => {
    await nextTick()
    if (showFloatingComposer.value && floatingComposerRef.value) {
        await floatingComposerRef.value.focusTextarea()
        return
    }
    pageScrollRef.value?.scrollTo({ top: 0, behavior: 'smooth' })
}
const fetchCaseReferenceBlob = async (urls: string[]) => {
    let lastError: unknown
    for (const url of urls) {
        try {
            const response = await fetch(url)
            if (!response.ok) {
                lastError = new Error('案例参考图获取失败')
                continue
            }
            return await response.blob()
        } catch (error) {
            lastError = error
        }
    }
    throw lastError instanceof Error ? lastError : new Error('案例参考图获取失败')
}
const buildCaseReferenceAsset = async (item: CardItem, source: 'reference' | 'work' = 'reference'): Promise<UploadedAsset> => {
    const sourceUrl = source === 'work'
        ? (item.image || (item.category === 'image' ? item.mediaUrl : ''))
        : item.referenceAssetUrl
    const directUri = getReferenceAssetUri(sourceUrl)
    const directPreviewUrl = normalizeCaseAssetUrl(sourceUrl)
    if (directUri) {
        return {
            id: `case-${item.id}-${Date.now()}`,
            name: item.referenceAssetName || item.title || '案例参考图',
            url: directPreviewUrl || directUri,
            uri: directUri,
            isObjectUrl: false
        }
    }

    const targetUrls = getCaseFetchUrlCandidates(sourceUrl)
    if (!targetUrls.length) throw new Error('案例参考图获取失败')
    const blob = await fetchCaseReferenceBlob(targetUrls)
    const extension = blob.type.includes('png') ? 'png' : blob.type.includes('webp') ? 'webp' : 'jpg'
    const file = new File([blob], `${item.referenceAssetName || item.title}.${extension}`, { type: blob.type || 'image/png' })
    const objectUrl = URL.createObjectURL(file)
    try {
        const res: any = await uploadImage({ file })
        const uri = res?.uri || res?.url || res?.path
        if (!uri) throw new Error('案例参考图上传失败')
        return {
            id: `case-${item.id}-${Date.now()}`,
            name: file.name,
            url: objectUrl,
            uri,
            isObjectUrl: true
        }
    } catch (error) {
        URL.revokeObjectURL(objectUrl)
        throw error
    }
}
const syncCardReference = async (item: CardItem, source: 'reference' | 'work' = 'reference') => {
    if (!userStore.isLogin) return false
    uploading.value = true
    feedback.loading('正在同步案例...')
    try {
        const asset = await buildCaseReferenceAsset(item, source)
        replaceUploadedAssets([asset])
        return true
    } finally {
        feedback.closeLoading()
        uploading.value = false
    }
}
const findCaseChannel = (options: CaseGenerationOptions, channelList: ChannelOption[]) => {
    const model = String(options.model || '').trim().toLowerCase()
    const channel = String(options.channel || '').trim().toLowerCase()
    if (!model && !channel) return undefined
    return channelList.find((item) => {
        const label = String(item.label || '').trim().toLowerCase()
        const value = String(item.value || '').trim().toLowerCase()
        return Boolean(
            (channel && (value === channel || label === channel)) ||
            (model && (label === model || value === model || label.includes(model) || model.includes(label)))
        )
    })
}
const applyCardGenerationOptions = (item: CardItem) => {
    const options = item.generationOptions || {}
    if (item.category === 'image') {
        const channel = findCaseChannel(options, channels.value)
        if (channel) selectedChannelCode.value = channel.value
        syncAigcSelection()
        if (options.resolution && qualities.value.some((quality) => quality.value === options.resolution)) {
            optionState.value.resolution = options.resolution
        }
        syncAigcSelection()
        if (options.ratio && ratios.value.some((ratio) => ratio.value === options.ratio)) {
            optionState.value.ratio = options.ratio
        }
        if (options.count && optionValues.value.count.includes(options.count)) {
            optionState.value.count = options.count
        }
        optionState.value.model = currentChannel.value?.label || options.model || optionState.value.model
        return
    }

    const channel = findCaseChannel(options, videoChannels.value)
    if (channel) selectedVideoChannelCode.value = channel.value
    syncAigcVideoSelection()
    const normalizedDuration = options.duration
        ? normalizeVideoDuration(options.duration)
        : ''
    if (normalizedDuration && videoDurations.value.includes(normalizedDuration)) {
        optionState.value.duration = normalizedDuration
    }
    syncAigcVideoSelection()
    if (options.ratio && videoRatios.value.some((ratio) => ratio.value === options.ratio)) {
        optionState.value.ratio = options.ratio
    }
    optionState.value.model = currentVideoChannel.value?.label || options.model || optionState.value.model
    optionState.value.count = '1条'
    optionState.value.quality = options.quality || optionState.value.quality || '720p'
}
const syncCardPromptToComposer = (item: CardItem) => {
    selectedCardKey.value = item.uniqueId
    generationMode.value = item.category
    prompt.value = getCardPrompt(item)
    applyCardGenerationOptions(item)
}
const copyCardPrompt = async (item: CardItem) => {
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    syncCardPromptToComposer(item)
    await focusActiveComposer()
    if (!item.hasReferenceAsset) {
        feedback.msgSuccess('提示词已同步')
        return
    }
    try {
        await syncCardReference(item)
        feedback.msgSuccess('提示词和参考图已同步')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgWarning(error?.msg || error?.message || '提示词已同步，参考图同步失败')
    }
}
const applyDetailPrompt = async () => {
    if (!activeDetailCard.value) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    const item = activeDetailCard.value
    syncCardPromptToComposer(item)
    if (!item.hasReferenceAsset) {
        feedback.msgSuccess('提示词已同步')
        closeWorkDetail()
        return
    }
    try {
        await syncCardReference(item)
        feedback.msgSuccess('提示词和参考图已同步')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgWarning(error?.msg || error?.message || '提示词已同步，参考图同步失败')
    }
    closeWorkDetail()
}
const applyDetailReference = async () => {
    if (!activeDetailCard.value) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    generationMode.value = activeDetailCard.value.category
    try {
        await syncCardReference(activeDetailCard.value, 'work')
        feedback.msgSuccess('参考图已同步')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图同步失败')
    }
    closeWorkDetail()
}
const buildCreateDraft = (task: string): AiCreateDraft => ({
    task,
    prompt: prompt.value.trim(),
    mode: generationMode.value,
    model: optionState.value.model,
    count: optionState.value.count,
    ratio: optionState.value.ratio,
    resolution: optionState.value.resolution,
    duration: optionState.value.duration,
    quality: optionState.value.quality,
    referenceFileName: uploadedAssets.value[0]?.name || '',
    referenceImage: uploadedAssets.value[0]?.url || '',
    referenceImages: uploadedAssets.value.map((item) => item.uri).filter(Boolean)
})
const submitPrompt = async () => {
    if (submitting.value || !canGenerate.value) return
    if (!ensurePcLogin()) return
    if (generationMode.value === 'image') {
        syncAigcSelection()
        if (!currentChannel.value?.value) {
            feedback.msgError('暂无可用图片模型')
            return
        }
        submitting.value = true
        feedback.loading('正在提交生成任务...')
        try {
            const res: any = await generateAigcImage({
                prompt: prompt.value.trim(),
                reference_images: uploadedAssets.value.map((item) => item.uri).filter(Boolean),
                ratio: optionState.value.ratio,
                quality: optionState.value.resolution,
                quantity: selectedQuantity.value,
                channel: selectedChannelCode.value,
                negative_prompt: ''
            })
            setDraft(buildCreateDraft(String(res?.task_id || Date.now())))
            feedback.msgSuccess(`已提交生成任务${selectedQuantity.value > 1 ? `，预计生成 ${selectedQuantity.value} 张图片` : ''}`)
            router.push(buildSidebarRouteLocation('create'))
        } catch (error: any) {
            if (isPcLoginRequiredError(error)) return
            feedback.msgError(error?.msg || error?.message || '提交生成任务失败')
        } finally {
            feedback.closeLoading()
            submitting.value = false
        }
        return
    }

    syncAigcVideoSelection()
    if (!currentVideoChannel.value?.value) {
        feedback.msgError('暂无可用视频模型')
        return
    }
    submitting.value = true
    feedback.loading('正在提交视频生成任务...')
    try {
        const res: any = await generateAigcVideo({
            prompt: prompt.value.trim(),
            reference_images: uploadedAssets.value.map((item) => item.uri).filter(Boolean),
            ratio: optionState.value.ratio,
            quality: currentVideoQuality.value?.value || optionState.value.quality,
            duration: durationValue(optionState.value.duration),
            quantity: 1,
            channel: selectedVideoChannelCode.value || currentVideoChannel.value.value,
            negative_prompt: ''
        })
        setDraft(buildCreateDraft(String(res?.task_id || Date.now())))
        feedback.msgSuccess('已提交视频生成任务')
        router.push(buildSidebarRouteLocation('create'))
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交视频生成任务失败')
    } finally {
        feedback.closeLoading()
        submitting.value = false
    }
}
const { lockFn: submitPromptLock } = useLockFn(submitPrompt)

const handleGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && detailOpen.value) closeWorkDetail()
}

watch(generationMode, (mode) => {
    if (mode === 'image') syncAigcSelection()
    else syncAigcVideoSelection()
})
watch(activeInspirationTab, (tab) => {
    if (tab === 'all') return
    if (!caseCards.value.some((item) => item.uniqueId === selectedCardKey.value && item.category === tab)) {
        const nextCard = caseCards.value.find((item) => item.category === tab)
        if (nextCard) selectedCardKey.value = nextCard.uniqueId
    }
})
watch(detailOpen, (value) => {
    if (typeof document === 'undefined') return
    if (pageScrollRef.value) pageScrollRef.value.style.overflowY = value ? 'hidden' : 'auto'
    if (value) {
        stopPageScrollThumbDrag()
        pageScrollThumbVisible.value = false
        return
    }
    nextTick(syncPageScrollUi)
})

onMounted(() => {
    if (!userStore.isLogin && typeof sessionStorage !== 'undefined' && sessionStorage.getItem('pc-ai-inspiration-login-shown') !== '1') {
        sessionStorage.setItem('pc-ai-inspiration-login-shown', '1')
        ensurePcLogin({ redirect: route.fullPath })
    }
    syncPageScrollUi()
    loadAigcConfig()
    loadAigcVideoConfig()
    refreshCaseCards()
    pageScrollRef.value?.addEventListener('scroll', syncPageScrollUi, { passive: true })
    window.addEventListener('resize', syncPageScrollUi)
    window.addEventListener('keydown', handleGlobalKeydown)
})

onBeforeUnmount(() => {
    stopPageScrollThumbDrag()
    uploadedAssets.value.forEach(revokeUploadedAsset)
    pageScrollRef.value?.removeEventListener('scroll', syncPageScrollUi)
    window.removeEventListener('resize', syncPageScrollUi)
    window.removeEventListener('keydown', handleGlobalKeydown)
})
</script>

<style lang="scss" scoped>
:global(html),
:global(body) {
    overflow: hidden;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.ai-app-page {
    position: relative;
    height: 100vh;
    min-width: 810px;
    padding: 0;
    background: #050505;
    color: #fff;
    overflow-x: hidden;
    overflow-y: auto;
    box-sizing: border-box;
    scrollbar-width: none;
    -ms-overflow-style: none;

    &::-webkit-scrollbar {
        width: 0;
        height: 0;
        background: transparent;
    }

    &__background,
    &__noise,
    &__stars {
        position: fixed;
        inset: 0;
        pointer-events: none;
        will-change: opacity;
    }

    &__background {
        background-position: center top;
        background-repeat: no-repeat;
        background-size: cover;
        opacity: 1;
    }

    &__noise {
        background-image:
            radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.65) 0 1px, transparent 1.8px),
            radial-gradient(circle at 12% 54%, rgba(255, 255, 255, 0.4) 0 1px, transparent 1.8px),
            radial-gradient(circle at 18% 32%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.6px),
            radial-gradient(circle at 26% 12%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.6px),
            radial-gradient(circle at 34% 58%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.8px),
            radial-gradient(circle at 42% 18%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.5px),
            radial-gradient(circle at 52% 10%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.5px),
            radial-gradient(circle at 61% 44%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.5px),
            radial-gradient(circle at 72% 20%, rgba(255, 255, 255, 0.48) 0 1px, transparent 1.7px),
            radial-gradient(circle at 84% 38%, rgba(255, 255, 255, 0.42) 0 1px, transparent 1.7px),
            radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.52) 0 1px, transparent 1.7px),
            radial-gradient(circle at 96% 52%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.8px);
        opacity: 0.24;
    }

    &__stars {
        opacity: 0.95;
        mix-blend-mode: screen;

        &--near {
            background-image:
                radial-gradient(circle at 10% 22%, rgba(255, 255, 255, 0.95) 0 1.4px, transparent 2.2px),
                radial-gradient(circle at 21% 68%, rgba(255, 255, 255, 0.92) 0 1.2px, transparent 2px),
                radial-gradient(circle at 36% 36%, rgba(255, 255, 255, 0.98) 0 1.5px, transparent 2.2px),
                radial-gradient(circle at 48% 14%, rgba(255, 255, 255, 0.9) 0 1.3px, transparent 2px),
                radial-gradient(circle at 57% 60%, rgba(255, 255, 255, 0.9) 0 1.1px, transparent 1.8px),
                radial-gradient(circle at 69% 28%, rgba(255, 255, 255, 0.96) 0 1.5px, transparent 2.2px),
                radial-gradient(circle at 82% 58%, rgba(255, 255, 255, 0.94) 0 1.25px, transparent 2px),
                radial-gradient(circle at 92% 20%, rgba(255, 255, 255, 0.9) 0 1.35px, transparent 2px);
            animation: starTwinkle 4.8s ease-in-out infinite alternate;
        }

        &--far {
            background-image:
                radial-gradient(circle at 14% 10%, rgba(160, 203, 255, 0.8) 0 1px, transparent 1.8px),
                radial-gradient(circle at 30% 48%, rgba(255, 255, 255, 0.72) 0 0.9px, transparent 1.5px),
                radial-gradient(circle at 44% 72%, rgba(178, 193, 255, 0.72) 0 0.9px, transparent 1.5px),
                radial-gradient(circle at 60% 8%, rgba(255, 255, 255, 0.68) 0 1px, transparent 1.6px),
                radial-gradient(circle at 78% 42%, rgba(181, 220, 255, 0.75) 0 0.95px, transparent 1.6px),
                radial-gradient(circle at 88% 74%, rgba(255, 255, 255, 0.7) 0 1px, transparent 1.6px);
            animation: starTwinkle 6.2s ease-in-out infinite alternate-reverse;
        }
    }
}

@keyframes starTwinkle {
    0% {
        opacity: 0.28;
        transform: scale(1);
    }

    50% {
        opacity: 0.62;
        transform: scale(1.01);
    }

    100% {
        opacity: 0.95;
        transform: scale(1.02);
    }
}

.app-main {
    position: relative;
    z-index: 1;
    width: 100%;
    min-width: 810px;
    min-height: 100%;
    margin: 0;
    padding: 96px 40px 120px 116px;
    box-sizing: border-box;
}

.hero-panel {
    position: relative;
    z-index: 3;
}

.hero-panel__heading {
    text-align: center;

    h1 {
        margin: 0;
        font-size: clamp(24px, 2.1vw, 32px);
        line-height: 1.2;
        font-weight: 700;
        letter-spacing: 0;
    }

    p {
        margin: 10px 0 0;
        color: #a1a1a1;
        font-size: 14px;
    }
}

.hero-panel__tags {
    display: flex;
    justify-content: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.hero-tag {
    height: 30px;
    padding: 0 14px;
    border: 0;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.78);
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s ease;

    &.is-active,
    &:hover {
        background: rgba(255, 255, 255, 0.14);
        color: #fff;
    }
}

.hero-composer {
    position: relative;
    z-index: 4;
    width: min(100%, clamp(650px, 70vw, 1200px));
    margin: 26px auto 0;
}

.inspiration-board {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: none;
    margin: 88px 0 0;
    overflow: visible;

    &__toolbar {
        display: flex;
        align-items: center;
        gap: 24px;
        margin-bottom: 20px;
    }

    &__scroller {
        min-height: auto;
        overflow: hidden;
        padding-right: 0;
    }
}

.inspiration-tabs {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;

    &__item {
        height: 36px;
        padding: 0 20px;
        border: 0;
        border-radius: 12px;
        background: transparent;
        color: rgba(255, 255, 255, 0.7);
        font-size: 15px;
        font-weight: 500;
        cursor: pointer;
        transition:
            background 0.2s ease,
            color 0.2s ease;

        &:hover,
        &.is-active {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }
    }
}

.inspiration-search {
    position: relative;
    display: inline-flex;
    align-items: center;
    width: min(312px, 100%);
    height: 36px;
    padding: 0 16px 0 40px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.02);

    &__icon {
        position: absolute;
        left: 16px;
        width: 14px;
        height: 14px;
        border: 1.5px solid rgba(255, 255, 255, 0.58);
        border-radius: 50%;
    }

    &__icon::after {
        content: '';
        position: absolute;
        right: -4px;
        bottom: -2px;
        width: 6px;
        height: 1.5px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.58);
        transform: rotate(45deg);
        transform-origin: center;
    }

    input {
        width: 100%;
        height: 100%;
        padding: 0;
        border: 0;
        background: transparent;
        color: #fff;
        font-size: 14px;
        outline: none;
    }

    input::placeholder {
        color: rgba(255, 255, 255, 0.42);
    }
}

.inspiration-grid {
    display: grid;
    grid-template-columns: repeat(var(--inspiration-columns, 6), minmax(0, 1fr));
    gap: 2px;
    margin-top: 4px;

    &__column {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }
}

.inspiration-card {
    position: relative;
    display: block;
    width: 100%;
    overflow: hidden;
    border: 1px solid transparent;
    border-radius: 2px;
    cursor: pointer;
    background: #101012;

    &__overlay {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
    }

    img,
    &__video,
    &__placeholder {
        display: block;
        position: relative;
        width: 100%;
        height: auto;
        object-fit: contain;
    }

    &.is-ratio-card img,
    &.is-ratio-card &__video,
    &.is-ratio-card &__placeholder {
        height: 100%;
        object-fit: cover;
    }

    &__video,
    &__placeholder {
        background: #101012;
    }

    &__placeholder {
        aspect-ratio: 3 / 4;
        background: #202127;
    }

    &.is-video::before {
        content: '';
        position: absolute;
        inset: 0;
        z-index: 0;
        background: linear-gradient(180deg, #1b1c1f 0%, #101012 100%);
    }

    &.is-video &__video {
        opacity: 0;
        transition: opacity 0.18s ease;
    }

    &.is-video-ready &__video {
        opacity: 1;
    }

    &__overlay {
        background: linear-gradient(180deg, rgba(13, 17, 20, 0.04) 36%, rgba(13, 17, 20, 0.94) 100%);
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    &__info {
        position: absolute;
        left: 18px;
        right: 18px;
        bottom: 58px;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 6px;
        opacity: 0;
        transform: translateY(8px);
        transition: all 0.2s ease;

        strong {
            font-size: 18px;
            line-height: 1.35;
            font-weight: 600;
        }

        span {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
        }
    }

    &__action {
        position: absolute;
        left: 18px;
        right: 18px;
        bottom: 18px;
        z-index: 2;
        height: 36px;
        border: 0;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.95);
        color: #222;
        font-size: 14px;
        font-weight: 500;
        opacity: 0;
        transform: translateY(8px);
        transition: all 0.2s ease;
        cursor: pointer;
    }

    &:hover,
    &.is-selected {
        border-color: rgba(255, 255, 255, 0.12);
    }

    &:hover &__overlay,
    &:hover &__info,
    &:hover &__action {
        opacity: 1;
        transform: translateY(0);
    }
}

.inspiration-empty {
    display: flex;
    min-height: 280px;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 8px;
    color: rgba(255, 255, 255, 0.54);

    strong {
        color: #fff;
        font-size: 18px;
    }
}

.detail-fade-enter-active,
.detail-fade-leave-active {
    transition: opacity 0.22s ease;
}

.detail-fade-enter-from,
.detail-fade-leave-to {
    opacity: 0;
}

.work-detail {
    position: fixed;
    inset: 0;
    z-index: 30;
    background: rgba(10, 10, 12, 0.96);
    backdrop-filter: blur(14px);

    &__panel {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 380px;
        width: 100%;
        height: 100%;
    }

    &__close {
        position: absolute;
        top: 24px;
        left: 24px;
        z-index: 4;
        width: 40px;
        height: 40px;
        border: 1px solid rgba(255, 255, 255, 0.12);
        border-radius: 50%;
        background: rgba(17, 17, 19, 0.72);
        color: #fff;
        font-size: 28px;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition:
            border-color 0.2s ease,
            background 0.2s ease;

        img {
            display: block;
            width: 20px;
            height: 20px;
            object-fit: contain;
        }

        &:hover {
            border-color: rgba(255, 255, 255, 0.24);
            background: rgba(28, 28, 31, 0.94);
        }
    }

    &__media {
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 0;
        padding: 20px 24px;
        background:
            radial-gradient(circle at top, rgba(255, 255, 255, 0.06), transparent 36%),
            #111114;

        &-frame {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
            padding: 56px 24px 32px;
        }

        img,
        video,
        .work-detail__placeholder {
            display: block;
            width: auto;
            height: auto;
            max-width: min(100%, calc(100vw - 500px));
            max-height: calc(100vh - 112px);
            border-radius: 12px;
            object-fit: contain;
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.4);
        }

        .work-detail__placeholder {
            width: min(520px, calc(100vw - 500px));
            aspect-ratio: 3 / 4;
            background: #202127;
        }
    }

    &__content {
        display: flex;
        flex-direction: column;
        gap: 22px;
        height: 100%;
        min-height: 0;
        padding: 20px 22px 22px;
        border-left: 1px solid rgba(255, 255, 255, 0.06);
        color: rgba(255, 255, 255, 0.96);
        background:
            radial-gradient(circle at top left, rgba(255, 255, 255, 0.05), transparent 26%),
            linear-gradient(180deg, #171719 0%, #101012 100%);
        overflow-y: auto;
    }

    &__header {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    &__author-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
    }

    &__author-group,
    &__author {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

    &__avatar {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        flex-shrink: 0;

        img {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: cover;
        }
    }

    &__author-meta {
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 4px;

        strong {
            color: rgba(255, 255, 255, 0.98);
            font-size: 16px;
            font-weight: 600;
            line-height: 1.2;
        }
    }

    &__social {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        color: rgba(255, 255, 255, 0.92);
        font-size: 14px;

        button,
        a {
            width: 28px;
            height: 28px;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.06);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;

            img {
                width: 16px;
                height: 16px;
                display: block;
                object-fit: contain;
            }
        }
    }

    &__subline {
        display: flex;
        align-items: center;
        gap: 18px;
        padding-bottom: 18px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.78);
        font-size: 12px;
    }

    &__prompt {
        display: flex;
        flex-direction: column;
        min-height: 0;
        flex: 1;
        padding: 2px 0 0;
    }

    &__prompt-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;

        span {
            color: rgba(255, 255, 255, 0.94);
            font-size: 15px;
            font-weight: 600;
        }
    }

    &__prompt-body {
        min-height: 0;
        flex: 1;
        overflow: auto;
        padding-right: 8px;

        p {
            margin: 0;
            color: rgba(255, 255, 255, 0.94);
            font-size: 15px;
            line-height: 2;
            white-space: pre-wrap;
            word-break: break-word;
            font-weight: 600;
        }
    }

    &__config {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0;
        margin-top: 20px;
        min-height: 16px;
        white-space: nowrap;
    }

    &__config-text {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: rgba(255, 255, 255, 0.48);
        font-size: 14px;
        font-weight: 500;
        line-height: 1;
    }

    &__config-text + .work-detail__config-text::before {
        content: '|';
        margin: 0 8px;
        color: rgba(255, 255, 255, 0.24);
    }

    &__footer {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    &__ghost,
    &__primary {
        flex: 1;
        height: 44px;
        padding: 0 20px;
        border: 0;
        border-radius: 12px;
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }

    &__ghost {
        background: rgba(255, 255, 255, 0.08);
    }

    &__primary {
        background: rgba(255, 255, 255, 0.12);
    }
}

.inspiration-board__scroller,
.work-detail__content,
.work-detail__prompt-body {
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.inspiration-board__scroller::-webkit-scrollbar,
.work-detail__content::-webkit-scrollbar,
.work-detail__prompt-body::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.inspiration-board__scroller::-webkit-scrollbar-track,
.work-detail__content::-webkit-scrollbar-track,
.work-detail__prompt-body::-webkit-scrollbar-track {
    background: transparent;
}

.inspiration-board__scroller::-webkit-scrollbar-thumb,
.work-detail__content::-webkit-scrollbar-thumb,
.work-detail__prompt-body::-webkit-scrollbar-thumb {
    min-height: 64px;
    border-radius: 999px;
    background: #242424;
}

.floating-prompt-fade-enter-active,
.floating-prompt-fade-leave-active {
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
}

.floating-prompt-fade-enter-from,
.floating-prompt-fade-leave-to {
    opacity: 0;
    transform: translateY(18px);
}

.floating-prompt {
    position: fixed;
    left: calc(88px + (max(900px, 100vw) - 112px) / 2);
    bottom: 24px;
    z-index: 11;
    width: min(1024px, calc(100vw - 224px));
    transform: translateX(-50%);
}

.floating-prompt :deep(.ai-create-composer.is-collapsed) {
    width: min(720px, 100%);
    margin: 0 auto;
}

.page-scroll-thumb {
    position: fixed;
    top: 0;
    right: 2px;
    z-index: 40;
    width: 6px;
    border-radius: 999px;
    background: #242424;
    opacity: 0.96;
    cursor: grab;
    pointer-events: auto;
    touch-action: none;
    transition:
        background-color 0.18s ease,
        opacity 0.18s ease,
        width 0.18s ease;
    will-change: transform;

    &:hover {
        width: 7px;
        background: #343434;
        opacity: 1;
    }

    &.is-dragging {
        width: 7px;
        background: #3a3a3a;
        opacity: 1;
        cursor: grabbing;
    }
}

@media (max-width: 1100px) {
    .app-main {
        padding: 96px 20px 120px 116px;
    }

    .hero-composer {
        width: 100%;
    }

    .inspiration-board__toolbar {
        gap: 16px;
    }

    .inspiration-search {
        width: min(260px, 100%);
    }

    .work-detail__panel {
        grid-template-columns: 1fr;
        overflow-y: auto;
    }

    .work-detail__media {
        min-height: 360px;
        padding: 72px 20px 20px;
    }

    .work-detail__media-frame {
        padding: 0;
    }

    .work-detail__media img,
    .work-detail__media video,
    .work-detail__placeholder {
        max-width: 100%;
        max-height: 300px;
    }

    .work-detail__content {
        height: auto;
        padding: 24px 18px 18px;
        border-left: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
    }

    .floating-prompt {
        left: calc(88px + (max(900px, 100vw) - 112px) / 2);
        width: min(1024px, calc(100vw - 224px));
    }
}

@media (max-width: 760px) {
    .inspiration-board__toolbar {
        flex-wrap: wrap;
    }

    .inspiration-search {
        width: 100%;
    }

    .work-detail__footer {
        flex-direction: column;
    }

    .work-detail__author-row {
        flex-direction: column;
    }

    .work-detail__ghost,
    .work-detail__primary {
        width: 100%;
    }
}
</style>
