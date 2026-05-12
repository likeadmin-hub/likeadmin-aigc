<template>
    <div class="page">
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

        <main class="main">
            <section class="summary">
                <div class="summary__filters">
                    <div class="summary__tabs">
                        <button
                            v-for="item in statusTabs"
                            :key="item.value"
                            :class="['tab', { 'is-active': activeTab === item.value }]"
                            type="button"
                            @click="activeTab = item.value"
                        >
                            {{ item.label }} <span>{{ getStatusCount(item.value) }}</span>
                        </button>
                    </div>
                    <div class="type-tabs">
                        <button
                            v-for="item in typeTabs"
                            :key="item.value"
                            :class="['type-tab', { 'is-active': activeType === item.value }]"
                            type="button"
                            @click="activeType = item.value"
                        >
                            {{ item.label }} <span>{{ getTypeCount(item.value) }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <section ref="workListRef" class="works">
                <template v-if="filteredWorks.length">
                    <article v-for="work in pagedFilteredWorks" :key="work.id" class="work">
                        <div class="work__head">
                            <div class="work__tags">
                                <span :class="['chip', `is-${work.status}`, `is-backend-${work.backendStatus || work.status}`]">
                                    <i v-if="isWorkCreating(work)"></i>
                                    {{ getWorkStatusText(work) }}
                                </span>
                                <span class="chip chip--muted">{{ getWorkTypeLabel(work) }}</span>
                                <span class="work__time">{{ formatTime(work.createdAt) }}</span>
                            </div>

                            <div class="work__actions">
                                <button class="ghost" type="button" @click="loadWorkToComposer(work)">编辑</button>
                                <button
                                    v-if="work.status === 'creating' && work.source !== 'backend'"
                                    class="primary"
                                    type="button"
                                    @click="completeWork(work.task)"
                                >
                                    标记已创作
                                </button>
                                <button v-else-if="work.status !== 'creating'" class="primary" type="button" @click="rerunWork(work)">再次创作</button>
                            </div>
                        </div>

                        <div class="work__prompt">
                            <span>提示词</span>
                            <p>{{ work.prompt }}</p>
                        </div>

                        <div class="work__config">
                            <span v-for="item in getWorkConfigItems(work)" :key="`${work.id}-${item}`">{{ item }}</span>
                        </div>

                        <div v-if="getWorkReferenceImages(work).length || work.referenceFileName" class="ref-card">
                            <div v-if="getWorkReferenceImages(work).length" class="ref-card__thumbs">
                                <button
                                    v-for="(image, index) in getWorkReferenceImages(work)"
                                    :key="`${work.id}-ref-${index}`"
                                    class="ref-card__thumb"
                                    type="button"
                                    @click="openReferencePreview(work, image, index)"
                                >
                                    <img :src="image" :alt="`${work.referenceFileName || '参考图'} ${index + 1}`" />
                                </button>
                            </div>
                            <div>
                                <small>参考图 {{ getWorkReferenceImages(work).length ? getWorkReferenceImages(work).length : '' }}</small>
                                <strong>{{ work.referenceFileName || '已上传参考图' }}</strong>
                            </div>
                        </div>

                        <div :class="['grid', `is-${getCards(work).length}`]">
                            <article
                                v-for="(card, index) in getCards(work)"
                                :key="card.id"
                                :class="['card', { 'is-video': Boolean(card.video), 'is-pending': isWorkCreating(work) }]"
                                :style="getCardStyle(card)"
                                @click="openWorkDetail(work, card.image, card.video)"
                            >
                                <video v-if="card.video && !isWorkWithoutResult(work)" :src="card.video" :poster="card.image || undefined" muted playsinline preload="metadata"></video>
                                <img v-else-if="card.image && !isWorkWithoutResult(work)" :src="card.image" :alt="card.alt" />
                                <div class="card__mask"></div>
                                <span v-if="card.video && !isWorkWithoutResult(work)" class="card__play" aria-hidden="true">▶</span>
                                <button
                                    v-if="(card.video || card.image) && !isWorkWithoutResult(work)"
                                    :class="['card__favorite', { 'is-active': isWorkFavorite(work) }]"
                                    type="button"
                                    aria-label="收藏作品"
                                    @pointerdown.stop
                                    @click.stop.prevent="toggleWorkFavorite(work)"
                                >
                                    <img :src="favoriteIcon" alt="" />
                                </button>
                                <button
                                    v-if="(card.video || card.image) && !isWorkWithoutResult(work)"
                                    class="card__download"
                                    type="button"
                                    aria-label="下载作品"
                                    @pointerdown.stop
                                    @click.stop.prevent="downloadWorkImage(work, card.video || card.image)"
                                >
                                    <img :src="downloadIcon" alt="" />
                                </button>
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
                    </article>
                </template>

                <div v-else class="empty">
                    <strong>{{ emptyTitle }}</strong>
                    <p>{{ emptyText }}</p>
                </div>
                <div v-if="filteredWorks.length > workPageSize" class="works__pagination">
                    <ElPagination
                        v-model:current-page="workPage"
                        :total="filteredWorks.length"
                        :page-size="workPageSize"
                        hide-on-single-page
                        layout="total, prev, pager, next, jumper"
                    />
                </div>
            </section>
        </main>

        <Teleport to="body">
            <div v-if="detailWork" class="work-detail" @click.self="closeWorkDetail">
                <div class="work-detail__panel">
                    <div class="work-detail__media">
                        <video v-if="detailVideo" :src="detailVideo" :poster="detailImage" controls autoplay playsinline></video>
                        <img v-else-if="detailImage" :src="detailImage" alt="生成结果" />
                        <div v-else class="work-detail__placeholder">
                            <strong>{{ getWorkStatusText(detailWork) }}</strong>
                            <span>{{ getWorkStatusDescription(detailWork, 0) }}</span>
                        </div>
                    </div>
                    <div class="work-detail__content">
                        <div class="work-detail__toolbar">
                            <button class="work-detail__close" type="button" aria-label="关闭" @click="closeWorkDetail">×</button>
                        </div>
                        <div class="work-detail__head">
                            <div>
                                <strong>{{ getWorkTypeLabel(detailWork) }}</strong>
                                <span>{{ formatTime(detailWork.createdAt) }}</span>
                            </div>
                        </div>
                        <div class="work-detail__section">
                            <span>提示词</span>
                            <p>{{ detailWork.prompt || '无提示词' }}</p>
                        </div>
                        <div class="work-detail__config">
                            <span v-for="item in getWorkConfigItems(detailWork)" :key="`detail-${item}`">{{ item }}</span>
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
                        <div v-if="detailWork" class="work-detail__actions">
                            <button
                                :class="['work-detail__favorite', { 'is-active': isWorkFavorite(detailWork) }]"
                                type="button"
                                @click="toggleWorkFavorite(detailWork)"
                            >
                                <img :src="favoriteIcon" alt="" />
                                {{ isWorkFavorite(detailWork) ? '已收藏' : '收藏作品' }}
                            </button>
                            <button
                                class="work-detail__download"
                                type="button"
                                @click.stop.prevent="downloadWorkImage(detailWork, detailDownloadUrl)"
                            >
                                <img :src="downloadIcon" alt="" />
                                下载作品
                            </button>
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

        <div class="composer-hotzone" aria-hidden="true" @mouseenter="openComposerDrawer"></div>

        <div
            :class="['composer-drawer', { 'is-open': composerOpen }]"
            @mouseenter="openComposerDrawer"
            @mouseleave="scheduleComposerCollapse()"
        >
            <div class="composer">
            <div
                :class="['upload', { 'is-filled': uploadedPreviewUrl || uploadedFileName }]"
                role="button"
                tabindex="0"
                @click="triggerUpload"
                @keydown.enter.prevent="triggerUpload"
                @keydown.space.prevent="triggerUpload"
            >
                <span v-if="uploadedPreviewUrl" class="upload__preview">
                    <img :src="uploadedPreviewUrl" :alt="uploadedFileName || '参考图'" />
                    <span class="upload__delete" role="button" tabindex="0" aria-label="移除参考图" @click.stop="clearReference" @keydown.enter.stop.prevent="clearReference" @keydown.space.stop.prevent="clearReference">×</span>
                </span>
                <span v-else class="upload__box"><span>＋</span></span>
            </div>

            <div ref="promptCardRef" class="composer__body">
                <textarea
                    ref="promptTextareaRef"
                    v-model="prompt"
                    :placeholder="currentPlaceholder"
                    @focus="openComposerDrawer"
                    @input="handleComposerInput"
                    @keydown.meta.enter.prevent="submitPrompt"
                    @keydown.ctrl.enter.prevent="submitPrompt"
                ></textarea>

                <div class="composer__footer">
                    <div class="options">
                        <div class="mode-switch">
                            <button :class="['prompt-toggle', { 'is-active': generationMode === 'image' }]" type="button" @click="setGenerationMode('image')">
                                <img :src="generationMode === 'image' ? imageIconActive : imageIcon" alt="" />
                                <span>图片</span>
                            </button>
                            <button :class="['prompt-toggle', { 'is-active': generationMode === 'video' }]" type="button" @click="setGenerationMode('video')">
                                <img :src="generationMode === 'video' ? videoIconActive : videoIcon" alt="" />
                                <span>视频</span>
                            </button>
                        </div>
                        <div v-for="item in configOptions" :key="item.key" class="select">
                            <button
                                :class="['select__btn', { 'is-open': openedOption === item.key, 'is-disabled': item.disabled }]"
                                type="button"
                                :disabled="item.disabled"
                                @click.stop="toggleOption(item.key, item.disabled)"
                            >
                                <span>{{ item.value }}</span>
                                <img v-if="!item.disabled" :src="downIcon" alt="" />
                            </button>
                            <div v-if="openedOption === item.key" class="select__menu">
                                <button
                                    v-for="value in getOptionValues(item.key)"
                                    :key="`${item.key}-${value}`"
                                    :class="{ 'is-active': isOptionActive(item.key, value) }"
                                    type="button"
                                    @click.stop="setOption(item.key, value)"
                                >
                                    <span v-if="item.key === 'ratio'" class="select__ratio-preview" aria-hidden="true">
                                        <span class="select__ratio-shape" :style="getAiCreateRatioPreviewStyle(value)"></span>
                                    </span>
                                    {{ value }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="submit">
                        <span><img :src="sparkIcon" alt="" />{{ generationMode === 'image' ? `${selectedUnitPrice}/张` : `${selectedVideoUnitPrice}/条` }}</span>
                        <button type="button" :disabled="!canGenerate || isSubmitPromptLocked" @click="submitPromptLock">{{ submitting ? '...' : '↑' }}</button>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <input ref="fileInputRef" type="file" class="sr-only" accept="image/*" @change="handleUpload" />
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type {
    AiCreateDraft,
    AiCreateOptionKey,
    AiCreateOptionState,
    AiCreateStatus,
    AiCreateWork,
    AiGenerationMode
} from '~/composables/useAiCreateWorks'
import {
    createAiCreateOptionState,
    getAiCreateCards,
    getAiCreateRatioPreviewStyle,
    normalizeAiCreateOptionState,
    useAiCreateWorks
} from '~/composables/useAiCreateWorks'
import { getAigcImageConfig, generateAigcImage, getAigcImageResults, getAigcImageTask, getAigcImageTasks } from '@/apps/aigc_image/api'
import { getAigcVideoConfig, generateAigcVideo, getAigcVideoResults, getAigcVideoTask, getAigcVideoTasks } from '@/apps/aigc_video/api'
import { getAigcDigitalHumanResults, getAigcDigitalHumanTask, getAigcDigitalHumanTasks } from '@/apps/aigc_digital_human/api'
import { uploadImage as uploadAppImage } from '@/api/app'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'
import { downloadPcAsset, getPcDownloadExtension, openPcAsset } from '@/utils/download'
import feedback from '@/utils/feedback'
import { ElPagination } from 'element-plus'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import sparkIcon from '@/assets/images/icon/lingganzhi.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import imageIcon from '@/assets/images/icon/New-picture -yuanshi.svg'
import imageIconActive from '@/assets/images/icon/New-picture -gaoliang.svg'
import videoIcon from '@/assets/images/icon/shipin-yuanshi.svg'
import videoIconActive from '@/assets/images/icon/shipin-gaoliang.svg'
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

interface RatioOption {
    label: string
    value: string
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
    qualities: QualityOption[]
}

type BackendWork = AiCreateWork & {
    backendStatus?: string
    error?: string
    source?: 'backend' | 'local'
    category?: WorkCategory
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
const promptCardRef = ref<HTMLElement | null>(null)
const promptTextareaRef = ref<HTMLTextAreaElement | null>(null)
const workListRef = ref<HTMLElement | null>(null)
const { works, draft, appendWork, setDraft, setWorkStatus } = useAiCreateWorks()
const { isFavorite, toggleFavorite } = useAiWorkspaceFavorites()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()

const activeSidebar = ref<SidebarKey>('create')
const activeTab = ref<WorkStatusFilter>('')
const activeType = ref<WorkTypeFilter>('')
const apiCredits = ref(24)
const activePopover = ref<PopoverKey>('')
const openedOption = ref<OptionKey | ''>('')
const generationMode = ref<AiGenerationMode>('image')
const prompt = ref('')
const uploadedFileName = ref('')
const uploadedPreviewUrl = ref('')
const uploadedReferenceUri = ref('')
const uploading = ref(false)
const submitting = ref(false)
const workPage = ref(1)
const workPageSize = 6
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
const composerOpen = ref(false)
let composerCollapseTimer: ReturnType<typeof setTimeout> | null = null
let backendRefreshTimer: ReturnType<typeof setInterval> | null = null
let isRefreshingBackendWorks = false
let lastScrollY = 0
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
const backgroundStyle = computed(() => ({ backgroundImage: 'linear-gradient(180deg,#050505 0%,#06070a 42%,#050505 100%)' }))
const configOptions = computed<ConfigOption[]>(() =>
    generationMode.value === 'video'
        ? [
            { key: 'model', value: currentVideoChannel.value?.label || 'Grok Video（xAIQ）', disabled: videoChannels.value.length <= 1 },
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
const videoChannels = computed<ChannelOption[]>(() =>
    (aigcVideoOptionConfig.value.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        value: channel.value || channel.code,
        max_reference_images: Number(channel.max_reference_images || aigcVideoOptionConfig.value.max_reference_images || 7),
        qualities: (channel.qualities || []).map((quality: any) => ({
            label: quality.label || quality.quality_label || `${quality.value || quality.quality}秒`,
            value: String(quality.value || quality.quality),
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
const currentVideoQuality = computed(() => videoQualities.value.find((item) => item.value === optionState.value.duration) || videoQualities.value[0])
const videoRatios = computed<RatioOption[]>(() => currentVideoQuality.value?.ratios || [])
const currentVideoRatio = computed(() => videoRatios.value.find((item) => item.value === optionState.value.ratio) || videoRatios.value[0])
const selectedVideoUnitPrice = computed(() => Number(currentVideoRatio.value?.tenant_unit_price || 0).toString())
const selectedQuantity = computed(() => {
    const parsed = Number.parseInt(optionState.value.count, 10)
    return Number.isFinite(parsed) && parsed > 0 ? Math.min(parsed, 4) : 1
})
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
    resolution: videoQualities.value.map((item) => item.value),
    duration: videoQualities.value.map((item) => item.value),
    quality: []
}))
const localWorks = computed<BackendWork[]>(() => works.value
    .filter((item) => !backendWorks.value.some((backendItem) => backendItem.task === item.task))
    .map((item) => ({ ...item, source: 'local' as const, category: item.mode === 'video' ? 'video' as const : 'image' as const })))
const orderedWorks = computed<BackendWork[]>(() => [
    ...optimisticBackendWorks.value.filter((localItem) => !backendWorks.value.some((backendItem) => backendItem.id === localItem.id)),
    ...backendWorks.value,
    ...localWorks.value
].sort((a, b) => b.createdAt - a.createdAt))
const statusTabs: Array<{ label: string; value: WorkStatusFilter }> = [
    { label: '全部', value: '' },
    { label: '排队中', value: 'pending' },
    { label: '生成中', value: 'running' },
    { label: '已完成', value: 'success' },
    { label: '失败', value: 'failed' },
    { label: '已取消', value: 'canceled' }
]
const typeTabs: Array<{ label: string; value: WorkTypeFilter }> = [
    { label: '全部', value: '' },
    { label: '图片', value: 'image' },
    { label: '视频', value: 'video' },
    { label: '数字人', value: 'digital_human' }
]
const creatingCount = computed(() => orderedWorks.value.filter((item) => isWorkCreating(item)).length)
const createdCount = computed(() => orderedWorks.value.filter((item) => item.backendStatus === 'success' || item.status === 'created').length)
const failedCount = computed(() => orderedWorks.value.filter((item) => item.backendStatus === 'failed').length)
const filterWorksByStatus = (list: BackendWork[], status: WorkStatusFilter) => {
    if (!status) return list
    if (status === 'creating') return list.filter((item) => isWorkCreating(item))
    if (status === 'created') return list.filter((item) => item.status === 'created')
    return list.filter((item) => (item.backendStatus || item.status) === status)
}
const filterWorksByType = (list: BackendWork[], type: WorkTypeFilter) => {
    if (!type) return list
    return list.filter((item) => (item.category || item.mode) === type)
}
const typeFilteredWorks = computed(() => filterWorksByType(orderedWorks.value, activeType.value))
const filteredWorks = computed(() => filterWorksByStatus(typeFilteredWorks.value, activeTab.value))
const pagedFilteredWorks = computed(() => {
    const start = (workPage.value - 1) * workPageSize
    return filteredWorks.value.slice(start, start + workPageSize)
})
const activeTabMeta = computed(() => {
    const map: Record<WorkStatusFilter, { title: string; desc: string; hint: string; empty: string }> = {
        '': {
            title: '全部创作任务',
            desc: '所有提交到生图应用的任务都会展示在这里，可按状态筛选查看。',
            hint: '当前筛选下的任务数',
            empty: '还没有创作任务'
        },
        pending: {
            title: '排队中的任务',
            desc: '任务已创建，正在等待上游接口处理。',
            hint: '等待提交或执行',
            empty: '当前没有排队中的任务'
        },
        running: {
            title: '生成中的任务',
            desc: '任务已提交到上游接口，完成后会自动展示结果。',
            hint: '生成完成后可查看结果',
            empty: '当前没有生成中的任务'
        },
        success: {
            title: '已完成的作品',
            desc: '创作成功的图片会展示在这里，支持编辑后继续生成。',
            hint: '可编辑后继续生成',
            empty: '当前还没有已完成作品'
        },
        failed: {
            title: '失败的任务',
            desc: '失败任务会保留错误原因，便于调整提示词或参数后重新创作。',
            hint: '可调整后再次创作',
            empty: '当前没有失败任务'
        },
        canceled: {
            title: '已取消的任务',
            desc: '已取消的任务会展示在这里。',
            hint: '已取消任务数',
            empty: '当前没有已取消任务'
        },
        creating: {
            title: '当前任务正在排队或生成中',
            desc: '灵感页提交的新任务会直接进入创作列表。',
            hint: '等待完成后可查看结果',
            empty: '当前没有创作中的任务'
        },
        created: {
            title: '创作完成的作品都展示在这里',
            desc: '已完成的作品支持编辑后继续调整，也可以基于当前参数再次创作。',
            hint: '可编辑后继续生成',
            empty: '当前还没有已创作作品'
        }
    }
    return map[activeTab.value]
})
const activeTabTitle = computed(() => activeTabMeta.value.title)
const activeTabDescription = computed(() => activeTabMeta.value.desc)
const activeTabHint = computed(() => activeTabMeta.value.hint)
const emptyTitle = computed(() => activeTabMeta.value.empty)
const emptyText = computed(() => '去灵感页输入内容、上传参考图并点击创作，新的任务会出现在这里。')
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
const getWorkFavoriteId = (work: Pick<BackendWork, 'task' | 'id'>) => work.task || work.id
const isWorkFavorite = (work: BackendWork | null) => Boolean(work && isFavorite(getWorkFavoriteCategory(work), getWorkFavoriteId(work)))
const toggleWorkFavorite = (work: BackendWork | null) => {
    if (!work) return
    if (!ensurePcLogin()) return
    toggleFavorite(getWorkFavoriteCategory(work), getWorkFavoriteId(work))
}
const getWorkTypeLabel = (work: Pick<BackendWork, 'category' | 'mode'>) => {
    const category = getWorkCategory(work)
    if (category === 'digital_human') return '数字人'
    return category === 'video' ? '视频生成' : '图片生成'
}
const getWorkConfigItems = (work: Pick<BackendWork, 'mode' | 'category' | 'model' | 'count' | 'ratio' | 'resolution' | 'duration' | 'quality'>) => {
    const normalizedOptions = normalizeAiCreateOptionState(work)
    if (getWorkCategory(work) === 'digital_human') {
        return ['数字人合成', normalizedOptions.model, normalizedOptions.ratio, normalizedOptions.duration || normalizedOptions.quality].filter(Boolean)
    }
    return work.mode === 'video'
        ? [normalizedOptions.model, normalizedOptions.ratio, normalizedOptions.duration, normalizedOptions.quality]
        : [normalizedOptions.model, normalizedOptions.count, normalizedOptions.ratio, normalizedOptions.resolution]
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
    if (!videoQualities.value.some((item) => item.value === optionState.value.duration)) {
        optionState.value.duration = aigcVideoOptionConfig.value.defaults?.quality || videoQualities.value[0]?.value || optionState.value.duration
    }
    if (!videoQualities.value.some((item) => item.value === optionState.value.duration)) {
        optionState.value.duration = videoQualities.value[0]?.value || optionState.value.duration
    }
    if (!videoRatios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = aigcVideoOptionConfig.value.defaults?.ratio || videoRatios.value[0]?.value || optionState.value.ratio
    }
    if (!videoRatios.value.some((item) => item.value === optionState.value.ratio)) {
        optionState.value.ratio = videoRatios.value[0]?.value || optionState.value.ratio
    }
    optionState.value.model = currentVideoChannel.value?.label || 'Grok Video（xAIQ）'
    optionState.value.quality = '720p'
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
        optionState.value.duration = defaults.quality || optionState.value.duration
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
    return {
        task: String(item.task_id || item.id || index),
        id: `video-${item.task_id || item.id || index}`,
        prompt: item.prompt || '',
        mode: 'video',
        model: item.channel || item.model || currentVideoChannel.value?.label || 'Grok Video（xAIQ）',
        count: `${Number(item.quantity || 1)}条`,
        ratio: item.ratio || '16:9',
        resolution: item.quality || '',
        duration: item.quality ? `${item.quality}秒` : '6秒',
        quality: '720p',
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

const mapDigitalHumanWork = (item: any, index: number): BackendWork => {
    const rawId = String(item.task_id || item.id || index)
    const backendStatus = normalizeBackendStatus(item.status)
    const videoUrl = normalizeDigitalHumanVideo(item)
    const coverUrl = normalizeDigitalHumanCover(item)
    const duration = Number(item.duration || 0)
    return {
        task: rawId,
        id: `digital-human-${rawId}`,
        prompt: item.script_text || item.prompt || item.title || '',
        mode: 'video',
        model: item.avatar_name || item.voice_name || item.channel || item.model || '数字人合成',
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
        createdAt: getBackendTimestamp(item),
        seed: index,
        resultImage: coverUrl,
        coverImage: coverUrl,
        resultVideo: videoUrl
    }
}

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
        const [resultList, taskList, videoResultList, videoTaskList, digitalHumanResultList, digitalHumanTaskList] = await Promise.all([
            fetchBackendList(getAigcImageResults, 'aigc image results'),
            fetchBackendList(getAigcImageTasks, 'aigc image tasks'),
            fetchBackendList(getAigcVideoResults, 'aigc video results'),
            fetchBackendList(getAigcVideoTasks, 'aigc video tasks'),
            fetchBackendList(getAigcDigitalHumanResults, 'digital human results'),
            fetchBackendList(getAigcDigitalHumanTasks, 'digital human tasks')
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
        quality: optionState.value.duration,
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
const getDownloadDetailCandidates = (payload: any) => {
    const candidates: any[] = []
    const push = (value: any) => {
        if (!value) return
        if (Array.isArray(value)) {
            value.forEach(push)
            return
        }
        if (typeof value === 'object') candidates.push(value)
    }
    push(payload)
    push(payload?.data)
    push(payload?.detail)
    push(payload?.task)
    push(payload?.result)
    push(payload?.results)
    push(payload?.list)
    push(payload?.lists)
    return candidates
}
const fetchWorkDownloadDetail = async (work: BackendWork) => {
    if (work.source !== 'backend') return null
    const id = getWorkTaskId(work)
    if (!id) return null
    const category = getWorkCategory(work)
    if (category === 'digital_human') return getAigcDigitalHumanTask({ id })
    if (category === 'video') return getAigcVideoTask({ id })
    return getAigcImageTask({ id })
}
const mergeWorkDownloadDetail = (work: BackendWork, detail: any) => {
    if (!detail || typeof detail !== 'object') return work
    const category = getWorkCategory(work)
    const next = category === 'digital_human'
        ? mapDigitalHumanWork(detail, Number(work.seed || 0))
        : category === 'video'
            ? mapBackendVideoWork(detail, Number(work.seed || 0))
            : mapBackendWork(detail, Number(work.seed || 0))
    const merged = { ...work, ...next }
    backendWorks.value = backendWorks.value.map((item) =>
        item.id === work.id || item.task === work.task ? { ...item, ...merged } : item
    )
    if (detailWork.value && (detailWork.value.id === work.id || detailWork.value.task === work.task)) {
        detailWork.value = { ...detailWork.value, ...merged }
        detailImage.value = merged.resultImage || detailImage.value
        detailVideo.value = merged.resultVideo || detailVideo.value
    }
    return merged
}
const resolveWorkDownloadUrlFromDetail = (work: BackendWork, detail: any, preferred = '') => {
    const candidates = getDownloadDetailCandidates(detail)
    const category = getWorkCategory(work)
    if (category === 'image') {
        return candidates.map(normalizeResultImage).find(Boolean) || getWorkDownloadUrl(work, preferred)
    }
    const video = candidates.map(normalizeDigitalHumanVideo).find(Boolean)
    const cover = candidates
        .map((item) => category === 'digital_human' ? normalizeDigitalHumanCover(item) || normalizeVideoCover(item, '') : normalizeVideoCover(item, ''))
        .find(Boolean)
    return video || cover || getWorkDownloadUrl(work, preferred)
}
const getDownloadName = (work: BackendWork, image: string) => {
    const ext = getPcDownloadExtension(image, getWorkCategory(work) === 'video' || getWorkCategory(work) === 'digital_human' ? 'mp4' : 'png')
    return `aigc-${work.task || work.id}.${ext}`
}
const downloadWorkImage = async (work: BackendWork, image: string) => {
    if (!ensurePcLogin()) return
    const url = getWorkDownloadUrl(work, image)
    if (!downloadPcAsset(url, getDownloadName(work, url || image))) {
        openPcAsset(url)
    }
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
const getStatusCount = (status: WorkStatusFilter) => {
    return filterWorksByStatus(typeFilteredWorks.value, status).length
}
const getTypeCount = (type: WorkTypeFilter) => {
    return filterWorksByType(filterWorksByStatus(orderedWorks.value, activeTab.value), type).length
}
const getOptionValues = (key: OptionKey) => {
    if (generationMode.value === 'image') {
        return imageOptionValues.value[key] || []
    }
    return videoOptionValues.value[key] || []
}
const isOptionActive = (key: OptionKey, value: string) => {
    if (generationMode.value === 'image' && key === 'model') {
        return currentChannel.value?.label === value
    }
    if (generationMode.value === 'video' && key === 'model') {
        return currentVideoChannel.value?.label === value
    }
    return optionState.value[key] === value
}
const formatTime = (timestamp: number) =>
    timestamp > 0
        ? new Intl.DateTimeFormat('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(timestamp)
        : '最近作品'
const goHome = () => router.push('/')
const togglePopover = (key: Exclude<PopoverKey, ''>) => { activePopover.value = activePopover.value === key ? '' : key }
const closeMenus = () => { activePopover.value = ''; openedOption.value = '' }
const clearComposerCollapseTimer = () => {
    if (!composerCollapseTimer) return
    clearTimeout(composerCollapseTimer)
    composerCollapseTimer = null
}
const canAutoCollapseComposer = () => !openedOption.value && !submitting.value
const scheduleComposerCollapse = (delay = 5200) => {
    clearComposerCollapseTimer()
    if (!canAutoCollapseComposer()) return
    composerCollapseTimer = setTimeout(() => {
        if (canAutoCollapseComposer()) composerOpen.value = false
    }, delay)
}
const openComposerDrawer = () => {
    composerOpen.value = true
    scheduleComposerCollapse()
}
const handleComposerInput = () => {
    composerOpen.value = true
    scheduleComposerCollapse(6500)
}
const handleWindowScroll = () => {
    const currentY = window.scrollY || 0
    if (Math.abs(currentY - lastScrollY) < 8) return
    if (currentY > lastScrollY) {
        composerOpen.value = false
        clearComposerCollapseTimer()
    } else {
        openComposerDrawer()
    }
    lastScrollY = currentY
}
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
const triggerUpload = () => {
    openComposerDrawer()
    if (!ensurePcLogin()) return
    fileInputRef.value?.click()
}
const setGenerationMode = (mode: AiGenerationMode) => {
    openComposerDrawer()
    generationMode.value = mode
    openedOption.value = ''
    if (mode === 'image') syncAigcSelection()
    if (mode === 'video') syncAigcVideoSelection()
}
const toggleOption = (key: OptionKey, disabled = false) => {
    if (disabled) return
    if (!getOptionValues(key).length) return
    openComposerDrawer()
    openedOption.value = openedOption.value === key ? '' : key
}
const setOption = (key: OptionKey, value: string) => {
    openComposerDrawer()
    if (generationMode.value === 'image') {
        if (key === 'model') {
            const nextChannel = channels.value.find((item) => item.label === value || item.value === value)
            if (nextChannel) selectedChannelCode.value = nextChannel.value
            syncAigcSelection()
            openedOption.value = ''
            return
        }
        if (key === 'resolution') {
            optionState.value.resolution = value
            optionState.value.ratio = qualities.value.find((item) => item.value === value)?.ratios?.[0]?.value || optionState.value.ratio
            syncAigcSelection()
            openedOption.value = ''
            return
        }
        if (key === 'ratio' || key === 'count') {
            optionState.value[key] = value
            syncAigcSelection()
            openedOption.value = ''
            return
        }
    }
    if (generationMode.value === 'video') {
        if (key === 'model') {
            const nextChannel = videoChannels.value.find((item) => item.label === value || item.value === value)
            if (nextChannel) selectedVideoChannelCode.value = nextChannel.value
            syncAigcVideoSelection()
            openedOption.value = ''
            return
        }
        if (key === 'duration') {
            optionState.value.duration = value
            optionState.value.ratio = videoQualities.value.find((item) => item.value === value)?.ratios?.[0]?.value || optionState.value.ratio
            syncAigcVideoSelection()
            openedOption.value = ''
            return
        }
        if (key === 'ratio') {
            optionState.value.ratio = value
            syncAigcVideoSelection()
            openedOption.value = ''
            return
        }
    }
    optionState.value[key] = value
    openedOption.value = ''
}
const clearReference = () => {
    uploadedFileName.value = ''
    uploadedPreviewUrl.value = ''
    uploadedReferenceUri.value = ''
    if (fileInputRef.value) fileInputRef.value.value = ''
    scheduleComposerCollapse()
}
const handleUpload = async (event: Event) => {
    openComposerDrawer()
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) return
    const objectUrl = URL.createObjectURL(file)
    uploading.value = true
    try {
        const res: any = await uploadAppImage({ file })
        const uri = res?.uri || res?.url || res?.path || ''
        if (!uri) throw new Error('参考图上传失败')
        uploadedFileName.value = file.name
        uploadedPreviewUrl.value = objectUrl
        uploadedReferenceUri.value = uri
        if (!prompt.value.trim()) prompt.value = `基于上传图片“${file.name}”生成高质量${generationMode.value === 'image' ? '图片' : '视频'}内容`
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        URL.revokeObjectURL(objectUrl)
        feedback.msgError(error?.msg || error?.message || '参考图上传失败')
    } finally {
        uploading.value = false
    }
}
const focusComposer = async () => {
    openComposerDrawer()
    await nextTick()
    window.setTimeout(() => promptTextareaRef.value?.focus(), 180)
}
const scrollToLatestWork = async () => {
    await nextTick()
    window.scrollTo({ top: 0, behavior: 'smooth' })
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
            workPage.value = 1
            composerOpen.value = false
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
            quality: optionState.value.duration,
            quantity: 1,
            channel: selectedVideoChannelCode.value || currentVideoChannel.value.value,
            negative_prompt: ''
        })
        addOptimisticBackendVideoWork(res?.task_id, res?.status || 'running')
        activeTab.value = ''
        activeType.value = ''
        workPage.value = 1
        composerOpen.value = false
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
const goEditDigitalHumanWork = (work: BackendWork) => router.push({
    path: '/ai/avatar',
    query: {
        edit_task_id: String(getWorkTaskId(work) || work.task || work.id || '')
    }
})
const loadWorkToComposer = async (work: BackendWork) => {
    if (getWorkCategory(work) === 'digital_human') {
        await goEditDigitalHumanWork(work)
        return
    }
    syncComposerFromWork(work)
    await focusComposer()
}
const completeWork = (task: string) => { setWorkStatus(task, 'created'); activePopover.value = 'notice' }
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
        await nextTick()
        window.scrollTo({ top: 0, behavior: 'smooth' })
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
watch(
    [activeTab, activeType],
    async () => {
        workPage.value = 1
        await nextTick()
        window.scrollTo({ top: 0, behavior: 'auto' })
    }
)
watch(
    () => route.query,
    (query) => {
        const type = Array.isArray(query.type) ? query.type[0] : query.type
        const status = Array.isArray(query.status) ? query.status[0] : query.status
        if (type === 'image' || type === 'video' || type === 'digital_human') {
            activeType.value = type
        }
        if (status === 'pending' || status === 'running' || status === 'success' || status === 'failed' || status === 'canceled') {
            activeTab.value = status
        } else if (status === '') {
            activeTab.value = ''
        }
    },
    { immediate: true }
)
watch(filteredWorks, (list) => {
    const totalPages = Math.max(1, Math.ceil(list.length / workPageSize))
    if (workPage.value > totalPages) workPage.value = totalPages
})
onMounted(() => {
    unlockCreatePageScroll()
    document.addEventListener('click', closeMenus)
    window.addEventListener('keydown', handleReferencePreviewKeydown)
    lastScrollY = window.scrollY || 0
    window.addEventListener('scroll', handleWindowScroll, { passive: true })
    loadAigcConfig()
    loadAigcVideoConfig()
    refreshBackendWorks()
})

watch(() => userStore.isLogin, (loggedIn) => {
    if (!loggedIn) {
        backendWorks.value = []
        optimisticBackendWorks.value = []
        stopBackendRefreshPolling()
        return
    }
    refreshBackendWorks()
})
onBeforeUnmount(() => {
    stopBackendRefreshPolling()
    document.removeEventListener('click', closeMenus)
    window.removeEventListener('keydown', handleReferencePreviewKeydown)
    window.removeEventListener('scroll', handleWindowScroll)
    clearComposerCollapseTimer()
    document.documentElement.classList.remove('ai-create-scrollable')
    document.body.classList.remove('ai-create-scrollable')
    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
    if (uploadedPreviewUrl.value.startsWith('blob:')) {
        URL.revokeObjectURL(uploadedPreviewUrl.value)
    }
})
</script>

<style lang="scss" scoped>
:global(html){overflow:auto;scrollbar-width:none;-ms-overflow-style:none}
:global(body){overflow:auto;scrollbar-width:none;-ms-overflow-style:none}
:global(html.ai-create-scrollable),:global(body.ai-create-scrollable){overflow:auto!important}
:global(html::-webkit-scrollbar){width:0;height:0;background:transparent}
:global(body::-webkit-scrollbar){width:0;height:0;background:transparent}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.page{position:relative;min-height:100dvh;padding:0 0 120px;background:#050505;color:#fff;overflow-x:hidden}
.page__bg,.page__noise,.page__stars{position:fixed;inset:0;pointer-events:none;will-change:opacity}
.page__noise{background-image:radial-gradient(circle at 6% 16%,rgba(255,255,255,.65) 0 1px,transparent 1.8px),radial-gradient(circle at 12% 54%,rgba(255,255,255,.4) 0 1px,transparent 1.8px),radial-gradient(circle at 18% 32%,rgba(255,255,255,.35) 0 1px,transparent 1.6px),radial-gradient(circle at 26% 12%,rgba(255,255,255,.55) 0 1px,transparent 1.6px),radial-gradient(circle at 34% 58%,rgba(255,255,255,.45) 0 1px,transparent 1.8px),radial-gradient(circle at 42% 18%,rgba(255,255,255,.45) 0 1px,transparent 1.5px),radial-gradient(circle at 52% 10%,rgba(255,255,255,.55) 0 1px,transparent 1.5px),radial-gradient(circle at 61% 44%,rgba(255,255,255,.35) 0 1px,transparent 1.5px),radial-gradient(circle at 72% 20%,rgba(255,255,255,.48) 0 1px,transparent 1.7px),radial-gradient(circle at 84% 38%,rgba(255,255,255,.42) 0 1px,transparent 1.7px),radial-gradient(circle at 90% 14%,rgba(255,255,255,.52) 0 1px,transparent 1.7px),radial-gradient(circle at 96% 52%,rgba(255,255,255,.35) 0 1px,transparent 1.8px);opacity:.24}
.page__stars{opacity:.95;mix-blend-mode:screen}
.page__stars--near{background-image:radial-gradient(circle at 10% 22%,rgba(255,255,255,.95) 0 1.4px,transparent 2.2px),radial-gradient(circle at 21% 68%,rgba(255,255,255,.92) 0 1.2px,transparent 2px),radial-gradient(circle at 36% 36%,rgba(255,255,255,.98) 0 1.5px,transparent 2.2px),radial-gradient(circle at 48% 14%,rgba(255,255,255,.9) 0 1.3px,transparent 2px),radial-gradient(circle at 57% 60%,rgba(255,255,255,.9) 0 1.1px,transparent 1.8px),radial-gradient(circle at 69% 28%,rgba(255,255,255,.96) 0 1.5px,transparent 2.2px),radial-gradient(circle at 82% 58%,rgba(255,255,255,.94) 0 1.25px,transparent 2px),radial-gradient(circle at 92% 20%,rgba(255,255,255,.9) 0 1.35px,transparent 2px);animation:starTwinkle 4.8s ease-in-out infinite alternate}
.page__stars--far{background-image:radial-gradient(circle at 14% 10%,rgba(160,203,255,.8) 0 1px,transparent 1.8px),radial-gradient(circle at 30% 48%,rgba(255,255,255,.72) 0 .9px,transparent 1.5px),radial-gradient(circle at 44% 72%,rgba(178,193,255,.72) 0 .9px,transparent 1.5px),radial-gradient(circle at 60% 8%,rgba(255,255,255,.68) 0 1px,transparent 1.6px),radial-gradient(circle at 78% 42%,rgba(181,220,255,.75) 0 .95px,transparent 1.6px),radial-gradient(circle at 88% 74%,rgba(255,255,255,.7) 0 1px,transparent 1.6px);animation:starTwinkle 6.2s ease-in-out infinite alternate-reverse}
.header,.main,.composer-drawer{position:relative;z-index:1}
.header{position:fixed;top:0;left:0;right:0;z-index:12;display:flex;align-items:center;justify-content:space-between;gap:24px;padding:22px 40px 18px;background:linear-gradient(180deg,rgba(5,5,5,.9) 0%,rgba(5,5,5,.58) 74%,rgba(5,5,5,0) 100%);backdrop-filter:blur(18px)}
.logo{display:inline-flex;align-items:center;gap:10px;color:#fff;text-decoration:none}.logo img{width:28px;height:28px}.logo span{font-size:20px;font-weight:700}
.header__actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.pill,.icon-btn,.avatar,.sidebar__item,.upload,.select__btn,.select__menu button,.ghost,.primary,.ref-chip button,.submit button,.tab,.type-tab{border:0;cursor:pointer;transition:all .2s ease}
.pill,.icon-btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 14px;border-radius:999px;background:rgba(30,31,32,.96);color:#fff;font-size:14px}.pill img{width:16px;height:16px}.pill__spark{width:11px!important;height:11px!important}.icon-btn{justify-content:center;min-width:32px;padding:0 10px}.icon-btn img{width:20px;height:20px}.avatar{width:40px;height:40px;border-radius:50%;padding:0;background:transparent;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:cover}
.popover{position:fixed;top:74px;right:40px;width:220px;padding:14px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(17,17,17,.96);box-shadow:0 18px 30px rgba(0,0,0,.35);backdrop-filter:blur(10px)}.popover strong{display:block;margin-bottom:6px;font-size:14px}.popover p{margin:0;color:rgba(255,255,255,.68);font-size:13px;line-height:1.6}
.sidebar{position:fixed;left:28px;top:calc(50% - 216px);height:432px;display:flex;flex-direction:column;gap:18px;z-index:14}.sidebar__item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;width:72px;height:72px;padding:10px 8px;border-radius:18px;background:transparent;color:rgba(255,255,255,.86);font-size:12px;line-height:1;box-sizing:border-box;flex-shrink:0}.sidebar__icon{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;flex-shrink:0}.sidebar__item img{display:block;width:24px;height:24px;object-fit:contain;object-position:center center}.sidebar__item.is-active,.sidebar__item:hover{background:rgba(255,255,255,.06);color:#fff}
.main{width:var(--ai-content-width);margin:128px var(--ai-content-gutter) 0 var(--ai-content-left);padding-bottom:120px}.work,.empty{border:1px solid rgba(255,255,255,.08);border-radius:28px;background:rgba(18,18,18,.72);box-shadow:0 18px 42px rgba(0,0,0,.22);backdrop-filter:blur(14px)}
.summary{width:min(1080px,100%);margin:0 auto}.summary__filters{display:grid;grid-template-columns:minmax(0,1.8fr) minmax(0,1fr);align-items:center;gap:10px;max-width:100%}.summary__tabs,.type-tabs{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(0,1fr);max-width:100%;gap:4px;padding:4px;border-radius:999px;background:rgba(255,255,255,.04);overflow:hidden;scrollbar-width:none;min-width:0}.summary__tabs::-webkit-scrollbar,.type-tabs::-webkit-scrollbar{display:none}.tab,.type-tab{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:38px;min-width:0;padding:0 12px;border-radius:999px;background:transparent;color:rgba(255,255,255,.68);font-size:14px;white-space:nowrap;flex-shrink:1}.tab span,.type-tab span{color:rgba(255,255,255,.42);font-size:12px}.tab.is-active,.type-tab.is-active{background:#fff;color:#050505;font-weight:600}.tab.is-active span,.type-tab.is-active span{color:rgba(5,5,5,.6)}
.works{width:min(1080px,100%);margin:20px auto 0;padding-bottom:120px;display:flex;flex-direction:column;gap:20px}.work{padding:24px;scroll-margin-top:128px}.work__head,.work__tags,.work__actions,.work__config{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.work__head{justify-content:space-between}.chip,.work__config span{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 12px;border-radius:999px;font-size:12px}.chip{gap:6px;background:rgba(255,255,255,.08)}.chip i{width:6px;height:6px;border-radius:50%;background:#54f654;box-shadow:0 0 10px rgba(84,246,84,.9)}.chip--muted{color:rgba(255,255,255,.72)}.work__time{color:rgba(255,255,255,.42);font-size:13px}.ghost,.primary{height:36px;padding:0 16px;border-radius:999px;font-size:13px}.ghost{background:rgba(255,255,255,.08);color:#fff}.primary{background:#fff;color:#050505;font-weight:600}.work__prompt{margin-top:18px}.work__prompt span{display:block;margin-bottom:10px;color:rgba(255,255,255,.58);font-size:12px}.work__prompt p{display:-webkit-box;margin:0;overflow:hidden;color:#fff;font-size:15px;line-height:26px;text-overflow:ellipsis;-webkit-box-orient:vertical;-webkit-line-clamp:2}.work__config{margin-top:18px}.work__config span{color:rgba(255,255,255,.72);background:rgba(255,255,255,.05)}
.ref-card,.ref-chip{display:flex;align-items:center;gap:12px;margin-top:18px;padding:12px 14px;border-radius:18px;background:rgba(255,255,255,.04)}.ref-card{width:100%;color:inherit;text-align:left}.ref-card--detail{margin-top:0}.ref-card__thumbs{display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex-shrink:0}.ref-card__thumb{width:48px;height:48px;padding:0;border:0;border-radius:14px;background:rgba(255,255,255,.06);overflow:hidden;cursor:pointer;transition:transform .2s ease,box-shadow .2s ease}.ref-card__thumb:hover{transform:translateY(-1px) scale(1.04);box-shadow:0 10px 24px rgba(0,0,0,.28)}.ref-card__thumb img,.ref-chip img{display:block;width:100%;height:100%;object-fit:cover}.ref-card__thumb img{border-radius:14px}.ref-card small,.ref-chip small{display:block;color:rgba(255,255,255,.42);font-size:12px}.ref-card strong,.ref-chip strong{display:block;overflow:hidden;color:#fff;font-size:14px;line-height:20px;text-overflow:ellipsis;white-space:nowrap}
.ref-chip{width:100%;min-width:0;min-height:56px;margin-top:10px;box-sizing:border-box;overflow:hidden}.ref-chip img{width:40px!important;height:40px!important;flex:0 0 40px;border-radius:12px}.ref-chip div{min-width:0;flex:1}.ref-chip button{flex:0 0 auto;white-space:nowrap}
.grid{display:grid;gap:12px;margin-top:20px}.grid.is-1{grid-template-columns:minmax(0,1fr)}.grid.is-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid.is-3,.grid.is-4{grid-template-columns:repeat(4,minmax(0,1fr))}.card{position:relative;height:320px;overflow:hidden;border-radius:16px;background:#101010;cursor:pointer}.card img,.card video,.card__mask{position:absolute;inset:0;width:100%;height:100%}.card img,.card video{object-fit:cover}.card.is-video{height:auto;min-height:0;max-height:520px}.card.is-video video{object-fit:contain;background:#070707}.card__mask{background:linear-gradient(180deg,rgba(7,7,7,.02) 12%,rgba(7,7,7,.46) 100%)}.card.is-pending img,.card.is-pending video{filter:blur(6px) saturate(.85);transform:scale(1.04);opacity:.82}.card.is-pending .card__mask{background:linear-gradient(180deg,rgba(7,7,7,.22) 0%,rgba(7,7,7,.72) 100%)}.card__play{position:absolute;left:50%;top:50%;z-index:3;display:inline-flex;align-items:center;justify-content:center;width:62px;height:62px;padding-left:4px;border:1px solid rgba(255,255,255,.28);border-radius:50%;background:rgba(0,0,0,.58);box-shadow:0 16px 42px rgba(0,0,0,.36);color:#fff;font-size:22px;line-height:1;transform:translate(-50%,-50%);backdrop-filter:blur(12px);transition:transform .2s ease,background .2s ease}.card:hover .card__play{background:rgba(255,255,255,.92);color:#050505;transform:translate(-50%,-50%) scale(1.04)}.card__download,.card__favorite{position:absolute;top:12px;z-index:4;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:1px solid rgba(255,255,255,.24);border-radius:50%;background:rgba(0,0,0,.58);opacity:0;backdrop-filter:blur(12px);transition:opacity .2s ease,background .2s ease,transform .2s ease}.card__download{right:12px}.card__favorite{right:58px}.card:hover .card__download,.card:hover .card__favorite,.card__favorite.is-active{opacity:1}.card__download:hover,.card__favorite:hover{background:rgba(255,255,255,.92);border-color:rgba(255,255,255,.92);transform:scale(1.04)}.card__favorite.is-active{background:#ffd84d;border-color:#ffd84d;transform:scale(1.04)}.card__download img,.card__favorite img{position:static;width:18px;height:18px;object-fit:contain;filter:drop-shadow(0 1px 4px rgba(0,0,0,.8))}.card__download:hover img,.card__favorite:hover img,.card__favorite.is-active img{filter:invert(1) drop-shadow(0 1px 2px rgba(255,255,255,.18))}.card__state{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center}.card__state strong{font-size:18px}.card__state span:last-child{color:rgba(255,255,255,.68);font-size:13px}.spinner{width:34px;height:34px;border:2px solid rgba(255,255,255,.18);border-top-color:#fff;border-radius:50%;animation:spin 1s linear infinite}
.empty{padding:56px 28px;text-align:center}.empty strong{display:block;margin-bottom:10px;font-size:22px}.empty p{margin:0;color:rgba(255,255,255,.58);font-size:14px;line-height:24px}
.work-detail{position:fixed;inset:0;z-index:80;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.72);backdrop-filter:blur(18px)}.work-detail__panel{position:relative;display:grid;grid-template-columns:minmax(0,1.08fr) minmax(320px,.92fr);width:min(1120px,calc(100vw - 32px));max-height:min(calc(100vh - 32px),920px);overflow:hidden;border:1px solid rgba(255,255,255,.1);border-radius:24px;background:#101012;box-shadow:0 30px 90px rgba(0,0,0,.56)}.work-detail__toolbar{position:sticky;top:0;z-index:3;display:flex;justify-content:flex-end;margin:-12px -8px 0 0;padding-bottom:4px}.work-detail__close{display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:0;border-radius:50%;background:rgba(255,255,255,.1);color:#fff;font-size:24px;line-height:1}.work-detail__close:hover{background:rgba(255,255,255,.16)}.work-detail__media{position:relative;min-height:clamp(280px,52vh,640px);background:#070707}.work-detail__media img,.work-detail__media video{display:block;width:100%;height:100%;min-height:clamp(280px,52vh,640px);object-fit:contain}.work-detail__placeholder{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center}.work-detail__placeholder strong{font-size:22px}.work-detail__placeholder span{color:rgba(255,255,255,.62);font-size:14px}.work-detail__content{display:flex;flex-direction:column;gap:24px;min-width:0;padding:24px 28px 28px;overflow-y:auto}.work-detail__head{display:flex;align-items:center;justify-content:space-between;gap:16px}.work-detail__head div{display:flex;flex-direction:column;gap:8px;min-width:0}.work-detail__head strong{font-size:22px}.work-detail__head span,.work-detail__section span{color:rgba(255,255,255,.5);font-size:13px}.work-detail__actions{display:flex;align-items:center;gap:10px;margin-top:auto;padding-top:8px}.work-detail__download,.work-detail__favorite{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 18px;border:0;border-radius:999px;background:#fff;color:#050505;font-size:14px;font-weight:600}.work-detail__download:disabled{opacity:.45;cursor:not-allowed}.work-detail__favorite{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.18)}.work-detail__favorite:hover{background:#fff;color:#050505;border-color:#fff}.work-detail__favorite.is-active{background:#ffd84d;color:#050505;border-color:#ffd84d}.work-detail__download img{width:16px;height:16px;filter:invert(1)}.work-detail__favorite img{width:16px;height:16px;filter:drop-shadow(0 1px 4px rgba(0,0,0,.8))}.work-detail__favorite.is-active img,.work-detail__favorite:hover img{filter:invert(1)}.work-detail__section{display:flex;flex-direction:column;gap:10px;min-width:0}.work-detail__section p{margin:0;color:#fff;font-size:15px;line-height:26px;white-space:pre-wrap;word-break:break-word}.work-detail__config{display:flex;flex-wrap:wrap;gap:8px}.work-detail__config span{display:inline-flex;align-items:center;min-height:30px;padding:0 12px;border-radius:999px;background:rgba(255,255,255,.07);color:rgba(255,255,255,.74);font-size:13px}
.works__pagination{display:flex;justify-content:flex-end;margin-top:24px;padding-bottom:8px}
.works__pagination :deep(.el-pagination){--el-pagination-bg-color:transparent;--el-pagination-text-color:rgba(255,255,255,.72);--el-pagination-button-color:rgba(255,255,255,.72);--el-pagination-button-disabled-color:rgba(255,255,255,.28);--el-pagination-hover-color:#fff;--el-pagination-border-radius:999px}
.works__pagination :deep(.el-pager li),.works__pagination :deep(.btn-prev),.works__pagination :deep(.btn-next){background:rgba(255,255,255,.06);border-radius:999px}
.works__pagination :deep(.el-pager li.is-active){background:#fff;color:#050505}
.reference-preview{position:fixed;inset:0;z-index:96;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;padding:48px;background:rgba(0,0,0,.78);backdrop-filter:blur(18px)}.reference-preview img{display:block;max-width:min(1120px,calc(100vw - 120px));max-height:calc(100vh - 150px);border-radius:18px;object-fit:contain;background:#111;box-shadow:0 28px 88px rgba(0,0,0,.55)}.reference-preview span{max-width:min(720px,calc(100vw - 80px));overflow:hidden;color:rgba(255,255,255,.72);font-size:13px;text-overflow:ellipsis;white-space:nowrap}.reference-preview__close,.reference-preview__nav{position:fixed;display:inline-flex;align-items:center;justify-content:center;border:0;border-radius:50%;background:rgba(255,255,255,.12);color:#fff;line-height:1;backdrop-filter:blur(12px);transition:background .2s ease,transform .2s ease}.reference-preview__close{top:34px;right:40px;width:44px;height:44px;font-size:28px}.reference-preview__nav{top:50%;width:52px;height:52px;font-size:44px;transform:translateY(-50%)}.reference-preview__nav--prev{left:42px}.reference-preview__nav--next{right:42px}.reference-preview__close:hover,.reference-preview__nav:hover{background:rgba(255,255,255,.18)}.reference-preview__nav:hover{transform:translateY(-50%) scale(1.04)}
.composer-hotzone{position:fixed;left:calc(var(--ai-content-left) + (100vw - var(--ai-content-left) - var(--ai-content-gutter)) / 2);bottom:0;z-index:19;width:min(960px,var(--ai-content-width));height:34px;transform:translateX(-50%)}.composer-drawer{position:fixed;left:calc(var(--ai-content-left) + (100vw - var(--ai-content-left) - var(--ai-content-gutter)) / 2);bottom:max(18px,env(safe-area-inset-bottom));z-index:20;display:flex;flex-direction:column;align-items:center;width:min(960px,var(--ai-content-width));transform:translate(-50%,calc(100% + 34px));transition:transform .34s cubic-bezier(.22,.61,.36,1)}.composer-drawer.is-open{transform:translate(-50%,0)}.composer{position:relative;display:flex;gap:14px;width:100%;padding:16px 18px 14px 10px;border:1px solid rgba(255,255,255,.08);border-radius:22px;background:rgba(34,34,34,.97);box-shadow:0 24px 64px rgba(0,0,0,.32);backdrop-filter:blur(22px)}.upload{position:relative;display:flex;align-items:center;justify-content:center;width:68px;height:74px;background:transparent;color:#a5a5a6;flex-shrink:0}.upload__box,.upload__preview{display:inline-flex;align-items:center;justify-content:center;width:48px;height:64px;border-radius:13px;background:linear-gradient(180deg,#313233 0%,#2a2b2c 100%);transform:rotate(-8.5deg);overflow:hidden}.upload__box span{font-size:28px;transform:rotate(9deg)}.upload__preview img{width:100%;height:100%;object-fit:cover;transform:rotate(8.5deg) scale(1.15)}.upload__delete{position:absolute;right:5px;top:1px;z-index:3;width:20px;height:20px;padding:0;border:0;border-radius:50%;background:rgba(0,0,0,.62);color:#fff;font-size:14px;line-height:20px;cursor:pointer}.composer__body{flex:1;display:flex;flex-direction:column;min-width:0}.composer textarea{width:100%;min-height:72px;border:0;outline:none;resize:none;background:transparent;color:#fff;font-size:15px;line-height:1.6}.composer textarea::placeholder{color:#7f7f80}.ref-chip{margin-top:12px}.ref-chip button{margin-left:auto;padding:0;background:transparent;color:rgba(255,255,255,.52);font-size:13px}.composer__footer{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-top:14px}.options{display:flex;align-items:flex-end;gap:6px;flex-wrap:wrap;min-width:0}.mode-switch{display:inline-flex;align-items:center;width:auto;height:32px;padding:2px;border-radius:32px;background:#050505}.prompt-toggle,.select__btn{display:inline-flex;align-items:center;gap:6px;height:32px;padding:0 12px;border-radius:32px;background:#292a2b;color:#a5a5a6;font-size:14px;line-height:14px;white-space:nowrap;flex-shrink:0}.prompt-toggle img,.select__btn img{width:16px;height:16px;object-fit:contain;flex-shrink:0}.prompt-toggle:hover,.select__btn.is-open,.select__btn:hover{color:#fff}.mode-switch .prompt-toggle{min-width:78px;height:28px;background:transparent}.mode-switch .prompt-toggle.is-active{background:#292a2b;color:#fff}.select{position:relative;display:inline-flex}.select__btn img{width:14px;height:14px;opacity:.65}.select__btn{background:#292a2b}.select__btn.is-open,.select__btn:hover{background:#323334}.select__menu{position:absolute;right:0;bottom:calc(100% + 8px);min-width:160px;max-height:min(320px,45vh);overflow-y:auto;padding:8px;border-radius:14px;background:rgba(17,17,17,.96);box-shadow:0 18px 30px rgba(0,0,0,.35);z-index:24}.select__menu button{display:flex;align-items:center;gap:8px;width:100%;min-height:34px;padding:0 10px;border-radius:10px;background:transparent;color:rgba(255,255,255,.7);font-size:13px;text-align:left;white-space:nowrap}.select__ratio-preview{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;flex-shrink:0}.select__ratio-shape{display:block;border:1px solid rgba(255,255,255,.48);border-radius:2px;background:rgba(255,255,255,.06)}.select__menu button.is-active,.select__menu button:hover{background:rgba(255,255,255,.08);color:#fff}.submit{display:inline-flex;align-items:center;gap:12px;flex-shrink:0}.submit span{display:inline-flex;align-items:center;gap:5px;color:#a5a5a6;font-size:14px;white-space:nowrap}.submit button{width:32px;height:32px;border-radius:50%;background:#fff;color:#222;font-size:18px;font-weight:700}.submit button:disabled{opacity:.45;cursor:not-allowed}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes starTwinkle{0%{opacity:.28;transform:scale(1)}50%{opacity:.62;transform:scale(1.01)}100%{opacity:.95;transform:scale(1.02)}}
@media (max-width:1400px){.summary,.works{width:min(1000px,100%)}.grid.is-3,.grid.is-4{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:1100px){.header{align-items:flex-start;flex-direction:column;padding:18px 20px 14px}.header__actions{width:100%;justify-content:flex-start}.popover{right:20px}.sidebar{position:fixed;top:126px;left:20px;right:20px;height:auto;display:flex;flex-direction:row;flex-wrap:nowrap;gap:8px;overflow-x:auto;padding:6px;border:1px solid rgba(255,255,255,.08);border-radius:18px;background:rgba(8,8,10,.78);backdrop-filter:blur(14px);scrollbar-width:none}.sidebar::-webkit-scrollbar{display:none}.sidebar__item{width:68px;height:64px;border-radius:14px}.main{width:auto;margin:214px 20px 0}.summary,.works{width:100%;padding-inline:20px}.summary__filters{grid-template-columns:1fr;align-items:flex-start}.summary__tabs,.type-tabs{width:100%}.work__head,.composer,.composer__footer{flex-direction:column;align-items:flex-start}.composer-hotzone{left:20px;right:20px;width:auto;transform:none}.composer-drawer{left:20px;right:20px;width:auto;transform:translateY(calc(100% + 34px))}.composer-drawer.is-open{transform:translateY(0)}.composer{max-height:calc(100vh - 120px);overflow-y:auto}.composer__footer{padding-left:0}.options{margin-left:0;flex-wrap:wrap}.work-detail{padding:14px}.work-detail__panel{grid-template-columns:1fr;width:calc(100vw - 28px);max-height:calc(100vh - 28px);overflow-y:auto}.work-detail__media,.work-detail__media img,.work-detail__media video{min-height:300px}.work-detail__content{padding:18px 20px 22px}}
@media (max-width:820px){.sidebar{top:150px}.sidebar__item{width:64px}.page{padding-bottom:140px}.main{margin-top:236px;padding-bottom:120px}.works{padding-bottom:120px}.grid,.grid.is-2,.grid.is-3,.grid.is-4{grid-template-columns:1fr}.card{height:280px}.composer-hotzone{left:12px;right:12px}.composer-drawer{left:12px;right:12px;bottom:max(12px,env(safe-area-inset-bottom))}.composer{padding:14px 14px 14px 8px}.upload{width:100%;height:76px}.upload__box{height:66px}.options{margin-left:0;flex-wrap:wrap}.submit{width:100%;justify-content:space-between}}
</style>
