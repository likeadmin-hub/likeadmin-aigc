import { computed } from 'vue'
import { getIndex } from '@/api/shop'
import { useAppStore } from '@/stores/app'
import { normalizeFileUrl } from '@/utils/file-url'
import {
    applyFeaturedToolDisplayOverrides,
    applyToolDisplayOverrides,
    featuredTools,
    toolCards
} from '~/composables/use-ai-tools'
import type { ToolDisplayOverride } from '~/composables/use-ai-tools'
import { normalizePcDecorationWidgets, normalizePcPageMeta } from '@decoration-core'

export interface PcHomeBannerItem {
    id: string
    title: string
    description: string
    route?: string
    images: string[]
}

const parseJsonArray = (value: unknown) => {
    if (Array.isArray(value)) return value
    if (typeof value !== 'string' || !value.trim()) return []
    try {
        const parsed = JSON.parse(value)
        return Array.isArray(parsed) ? parsed : []
    } catch (error) {
        return []
    }
}

const parseJsonObject = (value: unknown) => {
    if (value && typeof value === 'object' && !Array.isArray(value)) return value as Record<string, any>
    if (typeof value !== 'string' || !value.trim()) return {}
    try {
        const parsed = JSON.parse(value)
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
    } catch (error) {
        return {}
    }
}

const resolveMediaUrl = (url: unknown, appStore: ReturnType<typeof useAppStore>) => {
    const normalized = normalizeFileUrl(typeof url === 'string' ? url : '')
    if (!normalized) return ''
    if (/^(https?:\/\/|data:|blob:)/i.test(normalized)) return normalized
    if (normalized.startsWith('/')) {
        const domain = String(appStore.config?.domain || '').replace(/\/$/, '')
        return domain ? `${domain}${normalized}` : normalized
    }
    return normalized
}

const getWidget = (pageData: any[], name: string) => pageData.find((item) => item?.name === name && item?.content?.enabled !== 0)
const legacyHomeWidgets = ['pc-banner', 'pc-tool-config']

export const useAiPcHomeDecorate = () => {
    const appStore = useAppStore()
    const route = useRoute()
    const pcDecorateEnabled = computed(() => route.query.pc_diy === '1')
    const homeBanners = useState<PcHomeBannerItem[]>('ai-pc-home-banners', () => [])
    const toolOverrides = useState<Record<string, ToolDisplayOverride>>('ai-pc-tool-overrides', () => ({}))
    const decoratePageData = useState<any[]>('ai-pc-home-page-data', () => [])
    const decoratePageMeta = useState<any[]>('ai-pc-home-page-meta', () => [])
    const decorateResolvedSources = useState<Record<string, any>>('ai-pc-home-resolved-sources', () => ({}))
    const loaded = useState<boolean>('ai-pc-home-decorate-loaded', () => false)
    const loading = useState<boolean>('ai-pc-home-decorate-loading', () => false)

    const applyPcHomePageData = (pageData: any[], pageMeta: any[] = decoratePageMeta.value, resolvedSources: Record<string, any> = decorateResolvedSources.value) => {
        decoratePageData.value = normalizePcDecorationWidgets(pageData)
        decoratePageMeta.value = normalizePcPageMeta(pageMeta)
        decorateResolvedSources.value = resolvedSources || {}

        const bannerWidget = getWidget(decoratePageData.value, 'pc-banner') || getWidget(decoratePageData.value, 'pc-home-hero-grid')
        const bannerRows = (bannerWidget?.content?.data || [])
            .concat(bannerWidget?.content?.banners || [])
            .filter((item: any) => item?.is_show !== '0')
            .map((item: any, index: number) => {
                const image = resolveMediaUrl(item.image, appStore)
                const bg = resolveMediaUrl(item.bg, appStore)
                return {
                    id: `decorate-banner-${index}`,
                    title: item.title || item.name || (index === 0 ? 'AI 创作介绍' : 'AI 创作灵感'),
                    description: item.description || item.desc || '模型、应用、资产与灵感统一入口',
                    route:
                        item.link?.path && !String(item.link.path).startsWith('/pages/')
                            ? item.link.path
                            : '/ai/tools',
                    images: [image, bg].filter(Boolean)
                }
            })
            .filter((item: PcHomeBannerItem) => item.images.length)

        const toolWidget = getWidget(pageData, 'pc-tool-config')
        const nextOverrides: Record<string, ToolDisplayOverride> = {}
        ;(toolWidget?.content?.data || []).forEach((item: any) => {
            const id = String(item?.id || '').trim()
            if (!id) return
            nextOverrides[id] = {
                id,
                title: String(item.title || '').trim(),
                description: String(item.description || '').trim(),
                badge: String(item.badge || '').trim(),
                cover: resolveMediaUrl(item.cover || item.image, appStore),
                virtualUseCount: String(item.virtual_use_count || item.virtualUseCount || '').trim(),
                enabled: item.enabled === undefined ? true : item.enabled !== 0 && item.enabled !== false
            }
        })

        homeBanners.value = bannerRows
        toolOverrides.value = nextOverrides
    }

    const loadPcHomeDecorate = async (force = false) => {
        if (!pcDecorateEnabled.value) {
            decoratePageData.value = []
            decoratePageMeta.value = []
            decorateResolvedSources.value = {}
            homeBanners.value = []
            toolOverrides.value = {}
            loaded.value = true
            loading.value = false
            return
        }
        if (loading.value || (loaded.value && !force)) return
        loading.value = true
        try {
            const data: any = await getIndex({
                preview: route.query.preview,
                template_id: route.query.template_id,
                page_id: route.query.page_id
            })
            const pageData = parseJsonArray(data?.page?.data)
            const pageMeta = parseJsonArray(data?.page?.meta)
            applyPcHomePageData(pageData, pageMeta, parseJsonObject(data?.page?.resolved_sources))
            loaded.value = true
        } catch (error) {
            loaded.value = true
        } finally {
            loading.value = false
        }
    }

    const displayToolCards = computed(() => applyToolDisplayOverrides(toolCards, toolOverrides.value))
    const displayFeaturedTools = computed(() => applyFeaturedToolDisplayOverrides(featuredTools, displayToolCards.value))
    const hasCustomHomeDecorate = computed(() => {
        const metaContent = decoratePageMeta.value?.[0]?.content || {}
        if (metaContent.render_mode === 'diy') return decoratePageData.value.length > 0
        return decoratePageData.value.some((item: any) => {
            const name = String(item?.name || '')
            const hidden = item?.content?.enabled === 0 || item?.styles?.layout?.hidden
            return name && !hidden && !legacyHomeWidgets.includes(name)
        })
    })

    return {
        homeBanners,
        toolOverrides,
        decoratePageData,
        decoratePageMeta,
        decorateResolvedSources,
        hasCustomHomeDecorate,
        displayToolCards,
        displayFeaturedTools,
        loadPcHomeDecorate,
        applyPcHomePageData
    }
}
