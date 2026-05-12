<template>
    <div class="diy-render">
        <template v-for="(item, index) in pages" :key="item.id || index">
            <ElCarousel
                v-if="item.name === 'pc-banner' && item.content?.enabled"
                class="w-full"
                trigger="click"
                height="340px"
            >
                <ElCarouselItem v-for="banner in item.content.data || []" :key="banner.image">
                    <NuxtLink :to="banner.link?.path || ''">
                        <ElImage class="w-full h-full rounded-[8px] bg-white" :src="imageUrl(banner.image)" fit="contain" />
                    </NuxtLink>
                </ElCarouselItem>
            </ElCarousel>
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
        </template>
    </div>
</template>

<script lang="ts" setup>
import { ArrowRight } from '@element-plus/icons-vue'
import { ElCarousel, ElCarouselItem, ElIcon, ElImage } from 'element-plus'
import type { PropType } from 'vue'
import { useAppStore } from '~~/stores/app'

defineProps({
    pages: {
        type: Array as PropType<any[]>,
        default: () => []
    }
})

const appStore = useAppStore()
const imageUrl = (url: string) => (url ? appStore.getImageUrl(url) : '')
const titleStyle = (item: any) => ({
    background: item.styles?.background || '#ffffff',
    color: item.styles?.color || '#101010',
    padding: `${item.styles?.padding_top || 14}px 18px ${item.styles?.padding_bottom || 14}px`
})
</script>

<style scoped lang="scss">
.diy-render > * {
    margin-bottom: 16px;
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
