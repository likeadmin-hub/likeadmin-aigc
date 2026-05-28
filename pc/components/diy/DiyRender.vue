<template>
    <div class="diy-render" :class="{ 'is-free': isFreeLayout }" :style="renderStyle">
        <div
            v-for="(item, index) in visiblePages"
            :key="item.id || index"
            class="diy-widget"
            :style="widgetShellStyle(item)"
        >
            <ElCarousel
                v-if="item.name === 'pc-banner' && item.content?.enabled"
                class="w-full"
                trigger="click"
                height="340px"
            >
                <ElCarouselItem v-for="banner in bannerRows(item)" :key="banner.image || banner.name">
                    <NuxtLink :to="banner.link?.path || ''">
                        <div class="pc-banner-visual">
                            <ElImage
                                v-if="isValidImage(banner.image)"
                                class="pc-banner-image"
                                :src="imageUrl(banner.image)"
                                fit="cover"
                            />
                            <div class="pc-banner-copy">
                                <span>AI</span>
                                <strong>{{ banner.name || banner.title || 'AI 创作工作台' }}</strong>
                                <p>{{ banner.description || '配置封面、标题、描述和跳转链接' }}</p>
                            </div>
                        </div>
                    </NuxtLink>
                </ElCarouselItem>
            </ElCarousel>
            <div v-else-if="item.name === 'pc-tool-config' && item.content?.enabled" class="pc-tool-grid">
                <NuxtLink
                    v-for="tool in (item.content.data || []).filter((row: any) => row.enabled !== 0).slice(0, 8)"
                    :key="tool.id"
                    class="pc-tool-card"
                    :to="tool.path || tool.link?.path || '/ai/tools'"
                >
                    <ElImage v-if="tool.cover" class="pc-tool-cover" :src="imageUrl(tool.cover)" fit="cover" />
                    <div v-else class="pc-tool-cover placeholder"></div>
                    <div class="pc-tool-info">
                        <strong>{{ tool.title || tool.id }}</strong>
                        <span>{{ tool.description || 'AI 创作工具' }}</span>
                    </div>
                    <em v-if="tool.badge">{{ tool.badge }}</em>
                </NuxtLink>
            </div>
            <NuxtLink v-else-if="item.name === 'pc-hero-entry' && item.content?.enabled" class="pc-hero-entry" :to="item.content.primary_link?.path || '/ai/create'">
                <span>AI WORKSPACE</span>
                <strong>{{ item.content.title }}</strong>
                <p>{{ item.content.description }}</p>
                <div>{{ item.content.primary_text || '开始创作' }}</div>
            </NuxtLink>
            <div v-else-if="item.name === 'pc-tool-carousel' && item.content?.enabled" class="pc-tool-carousel">
                <div class="pc-section-head">
                    <strong>{{ item.content.title || '热门工具' }}</strong>
                </div>
                <div class="pc-carousel-row">
                    <NuxtLink
                        v-for="tool in sourceItems(item).slice(0, Number(item.content?.source_params?.limit || 12))"
                        :key="tool.id || tool.title"
                        class="pc-tool-card"
                        :to="tool.path || tool.link?.path || '/ai/tools'"
                    >
                        <ElImage v-if="tool.cover || tool.image" class="pc-tool-cover" :src="imageUrl(tool.cover || tool.image)" fit="cover" />
                        <div v-else class="pc-tool-cover placeholder"></div>
                        <div class="pc-tool-info">
                            <strong>{{ tool.title || tool.name }}</strong>
                            <span>{{ tool.description || tool.desc || 'AI 工具' }}</span>
                        </div>
                    </NuxtLink>
                </div>
            </div>
            <div v-else-if="item.name === 'pc-case-feed' && item.content?.enabled" class="pc-case-feed">
                <div class="pc-section-head">
                    <strong>{{ item.content.title || '灵感案例' }}</strong>
                </div>
                <div class="pc-case-grid">
                    <NuxtLink
                        v-for="row in sourceItems(item).slice(0, Number(item.content?.source_params?.limit || 20))"
                        :key="row.id || row.image || row.video"
                        class="pc-case-card"
                        :to="row.link?.path || row.path || '/ai'"
                    >
                        <ElImage class="pc-case-image" :src="imageUrl(row.cover || row.image || row.video_cover || row.url)" fit="cover" />
                        <div class="pc-case-mask">
                            <strong>{{ row.title || row.name || '创作案例' }}</strong>
                            <span>做同款</span>
                        </div>
                    </NuxtLink>
                </div>
            </div>
            <NuxtLink v-else-if="item.name === 'pc-rich-media' && item.content?.enabled" class="pc-rich-media" :to="item.content.link?.path || ''">
                <ElImage v-if="item.content.image" class="pc-rich-image" :src="imageUrl(item.content.image)" fit="cover" />
                <div class="pc-rich-info">
                    <strong>{{ item.content.title }}</strong>
                    <span>{{ item.content.description }}</span>
                </div>
            </NuxtLink>
            <div v-else-if="item.name === 'title-bar' && item.content?.enabled" class="title-bar" :style="titleStyle(item)">
                <div class="title" :class="`text-${item.content.align || 'left'}`">{{ item.content.title }}</div>
                <div v-if="item.content.sub_title" class="sub-title" :class="`text-${item.content.align || 'left'}`">
                    {{ item.content.sub_title }}
                </div>
            </div>
            <div
                v-else-if="item.name === 'divider' && item.content?.enabled"
                class="divider"
                :style="{
                    margin: `${item.styles?.margin_top || 0}px 0 ${item.styles?.margin_bottom || 0}px`,
                    borderTop: `1px ${item.content?.style || 'solid'} ${item.styles?.color || '#eeeeee'}`
                }"
            />
            <NuxtLink
                v-else-if="item.name === 'notice' && item.content?.enabled"
                class="notice"
                :to="item.content.link?.path || ''"
                :style="{ background: item.styles?.background || '#fff7e6', color: item.styles?.color || '#8a5a00' }"
            >
                {{ item.content.text }}
            </NuxtLink>
            <div v-else-if="item.name === 'list-nav' && item.content?.enabled" class="list-nav">
                <NuxtLink
                    v-for="(nav, navIndex) in (item.content.data || []).filter((row: any) => row.is_show !== '0')"
                    :key="navIndex"
                    :to="nav.link?.path || ''"
                    class="list-nav-item"
                >
                    <ElImage v-if="nav.image" class="icon" :src="imageUrl(nav.image)" fit="cover" />
                    <div class="min-w-0 flex-1">
                        <div class="name">{{ nav.name }}</div>
                        <div v-if="nav.desc" class="desc">{{ nav.desc }}</div>
                    </div>
                    <ElIcon><ArrowRight /></ElIcon>
                </NuxtLink>
            </div>
            <div
                v-else-if="item.name === 'image-hotspot' && item.content?.enabled"
                class="image-hotspot"
                :style="{ height: `${item.content.height || 180}px` }"
            >
                <ElImage v-if="item.content.image" class="image" :src="imageUrl(item.content.image)" fit="cover" />
                <NuxtLink
                    v-for="(area, areaIndex) in item.content.areas || []"
                    :key="areaIndex"
                    class="hotspot-area"
                    :to="area.link?.path || ''"
                    :style="{
                        left: `${area.left}%`,
                        top: `${area.top}%`,
                        width: `${area.width}%`,
                        height: `${area.height}%`
                    }"
                />
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ArrowRight } from '@element-plus/icons-vue'
import { ElCarousel, ElCarouselItem, ElIcon, ElImage } from 'element-plus'
import type { PropType } from 'vue'
import { useAppStore } from '~~/stores/app'

const props = defineProps({
    pages: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    resolvedSources: {
        type: Object as PropType<Record<string, any>>,
        default: () => ({})
    },
    pageMeta: {
        type: Array as PropType<any[]>,
        default: () => []
    }
})

const appStore = useAppStore()
const imageUrl = (url: string) => {
    if (!url) return ''
    if (/^(https?:\/\/|data:|blob:)/i.test(url)) return url
    return appStore.getImageUrl(url)
}
const numberValue = (value: any, fallback: number) => {
    const parsed = Number(String(value ?? '').replace('px', ''))
    return Number.isFinite(parsed) ? parsed : fallback
}
const pageContent = computed(() => props.pageMeta?.[0]?.content || {})
const layoutOf = (item: any) => {
    const styles = item?.styles || {}
    const layout = styles.layout || {}
    return {
        mode: layout.mode || (styles.position === 'absolute' ? 'free' : 'flow'),
        x: numberValue(layout.x ?? styles.left, 0),
        y: numberValue(layout.y ?? styles.top, 0),
        w: numberValue(layout.w ?? styles.width, 0),
        h: numberValue(layout.h ?? styles.height, 0),
        z: numberValue(layout.z, 1),
        hidden: !!layout.hidden
    }
}
const visiblePages = computed(() =>
    props.pages.filter((item: any) => item?.content?.enabled !== 0 && !layoutOf(item).hidden)
)
const isValidImage = (url: string) => {
    const value = String(url || '')
    return !!value && !value.includes('undefined') && !value.includes('null')
}
const bannerRows = (item: any) => {
    const rows = (item.content?.data || []).filter((row: any) => row.is_show !== '0')
    return rows.length ? rows : [{ name: 'AI 创作工作台', description: '配置封面、标题、描述和跳转链接' }]
}
const isFreeLayout = computed(() => visiblePages.value.some((item: any) => layoutOf(item).mode === 'free'))
const renderStyle = computed(() => {
    const width = numberValue(pageContent.value.pc_width, 1200)
    const minHeight = numberValue(pageContent.value.pc_min_height, 680)
    if (!isFreeLayout.value) {
        return { width: `${width}px`, minHeight: `${minHeight}px` }
    }
    const freeHeight = Math.max(
        minHeight,
        ...visiblePages.value.map((item: any) => {
            const layout = layoutOf(item)
            return layout.y + layout.h
        })
    )
    return { width: `${width}px`, minHeight: `${freeHeight + 80}px` }
})
const designStyle = (item: any) => {
    const styles = item?.styles || {}
    const radius = numberValue(styles.border_radius, NaN)
    const borderWidth = numberValue(styles.border_width, NaN)
    const opacity = numberValue(styles.opacity, NaN)
    return {
        marginTop: styles.margin_top !== undefined ? `${numberValue(styles.margin_top, 0)}px` : undefined,
        marginBottom: styles.margin_bottom !== undefined ? `${numberValue(styles.margin_bottom, 0)}px` : undefined,
        padding: styles.padding !== undefined ? `${numberValue(styles.padding, 0)}px` : undefined,
        background: styles.background || undefined,
        color: styles.color || undefined,
        borderRadius: Number.isFinite(radius) ? `${radius}px` : undefined,
        border:
            Number.isFinite(borderWidth) && borderWidth > 0
                ? `${borderWidth}px solid ${styles.border_color || 'rgba(255,255,255,0.16)'}`
                : undefined,
        boxShadow: styles.shadow || undefined,
        opacity: Number.isFinite(opacity) ? opacity / 100 : undefined
    }
}
const widgetShellStyle = (item: any) => {
    if (!isFreeLayout.value) return designStyle(item)
    const layout = layoutOf(item)
    return {
        position: 'absolute',
        left: `${layout.x}px`,
        top: `${layout.y}px`,
        width: layout.w ? `${layout.w}px` : undefined,
        minHeight: layout.h ? `${layout.h}px` : undefined,
        zIndex: layout.z,
        ...designStyle(item)
    }
}
const sourceItems = (item: any) => {
    const key = item?.content?.source_key || item?.content?.source?.key || ''
    const row = props.resolvedSources?.[key]
    return Array.isArray(row?.items) ? row.items : Array.isArray(item?.content?.data) ? item.content.data : []
}
const titleStyle = (item: any) => ({
    background: item.styles?.background || '#ffffff',
    color: item.styles?.color || '#101010',
    padding: `${item.styles?.padding_top || 14}px 18px ${item.styles?.padding_bottom || 14}px`
})
</script>

<style scoped lang="scss">
.diy-render {
    position: relative;
    margin: 0 auto;
}
.diy-render:not(.is-free) > * {
    margin-bottom: 16px;
}
.diy-widget {
    box-sizing: border-box;
}
.pc-banner-visual {
    position: relative;
    overflow: hidden;
    height: 340px;
    border-radius: 8px;
    background:
        radial-gradient(circle at 20% 20%, rgba(69, 108, 255, 0.45), transparent 34%),
        radial-gradient(circle at 80% 18%, rgba(255, 96, 168, 0.36), transparent 32%),
        linear-gradient(135deg, #eef4ff, #ffffff 44%, #fff1f7);
    color: #111827;
}
.pc-banner-image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0.72;
}
.pc-banner-copy {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    height: 100%;
    padding-left: 8%;
}
.pc-banner-copy span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    margin-bottom: 18px;
    border-radius: 18px;
    background: linear-gradient(135deg, #4f6cff, #ff68aa);
    color: #fff;
    font-size: 22px;
    font-weight: 700;
}
.pc-banner-copy strong {
    font-size: 34px;
    line-height: 1.2;
}
.pc-banner-copy p {
    margin-top: 10px;
    color: #4f5d75;
    font-size: 15px;
}
.pc-hero-entry {
    display: block;
    min-height: 220px;
    padding: 34px;
    border-radius: 8px;
    border: 1px solid rgba(85, 112, 255, 0.12);
    background:
        radial-gradient(circle at 84% 18%, rgba(255, 116, 177, 0.18), transparent 28%),
        linear-gradient(135deg, #ffffff, #f2f6ff);
    color: #111827;
}
.pc-hero-entry span {
    color: #6b7a99;
    font-size: 13px;
}
.pc-hero-entry strong {
    display: block;
    margin-top: 10px;
    font-size: 32px;
}
.pc-hero-entry p {
    margin-top: 8px;
    color: #697386;
}
.pc-hero-entry div {
    display: inline-flex;
    margin-top: 22px;
    padding: 10px 18px;
    border-radius: 8px;
    background: linear-gradient(135deg, #7c5cff, #ff5e9f);
}
.pc-section-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
    color: #111827;
}
.pc-section-head strong {
    font-size: 22px;
}
.pc-tool-grid,
.pc-carousel-row {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}
.pc-tool-carousel,
.pc-case-feed {
    padding: 18px;
    border-radius: 8px;
    border: 1px solid rgba(85, 112, 255, 0.1);
    background: #ffffff;
    box-shadow: 0 16px 36px rgba(74, 87, 128, 0.08);
}
.pc-carousel-row {
    display: flex;
    overflow-x: auto;
    padding-bottom: 6px;
}
.pc-carousel-row .pc-tool-card {
    flex: 0 0 220px;
}
.pc-tool-card {
    position: relative;
    overflow: hidden;
    min-height: 220px;
    border: 1px solid #edf0f7;
    border-radius: 8px;
    background: #f8faff;
    color: #293247;
}
.pc-tool-cover {
    width: 100%;
    height: 132px;
}
.pc-tool-cover.placeholder {
    background: linear-gradient(135deg, #dce7ff, #ffe2f0);
}
.pc-tool-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 12px;
}
.pc-tool-info span {
    color: #7a8498;
    font-size: 12px;
}
.pc-tool-card em {
    position: absolute;
    right: 10px;
    top: 10px;
    padding: 4px 8px;
    border-radius: 6px;
    background: rgba(0, 0, 0, 0.46);
    color: #fff;
    font-size: 12px;
    font-style: normal;
}
.pc-case-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 14px;
}
.pc-case-card {
    position: relative;
    overflow: hidden;
    aspect-ratio: 4 / 5;
    border-radius: 8px;
    background: linear-gradient(135deg, #dce7ff, #ffe2f0);
}
.pc-case-image {
    width: 100%;
    height: 100%;
}
.pc-case-mask {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 12px;
    background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.76));
    color: #fff;
    opacity: 0;
    transition: opacity 0.18s ease;
}
.pc-case-card:hover .pc-case-mask {
    opacity: 1;
}
.pc-case-mask span {
    flex: none;
    padding: 7px 10px;
    border-radius: 8px;
    background: linear-gradient(135deg, #7c5cff, #ff5e9f);
}
.pc-rich-media {
    display: block;
    overflow: hidden;
    border-radius: 8px;
    background: #11151f;
    color: #fff;
}
.pc-rich-image {
    width: 100%;
    height: 220px;
}
.pc-rich-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px;
}
.pc-rich-info span {
    color: rgba(255, 255, 255, 0.62);
}
.title {
    font-size: 22px;
    font-weight: 600;
}
.sub-title {
    margin-top: 4px;
    color: #888;
}
.text-left {
    text-align: left;
}
.text-center {
    text-align: center;
}
.text-right {
    text-align: right;
}
.notice {
    display: block;
    padding: 14px 18px;
    border-radius: 8px;
}
.list-nav {
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
}
.list-nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid #f2f2f2;
}
.list-nav-item:last-child {
    border-bottom: 0;
}
.icon {
    width: 32px;
    height: 32px;
    border-radius: 4px;
}
.name {
    color: #222;
}
.desc {
    margin-top: 2px;
    color: #999;
    font-size: 12px;
}
.image-hotspot {
    position: relative;
    overflow: hidden;
    border-radius: 8px;
    background: #f5f7fa;
}
.image {
    width: 100%;
    height: 100%;
}
.hotspot-area {
    position: absolute;
}
</style>
