<template>
    <page-meta :page-style="$theme.pageStyle" />
    <view class="index" :style="pageStyle">
        <diy-page-nav :meta="state.meta" fallback-title="首页" />
        <diy-render
            :pages="state.pages"
            :meta="state.meta"
            :percent="percent"
            :is-large-screen="isLargeScreen"
            @bannerChange="handleBanner"
        />

        <view class="article" v-if="state.article.length">
            <view class="flex items-center article-title mx-[20rpx] my-[30rpx] text-lg font-medium">
                最新资讯
            </view>
            <news-card
                v-for="item in state.article"
                :key="item.id"
                :news-id="item.id"
                :item="item"
            />
        </view>

        <!--  #ifdef H5  -->
        <view class="text-center py-4 mb-12">
            <router-navigate
                class="mx-1 text-xs text-[#495770]"
                :to="{
                    path: '/pages/webview/webview',
                    query: {
                        url: item.value
                    }
                }"
                v-for="item in appStore.getCopyrightConfig"
                :key="item.key"
            >
                {{ item.key }}
            </router-navigate>
        </view>
        <!--  #endif  -->

        <!-- 返回顶部按钮 -->
        <u-back-top
            :scroll-top="scrollTop"
            :top="100"
            :customStyle="{
                backgroundColor: '#FFF',
                color: '#000',
                boxShadow: '0px 3px 6px rgba(0, 0, 0, 0.1)'
            }"
        >
        </u-back-top>

        <!--  #ifdef MP  -->
        <!--  微信小程序隐私弹窗  -->
        <MpPrivacyPopup></MpPrivacyPopup>
        <!--  #endif  -->

        <tabbar />
    </view>
</template>

<script setup lang="ts">
import { getIndex } from '@/api/shop'
import { onLoad, onPageScroll } from '@dcloudio/uni-app'
import { computed, reactive, ref } from 'vue'
import { useAppStore } from '@/stores/app'
import DiyPageNav from '@/components/diy/diy-page-nav.vue'
import DiyRender from '@/components/diy/diy-render.vue'

// #ifdef MP
import MpPrivacyPopup from './component/mp-privacy-popup.vue'
// #endif

const appStore = useAppStore()
const state = reactive<{
    pages: any[]
    meta: any[]
    article: any[]
    bannerImage: string
}>({
    pages: [],
    meta: [],
    article: [],
    bannerImage: ''
})
const scrollTop = ref<number>(0)
const percent = ref<number>(0)

// 是否联动背景图
const isLinkage = computed(() => {
    return state.pages.find((item: any) => item.name === 'banner')?.content.bg_style === 1
})
// 是否大屏banner
const isLargeScreen = computed(() => {
    return state.pages.find((item: any) => item.name === 'banner')?.content.style === 2
})
const pageMetaContent: any = computed(() => state.meta[0]?.content ?? {})

// 根页面样式
const pageStyle = computed(() => {
    const content = pageMetaContent.value
    if (isLinkage.value) {
        return { 'background-image': `url(${state.bannerImage})` }
    }
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
    if (bgType === '4') {
        const colors =
            Array.isArray(content.gradient_colors) && content.gradient_colors.length
                ? content.gradient_colors
                : [
                      content.gradient_color_start || '#f8f8f8',
                      content.gradient_color_end || '#ffffff'
                  ]
        return {
            'background-image': `linear-gradient(${content.gradient_direction || '180deg'}, ${colors
                .filter(Boolean)
                .join(', ')})`
        }
    }
    return { 'background-color': content.bg_color || '#ffffff' }
})

const handleBanner = (url: string) => {
    state.bannerImage = url
}

const getData = async () => {
    const data = await getIndex()
    state.pages = JSON.parse(data?.page?.data)
    state.meta = JSON.parse(data?.page?.meta)
    state.article = data.article
    uni.setNavigationBarTitle({
        title: state.meta[0].content.title
    })
}

onPageScroll((event: any) => {
    scrollTop.value = event.scrollTop
    const top = uni.upx2px(100)
    percent.value = event.scrollTop / top > 1 ? 1 : event.scrollTop / top
})

onLoad(() => {
    getData()
})
</script>

<style lang="scss" scoped>
.index {
    position: relative;
    background-repeat: no-repeat;
    background-size: 100% auto;
    overflow: hidden;
    width: 100%;
    transition: all 1s;
    min-height: calc(100vh - env(safe-area-inset-bottom));
}

.article-title {
    &::before {
        content: '';
        width: 8rpx;
        height: 34rpx;
        display: block;
        margin-right: 10rpx;
        @apply bg-primary;
    }
}
</style>
