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
                <button
                    :class="['assets-tabs__item', { 'is-active': activeTab === 'all' }]"
                    type="button"
                    @click="activeTab = 'all'"
                >
                    {{ allTabLabel }}
                </button>
                <button
                    :class="['assets-tabs__item', { 'is-active': activeTab === 'favorites' }]"
                    type="button"
                    @click="activeTab = 'favorites'"
                >
                    收藏
                </button>
            </div>

            <div :class="['assets-selection-bar', { 'assets-selection-bar--idle': !batchMode }]">
                <template v-if="batchMode">
                    <span class="assets-selection-bar__count">{{ selectionCountLabel }}</span>

                    <div class="assets-selection-bar__actions">
                        <button
                            class="assets-action-button"
                            type="button"
                            :disabled="!selectedAssetIds.length"
                            @click="deleteSelectedAssets"
                        >
                            <img :src="deleteIcon" alt="" />
                            <span>删除</span>
                        </button>

                        <button
                            class="assets-action-button"
                            type="button"
                            :disabled="!selectedAssetIds.length"
                            @click="downloadSelectedAssets"
                        >
                            <img :src="downloadIcon" alt="" />
                            <span>下载</span>
                        </button>

                        <button
                            class="assets-action-button"
                            type="button"
                            :disabled="!selectedAssetIds.length"
                            @click="favoriteSelectedAssets"
                        >
                            <img :src="favoriteIcon" alt="" />
                            <span>收藏</span>
                        </button>

                        <button class="assets-cancel-button" type="button" @click="exitBatchMode">
                            <img :src="closeSmallIcon" alt="" />
                            <span>取消批量</span>
                        </button>
                    </div>
                </template>

                <button
                    v-else
                    class="assets-batch"
                    type="button"
                    @click="toggleBatchMode"
                >
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

                            <span
                                v-if="batchMode"
                                :class="['assets-card__check', { 'is-selected': isSelected(item.id) }]"
                                aria-hidden="true"
                            >
                                <img
                                    v-if="isSelected(item.id)"
                                    class="assets-card__checkmark"
                                    :src="checkSmallIcon"
                                    alt=""
                                />
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
                            <button
                                v-if="!batchMode && item.status !== 'failed' && (item.video || item.image)"
                                class="assets-card__download"
                                type="button"
                                aria-label="下载作品"
                                @pointerdown.stop
                                @click.stop.prevent="downloadAsset(item)"
                            >
                                <img :src="downloadIcon" alt="" />
                            </button>
                        </article>
                    </div>
                </section>
            </div>

            <div v-else class="assets-empty">
                <strong>当前分类下还没有内容</strong>
                <span>试试切换分类、切换收藏筛选，或者稍后再来看。</span>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="previewAsset" class="assets-preview" @click.self="closeAssetPreview">
                <div class="assets-preview__panel">
                    <div class="assets-preview__media">
                        <video
                            v-if="previewAsset.video"
                            :src="previewAsset.video"
                            :poster="previewAsset.image"
                            controls
                            autoplay
                            playsinline
                        ></video>
                        <img v-else-if="previewAsset.image" :src="previewAsset.image" :alt="previewAsset.title" />
                        <div v-else class="assets-preview__placeholder">
                            <strong>{{ previewAsset.status === 'failed' ? '生成失败' : '暂无预览' }}</strong>
                            <span>{{ previewAsset.error || '当前作品暂未获取到可预览资源' }}</span>
                        </div>
                        <div v-if="previewAsset.status !== 'failed'" class="assets-preview__floating-actions">
                            <button
                                :class="['assets-preview__icon-action', { 'is-active': previewAsset.favorite }]"
                                type="button"
                                aria-label="收藏作品"
                                @click.stop.prevent="toggleFavoriteAsset(previewAsset.id)"
                            >
                                <img :src="favoriteIcon" alt="" />
                            </button>
                            <button
                                class="assets-preview__icon-action"
                                type="button"
                                aria-label="下载作品"
                                @click.stop.prevent="downloadPreviewAsset"
                            >
                                <img :src="downloadIcon" alt="" />
                            </button>
                        </div>
                    </div>
                    <div class="assets-preview__content">
                        <div class="assets-preview__toolbar">
                            <button class="assets-preview__close" type="button" aria-label="关闭" @click="closeAssetPreview">×</button>
                        </div>
                        <div class="assets-preview__meta">
                            <strong>{{ previewAsset.title }}</strong>
                            <span>{{ previewAsset.dateText || previewAsset.date }}</span>
                        </div>
                        <div class="assets-preview__chips">
                            <span>{{ categoryLabelMap[previewAsset.category] }}</span>
                            <span v-if="previewAsset.duration">{{ previewAsset.duration }}</span>
                        </div>
                        <div class="assets-preview__actions">
                            <button
                                :class="['assets-preview__favorite', { 'is-active': previewAsset.favorite }]"
                                type="button"
                                @click.stop.prevent="toggleFavoriteAsset(previewAsset.id)"
                            >
                                <img :src="favoriteIcon" alt="" />
                                {{ previewAsset.favorite ? '已收藏' : '收藏作品' }}
                            </button>
                            <button
                                class="assets-preview__download"
                                type="button"
                                @click.stop.prevent="downloadPreviewAsset"
                            >
                                <img :src="downloadIcon" alt="" />
                                下载作品
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </section>
</template>

<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import { deleteAigcDigitalHumanResult, getAigcDigitalHumanResults, getAigcDigitalHumanTask } from '@/apps/aigc_digital_human/api'
import { deleteAigcImageResult, getAigcImageResults, getAigcImageTask } from '@/apps/aigc_image/api'
import { deleteAigcVideoResult, getAigcVideoResults, getAigcVideoTask } from '@/apps/aigc_video/api'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'
import { downloadPcAsset, getPcDownloadExtension, openPcAsset } from '@/utils/download'
import checkSmallIcon from '@/assets/images/icon/Check-small.svg'
import closeSmallIcon from '@/assets/images/icon/Close-small.svg'
import deleteIcon from '@/assets/images/icon/Delete-themes.svg'
import favoriteIcon from '@/assets/images/icon/shoucang.svg'
import fullSelectionIcon from '@/assets/images/icon/Full-selection.svg'
import downloadIcon from '@/assets/images/icon/xiazai.svg'

type AssetCategory = 'all' | 'image' | 'video' | 'avatar' | 'tool'
type AssetTab = 'all' | 'favorites'
type AssetSource = 'image' | 'video' | 'digital_human' | 'tool'
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
const categoryLabelMap: Record<AssetCategory, string> = {
    all: '全部',
    image: '图片',
    video: '视频',
    avatar: '数字人',
    tool: '工具'
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
const visibleAssetItems = computed(() =>
    assetItems.value.filter(
        (item) => (activeCategory.value === 'all' || item.category === activeCategory.value) && (activeTab.value === 'all' || item.favorite)
    )
)
const selectionCountLabel = computed(() => `已选 ${selectedAssetIds.value.length} 项`)
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
    const isDigitalHuman = source === 'digital_human'
    const timestamp = getAssetTimestamp(item)
    const category: AssetCategory = source === 'image' ? 'image' : isDigitalHuman ? 'avatar' : 'video'
    const status = normalizeAssetStatus(item.status)
    const video = source === 'image' ? '' : getVideoUrl(item)
    const fallbackImage = Array.isArray(item.reference_images) && item.reference_images[0]
        ? normalizeAssetUrl(item.reference_images[0])
        : ''
    const image = source === 'image' ? getImageUrl(item) : (getCoverUrl(item) || fallbackImage)
    if (status !== 'failed' && !image && !video) return null
    return {
        id,
        source,
        taskId: rawTaskNumberId,
        resultId: rawResultId,
        favoriteId: rawTaskId,
        title: item.title || item.prompt || item.script_text || (isDigitalHuman ? '数字人作品' : source === 'video' ? '视频作品' : '图片作品'),
        image,
        video,
        category,
        status,
        error: normalizeAssetError(item),
        timestamp,
        date: formatAssetDateGroup(timestamp),
        dateText: formatAssetDateText(timestamp),
        favorite: isFavorite(category, rawTaskId),
        badge: isDigitalHuman ? '数字人' : source === 'video' ? '视频' : '图片',
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
    const [images, videos, digitalHumans] = await Promise.all([
        fetchAssetList(getAigcImageResults, 'image'),
        fetchAssetList(getAigcVideoResults, 'video'),
        fetchAssetList(getAigcDigitalHumanResults, 'digital_human')
    ])
    assetItems.value = [...images, ...videos, ...digitalHumans].sort((a, b) => b.timestamp - a.timestamp)
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
}

const getAssetDetailCandidates = (payload: any) => {
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

const fetchAssetDetail = async (asset: AssetItem) => {
    const id = asset.taskId || asset.resultId
    if (!id) return null
    if (asset.source === 'image') return getAigcImageTask({ id })
    if (asset.source === 'video') return getAigcVideoTask({ id })
    if (asset.source === 'digital_human') return getAigcDigitalHumanTask({ id })
    return null
}

const updateAssetDownloadUrl = (asset: AssetItem, url: string, isVideoResource = false) => {
    if (!url) return asset
    const nextAsset = isVideoResource ? { ...asset, video: url } : { ...asset, image: url }
    assetItems.value = assetItems.value.map((item) => item.id === asset.id ? { ...item, ...nextAsset } : item)
    if (previewAsset.value?.id === asset.id) {
        previewAsset.value = { ...previewAsset.value, ...nextAsset }
    }
    return nextAsset
}

const resolveAssetDownloadUrlFromDetail = (asset: AssetItem, detail: any) => {
    const candidates = getAssetDetailCandidates(detail)
    if (asset.source === 'image') {
        return { url: candidates.map(getImageUrl).find(Boolean) || asset.image, isVideo: false }
    }
    const video = candidates.map(getVideoUrl).find(Boolean)
    if (video) return { url: video, isVideo: true }
    const image = candidates.map((item) => getCoverUrl(item) || getImageUrl(item)).find(Boolean)
    return { url: image || asset.video || asset.image, isVideo: Boolean(asset.video && !image) }
}

const getAssetDownloadName = (asset: AssetItem, url = '') => {
    const target = url || asset.video || asset.image
    const ext = getPcDownloadExtension(target, asset.source === 'image' ? 'png' : 'mp4')
    return `${asset.title || 'asset'}.${ext}`
}

const downloadAsset = async (asset: AssetItem) => {
    if (!ensurePcLogin()) return
    let targetAsset = asset
    let url = ''
    try {
        const detail = await fetchAssetDetail(asset)
        if (detail) {
            const target = resolveAssetDownloadUrlFromDetail(asset, detail)
            url = target.url
            targetAsset = updateAssetDownloadUrl(asset, url, target.isVideo)
        }
    } catch (error) {
        console.error('fetch asset detail for download failed', error)
    }
    url = url || targetAsset.video || targetAsset.image
    if (!downloadPcAsset(url, getAssetDownloadName(targetAsset, url))) {
        openPcAsset(url)
    }
}

const downloadPreviewAsset = () => {
    if (!previewAsset.value) return
    downloadAsset(previewAsset.value)
}

const downloadSelectedAssets = async () => {
    if (!selectedAssetIds.value.length) return
    if (!ensurePcLogin()) return

    for (const id of selectedAssetIds.value) {
        try {
            const asset = assetItems.value.find((item) => item.id === id)
            if (!asset) continue
            await downloadAsset(asset)
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
    padding-top: 40px;
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
    padding-bottom: 8px;
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
.assets-card:hover .assets-card__download,
.assets-card__favorite.is-active {
    opacity: 1;
}

.assets-card__download:hover,
.assets-card__favorite:hover,
.assets-card__favorite.is-active {
    border-color: rgba(255, 255, 255, 0.92);
    background: rgba(255, 255, 255, 0.92);
    transform: scale(1.04);
}

.assets-card__download:hover img,
.assets-card__favorite:hover img,
.assets-card__favorite.is-active img {
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
    gap: 10px;
    min-height: max(320px, 100%);
    border-radius: 24px;
    background: rgba(255, 255, 255, 0.03);
    color: rgba(255, 255, 255, 0.78);
    text-align: center;
}

.assets-empty strong {
    color: #fff;
    font-size: 18px;
    font-weight: 500;
}

.assets-empty span {
    font-size: 14px;
}

.assets-preview {
    position: fixed;
    inset: 0;
    z-index: 96;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px;
    background: rgba(0, 0, 0, 0.78);
    backdrop-filter: blur(18px);
}

.assets-preview__panel {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1.08fr) minmax(320px, 0.92fr);
    width: min(1120px, calc(100vw - 96px));
    max-height: calc(100vh - 96px);
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 24px;
    background: #101012;
    box-shadow: 0 30px 90px rgba(0, 0, 0, 0.56);
}

.assets-preview__close {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.14);
    color: #fff;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    backdrop-filter: blur(12px);
}

.assets-preview__media {
    position: relative;
    min-height: clamp(280px, 52vh, 640px);
    background: #070707;
}

.assets-preview__media video,
.assets-preview__media img {
    display: block;
    width: 100%;
    height: 100%;
    min-height: clamp(280px, 52vh, 640px);
    background: #070707;
    object-fit: contain;
}

.assets-preview__placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 24px;
    color: #fff;
    text-align: center;
}

.assets-preview__placeholder strong {
    font-size: 22px;
    font-weight: 600;
}

.assets-preview__placeholder span {
    max-width: 360px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    line-height: 1.7;
}

.assets-preview__floating-actions {
    position: absolute;
    top: 24px;
    right: 24px;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    gap: 12px;
}

.assets-preview__icon-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    border: 1px solid rgba(255, 255, 255, 0.26);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.58);
    backdrop-filter: blur(12px);
}

.assets-preview__icon-action:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.assets-preview__icon-action:hover,
.assets-preview__icon-action.is-active {
    border-color: #fff;
    background: #fff;
}

.assets-preview__icon-action.is-active {
    border-color: #ffd84d;
    background: #ffd84d;
}

.assets-preview__icon-action img {
    width: 22px;
    height: 22px;
    object-fit: contain;
    filter: drop-shadow(0 1px 4px rgba(0, 0, 0, 0.8));
}

.assets-preview__icon-action:hover img,
.assets-preview__icon-action.is-active img {
    filter: invert(1);
}

.assets-preview__content {
    display: flex;
    flex-direction: column;
    gap: 24px;
    min-width: 0;
    padding: 24px 28px 28px;
    overflow-y: auto;
}

.assets-preview__toolbar {
    display: flex;
    justify-content: flex-end;
    margin: -12px -8px 0 0;
}

.assets-preview__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    min-height: 36px;
    color: rgba(255, 255, 255, 0.68);
}

.assets-preview__meta strong {
    overflow: hidden;
    color: #fff;
    font-size: 15px;
    font-weight: 500;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.assets-preview__meta span {
    flex-shrink: 0;
    font-size: 13px;
}

.assets-preview__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.assets-preview__chips span {
    display: inline-flex;
    align-items: center;
    min-height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.07);
    color: rgba(255, 255, 255, 0.74);
    font-size: 13px;
}

.assets-preview__actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: auto;
    padding-top: 8px;
}

.assets-preview__download,
.assets-preview__favorite {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 0 18px;
    border: 0;
    border-radius: 999px;
    background: #fff;
    color: #050505;
    font-size: 14px;
    font-weight: 600;
}

.assets-preview__download:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.assets-preview__favorite {
    border: 1px solid rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.assets-preview__favorite:hover {
    border-color: #fff;
    background: #fff;
    color: #050505;
}

.assets-preview__favorite.is-active {
    border-color: #ffd84d;
    background: #ffd84d;
    color: #050505;
}

.assets-preview__download img,
.assets-preview__favorite img {
    width: 16px;
    height: 16px;
}

.assets-preview__download img,
.assets-preview__favorite.is-active img,
.assets-preview__favorite:hover img {
    filter: invert(1);
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
        height: auto;
        min-height: auto;
        padding-top: 0;
        overflow: visible;
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
        flex: none;
        min-height: auto;
        padding-right: 0;
        overflow: visible;
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

    .assets-preview {
        padding: 14px;
    }

    .assets-preview__panel {
        grid-template-columns: 1fr;
        width: calc(100vw - 28px);
        max-height: calc(100vh - 28px);
        overflow-y: auto;
    }

    .assets-preview__media,
    .assets-preview__media video,
    .assets-preview__media img {
        min-height: 300px;
    }

    .assets-preview__content {
        padding: 18px 20px 22px;
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

    .assets-preview__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .assets-preview__download,
    .assets-preview__favorite {
        width: 100%;
    }
}
</style>
