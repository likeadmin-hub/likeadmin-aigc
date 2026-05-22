<template>
    <div :class="['page', { 'is-empty': !filteredWorks.length, 'is-fit': pageFitsViewport }]">
        <div class="page__bg" :style="backgroundStyle"></div>
        <div class="page__noise"></div>
        <div class="page__stars page__stars--near"></div>
        <div class="page__stars page__stars--far"></div>

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
            @navigate="handleSidebar"
        />

        <main ref="mainRef" :class="['main', { 'is-empty': !filteredWorks.length }]">
            <section v-if="orderedWorks.length" class="summary">
                <div class="summary__filters">
                    <div class="generation-filter">
                        <button
                            class="summary-filter"
                            type="button"
                            @click.stop="generationFilterOpen = !generationFilterOpen"
                        >
                            {{ generationFilterLabel }}
                            <img :src="downIcon" alt="" />
                        </button>
                        <div v-if="generationFilterOpen" class="generation-filter__menu">
                            <button
                                v-for="item in generationFilterOptions"
                                :key="item.value"
                                :class="{ 'is-active': selectedGenerationFilter === item.value }"
                                type="button"
                                @click.stop="selectGenerationFilter(item.value)"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <section ref="workListRef" class="works">
                <template v-if="filteredWorks.length">
                    <article v-for="work in filteredWorks" :key="work.id" class="work">
                        <div class="work__head">
                            <div class="work__text">
                                <div class="work__title">
                                    <div
                                        v-if="getWorkReferenceImages(work).length"
                                        class="ref-stack"
                                        :style="getReferenceStackStyle(work)"
                                        aria-label="参考图"
                                    >
                                        <span
                                            v-for="(image, index) in getWorkReferenceImages(work)"
                                            :key="`${work.id}-ref-${index}`"
                                            class="ref-stack__item"
                                            :style="getReferenceStackItemStyle(index)"
                                        >
                                            <img :src="image" alt="" />
                                        </span>
                                    </div>
                                    <h2>{{ getWorkPromptTitle(work) }}</h2>
                                </div>
                                <div class="work__meta">
                                    <span v-for="item in getWorkConfigItems(work)" :key="`${work.id}-${item}`">{{ item }}</span>
                                </div>
                            </div>
                        </div>

                        <div :class="['grid', `is-${getCards(work).length}`]">
                            <article
                                v-for="(card, index) in getCards(work)"
                                :key="card.id"
                                :class="['card', { 'is-pending': isWorkCreating(work), 'is-failed': work.backendStatus === 'failed' }]"
                                :style="getCardStyle(card)"
                                @click="openWorkDetail(work, card.image, card.video || '')"
                            >
                                <video
                                    v-if="card.video && !isWorkWithoutResult(work)"
                                    class="card__video"
                                    :src="card.video"
                                    :poster="card.image || undefined"
                                    muted
                                    loop
                                    playsinline
                                    preload="metadata"
                                    @loadedmetadata="primeCardVideoFrame"
                                    @mouseenter="playCardVideo"
                                    @mouseleave="pauseCardVideo"
                                ></video>
                                <img v-else-if="card.image && !isWorkWithoutResult(work)" :src="card.image" :alt="card.alt" />
                                <span v-else class="card__placeholder" aria-hidden="true"></span>
                                <div class="card__mask"></div>
                                <div v-if="isWorkCreating(work)" class="card__state">
                                    <span class="spinner"></span>
                                    <strong>{{ getWorkStatusText(work) }}</strong>
                                    <span>{{ getWorkStatusDescription(work, index) }}</span>
                                </div>
                                <div v-else-if="work.backendStatus === 'failed'" class="card__state">
                                    <strong>创作失败</strong>
                                    <span>{{ work.error || '上游生成失败，请调整参数后重试' }}</span>
                                </div>
                                <div v-else-if="isWorkWithoutResult(work)" class="card__state">
                                    <strong>{{ getWorkStatusText(work) }}</strong>
                                    <span>{{ getWorkStatusDescription(work, index) }}</span>
                                </div>
                            </article>
                        </div>

                        <div class="work__footer">
                            <button class="ghost" type="button" @click="loadWorkToComposer(work)">重新编辑</button>
                            <button v-if="work.status !== 'creating'" class="ghost" type="button" @click="rerunWork(work)">再次生成</button>
                            <button
                                v-if="canDeleteWork(work)"
                                class="ghost ghost--icon"
                                type="button"
                                aria-label="删除作品"
                                title="删除"
                                @click.stop.prevent="deleteWork(work)"
                            >
                                <img :src="deleteIcon" alt="" />
                            </button>
                        </div>
                    </article>
                </template>

                <div v-else class="empty">
                    <img class="empty__placeholder" :src="emptyImage" alt="" />
                    <p>暂无创作，开启你的创作</p>
                </div>
            </section>
        </main>

        <Teleport to="body">
            <div v-if="detailWork" class="work-detail" @click.self="closeWorkDetail">
                <div class="work-detail__panel">
                    <div class="work-detail__media">
                        <button class="work-detail__close" type="button" aria-label="关闭" @click="closeWorkDetail">×</button>
                        <div class="work-detail__media-frame">
                            <video v-if="detailVideo" :src="detailVideo" :poster="detailImage" controls autoplay playsinline></video>
                            <img v-else-if="detailImage" :src="detailImage" alt="生成结果" />
                            <div v-else class="work-detail__placeholder">
                                <strong>{{ getWorkStatusText(detailWork) }}</strong>
                                <span>{{ getWorkStatusDescription(detailWork, 0) }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="work-detail__content">
                        <div class="work-detail__header">
                            <div class="work-detail__author-row">
                                <div class="work-detail__author">
                                    <span class="work-detail__avatar">{{ getWorkTypeLabel(detailWork).slice(0, 1) }}</span>
                                    <div class="work-detail__author-meta">
                                        <strong>我的创作</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="work-detail__subline">
                                <strong>{{ getWorkTypeLabel(detailWork) }}</strong>
                                <span>{{ formatTime(detailWork.createdAt) }}</span>
                                <span>内容由 AI 生成</span>
                            </div>
                        </div>

                        <div class="work-detail__prompt">
                            <div class="work-detail__prompt-head">
                                <span>提示词</span>
                            </div>
                            <div class="work-detail__prompt-body">
                                <p>{{ detailWork.prompt || '无提示词' }}</p>
                            </div>
                            <div class="work-detail__config">
                                <span v-for="item in getWorkConfigItems(detailWork)" :key="`detail-${item}`" class="work-detail__config-text">{{ item }}</span>
                            </div>
                            <div v-if="getWorkReferenceImages(detailWork).length || detailWork.referenceFileName" class="work-detail__section">
                                <span>参考图</span>
                                <div class="ref-card ref-card--detail">
                                    <div v-if="getWorkReferenceImages(detailWork).length" class="ref-card__thumbs">
                                        <button
                                            v-for="(image, index) in getWorkReferenceImages(detailWork)"
                                            :key="`detail-ref-${index}`"
                                            class="ref-card__thumb"
                                            type="button"
                                            @click="openReferencePreview(detailWork, image, index)"
                                        >
                                            <img :src="image" :alt="`${detailWork.referenceFileName || '参考图'} ${index + 1}`" />
                                        </button>
                                    </div>
                                    <div>
                                        <small>参考图 {{ getWorkReferenceImages(detailWork).length ? getWorkReferenceImages(detailWork).length : '' }}</small>
                                        <strong>{{ detailWork.referenceFileName || '已上传参考图' }}</strong>
                                    </div>
                                </div>
                            </div>
                            <div v-if="detailWork.error" class="work-detail__section">
                                <span>失败原因</span>
                                <p>{{ detailWork.error }}</p>
                            </div>
                        </div>
                        <div v-if="detailWork" class="work-detail__actions">
                            <button
                                :class="['work-detail__favorite', { 'is-active': isWorkFavorite(detailWork) }]"
                                type="button"
                                @click="toggleWorkFavorite(detailWork)"
                            >
                                <img :src="favoriteIcon" alt="" />
                                {{ isWorkFavorite(detailWork) ? '已收藏' : '收藏作品' }}
                            </button>
                            <a
                                v-if="getWorkDownloadHref(detailWork, detailDownloadUrl)"
                                class="work-detail__download"
                                :href="getWorkDownloadHref(detailWork, detailDownloadUrl)"
                                :download="getDownloadName(detailWork, getWorkDownloadHref(detailWork, detailDownloadUrl))"
                                target="_blank"
                                rel="noopener noreferrer"
                                @pointerdown.stop
                                @click.stop="handleDownloadClick"
                            >
                                <img :src="downloadIcon" alt="" />
                                下载作品
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <Teleport to="body">
            <div v-if="referencePreviewImage" class="reference-preview" @click.self="closeReferencePreview">
                <button class="reference-preview__close" type="button" aria-label="关闭参考图预览" @click="closeReferencePreview">×</button>
                <button
                    v-if="referencePreviewImages.length > 1"
                    class="reference-preview__nav reference-preview__nav--prev"
                    type="button"
                    aria-label="上一张参考图"
                    @click.stop="showPreviousReferencePreview"
                >
                    ‹
                </button>
                <img :src="referencePreviewImage" :alt="referencePreviewTitle || '参考图'" />
                <button
                    v-if="referencePreviewImages.length > 1"
                    class="reference-preview__nav reference-preview__nav--next"
                    type="button"
                    aria-label="下一张参考图"
                    @click.stop="showNextReferencePreview"
                >
                    ›
                </button>
                <span>{{ referencePreviewTitle || '参考图' }}</span>
            </div>
        </Teleport>

        <div ref="composerShellRef" class="composer">
            <AiCreateComposer
                ref="composerRef"
                v-model="prompt"
                v-model:mode="generationMode"
                v-model:option-state="optionState"
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
                @submit="submitPromptLock"
            />
        </div>

        <input ref="fileInputRef" type="file" class="sr-only" accept="image/*" multiple @change="handleUpload" />
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type {
    AiCreateDraft,
    AiCreateOptionKey,
    AiCreateOptionState,
    AiCreateOptionValues,
    AiCreateStatus,
    AiCreateWork,
    AiGenerationMode
} from '~/composables/useAiCreateWorks'
import {
    createAiCreateOptionState,
    getAiCreateCards,
    normalizeAiCreateOptionState,
    useAiCreateWorks
} from '~/composables/useAiCreateWorks'
import { getAigcImageConfig, generateAigcImage, getAigcImageResults, getAigcImageTasks, deleteAigcImageResult } from '@/apps/aigc_image/api'
import { getAigcVideoConfig, generateAigcVideo, getAigcVideoResults, getAigcVideoTasks, deleteAigcVideoResult } from '@/apps/aigc_video/api'
import { getAigcDigitalHumanResults, getAigcDigitalHumanTasks, deleteAigcDigitalHumanResult } from '@/apps/aigc_digital_human/api'
import { getImageHumanResults, getImageHumanTasks, deleteImageHumanResult } from '@/apps/image_human/api'
import { uploadImage as uploadAppImage } from '@/api/app'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'
import { getPcDownloadExtension, resolvePcDownloadUrl } from '@/utils/download'
import feedback from '@/utils/feedback'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import deleteIcon from '@/assets/images/icon/Delete-themes.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'
import favoriteIcon from '@/assets/images/icon/shoucang.svg'

definePageMeta({ layout: 'blank' })

type OptionKey = AiCreateOptionKey
type PopoverKey = '' | 'share' | 'api' | 'notice'
type WorkStatusFilter = '' | 'pending' | 'running' | 'success' | 'failed' | 'canceled' | 'creating' | 'created'
type WorkTypeFilter = '' | 'image' | 'video' | 'digital_human'
type WorkCategory = Exclude<WorkTypeFilter, ''>
type ConfigOption = {
    key: OptionKey
    value: string
    disabled?: boolean
}
type UploadedAsset = {
    id: string
    name: string
    url: string
    isObjectUrl: boolean
    uri?: string
    uploading?: boolean
}

interface RatioOption {
    label: string
    value: string
    tenant_unit_price?: string | number
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
    qualities: QualityOption[]
}

type BackendWork = AiCreateWork & {
    backendStatus?: string
    error?: string
    source?: 'backend' | 'local'
    category?: WorkCategory
    appCode?: 'aigc_digital_human' | 'image_human'
    referenceImages?: string[]
    resultImage?: string
    resultVideo?: string
    coverImage?: string
}

type WorkCard = {
    id: string
    image: string
    video?: string
    alt: string
    ratio?: string
}

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const fileInputRef = ref<HTMLInputElement | null>(null)
const workListRef = ref<HTMLElement | null>(null)
const { works, draft, appendWork, setDraft } = useAiCreateWorks()
const { isFavorite, toggleFavorite } = useAiWorkspaceFavorites()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()

const activeSidebar = ref<SidebarKey>('create')
const activeTab = ref<WorkStatusFilter>('')
const activeType = ref<WorkTypeFilter>('')
const apiCredits = ref(24)
const activePopover = ref<PopoverKey>('')
const generationMode = ref<AiGenerationMode>('image')
const prompt = ref('')
const mainRef = ref<HTMLElement | null>(null)
const composerShellRef = ref<HTMLElement | null>(null)
const composerRef = ref<{ focusTextarea: () => Promise<void>; collapseIfEmpty: () => void } | null>(null)
const generationFilterOpen = ref(false)
const selectedGenerationFilter = ref<'all' | AiGenerationMode>('all')
const emptyImage = 'https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/images/20260519/20260519165642975309142.jpg'
const pageFitsViewport = ref(false)
const uploadedFileName = ref('')
const uploadedPreviewUrl = ref('')
const uploadedReferenceUri = ref('')
const uploadedAssets = ref<UploadedAsset[]>([])
const uploading = ref(false)
const submitting = ref(false)
const backendWorks = ref<BackendWork[]>([])
const optimisticBackendWorks = ref<BackendWork[]>([])
const detailWork = ref<BackendWork | null>(null)
const detailImage = ref('')
const detailVideo = ref('')
const detailDownloadUrl = computed(() => detailVideo.value || detailImage.value || detailWork.value?.resultVideo || detailWork.value?.resultImage || detailWork.value?.coverImage || '')
const referencePreviewImage = ref('')
const referencePreviewTitle = ref('')
const referencePreviewImages = ref<string[]>([])
const referencePreviewIndex = ref(0)
const referencePreviewBaseTitle = ref('参考图')
let backendRefreshTimer: ReturnType<typeof setInterval> | null = null
let latestWorkAlignTimers: ReturnType<typeof setTimeout>[] = []
let isRefreshingBackendWorks = false
let hasMountedCreatePage = false
let pendingInitialLatestWorkJump = true
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
        channel: 'grok_video_xaiq',
        quality: '6',
        ratio: '16:9',
        quantity: 1
    },
    quantity_options: [1],
    max_reference_images: 7
})
const selectedVideoChannelCode = ref('')

const optionState = ref<AiCreateOptionState>(createAiCreateOptionState())

const currentPlaceholder = computed(() =>
    generationMode.value === 'image'
        ? '输入图片生成的提示词，例如：浩瀚的银河中一艘宇宙飞船驶过'
        : '输入视频生成的提示词，例如：镜头缓慢推进，角色在霓虹街道中回眸'
)
const canGenerate = computed(() => Boolean(prompt.value.trim()) && !submitting.value && !uploading.value)
const canSubmit = computed(() => canGenerate.value && !isSubmitPromptLocked.value)
const backgroundStyle = computed(() => ({ backgroundImage: 'linear-gradient(180deg,#050505 0%,#06070a 42%,#050505 100%)' }))
const configOptions = computed<ConfigOption[]>(() =>
    generationMode.value === 'video'
        ? [
            { key: 'model', value: currentVideoChannel.value?.label || 'Grok Video（xAIQ）', disabled: videoChannels.value.length <= 1 },
            ...(videoHasResolutionOptions.value ? [{ key: 'resolution' as OptionKey, value: optionState.value.resolution || '分辨率' }] : []),
            { key: 'ratio', value: optionState.value.ratio },
            { key: 'duration', value: optionState.value.duration },
        ]
        : [
            { key: 'model', value: currentChannel.value?.label || '通道选择', disabled: channels.value.length <= 1 },
            { key: 'count', value: optionState.value.count },
            { key: 'ratio', value: optionState.value.ratio },
            { key: 'resolution', value: optionState.value.resolution }
        ]
)
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
const getVideoQualityResolution = (quality: any) =>
    normalizeVideoResolution(quality.resolution || quality.provider_params_json?.resolution || quality.label || quality.quality_label || quality.value || quality.quality)
const getVideoQualityDuration = (quality: any) =>
    normalizeVideoDuration(quality.duration || quality.provider_params_json?.duration || quality.label || quality.quality_label || quality.value || quality.quality)
const videoChannels = computed<ChannelOption[]>(() =>
    (aigcVideoOptionConfig.value.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        value: channel.value || channel.code,
        max_reference_images: Number(channel.max_reference_images || aigcVideoOptionConfig.value.max_reference_images || 7),
        qualities: (channel.qualities || []).map((quality: any) => ({
            label: getVideoQualityDuration(quality),
            value: String(quality.value || quality.quality),
            resolution: getVideoQualityResolution(quality),
            duration: getVideoQualityDuration(quality),
            ratios: (quality.ratios || []).map((ratio: any) => ({
                ...ratio,
                label: ratio.label || ratio.ratio || ratio.value,
                value: ratio.value || ratio.ratio
            }))
        }))
    }))
)
const currentVideoChannel = computed(() => videoChannels.value.find((item) => item.value === selectedVideoChannelCode.value) || videoChannels.value[0])
const videoQualities = computed<QualityOption[]>(() => currentVideoChannel.value?.qualities || [])
const videoResolutions = computed(() => Array.from(new Set(videoQualities.value.map((item) => item.resolution || '默认'))))
const videoHasResolutionOptions = computed(() => videoResolutions.value.some((item) => item !== '默认'))
const videoQualitiesByResolution = computed(() =>
    videoQualities.value.filter((item) => String(item.resolution || '默认') === String(optionState.value.resolution || videoResolutions.value[0] || '默认'))
)
const videoDurations = computed(() => Array.from(new Set(videoQualitiesByResolution.value.map((item) => item.duration || item.label || item.value))))
const currentVideoQuality = computed(() =>
    videoQualitiesByResolution.value.find((item) => String(item.duration || item.label || item.value) === String(optionState.value.duration)) ||
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
const normalizeOptionValue = (value: unknown) => String(value ?? '').trim()
let isSyncingOptionSideEffects = false
const imageOptionValues = computed<Record<OptionKey, string[]>>(() => ({
    model: channels.value.map((item) => item.label),
    count: ['1张', '2张', '3张', '4张'],
    ratio: ratios.value.map((item) => item.value),
    resolution: qualities.value.map((item) => item.value),
    duration: ['5s', '10s', '15s'],
    quality: ['1k标清', '2k高清']
}))
const videoOptionValues = computed<Record<OptionKey, string[]>>(() => ({
    model: videoChannels.value.map((item) => item.label),
    count: ['1条'],
    ratio: videoRatios.value.map((item) => item.value),
    resolution: videoResolutions.value,
    duration: videoDurations.value,
    quality: []
}))
const optionValues = computed<AiCreateOptionValues>(() => (
    generationMode.value === 'image'
        ? imageOptionValues.value
        : videoOptionValues.value
))
const unitPriceLabel = computed(() => (
    generationMode.value === 'image'
        ? `${selectedUnitPrice.value}/张`
        : `${selectedVideoUnitPrice.value}/条`
))
const generationFilterOptions = [
    { label: '全部', value: 'all' as const },
    { label: '图片', value: 'image' as const },
    { label: '视频', value: 'video' as const }
]
const generationFilterLabel = computed(() => generationFilterOptions.find((item) => item.value === selectedGenerationFilter.value)?.label || '全部')
const syncImageOptionSideEffects = (next: AiCreateOptionState, previous: AiCreateOptionState) => {
    if (isSyncingOptionSideEffects) return
    isSyncingOptionSideEffects = true
    const modelChanged = normalizeOptionValue(next.model) !== normalizeOptionValue(previous.model)
    if (modelChanged) {
        const nextChannel = channels.value.find((item) => item.label === next.model || item.value === next.model)
        if (nextChannel) selectedChannelCode.value = nextChannel.value
    }
    const resolutionChanged = normalizeOptionValue(next.resolution) !== normalizeOptionValue(previous.resolution)
    if (resolutionChanged) {
        const nextQuality = qualities.value.find((item) => item.value === next.resolution)
        optionState.value.ratio = nextQuality?.ratios?.[0]?.value || next.ratio
    }
    syncAigcSelection()
    nextTick(() => { isSyncingOptionSideEffects = false })
}
const syncVideoOptionSideEffects = (next: AiCreateOptionState, previous: AiCreateOptionState) => {
    if (isSyncingOptionSideEffects) return
    isSyncingOptionSideEffects = true
    const modelChanged = normalizeOptionValue(next.model) !== normalizeOptionValue(previous.model)
    if (modelChanged) {
        const nextChannel = videoChannels.value.find((item) => item.label === next.model || item.value === next.model)
        if (nextChannel) selectedVideoChannelCode.value = nextChannel.value
    }
    const durationChanged = normalizeOptionValue(next.duration) !== normalizeOptionValue(previous.duration)
    const resolutionChanged = normalizeOptionValue(next.resolution) !== normalizeOptionValue(previous.resolution)
    if (resolutionChanged) {
        optionState.value.duration = videoDurations.value[0] || next.duration
        optionState.value.ratio = videoQualitiesByResolution.value[0]?.ratios?.[0]?.value || next.ratio
    }
    if (durationChanged) {
        const nextQuality = videoQualitiesByResolution.value.find((item) => (item.duration || item.label || item.value) === next.duration)
        optionState.value.ratio = nextQuality?.ratios?.[0]?.value || next.ratio
    }
    syncAigcVideoSelection()
    nextTick(() => { isSyncingOptionSideEffects = false })
}
const localWorks = computed<BackendWork[]>(() => works.value
    .filter((item) => !backendWorks.value.some((backendItem) => backendItem.task === item.task))
    .map((item) => ({ ...item, source: 'local' as const, category: item.mode === 'video' ? 'video' as const : 'image' as const })))
const orderedWorks = computed<BackendWork[]>(() => [
    ...optimisticBackendWorks.value.filter((localItem) => !backendWorks.value.some((backendItem) => backendItem.id === localItem.id)),
    ...backendWorks.value,
    ...localWorks.value
].sort((a, b) => a.createdAt - b.createdAt))
const creatingCount = computed(() => orderedWorks.value.filter((item) => isWorkCreating(item)).length)
const createdCount = computed(() => orderedWorks.value.filter((item) => item.backendStatus === 'success' || item.status === 'created').length)
const failedCount = computed(() => orderedWorks.value.filter((item) => item.backendStatus === 'failed').length)
const filterWorksByStatus = (list: BackendWork[], status: WorkStatusFilter) => {
    if (!status) return list
    if (status === 'creating') return list.filter((item) => isWorkCreating(item))
    if (status === 'created') return list.filter((item) => item.status === 'created')
    return list.filter((item) => (item.backendStatus || item.status) === status)
}
const generationFilteredWorks = computed(() => {
    if (selectedGenerationFilter.value === 'all') return orderedWorks.value
    return orderedWorks.value.filter((item) => item.mode === selectedGenerationFilter.value && getWorkCategory(item) !== 'digital_human')
})
const filteredWorks = computed(() => filterWorksByStatus(generationFilteredWorks.value, activeTab.value))
const chromePopoverContent = computed(() => ({
    share: {
        title: '邀请好友',
        text: '分享创作链接，双方各得 10 张生成额度。'
    },
    api: {
        title: 'API 配额',
        text: `当前体验额度 ${apiCredits.value} 次，点击可查看调用模型说明。`
    },
    notice: {
        title: '消息中心',
        text: !orderedWorks.value.length
            ? '当前还没有创作任务，去灵感页发起第一条创作吧。'
            : `当前共有 ${orderedWorks.value.length} 个任务，${creatingCount.value} 个生成中，${createdCount.value} 个已完成，${failedCount.value} 个失败。`,
        compact: true
    }
}))
const getWorkCategory = (work: Pick<BackendWork, 'category' | 'mode'>): WorkCategory => work.category || (work.mode === 'video' ? 'video' : 'image')
const getWorkFavoriteCategory = (work: Pick<BackendWork, 'category' | 'mode'>) => {
    const category = getWorkCategory(work)
    return category === 'digital_human' ? 'avatar' : category
}
const getWorkFavoriteId = (work: Pick<BackendWork, 'task' | 'id' | 'appCode'>) =>
    work.appCode === 'image_human' ? `image_human-${work.task || work.id}` : work.task || work.id
const isWorkFavorite = (work: BackendWork | null) => Boolean(work && isFavorite(getWorkFavoriteCategory(work), getWorkFavoriteId(work)))
const toggleWorkFavorite = (work: BackendWork | null) => {
    if (!work) return
    if (!userStore.isLogin) return
    toggleFavorite(getWorkFavoriteCategory(work), getWorkFavoriteId(work))
}
const canDeleteWork = (work: BackendWork | null) => Boolean(work && work.status !== 'creating' && !isWorkCreating(work))
const removeWorkFromCreateList = (work: BackendWork) => {
    backendWorks.value = backendWorks.value.filter((item) => item.id !== work.id && item.task !== work.task)
    optimisticBackendWorks.value = optimisticBackendWorks.value.filter((item) => item.id !== work.id && item.task !== work.task)
    works.value = works.value.filter((item) => item.id !== work.id && item.task !== work.task)
    if (detailWork.value && (detailWork.value.id === work.id || detailWork.value.task === work.task)) {
        closeWorkDetail()
    }
}
const deleteBackendWork = (work: BackendWork) => {
    const taskId = getWorkTaskId(work)
    if (!taskId) return Promise.resolve()
    const category = getWorkCategory(work)
    if (category === 'digital_human') {
        return work.appCode === 'image_human'
            ? deleteImageHumanResult({ id: taskId, task_id: taskId })
            : deleteAigcDigitalHumanResult({ id: taskId, task_id: taskId })
    }
    if (category === 'video') return deleteAigcVideoResult({ id: taskId, task_id: taskId })
    return deleteAigcImageResult({ id: taskId, task_id: taskId })
}
const deleteWork = async (work: BackendWork) => {
    if (!canDeleteWork(work)) return
    if (!userStore.isLogin) return
    try {
        if (work.source === 'backend') {
            await deleteBackendWork(work)
        }
        removeWorkFromCreateList(work)
        feedback.msgSuccess('删除成功')
        await nextTick()
        jumpToLatestWork()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '删除失败')
    }
}
const getWorkTypeLabel = (work: Pick<BackendWork, 'category' | 'mode'>) => {
    const category = getWorkCategory(work)
    if (category === 'digital_human') return '数字人'
    return category === 'video' ? '视频生成' : '图片生成'
}
const getWorkConfigItems = (work: Pick<BackendWork, 'mode' | 'category' | 'appCode' | 'model' | 'count' | 'ratio' | 'resolution' | 'duration' | 'quality'>) => {
    const normalizedOptions = normalizeAiCreateOptionState(work)
    if (getWorkCategory(work) === 'digital_human') {
        return [work.appCode === 'image_human' ? '全驱动数字人' : '数字人合成', normalizedOptions.model, normalizedOptions.ratio, normalizedOptions.duration || normalizedOptions.quality].filter(Boolean)
    }
    return work.mode === 'video'
        ? [normalizedOptions.model, normalizedOptions.ratio, normalizedOptions.resolution, normalizedOptions.duration]
        : [normalizedOptions.model, normalizedOptions.count, normalizedOptions.ratio, normalizedOptions.resolution]
}
const getWorkPromptTitle = (work: Pick<BackendWork, 'prompt'>) => String(work.prompt || '未命名创作')
const referenceStackCardWidth = 32
const referenceStackOffset = 12
const referenceStackEndGap = 10
const getReferenceStackStyle = (work: Pick<BackendWork, 'referenceImage' | 'referenceImages'>) => {
    const count = getWorkReferenceImages(work).length
    return {
        width: `${Math.max(44, referenceStackCardWidth + Math.max(0, count - 1) * referenceStackOffset + referenceStackEndGap)}px`
    }
}
const getReferenceStackItemStyle = (index: number) => ({
    transform: `translateX(${index * referenceStackOffset}px) rotate(${index % 2 === 0 ? -5 : 5}deg)`,
    zIndex: index + 1
})
const selectGenerationFilter = (value: 'all' | AiGenerationMode) => {
    selectedGenerationFilter.value = value
    generationFilterOpen.value = false
    activeTab.value = ''
    activeType.value = value === 'all' ? '' : value
}

const syncComposerFromWork = (
    work: Pick<
        BackendWork,
        'prompt' | 'mode' | 'model' | 'count' | 'ratio' | 'resolution' | 'duration' | 'quality' | 'referenceFileName' | 'referenceImage' | 'referenceImages'
    >
) => {
    prompt.value = work.prompt
    generationMode.value = work.mode
    optionState.value = normalizeAiCreateOptionState(work)
    uploadedFileName.value = work.referenceFileName
    uploadedPreviewUrl.value = getWorkReferenceImage(work)
    uploadedReferenceUri.value = work.referenceImages?.[0] || ''
    uploadedAssets.value.forEach(revokeUploadedAsset)
    uploadedAssets.value = getWorkReferenceImages(work).map((url, index) => ({
        id: `work-${Date.now()}-${index}`,
        name: index === 0 ? (work.referenceFileName || '已上传参考图') : `参考图 ${index + 1}`,
        url,
        uri: url,
        isObjectUrl: false
    }))
}

const syncComposerFromDraft = () => {
    const draftValue = draft.value
    if (!draftValue) return

    const work = appendWork(draftValue)
    syncComposerFromWork(work)
    activeTab.value = ''
    activeType.value = ''
    setDraft(null)
}

const buildPayload = (task?: string): AiCreateDraft => ({
    task: task || `${Date.now()}`,
    prompt: prompt.value.trim(),
    mode: generationMode.value,
    model: optionState.value.model,
    count: optionState.value.count,
    ratio: optionState.value.ratio,
    resolution: optionState.value.resolution,
    duration: optionState.value.duration,
    quality: optionState.value.quality,
    referenceFileName: uploadedFileName.value,
    referenceImage: uploadedPreviewUrl.value,
    referenceImages: uploadedReferenceUri.value ? [uploadedReferenceUri.value] : []
})

const syncAigcSelection = () => {
    if (!channels.value.length) return
    if (!channels.value.some((item) => item.value === selectedChannelCode.value)) {
        selectedChannelCode.value = aigcOptionConfig.value.defaults?.channel || channels.value[0].value
    }
    if (!channels.value.some((item) => item.value === selectedChannelCode.value)) {
        selectedChannelCode.value = channels.value[0].value
    }
    if (!qualities.value.some((item) => item.value === optionState.value.resolution)) {
        optionState.value.resolution = aigcOptionConfig.value.defaults?.quality || qualities.value[0]?.value || optionState.value.resolution
    }
    if (!qualities.value.some((item) => item.value === optionState.value.resolution)) {
        optionState.value.resolution = qualities.value[0]?.value || optionState.value.resolution
    }
    if (!ratios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = aigcOptionConfig.value.defaults?.ratio || ratios.value[0]?.value || optionState.value.ratio
    }
    if (!ratios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = ratios.value[0]?.value || optionState.value.ratio
    }
    optionState.value.count = `${selectedQuantity.value}张`
}

const syncAigcVideoSelection = () => {
    if (!videoChannels.value.length) return
    if (!videoChannels.value.some((item) => item.value === selectedVideoChannelCode.value)) {
        selectedVideoChannelCode.value = aigcVideoOptionConfig.value.defaults?.channel || videoChannels.value[0].value
    }
    if (!videoChannels.value.some((item) => item.value === selectedVideoChannelCode.value)) {
        selectedVideoChannelCode.value = videoChannels.value[0].value
    }
    if (!videoDurations.value.includes(optionState.value.duration)) {
        const defaultQuality = videoQualities.value.find((item) => item.value === aigcVideoOptionConfig.value.defaults?.quality)
        optionState.value.resolution = defaultQuality?.resolution || videoResolutions.value[0] || optionState.value.resolution
        optionState.value.duration = defaultQuality?.duration || videoDurations.value[0] || optionState.value.duration
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
    if (!videoRatios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = videoRatios.value[0]?.value || optionState.value.ratio
    }
    optionState.value.model = currentVideoChannel.value?.label || 'Grok Video（xAIQ）'
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
        optionState.value.duration = defaultQuality?.duration || optionState.value.duration
        optionState.value.ratio = defaults.ratio || optionState.value.ratio
        syncAigcVideoSelection()
    } catch (error) {
        console.warn('load aigc video config failed', error)
    }
}

const getBackendWorkStatus = (status: string): AiCreateStatus => (
    status === 'running' || status === 'pending' ? 'creating' : 'created'
)

const normalizeBackendTimestamp = (value: unknown) => {
    if (typeof value === 'number' && value > 0) return value > 100000000000 ? value : value * 1000
    if (typeof value === 'string' && value.trim()) {
        const numeric = Number(value)
        if (Number.isFinite(numeric) && numeric > 0) return numeric > 100000000000 ? numeric : numeric * 1000
        const parsed = Date.parse(value)
        if (Number.isFinite(parsed)) return parsed
    }
    return 0
}

const getBackendTimestamp = (item: any) => {
    const firstResult = Array.isArray(item?.results) ? item.results[0] || {} : {}
    return normalizeBackendTimestamp(
        item.create_time
        || item.update_time
        || item.finish_time
        || item.created_at
        || item.updated_at
        || item.create_at
        || item.createTime
        || firstResult.create_time
        || firstResult.update_time
        || firstResult.finish_time
        || firstResult.created_at
        || firstResult.updated_at
    )
}

const normalizeBackendStatus = (status: unknown) => {
    const value = String(status || '').toLowerCase()
    if (['queued', 'created', 'submitted'].includes(value)) return 'pending'
    if (['processing'].includes(value)) return 'running'
    if (['completed', 'succeeded'].includes(value)) return 'success'
    if (['cancelled'].includes(value)) return 'canceled'
    return value || 'pending'
}

const normalizeAigcImageUrl = (url: unknown) => {
    let raw = String(url || '').trim()
    if (!raw) return ''
    raw = raw.replace(/\\/g, '/')
    if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('blob:')) {
        return raw
    }
    const normalized = normalizeFileUrl(raw)
    const apiUrl = String(getApiUrl() || '').replace(/\/+$/, '')
    if (apiUrl && normalized.startsWith('/uploads/')) {
        return `${apiUrl}${normalized}`
    }
    return normalized
}

const normalizeResultImage = (item: any) => {
    const firstResult = Array.isArray(item.results)
        ? item.results.find((result: any) => result?.image_url || result?.url || result?.image_uri || result?.image)
        : null
    return normalizeAigcImageUrl(
        item.image_url
        || item.url
        || item.image
        || item.file_url
        || item.cover_url
        || item.origin_url
        || item.download_url
        || item.image_uri
        || firstResult?.image_url
        || firstResult?.url
        || firstResult?.image
        || firstResult?.file_url
        || firstResult?.cover_url
        || firstResult?.origin_url
        || firstResult?.download_url
        || firstResult?.image_uri
        || ''
    )
}

const getFirstDigitalHumanResult = (item: any) => Array.isArray(item.results)
    ? item.results.find((result: any) => result?.video_url || result?.video_uri || result?.url || result?.cover_url)
    : null

const normalizeDigitalHumanVideo = (item: any) => {
    const firstResult = getFirstDigitalHumanResult(item)
    return normalizeAigcImageUrl(
        item.video_url
        || item.video
        || item.media_url
        || item.media_path
        || item.download_url
        || item.origin_url
        || item.url
        || item.file_url
        || item.video_uri
        || firstResult?.video_url
        || firstResult?.video
        || firstResult?.media_url
        || firstResult?.media_path
        || firstResult?.download_url
        || firstResult?.origin_url
        || firstResult?.url
        || firstResult?.file_url
        || firstResult?.video_uri
        || ''
    )
}

const normalizeDigitalHumanCover = (item: any) => {
    const firstResult = getFirstDigitalHumanResult(item)
    return normalizeAigcImageUrl(
        item.cover_url
        || item.cover
        || item.image_url
        || item.image
        || item.cover_uri
        || firstResult?.cover_url
        || firstResult?.cover
        || firstResult?.image_url
        || firstResult?.image
        || firstResult?.cover_uri
        || ''
    )
}

const normalizeVideoCover = (item: any, fallback = '') => {
    const firstResult = getFirstDigitalHumanResult(item)
    return normalizeAigcImageUrl(
        item.cover_url
        || item.cover_uri
        || item.cover
        || item.poster_url
        || item.poster_uri
        || item.poster
        || item.thumb_url
        || item.thumb_uri
        || item.thumbnail_url
        || item.thumbnail_uri
        || item.thumbnail
        || item.image_url
        || item.image_uri
        || item.image
        || firstResult?.cover_url
        || firstResult?.cover_uri
        || firstResult?.cover
        || firstResult?.poster_url
        || firstResult?.poster_uri
        || firstResult?.poster
        || firstResult?.thumb_url
        || firstResult?.thumb_uri
        || firstResult?.thumbnail_url
        || firstResult?.thumbnail_uri
        || firstResult?.thumbnail
        || firstResult?.image_url
        || firstResult?.image_uri
        || firstResult?.image
        || fallback
        || ''
    )
}

const normalizeReferenceImages = (value: any): string[] => {
    if (Array.isArray(value)) {
        return value
            .map((item) => {
                if (typeof item === 'string') return item
                return item?.uri || item?.url || item?.path || item?.image || ''
            })
            .filter(Boolean)
    }
    if (typeof value === 'string') {
        const raw = value.trim()
        if (!raw) return []
        try {
            return normalizeReferenceImages(JSON.parse(raw))
        } catch (error) {
            return [raw]
        }
    }
    if (value && typeof value === 'object') {
        return normalizeReferenceImages(Object.values(value))
    }
    return []
}

const getWorkReferenceImages = (work: Pick<BackendWork, 'referenceImage' | 'referenceImages'>) => {
    const images = [
        ...(work.referenceImages || []).map((item) => normalizeAigcImageUrl(item)),
        work.referenceImage || ''
    ].filter(Boolean)
    return Array.from(new Set(images))
}

const getWorkReferenceImage = (work: Pick<BackendWork, 'referenceImage' | 'referenceImages'>) => getWorkReferenceImages(work)[0] || ''

const mapBackendWork = (item: any, index: number): BackendWork => {
    const backendStatus = normalizeBackendStatus(item.status)
    const imageUrl = normalizeResultImage(item)
    const referenceImages = normalizeReferenceImages(
        item.reference_images
        || item.referenceImages
        || item.reference_image
        || item.referenceImage
    )
    const referenceImage = referenceImages[0] ? normalizeAigcImageUrl(referenceImages[0]) : ''
    return {
        task: String(item.task_id || item.id || index),
        id: String(item.task_id || item.id || index),
        prompt: item.prompt || '',
        mode: 'image',
        model: item.channel || item.model || currentChannel.value?.label || '通道选择',
        count: `${Number(item.quantity || 1)}张`,
        ratio: item.ratio || '1:1',
        resolution: item.quality || '1k',
        duration: '5s',
        quality: '1k标清',
        referenceFileName: referenceImages.length ? '参考图' : '',
        referenceImage,
        referenceImages,
        status: getBackendWorkStatus(backendStatus),
        backendStatus,
        error: item.error || '',
        source: 'backend',
        category: 'image',
        createdAt: getBackendTimestamp(item),
        seed: index,
        resultImage: imageUrl
    }
}

const mapBackendVideoWork = (item: any, index: number): BackendWork => {
    const backendStatus = normalizeBackendStatus(item.status)
    const videoUrl = normalizeDigitalHumanVideo(item)
    const referenceImages = normalizeReferenceImages(
        item.reference_images
        || item.referenceImages
        || item.reference_image
        || item.referenceImage
    )
    const referenceImage = referenceImages[0] ? normalizeAigcImageUrl(referenceImages[0]) : ''
    const fallbackCover = referenceImage
    const coverUrl = normalizeVideoCover(item, fallbackCover)
    const displayResolution = getVideoDisplayResolution(item.quality)
    const displayDuration = getVideoDisplayDuration(item.quality)
    return {
        task: String(item.task_id || item.id || index),
        id: `video-${item.task_id || item.id || index}`,
        prompt: item.prompt || '',
        mode: 'video',
        model: item.channel || item.model || currentVideoChannel.value?.label || 'Grok Video（xAIQ）',
        count: `${Number(item.quantity || 1)}条`,
        ratio: item.ratio || '16:9',
        resolution: displayResolution,
        duration: displayDuration || '6秒',
        quality: item.quality || '',
        referenceFileName: referenceImages.length ? '参考图' : '',
        referenceImage,
        referenceImages,
        status: getBackendWorkStatus(backendStatus),
        backendStatus,
        error: item.error || '',
        source: 'backend',
        category: 'video',
        createdAt: getBackendTimestamp(item),
        seed: index,
        resultImage: coverUrl,
        coverImage: coverUrl,
        resultVideo: videoUrl
    }
}

const mapDigitalHumanWork = (item: any, index: number, appCode: 'aigc_digital_human' | 'image_human' = 'aigc_digital_human'): BackendWork => {
    const rawId = String(item.task_id || item.id || index)
    const backendStatus = normalizeBackendStatus(item.status)
    const videoUrl = normalizeDigitalHumanVideo(item)
    const coverUrl = normalizeDigitalHumanCover(item) || normalizeAigcImageUrl(item.image_url || item.image_uri || '')
    const duration = Number(item.duration || 0)
    const isImageHuman = appCode === 'image_human'
    return {
        task: rawId,
        id: `${isImageHuman ? 'image-human' : 'digital-human'}-${rawId}`,
        prompt: item.script_text || item.prompt || item.title || '',
        mode: 'video',
        model: item.avatar_name || item.voice_name || item.channel || item.model || (isImageHuman ? '全驱动数字人' : '数字人合成'),
        count: '1条',
        ratio: item.ratio || '16:9',
        resolution: item.quality || '高清',
        duration: duration > 0 ? `${duration}s` : '',
        quality: item.quality || '',
        referenceFileName: '',
        referenceImage: '',
        referenceImages: [],
        status: getBackendWorkStatus(backendStatus),
        backendStatus,
        error: item.error || item.error_msg || item.fail_reason || '',
        source: 'backend',
        category: 'digital_human',
        appCode,
        createdAt: getBackendTimestamp(item),
        seed: index,
        resultImage: coverUrl,
        coverImage: coverUrl,
        resultVideo: videoUrl
    }
}

const silentRequestOptions = { suppressErrorMessage: true }

const fetchBackendList = async (loader: () => Promise<any>, label: string): Promise<any[]> => {
    try {
        const list = await loader()
        return Array.isArray(list) ? list : []
    } catch (error) {
        console.warn(`refresh ${label} failed`, error)
        return []
    }
}

const hasActiveBackendWorks = (list: BackendWork[] = backendWorks.value) =>
    list.some((work) => ['pending', 'running'].includes(work.backendStatus || '') || work.status === 'creating')

const stopBackendRefreshPolling = () => {
    if (!backendRefreshTimer) return
    clearInterval(backendRefreshTimer)
    backendRefreshTimer = null
}

const startBackendRefreshPolling = () => {
    if (backendRefreshTimer || !userStore.isLogin) return
    backendRefreshTimer = setInterval(() => {
        if (!hasActiveBackendWorks([...backendWorks.value, ...optimisticBackendWorks.value])) {
            stopBackendRefreshPolling()
            return
        }
        refreshBackendWorks()
    }, 5000)
}

const syncBackendRefreshPolling = () => {
    if (!userStore.isLogin || !hasActiveBackendWorks([...backendWorks.value, ...optimisticBackendWorks.value])) {
        stopBackendRefreshPolling()
        return
    }
    startBackendRefreshPolling()
}

const refreshBackendWorks = async () => {
    if (!userStore.isLogin) {
        backendWorks.value = []
        optimisticBackendWorks.value = []
        stopBackendRefreshPolling()
        return
    }
    if (isRefreshingBackendWorks) return
    isRefreshingBackendWorks = true
    try {
        const [
            resultList,
            taskList,
            videoResultList,
            videoTaskList,
            digitalHumanResultList,
            digitalHumanTaskList,
            imageHumanResultList,
            imageHumanTaskList
        ] = await Promise.all([
            fetchBackendList(getAigcImageResults, 'aigc image results'),
            fetchBackendList(getAigcImageTasks, 'aigc image tasks'),
            fetchBackendList(getAigcVideoResults, 'aigc video results'),
            fetchBackendList(getAigcVideoTasks, 'aigc video tasks'),
            fetchBackendList(getAigcDigitalHumanResults, 'digital human results'),
            fetchBackendList(getAigcDigitalHumanTasks, 'digital human tasks'),
            fetchBackendList(() => getImageHumanResults(undefined, silentRequestOptions), 'image human results'),
            fetchBackendList(() => getImageHumanTasks(undefined, silentRequestOptions), 'image human tasks')
        ])
        const map = new Map<string, BackendWork>()
        ;(Array.isArray(taskList) ? taskList : []).forEach((item, index) => {
            const work = mapBackendWork(item, index)
            map.set(work.id, work)
        })
        ;(Array.isArray(resultList) ? resultList : []).forEach((item, index) => {
            const work = mapBackendWork(item, index)
            map.set(work.id, { ...(map.get(work.id) || {}), ...work })
        })
        ;(Array.isArray(videoTaskList) ? videoTaskList : []).forEach((item, index) => {
            const work = mapBackendVideoWork(item, index)
            map.set(work.id, work)
        })
        ;(Array.isArray(videoResultList) ? videoResultList : []).forEach((item, index) => {
            const work = mapBackendVideoWork(item, index)
            map.set(work.id, { ...(map.get(work.id) || {}), ...work })
        })
        ;(Array.isArray(digitalHumanTaskList) ? digitalHumanTaskList : []).forEach((item, index) => {
            const work = mapDigitalHumanWork(item, index)
            map.set(work.id, work)
        })
        ;(Array.isArray(digitalHumanResultList) ? digitalHumanResultList : []).forEach((item, index) => {
            const work = mapDigitalHumanWork(item, index)
            map.set(work.id, { ...(map.get(work.id) || {}), ...work })
        })
        ;(Array.isArray(imageHumanTaskList) ? imageHumanTaskList : []).forEach((item, index) => {
            const work = mapDigitalHumanWork(item, index, 'image_human')
            map.set(work.id, work)
        })
        ;(Array.isArray(imageHumanResultList) ? imageHumanResultList : []).forEach((item, index) => {
            const work = mapDigitalHumanWork(item, index, 'image_human')
            map.set(work.id, { ...(map.get(work.id) || {}), ...work })
        })
        backendWorks.value = Array.from(map.values())
        optimisticBackendWorks.value = optimisticBackendWorks.value.filter((localItem) => !map.has(localItem.id))
    } catch (error) {
        console.warn('refresh aigc works failed', error)
    } finally {
        isRefreshingBackendWorks = false
        syncBackendRefreshPolling()
    }
}

const addOptimisticBackendWork = (taskId: unknown, status: unknown = 'running', quantity = 1) => {
    const id = String(taskId || '')
    if (!id) return
    const row = mapBackendWork({
        id,
        task_id: id,
        prompt: prompt.value.trim(),
        reference_images: uploadedReferenceUri.value ? [uploadedReferenceUri.value] : [],
        channel: selectedChannelCode.value || currentChannel.value?.value || optionState.value.model,
        quality: optionState.value.resolution,
        ratio: optionState.value.ratio,
        quantity,
        status,
        create_time: Math.floor(Date.now() / 1000)
    }, optimisticBackendWorks.value.length)
    optimisticBackendWorks.value = [
        row,
        ...optimisticBackendWorks.value.filter((item) => item.id !== row.id)
    ]
    syncBackendRefreshPolling()
}

const addOptimisticBackendVideoWork = (taskId: unknown, status: unknown = 'running') => {
    const id = String(taskId || '')
    if (!id) return
    const row = mapBackendVideoWork({
        id,
        task_id: id,
        prompt: prompt.value.trim(),
        reference_images: uploadedReferenceUri.value ? [uploadedReferenceUri.value] : [],
        channel: selectedVideoChannelCode.value || currentVideoChannel.value?.value || 'grok_video_xaiq',
        quality: currentVideoQuality.value?.value || optionState.value.quality,
        ratio: optionState.value.ratio,
        quantity: 1,
        status,
        create_time: Math.floor(Date.now() / 1000)
    }, optimisticBackendWorks.value.length)
    optimisticBackendWorks.value = [
        row,
        ...optimisticBackendWorks.value.filter((item) => item.id !== row.id)
    ]
    syncBackendRefreshPolling()
}

const addOptimisticDigitalHumanWork = (
    taskId: unknown,
    appCode: 'aigc_digital_human' | 'image_human' = 'aigc_digital_human',
    title = ''
) => {
    const id = String(taskId || '')
    if (!id) return
    const row = mapDigitalHumanWork({
        id,
        task_id: id,
        title: title || (appCode === 'image_human' ? '全驱动数字人' : '数字人合成'),
        status: 'running',
        create_time: Math.floor(Date.now() / 1000)
    }, optimisticBackendWorks.value.length, appCode)
    optimisticBackendWorks.value = [
        row,
        ...optimisticBackendWorks.value.filter((item) => item.id !== row.id)
    ]
    syncBackendRefreshPolling()
}

const getCards = (work: BackendWork): WorkCard[] => {
    if (getWorkCategory(work) === 'digital_human' && (work.resultVideo || work.resultImage)) {
        return [{ id: `${work.id}-result`, image: work.resultImage || '', video: work.resultVideo || '', alt: '数字人合成结果', ratio: work.ratio }]
    }
    if (getWorkCategory(work) === 'video' && (work.resultVideo || work.resultImage)) {
        return [{ id: `${work.id}-result`, image: work.resultImage || '', video: work.resultVideo || '', alt: '视频生成结果', ratio: work.ratio }]
    }
    const image = work.resultImage
    if (image) {
        return [{ id: `${work.id}-result`, image, alt: '生成结果' }]
    }
    if (work.source === 'backend') {
        return [{ id: `${work.id}-status`, image: '', alt: getWorkStatusText(work) }]
    }
    return getAiCreateCards(work)
}
const getWorkDownloadUrl = (work: BackendWork | null, preferred = '') => {
    if (!work) return preferred
    const cardUrl = getCards(work).map((card) => card.video || card.image).find(Boolean) || ''
    return preferred || work.resultVideo || work.resultImage || work.coverImage || cardUrl || ''
}
const getWorkDownloadHref = (work: BackendWork | null, preferred = '') => resolvePcDownloadUrl(getWorkDownloadUrl(work, preferred))
const getCardStyle = (card: WorkCard) => {
    if (!card.video) return undefined
    const [width, height] = String(card.ratio || '16:9').split(':').map((item) => Number(item))
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
        return { aspectRatio: '16 / 9' }
    }
    return { aspectRatio: `${width} / ${height}` }
}
const openWorkDetail = (work: BackendWork, image = '', video = '') => {
    detailWork.value = work
    detailImage.value = image || work.resultImage || ''
    detailVideo.value = video || work.resultVideo || ''
}
const closeWorkDetail = () => {
    detailWork.value = null
    detailImage.value = ''
    detailVideo.value = ''
}
const openReferencePreview = (work: BackendWork, targetImage = '', index = 0) => {
    const images = getWorkReferenceImages(work)
    const safeIndex = images.findIndex((item) => item === targetImage)
    const nextIndex = safeIndex >= 0 ? safeIndex : Math.max(0, Math.min(index, images.length - 1))
    const image = images[nextIndex] || targetImage || getWorkReferenceImage(work)
    if (!image) return
    referencePreviewImages.value = images.length ? images : [image]
    referencePreviewIndex.value = nextIndex
    referencePreviewBaseTitle.value = work.referenceFileName || '参考图'
    referencePreviewImage.value = image
    referencePreviewTitle.value = `${referencePreviewBaseTitle.value}${referencePreviewImages.value.length > 1 ? ` ${nextIndex + 1}/${referencePreviewImages.value.length}` : ''}`
}
const closeReferencePreview = () => {
    referencePreviewImage.value = ''
    referencePreviewTitle.value = ''
    referencePreviewImages.value = []
    referencePreviewIndex.value = 0
    referencePreviewBaseTitle.value = '参考图'
}
const syncReferencePreviewByIndex = () => {
    const images = referencePreviewImages.value
    if (!images.length) return
    const index = ((referencePreviewIndex.value % images.length) + images.length) % images.length
    referencePreviewIndex.value = index
    referencePreviewImage.value = images[index]
    referencePreviewTitle.value = `${referencePreviewBaseTitle.value}${images.length > 1 ? ` ${index + 1}/${images.length}` : ''}`
}
const showPreviousReferencePreview = () => {
    if (referencePreviewImages.value.length <= 1) return
    referencePreviewIndex.value -= 1
    syncReferencePreviewByIndex()
}
const showNextReferencePreview = () => {
    if (referencePreviewImages.value.length <= 1) return
    referencePreviewIndex.value += 1
    syncReferencePreviewByIndex()
}
const getWorkTaskId = (work: BackendWork) => {
    const directId = Number(work.task || work.id || 0)
    if (Number.isFinite(directId) && directId > 0) return directId
    const match = String(work.task || work.id || '').match(/\d+/g)
    const parsed = Number(match?.[match.length - 1] || 0)
    return Number.isFinite(parsed) && parsed > 0 ? parsed : 0
}
const primeCardVideoFrame = (event: Event) => {
    const video = event.currentTarget as HTMLVideoElement | null
    if (!video) return
    try {
        if (video.readyState > 0 && video.currentTime < 0.05) video.currentTime = 0.05
    } catch {}
}
const playCardVideo = (event: Event) => {
    const video = event.currentTarget as HTMLVideoElement | null
    video?.play?.().catch(() => {})
}
const pauseCardVideo = (event: Event) => {
    const video = event.currentTarget as HTMLVideoElement | null
    if (!video) return
    video.pause()
    try {
        video.currentTime = 0.05
    } catch {}
}
const getDownloadName = (work: BackendWork | null, image: string) => {
    const id = work?.task || work?.id || Date.now()
    const category = work ? getWorkCategory(work) : 'image'
    const ext = getPcDownloadExtension(image, category === 'video' || category === 'digital_human' ? 'mp4' : 'png')
    return `aigc-${id}.${ext}`
}
const handleDownloadClick = (event: MouseEvent) => {
    if (userStore.isLogin) return
    event.preventDefault()
}
const isWorkCreating = (work: BackendWork) => ['pending', 'running'].includes(work.backendStatus || '') || work.status === 'creating'
const isWorkWithoutResult = (work: BackendWork) => work.source === 'backend' && !work.resultImage && !work.resultVideo
const getWorkStatusText = (work: BackendWork) => {
    if (work.backendStatus === 'pending') return '排队中'
    if (work.backendStatus === 'running') return '生成中'
    if (work.backendStatus === 'success') return '已完成'
    if (work.backendStatus === 'failed') return '创作失败'
    if (work.backendStatus === 'canceled') return '已取消'
    if (work.status === 'creating') return '创作中'
    return '已创作'
}
const getWorkStatusDescription = (work: BackendWork, index: number) => {
    if (work.backendStatus === 'pending') return '任务已创建，等待上游接口处理'
    if (work.backendStatus === 'running') return getWorkCategory(work) === 'digital_human' ? '数字人视频正在合成' : getWorkCategory(work) === 'video' ? '视频正在生成' : `第 ${index + 1} 张正在生成`
    if (work.backendStatus === 'success') return getWorkCategory(work) === 'digital_human' || getWorkCategory(work) === 'video' ? '任务已完成，暂未拿到视频' : '任务已完成，暂未拿到结果图'
    if (work.backendStatus === 'failed') return work.error || '上游生成失败，请调整参数后重试'
    if (work.backendStatus === 'canceled') return '任务已取消'
    return getWorkCategory(work) === 'digital_human' ? '数字人视频正在合成' : `第 ${index + 1} 张正在生成`
}
const formatTime = (timestamp: number) => {
    const date = new Date(timestamp)
    if (Number.isNaN(date.getTime())) return '最近作品'
    return new Intl.DateTimeFormat('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    }).format(date)
}
const goHome = () => router.push('/')
const togglePopover = (key: Exclude<PopoverKey, ''>) => { activePopover.value = activePopover.value === key ? '' : key }
const closeMenus = () => { activePopover.value = ''; generationFilterOpen.value = false }
const handleReferencePreviewKeydown = (event: KeyboardEvent) => {
    if (!referencePreviewImage.value) return
    if (event.key === 'ArrowLeft') {
        event.preventDefault()
        showPreviousReferencePreview()
        return
    }
    if (event.key === 'ArrowRight') {
        event.preventDefault()
        showNextReferencePreview()
        return
    }
    if (event.key === 'Escape') {
        event.preventDefault()
        closeReferencePreview()
    }
}
const handleCreatePageKeydown = (event: KeyboardEvent) => {
    handleReferencePreviewKeydown(event)
    if (['ArrowUp', 'ArrowDown', 'PageUp', 'PageDown', 'Home', 'End', 'Space'].includes(event.key)) {
        cancelInitialLatestWorkJump()
    }
}
const triggerUpload = () => {
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    fileInputRef.value?.click()
}
const syncPrimaryUploadedAsset = () => {
    const first = uploadedAssets.value[0]
    uploadedFileName.value = first?.name || ''
    uploadedPreviewUrl.value = first?.url || ''
    uploadedReferenceUri.value = first?.uri || ''
}
const revokeUploadedAsset = (asset: UploadedAsset) => {
    if (asset.isObjectUrl && asset.url.startsWith('blob:')) URL.revokeObjectURL(asset.url)
}
const clearReference = () => {
    uploadedAssets.value.forEach(revokeUploadedAsset)
    uploadedAssets.value = []
    uploadedFileName.value = ''
    uploadedPreviewUrl.value = ''
    uploadedReferenceUri.value = ''
    if (fileInputRef.value) fileInputRef.value.value = ''
}
const uploadSingleFile = async (file: File, placeholderAsset: UploadedAsset) => {
    try {
        const res: any = await uploadAppImage({ file })
        const uri = res?.uri || res?.url || res?.path || ''
        if (!uri) throw new Error('参考图上传失败')
        uploadedAssets.value = uploadedAssets.value.map((asset) => (
            asset.id === placeholderAsset.id
                ? { ...asset, uri, uploading: false }
                : asset
        ))
        syncPrimaryUploadedAsset()
        if (!prompt.value.trim()) prompt.value = `基于上传图片“${file.name}”生成高质量${generationMode.value === 'image' ? '图片' : '视频'}内容`
    } catch (error: any) {
        URL.revokeObjectURL(placeholderAsset.url)
        uploadedAssets.value = uploadedAssets.value.filter((asset) => asset.id !== placeholderAsset.id)
        syncPrimaryUploadedAsset()
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图上传失败')
    }
}
const addUploadedFiles = async (files: File[]) => {
    if (!files.length) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    const pendingAssets = files.map((file, index) => ({
        file,
        asset: {
            id: `uploading-${Date.now()}-${index}-${Math.random().toString(36).slice(2)}`,
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
    syncPrimaryUploadedAsset()
    uploading.value = true
    try {
        for (const { file, asset } of pendingAssets) {
            await uploadSingleFile(file, asset)
        }
    } finally {
        uploading.value = false
    }
}
const removeUploadedAsset = (id: string) => {
    const target = uploadedAssets.value.find((item) => item.id === id)
    if (target) revokeUploadedAsset(target)
    uploadedAssets.value = uploadedAssets.value.filter((item) => item.id !== id)
    syncPrimaryUploadedAsset()
    if (!uploadedAssets.value.length && fileInputRef.value) fileInputRef.value.value = ''
}
const handleUpload = async (event: Event) => {
    const files = Array.from((event.target as HTMLInputElement).files || [])
    await addUploadedFiles(files)
    if (fileInputRef.value) fileInputRef.value.value = ''
}
const focusComposer = async () => {
    await nextTick()
    window.setTimeout(() => composerRef.value?.focusTextarea?.(), 180)
}
const getRouteQueryString = (value: unknown) => Array.isArray(value) ? String(value[0] || '') : String(value || '')
const shouldAutoScrollToLatestWork = () => {
    const scroll = getRouteQueryString(route.query.scroll)
    const type = getRouteQueryString(route.query.type)
    return scroll === 'latest' || type === 'digital_human'
}
const primeLatestWorkFromRoute = () => {
    if (!shouldAutoScrollToLatestWork()) return
    const taskId = getRouteQueryString(route.query.task_id)
    if (!taskId) return
    const appCode = getRouteQueryString(route.query.app_code) === 'image_human' ? 'image_human' : 'aigc_digital_human'
    addOptimisticDigitalHumanWork(taskId, appCode, getRouteQueryString(route.query.title))
}
const getDocumentBottomScrollTop = () => Math.max(
    document.documentElement.scrollHeight,
    document.body.scrollHeight
) - window.innerHeight
const clearLatestWorkAlignTimers = () => {
    latestWorkAlignTimers.forEach((timer) => clearTimeout(timer))
    latestWorkAlignTimers = []
}
const cancelInitialLatestWorkJump = () => {
    pendingInitialLatestWorkJump = false
    clearLatestWorkAlignTimers()
}
const scheduleLatestWorkBottomScroll = (behavior: ScrollBehavior = 'auto') => {
    clearLatestWorkAlignTimers()
    const scroll = () => {
        window.requestAnimationFrame(() => {
            window.scrollTo({ top: Math.max(getDocumentBottomScrollTop(), 0), behavior })
        })
    }
    nextTick(() => {
        scroll()
        ;[80, 180, 360, 720, 1200, 2000].forEach((delay) => {
            latestWorkAlignTimers.push(window.setTimeout(scroll, delay))
        })
    })
}
const scrollToLatestWork = async () => {
    await nextTick()
    scheduleLatestWorkBottomScroll('smooth')
}
const jumpToLatestWork = async () => {
    await nextTick()
    scheduleLatestWorkBottomScroll('auto')
}
const handlePageScroll = () => {
    composerRef.value?.collapseIfEmpty()
}
const handleWindowResize = () => {
    if (pendingInitialLatestWorkJump) jumpToLatestWork()
}
const submitPrompt = async () => {
    if (submitting.value) return
    if (!canGenerate.value) return
    if (!ensurePcLogin()) return
    if (generationMode.value === 'image') {
        syncAigcSelection()
        if (!currentChannel.value?.value) {
            feedback.msgError('暂无可用通道')
            return
        }
        submitting.value = true
        feedback.loading('正在提交生成任务...')
        try {
            const referenceImages = uploadedReferenceUri.value ? [uploadedReferenceUri.value] : []
            const res: any = await generateAigcImage({
                prompt: prompt.value.trim(),
                reference_images: referenceImages,
                ratio: optionState.value.ratio,
                quality: optionState.value.resolution,
                quantity: selectedQuantity.value,
                channel: selectedChannelCode.value,
                negative_prompt: ''
            })
            addOptimisticBackendWork(res?.task_id, res?.status || 'running', selectedQuantity.value)
            activeTab.value = ''
            activeType.value = ''
            feedback.msgSuccess(`已提交生成任务${selectedQuantity.value > 1 ? `，预计生成${selectedQuantity.value}张图片` : ''}`)
            await refreshCredits()
            await refreshBackendWorks()
            await scrollToLatestWork()
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
        feedback.msgError('暂无可用视频通道')
        return
    }
    if (!currentVideoRatio.value?.value) {
        feedback.msgError('请选择视频比例')
        return
    }
    submitting.value = true
    feedback.loading('正在提交视频生成任务...')
    try {
        const referenceImages = uploadedReferenceUri.value ? [uploadedReferenceUri.value] : []
        const res: any = await generateAigcVideo({
            prompt: prompt.value.trim(),
            reference_images: referenceImages,
            ratio: optionState.value.ratio,
            quality: currentVideoQuality.value?.value || optionState.value.quality,
            quantity: 1,
            channel: selectedVideoChannelCode.value || currentVideoChannel.value.value,
            negative_prompt: ''
        })
        addOptimisticBackendVideoWork(res?.task_id, res?.status || 'running')
        activeTab.value = ''
        activeType.value = ''
        feedback.msgSuccess('已提交视频生成任务')
        await refreshCredits()
        await refreshBackendWorks()
        await scrollToLatestWork()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交视频生成任务失败')
    } finally {
        feedback.closeLoading()
        submitting.value = false
    }
}
const { lockFn: submitPromptLock, isLock: isSubmitPromptLocked } = useLockFn(submitPrompt)
const goEditDigitalHumanWork = (work: BackendWork) => router.push(
    work.appCode === 'image_human'
        ? { path: '/ai/avatar', query: { tab: 'image_human' } }
        : {
            path: '/ai/avatar',
            query: {
                edit_task_id: String(getWorkTaskId(work) || work.task || work.id || '')
            }
        }
)
const loadWorkToComposer = async (work: BackendWork) => {
    if (getWorkCategory(work) === 'digital_human') {
        await goEditDigitalHumanWork(work)
        return
    }
    syncComposerFromWork(work)
    await focusComposer()
}
const rerunWork = async (work: BackendWork) => {
    if (getWorkCategory(work) === 'digital_human') {
        await goEditDigitalHumanWork(work)
        return
    }
    syncComposerFromWork(work)
    await submitPromptLock()
}
const handleSidebar = async (key: SidebarKey) => {
    activeSidebar.value = key
    if (key === 'inspiration') return void router.push(buildSidebarRouteLocation(key))
    if (key === 'create') {
        activeTab.value = ''
        activeType.value = ''
        await jumpToLatestWork()
        return
    }
    if (key === 'assets' && !ensurePcLogin({ redirect: buildSidebarRouteLocation(key).path || route.fullPath })) {
        activeSidebar.value = 'create'
        return
    }
    await router.push(buildSidebarRouteLocation(key))
}

const unlockCreatePageScroll = () => {
    if (typeof document === 'undefined') return
    document.documentElement.classList.add('ai-create-scrollable')
    document.body.classList.add('ai-create-scrollable')
    if (!detailWork.value) {
        document.documentElement.style.overflow = ''
        document.body.style.overflow = ''
    }
}

watch(draft, syncComposerFromDraft, { immediate: true })
watch(detailWork, (work) => {
    if (typeof document === 'undefined') return
    const locked = Boolean(work || referencePreviewImage.value)
    document.documentElement.classList.toggle('ai-create-scrollable', !locked)
    document.body.classList.toggle('ai-create-scrollable', !locked)
    document.documentElement.style.overflow = locked ? 'hidden' : ''
    document.body.style.overflow = locked ? 'hidden' : ''
    if (!locked) unlockCreatePageScroll()
})
watch(referencePreviewImage, (image) => {
    if (typeof document === 'undefined') return
    const locked = Boolean(detailWork.value || image)
    document.documentElement.classList.toggle('ai-create-scrollable', !locked)
    document.body.classList.toggle('ai-create-scrollable', !locked)
    document.documentElement.style.overflow = locked ? 'hidden' : ''
    document.body.style.overflow = locked ? 'hidden' : ''
    if (!locked) unlockCreatePageScroll()
})
watch(generationMode, (mode) => {
    if (mode === 'image') syncAigcSelection()
    if (mode === 'video') syncAigcVideoSelection()
})
watch(
    optionState,
    (next, previous) => {
        if (!previous) return
        if (generationMode.value === 'image') syncImageOptionSideEffects(next, previous)
        if (generationMode.value === 'video') syncVideoOptionSideEffects(next, previous)
    },
    { deep: true }
)
watch(
    [activeTab, activeType],
    () => {
        if (hasMountedCreatePage) cancelInitialLatestWorkJump()
    }
)
watch(
    () => filteredWorks.value.map((work) => `${work.id}:${work.backendStatus || work.status}:${work.resultImage || ''}:${work.resultVideo || ''}`).join('|'),
    (next, previous) => {
        if (pendingInitialLatestWorkJump && next && !previous) jumpToLatestWork()
    }
)
watch(
    () => route.query,
    (query) => {
        const type = getRouteQueryString(query.type)
        const status = getRouteQueryString(query.status)
        if (type === 'image' || type === 'video' || type === 'digital_human') {
            activeType.value = type
        }
        if (status === 'pending' || status === 'running' || status === 'success' || status === 'failed' || status === 'canceled') {
            activeTab.value = status
        } else if (status === '') {
            activeTab.value = ''
        }
        primeLatestWorkFromRoute()
        if (hasMountedCreatePage && shouldAutoScrollToLatestWork()) {
            pendingInitialLatestWorkJump = true
            jumpToLatestWork().finally(() => {
                pendingInitialLatestWorkJump = false
            })
        }
    },
    { immediate: true }
)
onMounted(() => {
    unlockCreatePageScroll()
    primeLatestWorkFromRoute()
    document.addEventListener('click', closeMenus)
    window.addEventListener('scroll', handlePageScroll, { passive: true })
    window.addEventListener('wheel', cancelInitialLatestWorkJump, { passive: true })
    window.addEventListener('touchmove', cancelInitialLatestWorkJump, { passive: true })
    window.addEventListener('resize', handleWindowResize)
    window.addEventListener('keydown', handleCreatePageKeydown)
    loadAigcConfig()
    loadAigcVideoConfig()
    refreshBackendWorks().finally(async () => {
        hasMountedCreatePage = true
        if (pendingInitialLatestWorkJump && filteredWorks.value.length) await jumpToLatestWork()
        pendingInitialLatestWorkJump = false
    })
})

watch(() => userStore.isLogin, (loggedIn) => {
    if (!loggedIn) {
        backendWorks.value = []
        optimisticBackendWorks.value = []
        stopBackendRefreshPolling()
        return
    }
    pendingInitialLatestWorkJump = true
    refreshBackendWorks().then(() => {
        if (pendingInitialLatestWorkJump && filteredWorks.value.length) return jumpToLatestWork()
        return undefined
    }).finally(() => {
        pendingInitialLatestWorkJump = false
    })
})
onBeforeUnmount(() => {
    stopBackendRefreshPolling()
    clearLatestWorkAlignTimers()
    document.removeEventListener('click', closeMenus)
    window.removeEventListener('scroll', handlePageScroll)
    window.removeEventListener('wheel', cancelInitialLatestWorkJump)
    window.removeEventListener('touchmove', cancelInitialLatestWorkJump)
    window.removeEventListener('resize', handleWindowResize)
    window.removeEventListener('keydown', handleCreatePageKeydown)
    document.documentElement.classList.remove('ai-create-scrollable')
    document.body.classList.remove('ai-create-scrollable')
    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
    uploadedAssets.value.forEach(revokeUploadedAsset)
})
</script>

<style lang="scss" scoped>
:global(html),
:global(body) {
    overflow: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}

:global(html.ai-create-scrollable),
:global(body.ai-create-scrollable) {
    overflow: auto !important;
}

:global(html::-webkit-scrollbar),
:global(body::-webkit-scrollbar) {
    width: 0;
    height: 0;
    background: transparent;
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

.page {
    position: relative;
    min-height: 100dvh;
    padding-bottom: 236px;
    background: #050505;
    color: #fff;
    overflow-x: hidden;
}

.page.is-empty {
    height: 100dvh;
    min-height: 100dvh;
    padding-bottom: 0;
    overflow: hidden;
}

.page:not(.is-empty).is-fit {
    height: 100vh;
    min-height: 100vh;
    padding-bottom: 0;
    overflow-y: hidden;
}

.page__bg,
.page__noise,
.page__stars {
    position: fixed;
    inset: 0;
    pointer-events: none;
    will-change: opacity;
}

.page__noise {
    background-image:
        radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.65) 0 1px, transparent 1.8px),
        radial-gradient(circle at 18% 32%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.6px),
        radial-gradient(circle at 34% 58%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.8px),
        radial-gradient(circle at 52% 10%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.5px),
        radial-gradient(circle at 72% 20%, rgba(255, 255, 255, 0.48) 0 1px, transparent 1.7px),
        radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.52) 0 1px, transparent 1.7px);
    opacity: 0.24;
}

.page__stars {
    opacity: 0.95;
    mix-blend-mode: screen;
}

.page__stars--near {
    background-image:
        radial-gradient(circle at 10% 22%, rgba(255, 255, 255, 0.95) 0 1.4px, transparent 2.2px),
        radial-gradient(circle at 36% 36%, rgba(255, 255, 255, 0.98) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 57% 60%, rgba(255, 255, 255, 0.9) 0 1.1px, transparent 1.8px),
        radial-gradient(circle at 82% 58%, rgba(255, 255, 255, 0.94) 0 1.25px, transparent 2px);
    animation: starTwinkle 4.8s ease-in-out infinite alternate;
}

.page__stars--far {
    background-image:
        radial-gradient(circle at 14% 10%, rgba(160, 203, 255, 0.8) 0 1px, transparent 1.8px),
        radial-gradient(circle at 44% 72%, rgba(178, 193, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 78% 42%, rgba(181, 220, 255, 0.75) 0 0.95px, transparent 1.6px),
        radial-gradient(circle at 88% 74%, rgba(255, 255, 255, 0.7) 0 1px, transparent 1.6px);
    animation: starTwinkle 6.2s ease-in-out infinite alternate-reverse;
}

.main {
    position: relative;
    z-index: 1;
    width: calc(100vw - 188px);
    margin-top: 56px;
    margin-left: 116px;
    margin-right: 72px;
    padding-bottom: 152px;
}

.main.is-empty {
    position: fixed;
    inset: 56px 72px 124px 116px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: auto;
    height: auto;
    min-height: 0;
    margin: 0;
    padding-bottom: 0;
    overflow: hidden;
}

.summary {
    display: block;
    width: 100%;
    height: 0;
    min-height: 0;
    margin: 0;
    padding: 0;
    background: transparent;
    border: 0;
}

.summary__filters {
    position: fixed;
    top: 56px;
    right: 40px;
    z-index: 21;
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

.generation-filter {
    position: relative;
}

.summary-filter {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 36px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    background: #1a1b20;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
}

.summary-filter img {
    width: 14px;
    height: 14px;
    opacity: 0.7;
}

.generation-filter__menu {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    min-width: 120px;
    padding: 6px;
    border-radius: 12px;
    background: rgba(21, 21, 21, 0.98);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.32);
}

.generation-filter__menu button {
    display: flex;
    align-items: center;
    width: 100%;
    height: 34px;
    padding: 0 10px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    cursor: pointer;
}

.generation-filter__menu button:hover,
.generation-filter__menu button.is-active {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.works {
    display: flex;
    flex-direction: column;
    gap: 0;
    width: 100%;
    max-width: none;
    margin-top: 0;
    padding-bottom: 0;
}

.page:not(.is-empty) .works {
    margin-top: 0;
}

.work {
    display: block;
    padding: 30px 0 34px;
    border: 0;
    background: transparent;
}

.work:first-child {
    padding-top: 0;
}

.work + .work {
    border-top: 1px solid rgba(255, 255, 255, 0.06);
}

.work__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-bottom: 14px;
}

.work__text {
    min-width: 0;
}

.work__title {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
    flex-wrap: nowrap;
}

.work__title .ref-stack {
    position: relative;
    display: inline-flex;
    width: 44px;
    height: 36px;
    flex: 0 0 auto;
    margin-right: 2px;
}

.work__title .ref-stack__item {
    position: absolute;
    left: 0;
    top: 0;
    display: block;
    width: 32px;
    height: 36px;
    border: 1px solid rgba(255, 255, 255, 0.72);
    border-radius: 8px;
    background: #151515;
    overflow: hidden;
    box-shadow: 0 8px 18px rgba(0, 0, 0, 0.24);
    transform-origin: center;
}

.work__title .ref-stack__item img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.work__text h2 {
    max-width: min(960px, calc(100vw - 360px));
    min-width: 0;
    margin: 0;
    overflow: hidden;
    color: #fff;
    font-size: 14px;
    font-weight: 700;
    line-height: 22px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.work__meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0;
    margin-top: 2px;
    color: #7f8a9a;
    font-size: 14px;
    line-height: 22px;
}

.work__meta span + span::before {
    content: '|';
    margin: 0 8px;
    color: #384151;
}

.grid,
.grid.is-1,
.grid.is-2,
.grid.is-3,
.grid.is-4 {
    display: flex;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 2px;
    width: auto;
    max-width: 100%;
    margin-top: 0;
    justify-content: start;
}

.card {
    position: relative;
    width: 176px;
    height: auto;
    overflow: hidden;
    border-radius: 0;
    background: #202127;
    flex: 0 0 auto;
    cursor: zoom-in;
}

.card img,
.card__video,
.card__placeholder {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.card__placeholder {
    aspect-ratio: 1 / 1;
    background: #202127;
}

.card__mask {
    display: none;
}

.card.is-pending img,
.card.is-pending video {
    filter: blur(5px) saturate(0.85);
    transform: scale(1.04);
    opacity: 0.82;
}

.card__state {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px;
    background: rgba(10, 10, 10, 0.58);
    text-align: center;
}

.card__state strong {
    font-size: 16px;
}

.card__state span:last-child {
    color: rgba(255, 255, 255, 0.68);
    font-size: 12px;
    line-height: 18px;
}

.spinner {
    width: 30px;
    height: 30px;
    border: 2px solid rgba(255, 255, 255, 0.18);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.work__footer {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 18px;
}

.ghost,
.primary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 36px;
    padding: 0 14px;
    border: 0;
    border-radius: 8px;
    background: #1d1e25;
    color: #fff;
    font-size: 14px;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
}

.ghost--icon {
    width: 36px;
    padding: 0;
}

.ghost--icon img {
    width: 16px;
    height: 16px;
}

.ghost--icon:hover {
    background: #272832;
}

.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    width: auto;
    min-height: 0;
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
    backdrop-filter: none;
}

.empty__placeholder {
    display: block;
    width: 240px;
    height: 240px;
    object-fit: cover;
    opacity: 0.58;
    filter: blur(0.2px);
    -webkit-mask-image: radial-gradient(circle, #000 48%, rgba(0, 0, 0, 0.86) 62%, rgba(0, 0, 0, 0.18) 78%, transparent 94%);
    mask-image: radial-gradient(circle, #000 48%, rgba(0, 0, 0, 0.86) 62%, rgba(0, 0, 0, 0.18) 78%, transparent 94%);
    -webkit-mask-size: 100% 100%;
    mask-size: 100% 100%;
    -webkit-mask-repeat: no-repeat;
    mask-repeat: no-repeat;
}

.empty p {
    margin: 0;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    line-height: 22px;
    text-align: center;
}

.work-detail,
.reference-preview {
    position: fixed;
    inset: 0;
    z-index: 80;
    background: rgba(10, 10, 12, 0.96);
    backdrop-filter: blur(14px);
}

.reference-preview {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}

.work-detail__panel {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    width: 100%;
    height: 100%;
}

.work-detail__media {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 0;
    padding: 20px 24px;
    background:
        radial-gradient(circle at top, rgba(255, 255, 255, 0.06), transparent 36%),
        #111114;
}

.work-detail__media-frame {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    padding: 56px 24px 32px;
}

.work-detail__media img,
.work-detail__media video,
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

.work-detail__content {
    display: flex;
    flex-direction: column;
    gap: 22px;
    height: 100%;
    min-height: 0;
    min-width: 0;
    padding: 20px 22px 22px;
    border-left: 1px solid rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.96);
    background:
        radial-gradient(circle at top left, rgba(255, 255, 255, 0.05), transparent 26%),
        linear-gradient(180deg, #171719 0%, #101012 100%);
    overflow-y: auto;
}

.work-detail__header {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.work-detail__author-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
}

.work-detail__author {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.work-detail__avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.94);
    font-size: 14px;
    font-weight: 700;
}

.work-detail__author-meta {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.work-detail__author-meta strong {
    color: rgba(255, 255, 255, 0.98);
    font-size: 16px;
    font-weight: 600;
    line-height: 1.2;
}

.work-detail__subline {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 18px;
    padding-bottom: 18px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.78);
    font-size: 12px;
}

.work-detail__close,
.reference-preview__close,
.reference-preview__nav {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    cursor: pointer;
}

.work-detail__close {
    position: absolute;
    top: 24px;
    left: 24px;
    z-index: 4;
    width: 42px;
    height: 42px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(17, 17, 19, 0.72);
    font-size: 24px;
    transition:
        border-color 0.2s ease,
        background 0.2s ease;
}

.work-detail__close:hover {
    border-color: rgba(255, 255, 255, 0.24);
    background: rgba(28, 28, 31, 0.94);
}

.work-detail__subline strong {
    color: rgba(255, 255, 255, 0.94);
    font-size: 13px;
    font-weight: 600;
}

.work-detail__section span {
    color: rgba(255, 255, 255, 0.5);
    font-size: 13px;
}

.work-detail__section {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
}

.work-detail__prompt {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-height: 0;
    padding: 2px 0 0;
}

.work-detail__prompt-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
}

.work-detail__prompt-head span {
    color: rgba(255, 255, 255, 0.94);
    font-size: 15px;
    font-weight: 600;
}

.work-detail__prompt-body {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding-right: 8px;
}

.work-detail__prompt-body p,
.work-detail__section p {
    margin: 0;
    color: rgba(255, 255, 255, 0.94);
    font-size: 15px;
    font-weight: 600;
    line-height: 2;
    white-space: pre-wrap;
    word-break: break-word;
}

.work-detail__config {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0;
    min-height: 16px;
    margin-top: 20px;
    margin-bottom: 18px;
    white-space: nowrap;
}

.work-detail__config-text {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.48);
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
}

.work-detail__config-text + .work-detail__config-text::before {
    content: '|';
    margin: 0 8px;
    color: rgba(255, 255, 255, 0.24);
}

.work-detail__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 12px;
    margin-top: auto;
}

.work-detail__download,
.work-detail__favorite {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex: 0 0 auto;
    width: 162px;
    height: 44px;
    padding: 0 20px;
    border: 0;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.work-detail__favorite {
    border: 0;
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.work-detail__download:hover,
.work-detail__favorite:hover,
.work-detail__favorite.is-active {
    background: rgba(255, 255, 255, 0.18);
    color: #fff;
}

.work-detail__download img,
.work-detail__favorite img {
    width: 16px;
    height: 16px;
}

.work-detail__download:hover img,
.work-detail__favorite:hover img,
.work-detail__favorite.is-active img {
    filter: none;
}

.ref-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 14px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.04);
}

.ref-card__thumbs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.ref-card__thumb {
    width: 48px;
    height: 48px;
    padding: 0;
    border: 0;
    border-radius: 14px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.06);
    cursor: pointer;
}

.ref-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.reference-preview {
    z-index: 96;
    flex-direction: column;
    gap: 14px;
    padding: 48px;
}

.reference-preview img {
    display: block;
    max-width: min(1120px, calc(100vw - 120px));
    max-height: calc(100vh - 150px);
    border-radius: 18px;
    object-fit: contain;
    background: #111;
}

.reference-preview span {
    max-width: min(720px, calc(100vw - 80px));
    overflow: hidden;
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.reference-preview__close {
    position: fixed;
    top: 34px;
    right: 40px;
    width: 44px;
    height: 44px;
    font-size: 28px;
}

.reference-preview__nav {
    position: fixed;
    top: 50%;
    width: 52px;
    height: 52px;
    font-size: 44px;
    transform: translateY(-50%);
}

.reference-preview__nav--prev {
    left: 42px;
}

.reference-preview__nav--next {
    right: 42px;
}

.composer {
    position: fixed;
    left: 50%;
    bottom: 24px;
    z-index: 20;
    display: block;
    width: min(1024px, calc(100vw - 260px));
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    backdrop-filter: none;
    transform: translateX(-50%);
}

.composer :deep(.ai-create-composer.is-collapsed) {
    width: min(640px, 100%);
    margin: 0 auto;
}

@keyframes spin {
    to {
        transform: rotate(360deg);
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

@media (max-width: 1100px) {
    .main {
        width: calc(100vw - 132px);
        margin-left: 108px;
        margin-right: 24px;
    }

    .summary__filters {
        right: 24px;
    }

    .work__title {
        flex-wrap: wrap;
    }

    .work__text h2 {
        max-width: 100%;
    }

    .grid,
    .grid.is-1,
    .grid.is-2,
    .grid.is-3,
    .grid.is-4 {
        width: 100%;
    }

    .card {
        width: min(176px, calc((100vw - 156px) / 2));
    }

    .composer {
        width: min(100%, calc(100vw - 148px));
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
        border-top: 1px solid rgba(255, 255, 255, 0.06);
        border-left: 0;
    }
}

@media (max-width: 760px) {
    .main {
        width: calc(100vw - 120px);
        margin-left: 96px;
        margin-right: 24px;
    }

    .summary__filters {
        top: 92px;
        right: 24px;
    }

    .card {
        width: calc(50vw - 64px);
        min-width: 130px;
    }

    .composer {
        width: calc(100vw - 120px);
        bottom: 14px;
    }

    .empty__placeholder {
        width: 180px;
        height: 180px;
    }

    .work-detail__actions {
        flex-direction: column;
        align-items: stretch;
    }

    .work-detail__download,
    .work-detail__favorite {
        width: 100%;
    }
}
</style>




