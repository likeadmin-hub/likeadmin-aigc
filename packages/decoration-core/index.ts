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

export interface DecorateWidgetSchema {
    id?: string
    name: string
    title: string
    content: Record<string, any>
    styles: Record<string, any>
    visibility?: Record<string, any>
}

export interface DecorateWidgetDefinition {
    name: string
    title: string
    category: string
    icon: string
    terminal: DecorateTerminal[]
    repeatable?: boolean
    options: () => DecorateWidgetSchema
}

export const numberValue = (value: any, fallback: number) => {
    const parsed = Number(String(value ?? '').replace('px', ''))
    return Number.isFinite(parsed) ? parsed : fallback
}

export const createWidgetId = (prefix = 'diy') =>
    `${prefix}_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`

export const cloneJson = <T>(value: T): T => JSON.parse(JSON.stringify(value ?? null))

export const getWidgetLayout = (widget: any, index = 0): DecorateWidgetLayout => {
    const styles = widget?.styles || {}
    const layout = styles.layout || {}
    const left = numberValue(styles.left, NaN)
    const top = numberValue(styles.top, NaN)
    const width = numberValue(styles.width, NaN)
    const height = numberValue(styles.height, NaN)
    return {
        mode: layout.mode || (styles.position === 'absolute' ? 'free' : 'flow'),
        x: Number.isFinite(numberValue(layout.x, NaN))
            ? numberValue(layout.x, 0)
            : Number.isFinite(left)
              ? left
              : 40 + index * 8,
        y: Number.isFinite(numberValue(layout.y, NaN))
            ? numberValue(layout.y, 0)
            : Number.isFinite(top)
              ? top
              : 40 + index * 24,
        w: Number.isFinite(numberValue(layout.w, NaN))
            ? numberValue(layout.w, 1120)
            : Number.isFinite(width)
              ? width
              : 1120,
        h: Number.isFinite(numberValue(layout.h, NaN))
            ? numberValue(layout.h, 240)
            : Number.isFinite(height)
              ? height
              : 240,
        z: numberValue(layout.z, index + 1),
        locked: !!layout.locked,
        hidden: !!layout.hidden,
        snap: numberValue(layout.snap, 8)
    }
}

export const withLayout = (
    widget: DecorateWidgetSchema,
    layout: Partial<DecorateWidgetLayout>,
    index = 0
): DecorateWidgetSchema => {
    const current = getWidgetLayout(widget, index)
    return {
        ...widget,
        id: widget.id || createWidgetId(widget.name),
        content: {
            enabled: 1,
            ...(widget.content || {})
        },
        styles: {
            ...(widget.styles || {}),
            layout: {
                ...current,
                ...layout,
                locked: layout.locked ?? current.locked,
                hidden: layout.hidden ?? current.hidden,
                snap: layout.snap ?? current.snap
            }
        }
    }
}

export const pcWidgetDefinitions: DecorateWidgetDefinition[] = [
    {
        name: 'pc-sidebar',
        title: 'PC侧栏',
        category: 'pc',
        icon: 'el-icon-Monitor',
        terminal: ['pc'],
        repeatable: false,
        options: () =>
            withLayout(
                {
                    title: 'PC侧栏',
                    name: 'pc-sidebar',
                    content: {
                        enabled: 1,
                        logo: 'LA',
                        items: [
                            { key: 'inspiration', title: '灵感', path: '/ai' },
                            { key: 'create', title: '创作', path: '/ai/create' },
                            { key: 'short-drama', title: '短剧', path: '' },
                            { key: 'avatar', title: '数字人', path: '/ai/avatar' },
                            { key: 'tools', title: '工具', path: '/ai/tools' },
                            { key: 'assets', title: '资产', path: '/ai/assets' }
                        ],
                        footer: [
                            { key: 'vip', title: '开通会员', path: '/ai/membership' },
                            { key: 'user', title: '个人中心', path: '/user/info' },
                            { key: 'api', title: 'API', path: '' },
                            { key: 'notice', title: '消息', path: '' },
                            { key: 'mobile', title: '手机', path: '' },
                            { key: 'language', title: '语言', path: '' }
                        ]
                    },
                    styles: {}
                },
                { mode: 'flow', x: 0, y: 0, w: 100, h: 900, z: 1, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-home-hero-grid',
        title: '首页功能入口',
        category: 'pc',
        icon: 'el-icon-House',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '首页功能入口',
                    name: 'pc-home-hero-grid',
                    content: {
                        enabled: 1,
                        banners: [
                            {
                                title: 'AI 创作介绍',
                                description: '模型、应用、资产与灵感统一入口',
                                image: '',
                                link: { path: '/ai/tools' }
                            },
                            {
                                title: 'AI 工具合集',
                                description: '热门 AI 能力一站式直达',
                                image: '',
                                link: { path: '/ai/create' }
                            },
                            {
                                title: '灵感案例广场',
                                description: '发现案例，一键做同款',
                                image: '',
                                link: { path: '/ai' }
                            }
                        ],
                        features: [
                            { title: 'AI TV', description: '全新功能，助力短剧创作。', image: '', link: { path: '/ai/create' } },
                            { title: '视频生成', description: '创意视频，一键生成', image: '', link: { path: '/ai/create?type=video' } },
                            { title: '图片生成', description: '智能绘制，即刻成图', image: '', link: { path: '/ai/create?type=image' } }
                        ]
                    },
                    styles: {}
                },
                { mode: 'flow', x: 130, y: 42, w: 1884, h: 240, z: 2, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-tool-carousel',
        title: '工具轮播',
        category: 'pc',
        icon: 'el-icon-MagicStick',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '工具轮播',
                    name: 'pc-tool-carousel',
                    content: {
                        enabled: 1,
                        title: '热门工具',
                        source_key: 'ai_tools',
                        source_params: { limit: 12 },
                        data: []
                    },
                    styles: {}
                },
                { mode: 'flow', x: 130, y: 315, w: 1884, h: 400, z: 3, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-case-feed',
        title: '案例流',
        category: 'pc',
        icon: 'el-icon-Picture',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '案例流',
                    name: 'pc-case-feed',
                    content: {
                        enabled: 1,
                        title: '案例展示',
                        source_key: 'image_cases',
                        source_params: { limit: 24 },
                        tabs: [
                            { key: 'all', name: '综合' },
                            { key: 'model', name: 'AI模型' },
                            { key: 'app', name: 'AI应用' },
                            { key: 'effect', name: '视频特效' },
                            { key: 'inspiration', name: '灵感' },
                            { key: 'workflow', name: '工作流' }
                        ]
                    },
                    styles: {}
                },
                { mode: 'flow', x: 130, y: 764, w: 1884, h: 520, z: 4, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-rich-media',
        title: '富媒体区块',
        category: 'media',
        icon: 'el-icon-PictureFilled',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '富媒体区块',
                    name: 'pc-rich-media',
                    content: {
                        enabled: 1,
                        title: '图文区块',
                        description: '配置图片、视频、文案和跳转链接',
                        image: '',
                        link: {}
                    },
                    styles: {}
                },
                { mode: 'flow', x: 130, y: 42, w: 760, h: 260, z: 5, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-text',
        title: '文本',
        category: 'basic',
        icon: 'el-icon-Document',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '文本',
                    name: 'pc-text',
                    content: { enabled: 1, text: '文本内容' },
                    styles: { color: '#ffffff', font_size: 24, font_weight: 600 }
                },
                { mode: 'flow', x: 130, y: 42, w: 600, h: 80, z: 6, locked: false, hidden: false }
            )
    },
    {
        name: 'pc-button',
        title: '按钮',
        category: 'basic',
        icon: 'el-icon-Mouse',
        terminal: ['pc'],
        options: () =>
            withLayout(
                {
                    title: '按钮',
                    name: 'pc-button',
                    content: { enabled: 1, text: '立即查看', link: { path: '/ai/tools' } },
                    styles: {}
                },
                { mode: 'flow', x: 130, y: 42, w: 180, h: 52, z: 7, locked: false, hidden: false }
            )
    }
]

export const pcWidgetRegistry = Object.fromEntries(pcWidgetDefinitions.map((item) => [item.name, item]))

export const createDefaultPcWidget = (name: string) => {
    const definition = pcWidgetRegistry[name]
    return definition ? cloneJson(definition.options()) : null
}

const legacyPcNames = ['pc-banner', 'pc-hero-entry', 'pc-tool-config']
const requiredPcHomeNames = ['pc-sidebar', 'pc-home-hero-grid', 'pc-tool-carousel', 'pc-case-feed']

const legacyBannerToHeroGrid = (widgets: DecorateWidgetSchema[]) => {
    const banner = widgets.find((item) => item.name === 'pc-banner')
    const hero = widgets.find((item) => item.name === 'pc-hero-entry')
    const base = createDefaultPcWidget('pc-home-hero-grid')!
    const bannerRows = (banner?.content?.data || []).filter((item: any) => item?.is_show !== '0')
    return {
        ...base,
        content: {
            ...base.content,
            banners: bannerRows.length
                ? bannerRows.map((item: any) => ({
                      title: item.title || item.name || 'AI 创作介绍',
                      description: item.description || item.desc || '模型、应用、资产与灵感统一入口',
                      image: item.image || item.cover || '',
                      link: item.link || { path: '/ai/tools' }
                  }))
                : base.content.banners,
            features: [
                {
                    title: hero?.content?.title || 'AI TV',
                    description: hero?.content?.description || '全新功能，助力短剧创作。',
                    image: '',
                    link: hero?.content?.primary_link || { path: '/ai/create' }
                },
                ...(base.content.features || []).slice(1)
            ]
        }
    }
}

const legacyToolConfigToCarousel = (widgets: DecorateWidgetSchema[]) => {
    const toolConfig = widgets.find((item) => item.name === 'pc-tool-config')
    if (!toolConfig) return null
    const base = createDefaultPcWidget('pc-tool-carousel')!
    const rows = Array.isArray(toolConfig.content?.data) ? toolConfig.content.data : []
    const data = rows
        .filter((item: any) => item?.enabled !== 0 && item?.enabled !== false)
        .map((item: any, index: number) => ({
            id: item.id || `legacy-tool-${index}`,
            title: item.title || item.name || `工具 ${index + 1}`,
            description: item.description || item.desc || item.badge || 'AI 工具',
            badge: item.badge || '',
            cover: item.cover || item.image || '',
            path: item.path || item.route || `/ai/tools/${item.id || `tool-card-${index + 1}`}`,
            virtual_use_count: item.virtual_use_count || item.virtualUseCount || ''
        }))
    return {
        ...base,
        content: {
            ...base.content,
            data,
            source_key: data.length ? '' : base.content.source_key,
            source_params: {
                ...(base.content.source_params || {}),
                limit: Math.max(data.length || 0, Number(base.content.source_params?.limit || 12))
            }
        }
    }
}

export const normalizePcDecorationWidgets = (value: any[]): DecorateWidgetSchema[] => {
    const input = Array.isArray(value) ? cloneJson(value) : []
    if (!input.length) {
        return requiredPcHomeNames
            .map((name) => createDefaultPcWidget(name))
            .filter(Boolean) as DecorateWidgetSchema[]
    }

    const nonLegacy = input.filter((item) => item?.name && !legacyPcNames.includes(item.name))
    const findModern = (name: string) => nonLegacy.find((item) => item?.name === name)
    const hasLegacyHome = input.some((item) => legacyPcNames.includes(item?.name))
    const migratedHero = legacyBannerToHeroGrid(input)
    const migratedTools = legacyToolConfigToCarousel(input)
    const modern = [
        findModern('pc-sidebar') || createDefaultPcWidget('pc-sidebar'),
        hasLegacyHome ? migratedHero : findModern('pc-home-hero-grid') || migratedHero,
        hasLegacyHome ? migratedTools || findModern('pc-tool-carousel') || createDefaultPcWidget('pc-tool-carousel') : findModern('pc-tool-carousel') || migratedTools || createDefaultPcWidget('pc-tool-carousel'),
        findModern('pc-case-feed') || createDefaultPcWidget('pc-case-feed'),
        ...nonLegacy.filter((item) => !requiredPcHomeNames.includes(item.name))
    ].filter(Boolean)

    return modern.map((item: any, index: number) => {
        const definition = pcWidgetRegistry[item.name]
        const defaults = definition?.options?.() || {}
        const merged = {
            ...defaults,
            ...item,
            title: item.title || defaults.title || item.name,
            content: {
                enabled: 1,
                ...(defaults.content || {}),
                ...(item.content || {})
            },
            styles: {
                ...(defaults.styles || {}),
                ...(item.styles || {})
            }
        }
        if (requiredPcHomeNames.includes(String(merged.name))) {
            delete merged.styles.position
            delete merged.styles.left
            delete merged.styles.top
            delete merged.styles.width
            delete merged.styles.height
            if (numberValue(merged.styles.opacity, 100) < 30) {
                delete merged.styles.opacity
            }
        }
        return withLayout(merged, getWidgetLayout(merged, index), index)
    })
}

export const normalizePcPageMeta = (value: any) => {
    const meta = Array.isArray(value) ? value : value ? [value] : []
    const current = meta[0] || {}
    const currentContent = current.content || {}
    const legacyLightBg = ['#f6f8ff', '#F6F8FF', '#eef1f6', '#EEF1F6'].includes(String(currentContent.bg_color || ''))
    return [
        {
            title: '页面配置',
            name: 'page-meta',
            content: {
                pc_width: 1440,
                pc_min_height: 1080,
                bg_type: '1',
                ...currentContent,
                render_mode: 'pc_diy_v2',
                layout_scope: 'full_page',
                bg_color: legacyLightBg ? '#050505' : currentContent.bg_color || '#050505'
            },
            styles: {
                ...(current.styles || {})
            }
        }
    ]
}

export const mobileWidgetDefinitions: DecorateWidgetDefinition[] = [
    { name: 'search', title: '搜索', category: 'basic', icon: 'el-icon-Search', terminal: ['mobile'], options: () => ({ name: 'search', title: '搜索', content: { enabled: 1 }, styles: {} }) },
    { name: 'banner', title: '轮播图', category: 'media', icon: 'el-icon-Picture', terminal: ['mobile'], options: () => ({ name: 'banner', title: '轮播图', content: { enabled: 1, data: [] }, styles: {} }) },
    { name: 'nav', title: '图文导航', category: 'nav', icon: 'el-icon-Grid', terminal: ['mobile'], options: () => ({ name: 'nav', title: '图文导航', content: { enabled: 1, data: [] }, styles: {} }) },
    { name: 'middle-banner', title: '中部广告', category: 'media', icon: 'el-icon-PictureFilled', terminal: ['mobile'], options: () => ({ name: 'middle-banner', title: '中部广告', content: { enabled: 1, image: '', link: {} }, styles: {} }) },
    { name: 'title-bar', title: '标题栏', category: 'basic', icon: 'el-icon-Document', terminal: ['mobile', 'pc'], options: () => ({ name: 'title-bar', title: '标题栏', content: { enabled: 1, title: '标题', sub_title: '', align: 'left' }, styles: {} }) },
    { name: 'notice', title: '公告', category: 'basic', icon: 'el-icon-Bell', terminal: ['mobile', 'pc'], options: () => ({ name: 'notice', title: '公告', content: { enabled: 1, text: '公告内容', link: {} }, styles: {} }) },
    { name: 'list-nav', title: '列表导航', category: 'nav', icon: 'el-icon-Menu', terminal: ['mobile', 'pc'], options: () => ({ name: 'list-nav', title: '列表导航', content: { enabled: 1, data: [] }, styles: {} }) },
    { name: 'image-hotspot', title: '图片热区', category: 'media', icon: 'el-icon-Crop', terminal: ['mobile', 'pc'], options: () => ({ name: 'image-hotspot', title: '图片热区', content: { enabled: 1, image: '', height: 180, areas: [] }, styles: {} }) },
    { name: 'divider', title: '分割线', category: 'basic', icon: 'el-icon-Minus', terminal: ['mobile', 'pc'], options: () => ({ name: 'divider', title: '分割线', content: { enabled: 1, style: 'solid' }, styles: {} }) },
    { name: 'user-info', title: '用户信息', category: 'user', icon: 'el-icon-User', terminal: ['mobile'], options: () => ({ name: 'user-info', title: '用户信息', content: { enabled: 1 }, styles: {} }) },
    { name: 'customer-service', title: '客服', category: 'user', icon: 'el-icon-Service', terminal: ['mobile'], options: () => ({ name: 'customer-service', title: '客服', content: { enabled: 1 }, styles: {} }) }
]

export const mobileWidgetRegistry = Object.fromEntries(mobileWidgetDefinitions.map((item) => [item.name, item]))
