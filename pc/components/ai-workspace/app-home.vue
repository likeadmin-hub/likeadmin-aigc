
<template>
    <div ref="pageScrollRef" class="ai-app-page">
        <div class="ai-app-page__background" :style="backgroundStyle"></div>
        <div class="ai-app-page__noise"></div>
        <div class="ai-app-page__orbit ai-app-page__orbit--outer"></div>
        <div class="ai-app-page__orbit ai-app-page__orbit--inner"></div>
        <div class="ai-app-page__scan"></div>
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
                    <div class="hero-panel__eyebrow">
                        <span></span>
                        AI 赋能超级个体
                        <span></span>
                    </div>
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

                <div ref="promptCardRef" class="prompt-card">
                    <div
                        :class="['upload-panel', { 'is-filled': uploadedAssets.length }]"
                        :style="getUploadPanelStyle()"
                        role="button"
                        tabindex="0"
                        @click="triggerUpload"
                        @keydown.enter.prevent="triggerUpload"
                        @keydown.space.prevent="triggerUpload"
                        @mouseenter="uploadPreviewExpanded = true"
                        @mouseleave="uploadPreviewExpanded = false"
                    >
                        <template v-if="uploadedAssets.length">
                            <span class="upload-panel__preview" :style="getUploadPreviewStyle()">
                                <span class="upload-panel__preview-stack">
                                    <span
                                        v-for="(asset, index) in uploadedAssets"
                                        :key="asset.id"
                                        class="upload-panel__preview-frame"
                                        :style="getUploadPreviewFrameStyle(index)"
                                    >
                                        <span class="upload-panel__preview-card">
                                            <img :src="asset.url" :alt="asset.name || '已上传图片'" />
                                            <button
                                                class="upload-panel__delete"
                                                type="button"
                                                aria-label="删除图片"
                                                @click.stop="removeUploadedAsset(asset.id)"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    </span>
                                </span>
                                <span
                                    class="upload-panel__badge"
                                    :class="{ 'is-expanded': uploadPreviewExpanded && uploadedAssets.length > 1 }"
                                    :style="getUploadPreviewBadgeStyle()"
                                >
                                    <span class="upload-panel__badge-plus">＋</span>
                                </span>
                            </span>
                        </template>
                        <span v-else class="upload-panel__tilt">
                            <span class="upload-panel__plus">＋</span>
                        </span>
                    </div>

                    <div class="prompt-card__body">
                        <textarea
                            v-model="prompt"
                            class="prompt-card__textarea"
                            :placeholder="currentPlaceholder"
                            @keydown.meta.enter.prevent="generateImageLock"
                            @keydown.ctrl.enter.prevent="generateImageLock"
                        ></textarea>

                        <div class="prompt-card__footer">
                            <div class="prompt-tools">
                                <div class="mode-switch">
                                    <button
                                        :class="['prompt-toggle', { 'is-active': generationMode === 'image' }]"
                                        type="button"
                                        @click="setGenerationMode('image')"
                                    >
                                        <img :src="generationMode === 'image' ? imageIconActive : imageIcon" alt="" />
                                        <span>图片</span>
                                    </button>
                                    <button
                                        :class="['prompt-toggle', { 'is-active': generationMode === 'video' }]"
                                        type="button"
                                        @click="setGenerationMode('video')"
                                    >
                                        <img :src="generationMode === 'video' ? videoIconActive : videoIcon" alt="" />
                                        <span>视频</span>
                                    </button>
                                </div>

                                <div
                                    v-for="item in configOptions"
                                    :key="item.key"
                                    class="select-chip-wrap"
                                >
                                    <button
                                        :class="['select-chip', { 'is-open': openedOption === item.key, 'is-disabled': item.disabled }]"
                                        type="button"
                                        :disabled="item.disabled"
                                        @click="toggleOption(item.key, item.disabled)"
                                >
                                    <span>{{ item.value }}</span>
                                    <img v-if="!item.disabled" class="select-chip__arrow" :src="downIcon" alt="" />
                                </button>

                                <div v-if="openedOption === item.key" class="select-chip-menu">
                                    <button
                                        v-for="value in getOptionValues(item.key)"
                                        :key="value"
                                        :class="['select-chip-menu__item', { 'is-active': isOptionActive(item.key, value) }]"
                                        type="button"
                                        @click="setOption(item.key, value)"
                                        >
                                            <span v-if="item.key === 'ratio'" class="select-chip__ratio-preview" aria-hidden="true">
                                                <span class="select-chip__ratio-shape" :style="getAiCreateRatioPreviewStyle(value)"></span>
                                            </span>
                                            {{ value }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="prompt-submit">
                                <span class="prompt-submit__credit">
                                    <img class="prompt-submit__spark" :src="sparkIcon" alt="" />
                                    {{ generationMode === 'image' ? `${selectedUnitPrice}/张` : `${selectedVideoUnitPrice}/条` }}
                                </span>
                                <button
                                    class="prompt-submit__button"
                                    type="button"
                                    :disabled="!canGenerate || isGenerateImageLocked"
                                    @click="generateImageLock"
                                >
                                    {{ submitting || isGenerateImageLocked ? '...' : '↑' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </section>

            <section ref="inspirationBoardRef" class="inspiration-board">
                <div class="inspiration-board__scroller">
                    <div class="inspiration-grid" :style="{ '--case-column-count': caseColumns.length }">
                        <div
                            v-for="(column, columnIndex) in caseColumns"
                            :key="`case-column-${columnIndex}`"
                            class="inspiration-grid__column"
                        >
                            <article
                                v-for="item in column"
                                :key="item.uniqueId || item.id"
                                :class="['inspiration-card', { 'is-selected': selectedCardKey === getCardKey(item) }]"
                                :style="getInspirationCardStyle(item)"
                                @click="openWorkDetail(item)"
                            >
                                <video
                                    v-if="item.category === 'video' && item.mediaUrl"
                                    :src="item.mediaUrl"
                                    :poster="item.image || undefined"
                                    muted
                                    autoplay
                                    loop
                                    playsinline
                                    preload="metadata"
                                ></video>
                                <img
                                    v-else
                                    :src="item.image"
                                    :alt="item.title"
                                    @error="handleCardImageError(item)"
                                />
                                <div class="inspiration-card__overlay"></div>
                                <div class="inspiration-card__info">
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ getCaseTypeLabel(item) }}</span>
                                </div>
                                <button
                                    class="inspiration-card__action"
                                    type="button"
                                    @click.stop="copyCardPrompt(item)"
                                >
                                    一键同款
                                </button>
                            </article>
                        </div>
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
                                ×
                            </button>
                            <div class="work-detail__media-frame">
                                <video
                                    v-if="activeDetailCard.category === 'video' && activeDetailCard.mediaUrl"
                                    :src="activeDetailCard.mediaUrl"
                                    :poster="activeDetailCard.image || undefined"
                                    controls
                                    autoplay
                                    playsinline
                                ></video>
                                <img
                                    v-else
                                    :src="activeDetailCard.image"
                                    :alt="activeDetailCard.title"
                                    @error="handleCardImageError(activeDetailCard)"
                                />
                            </div>
                        </div>

                        <div class="work-detail__content">
                            <div class="work-detail__header">
                                <div class="work-detail__author-row">
                                    <div class="work-detail__author-group">
                                        <div class="work-detail__author">
                                            <span class="work-detail__avatar">
                                                <img :src="displayAvatarUrl" :alt="displayNickname" />
                                            </span>
                                            <div class="work-detail__author-meta">
                                                <strong>{{ displayNickname }}</strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="work-detail__social">
                                        <button
                                            :class="{ 'is-active': activeDetailFavorite }"
                                            type="button"
                                            aria-label="收藏作品"
                                            @click.stop.prevent="toggleActiveDetailFavorite"
                                        >
                                            <img :src="favoriteIcon" alt="" />
                                        </button>
                                        <button type="button" aria-label="下载作品" @click.stop.prevent="downloadDetailAsset">
                                            <img :src="downloadIcon" alt="" />
                                        </button>
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
                                    <span
                                        v-for="field in activeDetailConfigFields"
                                        :key="field"
                                        class="work-detail__config-text"
                                    >
                                        {{ field }}
                                    </span>
                                </div>
                            </div>

                            <div class="work-detail__footer">
                                <button class="work-detail__ghost" type="button" @click="applyDetailPrompt">
                                    创作同款
                                </button>
                                <button class="work-detail__primary" type="button" @click="applyDetailReference">
                                    用作参考图
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </transition>
        </Teleport>

        <transition name="floating-prompt-fade">
            <div v-if="showFloatingComposer && !detailOpen" class="floating-prompt">
                <div class="prompt-card">
                    <div
                        :class="['upload-panel', { 'is-filled': uploadedAssets.length }]"
                        :style="getUploadPanelStyle()"
                        role="button"
                        tabindex="0"
                        @click="triggerUpload"
                        @keydown.enter.prevent="triggerUpload"
                        @keydown.space.prevent="triggerUpload"
                        @mouseenter="uploadPreviewExpanded = true"
                        @mouseleave="uploadPreviewExpanded = false"
                    >
                        <template v-if="uploadedAssets.length">
                            <span class="upload-panel__preview" :style="getUploadPreviewStyle()">
                                <span class="upload-panel__preview-stack">
                                    <span
                                        v-for="(asset, index) in uploadedAssets"
                                        :key="asset.id"
                                        class="upload-panel__preview-frame"
                                        :style="getUploadPreviewFrameStyle(index)"
                                    >
                                        <span class="upload-panel__preview-card">
                                            <img :src="asset.url" :alt="asset.name || '已上传图片'" />
                                            <button
                                                class="upload-panel__delete"
                                                type="button"
                                                aria-label="删除图片"
                                                @click.stop="removeUploadedAsset(asset.id)"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    </span>
                                </span>
                                <span
                                    class="upload-panel__badge"
                                    :class="{ 'is-expanded': uploadPreviewExpanded && uploadedAssets.length > 1 }"
                                    :style="getUploadPreviewBadgeStyle()"
                                >
                                    <span class="upload-panel__badge-plus">＋</span>
                                </span>
                            </span>
                        </template>
                        <span v-else class="upload-panel__tilt">
                            <span class="upload-panel__plus">＋</span>
                        </span>
                    </div>

                    <div class="prompt-card__body">
                        <textarea
                            v-model="prompt"
                            class="prompt-card__textarea"
                            :placeholder="currentPlaceholder"
                            @keydown.meta.enter.prevent="generateImage"
                            @keydown.ctrl.enter.prevent="generateImage"
                        ></textarea>

                        <div class="prompt-card__footer">
                            <div class="prompt-tools">
                                <div class="mode-switch">
                                    <button
                                        :class="['prompt-toggle', { 'is-active': generationMode === 'image' }]"
                                        type="button"
                                        @click="setGenerationMode('image')"
                                    >
                                        <img :src="generationMode === 'image' ? imageIconActive : imageIcon" alt="" />
                                        <span>图片</span>
                                    </button>
                                    <button
                                        :class="['prompt-toggle', { 'is-active': generationMode === 'video' }]"
                                        type="button"
                                        @click="setGenerationMode('video')"
                                    >
                                        <img :src="generationMode === 'video' ? videoIconActive : videoIcon" alt="" />
                                        <span>视频</span>
                                    </button>
                                </div>

                                <div
                                    v-for="item in configOptions"
                                    :key="`floating-${item.key}`"
                                    class="select-chip-wrap"
                                >
                                    <button
                                        :class="['select-chip', { 'is-open': openedOption === item.key, 'is-disabled': item.disabled }]"
                                        type="button"
                                        :disabled="item.disabled"
                                        @click="toggleOption(item.key, item.disabled)"
                                    >
                                        <span>{{ item.value }}</span>
                                        <img v-if="!item.disabled" class="select-chip__arrow" :src="downIcon" alt="" />
                                    </button>

                                    <div v-if="openedOption === item.key" class="select-chip-menu">
                                        <button
                                            v-for="value in getOptionValues(item.key)"
                                            :key="`floating-${item.key}-${value}`"
                                            :class="['select-chip-menu__item', { 'is-active': isOptionActive(item.key, value) }]"
                                            type="button"
                                            @click="setOption(item.key, value)"
                                        >
                                            <span v-if="item.key === 'ratio'" class="select-chip__ratio-preview" aria-hidden="true">
                                                <span class="select-chip__ratio-shape" :style="getAiCreateRatioPreviewStyle(value)"></span>
                                            </span>
                                            {{ value }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="prompt-submit">
                                <span class="prompt-submit__credit">
                                    <img class="prompt-submit__spark" :src="sparkIcon" alt="" />
                                    {{ generationMode === 'image' ? `${selectedUnitPrice}/张` : `${selectedVideoUnitPrice}/条` }}
                                </span>
                                <button
                                    class="prompt-submit__button"
                                    type="button"
                                    :disabled="!canGenerate"
                                    @click="generateImageLock"
                                >
                                    {{ submitting ? '...' : '↑' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
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
import type { AiCreateDraft, AiCreateOptionKey, AiCreateOptionState } from '~/composables/useAiCreateWorks'
import {
    createAiCreateOptionState,
    getAiCreateRatioPreviewStyle,
    useAiCreateWorks
} from '~/composables/useAiCreateWorks'
import { getAigcImageCases, getAigcImageConfig, generateAigcImage } from '@/apps/aigc_image/api'
import { getAigcVideoCases, getAigcVideoConfig, generateAigcVideo } from '@/apps/aigc_video/api'
import { getAigcDigitalHumanCases } from '@/apps/aigc_digital_human/api'
import { uploadImage as uploadAppImage } from '@/api/app'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { normalizeFileUrl } from '@/utils/file-url'
import { downloadPcAsset, openPcAsset } from '@/utils/download'
import feedback from '@/utils/feedback'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import sparkIcon from '@/assets/images/icon/lingganzhi.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'
import favoriteIcon from '@/assets/images/icon/shoucang.svg'
import imageIcon from '@/assets/images/icon/New-picture -yuanshi.svg'
import imageIconActive from '@/assets/images/icon/New-picture -gaoliang.svg'
import videoIcon from '@/assets/images/icon/shipin-yuanshi.svg'
import videoIconActive from '@/assets/images/icon/shipin-gaoliang.svg'
import { useAiUserDisplay } from '~/composables/useAiUserDisplay'
import { useAiWorkspaceFavorites } from '~/composables/useAiWorkspaceFavorites'
import card1 from '@/assets/images/ai-app/card-1.png'
import card2 from '@/assets/images/ai-app/card-2.png'
import card3 from '@/assets/images/ai-app/card-3.png'
import card4 from '@/assets/images/ai-app/card-4.png'
import card5 from '@/assets/images/ai-app/card-5.png'
import card6 from '@/assets/images/ai-app/card-6.png'
import card7 from '@/assets/images/ai-app/card-7.png'
import card8 from '@/assets/images/ai-app/card-8.png'
import card9 from '@/assets/images/ai-app/card-9.png'
import card10 from '@/assets/images/ai-app/card-10.png'
import card11 from '@/assets/images/ai-app/card-11.png'
import card12 from '@/assets/images/ai-app/card-12.png'

type GenerationMode = 'image' | 'video'
type TabKey = 'all' | 'image' | 'video'
type OptionKey = AiCreateOptionKey
type ConfigOption = {
    key: OptionKey
    value: string
    disabled?: boolean
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
}

interface ChannelOption {
    label: string
    value: string
    max_reference_images?: number
    qualities: QualityOption[]
}

interface CardItem {
    id: number
    uniqueId?: string | number
    title: string
    category: Exclude<TabKey, 'all'>
    appCode?: 'aigc_image' | 'aigc_video' | 'aigc_digital_human'
    image: string
    mediaUrl?: string
    fallbackImage?: string
    imageHeight: number
    prompt?: string
    configFields?: string[]
    source?: 'case' | 'fallback'
    referenceAssetUrl?: string
    referenceAssetName?: string
}

interface UploadedAsset {
    id: string
    name: string
    url: string
    uri?: string
    isObjectUrl: boolean
}

const UPLOAD_PANEL_BASE_WIDTH = 96
const UPLOAD_PREVIEW_CARD_WIDTH = 60
const UPLOAD_PREVIEW_GAP = 8
const UPLOAD_PREVIEW_STEP = UPLOAD_PREVIEW_CARD_WIDTH + UPLOAD_PREVIEW_GAP

const router = useRouter()
const { ensurePcLogin } = usePcLoginGate()
const { displayAvatarUrl, displayNickname } = useAiUserDisplay()
const { isFavorite, toggleFavorite } = useAiWorkspaceFavorites()
const { setDraft } = useAiCreateWorks()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()
const fileInputRef = ref<HTMLInputElement | null>(null)
const pageScrollRef = ref<HTMLElement | null>(null)
const promptCardRef = ref<HTMLElement | null>(null)
const inspirationBoardRef = ref<HTMLElement | null>(null)

const prompt = ref('')
const generationMode = ref<GenerationMode>('image')
const apiCredits = ref(24)
const activeSidebar = ref<SidebarKey>('inspiration')
const activeInspirationTab = ref<TabKey>('all')
const selectedCardId = ref(1)
const selectedCardKey = ref('')
const lastGeneratedLabel = ref('')
const uploadedAssets = ref<UploadedAsset[]>([])
const uploadPreviewExpanded = ref(false)
const viewportWidth = ref(1920)
const activePopover = ref<'' | 'share' | 'api' | 'notice'>('')
const openedOption = ref<OptionKey | ''>('')
const caseCards = ref<CardItem[]>([])
const detailOpen = ref(false)
const activeDetailCard = ref<CardItem | null>(null)
const showFloatingComposer = ref(false)
const promptScrollExpanded = ref(false)
const pageScrollThumbTop = ref(0)
const pageScrollThumbHeight = ref(64)
const pageScrollThumbVisible = ref(false)
const pageScrollThumbDragging = ref(false)
const pageScrollThumbPointerOffset = ref(0)

const notifications = ['你的“赛博朋克短发角色”已生成完成，可继续一键同款扩展。']
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
        text: notifications.length ? notifications[0] : '暂无未读消息',
        compact: true
    }
}))

const quickTags: string[] = []
const cardModelPool = ['豆包 AI 生成', 'Seed 2.0 Pro', '写实人像引擎', '叙事镜头引擎']
const cardResolutionPool = ['图片 4:1 | 916', '图片 3:4 | 1024', '视频 16:9 | 720P', '图片 1:1 | 2048']

const optionState = ref<AiCreateOptionState>(createAiCreateOptionState())
const submitting = ref(false)
const uploading = ref(false)
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

const cards: CardItem[] = [
    { id: 1, title: '未来机械人像', category: 'image', image: card1, imageHeight: 307, prompt: '未来机械风格女性半身肖像，金属细节，冷调蓝光，电影级布光，超高精度，主体突出，背景虚化，高级时尚感' },
    { id: 2, title: '电影感少年肖像', category: 'image', image: card2, imageHeight: 307, prompt: '少年人像特写，电影感构图，柔和侧逆光，肤质真实，浅景深，胶片色调，安静克制的情绪表达' },
    { id: 3, title: '暗黑巨龙飞行', category: 'image', image: card3, imageHeight: 307, prompt: '暗黑奇幻巨龙掠过天空，乌云与闪电交织，史诗级场景，强烈体积光，动态冲击，细节丰富' },
    { id: 4, title: '赛博朋克短发角色', category: 'image', image: card4, imageHeight: 307, prompt: '赛博朋克短发女性角色，霓虹城市夜景，紫蓝撞色灯光，皮革与金属材质，电影剧照风格，细节锐利' },
    { id: 5, title: '月面赛车场景', category: 'video', image: card5, imageHeight: 307, prompt: '月球表面未来赛车高速穿梭，镜头低机位跟拍，尘埃扬起，远处地球悬挂天际，科幻大片质感' },
    { id: 6, title: '梦幻云朵萌宠', category: 'image', image: card6, imageHeight: 307, prompt: '梦幻云朵中的可爱萌宠，柔软蓬松质感，粉蓝配色，童话氛围，治愈系插画质感，光线轻盈' },
    { id: 7, title: '复古人像质感', category: 'image', image: card7, imageHeight: 307, prompt: '复古人像写真，暖棕胶片色调，细腻颗粒感，经典布光，人物神态自然，高级杂志封面风格' },
    { id: 8, title: '夜景人物汽车大片', category: 'video', image: card8, imageHeight: 307, prompt: '都市夜景中人物与跑车同框，镜头缓慢推进，霓虹反射，潮流广告片风格，速度与情绪并存' },
    { id: 9, title: '未来农场机器人', category: 'image', image: card9, imageHeight: 307, prompt: '未来智能农场中的机器人作业场景，干净通透的自然光，科技与生态融合，写实风格，构图开阔' },
    { id: 10, title: '雪山蒸汽火车', category: 'video', image: card10, imageHeight: 307, prompt: '雪山之间蒸汽火车穿越而过，镜头横向追随，白雾与雪粒飞散，复古冒险电影质感，场景壮阔' },
    { id: 11, title: '城市地铁电影帧', category: 'video', image: card11, imageHeight: 307, prompt: '城市地铁站电影帧，人物独自行走，冷暖对比灯光，镜头缓慢平移，情绪克制，都市叙事感' },
    { id: 12, title: '彩色手作玩偶', category: 'image', image: card12, imageHeight: 307, prompt: '彩色手作玩偶陈列，手工布料与针脚细节，活泼高饱和配色，温暖自然光，商业拍摄质感' },
    { id: 13, title: '银翼城市漫游', category: 'image', image: card1, imageHeight: 307, prompt: '银翼风格未来都市漫游，超高楼宇与飞行器穿梭，霓虹雾气，电影级远景，氛围浓烈' },
    { id: 14, title: '海边杂志封面', category: 'image', image: card2, imageHeight: 307, prompt: '海边时尚杂志封面人像，逆光金色日落，轻风发丝，服装高级简洁，大片质感' },
    { id: 15, title: '熔岩龙巢穴', category: 'image', image: card3, imageHeight: 307, prompt: '熔岩洞穴中的巨龙巢穴，炽热火光与岩浆纹理，奇幻电影场景，压迫感十足，细节丰富' },
    { id: 16, title: '虚拟偶像舞台', category: 'video', image: card4, imageHeight: 307, prompt: '虚拟偶像站上全息舞台，镜头绕场缓慢环绕，灯光节奏变化，科技演唱会氛围，绚丽动感' },
    { id: 17, title: '星际机甲追逐', category: 'video', image: card5, imageHeight: 307, prompt: '星际机甲在荒漠星球高速追逐，低机位推镜，沙尘翻涌，冲击感强烈，科幻预告片质感' },
    { id: 18, title: '奶油云朵小屋', category: 'image', image: card6, imageHeight: 307, prompt: '奶油色云朵上的童话小屋，软萌可爱，暖光包裹，梦境感十足，细节精致' },
    { id: 19, title: '老电影肖像集', category: 'image', image: card7, imageHeight: 307, prompt: '老电影风格室内肖像，柔焦，胶片颗粒，年代色彩，人物情绪安静内敛，复古审美' },
    { id: 20, title: '霓虹街头跑车', category: 'video', image: card8, imageHeight: 307, prompt: '霓虹街头跑车疾驰，镜头快速跟拍切换，雨夜反光地面，酷感广告片质感，速度线明显' },
    { id: 21, title: '温室机器人采摘', category: 'image', image: card9, imageHeight: 307, prompt: '未来温室中机器人采摘果实，玻璃穹顶通透，绿色植物茂盛，科技农业写实风格' },
    { id: 22, title: '极地列车远征', category: 'video', image: card10, imageHeight: 307, prompt: '极地列车穿越雪暴，远景转近景，空气中充满寒雾，冒险电影镜头语言，氛围紧张' },
    { id: 23, title: '城市列车情绪片段', category: 'video', image: card11, imageHeight: 307, prompt: '城市列车车厢内人物沉思，镜头缓慢推进，窗外光影掠过脸部，情绪短片氛围' },
    { id: 24, title: '缤纷手作陈列', category: 'image', image: card12, imageHeight: 307, prompt: '缤纷手作玩偶与摆件陈列台，高饱和产品摄影，柔和自然光，商业电商主图质感' }
]
const defaultCaseImages = [
    card1,
    card2,
    card3,
    card4,
    card5,
    card6,
    card7,
    card8,
    card9,
    card10,
    card11,
    card12
]
const configOptions = computed<ConfigOption[]>(() =>
    generationMode.value === 'video'
        ? [
            { key: 'model', value: currentVideoChannel.value?.label || 'Grok Video（xAIQ）', disabled: videoChannels.value.length <= 1 },
            { key: 'ratio', value: optionState.value.ratio },
            { key: 'duration', value: optionState.value.duration }
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
const maxReferenceCount = computed(() => generationMode.value === 'video'
    ? Number(currentVideoChannel.value?.max_reference_images || aigcVideoOptionConfig.value.max_reference_images || 7)
    : Number(currentChannel.value?.max_reference_images || aigcOptionConfig.value.max_reference_images || 4)
)

const galleryCards = computed(() => caseCards.value)
const filteredCards = computed(() => galleryCards.value)

const displayedCards = computed(() => filteredCards.value)
const caseColumnCount = computed(() => {
    const width = viewportWidth.value || 1920
    if (width <= 520) return 1
    if (width <= 760) return 2
    if (width <= 1100) return 3
    if (width <= 1600) return 4
    return 5
})
const caseColumns = computed(() => {
    const count = Math.max(1, Math.min(caseColumnCount.value, Math.max(displayedCards.value.length, 1)))
    const columns = Array.from({ length: count }, () => [] as CardItem[])
    const heights = Array.from({ length: count }, () => 0)

    displayedCards.value.forEach((item) => {
        const targetIndex = heights.indexOf(Math.min(...heights))
        columns[targetIndex].push(item)
        heights[targetIndex] += item.imageHeight + 8
    })

    return columns
})

const getCardKey = (item: CardItem) => String(item.uniqueId || item.id)
const selectedCard = computed(() => galleryCards.value.find((item) => getCardKey(item) === selectedCardKey.value) || null)
const activeDetailDate = computed(() => {
    if (!activeDetailCard.value) return ''
    const day = String(((activeDetailCard.value.id + 2) % 6) + 1).padStart(2, '0')
    return `2026-04-${day}`
})
const activeDetailModel = computed(() => {
    if (!activeDetailCard.value) return ''
    return cardModelPool[(activeDetailCard.value.id - 1) % cardModelPool.length]
})
const activeDetailLikes = computed(() => {
    if (!activeDetailCard.value) return 0
    return 12 + activeDetailCard.value.id * 3
})
const getCardFavoriteCategory = (card: CardItem) => card.appCode === 'aigc_digital_human' ? 'avatar' : card.category
const getCardFavoriteId = (card: CardItem) => card.uniqueId || card.id
const activeDetailFavorite = computed(() => Boolean(activeDetailCard.value && isFavorite(getCardFavoriteCategory(activeDetailCard.value), getCardFavoriteId(activeDetailCard.value))))
const activeDetailPromptTitle = computed(() => (
    activeDetailCard.value?.category === 'video' ? '视频提示词' : '图片提示词'
))
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
const activeDetailPromptCollapsed = computed(() => {
    const text = activeDetailPromptText.value
    return text.length > 220 ? `${text.slice(0, 220)}...` : text
})
const activeDetailPromptDisplay = computed(() => (
    promptScrollExpanded.value ? activeDetailPromptText.value : activeDetailPromptCollapsed.value
))
const activeDetailConfigFields = computed(() => {
    if (!activeDetailCard.value) return []
    if (activeDetailCard.value.configFields?.length) return activeDetailCard.value.configFields

    const poolIndex = activeDetailCard.value.id - 1
    const imageRatioPool = ['4:1', '3:4', '1:1', '16:9']
    const videoRatioPool = ['9:16', '16:9', '21:9', '4:3']
    const imageResolutionPool = ['1k', '2k', '4k']
    const imageCountPool = ['1张', '2张', '4张']
    const videoDurationPool = ['5s', '10s', '15s']
    const videoQualityPool = ['1k标清', '2k高清']

    return activeDetailCard.value.category === 'video'
        ? [
            activeDetailModel.value,
            videoRatioPool[poolIndex % videoRatioPool.length],
            videoDurationPool[poolIndex % videoDurationPool.length],
            videoQualityPool[poolIndex % videoQualityPool.length]
        ]
        : [
            activeDetailModel.value,
            imageCountPool[poolIndex % imageCountPool.length],
            imageRatioPool[poolIndex % imageRatioPool.length],
            imageResolutionPool[poolIndex % imageResolutionPool.length]
        ]
})
const primaryUploadedAsset = computed(() => uploadedAssets.value[0] || null)
const uploadedFileName = computed(() => primaryUploadedAsset.value?.name || '')
const uploadedPreviewUrl = computed(() => primaryUploadedAsset.value?.url || '')

const heroTitle = computed(() => '一个人就是一家公司')
const heroSubtitle = computed(() => 'AI时代，不要限制你的想象力和创意')
const currentPlaceholder = computed(() =>
    generationMode.value === 'image'
        ? '描述画面主体、风格、场景与用途，例如：科技产品主视觉，冷白金属质感，暗色背景，商业海报构图'
        : '描述镜头内容、运动、节奏与画面风格，例如：镜头缓慢推进，产品在暗色展台旋转，光线扫过机身'
)
const canGenerate = computed(() => Boolean(prompt.value.trim()) && !submitting.value && !uploading.value)
const backgroundStyle = computed(() => ({
    backgroundImage: `
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%)
    `
}))

const pageScrollThumbStyle = computed(() => ({
    height: `${pageScrollThumbHeight.value}px`,
    transform: `translateY(${pageScrollThumbTop.value}px)`
}))

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
    const ratio = maxScrollTop > 0 ? scrollTop / maxScrollTop : 0

    pageScrollThumbVisible.value = true
    pageScrollThumbHeight.value = thumbHeight
    pageScrollThumbTop.value = 4 + maxThumbOffset * ratio
}

const syncPageScrollUi = () => {
    if (typeof window !== 'undefined') {
        viewportWidth.value = window.innerWidth
    }
    updateFloatingComposer()
    updatePageScrollThumb()
}

const syncPageScrollFromThumb = (clientY: number) => {
    if (!pageScrollRef.value) return
    const viewportHeight = pageScrollRef.value.clientHeight
    const scrollHeight = pageScrollRef.value.scrollHeight
    const maxScrollTop = Math.max(scrollHeight - viewportHeight, 0)
    const thumbHeight = pageScrollThumbHeight.value
    const maxThumbOffset = Math.max(viewportHeight - thumbHeight - 8, 0)

    if (maxScrollTop <= 0 || maxThumbOffset <= 0) return

    const nextTop = Math.min(
        Math.max(clientY - pageScrollThumbPointerOffset.value, 4),
        4 + maxThumbOffset
    )
    const ratio = (nextTop - 4) / maxThumbOffset
    pageScrollRef.value.scrollTop = ratio * maxScrollTop
    syncPageScrollUi()
}

const stopPageScrollThumbDrag = () => {
    if (typeof window === 'undefined' || !pageScrollThumbDragging.value) return
    pageScrollThumbDragging.value = false
    document.body.style.userSelect = ''
    window.removeEventListener('pointermove', handlePageScrollThumbPointerMove)
    window.removeEventListener('pointerup', stopPageScrollThumbDrag)
    window.removeEventListener('pointercancel', stopPageScrollThumbDrag)
}

const handlePageScrollThumbPointerMove = (event: PointerEvent) => {
    if (!pageScrollThumbDragging.value) return
    syncPageScrollFromThumb(event.clientY)
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

const handleGlobalKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && detailOpen.value) {
        closeWorkDetail()
    }
}

watch(activeInspirationTab, (tab) => {
    if (tab === 'all') return
    if (selectedCard.value?.category !== tab) {
        const nextCard = galleryCards.value.find((item) => item.category === tab)
        if (nextCard) {
            selectedCardId.value = nextCard.id
            selectedCardKey.value = getCardKey(nextCard)
        }
    }
})

const goHome = () => router.push('/')

const togglePopover = (key: 'share' | 'api' | 'notice') => {
    activePopover.value = activePopover.value === key ? '' : key
}

const revokeUploadedAsset = (asset: UploadedAsset) => {
    if (asset.isObjectUrl) {
        URL.revokeObjectURL(asset.url)
    }
}

const getExpandedUploadPreviewWidth = () => {
    const total = uploadedAssets.value.length
    if (total <= 1) {
        return UPLOAD_PANEL_BASE_WIDTH
    }

    return total * UPLOAD_PREVIEW_STEP + UPLOAD_PREVIEW_CARD_WIDTH + 12
}

const getUploadPanelStyle = () => {
    return {
        width: `${UPLOAD_PANEL_BASE_WIDTH}px`,
        flexBasis: `${UPLOAD_PANEL_BASE_WIDTH}px`
    }
}

const getUploadPreviewStyle = () => {
    const width = getExpandedUploadPreviewWidth()
    const isMulti = uploadedAssets.value.length > 1

    return {
        width: `${width}px`,
        justifyContent: isMulti ? 'flex-start' : 'center'
    }
}

const getUploadPreviewFrameStyle = (index: number) => {
    const total = uploadedAssets.value.length
    const isSingle = total <= 1
    const isExpanded = uploadPreviewExpanded.value && total > 1
    const collapsedRotatePattern = [-18, -8, 10, -6, 12, 4]
    const collapsedXPattern = [0, 18, 34, 12, 28, 42]
    const collapsedYPattern = [0, 6, -2, 4, -3, 5]
    const collapsedX = isSingle ? 0 : collapsedXPattern[index % collapsedXPattern.length]
    const collapsedY = isSingle ? 0 : collapsedYPattern[index % collapsedYPattern.length]
    const collapsedRotate = isSingle ? -8.5 : collapsedRotatePattern[index % collapsedRotatePattern.length]
    const expandedX = index * UPLOAD_PREVIEW_STEP
    const expandedY = 0
    const expandedRotate = 0

    return {
        '--upload-preview-transform': `translate3d(${isExpanded ? expandedX : collapsedX}px, ${isExpanded ? expandedY : collapsedY}px, 0) rotate(${isExpanded ? expandedRotate : collapsedRotate}deg)`,
        '--upload-preview-hover-transform': isExpanded ? 'translateY(-6px) scale(1.08)' : 'translateY(-4px) scale(1.08)',
        zIndex: index + 1,
        opacity: 1
    }
}

const getUploadPreviewBadgeStyle = () => {
    const total = uploadedAssets.value.length
    const isExpanded = uploadPreviewExpanded.value && total > 1

    if (isExpanded) {
        return {
            transform: `translate3d(${total * UPLOAD_PREVIEW_STEP}px, 2px, 0) rotate(3deg)`,
            width: `${UPLOAD_PREVIEW_CARD_WIDTH}px`,
            height: '80px',
            borderRadius: '9px',
            background: 'linear-gradient(180deg, #313233 0%, #2a2b2c 100%)',
            boxShadow: 'inset 0 1px 0 rgba(255, 255, 255, 0.03)',
            color: '#a5a5a6',
            fontSize: '32px'
        }
    }

    return {
        transform: 'translate3d(48px, 54px, 0)',
        width: '30px',
        height: '30px',
        borderRadius: '50%',
        background: '#5a5d64',
        color: '#fff',
        fontSize: '20px',
        boxShadow: '0 8px 18px rgba(0, 0, 0, 0.24), 0 0 0 0.5px rgba(255, 255, 255, 0.08)'
    }
}

const removeUploadedAsset = (id: string) => {
    const target = uploadedAssets.value.find((item) => item.id === id)
    if (!target) return
    revokeUploadedAsset(target)
    uploadedAssets.value = uploadedAssets.value.filter((item) => item.id !== id)
    if (!uploadedAssets.value.length) {
        uploadPreviewExpanded.value = false
    }
}

const triggerUpload = () => {
    if (!ensurePcLogin()) return
    fileInputRef.value?.click()
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
    referenceFileName: uploadedFileName.value,
    referenceImage: uploadedPreviewUrl.value,
    referenceImages: uploadedAssets.value.map((item) => item.uri).filter(Boolean)
})

const buildVideoCreateDraft = (task: string): AiCreateDraft => ({
    task,
    prompt: prompt.value.trim(),
    mode: 'video',
    model: currentVideoChannel.value?.label || 'Grok Video（xAIQ）',
    count: '1条',
    ratio: optionState.value.ratio,
    resolution: optionState.value.duration,
    duration: optionState.value.duration,
    quality: '720p',
    referenceFileName: uploadedFileName.value,
    referenceImage: uploadedPreviewUrl.value,
    referenceImages: uploadedAssets.value.map((item) => item.uri).filter(Boolean)
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

    const quantity = selectedQuantity.value
    optionState.value.count = `${quantity}张`

    if (uploadedAssets.value.length > maxReferenceCount.value) {
        uploadedAssets.value.slice(maxReferenceCount.value).forEach(revokeUploadedAsset)
        uploadedAssets.value = uploadedAssets.value.slice(0, maxReferenceCount.value)
    }
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

    if (uploadedAssets.value.length > maxReferenceCount.value) {
        uploadedAssets.value.slice(maxReferenceCount.value).forEach(revokeUploadedAsset)
        uploadedAssets.value = uploadedAssets.value.slice(0, maxReferenceCount.value)
    }
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

const refreshCaseCards = async () => {
    try {
        const [imageCases, videoCases, digitalHumanCases] = await Promise.all([
            getAigcImageCases({ limit: 60 }, { withToken: false }).catch(() => []),
            getAigcVideoCases({ limit: 60 }, { withToken: false }).catch(() => []),
            getAigcDigitalHumanCases({ limit: 60 }, { withToken: false }).catch(() => [])
        ])
        const list = [
            ...(Array.isArray(imageCases) ? imageCases.map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_image' })) : []),
            ...(Array.isArray(videoCases) ? videoCases.map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_video' })) : []),
            ...(Array.isArray(digitalHumanCases) ? digitalHumanCases.map((item: any) => ({ ...item, app_code: item.app_code || 'aigc_digital_human' })) : [])
        ]
        caseCards.value = list
            .filter((item) => item.cover_path || item.media_path || item.cover_url || item.media_url)
            .map((item: any, index: number) => {
                const image = resolveCaseImage(item, index)
                const mediaUrl = normalizeFileUrl(item.media_path || item.media_url || item.media_uri || item.cover_path || item.cover_url || item.cover_uri || '')
                return {
                    id: Number(item.id || index + 1),
                    uniqueId: `${item.app_code || 'case'}-${item.id || index}`,
                    title: item.title || '案例作品',
                    category: item.media_type === 'video' ? 'video' : 'image',
                    appCode: item.app_code || (item.media_type === 'video' ? 'aigc_video' : 'aigc_image'),
                    image,
                    mediaUrl,
                    imageHeight: getCaseCardHeight(item, index),
                    prompt: item.prompt || '',
                    configFields: Array.isArray(item.config_fields) ? item.config_fields : [],
                    source: 'case',
                    referenceAssetUrl: resolveCaseReferenceAssetUrl(item, image),
                    referenceAssetName: item.title || '案例参考图'
                }
            })
        if (caseCards.value.length && !caseCards.value.some((item) => getCardKey(item) === selectedCardKey.value)) {
            selectedCardId.value = caseCards.value[0].id
            selectedCardKey.value = getCardKey(caseCards.value[0])
        }
    } catch (error) {
        console.warn('refresh aigc cases failed', error)
    }
}

const getCaseCardHeight = (item: any, index: number) => {
    const ratio = String(item.ratio || item.aspect_ratio || item.size || '').trim()
    const appCode = String(item.app_code || '')
    const mediaType = String(item.media_type || '')
    if (ratio === '16:9' || ratio === '4:3') return 226
    if (ratio === '9:16' || appCode === 'aigc_digital_human') return 430
    if (ratio === '1:1') return 310
    if (mediaType === 'video') return 340
    const heights = [420, 286, 356, 500, 318, 388, 452, 332]
    return heights[index % heights.length]
}

const getInspirationCardStyle = (item: CardItem) => ({
    '--case-card-height': `${item.imageHeight || 320}px`
})

const getDefaultCaseFallback = (item: any, index: number) => {
    const raw = String(
        item.cover_path
        || item.media_path
        || item.cover_uri
        || (item.media_type === 'video' ? '' : item.media_uri)
        || item.cover_url
        || item.media_url
        || ''
    ).replace(/\\/g, '/')
    const match = raw.match(/app_case\/aigc_image\/default\/card-(\d+)\.png/i)
    const cardNumber = match ? Number(match[1]) : 0
    if (cardNumber >= 1 && cardNumber <= defaultCaseImages.length) {
        return defaultCaseImages[cardNumber - 1]
    }
    return defaultCaseImages[index % defaultCaseImages.length]
}

const resolveCaseImage = (item: any, index: number) => {
    const raw = String(
        item.cover_path
        || item.cover_url
        || item.cover_uri
        || (item.media_type === 'video' ? '' : item.media_path)
        || (item.media_type === 'video' ? '' : item.media_url)
        || item.media_uri
        || ''
    ).trim()
    return normalizeFileUrl(raw) || ''
}

const resolveCaseReferenceAssetUrl = (item: any, previewImage: string) => {
    const referenceImages = Array.isArray(item.reference_images) ? item.reference_images : []
    const firstReference = String(referenceImages[0] || '').trim()
    const cover = String(item.cover_path || item.cover_url || item.cover_uri || '').trim()
    const media = item.media_type === 'video'
        ? ''
        : String(item.media_path || item.media_url || item.media_uri || '').trim()

    return normalizeFileUrl(firstReference || cover || media) || previewImage
}

const handleCardImageError = (item: CardItem) => {
    if (!item.fallbackImage || item.image === item.fallbackImage) return
    item.image = item.fallbackImage
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

const handleUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    const files = Array.from(target.files || [])
    if (!files.length) return

    const availableCount = maxReferenceCount.value - uploadedAssets.value.length
    if (availableCount <= 0) {
        feedback.msgWarning(`最多上传${maxReferenceCount.value}张参考图`)
        target.value = ''
        return
    }

    const uploadFiles = files.slice(0, availableCount)
    if (uploadFiles.length < files.length) {
        feedback.msgWarning(`最多上传${maxReferenceCount.value}张参考图`)
    }

    uploading.value = true
    try {
        const nextAssets: UploadedAsset[] = []
        for (const [index, file] of uploadFiles.entries()) {
            const objectUrl = URL.createObjectURL(file)
            try {
                const res: any = await uploadAppImage({ file })
                const uri = res?.uri || res?.url || res?.path
                nextAssets.push({
                    id: `${Date.now()}-${index}-${Math.random().toString(36).slice(2, 8)}`,
                    name: file.name,
                    url: objectUrl,
                    uri,
                    isObjectUrl: true
                })
            } catch (error) {
                URL.revokeObjectURL(objectUrl)
                throw error
            }
        }

        uploadedAssets.value = [...uploadedAssets.value, ...nextAssets]
        uploadPreviewExpanded.value = false
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图上传失败')
    } finally {
        uploading.value = false
        target.value = ''
    }
}

const activateSidebar = (key: SidebarKey) => {
    activeSidebar.value = key
    if (key === 'inspiration') return
    if (key === 'create') {
        setDraft(null)
        router.push(buildSidebarRouteLocation(key))
        return
    }
    router.push(buildSidebarRouteLocation(key))
}

const setGenerationMode = (mode: GenerationMode) => {
    generationMode.value = mode
    openedOption.value = ''
    if (mode === 'image') {
        syncAigcSelection()
    }
    if (mode === 'video') {
        syncAigcVideoSelection()
    }
}

const toggleOption = (key: OptionKey, disabled = false) => {
    if (disabled) return
    if (!getOptionValues(key).length) return
    openedOption.value = openedOption.value === key ? '' : key
}

const setOption = (key: OptionKey, value: string) => {
    if (generationMode.value === 'image') {
        if (key === 'model') {
            const nextChannel = channels.value.find((item) => item.label === value || item.value === value)
            if (nextChannel) {
                selectedChannelCode.value = nextChannel.value
            }
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
            if (nextChannel) {
                selectedVideoChannelCode.value = nextChannel.value
            }
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

const appendTag = (tag: string) => {
    prompt.value = prompt.value ? `${prompt.value}，${tag}` : tag
}

const getCardPrompt = (item: CardItem) => {
    return item.prompt || `${item.title}，高清，写实风格，主体突出，光影细腻`
}

const getCaseTypeLabel = (item: CardItem) => {
    if (item.appCode === 'aigc_digital_human') return '数字人案例'
    return item.category === 'video' ? '视频案例' : '图片案例'
}

const openWorkDetail = (item: CardItem) => {
    selectedCardId.value = item.id
    selectedCardKey.value = getCardKey(item)
    activeDetailCard.value = item
    promptScrollExpanded.value = false
    detailOpen.value = true
}

const closeWorkDetail = () => {
    detailOpen.value = false
}

const selectCard = (item: CardItem) => {
    selectedCardId.value = item.id
    selectedCardKey.value = getCardKey(item)
    prompt.value = `${item.title}，请保持高级感、电影感、细节丰富`
}

const scrollToComposer = () => {
    pageScrollRef.value?.scrollTo({ top: 0, behavior: 'smooth' })
}

const replaceUploadedAssets = (assets: UploadedAsset[]) => {
    uploadedAssets.value.forEach(revokeUploadedAsset)
    uploadedAssets.value = assets
    uploadPreviewExpanded.value = false
}

const buildCaseReferenceAsset = async (item: CardItem): Promise<UploadedAsset> => {
    const targetUrl = item.referenceAssetUrl || item.image
    const response = await fetch(targetUrl)
    if (!response.ok) throw new Error('案例参考图获取失败')
    const blob = await response.blob()
    const extension = blob.type.includes('png') ? 'png' : blob.type.includes('webp') ? 'webp' : 'jpg'
    const file = new File([blob], `${item.referenceAssetName || item.title}.${extension}`, { type: blob.type || 'image/png' })
    const objectUrl = URL.createObjectURL(file)
    try {
        const res: any = await uploadAppImage({ file })
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

const syncCardReference = async (item: CardItem) => {
    if (!ensurePcLogin()) return false
    uploading.value = true
    feedback.loading('正在同步案例...')
    try {
        const asset = await buildCaseReferenceAsset(item)
        replaceUploadedAssets([asset])
        return true
    } finally {
        feedback.closeLoading()
        uploading.value = false
    }
}

const copyCardPrompt = async (item: CardItem) => {
    if (!ensurePcLogin()) return
    if (item.appCode === 'aigc_digital_human') {
        selectedCardId.value = item.id
        selectedCardKey.value = getCardKey(item)
        closeWorkDetail()
        router.push(buildSidebarRouteLocation('avatar'))
        return
    }
    selectedCardId.value = item.id
    selectedCardKey.value = getCardKey(item)
    generationMode.value = item.category
    prompt.value = getCardPrompt(item)
    scrollToComposer()
    try {
        await syncCardReference(item)
        feedback.msgSuccess('已同步案例到创作区')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgWarning(error?.msg || error?.message || '提示词已同步，参考图同步失败')
    }
}

const applyDetailPrompt = async () => {
    if (!activeDetailCard.value) return
    await copyCardPrompt(activeDetailCard.value)
    closeWorkDetail()
}

const applyDetailReference = async () => {
    if (!activeDetailCard.value) return
    if (activeDetailCard.value.appCode === 'aigc_digital_human') {
        await copyCardPrompt(activeDetailCard.value)
        closeWorkDetail()
        return
    }
    generationMode.value = activeDetailCard.value.category
    scrollToComposer()
    try {
        await syncCardReference(activeDetailCard.value)
        feedback.msgSuccess('参考图已同步')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图同步失败')
    }
    closeWorkDetail()
}

const toggleActiveDetailFavorite = () => {
    if (!activeDetailCard.value) return
    if (!ensurePcLogin()) return
    toggleFavorite(getCardFavoriteCategory(activeDetailCard.value), getCardFavoriteId(activeDetailCard.value))
}

const downloadDetailAsset = () => {
    if (!ensurePcLogin()) return
    if (!activeDetailCard.value) return
    const url = activeDetailCard.value.mediaUrl || activeDetailCard.value.image
    const fileName = `${activeDetailCard.value.title}.${activeDetailCard.value.category === 'video' ? 'mp4' : 'png'}`
    if (!downloadPcAsset(url, fileName)) {
        openPcAsset(url)
    }
}

const useHistoryPrompt = (historyPrompt: string) => {
    prompt.value = historyPrompt
}

const generateImage = async () => {
    if (submitting.value) return
    if (!canGenerate.value) return
    if (!ensurePcLogin()) return
    if (generationMode.value === 'image') {
        syncAigcSelection()
        if (!currentChannel.value?.value) {
            feedback.msgError('暂无可用通道')
            return
        }
        if (!optionState.value.ratio || !optionState.value.resolution) {
            feedback.msgError('请选择图片参数')
            return
        }

        submitting.value = true
        feedback.loading('正在提交生成任务...')
        try {
            const referenceImages = uploadedAssets.value.map((item) => item.uri).filter(Boolean)
            const res: any = await generateAigcImage({
                prompt: prompt.value.trim(),
                reference_images: referenceImages,
                ratio: optionState.value.ratio,
                quality: optionState.value.resolution,
                quantity: selectedQuantity.value,
                channel: selectedChannelCode.value,
                negative_prompt: ''
            })
            setDraft(buildCreateDraft(String(res?.task_id || Date.now())))
            lastGeneratedLabel.value = `已提交生成任务${selectedQuantity.value > 1 ? `，预计生成${selectedQuantity.value}张图片` : ''}`
            feedback.msgSuccess(lastGeneratedLabel.value)
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
        feedback.msgError('暂无可用视频通道')
        return
    }
    if (!optionState.value.ratio || !optionState.value.duration) {
        feedback.msgError('请选择视频参数')
        return
    }

    submitting.value = true
    feedback.loading('正在提交视频生成任务...')
    try {
        const referenceImages = uploadedAssets.value.map((item) => item.uri).filter(Boolean)
        const res: any = await generateAigcVideo({
            prompt: prompt.value.trim(),
            reference_images: referenceImages,
            ratio: optionState.value.ratio,
            quality: optionState.value.duration,
            quantity: 1,
            channel: selectedVideoChannelCode.value || currentVideoChannel.value.value,
            negative_prompt: ''
        })
        setDraft(buildVideoCreateDraft(String(res?.task_id || Date.now())))
        lastGeneratedLabel.value = '已提交视频生成任务'
        feedback.msgSuccess(lastGeneratedLabel.value)
        router.push(buildSidebarRouteLocation('create'))
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交视频生成任务失败')
    } finally {
        feedback.closeLoading()
        submitting.value = false
    }
}
const { lockFn: generateImageLock, isLock: isGenerateImageLocked } = useLockFn(generateImage)

onMounted(() => {
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

watch(detailOpen, (value) => {
    if (typeof document === 'undefined') return
    if (pageScrollRef.value) {
        pageScrollRef.value.style.overflowY = value ? 'hidden' : 'auto'
    }
    if (value) {
        stopPageScrollThumbDrag()
        pageScrollThumbVisible.value = false
        return
    }
    nextTick(() => {
        syncPageScrollUi()
    })
})
</script>

<style lang="scss" scoped>
:global(html) {
    overflow: hidden;
    background: #050505;
}

:global(body) {
    overflow: hidden;
    background: #050505;
}

:global(#__nuxt) {
    min-height: 100vh;
    background: #050505;
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
    padding: 24px 32px 220px;
    background: #050505;
    color: #fff;
    overflow-x: hidden;
    overflow-y: auto;

    &__background,
    &__noise,
    &__orbit,
    &__scan,
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

    &__orbit {
        left: 50%;
        top: 38%;
        width: min(82vw, 980px);
        height: min(82vw, 980px);
        inset: auto;
        border: 1px solid rgba(0, 255, 170, 0.08);
        border-radius: 50%;
        transform: translate(-50%, -50%);
        box-shadow:
            0 0 80px rgba(0, 255, 170, 0.06),
            inset 0 0 80px rgba(0, 255, 170, 0.035);
        opacity: 0.88;
    }

    &__orbit::before,
    &__orbit::after {
        content: '';
        position: absolute;
        border-radius: 50%;
    }

    &__orbit::before {
        inset: 18%;
        border: 1px solid rgba(0, 206, 255, 0.07);
    }

    &__orbit::after {
        width: 8px;
        height: 8px;
        left: 10%;
        top: 58%;
        background: #00f0a8;
        box-shadow:
            0 0 18px rgba(0, 240, 168, 0.9),
            210px -220px 0 rgba(0, 206, 255, 0.72),
            610px -20px 0 rgba(0, 240, 168, 0.68);
        animation: orbitPulse 4.8s ease-in-out infinite;
    }

    &__orbit--outer {
        animation: orbitRotate 38s linear infinite;
    }

    &__orbit--inner {
        width: min(58vw, 700px);
        height: min(58vw, 700px);
        border-color: rgba(0, 206, 255, 0.08);
        animation: orbitRotateReverse 30s linear infinite;
    }

    &__scan {
        background:
            linear-gradient(120deg, transparent 0 35%, rgba(0, 255, 170, 0.07) 46%, transparent 58%),
            radial-gradient(circle at 50% 28%, rgba(0, 255, 170, 0.16), transparent 34%),
            radial-gradient(circle at 52% 24%, rgba(0, 170, 255, 0.12), transparent 28%);
        mix-blend-mode: screen;
        opacity: 0.72;
        animation: heroScan 8s ease-in-out infinite alternate;
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

@keyframes orbitRotate {
    from {
        transform: translate(-50%, -50%) rotate(0deg);
    }

    to {
        transform: translate(-50%, -50%) rotate(360deg);
    }
}

@keyframes orbitRotateReverse {
    from {
        transform: translate(-50%, -50%) rotate(360deg);
    }

    to {
        transform: translate(-50%, -50%) rotate(0deg);
    }
}

@keyframes orbitPulse {
    0%,
    100% {
        opacity: 0.42;
        transform: scale(0.8);
    }

    50% {
        opacity: 1;
        transform: scale(1.2);
    }
}

@keyframes heroScan {
    0% {
        transform: translate3d(-4%, -2%, 0);
        opacity: 0.38;
    }

    100% {
        transform: translate3d(4%, 2%, 0);
        opacity: 0.78;
    }
}

@keyframes heroTitleFlow {
    from {
        background-position: 0% 50%;
    }

    to {
        background-position: 100% 50%;
    }
}

.app-header,
.app-main {
    position: relative;
    z-index: 1;
}

.app-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 12;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 22px 40px 18px;
    background: linear-gradient(180deg, rgba(5, 5, 5, 0.9) 0%, rgba(5, 5, 5, 0.58) 74%, rgba(5, 5, 5, 0) 100%);
    backdrop-filter: blur(18px);
}

.app-logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    text-decoration: none;

    &__image {
        width: 28px;
        height: 28px;
    }

    &__text {
        font-size: 20px;
        line-height: 1;
        font-weight: 700;
    }
}

.app-header__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.header-popover-wrap {
    position: relative;
}

.header-popover {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 220px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(17, 17, 17, 0.96);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);

    strong {
        display: block;
        margin-bottom: 6px;
        font-size: 14px;
        font-weight: 600;
    }

    p {
        margin: 0;
        color: rgba(255, 255, 255, 0.68);
        font-size: 13px;
        line-height: 1.6;
    }

    &--compact {
        width: 200px;
    }
}

.app-pill,
.app-icon-button,
.app-avatar,
.sidebar-item,
.upload-panel,
.prompt-toggle,
.select-chip,
.select-chip-menu__item,
.prompt-submit__button,
.inspiration-tabs__item,
.inspiration-card__action,
.hero-tag {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.app-pill,
.app-icon-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(30, 31, 32, 0.96);
    color: #fff;
    font-size: 14px;

    &:hover {
        background: rgba(46, 47, 48, 0.96);
    }
}

.app-pill__icon.is-gold {
    color: #f6d26c;
}

.app-pill__asset {
    display: block;
    object-fit: contain;

    &--xs {
        width: 11px;
        height: 11px;
    }

    &--sm {
        width: 16px;
        height: 16px;
    }
}

.app-icon-button {
    justify-content: center;
    min-width: 32px;
    padding: 0 10px;

    &__asset {
        width: 20px;
        height: 20px;
        object-fit: contain;
    }
}

.app-avatar {
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 50%;
    background: transparent;
    overflow: hidden;

    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
}

.app-sidebar {
    position: fixed;
    left: 28px;
    top: calc(50% - 216px);
    height: 432px;
    display: flex;
    flex-direction: column;
    gap: 18px;
    z-index: 14;
    pointer-events: auto;
}

.sidebar-item {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 8px;
    width: 72px;
    height: 72px;
    padding: 10px 8px;
    border-radius: 18px;
    background: transparent;
    color: rgba(255, 255, 255, 0.86);
    font-size: 12px;
    line-height: 1;
    box-sizing: border-box;
    flex-shrink: 0;
    pointer-events: auto;
    user-select: none;

    &__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;

        img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
    }

    &.is-active,
    &:hover {
        background: rgba(255, 255, 255, 0.06);
        color: #fff;
    }
}

.app-main {
    width: 100%;
    margin: 128px auto 0;
}

.hero-panel__heading {
    text-align: center;

    .hero-panel__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 14px;
        min-height: 38px;
        margin-bottom: 22px;
        padding: 0 24px;
        border: 1px solid rgba(0, 255, 170, 0.18);
        border-radius: 999px;
        background: rgba(0, 255, 170, 0.05);
        color: #00f0a8;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.06em;
        box-shadow:
            inset 0 0 20px rgba(0, 255, 170, 0.05),
            0 0 24px rgba(0, 255, 170, 0.08);
    }

    .hero-panel__eyebrow span {
        width: 18px;
        height: 1px;
        background: linear-gradient(90deg, transparent, #00f0a8);
    }

    .hero-panel__eyebrow span:last-child {
        background: linear-gradient(90deg, #00f0a8, transparent);
    }

    h1 {
        margin: 0;
        font-size: clamp(46px, 5.2vw, 88px);
        line-height: 1.06;
        font-weight: 900;
        letter-spacing: 0;
        color: transparent;
        background: linear-gradient(100deg, #ffffff 0%, #00c8ff 34%, #00f0a8 72%, #ffffff 100%);
        background-size: 180% 100%;
        -webkit-background-clip: text;
        background-clip: text;
        text-shadow: 0 0 36px rgba(0, 255, 170, 0.12);
        animation: heroTitleFlow 5.8s ease-in-out infinite alternate;
    }

    p {
        width: min(640px, 100%);
        margin: 16px auto 0;
        color: rgba(255, 255, 255, 0.62);
        font-size: 18px;
        line-height: 1.7;
    }
}

.hero-panel {
    position: relative;
    z-index: 3;
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
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.78);
    font-size: 13px;

    &.is-active,
    &:hover {
        background: rgba(255, 255, 255, 0.14);
        color: #fff;
    }
}

.prompt-card {
    position: relative;
    z-index: 4;
    display: flex;
    gap: 16px;
    width: min(960px, 100%);
    margin: 44px auto 0;
    padding: 18px 18px 14px 10px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 22px;
    background: rgba(34, 34, 34, 0.96);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
    backdrop-filter: blur(10px);

    &__body {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-width: 0;
    }

    &__textarea {
        width: 100%;
        min-height: 92px;
        border: 0;
        outline: none;
        resize: none;
        background: transparent;
        color: #fff;
        font-size: 15px;
        line-height: 1.7;

        &::placeholder {
            color: #7f7f80;
        }
    }

    &__footer {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 18px;
        margin-top: 18px;
        padding-left: 34px;
    }
}

.upload-panel {
    position: relative;
    z-index: 8;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 88px;
    height: 86px;
    flex-shrink: 0;
    background: transparent;
    color: #a5a5a6;
    transform: translateX(2px);
    overflow: visible;
    cursor: pointer;
    transition:
        color 0.2s ease;

    &__tilt {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 80px;
        border-radius: 9px;
        background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
        transform: rotate(-8.5deg) translateY(1px);
        transition:
            background 0.2s ease,
            transform 0.2s ease;
    }

    &__plus {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        font-size: 32px;
        line-height: 1;
        transform: rotate(9deg);
    }

    &__preview {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        width: 88px;
        height: 86px;
        overflow: visible;
        transition: width 0.22s ease;
    }

    &__preview-stack {
        position: relative;
        display: block;
        width: 100%;
        height: 80px;
        overflow: visible;
    }

    &__preview-frame {
        position: absolute;
        left: 0;
        top: 0;
        display: inline-flex;
        align-items: center;
        justify-content: flex-start;
        width: 64px;
        height: 80px;
        transition:
            transform 0.22s ease,
            filter 0.22s ease;
        transform-origin: center center;
        transform: var(--upload-preview-transform);
        overflow: visible;

        &:hover {
            z-index: 20 !important;
            filter: drop-shadow(0 16px 24px rgba(0, 0, 0, 0.34));
            transform: var(--upload-preview-transform) var(--upload-preview-hover-transform);
        }
    }

    &__preview-card {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 80px;
        box-sizing: border-box;
        padding: 0;
        border-radius: 9px;
        border: 0.2px solid rgba(255, 255, 255, 0.92);
        background: #111214;
        overflow: visible;
        transition:
            border-color 0.2s ease,
            box-shadow 0.2s ease;

        img {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: 8px;
            background: #101010;
            object-fit: cover;
            overflow: hidden;
        }
    }

    &__preview-frame:hover &__preview-card {
        border-color: rgba(255, 255, 255, 0.98);
        box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08);
    }

    &__badge {
        position: absolute;
        left: 0;
        top: 0;
        z-index: 24;
        box-sizing: border-box;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 80px;
        border-radius: 50%;
        background: #5a5d64;
        color: #fff;
        font-size: 32px;
        line-height: 1;
        transition:
            transform 0.2s ease,
            width 0.2s ease,
            height 0.2s ease,
            border-radius 0.2s ease,
            background 0.2s ease,
            box-shadow 0.2s ease,
            color 0.2s ease;
    }

    &__badge-plus {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
        line-height: 1;
        transition: transform 0.2s ease;
    }

    &__badge.is-expanded &__badge-plus {
        transform: rotate(0deg);
    }

    &.is-filled {
        color: #fff;
        transform: none;
        overflow: visible;

        
        .upload-panel__preview-frame:hover .upload-panel__delete {
            opacity: 1;
            transform: scale(1);
        }

        &:hover .upload-panel__badge {
            background: #6a6e75;
        }
    }

    &:hover {
        color: #fff;

        .upload-panel__tilt {
            background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
            transform: rotate(-8.5deg) translateY(-1px);
        }
    }
}

.upload-panel__delete {
    position: absolute;
    top: -10px;
    right: -10px;
    z-index: 8;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 50%;
    background: rgba(18, 20, 24, 0.92);
    color: #fff;
    font-size: 14px;
    line-height: 1;
    opacity: 0;
    transform: scale(0.82);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease,
        background 0.2s ease;

    &:hover {
        background: rgba(18, 20, 24, 1);
    }
}

.prompt-tools {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    flex-wrap: nowrap;
    margin-left: -128px;
}

.mode-switch {
    display: inline-flex;
    align-items: center;
    width: auto;
    height: 32px;
    padding: 2px;
    border-radius: 32px;
    background: #050505;

    .prompt-toggle {
        min-width: 78px;
        height: 28px;
    }
}

.prompt-toggle,
.select-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border-radius: 32px;
    background: #292a2b;
    color: #a5a5a6;
    font-size: 14px;
    line-height: 14px;
    white-space: nowrap;
    flex-shrink: 0;

    &:hover {
        color: #fff;
    }

    img {
        width: 16px;
        height: 16px;
        object-fit: contain;
        flex-shrink: 0;
    }
}

.prompt-toggle.is-active,
.select-chip.is-open {
    background: #050505;
    color: #fff;
}

.mode-switch .prompt-toggle {
    background: transparent;
}

.mode-switch .prompt-toggle.is-active {
    background: #292a2b;
}

.select-chip-wrap {
    position: relative;
    display: inline-flex;

    &:has(.select-chip-menu) {
        z-index: 8;
    }
}

.select-chip__arrow {
    width: 14px !important;
    height: 14px !important;
    opacity: 0.65;
}

.select-chip-menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    min-width: 148px;
    padding: 6px;
    border-radius: 12px;
    background: rgba(21, 21, 21, 0.98);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.32);
    z-index: 20;

    &__item {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        min-height: 34px;
        padding: 0 10px;
        border-radius: 8px;
        background: transparent;
        color: rgba(255, 255, 255, 0.72);
        font-size: 13px;
        text-align: left;
        white-space: nowrap;

        &:hover,
        &.is-active {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }
    }
}

.select-chip__ratio-preview {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.select-chip__ratio-shape {
    display: block;
    border: 1px solid rgba(255, 255, 255, 0.48);
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.06);
}

.prompt-submit {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 0;

    &__credit {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #a5a5a6;
        font-size: 14px;
        line-height: 32px;
        white-space: nowrap;
    }

    &__spark {
        width: 11px;
        height: 11px;
        object-fit: contain;
    }

    &__button {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: #fff;
        color: #222;
        font-size: 18px;
        font-weight: 700;
        flex-shrink: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;

        &:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
    }
}

.inspiration-board {
    position: relative;
    z-index: 1;
    width: min(1693px, var(--ai-content-width));
    margin: 88px var(--ai-content-gutter) 0 var(--ai-content-left);
    overflow: visible;

    &__meta {
        margin-left: auto;
        color: #a1a1a1;
        font-size: 14px;
    }

    &__scroller {
        min-height: 760px;
        overflow: hidden;
        padding-right: 0;
    }
}

.inspiration-grid {
    display: grid;
    grid-template-columns: repeat(var(--case-column-count, 5), minmax(0, 1fr));
    align-items: start;
    gap: 8px;
    width: 100%;

    &__column {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-width: 0;
    }
}

.inspiration-card {
    position: relative;
    display: block;
    width: 100%;
    height: var(--case-card-height, 320px);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    margin: 0;
    background: rgba(255, 255, 255, 0.04);
    cursor: pointer;
    transform: translateZ(0);
    transition:
        border-color 0.2s ease,
        transform 0.2s ease,
        box-shadow 0.2s ease;

    &__overlay {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
    }

    img,
    video {
        display: block;
        position: relative;
        width: 100%;
        height: 100%;
        object-fit: cover;
        background: #050505;
    }

    &__overlay {
        background: linear-gradient(180deg, rgba(13, 17, 20, 0.02) 40%, rgba(13, 17, 20, 0.94) 100%);
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    &__info {
        position: absolute;
        left: 14px;
        right: 14px;
        bottom: 54px;
        z-index: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
        opacity: 0;
        transform: translateY(8px);
        transition: all 0.2s ease;

        strong {
            font-size: 16px;
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
        left: 12px;
        right: 12px;
        bottom: 12px;
        z-index: 2;
        height: 36px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.95);
        color: #222;
        font-size: 14px;
        font-weight: 500;
        opacity: 0;
        transform: translateY(8px);
        transition: all 0.2s ease;
    }

    &:hover,
    &.is-selected {
        border-color: rgba(255, 255, 255, 0.2);
        box-shadow: 0 18px 38px rgba(0, 0, 0, 0.28);
        transform: translateY(-2px);
    }

    &:hover &__overlay,
    &:hover &__info,
    &.is-selected &__overlay,
    &.is-selected &__info {
        opacity: 1;
        transform: translateY(0);
    }

    &:hover &__action {
        opacity: 1;
        transform: translateY(0);
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
        border-radius: 50%;
        border: 1px solid rgba(255, 255, 255, 0.12);
        background: rgba(17, 17, 19, 0.72);
        color: #fff;
        font-size: 28px;
        line-height: 1;
        transition:
            border-color 0.2s ease,
            background 0.2s ease;

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
        }

        img,
        video {
            display: block;
            height: calc(100vh - 40px);
            max-width: 100%;
            max-height: calc(100vh - 40px);
            width: auto;
            border-radius: 18px;
            object-fit: contain;
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.4);
            background: #000;
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

    &__author-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
    }

    &__author-group {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }

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
            font-size: 16px;
            font-weight: 600;
            line-height: 1.2;
            color: rgba(255, 255, 255, 0.98);
        }

        span {
            color: rgba(255, 255, 255, 0.74);
            font-size: 12px;
            line-height: 1.4;
        }
    }

    &__social {
        display: inline-flex;
        align-items: center;
        gap: 12px;
        color: rgba(255, 255, 255, 0.92);
        font-size: 14px;

        button {
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

            &.is-active {
                background: #ffd84d;
            }

            img {
                width: 16px;
                height: 16px;
                display: block;
                object-fit: contain;
            }

            &.is-active img {
                filter: invert(1);
            }
        }
    }

    &__type {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 74px;
        height: 28px;
        padding: 0 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.94);
        font-size: 12px;
        flex-shrink: 0;
    }

    &__header {
        display: flex;
        flex-direction: column;
        gap: 16px;
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
            font-size: 15px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.94);
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
        height: 44px;
        padding: 0 20px;
        border: 0;
        border-radius: 12px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
    }

    &__ghost {
        flex: 1;
        background: rgba(255, 255, 255, 0.08);
        color: #fff;
    }

    &__primary {
        flex: 1;
        background: rgba(255, 255, 255, 0.12);
        color: #fff;
    }
}

.inspiration-board__scroller,
.work-detail__content,
.work-detail__prompt-body {
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.ai-app-page {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.ai-app-page::-webkit-scrollbar {
    width: 0;
    height: 0;
    background: transparent;
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

.inspiration-board__scroller::-webkit-scrollbar-thumb:hover,
.work-detail__content::-webkit-scrollbar-thumb:hover,
.work-detail__prompt-body::-webkit-scrollbar-thumb:hover {
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
    left: calc(var(--ai-content-left) + (100vw - var(--ai-content-left) - var(--ai-content-gutter)) / 2);
    bottom: 24px;
    z-index: 11;
    width: min(960px, var(--ai-content-width));
    transform: translateX(-50%);
}

.floating-prompt .prompt-card {
    width: 100%;
    margin-top: 0;
}

.floating-prompt .select-chip-menu {
    top: auto;
    bottom: calc(100% + 8px);
}

@media (max-width: 1600px) {
    .app-main {
        width: 100%;
        margin-left: auto;
    }

    .inspiration-board {
        width: min(1354px, var(--ai-content-width));
    }
}

@media (max-width: 1100px) {
    .ai-app-page {
        padding: 20px 20px 210px;
    }

    .app-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px 20px 14px;
    }

    .app-header__actions {
        width: 100%;
        justify-content: flex-start;
    }

    .app-sidebar {
        position: static;
        flex-direction: row;
        flex-wrap: wrap;
        margin-top: 28px;
    }

    .app-main {
        width: 100%;
        margin-top: 214px;
        margin-left: auto;
    }

    .prompt-card {
        width: 100%;
    }

    .inspiration-board {
        width: min(1015px, calc(100vw - 40px));
        margin-right: auto;
        margin-left: auto;
    }

    .floating-prompt {
        left: 50%;
        width: calc(100vw - 40px);
    }

    .work-detail__panel {
        grid-template-columns: 1fr;
    }

    .work-detail__media {
        min-height: 360px;
        padding: 72px 20px 20px;
    }

    .work-detail__content {
        padding: 24px 18px 18px;
        border-left: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.06);
    }
}

@media (max-width: 820px) {
    .header-popover {
        right: auto;
        left: 0;
    }

    .prompt-card {
        flex-direction: column;
    }

    .upload-panel {
        width: 100%;
        height: 96px;
        transform: none;

        &__tilt {
            width: 60px;
            height: 86px;
        }

        &__preview {
            width: 96px;
            height: 84px;
        }
    }

    .prompt-card__footer {
        flex-direction: column;
        align-items: flex-start;
    }

    .prompt-submit {
        width: 100%;
        justify-content: space-between;
    }

    .inspiration-board__scroller {
        min-height: auto;
    }

    .inspiration-board {
        width: min(676px, calc(100vw - 24px));
    }

    .floating-prompt {
        width: calc(100vw - 32px);
        bottom: 12px;
    }

    .work-detail__meta {
        grid-template-columns: 1fr;
    }

    .work-detail__footer {
        flex-direction: column;
    }

    .work-detail__author-row,
    .work-detail__title-row {
        flex-direction: column;
    }

    .work-detail__ghost,
    .work-detail__primary {
        width: 100%;
    }
}

@media (max-width: 520px) {
    .ai-app-page {
        padding-inline: 14px;
    }

    .app-logo__text {
        font-size: 18px;
    }

    .app-pill,
    .app-icon-button {
        font-size: 13px;
    }

    .inspiration-board__scroller {
        height: auto;
        overflow: visible;
        padding-right: 0;
    }

    .inspiration-board {
        width: min(337px, calc(100vw - 28px));
    }

    .app-header {
        gap: 12px;
    }

    .app-header__actions {
        gap: 6px;
    }

    .floating-prompt {
        width: calc(100vw - 16px);
    }

}
</style>
