<template>
    <page-meta :page-style="$theme.pageStyle" />
    <view class="diy-page" :style="pageStyle">
        <video
            v-if="pageBackgroundVideo"
            class="page-bg-video"
            :src="pageBackgroundVideo"
            autoplay
            loop
            muted
            :controls="false"
            :show-center-play-btn="false"
            :show-play-btn="false"
        />
        <diy-page-nav :meta="state.meta" fallback-title="装修页面" />
        <view class="diy-content">
            <diy-render :pages="state.pages" :meta="state.meta" />
        </view>
        <tabbar />
    </view>
</template>

<script setup lang="ts">
import { getDecorate } from '@/api/shop'
import { onLoad } from '@dcloudio/uni-app'
import { computed, reactive } from 'vue'
import DiyPageNav from '@/components/diy/diy-page-nav.vue'
import DiyRender from '@/components/diy/diy-render.vue'

const state = reactive({
    meta: [] as any[],
    pages: [] as any[]
})

const pageMetaContent = computed(() => state.meta[0]?.content ?? {})
const pageBackgroundVideo = computed(() => {
    const content = pageMetaContent.value
    return String(content.bg_type) === '3' ? content.bg_video : ''
})
const gradientValue = computed(() => {
    const content = pageMetaContent.value
    const colors =
        Array.isArray(content.gradient_colors) && content.gradient_colors.length
            ? content.gradient_colors
            : [content.gradient_color_start || '#f8f8f8', content.gradient_color_end || '#ffffff']
    return `linear-gradient(${content.gradient_direction || '180deg'}, ${colors
        .filter(Boolean)
        .join(', ')})`
})
const pageStyle = computed(() => {
    const content = pageMetaContent.value
    const bgType = String(content.bg_type ?? '0')
    if (bgType === '0') return {}
    if (bgType === '2' && content.bg_image) {
        const repeat = content.bg_image_repeat || 'no-repeat'
        const sizeMap: Record<string, string> = {
            cover: 'cover',
            contain: 'contain',
            stretch: '100% 100%',
            auto: 'auto'
        }
        return {
            'background-color': content.bg_color || '#ffffff',
            'background-image': `url(${content.bg_image})`,
            'background-repeat': repeat,
            'background-size':
                repeat === 'repeat' ? 'auto' : sizeMap[content.bg_image_size] || 'cover',
            'background-position': content.bg_image_position || 'center top'
        }
    }
    if (bgType === '3') {
        return { 'background-color': content.bg_color || '#000000' }
    }
    if (bgType === '4') {
        return { 'background-image': gradientValue.value }
    }
    return { 'background-color': content.bg_color || '#ffffff' }
})
const getData = async (options: any = {}) => {
    const data = await getDecorate({
        terminal: 'mobile',
        page_code: options?.code || 'home',
        channel: 'h5',
        preview: options?.preview || '',
        template_id: options?.template_id || '',
        page_id: options?.page_id || ''
    })
    state.pages = JSON.parse(data?.data || '[]')
    state.meta = data?.meta ? JSON.parse(data.meta) : []
    uni.setNavigationBarTitle({
        title: state.meta?.[0]?.content?.title || data?.name || '装修页面'
    })
}

onLoad((options: any) => {
    getData(options)
})
</script>

<style scoped lang="scss">
.diy-page {
    position: relative;
    min-height: calc(100vh - env(safe-area-inset-bottom));
    background-repeat: no-repeat;
    background-size: 100% auto;
    overflow: hidden;
}
.diy-content {
    position: relative;
    z-index: 1;
}
.page-bg-video {
    position: fixed;
    left: 0;
    top: 0;
    width: 100vw;
    height: 100vh;
    object-fit: cover;
    z-index: 0;
    pointer-events: none;
}
</style>
