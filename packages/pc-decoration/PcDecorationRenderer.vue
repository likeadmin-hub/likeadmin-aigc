<template>
    <div class="pc-decoration" :class="{ 'is-edit': mode === 'edit' }" :style="pageStyle">
        <component-shell
            v-if="sidebarWidget"
            :widget="sidebarWidget"
            :selected="isSelected(sidebarWidget)"
            :mode="mode"
            class="pc-decoration__sidebar-shell"
            @select="selectWidget(sidebarWidget)"
            @move="emitMove(sidebarWidget, $event)"
            @copy="emitCopy(sidebarWidget)"
            @delete="emitDelete(sidebarWidget)"
            @toggle-hidden="emitToggleHidden(sidebarWidget)"
            @toggle-locked="emitToggleLocked(sidebarWidget)"
        >
            <aside class="pc-sidebar">
                <button class="pc-sidebar__logo" type="button" @click="handleAction('/')">
                    {{ sidebarWidget.content.logo || 'LA' }}
                </button>
                <nav class="pc-sidebar__nav">
                    <button
                        v-for="item in sidebarWidget.content.items || []"
                        :key="item.key || item.title"
                        class="pc-sidebar__item"
                        :class="{ 'is-active': item.key === activeSidebar }"
                        type="button"
                        @click="handleAction(item.path, item)"
                    >
                        <span v-html="sidebarIcon(item.key)"></span>
                        <em>{{ item.title }}</em>
                    </button>
                </nav>
                <div class="pc-sidebar__footer">
                    <button
                        v-for="item in sidebarWidget.content.footer || []"
                        :key="item.key || item.title"
                        class="pc-sidebar__footer-item"
                        :class="{ 'is-vip': item.key === 'vip' }"
                        type="button"
                        @click="handleAction(item.path, item)"
                    >
                        <span v-html="sidebarIcon(item.key)"></span>
                        <em v-if="item.key === 'vip'">{{ item.title }}</em>
                    </button>
                </div>
            </aside>
        </component-shell>

        <main class="pc-decoration__main" :class="{ 'has-sidebar': Boolean(sidebarWidget) }">
            <section v-if="mode === 'edit' && !hasRenderableMain" class="pc-decoration-empty">
                <strong>暂无 PC 内容组件</strong>
                <span>请从左侧添加首页功能入口、工具轮播或案例流。</span>
            </section>
            <component-shell
                v-for="widget in renderWidgets"
                :key="widget.id || widget.name"
                :widget="widget"
                :selected="isSelected(widget)"
                :mode="mode"
                @select="selectWidget(widget)"
                @move="emitMove(widget, $event)"
                @copy="emitCopy(widget)"
                @delete="emitDelete(widget)"
                @toggle-hidden="emitToggleHidden(widget)"
                @toggle-locked="emitToggleLocked(widget)"
            >
                <section v-if="widget.name === 'pc-home-hero-grid'" class="pc-hero-grid">
                    <button class="pc-hero-grid__banner" type="button" @click="handleAction(activeBanner(widget).link?.path, activeBanner(widget))">
                        <span class="pc-hero-grid__copy">
                            <strong>{{ activeBanner(widget).title }}</strong>
                            <small>{{ activeBanner(widget).description }}</small>
                        </span>
                        <span class="pc-hero-grid__backdrop" :style="mediaBackground(activeBanner(widget).image)"></span>
                        <span class="pc-hero-grid__device"></span>
                        <span class="pc-hero-grid__dots">
                            <i
                                v-for="(banner, index) in heroBanners(widget)"
                                :key="`${banner.title}-${index}`"
                                :class="{ 'is-active': index === activeHeroIndex % heroBanners(widget).length }"
                            ></i>
                        </span>
                    </button>
                    <button class="pc-hero-grid__tv" type="button" @click="handleAction(heroFeatures(widget)[0]?.link?.path, heroFeatures(widget)[0])">
                        <span>
                            <strong>{{ heroFeatures(widget)[0]?.title || 'AI TV' }}</strong>
                            <small>{{ heroFeatures(widget)[0]?.description || '全新功能，助力短剧创作。' }}</small>
                        </span>
                        <i>→</i>
                        <span class="pc-hero-grid__media-stack" :style="mediaBackground(heroFeatures(widget)[0]?.image)"></span>
                    </button>
                    <div class="pc-hero-grid__quick">
                        <button
                            v-for="entry in heroFeatures(widget).slice(1, 3)"
                            :key="entry.title"
                            type="button"
                            @click="handleAction(entry.link?.path, entry)"
                        >
                            <span>
                                <strong>{{ entry.title }}</strong>
                                <small>{{ entry.description }}</small>
                            </span>
                            <span class="pc-hero-grid__quick-media" :style="mediaBackground(entry.image)"></span>
                            <i>→</i>
                        </button>
                    </div>
                </section>

                <section v-else-if="widget.name === 'pc-tool-carousel'" class="pc-tool-carousel">
                    <button class="pc-carousel-arrow pc-carousel-arrow--left" type="button">‹</button>
                    <div class="pc-tool-strip">
                        <article
                            v-for="(item, index) in toolItems(widget)"
                            :key="item.id || item.title || index"
                            class="pc-tool-card"
                            @click="handleAction(item.path || item.appPath || item.link?.path, item)"
                        >
                            <span v-if="item.badge || index < 2" class="pc-tool-card__badge">{{ item.badge || (index === 0 ? '新上' : '热门') }}</span>
                            <span class="pc-tool-card__image" :style="mediaBackground(item.cover || item.image)"></span>
                            <button type="button">↗</button>
                            <span class="pc-tool-card__body">
                                <strong>{{ item.title || item.name || `工具 ${index + 1}` }}</strong>
                                <small>{{ item.description || item.desc || item.badge || 'AI 工具' }}</small>
                                <em>▷ {{ item.virtualUseCount || item.virtual_use_count || item.count || `${390 + index * 217}` }}</em>
                            </span>
                        </article>
                    </div>
                    <button class="pc-carousel-arrow pc-carousel-arrow--right" type="button">›</button>
                </section>

                <section v-else-if="widget.name === 'pc-case-feed'" class="pc-case-feed">
                    <div class="pc-case-feed__toolbar">
                        <div class="pc-case-feed__tabs">
                            <button
                                v-for="(tab, index) in widget.content.tabs || []"
                                :key="tab.key || tab.name"
                                :class="{ 'is-active': index === 0 }"
                                type="button"
                            >
                                {{ tab.name }}
                            </button>
                        </div>
                        <label class="pc-case-feed__search">
                            <span></span>
                            <input type="text" placeholder="搜索作品" :disabled="mode === 'edit'" />
                        </label>
                    </div>
                    <div class="pc-case-grid">
                        <article
                            v-for="(item, index) in caseItems(widget)"
                            :key="item.id || item.title || index"
                            class="pc-case-card"
                            @click="handleAction(item.path || item.link?.path, item)"
                        >
                            <span class="pc-case-card__image" :style="mediaBackground(item.cover || item.image || item.video_cover || item.url)"></span>
                            <span class="pc-case-card__mask">
                                <strong>{{ item.title || item.name || '创作案例' }}</strong>
                                <button type="button" @click.stop="handleSameStyle(item)">做同款</button>
                            </span>
                        </article>
                    </div>
                </section>

                <section v-else-if="widget.name === 'pc-rich-media'" class="pc-rich-media" @click="handleAction(widget.content.link?.path, widget.content)">
                    <span class="pc-rich-media__image" :style="mediaBackground(widget.content.image)"></span>
                    <span>
                        <strong>{{ widget.content.title }}</strong>
                        <small>{{ widget.content.description }}</small>
                    </span>
                </section>

                <section v-else-if="widget.name === 'pc-text'" class="pc-text" :style="textStyle(widget)">
                    {{ widget.content.text }}
                </section>

                <button v-else-if="widget.name === 'pc-button'" class="pc-button" type="button" @click="handleAction(widget.content.link?.path, widget.content)">
                    {{ widget.content.text || '立即查看' }}
                </button>

                <section v-else class="pc-unknown-widget">
                    <strong>{{ widget.title || widget.name }}</strong>
                    <span>共享组件暂未实现渲染</span>
                </section>
            </component-shell>
        </main>
    </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, onBeforeUnmount, onMounted, PropType, ref } from 'vue'
import { normalizePcDecorationWidgets, numberValue } from '../decoration-core/index'

const props = defineProps({
    widgets: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    pageMeta: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    resolvedSources: {
        type: Object as PropType<Record<string, any>>,
        default: () => ({})
    },
    mode: {
        type: String as PropType<'view' | 'edit'>,
        default: 'view'
    },
    selectedId: {
        type: String,
        default: ''
    },
    activeSidebar: {
        type: String,
        default: 'inspiration'
    },
    fallbackTools: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    fallbackCases: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    adapters: {
        type: Object as PropType<{
            resolveImage?: (value: string) => string
            navigate?: (path: string, payload?: any) => void
            sameStyle?: (payload: any) => void
        }>,
        default: () => ({})
    }
})

const emit = defineEmits<{
    (event: 'selectWidget', widget: any): void
    (event: 'moveWidget', payload: { widget: any; direction: 'up' | 'down' }): void
    (event: 'copyWidget', widget: any): void
    (event: 'deleteWidget', widget: any): void
    (event: 'toggleHidden', widget: any): void
    (event: 'toggleLocked', widget: any): void
}>()

const activeHeroIndex = ref(0)
let heroTimer: ReturnType<typeof setInterval> | null = null

const ComponentShell = defineComponent({
    props: {
        widget: { type: Object, required: true },
        selected: { type: Boolean, default: false },
        mode: { type: String, default: 'view' }
    },
    emits: ['select', 'move', 'copy', 'delete', 'toggleHidden', 'toggleLocked'],
    setup(shellProps, { slots, emit: shellEmit }) {
        const shellStyle = () => {
            const styles = shellProps.widget?.styles || {}
            const layout = styles.layout || {}
            const rawOpacity = styles.opacity ? numberValue(styles.opacity, 100) / 100 : undefined
            const next: Record<string, string | number | undefined> = {
                marginTop: styles.margin_top !== undefined ? `${numberValue(styles.margin_top, 0)}px` : undefined,
                marginBottom: styles.margin_bottom !== undefined ? `${numberValue(styles.margin_bottom, 0)}px` : undefined,
                padding: styles.padding !== undefined ? `${numberValue(styles.padding, 0)}px` : undefined,
                background: styles.background || undefined,
                borderRadius:
                    styles.border_radius !== undefined ? `${numberValue(styles.border_radius, 0)}px` : undefined,
                border:
                    styles.border_width !== undefined
                        ? `${numberValue(styles.border_width, 0)}px solid ${styles.border_color || 'rgba(255,255,255,.14)'}`
                        : undefined,
                boxShadow: styles.shadow || undefined,
                opacity: layout.hidden
                    ? 0.42
                    : shellProps.mode === 'edit' && rawOpacity !== undefined
                      ? Math.max(0.88, rawOpacity)
                      : rawOpacity,
                zIndex: layout.z || undefined
            }
            if (layout.mode === 'free') {
                next.position = 'absolute'
                next.left = `${numberValue(layout.x, 0)}px`
                next.top = `${numberValue(layout.y, 0)}px`
                next.width = `${numberValue(layout.w, 320)}px`
                next.minHeight = `${numberValue(layout.h, 120)}px`
            }
            return next
        }
        return () =>
            h(
                'div',
                {
                    class: [
                        'pc-decoration-widget',
                        `is-${shellProps.widget?.name}`,
                        {
                            'is-selected': shellProps.selected,
                            'is-hidden': shellProps.widget?.styles?.layout?.hidden,
                            'is-locked': shellProps.widget?.styles?.layout?.locked
                        }
                    ],
                    'data-widget-title': shellProps.widget?.title || shellProps.widget?.name || '组件',
                    style: shellStyle(),
                    onClick: (event: MouseEvent) => {
                        if (shellProps.mode === 'edit') {
                            event.stopPropagation()
                            shellEmit('select')
                        }
                    }
                },
                [
                    slots.default?.(),
                    shellProps.mode === 'edit' && shellProps.widget?.styles?.layout?.hidden
                        ? h('div', { class: 'pc-decoration-widget__hidden-label' }, '已隐藏')
                        : null,
                    shellProps.mode === 'edit'
                        ? h('div', { class: 'pc-decoration-widget__tools' }, [
                              h('button', { type: 'button', title: '上移', 'aria-label': '上移', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('move', 'up') } }, '↑'),
                              h('button', { type: 'button', title: '下移', 'aria-label': '下移', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('move', 'down') } }, '↓'),
                              h('button', { type: 'button', title: shellProps.widget?.styles?.layout?.hidden ? '显示' : '隐藏', 'aria-label': shellProps.widget?.styles?.layout?.hidden ? '显示' : '隐藏', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('toggleHidden') } }, shellProps.widget?.styles?.layout?.hidden ? '◐' : '◌'),
                              h('button', { type: 'button', title: shellProps.widget?.styles?.layout?.locked ? '解锁' : '锁定', 'aria-label': shellProps.widget?.styles?.layout?.locked ? '解锁' : '锁定', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('toggleLocked') } }, shellProps.widget?.styles?.layout?.locked ? '🔓' : '🔒'),
                              h('button', { type: 'button', title: '复制', 'aria-label': '复制', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('copy') } }, '⧉'),
                              h('button', { type: 'button', title: '删除', 'aria-label': '删除', class: 'danger', onClick: (event: MouseEvent) => { event.stopPropagation(); shellEmit('delete') } }, '×')
                          ])
                        : null
                ]
            )
    }
})

const normalizedWidgets = computed(() => normalizePcDecorationWidgets(props.widgets))
const visibleWidgets = computed(() =>
    normalizedWidgets.value.filter((item: any) => {
        if (['pc-banner', 'pc-tool-config', 'pc-hero-entry'].includes(item?.name)) return false
        if (item?.content?.enabled === 0) return false
        return props.mode === 'edit' ? true : !item?.styles?.layout?.hidden
    })
)
const sidebarWidget = computed(() => visibleWidgets.value.find((item: any) => item.name === 'pc-sidebar'))
const renderWidgets = computed(() => visibleWidgets.value.filter((item: any) => item.name !== 'pc-sidebar'))
const hasRenderableMain = computed(() => renderWidgets.value.length > 0)
const pageContent = computed(() => props.pageMeta?.[0]?.content || {})
const pageStyle = computed(() => {
    const bgImage = resolveImage(pageContent.value.bg_image)
    const bgColor = pageContent.value.bg_color || '#050505'
    return {
        minHeight: `${numberValue(pageContent.value.pc_min_height, 1080)}px`,
        background: bgImage
            ? `linear-gradient(180deg, rgba(5,5,5,.42), rgba(5,5,5,.72)), url(${bgImage}) center top / cover no-repeat`
            : `radial-gradient(circle at 46% 22%, rgba(72, 101, 255, 0.18), transparent 28%), radial-gradient(circle at 78% 8%, rgba(255, 92, 168, 0.14), transparent 24%), linear-gradient(180deg, ${bgColor} 0%, #06070a 48%, #050505 100%)`
    }
})

const isSelected = (widget: any) => Boolean(props.selectedId && props.selectedId === String(widget.id || widget.name))
const selectWidget = (widget: any) => emit('selectWidget', widget)
const emitMove = (widget: any, direction: 'up' | 'down') => emit('moveWidget', { widget, direction })
const emitCopy = (widget: any) => emit('copyWidget', widget)
const emitDelete = (widget: any) => emit('deleteWidget', widget)
const emitToggleHidden = (widget: any) => emit('toggleHidden', widget)
const emitToggleLocked = (widget: any) => emit('toggleLocked', widget)

const resolveImage = (value: any) => {
    const url = String(value || '')
    if (!url) return ''
    if (/^(https?:\/\/|data:|blob:)/i.test(url)) return url
    return props.adapters.resolveImage?.(url) || url
}
const mediaBackground = (value: any) => {
    const url = resolveImage(value)
    return url
        ? { backgroundImage: `url(${url})` }
        : {}
}
const handleAction = (path?: string, payload?: any) => {
    if (props.mode === 'edit') return
    if (path) props.adapters.navigate?.(path, payload)
}
const handleSameStyle = (payload: any) => {
    if (props.mode === 'edit') return
    props.adapters.sameStyle?.(payload)
}
const sourceItems = (widget: any) => {
    const key = widget?.content?.source_key || widget?.content?.source?.key || ''
    const row = props.resolvedSources?.[key]
    if (Array.isArray(row?.items)) return row.items
    if (Array.isArray(row)) return row
    return Array.isArray(widget?.content?.data) ? widget.content.data : []
}
const fallbackToolItems = () =>
    props.fallbackTools.length
        ? props.fallbackTools
        : Array.from({ length: 6 }).map((_, index) => ({
              id: `fallback-tool-${index}`,
              title: ['全驱数字人', '无限画布', 'AIGC对话', '通用工具', '老照片修复', '局部重绘'][index] || `工具 ${index + 1}`,
              description: ['数字人新能力', '画布编排', '大模型对话', '工具模板', '照片修复', '局部编辑'][index] || 'AI 工具',
              count: index === 1 ? '2.3万' : `${390 + index * 217}`
          }))
const toolItems = (widget: any) => {
    const list = sourceItems(widget)
    return (list.length ? list : fallbackToolItems()).slice(0, Number(widget.content?.source_params?.limit || 12))
}
const caseItems = (widget: any) => {
    const list = sourceItems(widget)
    const fallback = props.fallbackCases.length ? props.fallbackCases : fallbackToolItems()
    return (list.length ? list : fallback).slice(0, Number(widget.content?.source_params?.limit || 24))
}
const heroBanners = (widget: any) =>
    (widget.content?.banners || []).length
        ? widget.content.banners
        : [{ title: 'AI 创作介绍', description: '模型、应用、资产与灵感统一入口', image: '', link: { path: '/ai/tools' } }]
const activeBanner = (widget: any) => {
    const rows = heroBanners(widget)
    return rows[activeHeroIndex.value % rows.length] || rows[0]
}
const heroFeatures = (widget: any) =>
    (widget.content?.features || []).length
        ? widget.content.features
        : [
              { title: 'AI TV', description: '全新功能，助力短剧创作。', image: '', link: { path: '/ai/create' } },
              { title: '视频生成', description: '创意视频，一键生成', image: '', link: { path: '/ai/create?type=video' } },
              { title: '图片生成', description: '智能绘制，即刻成图', image: '', link: { path: '/ai/create?type=image' } }
          ]
const sidebarIcon = (key: string) =>
    ({
        inspiration: '<svg viewBox="0 0 24 24"><path d="M12 2l1.9 6.2L20 10l-6.1 1.8L12 18l-1.9-6.2L4 10l6.1-1.8L12 2z"/></svg>',
        create: '<svg viewBox="0 0 24 24"><path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7V4z"/></svg>',
        'short-drama': '<svg viewBox="0 0 24 24"><path d="M4 5h16v14H4V5zm3 2H6v2h1V7zm0 4H6v2h1v-2zm0 4H6v2h1v-2zm11-8h-1v2h1V7zm0 4h-1v2h1v-2zm0 4h-1v2h1v-2zM9 7v10h6V7H9z"/></svg>',
        avatar: '<svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 110 18 9 9 0 010-18zm0 2a7 7 0 100 14 7 7 0 000-14zm0 3a3 3 0 110 6 3 3 0 010-6zm0 2a1 1 0 100 2 1 1 0 000-2z"/></svg>',
        tools: '<svg viewBox="0 0 24 24"><path d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 0h6v6h-6v-6z"/></svg>',
        assets: '<svg viewBox="0 0 24 24"><path d="M4 7h6l2 2h8v9H4V7zm2 2v7h12v-5h-6.8l-2-2H6z"/></svg>',
        vip: '<svg viewBox="0 0 24 24"><path d="M12 3l8 7-8 11-8-11 8-7zm0 3.1L7 10l5 6.9 5-6.9-5-3.9z"/></svg>',
        user: '<svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 110-8 4 4 0 010 8zm0 2c4.4 0 8 2.2 8 5v1H4v-1c0-2.8 3.6-5 8-5z"/></svg>',
        api: '<svg viewBox="0 0 24 24"><path d="M8.5 8a4 4 0 013.1 1.5l1.1 1.3 1.1-1.3A4 4 0 1120 14.6l-1.4-1.4A2 2 0 0015.4 11l-1.4 1.7 1.4 1.7a2 2 0 003.2-.2l1.4 1.4a4 4 0 01-6.2.9l-1.1-1.3-1.1 1.3A4 4 0 115.4 10l1.4 1.4A2 2 0 008.6 16c.6 0 1.2-.3 1.6-.8l1.2-1.5-1.4-1.7A2 2 0 006.8 12L5.4 10A4 4 0 018.5 8z"/></svg>',
        notice: '<svg viewBox="0 0 24 24"><path d="M11 5h2v9h-2V5zm0 11h2v3h-2v-3z"/></svg>',
        mobile: '<svg viewBox="0 0 24 24"><path d="M8 2h8a2 2 0 012 2v16a2 2 0 01-2 2H8a2 2 0 01-2-2V4a2 2 0 012-2zm0 3v14h8V5H8zm3 15h2v1h-2v-1z"/></svg>',
        language: '<svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 100 18 9 9 0 000-18zm5.7 8h-3.1a14 14 0 00-1-5.1A7 7 0 0117.7 11zM12 5.2c.5.8 1.1 2.7 1.2 5.8h-2.4c.1-3.1.7-5 1.2-5.8zM6.3 13h3.1a14 14 0 001 5.1A7 7 0 016.3 13zm3.1-2H6.3a7 7 0 014.1-5.1 14 14 0 00-1 5.1zm1.4 2h2.4c-.1 3.1-.7 5-1.2 5.8-.5-.8-1.1-2.7-1.2-5.8zm2.8 5.1a14 14 0 001-5.1h3.1a7 7 0 01-4.1 5.1z"/></svg>'
    })[key] || '<svg viewBox="0 0 24 24"><path d="M4 4h7v7H4V4zm9 0h7v7h-7V4zM4 13h7v7H4v-7zm9 0h7v7h-7v-7z"/></svg>'
const textStyle = (widget: any) => ({
    color: widget.styles?.color || '#fff',
    fontSize: `${numberValue(widget.styles?.font_size, 24)}px`,
    fontWeight: numberValue(widget.styles?.font_weight, 600)
})

onMounted(() => {
    heroTimer = setInterval(() => {
        const maxLength = Math.max(
            1,
            ...normalizedWidgets.value
                .filter((item: any) => item.name === 'pc-home-hero-grid')
                .map((item: any) => heroBanners(item).length)
        )
        if (maxLength > 1) {
            activeHeroIndex.value = (activeHeroIndex.value + 1) % maxLength
        }
    }, 3500)
})

onBeforeUnmount(() => {
    if (heroTimer) clearInterval(heroTimer)
    heroTimer = null
})
</script>

<style scoped lang="scss">
.pc-decoration {
    position: relative;
    width: 100%;
    min-width: 810px;
    overflow: hidden;
    color: #fff;
    background:
        radial-gradient(circle at 48% 34%, rgba(255, 255, 255, 0.04), transparent 22%),
        linear-gradient(180deg, #050505 0%, #06070a 44%, #050505 100%);
}
.pc-decoration__main {
    position: relative;
    min-height: inherit;
    padding: 30px 24px 100px;
    box-sizing: border-box;
}
.pc-decoration__main.has-sidebar {
    margin-left: 0;
    padding-left: 96px;
}
.pc-decoration.is-edit .pc-decoration__main {
    padding-top: 30px;
}
.pc-decoration-empty {
    min-height: 360px;
    display: grid;
    place-items: center;
    gap: 8px;
    border: 1px dashed rgba(255, 255, 255, 0.16);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.72);
    strong,
    span {
        display: block;
        text-align: center;
    }
    strong {
        color: #fff;
        font-size: 20px;
    }
}
.pc-decoration-widget {
    position: relative;
    box-sizing: border-box;
    & + & {
        margin-top: 34px;
    }
}
.pc-decoration.is-edit .pc-decoration-widget {
    outline: 1px dashed transparent;
    opacity: 1;
    &:hover,
    &.is-selected {
        outline-color: #5f74ff;
    }
}
.pc-decoration.is-edit .pc-decoration__main {
    isolation: isolate;
}
.pc-decoration.is-edit .pc-decoration__main > .pc-decoration-widget {
    position: relative;
    z-index: 2;
}
.pc-decoration-widget__tools {
    position: absolute;
    right: 12px;
    top: 12px;
    z-index: 20;
    display: flex;
    gap: 6px;
    padding: 6px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.28);
    button {
        height: 26px;
        border: 0;
        border-radius: 6px;
        background: transparent;
        color: #111827;
        cursor: pointer;
        font-size: 12px;
        &.danger {
            color: #ef4444;
        }
        &:hover {
            background: #eef2ff;
        }
    }
}
.pc-decoration-widget__hidden-label {
    position: absolute;
    inset: 0;
    z-index: 18;
    display: grid;
    place-items: center;
    border-radius: inherit;
    background: rgba(0, 0, 0, 0.46);
    color: #fff;
    font-size: 18px;
    font-weight: 700;
    pointer-events: none;
}
.pc-decoration__sidebar-shell {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 30;
    margin: 0 !important;
}
.pc-sidebar {
    width: 74px;
    min-height: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px 6px;
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    background: #08090b;
    box-sizing: border-box;
}
.pc-sidebar__logo {
    flex: none;
    width: 36px;
    height: 36px;
    margin-top: 0;
    border: 0;
    border-radius: 12px;
    background:
        radial-gradient(circle at 30% 20%, rgba(77, 235, 255, 0.38), transparent 42%),
        rgba(255, 255, 255, 0.04);
    color: #fff;
    font-size: 18px;
    font-weight: 800;
}
.pc-sidebar__nav {
    width: 100%;
    margin-top: 0;
}
.pc-sidebar__item,
.pc-sidebar__footer-item {
    width: 62px;
    margin: 0 auto 6px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: rgba(255, 255, 255, 0.72);
    cursor: pointer;
    span,
    em {
        display: block;
        font-style: normal;
    }
    span {
        display: grid;
        place-items: center;
        font-size: 20px;
        line-height: 22px;
    }
    em {
        margin-top: 5px;
        font-size: 11px;
        line-height: 1;
    }
    &.is-active,
    &:hover {
        background: rgba(255, 255, 255, 0.11);
        color: #fff;
    }
}
.pc-sidebar__item {
    min-height: 54px;
    padding: 6px 4px;
    box-sizing: border-box;
}
.pc-sidebar__footer {
    width: 100%;
    margin-top: auto;
    padding-bottom: 0;
}
.pc-sidebar__footer-item {
    min-height: 22px;
    width: 48px;
    color: rgba(255, 255, 255, 0.5);
    &.is-vip {
        width: 58px;
        min-height: 54px;
        margin-bottom: 10px;
        border: 1px solid rgba(255, 255, 255, 0.16);
        border-radius: 10px;
        color: #fff;
        background: rgba(255, 255, 255, 0.02);
    }
}
.pc-hero-grid {
    display: grid;
    grid-template-columns: minmax(430px, 1.75fr) minmax(260px, 0.98fr) minmax(250px, 0.98fr);
    gap: 8px;
    min-height: 178px;
}
.pc-hero-grid__banner,
.pc-hero-grid__tv,
.pc-hero-grid__quick button,
.pc-rich-media {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    background: #101217;
    color: #fff;
    text-align: left;
    cursor: pointer;
}
.pc-hero-grid__banner {
    min-height: 178px;
    padding: 48px 34px;
    background: linear-gradient(120deg, #111a62, #0b1119);
}
.pc-hero-grid__copy,
.pc-hero-grid__tv span,
.pc-hero-grid__quick span {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    gap: 12px;
    strong {
        font-size: 30px;
        line-height: 1.2;
    }
    small {
        color: rgba(255, 255, 255, 0.68);
        font-size: 16px;
    }
}
.pc-hero-grid__backdrop,
.pc-tool-card__image,
.pc-case-card__image,
.pc-rich-media__image,
.pc-hero-grid__media-stack,
.pc-hero-grid__quick-media {
    background:
        linear-gradient(135deg, rgba(78, 96, 255, 0.4), rgba(255, 104, 170, 0.32)),
        #181b20;
    background-size: cover;
    background-position: center;
}
.pc-hero-grid__backdrop {
    position: absolute;
    inset: 0;
    opacity: 0.34;
}
.pc-hero-grid__device {
    position: absolute;
    right: 52px;
    top: 36px;
    width: 154px;
    height: 118px;
    border: 1px solid rgba(255, 255, 255, 0.32);
    border-radius: 12px;
    transform: rotate(5deg);
    background: rgba(255, 255, 255, 0.05);
}
.pc-hero-grid__dots {
    position: absolute;
    right: 40px;
    bottom: 24px;
    display: flex;
    gap: 8px;
    i {
        width: 8px;
        height: 8px;
        border-radius: 99px;
        background: rgba(255, 255, 255, 0.35);
        &.is-active {
            width: 24px;
            background: #46e6ff;
        }
    }
}
.pc-hero-grid__tv {
    min-height: 178px;
    padding: 24px 18px;
    background: linear-gradient(135deg, #141719, #08221e);
    i {
        position: absolute;
        right: 26px;
        top: 48px;
        z-index: 3;
        display: grid;
        place-items: center;
        width: 58px;
        height: 44px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        font-size: 28px;
        font-style: normal;
    }
}
.pc-hero-grid__media-stack {
    position: absolute;
    left: 42px;
    right: 40px;
    bottom: -26px;
    height: 78px;
    border-radius: 12px;
    transform: rotate(-8deg);
}
.pc-hero-grid__quick {
    display: grid;
    grid-template-rows: 1fr 1fr;
    gap: 8px;
    button {
        min-height: 85px;
        padding: 17px 18px;
        i {
            position: absolute;
            right: 24px;
            top: 50%;
            z-index: 2;
            display: grid;
            place-items: center;
            width: 48px;
            height: 40px;
            margin-top: -20px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            font-size: 24px;
            font-style: normal;
        }
    }
}
.pc-hero-grid__quick-media {
    position: absolute;
    inset: 0 0 0 44%;
    opacity: 0.52;
}
.pc-tool-carousel {
    position: relative;
}
.pc-tool-strip {
    display: flex;
    gap: 14px;
    overflow-x: auto;
    scrollbar-width: none;
}
.pc-tool-strip::-webkit-scrollbar {
    display: none;
}
.pc-carousel-arrow {
    position: absolute;
    top: 50%;
    z-index: 4;
    width: 34px;
    height: 46px;
    margin-top: -23px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    background: rgba(24, 26, 28, 0.88);
    color: #fff;
    font-size: 28px;
    cursor: pointer;
}
.pc-carousel-arrow--left {
    left: -6px;
}
.pc-carousel-arrow--right {
    right: -6px;
}
.pc-tool-card {
    position: relative;
    flex: 0 0 clamp(188px, 15.5vw, 244px);
    overflow: hidden;
    min-height: 250px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    background: #151820;
    cursor: pointer;
}
.pc-tool-card__image {
    position: absolute;
    inset: 0;
}
.pc-tool-card::after {
    position: absolute;
    inset: 0;
    content: '';
    background: linear-gradient(180deg, transparent 38%, rgba(0, 0, 0, 0.78));
}
.pc-tool-card__badge {
    position: absolute;
    left: 16px;
    top: 16px;
    z-index: 2;
    padding: 8px 12px;
    border-radius: 6px;
    background: #35d8ff;
    color: #061014;
    font-style: normal;
}
.pc-tool-card > button {
    position: absolute;
    right: 16px;
    top: 16px;
    z-index: 2;
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.36);
    color: #fff;
}
.pc-tool-card__body {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 10px;
    z-index: 2;
    display: grid;
    gap: 8px;
    strong {
        font-size: 20px;
    }
    small {
        color: rgba(255, 255, 255, 0.68);
    }
    em {
        justify-self: end;
        padding: 7px 10px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        font-style: normal;
    }
}
.pc-case-feed__toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 20px;
}
.pc-case-feed__tabs {
    display: flex;
    gap: 22px;
    button {
        height: 50px;
        padding: 0 20px;
        border: 0;
        border-radius: 12px;
        background: transparent;
        color: rgba(255, 255, 255, 0.66);
        cursor: pointer;
        font-size: 15px;
        font-weight: 500;
        &.is-active {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
        }
    }
}
.pc-case-feed__search {
    display: flex;
    align-items: center;
    width: 420px;
    height: 36px;
    padding: 0 18px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.04);
    input {
        flex: 1;
        min-width: 0;
        border: 0;
        outline: none;
        background: transparent;
        color: #fff;
        font-size: 16px;
    }
    span {
        width: 18px;
        height: 18px;
        margin-right: 12px;
        border: 2px solid rgba(255, 255, 255, 0.5);
        border-radius: 999px;
    }
}
.pc-case-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
}
.pc-case-card {
    position: relative;
    overflow: hidden;
    aspect-ratio: 4 / 3;
    border-radius: 8px;
    background: #151820;
    cursor: pointer;
}
.pc-case-card__image {
    position: absolute;
    inset: 0;
}
.pc-case-card__mask {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 16px;
    background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.78));
    opacity: 0;
    transition: opacity 0.18s ease;
    button {
        flex: none;
        border: 0;
        border-radius: 8px;
        padding: 8px 12px;
        background: linear-gradient(135deg, #7c5cff, #ff5e9f);
        color: #fff;
    }
}
.pc-case-card:hover .pc-case-card__mask {
    opacity: 1;
}
.pc-rich-media {
    min-height: 240px;
    padding: 28px;
}
.pc-rich-media__image {
    position: absolute;
    inset: 0;
    opacity: 0.48;
}
.pc-rich-media span:last-child {
    position: relative;
    display: grid;
    gap: 8px;
}
.pc-text {
    min-height: 48px;
}
.pc-button {
    height: 52px;
    padding: 0 22px;
    border: 0;
    border-radius: 8px;
    background: linear-gradient(135deg, #7c5cff, #ff5e9f);
    color: #fff;
    cursor: pointer;
}
.pc-unknown-widget {
    min-height: 120px;
    display: grid;
    place-items: center;
    border: 1px dashed rgba(255, 255, 255, 0.16);
    border-radius: 8px;
    color: rgba(255, 255, 255, 0.56);
}
@media (max-width: 1100px) {
    .pc-hero-grid {
        grid-template-columns: minmax(420px, 1.4fr) minmax(300px, 0.8fr);
    }
    .pc-hero-grid__quick {
        grid-column: 1 / -1;
        grid-template-columns: repeat(2, 1fr);
        grid-template-rows: none;
    }
    .pc-case-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>

<style lang="scss">
.pc-decoration .pc-decoration-widget {
    position: relative;
    box-sizing: border-box;
}
.pc-decoration .pc-decoration__sidebar-shell {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    z-index: 30 !important;
    width: 74px;
    margin: 0 !important;
}
.pc-decoration.is-edit .pc-decoration-widget {
    outline: 1px dashed transparent;
    outline-offset: 0;
    opacity: 1 !important;
}
.pc-decoration.is-edit .pc-decoration-widget:hover,
.pc-decoration.is-edit .pc-decoration-widget.is-selected {
    outline-color: #5f74ff;
}
.pc-decoration.is-edit .pc-decoration__main > .pc-decoration-widget {
    position: relative;
    z-index: 2;
}
.pc-decoration .pc-decoration-widget__tools {
    position: absolute;
    right: 10px;
    top: 10px;
    z-index: 80;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 8px;
    background: rgba(16, 18, 24, 0.92);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.36);
}
.pc-decoration.is-edit .pc-decoration-widget__tools {
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.16s ease;
}
.pc-decoration.is-edit .pc-decoration-widget:hover .pc-decoration-widget__tools,
.pc-decoration.is-edit .pc-decoration-widget.is-selected .pc-decoration-widget__tools {
    opacity: 1;
    pointer-events: auto;
}
.pc-decoration .pc-decoration-widget__tools button {
    display: grid;
    place-items: center;
    width: 24px;
    height: 24px;
    min-width: 24px;
    padding: 0;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: rgba(255, 255, 255, 0.86);
    cursor: pointer;
    font-size: 13px;
    line-height: 1;
}
.pc-decoration .pc-decoration-widget__tools button:hover {
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
}
.pc-decoration .pc-decoration-widget__tools button.danger {
    color: #ff6b8b;
}
.pc-decoration .pc-decoration-widget__hidden-label {
    position: absolute;
    inset: 0;
    z-index: 70;
    display: grid;
    place-items: center;
    border-radius: inherit;
    background: rgba(0, 0, 0, 0.46);
    color: #fff;
    font-size: 18px;
    font-weight: 700;
    pointer-events: none;
}
.pc-decoration .pc-sidebar__item span svg,
.pc-decoration .pc-sidebar__footer-item span svg {
    display: block;
    width: 22px;
    height: 22px;
    fill: currentColor;
}
.pc-decoration .pc-sidebar__footer {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0;
}
.pc-decoration .pc-sidebar__footer-item:not(.is-vip) em {
    display: none;
}
</style>
