<template>
    <div
        ref="pageScrollRef"
        class="ai-app-page"
        :class="{ 'is-immersive-home': isImmersiveHome }"
    >
        <template v-if="immersiveBackgroundItems.length && immersiveBackgroundType === 'video'">
            <video
                v-for="(item, index) in immersiveBackgroundItems"
                :key="`immersive-video-${item.sourceIndex}-${item.url}`"
                class="ai-app-page__media-bg"
                :class="{ 'is-active': index === activeImmersiveBackgroundIndex }"
                :src="item.url"
                :poster="item.poster || undefined"
                autoplay
                muted
                loop
                playsinline
                preload="auto"
                @error="handleImmersiveMediaError(item.sourceIndex)"
            ></video>
        </template>
        <template v-else-if="immersiveBackgroundItems.length && immersiveBackgroundType === 'image'">
            <img
                v-for="(item, index) in immersiveBackgroundItems"
                :key="`immersive-image-${item.sourceIndex}-${item.url}`"
                class="ai-app-page__media-bg"
                :class="{ 'is-active': index === activeImmersiveBackgroundIndex }"
                :src="item.url"
                alt=""
                @error="handleImmersiveMediaError(item.sourceIndex)"
            />
        </template>
        <div class="ai-app-page__background" :style="backgroundStyle"></div>
        <div class="ai-app-page__noise"></div>
        <div class="ai-app-page__stars ai-app-page__stars--near"></div>
        <div class="ai-app-page__stars ai-app-page__stars--far"></div>
        <div
            v-if="isImmersiveHome"
            class="ai-app-page__scroll-mask"
            :style="{ opacity: immersiveScrollMaskOpacity }"
        ></div>

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
            <section v-if="isImmersiveHome" class="immersive-stage yike-home-page" aria-label="首页主视觉">
                <h1 class="yike-home-page__title">{{ immersiveMainTitle }}</h1>
                <p v-if="immersiveSubtitle" class="yike-home-page__subtitle">{{ immersiveSubtitle }}</p>

                <div
                    class="light-beam-wrapper immersive-cta-shell"
                >
                    <GlassSurface
                        class="glassmorphism-button immersive-cta__button"
                        tag="button"
                        type="button"
                        v-bind="getGlassSurfaceProps('cta')"
                        @click="openHomeEntry(immersiveCtaEntry)"
                    >
                        <span class="glassmorphism-button__content immersive-cta__content">
                            <span class="immersive-cta__text">使用{{ immersiveCtaText }}</span>
                        </span>
                    </GlassSurface>
                </div>

                <div class="features-container" aria-label="快捷入口">
                    <div
                        v-for="entry in immersiveFeatureEntries"
                        :key="entry.id"
                        class="light-beam-wrapper feature-beam-wrapper"
                    >
                        <GlassSurface
                            class="glassmorphism-button home-glass-card"
                            tag="button"
                            type="button"
                            v-bind="getGlassSurfaceProps('feature')"
                            @click="openHomeEntry(entry)"
                        >
                            <span class="home-glass-card__preview" aria-hidden="true">
                                <img
                                    v-for="(image, imageIndex) in entry.images.slice(0, 3)"
                                    :key="`${entry.id}-preview-${imageIndex}`"
                                    :src="image"
                                    alt=""
                                    :style="{ '--preview-index': imageIndex }"
                                />
                            </span>
                            <span class="glassmorphism-button__content home-glass-card__content">
                                <strong>{{ entry.title }}</strong>
                                <small>{{ entry.description }}</small>
                                <i aria-hidden="true">↗</i>
                            </span>
                        </GlassSurface>
                    </div>
                </div>
            </section>

            <section v-else class="home-hero">
                <button class="home-banner" type="button" @click="openHomeEntry(activeBanner)">
                    <span class="home-banner__copy">
                        <strong>{{ activeBanner.title }}</strong>
                        <small>{{ activeBanner.description }}</small>
                    </span>
                    <span class="home-banner__backdrop">
                        <video
                            v-if="activeBanner.mediaType === 'video' && activeBanner.mediaUrl"
                            :src="activeBanner.mediaUrl"
                            :poster="activeBanner.images[0] || undefined"
                            autoplay
                            muted
                            loop
                            playsinline
                        ></video>
                        <img v-else :src="activeBanner.images[0]" alt="" />
                    </span>
                    <span class="home-banner__media">
                        <img
                            v-for="(image, index) in activeBanner.images"
                            :key="`${activeBanner.id}-${index}`"
                            :src="image"
                            alt=""
                            :style="{ '--banner-index': index }"
                        />
                    </span>
                    <span class="home-banner__dots">
                        <i
                            v-for="(banner, index) in homeBanners"
                            :key="banner.id"
                            :class="{ 'is-active': activeBannerIndex === index }"
                        ></i>
                    </span>
                </button>

                <button class="home-tv-card" type="button" @click="openHomeEntry(homeFeatureEntries[0])">
                    <span class="home-tv-card__copy">
                        <strong>{{ homeFeatureEntries[0].title }}</strong>
                        <small>{{ homeFeatureEntries[0].description }}</small>
                    </span>
                    <span class="home-tv-card__collage">
                        <img
                            v-for="(image, index) in homeFeatureEntries[0].images.slice(0, 4)"
                            :key="`${homeFeatureEntries[0].id}-${index}`"
                            :src="image"
                            alt=""
                            :style="{ '--collage-index': index }"
                        />
                    </span>
                    <i>→</i>
                </button>

                <div class="home-quick-stack" aria-label="快捷入口">
                    <button
                        v-for="entry in homeFeatureEntries.slice(1, 3)"
                        :key="entry.id"
                        class="home-quick-card"
                        type="button"
                        @click="openHomeEntry(entry)"
                    >
                        <span class="home-quick-card__copy">
                            <strong>{{ entry.title }}</strong>
                            <small>{{ entry.description }}</small>
                        </span>
                        <span class="home-quick-card__media">
                            <video
                                v-if="entry.mediaUrl"
                                :src="entry.mediaUrl"
                                :poster="entry.images[0]"
                                muted
                                loop
                                playsinline
                                preload="metadata"
                                @mouseenter="playInspirationVideo"
                                @mouseleave="pauseInspirationVideo"
                            ></video>
                            <img v-else :src="entry.images[0]" alt="" />
                        </span>
                        <i>→</i>
                    </button>
                </div>
            </section>

            <div v-if="isImmersiveHome" class="yike-tools-heading">推荐工具</div>

            <section class="model-carousel" :style="inspirationGridStyle" aria-label="工具轮播">
                <button class="model-carousel__arrow model-carousel__arrow--left" type="button" aria-label="向左滑动" @click="scrollModelStrip('left')">
                    ‹
                </button>
                <div ref="modelStripRef" class="model-strip">
                    <article
                        v-for="item in homeModelCards"
                        :key="item.id"
                        class="model-card"
                        @click="openHomeModelCard(item)"
                    >
                        <img :src="item.image" :alt="item.title" />
                        <span v-if="item.badge" class="model-card__badge">{{ item.badge }}</span>
                        <button type="button" aria-label="打开">↗</button>
                        <div class="model-card__body">
                            <strong>{{ item.title }}</strong>
                            <small>{{ item.description }}</small>
                            <em>▷ {{ item.count }}</em>
                        </div>
                    </article>
                </div>
                <button class="model-carousel__arrow model-carousel__arrow--right" type="button" aria-label="向右滑动" @click="scrollModelStrip('right')">
                    ›
                </button>
            </section>

            <div v-if="isImmersiveHome" class="yike-discovery-tabs" aria-label="发现分类">
                <button
                    v-for="tab in yikeDiscoveryTabs"
                    :key="tab.key"
                    :class="{ 'is-active': activeHomeFeed === tab.key }"
                    type="button"
                    @click="setHomeFeed(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </div>

            <section ref="inspirationBoardRef" class="inspiration-board">
                <div class="inspiration-board__toolbar">
                    <div class="inspiration-tabs">
                        <button
                            v-for="tab in homeFeedTabs"
                            :key="tab.key"
                            :class="['inspiration-tabs__item', { 'is-active': activeHomeFeed === tab.key }]"
                            type="button"
                            @click="setHomeFeed(tab.key)"
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
                                    做同款
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
                                    @loadedmetadata="handleDetailVideoMetadata"
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
import { parseTenantIdFromRoute } from '@/utils/tenant'
import feedback from '@/utils/feedback'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { useAppStore } from '@/stores/app'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import GlassSurface from './GlassSurface.vue'
import { useAiUserDisplay } from '~/composables/useAiUserDisplay'
import {
    buildToolCardPath,
    featuredTools,
    isToolCardImplemented,
    toolCards,
    toolComingSoonMessage
} from '~/composables/use-ai-tools'
import { useAiPcHomeDecorate } from '~/composables/useAiPcHomeDecorate'
import closeSmallIcon from '@/assets/images/icon/Close-small.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'

type GenerationMode = AiGenerationMode
type FeedTabKey = 'all' | 'image' | 'video'
type HomeFeedKey = 'all' | 'model' | 'app' | 'effect' | 'inspiration' | 'workflow' | 'image' | 'video'
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
    specs?: any[]
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

interface HomeHeroEntry {
    id: string
    title: string
    description: string
    badge?: string
    mode?: GenerationMode
    route?: string
    images: string[]
    mediaType?: 'image' | 'video'
    mediaUrl?: string
}

interface HomeModelCard {
    id: string
    title: string
    description: string
    badge: string
    count: string
    image: string
    mediaUrl?: string
    toolId?: string
    mode?: GenerationMode
    route?: string
}

const router = useRouter()
const route = useRoute()
const { ensurePcLogin } = usePcLoginGate()
const userStore = useUserStore()
const appStore = useAppStore()
const { displayAvatarUrl, displayNickname } = useAiUserDisplay()
const { setDraft } = useAiCreateWorks()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()
const {
    homeBanners: decorateHomeBanners,
    displayToolCards,
    loadPcHomeDecorate,
    applyPcHomePageData
} = useAiPcHomeDecorate()

const fileInputRef = ref<HTMLInputElement | null>(null)
const pageScrollRef = ref<HTMLElement | null>(null)
const promptCardRef = ref<HTMLElement | null>(null)
const inspirationBoardRef = ref<HTMLElement | null>(null)
const modelStripRef = ref<HTMLElement | null>(null)
const floatingComposerRef = ref<{ collapseIfEmpty: () => void; focusTextarea: () => Promise<void> } | null>(null)
const detailVideoRef = ref<HTMLVideoElement | null>(null)

const prompt = ref('')
const generationMode = ref<GenerationMode>('image')
const optionState = ref<AiCreateOptionState>(createAiCreateOptionState())
const uploadedAssets = ref<UploadedAsset[]>([])
const activeSidebar = ref<SidebarKey>('inspiration')
const activePopover = ref<PopoverKey>('')
const activeInspirationTab = ref<FeedTabKey>('all')
const activeHomeFeed = ref<HomeFeedKey>('all')
const inspirationQuery = ref('')
const inspirationColumnCount = ref(6)
const selectedCardKey = ref('')
const caseCards = ref<CardItem[]>([])
const detailOpen = ref(false)
const activeDetailCard = ref<CardItem | null>(null)
const detailVideoRatioMap = ref<Record<string, string>>({})
const showFloatingComposer = ref(false)
const submitting = ref(false)
const uploading = ref(false)
const pageScrollThumbTop = ref(0)
const pageScrollThumbHeight = ref(64)
const pageScrollThumbVisible = ref(false)
const pageScrollThumbDragging = ref(false)
const pageScrollThumbPointerOffset = ref(0)
const activeBannerIndex = ref(0)
const activeImmersiveBackgroundIndex = ref(0)
const immersiveMediaFailedIndexes = ref<number[]>([])
const immersiveScrollMaskOpacity = ref(0)
let bannerTimer: ReturnType<typeof window.setInterval> | null = null
let immersiveBackgroundTimer: ReturnType<typeof window.setInterval> | null = null

const quickTags = ['电影感', '写实质感', '高级光影', '细节丰富']
const cardAuthorPool = ['清澈灵感', '南汐', '可可', '安安', '林琼', '朝野', '浮光', '念初']
const cardModelPool = ['AIGC 图片生成', 'Seed 2.0 Pro', '写实人像引擎', '叙事镜头引擎']
const homeFeedTabs: Array<{ key: HomeFeedKey; label: string }> = [
    { key: 'all', label: '综合' },
    { key: 'model', label: 'AI模型' },
    { key: 'app', label: 'AI应用' },
    { key: 'effect', label: '视频特效' },
    { key: 'inspiration', label: '灵感' },
    { key: 'workflow', label: '工作流' }
]
const yikeDiscoveryTabs: Array<{ key: HomeFeedKey; label: string }> = [
    { key: 'all', label: '发现' },
    { key: 'app', label: '广告/营销' },
    { key: 'video', label: '剧场' },
    { key: 'effect', label: '美学' }
]
const getToolImage = (index: number) => toolCards[index]?.image || featuredTools[index]?.image || toolCards[0]?.image || ''
const homeHeroEntries: HomeHeroEntry[] = [
    {
        id: 'workspace',
        title: 'AI TV',
        description: '全新功能，助力短剧创作。',
        route: '/ai/create',
        images: [getToolImage(0), getToolImage(1), getToolImage(2)]
    },
    {
        id: 'video',
        title: '视频生成',
        description: '创意视频，一键生成',
        mode: 'video',
        images: [getToolImage(3)]
    },
    {
        id: 'image',
        title: '图片生成',
        description: '智能绘制，即刻成图',
        mode: 'image',
        images: [getToolImage(4)]
    },
    {
        id: 'intro',
        title: 'AI 工具',
        description: '精选应用与效率工具',
        route: '/ai/tools',
        images: [getToolImage(5), getToolImage(6), getToolImage(7)]
    },
    {
        id: 'avatar',
        title: '数字人',
        description: '创建你的虚拟出镜人',
        route: '/ai/avatar',
        images: [getToolImage(8), getToolImage(2), getToolImage(4)]
    },
    {
        id: 'assets',
        title: '资产库',
        description: '管理作品与素材',
        route: '/ai/assets',
        images: [getToolImage(9), getToolImage(1), getToolImage(3)]
    }
]
const homeEntryAppCodeMap: Record<string, string> = {
    video: 'aigc_video',
    image: 'aigc_image',
    avatar: 'aigc_digital_human',
    workspace: 'aigc_video'
}
const fallbackCaseCards = computed<CardItem[]>(() =>
    displayToolCards.value.slice(0, 18).map((item, index) => {
        const isVideo = /视频|短片|数字人/.test(`${item.title}${item.detailDescription}${item.appPath}`)
        const appCode: CardItem['appCode'] = /数字人/.test(`${item.title}${item.appPath}`)
            ? 'aigc_digital_human'
            : isVideo
                ? 'aigc_video'
                : 'aigc_image'
        const category: CardItem['category'] = isVideo ? 'video' : 'image'
        return {
            id: 9000 + index,
            uniqueId: `fallback-${item.id}`,
            title: item.title,
            category,
            appCode,
            image: item.image,
            imageHeight: 280 + (index % 4) * 44,
            aspectRatio: index % 3 === 0 ? '3 / 4' : index % 3 === 1 ? '4 / 5' : '1 / 1',
            prompt: item.detailDescription || item.description || item.title,
            configFields: [appCode === 'aigc_video' ? '视频生成' : '图片生成', item.badge || '推荐'],
            generationOptions: {
                model: item.title,
                ratio: index % 3 === 2 ? '1:1' : '3:4',
                resolution: '1k',
                duration: appCode === 'aigc_video' ? '5秒' : undefined,
                count: appCode === 'aigc_video' ? '1条' : '1张'
            },
            hasReferenceAsset: false,
            authorName: cardAuthorPool[index % cardAuthorPool.length]
        }
    })
)
const displayCaseCards = computed(() => caseCards.value.length ? caseCards.value : fallbackCaseCards.value)
const homeFeatureEntries = computed<HomeHeroEntry[]>(() => {
    const sourceCards = displayCaseCards.value
    const imageCases = sourceCards.filter((item) => item.category === 'image')
    const videoCases = sourceCards.filter((item) => item.category === 'video')
    const mixedCases = sourceCards.length ? sourceCards : []

    return homeHeroEntries.map((entry, index) => {
        const appCode = homeEntryAppCodeMap[entry.id]
        const displayTool = appCode
            ? displayToolCards.value.find((tool) => tool.appCode === appCode)
            : null
        const pool = entry.id === 'video'
            ? videoCases
            : entry.id === 'image'
                ? imageCases
                : mixedCases
        const caseItem = pool[index % Math.max(pool.length, 1)]
        const images = [
            displayTool?.image,
            caseItem?.image,
            ...entry.images
        ].filter(Boolean)

        return {
            ...entry,
            title: displayTool?.title || entry.title,
            description: displayTool?.detailDescription || entry.description,
            images: images.length ? images : entry.images,
            mediaUrl: caseItem?.mediaUrl
        }
    })
})
const homeBanners = computed<HomeHeroEntry[]>(() => {
    if (decorateHomeBanners.value.length) {
        return decorateHomeBanners.value.map((item) => ({
            id: item.id,
            title: item.title,
            description: item.description,
            route: item.route || '/ai/tools',
            images: item.images,
            mediaType: item.mediaType,
            mediaUrl: item.mediaUrl
        }))
    }
    const sourceCards = displayCaseCards.value
    const dynamic = sourceCards.slice(0, 4).map((item, index) => ({
        id: `banner-${item.uniqueId}`,
        title: index === 0 ? 'AI 创作介绍' : item.title,
        description: item.category === 'video' ? '一站式 AI 创作平台' : '模型、应用、资产与灵感统一入口',
        route: '/ai/tools',
        images: [
            item.image,
            sourceCards[index + 1]?.image || getToolImage(index + 3),
            sourceCards[index + 2]?.image || getToolImage(index + 5)
        ].filter(Boolean)
    }))
    return dynamic.length ? dynamic : [homeHeroEntries[3]]
})
const activeBanner = computed(() => homeBanners.value[activeBannerIndex.value % homeBanners.value.length] || homeHeroEntries[3])

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

const resolveWebsiteMedia = (url: unknown) => {
    const normalized = normalizeFileUrl(typeof url === 'string' ? url : '')
    if (!normalized) return ''
    if (/^(https?:\/\/|data:|blob:)/i.test(normalized)) return normalized
    if (normalized.startsWith('/')) {
        const domain = String(appStore.config?.domain || '').replace(/\/$/, '')
        return domain ? `${domain}${normalized}` : normalized
    }
    return normalized
}
const toCssUrl = (url: string) => `url("${url.replace(/"/g, '\\"')}")`
const normalizeWebsiteMediaList = (value: unknown) => {
    const list = Array.isArray(value) ? value : value ? [value] : []
    return list
        .map((item) => resolveWebsiteMedia(item))
        .filter(Boolean)
}
const websiteConfig = computed(() => appStore.getWebsiteConfig || {})
const isImmersiveHome = computed(() => String(websiteConfig.value.pc_home_style || 'default') === 'immersive')
const immersiveBackgroundType = computed(() => String(websiteConfig.value.pc_home_bg_type || 'none'))
const immersiveBackgroundUrls = computed(() => normalizeWebsiteMediaList(websiteConfig.value.pc_home_bg_url || websiteConfig.value.pc_home_bg))
const immersiveVideoPosterUrls = computed(() => normalizeWebsiteMediaList(websiteConfig.value.pc_home_bg_poster_url || websiteConfig.value.pc_home_bg_poster))
const glassSurfaceConfig = {
    borderWidth: 0.07,
    brightness: 50,
    opacity: 0.93,
    blur: 11,
    displace: 0.5,
    backgroundOpacity: 0,
    saturation: 1,
    distortionScale: -180,
    redOffset: 0,
    greenOffset: 10,
    blueOffset: 20,
    xChannel: 'R',
    yChannel: 'G',
    mixBlendMode: 'difference'
} as const
const immersiveBackgroundItems = computed(() => {
    if (!isImmersiveHome.value || !['image', 'video'].includes(immersiveBackgroundType.value)) {
        return []
    }
    return immersiveBackgroundUrls.value
        .map((url, index) => ({
            url,
            sourceIndex: index,
            poster: immersiveBackgroundType.value === 'video' ? immersiveVideoPosterUrls.value[index] || immersiveVideoPosterUrls.value[0] || '' : ''
        }))
        .filter((item) => !immersiveMediaFailedIndexes.value.includes(item.sourceIndex))
})
const activeImmersiveBackground = computed(() => immersiveBackgroundItems.value[activeImmersiveBackgroundIndex.value] || immersiveBackgroundItems.value[0])
const getGlassSurfaceProps = (scope: 'cta' | 'feature') => ({
    width: scope === 'cta' ? '17.5rem' : '100%',
    height: scope === 'cta' ? '4rem' : '100%',
    borderRadius: scope === 'cta' ? 20 : 16,
    ...glassSurfaceConfig
})
const findHomeTool = (appCode: string) => displayToolCards.value.find((tool) => tool.appCode === appCode)
const buildImmersiveEntry = (payload: {
    id: string
    title: string
    description: string
    badge: string
    appCode: string
    route: string
    fallbackImageIndex: number
}): HomeHeroEntry => {
    const tool = findHomeTool(payload.appCode)
    const relatedCases = displayCaseCards.value
        .filter((item) => {
            if (payload.appCode === 'aigc_image') return item.category === 'image'
            if (payload.appCode === 'aigc_video') return item.category === 'video'
            if (payload.appCode === 'image_human') return item.appCode === 'aigc_digital_human'
            return true
        })
        .slice(0, 4)
    return {
        id: payload.id,
        title: payload.title,
        description: payload.description,
        badge: payload.badge,
        route: payload.route,
        images: [
            tool?.image,
            ...relatedCases.map((item) => item.image),
            getToolImage(payload.fallbackImageIndex)
        ].filter(Boolean)
    }
}
const handleImmersiveMediaError = (index: number) => {
    if (!immersiveMediaFailedIndexes.value.includes(index)) {
        immersiveMediaFailedIndexes.value = [...immersiveMediaFailedIndexes.value, index]
    }
}
const immersiveDefaultTitle = 'OPC社区专属，AI创业平台'
const immersiveDefaultSubtitle = '一个人就是一支团队'
const immersiveMainTitle = computed(() => String(websiteConfig.value.pc_home_immersive_title || '').trim() || immersiveDefaultTitle)
const immersiveSubtitle = computed(() => String(websiteConfig.value.pc_home_immersive_subtitle || '').trim() || immersiveDefaultSubtitle)
const immersiveCtaText = computed(() => 'HappyHorse1.0')
const immersiveCtaEntry = computed<HomeHeroEntry>(() => ({
    id: 'happyhorse-video',
    title: 'HappyHorse1.0',
    description: '进入视频生成并选中 HappyHorse 模型',
    badge: 'VIDEO',
    route: '/ai/create?type=video&model=HappyHorse1.0&channel=happyhorse',
    images: [findHomeTool('aigc_video')?.image, getToolImage(3)].filter(Boolean)
}))
const immersiveFeatureEntries = computed<HomeHeroEntry[]>(() => [
    buildImmersiveEntry({
        id: 'image',
        title: '图片生成',
        description: '智能绘制，即刻成图',
        badge: '图片',
        appCode: 'aigc_image',
        route: '/ai/create?type=image',
        fallbackImageIndex: 4
    }),
    buildImmersiveEntry({
        id: 'video',
        title: '视频生成',
        description: '创意视频，一键生成',
        badge: '视频',
        appCode: 'aigc_video',
        route: '/ai/create?type=video',
        fallbackImageIndex: 3
    }),
    buildImmersiveEntry({
        id: 'image-human',
        title: '全驱动数字人',
        description: '图片与音频驱动数字人视频',
        badge: '数字人',
        appCode: 'image_human',
        route: '/ai/avatar?tab=image_human',
        fallbackImageIndex: 2
    }),
    buildImmersiveEntry({
        id: 'canvas',
        title: '无限画布',
        description: '节点编排，组织复杂创作流程',
        badge: '画布',
        appCode: 'aigc_canvas',
        route: '/app/aigc_canvas',
        fallbackImageIndex: 1
    })
])
const backgroundStyle = computed(() => {
    if (!isImmersiveHome.value) {
        return {
            backgroundImage: 'linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%)'
        }
    }
    if (immersiveBackgroundItems.value.length) {
        const poster = activeImmersiveBackground.value?.poster
        if (immersiveBackgroundType.value === 'video') {
            return {
                backgroundImage: poster
                    ? `linear-gradient(90deg, rgba(0,0,0,.72) 0%, rgba(0,0,0,.18) 34%, rgba(0,0,0,.08) 66%, rgba(0,0,0,.48) 100%), linear-gradient(180deg, rgba(0,0,0,.08) 0%, rgba(0,0,0,.08) 62%, rgba(0,0,0,.82) 100%)`
                    : 'linear-gradient(90deg, rgba(0,0,0,.72) 0%, rgba(0,0,0,.18) 34%, rgba(0,0,0,.08) 66%, rgba(0,0,0,.48) 100%), linear-gradient(180deg, rgba(0,0,0,.08) 0%, rgba(0,0,0,.08) 62%, rgba(0,0,0,.82) 100%)'
            }
        }
        return {
            backgroundImage: `linear-gradient(90deg, rgba(0,0,0,.72) 0%, rgba(0,0,0,.18) 34%, rgba(0,0,0,.08) 66%, rgba(0,0,0,.48) 100%), linear-gradient(180deg, rgba(0,0,0,.08) 0%, rgba(0,0,0,.08) 62%, rgba(0,0,0,.82) 100%), ${toCssUrl(activeImmersiveBackground.value?.url || '')}`
        }
    }
    return {
        backgroundImage: 'radial-gradient(circle at 28% 30%, rgba(0, 139, 255, 0.36), transparent 28%), radial-gradient(circle at 50% 28%, rgba(204, 74, 255, 0.32), transparent 26%), radial-gradient(circle at 74% 24%, rgba(255, 127, 24, 0.34), transparent 30%), linear-gradient(180deg, #000 0%, #050505 60%, #000 100%)'
    }
})
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
    if (activeHomeFeed.value !== 'all') return `搜索${homeFeedTabs.find((tab) => tab.key === activeHomeFeed.value)?.label || '推荐'}`
    return '搜索作品'
})
const canGenerate = computed(() => Boolean(prompt.value.trim()) && !submitting.value && !uploading.value)
const canSubmit = computed(() => canGenerate.value)
const getRequestErrorMessage = (error: any, fallback: string) => {
    if (typeof error === 'string' && error.trim()) return error
    return error?.msg || error?.message || fallback
}
const homeModelCards = computed<HomeModelCard[]>(() => {
    return displayToolCards.value.slice(0, 12).map((item, index) => ({
        id: item.id,
        title: item.title,
        description: item.badge || item.detailDescription,
        badge: index === 0 || index === 4 ? '新上' : index === 1 ? '热门' : '',
        count: item.virtualUseCount || (index === 1 ? '2.3万' : `${390 + index * 217}`),
        image: item.image,
        toolId: item.id,
        route: item.appPath
    }))
})

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
const getVideoSpecResolution = (spec: any) =>
    normalizeVideoResolution(spec?.resolution || spec?.provider_params_json?.resolution || spec?.provider_params_json?.quality || spec?.label || spec?.quality_label || spec?.value || spec?.quality)
const getVideoSpecRatio = (spec: any) =>
    String(spec?.ratio || spec?.value || spec?.provider_params_json?.ratio || spec?.provider_params_json?.aspect_ratio || spec?.provider_params_json?.size || '').trim()
const normalizeExplicitVideoDuration = (value: unknown) => {
    const raw = String(value || '').trim()
    if (!raw) return ''
    const explicit = raw.match(/(\d+)\s*(?:S|秒)\b/i)
    if (explicit) return `${Number(explicit[1])}秒`
    const underscored = raw.match(/_(\d+)(?:\D*)$/)
    const matched = underscored || raw.match(/^(\d+)$/)
    return matched ? `${Number(matched[1])}秒` : ''
}
const getVideoSpecDuration = (spec: any) =>
    normalizeExplicitVideoDuration(spec?.duration ?? spec?.provider_params_json?.duration ?? spec?.quality ?? spec?.quality_label)
const getVideoSpecDurationValue = (spec: any) => durationValue(getVideoSpecDuration(spec))
const normalizePricingVariant = (value: unknown) => String(value || '').trim().toLowerCase().replace(/[\s_-]+/g, '')
const getVideoSpecPricingVariant = (spec: any) =>
    normalizePricingVariant(spec?.provider_params_json?._pricing_variant || spec?.provider_params_json?.pricing_variant || '')
const currentVideoPricingVariant = () => {
    if (selectedVideoChannelCode.value !== 'seedance') return ''
    return 'withoutvideo'
}
const videoSpecMatchesPricingVariant = (spec: any) => {
    const variant = currentVideoPricingVariant()
    if (!variant) return true
    const specVariant = getVideoSpecPricingVariant(spec)
    return !specVariant || specVariant === variant
}

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
            specs: channel.specs || [],
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
const currentVideoChannelSpecs = computed(() => currentVideoChannel.value?.specs || [])
const videoSpecsByResolution = computed(() => {
    const resolution = String(optionState.value.resolution || videoResolutions.value[0] || '默认')
    const matched = currentVideoChannelSpecs.value.filter((spec: any) => String(getVideoSpecResolution(spec) || '默认') === resolution)
    return matched.length ? matched : currentVideoChannelSpecs.value
})
const videoSpecsByResolutionAndRatio = computed(() => {
    const matched = videoSpecsByResolution.value.filter((spec: any) => getVideoSpecRatio(spec) === optionState.value.ratio)
    return matched.length ? matched : videoSpecsByResolution.value
})
const supportedVideoDurationsBySpec = computed(() =>
    Array.from(new Set(videoSpecsByResolutionAndRatio.value.filter(videoSpecMatchesPricingVariant).map(getVideoSpecDuration).filter(Boolean)))
        .sort((a, b) => durationValue(a) - durationValue(b))
)
const videoDurations = computed(() => {
    if (currentVideoChannelHasDynamicDuration.value) {
        if (currentVideoChannelSpecs.value.length && supportedVideoDurationsBySpec.value.length) {
            return supportedVideoDurationsBySpec.value
        }
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
const videoRatios = computed<RatioOption[]>(() => {
    const qualityRatios = currentVideoQuality.value?.ratios || []
    if (!currentVideoChannelHasDynamicDuration.value || !currentVideoChannelSpecs.value.length) {
        return qualityRatios
    }
    const duration = durationValue(optionState.value.duration)
    const matchedSpecs = duration > 0
        ? videoSpecsByResolution.value.filter((spec: any) => {
            const specDuration = getVideoSpecDurationValue(spec)
            return videoSpecMatchesPricingVariant(spec) && (specDuration === duration || specDuration <= 0)
        })
        : videoSpecsByResolution.value
    const ratioSpecs = matchedSpecs.length ? matchedSpecs : videoSpecsByResolution.value
    const ratioValues = new Set(ratioSpecs.map(getVideoSpecRatio).filter(Boolean))
    const filteredRatios = qualityRatios.filter((item) => ratioValues.has(item.value))
    if (filteredRatios.length) return filteredRatios
    return ratioSpecs.map((spec: any) => ({
        ...spec,
        label: getVideoSpecRatio(spec),
        value: getVideoSpecRatio(spec)
    })).filter((item: RatioOption, index: number, list: RatioOption[]) =>
        item.value && list.findIndex((ratio) => ratio.value === item.value) === index
    )
})
const currentVideoRatio = computed(() => videoRatios.value.find((item) => item.value === optionState.value.ratio) || videoRatios.value[0])
const currentVideoSpec = computed(() => {
    if (!currentVideoChannelHasDynamicDuration.value) return currentVideoRatio.value
    const duration = durationValue(optionState.value.duration)
    if (!currentVideoChannelSpecs.value.length) return currentVideoRatio.value
    return currentVideoChannelSpecs.value.find((spec: any) =>
        getVideoSpecRatio(spec) === String(optionState.value.ratio)
        && String(getVideoSpecResolution(spec) || '默认') === String(currentVideoQuality.value?.resolution || '默认')
        && videoSpecMatchesPricingVariant(spec)
        && (getVideoSpecDurationValue(spec) === duration || getVideoSpecDurationValue(spec) <= 0)
    )
})
const selectedVideoUnitPrice = computed(() => Number(currentVideoSpec.value?.tenant_unit_price || currentVideoRatio.value?.tenant_unit_price || 0).toString())
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
    generationMode.value === 'image'
        ? `${selectedUnitPrice.value}/张`
        : `${selectedVideoUnitPrice.value}${selectedVideoChannelCode.value === 'seedance' ? '/百万Token' : selectedVideoChannelCode.value === 'happy_horse' ? '/秒' : '/次'}`
)

const filteredCards = computed(() => {
    const keyword = inspirationQuery.value.trim().toLowerCase()
    return displayCaseCards.value.filter((item) => {
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
    const actualRatio = getActiveDetailVideoRatio()
    if (activeDetailCard.value.configFields?.length) {
        return applyActualRatioToConfigFields(activeDetailCard.value.configFields, actualRatio)
    }
    const poolIndex = activeDetailCard.value.id - 1
    const model = cardModelPool[poolIndex % cardModelPool.length]
    return activeDetailCard.value.category === 'video'
        ? [model, actualRatio || '16:9', '5s', '720p']
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
const focusComposer = async () => {
    await nextTick()
    promptCardRef.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    await floatingComposerRef.value?.focusTextarea?.()
}
const openHomeEntry = (entry: HomeHeroEntry) => {
    const routeText = entry.route || ''
    if (routeText.includes('/ai/create') && routeText.includes('type=video') && /happyhorse/i.test(routeText)) {
        generationMode.value = 'video'
        setDraft(null)
        const channel = findCaseChannel({ model: 'HappyHorse1.0', channel: 'happyhorse' }, videoChannels.value)
        if (channel) selectedVideoChannelCode.value = channel.value
        syncAigcVideoSelection()
        router.push(routeText)
        return
    }
    if (entry.mode) {
        generationMode.value = entry.mode
        setDraft(null)
        router.push(buildSidebarRouteLocation('create'))
        return
    }
    if (entry.route) {
        if (/^https?:\/\//i.test(entry.route)) {
            window.open(entry.route, '_blank')
            return
        }
        router.push(entry.route)
    }
}
const openHomeModelCard = (item: HomeModelCard) => {
    if (item.mode) {
        generationMode.value = item.mode
        setDraft(null)
        router.push(buildSidebarRouteLocation('create'))
        return
    }
    const tool = item.toolId ? displayToolCards.value.find((card) => card.id === item.toolId) : null
    if (tool) {
        if (!isToolCardImplemented(tool)) {
            feedback.msgWarning(toolComingSoonMessage)
            return
        }
        router.push(tool.appPath || buildToolCardPath(tool))
        return
    }
    if (item.route) router.push(item.route)
}
const scrollModelStrip = (direction: 'left' | 'right') => {
    const node = modelStripRef.value
    if (!node) return
    const amount = Math.max(260, Math.floor(node.clientWidth * 0.72))
    node.scrollBy({
        left: direction === 'left' ? -amount : amount,
        behavior: 'smooth'
    })
}
const setHomeFeed = (key: HomeFeedKey) => {
    activeHomeFeed.value = key
    if (key === 'image' || key === 'video') {
        activeInspirationTab.value = key
        return
    }
    activeInspirationTab.value = 'all'
}

const refreshHomeWebsiteConfig = async () => {
    try {
        const tenantId = parseTenantIdFromRoute(route)
        await appStore.getConfig(tenantId, true)
    } catch (error) {
        console.warn('[pc-home] refresh website config failed', error)
    }
}
const appendTag = (tag: string) => {
    prompt.value = prompt.value ? `${prompt.value}，${tag}` : tag
}

const getAdaptiveInspirationColumns = (width: number) => {
    if (width < 810) return 2
    if (width < 1280) return 3
    if (width < 2200) return 5
    return 6
}
const updateInspirationColumnCount = () => {
    if (typeof window === 'undefined') return
    const width = inspirationBoardRef.value?.getBoundingClientRect().width || window.innerWidth
    inspirationColumnCount.value = getAdaptiveInspirationColumns(width)
}
const updateFloatingComposer = () => {
    showFloatingComposer.value = false
}
const updateImmersiveScrollMask = () => {
    if (typeof window === 'undefined' || !isImmersiveHome.value || !pageScrollRef.value || !inspirationBoardRef.value) {
        immersiveScrollMaskOpacity.value = 0
        return
    }

    const pageRect = pageScrollRef.value.getBoundingClientRect()
    const boardRect = inspirationBoardRef.value.getBoundingClientRect()
    const boardTop = boardRect.top - pageRect.top
    const viewportHeight = pageScrollRef.value.clientHeight || window.innerHeight
    const fadeStart = viewportHeight * 0.72
    const fadeEnd = viewportHeight * 0.42
    const rawProgress = (fadeStart - boardTop) / Math.max(fadeStart - fadeEnd, 1)
    const progress = Math.min(Math.max(rawProgress, 0), 1)
    immersiveScrollMaskOpacity.value = Number((progress * progress * (3 - 2 * progress)).toFixed(3))
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
    updateImmersiveScrollMask()
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
const validateVideoSelection = () => {
    if (!currentVideoChannel.value?.value) return '暂无可用视频模型'
    if (!videoDurations.value.includes(optionState.value.duration)) return '当前通道不支持所选时长'
    if (!currentVideoRatio.value?.value) return '请选择视频比例'
    if (currentVideoChannelHasDynamicDuration.value && currentVideoChannelSpecs.value.length && !currentVideoSpec.value) {
        return '当前通道不支持所选规格，请调整分辨率、比例或时长'
    }
    return ''
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

const ratioFieldPattern = /^\d+(\.\d+)?\s*[:/：]\s*\d+(\.\d+)?$/
const gcd = (a: number, b: number): number => {
    const x = Math.abs(Math.round(a))
    const y = Math.abs(Math.round(b))
    return y ? gcd(y, x % y) : x || 1
}
const formatVideoRatioFromSize = (width: number, height: number) => {
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return ''
    const divisor = gcd(width, height)
    return `${Math.round(width / divisor)}:${Math.round(height / divisor)}`
}
const getActiveDetailVideoRatio = () => {
    const item = activeDetailCard.value
    if (!item || item.category !== 'video') return ''
    return detailVideoRatioMap.value[item.uniqueId] || ''
}
const applyActualRatioToConfigFields = (fields: string[], actualRatio: string) => {
    if (!actualRatio) return fields
    let replaced = false
    const next = fields.map((field) => {
        if (ratioFieldPattern.test(field)) {
            replaced = true
            return actualRatio
        }
        return field
    })
    return replaced ? next : [...next, actualRatio]
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
const handleDetailVideoMetadata = (event: Event) => {
    const video = event.currentTarget as HTMLVideoElement | null
    const item = activeDetailCard.value
    if (video && item) {
        const ratio = formatVideoRatioFromSize(video.videoWidth, video.videoHeight)
        if (ratio) {
            detailVideoRatioMap.value = {
                ...detailVideoRatioMap.value,
                [item.uniqueId]: ratio
            }
        }
    }
    playDetailVideo()
}
const focusActiveComposer = async () => {
    await nextTick()
    router.push(buildSidebarRouteLocation('create'))
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
    if (!item.hasReferenceAsset) {
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
        feedback.msgSuccess('已同步到创作工作台')
        await focusActiveComposer()
        return
    }
    try {
        await syncCardReference(item)
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
        feedback.msgSuccess('提示词和参考图已同步到创作工作台')
        await focusActiveComposer()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgWarning(error?.msg || error?.message || '提示词已同步，参考图同步失败')
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
        await focusActiveComposer()
    }
}
const applyDetailPrompt = async () => {
    if (!activeDetailCard.value) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    const item = activeDetailCard.value
    syncCardPromptToComposer(item)
    if (!item.hasReferenceAsset) {
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
        feedback.msgSuccess('已同步到创作工作台')
        closeWorkDetail()
        await focusActiveComposer()
        return
    }
    try {
        await syncCardReference(item)
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
        feedback.msgSuccess('提示词和参考图已同步到创作工作台')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgWarning(error?.msg || error?.message || '提示词已同步，参考图同步失败')
        setDraft(buildCreateDraft(`case-${item.uniqueId}-${Date.now()}`))
    }
    closeWorkDetail()
    await focusActiveComposer()
}
const applyDetailReference = async () => {
    if (!activeDetailCard.value) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return
    generationMode.value = activeDetailCard.value.category
    try {
        await syncCardReference(activeDetailCard.value, 'work')
        setDraft(buildCreateDraft(`reference-${activeDetailCard.value.uniqueId}-${Date.now()}`))
        feedback.msgSuccess('参考图已同步到创作工作台')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图同步失败')
    }
    closeWorkDetail()
    await focusActiveComposer()
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
            feedback.msgError(getRequestErrorMessage(error, '提交生成任务失败'))
        } finally {
            feedback.closeLoading()
            submitting.value = false
        }
        return
    }

    syncAigcVideoSelection()
    const videoSelectionError = validateVideoSelection()
    if (videoSelectionError) {
        feedback.msgError(videoSelectionError)
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
        }, { suppressErrorMessage: true })
        setDraft(buildCreateDraft(String(res?.task_id || Date.now())))
        feedback.msgSuccess('已提交视频生成任务')
        router.push(buildSidebarRouteLocation('create'))
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(getRequestErrorMessage(error, '提交视频生成任务失败'))
    } finally {
        feedback.closeLoading()
        submitting.value = false
    }
}
const { lockFn: submitPromptLock } = useLockFn(submitPrompt)

const handleGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && detailOpen.value) closeWorkDetail()
}

const parsePreviewPageData = (value: any) => {
    if (Array.isArray(value)) return value
    if (typeof value !== 'string') return []
    try {
        const parsed = JSON.parse(value)
        return Array.isArray(parsed) ? parsed : []
    } catch (error) {
        return []
    }
}

const handlePcDecoratePreviewMessage = (event: MessageEvent) => {
    if (route.query.pc_diy !== '1') return
    const payload = event.data || {}
    if (payload?.type !== 'LIKEADMIN_PC_DECORATE_PREVIEW') return
    if (payload.page_code && payload.page_code !== 'pc_home') return
    applyPcHomePageData(parsePreviewPageData(payload.data))
    activeBannerIndex.value = 0
}

watch(generationMode, (mode) => {
    if (mode === 'image') syncAigcSelection()
    else syncAigcVideoSelection()
})
watch(activeInspirationTab, (tab) => {
    if (tab === 'all') return
    if (!displayCaseCards.value.some((item) => item.uniqueId === selectedCardKey.value && item.category === tab)) {
        const nextCard = displayCaseCards.value.find((item) => item.category === tab)
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
watch(
    () => [
        websiteConfig.value.pc_home_style,
        websiteConfig.value.pc_home_bg_type,
        websiteConfig.value.pc_home_bg,
        websiteConfig.value.pc_home_bg_url,
        websiteConfig.value.pc_home_bg_poster,
        websiteConfig.value.pc_home_bg_poster_url
    ],
    () => {
        activeImmersiveBackgroundIndex.value = 0
        immersiveMediaFailedIndexes.value = []
        nextTick(syncPageScrollUi)
    }
)

watch(
    () => immersiveBackgroundItems.value.length,
    (count) => {
        if (!count) {
            activeImmersiveBackgroundIndex.value = 0
            return
        }
        if (activeImmersiveBackgroundIndex.value >= count) {
            activeImmersiveBackgroundIndex.value = 0
        }
    }
)

onMounted(() => {
    nextTick(() => {
        syncPageScrollUi()
    })
    refreshHomeWebsiteConfig()
    loadAigcConfig()
    loadAigcVideoConfig()
    loadPcHomeDecorate()
    refreshCaseCards()
    if (typeof window !== 'undefined') {
        bannerTimer = window.setInterval(() => {
            const count = homeBanners.value.length || 1
            activeBannerIndex.value = (activeBannerIndex.value + 1) % count
        }, 4200)
        immersiveBackgroundTimer = window.setInterval(() => {
            const count = immersiveBackgroundItems.value.length
            if (count > 1) {
                activeImmersiveBackgroundIndex.value = (activeImmersiveBackgroundIndex.value + 1) % count
            }
        }, 7200)
    }
    pageScrollRef.value?.addEventListener('scroll', syncPageScrollUi, { passive: true })
    window.addEventListener('resize', syncPageScrollUi)
    window.addEventListener('keydown', handleGlobalKeydown)
    window.addEventListener('message', handlePcDecoratePreviewMessage)
})

onBeforeUnmount(() => {
    if (bannerTimer) window.clearInterval(bannerTimer)
    if (immersiveBackgroundTimer) window.clearInterval(immersiveBackgroundTimer)
    stopPageScrollThumbDrag()
    uploadedAssets.value.forEach(revokeUploadedAsset)
    pageScrollRef.value?.removeEventListener('scroll', syncPageScrollUi)
    window.removeEventListener('resize', syncPageScrollUi)
    window.removeEventListener('keydown', handleGlobalKeydown)
    window.removeEventListener('message', handlePcDecoratePreviewMessage)
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
    &__media-bg {
        position: fixed;
        inset: 0;
        pointer-events: none;
        will-change: opacity;
    }

    &__noise,
    &__stars,
    &__scroll-mask {
        position: fixed;
        inset: 0;
        pointer-events: none;
    }

    &__background {
        z-index: 0;
        background-position: center top;
        background-repeat: no-repeat;
        background-size: cover;
        opacity: 1;
    }

    &__media-bg {
        z-index: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 0;
        filter: saturate(1.18) contrast(1.08);
        transition: opacity 0.8s ease;
        transform: translateZ(0);

        &.is-active {
            opacity: 1;
        }
    }

    &__noise {
        z-index: 0;
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
        z-index: 0;
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

    &__scroll-mask {
        z-index: 0;
        background: #050505;
        opacity: 0;
        transition: opacity 0.08s linear;
        will-change: opacity;
    }
}

.ai-app-page.is-immersive-home {
    background: #050505;

    .ai-app-page__background {
        opacity: 1;
    }

    .ai-app-page__background::after {
        content: '';
        position: fixed;
        inset: 0;
        z-index: 0;
        pointer-events: none;
        background:
            linear-gradient(90deg, rgba(0, 0, 0, 0.78) 0%, transparent 19%, transparent 84%, rgba(0, 0, 0, 0.54) 100%),
            linear-gradient(180deg, rgba(0, 0, 0, 0.04) 0%, rgba(0, 0, 0, 0.03) 54%, rgba(0, 0, 0, 0.8) 100%);
    }

    .ai-app-page__media-bg {
        transform: none;
    }

    .ai-app-page__noise {
        display: none;
    }

    .ai-app-page__stars {
        display: none;
    }

    .app-main {
        min-height: 100vh;
        padding: 0 24px 96px 96px;
    }

    .model-carousel {
        margin-top: 18px;
    }

    .inspiration-board {
        margin-top: 20px;
    }

    .inspiration-board__toolbar {
        display: none;
    }
}

.yike-home-page {
    --yike-card-height: 98px;
    --yike-wrapper-height: 98px;
    --yike-wrapper-hover-height: 168px;
    --yike-feature-gap: clamp(0.625rem, 0.8vw, 1rem);
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    min-width: 0;
    min-height: 660px;
    margin: 0 auto;
    padding-top: 9rem;
    box-sizing: border-box;
}

.yike-home-page__title {
    width: 100%;
    max-width: 1120px;
    margin: 0 auto;
    color: rgba(255, 255, 255, 0.94);
    font-size: 3.875rem;
    font-weight: 600;
    line-height: 1.22;
    letter-spacing: 0;
    text-align: center;
    text-shadow: 0 16px 58px rgba(0, 0, 0, 0.56);
    overflow-wrap: anywhere;
}

.yike-home-page__subtitle {
    width: 100%;
    max-width: 880px;
    margin: 1rem auto 2.25rem;
    color: rgba(255, 255, 255, 0.76);
    font-size: 1.35rem;
    font-weight: 400;
    line-height: 1.65;
    letter-spacing: 0;
    text-align: center;
    text-shadow: 0 10px 36px rgba(0, 0, 0, 0.48);
    overflow-wrap: anywhere;
}

.immersive-cta-shell {
    position: relative;
    z-index: 3;
    width: 17.5rem;
    height: 4rem;
    min-width: 17.5rem;
    max-width: 17.5rem;
    min-height: 4rem;
    max-height: 4rem;
    margin: 0 auto;
    flex: none;
}

.immersive-cta__button {
    width: 17.5rem;
    height: 4rem;
    min-width: 17.5rem;
    max-width: 17.5rem;
    min-height: 4rem;
    max-height: 4rem;
    transform: none;
}

.immersive-cta__content {
    flex-flow: row;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 1rem;
    font-weight: 600;
}

.immersive-cta__text {
    position: relative;
    z-index: 6;
    white-space: nowrap;
}

.yike-tools-heading {
    position: relative;
    z-index: 3;
    width: 100%;
    max-width: 2028px;
    margin: 1rem auto 0.75rem;
    color: rgba(255, 255, 255, 0.92);
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.4;
    text-align: left;
    text-shadow: 0 8px 28px rgba(0, 0, 0, 0.52);
}

.features-container {
    position: relative;
    z-index: 3;
    display: flex;
    flex: none;
    flex-wrap: nowrap;
    align-items: flex-end;
    justify-content: center;
    gap: var(--yike-feature-gap);
    width: 100%;
    max-width: 2028px;
    height: var(--yike-wrapper-hover-height);
    margin: 5rem auto 0;
    overflow-y: visible;
}

.light-beam-wrapper {
    position: relative;
    flex: none;
    width: fit-content;
    height: var(--yike-wrapper-height);
    overflow: visible;
    isolation: isolate;
}

.feature-beam-wrapper {
    flex: 1 1 0;
    min-width: 0;
    width: auto;
    height: var(--yike-wrapper-height);
    max-width: none;
    min-height: var(--yike-wrapper-height);
    max-height: var(--yike-wrapper-hover-height);
    transform: none;
    transition:
        flex 0.38s cubic-bezier(0.16, 1, 0.3, 1),
        height 0.38s cubic-bezier(0.16, 1, 0.3, 1);
}

.feature-beam-wrapper:hover,
.feature-beam-wrapper:focus-within {
    flex: 1 1 0;
    height: var(--yike-wrapper-hover-height);
}

.glassmorphism-button {
    position: relative;
    display: flex;
    width: 100%;
    height: 100%;
    border-radius: 12px;
    border: 0;
    overflow: hidden;
    isolation: isolate;
    box-sizing: border-box;
    margin-top: 0;
    padding: 0;
    color: #fff;
    cursor: pointer;
    transform: none;
    transition: opacity 0.26s ease-out, box-shadow 0.26s ease-out;
}

:deep(.glassmorphism-button > .glass-surface__content) {
    position: absolute;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    overflow: hidden;
}

.glassmorphism-button::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 3;
    border-radius: inherit;
    background:
        radial-gradient(110% 130% at 12% 0%, rgba(255, 255, 255, 0.18) 0%, transparent 34%),
        radial-gradient(80% 120% at 86% 88%, rgba(255, 255, 255, 0.13) 0%, transparent 45%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.14), rgba(255, 255, 255, 0.03) 42%, rgba(0, 0, 0, 0.14));
    box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.34) inset,
        0 1px 0 rgba(255, 255, 255, 0.42) inset,
        0 -1px 0 rgba(255, 255, 255, 0.18) inset;
    mix-blend-mode: screen;
    opacity: 0.72;
    pointer-events: none;
}

.light-beam-wrapper.immersive-cta-shell {
    width: 17.5rem;
    height: 4rem;
}

.light-beam-wrapper.immersive-cta-shell .glassmorphism-button {
    width: 17.5rem;
    height: 4rem;
}

.feature-beam-wrapper .glassmorphism-button {
    position: absolute;
    bottom: 0;
    left: 0;
    flex: none;
    width: 100%;
    height: 100%;
}

.feature-beam-wrapper:hover .glassmorphism-button {
    width: 100%;
    height: 100%;
    min-width: 0;
    max-width: none;
    min-height: 0;
    max-height: none;
    transform: none;
}

.glassmorphism-button__content {
    position: relative;
    z-index: 5;
    display: flex;
    flex-flow: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
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

.home-glass-card {
    background: rgba(255, 255, 255, 0.01);
    flex: none;
    width: 100%;
    height: 100%;
    min-width: 0;
    max-width: none;
    min-height: 0;
    max-height: none;
    padding: 0;
    color: #fff;
    text-align: left;
    cursor: pointer;
    transform: none;
}

.home-glass-card::after {
    opacity: 0.68;
}

.immersive-cta__button::after {
    z-index: 4;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.42), rgba(255, 255, 255, 0.06) 12%, transparent 34%),
        linear-gradient(90deg, rgba(255, 255, 255, 0.22), transparent 10%, transparent 90%, rgba(255, 255, 255, 0.16)),
        radial-gradient(100% 120% at 50% 50%, rgba(255, 255, 255, 0.12), transparent 48%);
    box-shadow:
        0 0 2px 1px rgba(255, 255, 255, 0.35) inset,
        0 0 10px 4px rgba(255, 255, 255, 0.15) inset,
        0 4px 16px rgba(17, 17, 26, 0.05) inset,
        0 8px 24px rgba(17, 17, 26, 0.05) inset,
        0 16px 56px rgba(17, 17, 26, 0.05) inset;
    opacity: 0.28;
    mix-blend-mode: screen;
}

.feature-beam-wrapper .home-glass-card {
    border-radius: 16px;
    transform: none;
    transition:
        opacity 0.26s ease-out,
        box-shadow 0.26s ease-out,
        height 0.34s cubic-bezier(0.16, 1, 0.3, 1);
}

.feature-beam-wrapper .home-glass-card::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 2;
    border-radius: inherit;
    background:
        radial-gradient(80% 95% at 50% 50%, rgba(255, 255, 255, 0.14), transparent 56%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.09), transparent 44%, rgba(255, 255, 255, 0.035));
    opacity: 0.16;
    mix-blend-mode: screen;
    pointer-events: none;
    transition: opacity 0.28s ease;
}

.feature-beam-wrapper .home-glass-card::after {
    z-index: 4;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.42), rgba(255, 255, 255, 0.06) 12%, transparent 34%),
        linear-gradient(90deg, rgba(255, 255, 255, 0.22), transparent 10%, transparent 90%, rgba(255, 255, 255, 0.16)),
        radial-gradient(100% 120% at 50% 50%, rgba(255, 255, 255, 0.12), transparent 48%);
    box-shadow:
        0 0 2px 1px rgba(255, 255, 255, 0.35) inset,
        0 0 10px 4px rgba(255, 255, 255, 0.15) inset,
        0 4px 16px rgba(17, 17, 26, 0.05) inset,
        0 8px 24px rgba(17, 17, 26, 0.05) inset,
        0 16px 56px rgba(17, 17, 26, 0.05) inset;
    opacity: 0.28;
    mix-blend-mode: screen;
}

.feature-beam-wrapper:hover .home-glass-card,
.feature-beam-wrapper:focus-within .home-glass-card {
    width: 100%;
    height: 100%;
    min-width: 0;
    max-width: none;
    min-height: 0;
    max-height: none;
    transform: none;
}

.feature-beam-wrapper:hover .home-glass-card::before,
.feature-beam-wrapper:focus-within .home-glass-card::before {
    opacity: 0.16;
}

.home-glass-card__content {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    overflow: hidden;
    max-width: 100%;
    padding: 0 3.25rem 0 1.25rem;
    text-align: left;
    transition:
        max-width 0.34s cubic-bezier(0.16, 1, 0.3, 1),
        padding 0.28s ease,
        justify-content 0.28s ease;
}

.feature-beam-wrapper:hover .home-glass-card__content,
.feature-beam-wrapper:focus-within .home-glass-card__content {
    justify-content: center;
    max-width: min(42%, 15rem);
    padding: 0 0 0 1.25rem;
}

.home-glass-card__content strong {
    position: relative;
    z-index: 6;
    display: block;
    max-width: 100%;
    overflow: hidden;
    color: #fff;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.25;
    letter-spacing: 0;
    text-overflow: ellipsis;
    white-space: nowrap;
    text-shadow:
        0 2px 14px rgba(0, 0, 0, 0.8),
        0 0 28px rgba(0, 0, 0, 0.48);
    transition: transform 0.28s ease;
}

.feature-beam-wrapper:hover .home-glass-card__content strong,
.feature-beam-wrapper:focus-within .home-glass-card__content strong {
    font-size: 18px;
    font-weight: 700;
    line-height: 1.25;
    white-space: normal;
}

.home-glass-card__content small {
    position: relative;
    z-index: 6;
    display: block;
    max-width: 100%;
    margin-top: 0.25rem;
    overflow: hidden;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.75rem;
    line-height: 1rem;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: none;
    text-shadow:
        0 2px 12px rgba(0, 0, 0, 0.76),
        0 0 20px rgba(0, 0, 0, 0.42);
}

.feature-beam-wrapper:hover .home-glass-card__content small,
.feature-beam-wrapper:focus-within .home-glass-card__content small {
    margin-top: 0.35rem;
    color: rgba(255, 255, 255, 0.86);
    font-size: clamp(0.74rem, 0.8vw, 0.9rem);
    line-height: 1.25;
    white-space: normal;
}

.home-glass-card__preview {
    position: absolute;
    top: 50%;
    right: clamp(2rem, 3vw, 3.5rem);
    left: max(49%, 13rem);
    bottom: auto;
    z-index: 5;
    width: auto;
    height: 86%;
    opacity: 0;
    pointer-events: none;
    overflow: visible;
    --preview-main-size: clamp(4.75rem, 7vw, 8.25rem);
    --preview-back-size: calc(var(--preview-main-size) * 0.82);
    --preview-side-offset: calc(var(--preview-main-size) * 0.36);
    transform: translate3d(0.45rem, -44%, 0) scale(0.94);
    transition:
        opacity 0.26s ease,
        transform 0.34s cubic-bezier(0.16, 1, 0.3, 1);
}

.home-glass-card__preview::before {
    content: '';
    position: absolute;
    inset: -0.75rem;
    border-radius: 1.1rem;
    background:
        radial-gradient(58% 72% at 52% 48%, rgba(54, 99, 255, 0.34), transparent 68%),
        radial-gradient(60% 74% at 78% 20%, rgba(255, 255, 255, 0.18), transparent 64%);
    opacity: 0.58;
    filter: blur(12px);
}

.home-glass-card__preview img {
    position: absolute;
    display: block;
    top: 50%;
    left: 50%;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 0.72rem;
    object-fit: cover;
    opacity: 0;
    box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.07) inset,
        0 18px 42px rgba(0, 0, 0, 0.34);
    transition:
        opacity 0.24s ease,
        transform 0.34s cubic-bezier(0.16, 1, 0.3, 1);
}

.home-glass-card__preview img:nth-child(1) {
    z-index: 2;
    width: var(--preview-back-size);
    height: var(--preview-back-size);
    opacity: 0.58;
    transform: translate3d(calc(-50% - var(--preview-side-offset)), -50%, 0) scale(0.96);
}

.home-glass-card__preview img:nth-child(2) {
    z-index: 5;
    width: var(--preview-main-size);
    height: var(--preview-main-size);
    opacity: 0.96;
    transform: translate3d(-50%, -50%, 0) scale(1);
}

.home-glass-card__preview img:nth-child(3) {
    z-index: 1;
    width: var(--preview-back-size);
    height: var(--preview-back-size);
    opacity: 0.52;
    transform: translate3d(calc(-50% + var(--preview-side-offset)), -50%, 0) scale(0.94);
}

.feature-beam-wrapper:hover .home-glass-card__preview,
.feature-beam-wrapper:focus-within .home-glass-card__preview {
    opacity: 1;
    transform: translate3d(0, -50%, 0) scale(1);
}

.feature-beam-wrapper:hover .home-glass-card__preview img,
.feature-beam-wrapper:focus-within .home-glass-card__preview img {
    animation: immersivePreviewCarousel 7.2s ease-in-out infinite;
}

.feature-beam-wrapper:hover .home-glass-card__preview img:nth-child(1),
.feature-beam-wrapper:focus-within .home-glass-card__preview img:nth-child(1) {
    animation-delay: -2.4s;
}

.feature-beam-wrapper:hover .home-glass-card__preview img:nth-child(2),
.feature-beam-wrapper:focus-within .home-glass-card__preview img:nth-child(2) {
    animation-delay: 0s;
}

.feature-beam-wrapper:hover .home-glass-card__preview img:nth-child(3),
.feature-beam-wrapper:focus-within .home-glass-card__preview img:nth-child(3) {
    animation-delay: -4.8s;
}

@keyframes immersivePreviewCarousel {
    0%,
    27% {
        z-index: 5;
        width: var(--preview-main-size);
        height: var(--preview-main-size);
        opacity: 0.98;
        filter: saturate(1.08) contrast(1.04);
        transform: translate3d(-50%, -50%, 0) scale(1);
    }

    33%,
    60% {
        z-index: 2;
        width: var(--preview-back-size);
        height: var(--preview-back-size);
        opacity: 0.56;
        filter: saturate(0.88) contrast(0.96);
        transform: translate3d(calc(-50% + var(--preview-side-offset)), -50%, 0) scale(0.94);
    }

    66%,
    94% {
        z-index: 1;
        width: var(--preview-back-size);
        height: var(--preview-back-size);
        opacity: 0.52;
        filter: saturate(0.86) contrast(0.94);
        transform: translate3d(calc(-50% - var(--preview-side-offset)), -50%, 0) scale(0.96);
    }

    100% {
        z-index: 5;
        width: var(--preview-main-size);
        height: var(--preview-main-size);
        opacity: 0.98;
        filter: saturate(1.08) contrast(1.04);
        transform: translate3d(-50%, -50%, 0) scale(1);
    }
}

.home-glass-card i {
    position: absolute;
    left: auto;
    right: 1.35rem;
    top: 50%;
    bottom: auto;
    z-index: 6;
    color: rgba(255, 255, 255, 0.95);
    font-size: 22px;
    font-style: normal;
    line-height: 1;
    transform: translateY(-50%);
    transition:
        left 0.28s ease,
        right 0.28s ease,
        top 0.28s ease,
        bottom 0.28s ease,
        transform 0.28s ease,
        font-size 0.28s ease;
}

.feature-beam-wrapper:hover .home-glass-card i {
    left: 1.25rem;
    right: auto;
    top: auto;
    bottom: 1.05rem;
    font-size: 25px;
    transform: none;
}

@supports not ((-webkit-backdrop-filter: blur(1px)) or (backdrop-filter: blur(1px))) {
    .immersive-cta__button,
    .feature-beam-wrapper .home-glass-card {
        background: rgba(0, 0, 0, 0.4);
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, 0.2),
            inset 0 -1px 0 rgba(255, 255, 255, 0.1);
    }

}

@media (prefers-reduced-motion: reduce) {
    .feature-beam-wrapper,
    .feature-beam-wrapper .home-glass-card,
    .feature-beam-wrapper .home-glass-card::before,
    .feature-beam-wrapper .home-glass-card::after,
    .feature-beam-wrapper .home-glass-card__content,
    .feature-beam-wrapper .home-glass-card i {
        transition-duration: 0.01ms;
    }

    .home-glass-card__preview img {
        animation: none;
        opacity: 0.78;
    }

    .immersive-cta__button.glass-surface--svg,
    .feature-beam-wrapper .home-glass-card.glass-surface--svg {
        -webkit-backdrop-filter: blur(12px) saturate(1.4);
        backdrop-filter: blur(12px) saturate(1.4);
    }

}

@media (max-width: 1280px) {
    .yike-home-page {
        --yike-feature-gap: 0.625rem;
    }

    .feature-beam-wrapper:hover,
    .feature-beam-wrapper:focus-within {
        flex: 1 1 0;
    }

    .feature-beam-wrapper:hover .home-glass-card__content,
    .feature-beam-wrapper:focus-within .home-glass-card__content {
        max-width: 48%;
        padding-left: 1.25rem;
    }

    .home-glass-card__preview {
        right: 1.5rem;
        left: max(52%, 12rem);
        width: auto;
        --preview-main-size: clamp(4.5rem, 6.4vw, 7.25rem);
    }
}

.yike-discovery-tabs {
    position: relative;
    z-index: 3;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 0.5rem;
    width: 100%;
    max-width: 2028px;
    height: 2.5rem;
    margin: 2rem auto 1rem;
}

.yike-discovery-tabs button {
    height: 2.25rem;
    padding: 0 1rem;
    border: 0;
    border-radius: 0.5rem;
    background: transparent;
    color: rgba(255, 255, 255, 0.62);
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
}

.yike-discovery-tabs button:hover,
.yike-discovery-tabs button.is-active {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}

.app-main {
    position: relative;
    z-index: 1;
    width: 100%;
    min-width: 0;
    min-height: 100%;
    margin: 0;
    padding: 30px 24px 100px 96px;
    box-sizing: border-box;
}

.home-hero {
    display: grid;
    grid-template-columns: minmax(430px, 1.75fr) minmax(260px, 0.98fr) minmax(250px, 0.98fr);
    gap: 8px;
    min-height: 178px;
}

.home-tv-card,
.home-quick-card,
.home-banner,
.model-card {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
    overflow: hidden;
    text-align: left;
}

.home-tv-card,
.home-quick-card,
.home-banner {
    position: relative;
    min-width: 0;
    padding: 0;
    background: #0d0e12;
    isolation: isolate;
}

.home-tv-card {
    min-height: 178px;
    background:
        radial-gradient(circle at 84% 24%, rgba(77, 235, 255, 0.16), transparent 34%),
        linear-gradient(135deg, #15161d 0%, #090a0d 58%, #06070a 100%);
    transition:
        border-color 0.2s ease,
        transform 0.2s ease;
}

.home-tv-card:hover,
.home-quick-card:hover,
.home-banner:hover {
    border-color: rgba(77, 235, 255, 0.28);
}

.home-tv-card__copy,
.home-quick-card__copy,
.home-banner__copy {
    position: absolute;
    z-index: 4;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.home-tv-card__copy {
    left: 18px;
    top: 24px;
}

.home-tv-card__copy strong,
.home-quick-card__copy strong {
    color: #fff;
    font-size: 20px;
    font-weight: 760;
    line-height: 1.2;
}

.home-tv-card__copy small,
.home-quick-card__copy small {
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
    line-height: 1.4;
}

.home-tv-card__collage {
    position: absolute;
    left: 38px;
    right: 14px;
    bottom: -18px;
    z-index: 2;
    height: 112px;
    transform: rotate(-3deg);
}

.home-tv-card__collage::before {
    content: '';
    position: absolute;
    inset: -18px 0 auto auto;
    width: 92px;
    height: 92px;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(77, 235, 255, 0.22), transparent 68%);
    filter: blur(2px);
}

.home-tv-card__collage img {
    position: absolute;
    left: calc(var(--collage-index) * 58px);
    bottom: calc(var(--collage-index) * 4px);
    width: 96px;
    height: 92px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    object-fit: cover;
    box-shadow: 0 14px 30px rgba(0, 0, 0, 0.42);
    transform: rotate(calc((var(--collage-index) - 1.5) * 7deg));
}

.home-tv-card i,
.home-quick-card i {
    position: absolute;
    z-index: 5;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 64px;
    height: 34px;
    border-radius: 999px;
    background:
        linear-gradient(90deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.14)),
        rgba(17, 18, 22, 0.72);
    color: #fff;
    font-style: normal;
    font-size: 22px;
    backdrop-filter: blur(10px);
}

.home-tv-card i {
    right: 18px;
    top: 34px;
}

.home-quick-stack {
    display: grid;
    grid-template-rows: repeat(2, minmax(0, 1fr));
    gap: 8px;
    min-width: 0;
}

.home-quick-card {
    min-height: 85px;
    background:
        radial-gradient(circle at 92% 50%, rgba(77, 235, 255, 0.2), transparent 38%),
        linear-gradient(100deg, #101115 0%, #0a0b0e 58%, rgba(77, 235, 255, 0.14) 100%);
}

.home-quick-card__copy {
    left: 18px;
    top: 17px;
}

.home-quick-card__copy strong {
    font-size: 16px;
}

.home-quick-card__copy small {
    font-size: 12px;
}

.home-quick-card__media {
    position: absolute;
    right: 0;
    top: 0;
    bottom: 0;
    z-index: 1;
    width: 42%;
    min-width: 100px;
    overflow: hidden;
}

.home-quick-card__media::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, #0d0e12 0%, rgba(13, 14, 18, 0.08) 72%);
}

.home-quick-card__media img,
.home-quick-card__media video {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.home-quick-card i {
    right: 18px;
    top: 50%;
    width: 44px;
    height: 28px;
    font-size: 18px;
    transform: translateY(-50%);
}

.home-banner {
    min-height: 178px;
    background:
        radial-gradient(circle at 78% 22%, rgba(77, 235, 255, 0.18), transparent 32%),
        linear-gradient(90deg, #10164d 0%, #071032 46%, #05070c 100%);
}

.home-banner__copy {
    left: 34px;
    top: 48px;
}

.home-banner strong {
    font-size: 30px;
    font-weight: 760;
    line-height: 1.15;
}

.home-banner small {
    color: rgba(255, 255, 255, 0.82);
    font-size: 16px;
}

.home-banner__backdrop {
    position: absolute;
    inset: 0;
    z-index: -2;
}

.home-banner__backdrop img,
.home-banner__backdrop video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0.34;
    filter: saturate(1.08);
}

.home-banner__backdrop::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(90deg, rgba(10, 18, 68, 0.94) 0%, rgba(8, 14, 43, 0.82) 44%, rgba(5, 6, 10, 0.36) 100%),
        radial-gradient(circle at 78% 50%, rgba(77, 235, 255, 0.16), transparent 36%);
}

.home-banner__media img {
    position: absolute;
    right: calc(30px + var(--banner-index) * 78px);
    top: calc(28px + var(--banner-index) * 8px);
    width: 116px;
    height: 116px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 10px;
    object-fit: cover;
    box-shadow: 0 18px 38px rgba(0, 0, 0, 0.42);
    transform: rotate(calc((var(--banner-index) - 1) * -4deg));
}

.home-banner__dots {
    position: absolute;
    right: 26px;
    bottom: 16px;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.home-banner__dots i {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.34);
}

.home-banner__dots i.is-active {
    width: 18px;
    background: #4debff;
}

.model-carousel {
    position: relative;
    width: 100%;
    max-width: 2028px;
    margin-top: 24px;
    margin-right: auto;
    margin-left: auto;
    overflow: visible;
}

.model-carousel__arrow {
    position: absolute;
    top: 50%;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 32px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 999px;
    background: rgba(16, 17, 21, 0.82);
    color: rgba(255, 255, 255, 0.92);
    font-size: 18px;
    line-height: 1;
    cursor: pointer;
    backdrop-filter: blur(12px);
    transform: translateY(-50%);
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease;
}

.model-carousel__arrow:hover {
    border-color: rgba(77, 235, 255, 0.48);
    background: rgba(77, 235, 255, 0.16);
    color: #fff;
}

.model-carousel__arrow--left {
    left: -4px;
}

.model-carousel__arrow--right {
    right: -4px;
}

.model-strip {
    display: flex;
    gap: 2px;
    padding-bottom: 2px;
    overflow-x: auto;
    scroll-snap-type: x proximity;
    scrollbar-width: none;
}

.model-strip::-webkit-scrollbar {
    display: none;
}

.model-card {
    position: relative;
    flex: 0 0 calc((100% - (var(--inspiration-columns, 6) - 1) * 2px) / var(--inspiration-columns, 6));
    min-width: 0;
    min-height: 250px;
    background: #111217;
    scroll-snap-align: start;
}

.model-card > img,
.model-card > video {
    display: block;
    width: 100%;
    height: 100%;
    min-height: 250px;
    object-fit: cover;
    transition: transform 0.25s ease;
}

.model-card::after {
    content: '';
    position: absolute;
    inset: 38% 0 0;
    background: linear-gradient(180deg, transparent, rgba(7, 8, 10, 0.9));
}

.model-card:hover > img,
.model-card:hover > video {
    transform: scale(1.04);
}

.model-card__badge {
    position: absolute;
    left: 10px;
    top: 10px;
    z-index: 2;
    padding: 4px 8px;
    border-radius: 5px;
    background: linear-gradient(90deg, #3f8cff 0%, #4debff 100%);
    color: #061016;
    font-size: 12px;
}

.model-card > button {
    position: absolute;
    right: 10px;
    top: 10px;
    z-index: 2;
    width: 28px;
    height: 28px;
    border: 0;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.38);
    color: #fff;
    cursor: pointer;
}

.model-card__body {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 10px;
    z-index: 2;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 4px 8px;
    align-items: end;
}

.model-card__body strong,
.model-card__body small {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.model-card__body strong {
    grid-column: 1 / -1;
    font-size: 20px;
    line-height: 1.2;
}

.model-card__body small {
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
}

.model-card__body em {
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.26);
    color: #fff;
    font-size: 12px;
    font-style: normal;
}

.hero-panel {
    position: relative;
    z-index: 3;
}

.hero-panel--compact {
    margin-top: 34px;
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
    max-width: 2028px;
    margin: 34px auto 0;
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
    margin-top: 0;

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
    border-radius: 8px;
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
        border-radius: 8px;
        background: linear-gradient(90deg, #3f8cff 0%, #4debff 100%);
        color: #061016;
        font-size: 14px;
        font-weight: 700;
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

.ai-app-page.is-immersive-home {
    .model-card {
        min-height: clamp(260px, 29vh, 330px);
    }

    .model-card > img,
    .model-card > video {
        min-height: clamp(260px, 29vh, 330px);
    }
}

@media (max-width: 1100px) {
    .app-main {
        padding: 30px 18px 120px 94px;
    }

    .home-hero {
        grid-template-columns: 1fr;
    }

    .home-banner {
        min-height: 190px;
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
