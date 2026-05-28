import { cloneDeep } from 'lodash-es'
import {
    createDefaultPcWidget,
    getWidgetLayout,
    normalizePcDecorationWidgets,
    normalizePcPageMeta as normalizeSharedPcPageMeta
} from '@decoration-core'

export type DecorateTerminal = 'mobile' | 'pc'

export interface DecorateWidgetLayout {
    mode: 'flow' | 'free'
    x: number
    y: number
    w: number
    h: number
    z: number
    locked: boolean
    hidden: boolean
    snap: number
}

export const getPcWidgetLayout = (widget: any, index = 0): DecorateWidgetLayout =>
    getWidgetLayout(widget, index)

export const normalizePcWidget = (widget: any, _index = 0) => {
    const current = cloneDeep(widget || {})
    const defaults = createDefaultPcWidget(current.name) || {}
    const merged = {
        ...defaults,
        ...current,
        title: current.title || defaults.title || current.name,
        content: {
            enabled: 1,
            ...(defaults.content || {}),
            ...(current.content || {})
        },
        styles: {
            ...(defaults.styles || {}),
            ...(current.styles || {})
        }
    }
    return {
        ...merged,
        styles: {
            ...(merged.styles || {}),
            layout: getWidgetLayout(merged, _index)
        }
    }
}

export const normalizePcPageData = (data: any[]) =>
    normalizePcDecorationWidgets(Array.isArray(data) ? data : [])

export const normalizePcHomePageData = (data: any[]) => {
    return normalizePcDecorationWidgets(Array.isArray(data) ? data : [])
}

export const getDefaultPcPageMeta = () =>
    cloneDeep(
        {
            title: '页面配置',
            name: 'page-meta',
            content: { render_mode: 'pc_diy_v2', layout_scope: 'full_page', bg_color: '#050505' },
            styles: {}
        }
    )

export const normalizePcPageMeta = (value: any) => {
    return normalizeSharedPcPageMeta(value)
}

export const safeParseJson = (value: any, defaults: any) => {
    if (Array.isArray(value) || (value && typeof value === 'object')) return value
    try {
        const data = JSON.parse(value || '')
        return data ?? defaults
    } catch (error) {
        return defaults
    }
}

const inferPcOrigin = () => {
    const configured = String(import.meta.env.VITE_APP_PC_URL || '').replace(/\/$/, '')
    if (configured) return configured
    const origin = window.location.origin
    if (import.meta.env.DEV && /:(5173|5174|5175|5176)$/.test(origin)) {
        return origin.replace(/:(5173|5174|5175|5176)$/, ':3000')
    }
    return origin.replace(/\/admin\/?$/, '')
}

export const getPcPreviewUrl = (page: any, templateId: number | string) => {
    const origin = inferPcOrigin()
    const isHome = page?.page_code === 'pc_home' || page?.page_type === 'pc_home'
    const path = isHome ? '/ai' : `/page/${page?.page_code || ''}`
    const query = new URLSearchParams({
        preview: '1',
        template_id: String(templateId || page?.template_id || 0),
        page_id: String(page?.id || 0)
    })
    return `${origin}${path}?${query.toString()}`
}
