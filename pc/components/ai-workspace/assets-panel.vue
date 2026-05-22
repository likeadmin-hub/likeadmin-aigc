<template>
    <section class="assets-shell">
        <div class="assets-toolbar">
            <div class="assets-categories" role="tablist" aria-label="资产分类">
                <button
                    v-for="item in assetCategoryOptions"
                    :key="item"
                    :class="['assets-categories__item', { 'is-active': activeCategory === item }]"
                    type="button"
                    @click="activeCategory = item"
                >
                    {{ categoryLabelMap[item] }}
                </button>
            </div>
        </div>

        <div class="assets-subtoolbar">
            <div class="assets-tabs" role="tablist" aria-label="资产筛选">
                <button :class="['assets-tabs__item', { 'is-active': activeTab === 'all' }]" type="button" @click="activeTab = 'all'">
                    {{ allTabLabel }}
                </button>
                <button :class="['assets-tabs__item', { 'is-active': activeTab === 'favorites' }]" type="button" @click="activeTab = 'favorites'">
                    我的收藏
                </button>
            </div>

            <div :class="['assets-selection-bar', { 'assets-selection-bar--idle': !batchMode }]">
                <template v-if="batchMode">
                    <span class="assets-selection-bar__count">{{ selectionCountLabel }}</span>
                    <div class="assets-selection-bar__actions">
                        <button class="assets-action-button" type="button" :disabled="!selectedAssetIds.length" @click="deleteSelectedAssets">
                            <img :src="deleteIcon" alt="" />
                            <span>删除</span>
                        </button>
                        <button class="assets-action-button" type="button" :disabled="!selectedAssetIds.length" @click="downloadSelectedAssets">
                            <img :src="downloadIcon" alt="" />
                            <span>下载</span>
                        </button>
                        <button class="assets-action-button" type="button" :disabled="!selectedAssetIds.length" @click="favoriteSelectedAssets">
                            <img :src="favoriteIcon" alt="" />
                            <span>收藏</span>
                        </button>
                        <button class="assets-cancel-button" type="button" @click="exitBatchMode">
                            <img :src="closeSmallIcon" alt="" />
                            <span>取消选择</span>
                        </button>
                    </div>
                </template>

                <button v-else class="assets-batch" type="button" @click="toggleBatchMode">
                    <img class="assets-batch__asset" :src="fullSelectionIcon" alt="" />
                    <span>批量管理</span>
                </button>
            </div>
        </div>

        <div class="assets-scroll">
            <div v-if="assetSections.length" class="assets-groups">
                <section v-for="section in assetSections" :key="section.label" class="assets-group">
                    <h2>{{ section.label }}</h2>
                    <div class="assets-grid">
                        <article
                            v-for="item in section.items"
                            :key="item.id"
                            :class="['assets-card', { 'is-batch': batchMode, 'is-selected': isSelected(item.id), 'is-failed': item.status === 'failed' }]"
                            role="button"
                            tabindex="0"
                            @click="handleAssetClick(item)"
                            @keydown.enter.prevent="handleAssetClick(item)"
                            @keydown.space.prevent="handleAssetClick(item)"
                        >
                            <template v-if="item.status === 'failed'">
                                <span class="assets-card__failed">
                                    <strong>生成失败</strong>
                                    <em>{{ item.error || '上游生成失败，请调整参数后重试' }}</em>
                                </span>
                            </template>
                            <template v-else-if="item.video">
                                <video :src="item.video" :poster="item.image || undefined" muted playsinline preload="metadata"></video>
                                <span v-if="!batchMode" class="assets-card__play" aria-hidden="true">▶</span>
                            </template>
                            <img v-else-if="item.image" :src="item.image" :alt="item.title" />

                            <span v-if="batchMode" :class="['assets-card__check', { 'is-selected': isSelected(item.id) }]" aria-hidden="true">
                                <img v-if="isSelected(item.id)" class="assets-card__checkmark" :src="checkSmallIcon" alt="" />
                            </span>

                            <span v-if="!batchMode && item.badge" class="assets-card__badge">{{ item.badge }}</span>
                            <span v-if="!batchMode && item.duration" class="assets-card__duration">{{ item.duration }}</span>
                            <span v-if="!batchMode && item.dateText" class="assets-card__date">{{ item.dateText }}</span>
                            <button
                                v-if="!batchMode && item.status !== 'failed'"
                                :class="['assets-card__favorite', { 'is-active': item.favorite }]"
                                type="button"
                                aria-label="收藏作品"
                                @pointerdown.stop
                                @click.stop.prevent="toggleFavoriteAsset(item.id)"
                            >
                                <img :src="favoriteIcon" alt="" />
                            </button>
                            <a
                                v-if="!batchMode && item.status !== 'failed' && (item.video || item.image)"
                                class="assets-card__download"
                                aria-label="下载作品"
                                :href="getAssetDownloadHref(item)"
                                :download="getAssetDownloadName(item, getAssetDownloadHref(item))"
                                target="_blank"
                                rel="noopener noreferrer"
                                @pointerdown.stop
                                @click.stop="handleAssetDownloadClick"
                            >
                                <img :src="downloadIcon" alt="" />
                            </a>
                        </article>
                    </div>
                </section>
            </div>

            <div v-else class="assets-empty">
                <img class="assets-empty__placeholder" :src="assetsEmptyImage" alt="" />
                <p>{{ assetsEmptyText }}</p>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="previewAsset" class="work-detail" @click.self="closeAssetPreview">
                <div class="work-detail__panel">
                    <div class="work-detail__media">
                        <button class="work-detail__close" type="button" aria-label="关闭" @click="closeAssetPreview">×</button>
                        <div class="work-detail__media-frame">
                            <video v-if="previewAsset.video" :src="previewAsset.video" :poster="previewAsset.image" controls autoplay playsinline></video>
                            <img v-else-if="previewAsset.image" :src="previewAsset.image" alt="生成结果" />
                            <div v-else class="work-detail__placeholder">
                                <strong>{{ getAssetStatusText(previewAsset) }}</strong>
                                <span>{{ getAssetStatusDescription(previewAsset) }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="work-detail__content">
                        <div class="work-detail__header">
                            <div class="work-detail__author-row">
                                <div class="work-detail__author">
                                    <span class="work-detail__avatar">{{ getAssetTypeLabel(previewAsset).slice(0, 1) }}</span>
                                    <div class="work-detail__author-meta">
                                        <strong>我的创作</strong>
                                    </div>
                                </div>
                            </div>
                            <div class="work-detail__subline">
                                <strong>{{ getAssetTypeLabel(previewAsset) }}</strong>
                                <span>{{ previewAsset.dateText || previewAsset.date }}</span>
                                <span>内容由 AI 生成</span>
                            </div>
                        </div>

                        <div class="work-detail__prompt">
                            <div class="work-detail__prompt-head">
                                <span>提示词</span>
                            </div>
                            <div class="work-detail__prompt-body">
                                <p>{{ getAssetPromptText(previewAsset) }}</p>
                            </div>
                            <div class="work-detail__config">
                                <span v-for="item in getAssetConfigItems(previewAsset)" :key="`asset-detail-${item}`" class="work-detail__config-text">{{ item }}</span>
                            </div>
                            <div v-if="previewAsset.error" class="work-detail__section">
                                <span>失败原因</span>
                                <p>{{ previewAsset.error }}</p>
                            </div>
                        </div>
                        <div class="work-detail__actions">
                            <button :class="['work-detail__favorite', { 'is-active': previewAsset.favorite }]" type="button" @click.stop.prevent="toggleFavoriteAsset(previewAsset.id)">
                                <img :src="favoriteIcon" alt="" />
                                {{ previewAsset.favorite ? '已收藏' : '收藏作品' }}
                            </button>
                            <a
                                v-if="getAssetDownloadHref(previewAsset)"
                                class="work-detail__download"
                                :href="getAssetDownloadHref(previewAsset)"
                                :download="getAssetDownloadName(previewAsset, getAssetDownloadHref(previewAsset))"
                                target="_blank"
                                rel="noopener noreferrer"
                                @pointerdown.stop
                                @click.stop="handleAssetDownloadClick"
                            >
                                <img :src="downloadIcon" alt="" />
                                下载作品
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>
<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { deleteAigcDigitalHumanResult, getAigcDigitalHumanResults } from '@/apps/aigc_digital_human/api'
import { deleteAigcImageResult, getAigcImageResults } from '@/apps/aigc_image/api'
import { deleteAigcVideoResult, getAigcVideoResults } from '@/apps/aigc_video/api'
import { deleteImageHumanResult, getImageHumanResults } from '@/apps/image_human/api'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'
import { downloadPcAsset, getPcDownloadExtension, resolvePcDownloadUrl } from '@/utils/download'
import checkSmallIcon from '@/assets/images/icon/Check-small.svg'
import closeSmallIcon from '@/assets/images/icon/Close-small.svg'
import deleteIcon from '@/assets/images/icon/Delete-themes.svg'
import favoriteIcon from '@/assets/images/icon/shoucang.svg'
import fullSelectionIcon from '@/assets/images/icon/Full-selection.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'

type AssetCategory = 'all' | 'image' | 'video' | 'avatar' | 'tool'
type AssetTab = 'all' | 'favorites'
type AssetSource = 'image' | 'video' | 'digital_human' | 'image_human' | 'tool'
type AssetStatus = 'success' | 'failed'

interface AssetItem {
    id: string
    source: AssetSource
    taskId: number
    resultId: number
    favoriteId: string
    title: string
    image: string
    video?: string
    category: AssetCategory
    status: AssetStatus
    error?: string
    timestamp: number
    date: string
    dateText: string
    favorite: boolean
    badge?: string
    duration?: string
}

const assetCategoryOptions: AssetCategory[] = ['all', 'image', 'video', 'avatar', 'tool']
const assetsEmptyImage = 'https://aigclikeadmin.oss-cn-shenzhen.aliyuncs.com/uploads/images/20260519/20260519165642975309142.jpg'
const categoryLabelMap: Record<AssetCategory, string> = {
    all: '全部',
    image: '图片',
    video: '视频',
    avatar: '数字人',
    tool: '其他'
}

const activeCategory = ref<AssetCategory>('all')
const activeTab = ref<AssetTab>('all')
const batchMode = ref(false)
const selectedAssetIds = ref<string[]>([])
const assetItems = ref<AssetItem[]>([])
const previewAsset = ref<AssetItem | null>(null)
const { favoriteIds, isFavorite, setFavorite, toggleFavorite } = useAiWorkspaceFavorites()
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()

const allTabLabel = computed(() => activeCategory.value === 'all' ? '全部资产' : `全部${categoryLabelMap[activeCategory.value]}`)
const assetsEmptyText = computed(() => {
    const categoryName = activeCategory.value === 'all' ? '资产' : `${categoryLabelMap[activeCategory.value]}作品`
    return activeTab.value === 'favorites' ? `暂无收藏${categoryName}` : `暂无${categoryName}`
})
const visibleAssetItems = computed(() =>
    assetItems.value.filter(
        (item) => (activeCategory.value === 'all' || item.category === activeCategory.value) && (activeTab.value === 'all' || item.favorite)
    )
)
const selectionCountLabel = computed(() => `已选择 ${selectedAssetIds.value.length} 项`)
const assetSections = computed(() =>
    visibleAssetItems.value.reduce<Array<{ label: string; items: AssetItem[] }>>((sections, item) => {
        const currentSection = sections.find((section) => section.label === item.date)
        if (currentSection) {
            currentSection.items.push(item)
            return sections
        }
        sections.push({ label: item.date, items: [item] })
        return sections
    }, [])
)

const extractListData = <T = any>(payload: any): T[] => {
    if (Array.isArray(payload)) return payload
    if (Array.isArray(payload?.lists)) return payload.lists
    if (Array.isArray(payload?.list)) return payload.list
    if (Array.isArray(payload?.rows)) return payload.rows
    if (Array.isArray(payload?.data)) return payload.data
    return []
}

const normalizeAssetUrl = (url: unknown) => {
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

const normalizeTimestamp = (value: unknown) => {
    if (typeof value === 'number' && value > 0) return value > 100000000000 ? Math.floor(value / 1000) : value
    if (typeof value === 'string' && value.trim()) {
        const numeric = Number(value)
        if (Number.isFinite(numeric) && numeric > 0) return numeric > 100000000000 ? Math.floor(numeric / 1000) : numeric
        const parsed = Date.parse(value)
        if (Number.isFinite(parsed)) return Math.floor(parsed / 1000)
    }
    return 0
}

const getAssetTimestamp = (item: any) => {
    const firstResult = Array.isArray(item?.results) ? item.results[0] || {} : {}
    return normalizeTimestamp(
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

const formatAssetDateGroup = (timestamp: number) => {
    if (!timestamp) return '最近作品'
    const date = new Date(timestamp * 1000)
    return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date)
}

const formatAssetDateText = (timestamp: number) => {
    if (!timestamp) return ''
    return new Intl.DateTimeFormat('zh-CN', { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }).format(timestamp * 1000)
}

const formatDuration = (value: unknown) => {
    const duration = Number(value || 0)
    if (!Number.isFinite(duration) || duration <= 0) return ''
    return `${Math.round(duration)}s`
}

const getFirstResult = (item: any, keys: string[]) => Array.isArray(item?.results)
    ? item.results.find((result: any) => keys.some((key) => result?.[key]))
    : null

const getImageUrl = (item: any) => {
    const firstResult = getFirstResult(item, ['image_url', 'image_uri', 'url', 'image', 'file_url'])
    return normalizeAssetUrl(
        item.image_url || item.image || item.file_url || item.cover_url || item.url || item.image_uri
        || firstResult?.image_url || firstResult?.image || firstResult?.file_url || firstResult?.cover_url || firstResult?.url || firstResult?.image_uri
    )
}

const getVideoUrl = (item: any) => {
    const firstResult = getFirstResult(item, ['video_url', 'video_uri', 'url', 'video', 'file_url', 'media_url', 'download_url', 'origin_url'])
    return normalizeAssetUrl(
        item.video_url || item.video || item.file_url || item.media_url || item.download_url || item.origin_url || item.url || item.video_uri
        || firstResult?.video_url || firstResult?.video || firstResult?.file_url || firstResult?.media_url || firstResult?.download_url || firstResult?.origin_url || firstResult?.url || firstResult?.video_uri
    )
}

const getCoverUrl = (item: any) => {
    const firstResult = getFirstResult(item, ['cover_url', 'cover_uri', 'poster_url', 'poster_uri', 'thumb_url', 'thumb_uri', 'thumbnail_url', 'thumbnail_uri', 'image_url', 'image_uri', 'image'])
    return normalizeAssetUrl(
        item.cover_url || item.cover_uri || item.cover
        || item.poster_url || item.poster_uri || item.poster
        || item.thumb_url || item.thumb_uri || item.thumbnail_url || item.thumbnail_uri || item.thumbnail
        || item.image_url || item.image_uri || item.image
        || firstResult?.cover_url || firstResult?.cover_uri || firstResult?.cover
        || firstResult?.poster_url || firstResult?.poster_uri || firstResult?.poster
        || firstResult?.thumb_url || firstResult?.thumb_uri || firstResult?.thumbnail_url || firstResult?.thumbnail_uri || firstResult?.thumbnail
        || firstResult?.image_url || firstResult?.image_uri || firstResult?.image
    )
}

const getAssetFavoriteId = (item: Pick<AssetItem, 'favoriteId' | 'taskId' | 'resultId' | 'id'>) =>
    item.favoriteId || String(item.taskId || item.resultId || item.id)

const normalizeAssetStatus = (status: unknown): AssetStatus => {
    const value = String(status || '').toLowerCase()
    return ['failed', 'fail', 'error'].includes(value) ? 'failed' : 'success'
}

const normalizeAssetError = (item: any) => String(item.error || item.error_msg || item.fail_reason || item.message || '').trim()

const createAssetItem = (source: AssetSource, item: any, index: number): AssetItem | null => {
    const rawResultId = Number(item.result_id || item.id || 0)
    const rawTaskNumberId = Number(item.task_id || item.id || 0)
    const rawTaskId = String(item.task_id || item.id || index)
    const id = `${source}-${rawResultId || rawTaskId}`
    const isDigitalHuman = source === 'digital_human' || source === 'image_human'
    const timestamp = getAssetTimestamp(item)
    const category: AssetCategory = source === 'image' ? 'image' : isDigitalHuman ? 'avatar' : 'video'
    const status = normalizeAssetStatus(item.status)
    const video = source === 'image' ? '' : getVideoUrl(item)
    const fallbackImage = Array.isArray(item.reference_images) && item.reference_images[0]
        ? normalizeAssetUrl(item.reference_images[0])
        : ''
    const imageHumanFallbackImage = source === 'image_human'
        ? normalizeAssetUrl(item.image_url || item.image_uri || item.image || '')
        : ''
    const image = source === 'image' ? getImageUrl(item) : (getCoverUrl(item) || fallbackImage || imageHumanFallbackImage)
    if (status !== 'failed' && !image && !video) return null
    return {
        id,
        source,
        taskId: rawTaskNumberId,
        resultId: rawResultId,
        favoriteId: rawTaskId,
        title: item.title || item.prompt || item.script_text || (source === 'image_human' ? '形象作品' : isDigitalHuman ? '数字人作品' : source === 'video' ? '视频作品' : '图片作品'),
        image,
        video,
        category,
        status,
        error: normalizeAssetError(item),
        timestamp,
        date: formatAssetDateGroup(timestamp),
        dateText: formatAssetDateText(timestamp),
        favorite: isFavorite(category, rawTaskId),
        badge: source === 'image_human' ? '形象' : isDigitalHuman ? '数字人' : source === 'video' ? '视频' : '图片',
        duration: formatDuration(item.duration)
    }
}

const fetchAssetList = async (loader: () => Promise<any>, source: AssetSource) => {
    try {
        const response = await loader()
        return extractListData(response)
            .map((item, index) => createAssetItem(source, item, index))
            .filter(Boolean) as AssetItem[]
    } catch (error) {
        console.error(`load ${source} assets failed`, error)
        return []
    }
}

const loadAssets = async () => {
    if (!userStore.isLogin) {
        assetItems.value = []
        return
    }
    const [images, videos, digitalHumans, imageHumans] = await Promise.all([
        fetchAssetList(getAigcImageResults, 'image'),
        fetchAssetList(getAigcVideoResults, 'video'),
        fetchAssetList(getAigcDigitalHumanResults, 'digital_human'),
        fetchAssetList(getImageHumanResults, 'image_human')
    ])
    assetItems.value = [...images, ...videos, ...digitalHumans, ...imageHumans].sort((a, b) => b.timestamp - a.timestamp)
}

const ensureVisibleSelection = () => {
    const visibleIds = new Set(visibleAssetItems.value.map((item) => item.id))
    selectedAssetIds.value = selectedAssetIds.value.filter((id) => visibleIds.has(id))
    if (!selectedAssetIds.value.length && visibleAssetItems.value.length) {
        selectedAssetIds.value = [visibleAssetItems.value[0].id]
    }
}

const exitBatchMode = () => {
    batchMode.value = false
    selectedAssetIds.value = []
}

const toggleBatchMode = () => {
    batchMode.value = !batchMode.value
    if (batchMode.value) {
        ensureVisibleSelection()
        return
    }
    selectedAssetIds.value = []
}

const isSelected = (id: string) => selectedAssetIds.value.includes(id)

const handleAssetClick = (item: AssetItem) => {
    if (!batchMode.value) {
        if (item.status === 'failed') return
        if (item.video || item.image) {
            previewAsset.value = item
        }
        return
    }
    selectedAssetIds.value = isSelected(item.id)
        ? selectedAssetIds.value.filter((itemId) => itemId !== item.id)
        : [...selectedAssetIds.value, item.id]
}

const closeAssetPreview = () => {
    previewAsset.value = null
}

const deleteSelectedAssets = async () => {
    if (!selectedAssetIds.value.length) return
    if (!ensurePcLogin()) return
    try {
        const selectedAssets = assetItems.value.filter((item) => selectedAssetIds.value.includes(item.id))
        await Promise.all(selectedAssets.map((item) => deleteAsset(item)))
        const selectedIdSet = new Set(selectedAssetIds.value)
        assetItems.value = assetItems.value.filter((item) => !selectedIdSet.has(item.id))
    } catch (error) {
        console.error('delete assets failed', error)
        return
    }

    if (!assetItems.value.length) {
        exitBatchMode()
        return
    }

    ensureVisibleSelection()
}

const favoriteSelectedAssets = async () => {
    if (!selectedAssetIds.value.length) return
    if (!ensurePcLogin()) return
    const selectedIdSet = new Set(selectedAssetIds.value)
    assetItems.value.forEach((item) => {
        if (selectedIdSet.has(item.id)) setFavorite(item.category, getAssetFavoriteId(item), true)
    })
    assetItems.value = assetItems.value.map((item) =>
        selectedIdSet.has(item.id) ? { ...item, favorite: true } : item
    )

    ensureVisibleSelection()
}

const toggleFavoriteAsset = (id: string) => {
    if (!ensurePcLogin()) return
    const asset = assetItems.value.find((item) => item.id === id)
    if (!asset) return
    const nextFavorite = toggleFavorite(asset.category, getAssetFavoriteId(asset))
    assetItems.value = assetItems.value.map((item) => item.id === id ? { ...item, favorite: nextFavorite } : item)
    if (previewAsset.value?.id === id) {
        previewAsset.value = { ...previewAsset.value, favorite: nextFavorite }
    }
}

const deleteAsset = async (item: AssetItem) => {
    const taskId = item.taskId || item.resultId
    if (!taskId) return
    if (item.source === 'image') return deleteAigcImageResult({ id: taskId, task_id: taskId })
    if (item.source === 'video') return deleteAigcVideoResult({ id: item.resultId || taskId, task_id: taskId })
    if (item.source === 'digital_human') return deleteAigcDigitalHumanResult({ id: item.resultId || taskId, task_id: taskId })
    if (item.source === 'image_human') return deleteImageHumanResult({ id: item.resultId || taskId, task_id: taskId })
}

const getAssetDownloadName = (asset: AssetItem, url = '') => {
    const target = url || asset.video || asset.image
    const ext = getPcDownloadExtension(target, asset.source === 'image' ? 'png' : 'mp4')
    return `${asset.title || 'asset'}.${ext}`
}

const getAssetTypeLabel = (asset: AssetItem | null) =>
    asset ? categoryLabelMap[asset.category] || asset.badge || '作品' : '作品'

const getAssetPromptText = (asset: AssetItem | null) =>
    asset?.title || '无提示词'

const getAssetStatusText = (asset: AssetItem | null) =>
    asset?.status === 'failed' ? '生成失败' : '暂无预览'

const getAssetStatusDescription = (asset: AssetItem | null) =>
    asset?.error || '当前作品暂未获取到可预览资源'

const getAssetConfigItems = (asset: AssetItem | null) => {
    if (!asset) return []
    return [
        getAssetTypeLabel(asset),
        asset.duration,
        asset.dateText || asset.date
    ].filter(Boolean)
}

const getAssetDownloadHref = (asset: AssetItem | null) =>
    resolvePcDownloadUrl(asset?.video || asset?.image || '')

const handleAssetDownloadClick = (event: MouseEvent) => {
    if (userStore.isLogin) return
    event.preventDefault()
    ensurePcLogin()
}

const downloadSelectedAssets = async () => {
    if (!selectedAssetIds.value.length) return
    if (!ensurePcLogin()) return

    for (const id of selectedAssetIds.value) {
        try {
            const asset = assetItems.value.find((item) => item.id === id)
            if (!asset) continue
            const url = getAssetDownloadHref(asset)
            if (url) downloadPcAsset(url, getAssetDownloadName(asset, url))
        } catch (error) {
            console.error('download asset failed', error)
        }
    }
}

watch([activeCategory, activeTab], () => {
    if (!batchMode.value) {
        selectedAssetIds.value = []
        return
    }
    ensureVisibleSelection()
})

watch(visibleAssetItems, () => {
    if (!batchMode.value) return
    ensureVisibleSelection()
})

watch(favoriteIds, () => {
    assetItems.value = assetItems.value.map((item) => ({
        ...item,
        favorite: isFavorite(item.category, getAssetFavoriteId(item))
    }))
}, { deep: true })

onMounted(loadAssets)

watch(() => userStore.isLogin, loadAssets)
</script>

<style lang="scss" scoped>
.assets-shell {
    --asset-card-min-width: 260px;
    display: flex;
    flex-direction: column;
    width: 100%;
    height: 100%;
    min-height: 0;
    padding-top: 0;
    overflow: hidden;
    box-sizing: border-box;
}

.assets-toolbar,
.assets-tabs,
.assets-group,
.assets-empty,
.assets-scroll {
    width: 100%;
    max-width: none;
}

.assets-toolbar {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    flex-shrink: 0;
    margin-bottom: 28px;
}

.assets-subtoolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    width: 100%;
    min-height: 44px;
    flex-shrink: 0;
}

.assets-categories {
    display: flex;
    align-items: center;
    gap: 40px;
}

.assets-categories__item,
.assets-tabs__item,
.assets-batch,
.assets-card,
.assets-action-button,
.assets-cancel-button {
    border: 0;
    cursor: pointer;
}

.assets-categories__item {
    position: relative;
    padding: 0 0 8px;
    background: transparent;
    color: #a1a1a1;
    font-size: 20px;
    font-weight: 500;
    line-height: 1;
    transition: color 0.2s ease;
}

.assets-categories__item:hover {
    color: #fff;
}

.assets-categories__item.is-active {
    color: #fff;
}

.assets-categories__item.is-active::after {
    content: '';
    position: absolute;
    left: 50%;
    bottom: -2px;
    width: 40px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
    transform: translateX(-50%);
}

.assets-batch {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 44px;
    padding: 0 20px;
    border-radius: 12px;
    background: #222;
    color: #fff;
    font-size: 14px;
    line-height: 1;
    transition: box-shadow 0.2s ease, transform 0.2s ease;
}

.assets-batch:hover,
.assets-batch.is-active {
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16);
    transform: translateY(-1px);
}

.assets-batch__asset {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.assets-tabs {
    display: flex;
    align-items: center;
    gap: var(--category-chip-gap, 20px);
    flex: 0 1 auto;
    flex-wrap: wrap;
    min-width: 0;
}

.assets-tabs__item {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: var(--category-chip-min-height, 32px);
    padding: 0 var(--category-chip-padding-x, 16px);
    background: transparent;
    border-radius: var(--category-chip-radius, 4px);
    color: var(--category-chip-text-color, rgba(255, 255, 255, 0.62));
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
    opacity: 1;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.assets-tabs__item:hover {
    color: var(--category-chip-active-color, #fff);
}

.assets-tabs__item.is-active {
    background: var(--category-chip-active-bg, #2c2c2c);
    color: var(--category-chip-active-color, #fff);
}

.assets-selection-bar {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 20px;
    flex: 0 0 auto;
    margin-left: auto;
    white-space: nowrap;
}

.assets-selection-bar--idle {
    align-self: stretch;
}

.assets-selection-bar__count {
    color: #a1a1a1;
    font-size: 14px;
    line-height: 1;
    white-space: nowrap;
}

.assets-selection-bar__actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: nowrap;
    justify-content: flex-end;
}

.assets-action-button,
.assets-cancel-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 44px;
    padding: 0 20px;
    border-radius: 12px;
    background: #222;
    color: #fff;
    font-size: 14px;
    line-height: 1;
    transition: opacity 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

.assets-action-button {
    min-width: 96px;
}

.assets-cancel-button {
    min-width: auto;
    padding: 0 4px;
    background: transparent;
}

.assets-action-button:hover,
.assets-cancel-button:hover {
    transform: translateY(-1px);
}

.assets-action-button:hover {
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.16);
}

.assets-cancel-button:hover {
    box-shadow: none;
}

.assets-action-button:disabled {
    opacity: 0.42;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

.assets-action-button img,
.assets-cancel-button img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.assets-groups {
    display: flex;
    flex-direction: column;
    gap: 56px;
    padding-bottom: 0;
}

.assets-scroll {
    flex: 1;
    min-height: 0;
    margin-top: 20px;
    padding-right: 6px;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.assets-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.assets-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.assets-scroll::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.assets-scroll::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.assets-group {
    display: flex;
    flex-direction: column;
    gap: 36px;
}

.assets-group h2 {
    margin: 0;
    color: #fff;
    font-size: 24px;
    font-weight: 500;
    line-height: 1;
}

.assets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(var(--asset-card-min-width), 1fr));
    gap: 4px;
    width: 100%;
}

.assets-card {
    position: relative;
    overflow: hidden;
    aspect-ratio: 1 / 1;
    padding: 0;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    text-align: left;
}

.assets-card img,
.assets-card video,
.assets-card__failed {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.35s ease;
}

.assets-card__failed {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-end;
    gap: 10px;
    padding: 22px;
    background:
        linear-gradient(180deg, rgba(46, 14, 14, 0.34) 0%, rgba(10, 10, 10, 0.94) 100%);
    color: #fff;
    text-align: left;
    box-sizing: border-box;
}

.assets-card__failed strong {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    background: rgba(255, 73, 73, 0.18);
    color: #ffb5b5;
    font-size: 13px;
    font-style: normal;
    font-weight: 600;
}

.assets-card__failed em {
    position: relative;
    z-index: 1;
    display: -webkit-box;
    max-width: 100%;
    overflow: hidden;
    color: rgba(255, 255, 255, 0.82);
    font-size: 14px;
    font-style: normal;
    line-height: 1.55;
    text-overflow: ellipsis;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 4;
}

.assets-card.is-failed {
    cursor: default;
}

.assets-card::after {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, rgba(0, 0, 0, 0.02) 0%, rgba(0, 0, 0, 0.14) 58%, rgba(0, 0, 0, 0.48) 100%);
    opacity: 0;
    transition: opacity 0.25s ease;
}

.assets-card:hover img,
.assets-card:hover video,
.assets-card:hover .assets-card__failed,
.assets-card.is-selected img,
.assets-card.is-selected video,
.assets-card.is-selected .assets-card__failed {
    transform: scale(1.03);
}

.assets-card:hover::after,
.assets-card.is-selected::after,
.assets-card.is-batch::after {
    opacity: 1;
}

.assets-card.is-selected {
    box-shadow: inset 0 0 0 2px #fff;
}

.assets-card__check,
.assets-card__badge,
.assets-card__duration,
.assets-card__date,
.assets-card__play,
.assets-card__download,
.assets-card__favorite {
    position: absolute;
    z-index: 1;
}

.assets-card__play {
    left: 50%;
    top: 50%;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    padding-left: 4px;
    border: 1px solid rgba(255, 255, 255, 0.28);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.58);
    box-shadow: 0 16px 42px rgba(0, 0, 0, 0.36);
    color: #fff;
    font-size: 20px;
    line-height: 1;
    transform: translate(-50%, -50%);
    backdrop-filter: blur(12px);
    transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease;
}

.assets-card:hover .assets-card__play {
    background: rgba(255, 255, 255, 0.92);
    color: #050505;
    transform: translate(-50%, -50%) scale(1.04);
}

.assets-card__check {
    top: 8px;
    left: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border: 1.5px solid rgba(255, 255, 255, 0.92);
    border-radius: 8px;
    background: rgba(15, 15, 15, 0.18);
    backdrop-filter: blur(4px);
}

.assets-card__check.is-selected {
    background: rgba(255, 255, 255, 0.08);
    border-color: #fff;
}

.assets-card__checkmark {
    width: 15px;
    height: 15px;
    object-fit: contain;
}

.assets-card__badge,
.assets-card__duration,
.assets-card__date {
    bottom: 12px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.55);
    color: #fff;
    font-size: 12px;
    font-weight: 500;
    line-height: 1;
}

.assets-card__badge {
    left: 12px;
}

.assets-card__duration {
    right: 12px;
}

.assets-card__date {
    top: 12px;
    left: 12px;
    bottom: auto;
    color: rgba(255, 255, 255, 0.78);
}

.assets-card__download,
.assets-card__favorite {
    top: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1px solid rgba(255, 255, 255, 0.28);
    background: rgba(0, 0, 0, 0.62);
    opacity: 0;
    backdrop-filter: blur(10px);
    transition: opacity 0.2s ease, background 0.2s ease, transform 0.2s ease;
}

.assets-card__download {
    right: 10px;
}

.assets-card__favorite {
    right: 56px;
}

.assets-card__download img,
.assets-card__favorite img {
    width: 18px;
    height: 18px;
    object-fit: contain;
    filter: drop-shadow(0 1px 4px rgba(0, 0, 0, 0.8));
}

.assets-card:hover .assets-card__favorite,
.assets-card:hover .assets-card__download {
    opacity: 1;
}

.assets-card__download:hover,
.assets-card__favorite:hover {
    border-color: rgba(255, 255, 255, 0.92);
    background: rgba(255, 255, 255, 0.92);
    transform: scale(1.04);
}

.assets-card__download:hover img,
.assets-card__favorite:hover img {
    filter: invert(1) drop-shadow(0 1px 2px rgba(255, 255, 255, 0.18));
}

.assets-card__favorite.is-active {
    border-color: #ffd84d;
    background: #ffd84d;
}

.assets-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    width: auto;
    min-height: max(320px, 100%);
    margin: 0;
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
    backdrop-filter: none;
    text-align: center;
}

.assets-empty__placeholder {
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

.assets-empty p {
    margin: 0;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    line-height: 22px;
    text-align: center;
}

.work-detail {
    position: fixed;
    inset: 0;
    z-index: 96;
    background: rgba(10, 10, 12, 0.96);
    backdrop-filter: blur(14px);
}

.work-detail__panel {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 380px;
    width: 100%;
    height: 100%;
}

.work-detail__close {
    position: absolute;
    top: 24px;
    left: 24px;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 50%;
    background: rgba(17, 17, 19, 0.72);
    color: #fff;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        background 0.2s ease;
}

.work-detail__close:hover {
    border-color: rgba(255, 255, 255, 0.24);
    background: rgba(28, 28, 31, 0.94);
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

.work-detail__media video,
.work-detail__media img,
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
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: min(520px, calc(100vw - 500px));
    aspect-ratio: 3 / 4;
    padding: 24px;
    background: #202127;
    color: #fff;
    text-align: center;
}

.work-detail__placeholder strong {
    font-size: 22px;
    font-weight: 600;
}

.work-detail__placeholder span {
    max-width: 360px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    line-height: 1.7;
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
    min-width: 0;
}

.work-detail__author-meta strong {
    display: block;
    overflow: hidden;
    color: rgba(255, 255, 255, 0.98);
    font-size: 16px;
    font-weight: 600;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
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

.work-detail__subline strong {
    color: rgba(255, 255, 255, 0.94);
    font-size: 13px;
    font-weight: 600;
}

.work-detail__section {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
}

.work-detail__section span {
    color: rgba(255, 255, 255, 0.5);
    font-size: 13px;
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

.work-detail__download:disabled {
    opacity: 0.45;
    cursor: not-allowed;
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

@media (max-width: 1680px) {
    .assets-shell {
        --asset-card-min-width: 248px;
    }
}

@media (max-width: 1400px) {
    .assets-shell {
        --asset-card-min-width: 224px;
    }
}

@media (max-width: 1100px) {
    .assets-shell {
        --asset-card-min-width: 210px;
        height: 100%;
        min-height: 0;
        padding-top: 0;
        overflow: hidden;
    }

    .assets-toolbar {
        flex-direction: column;
        align-items: stretch;
        margin-bottom: 24px;
    }

    .assets-subtoolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .assets-categories {
        flex-wrap: wrap;
        gap: var(--category-chip-gap, 20px);
    }

    .assets-batch {
        justify-content: center;
    }

    .assets-scroll {
        flex: 1;
        min-height: 0;
        padding-right: 0;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .assets-tabs {
        flex-wrap: wrap;
        gap: var(--category-chip-gap, 20px);
    }

    .assets-selection-bar {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
        white-space: normal;
    }

    .assets-selection-bar__actions {
        flex-wrap: wrap;
        justify-content: flex-start;
    }

    .assets-action-button,
    .assets-cancel-button {
        flex: 1;
    }

    .assets-cancel-button {
        padding-inline: 20px;
        background: #222;
    }

    .assets-group {
        gap: 24px;
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

    .work-detail__media video,
    .work-detail__media img,
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
    .assets-shell {
        --asset-card-min-width: 160px;
    }

    .assets-categories__item {
        font-size: 18px;
    }

    .assets-group h2 {
        font-size: 22px;
    }

    .assets-empty__placeholder {
        width: 180px;
        height: 180px;
    }

    .work-detail__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .work-detail__download,
    .work-detail__favorite {
        width: 100%;
    }
}
</style>



