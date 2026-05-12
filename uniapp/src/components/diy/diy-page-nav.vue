<template>
    <!-- #ifndef H5 -->
    <view class="diy-page-nav">
        <view class="diy-page-nav__placeholder" :style="placeholderStyle" />
        <view class="diy-page-nav__fixed" :style="navStyle">
            <view class="diy-page-nav__status" :style="{ height: `${layout.statusBarHeight}px` }" />
            <view class="diy-page-nav__bar" :class="`is-${titleAlign}`" :style="barStyle">
                <view
                    v-if="showBack"
                    class="diy-page-nav__back"
                    :style="{ color: titleColor }"
                    @click.stop="handleBack"
                />
                <image
                    v-if="isImageTitle && metaContent.title_img"
                    class="diy-page-nav__title-img"
                    :src="getImageUrl(metaContent.title_img)"
                    mode="heightFix"
                />
                <view v-else class="diy-page-nav__title" :style="{ color: titleColor }">
                    {{ titleText }}
                </view>
            </view>
        </view>
    </view>
    <!-- #endif -->
</template>

<script setup lang="ts">
import { useAppStore } from '@/stores/app'
import { computed } from 'vue'

const props = defineProps({
    meta: {
        type: Array,
        default: () => []
    },
    fallbackTitle: {
        type: String,
        default: ''
    },
    transparent: {
        type: Boolean,
        default: false
    }
})

const { getImageUrl } = useAppStore()

const layout = computed(() => {
    const systemInfo = uni.getSystemInfoSync()
    const menuButton = uni.getMenuButtonBoundingClientRect?.()
    const statusBarHeight = systemInfo.statusBarHeight || 0
    const barHeight = systemInfo.platform === 'ios' ? 44 : 48
    const rightGap = menuButton?.left ? systemInfo.windowWidth - menuButton.left + 8 : 96
    return { statusBarHeight, barHeight, rightGap }
})
const metaContent: any = computed(() => (props.meta?.[0] as any)?.content || {})
const isImageTitle = computed(() => String(metaContent.value.title_type) === '2')
const titleText = computed(() => metaContent.value.title || props.fallbackTitle || '首页')
const titleColor = computed(() =>
    String(metaContent.value.text_color) === '1' ? '#ffffff' : '#111111'
)
const showBack = computed(() => Number(metaContent.value.show_back || 0) === 1)
const titleAlign = computed(() => metaContent.value.title_align || 'center')
const navBgColor = computed(() => {
    if (props.transparent) return 'transparent'
    const bgColor = metaContent.value.nav_bg_color || metaContent.value.navigation_bg_color
    if (bgColor) return bgColor
    return metaContent.value.bg_color || '#ffffff'
})
const navStyle = computed(() => ({
    background: navBgColor.value
}))
const placeholderStyle = computed(() => ({
    height: `${layout.value.statusBarHeight + layout.value.barHeight}px`
}))
const barStyle = computed(() => ({
    height: `${layout.value.barHeight}px`,
    paddingRight: `${layout.value.rightGap}px`
}))
const handleBack = () => {
    uni.navigateBack({
        fail() {
            uni.switchTab({ url: '/pages/index/index' })
        }
    })
}
</script>

<style scoped lang="scss">
.diy-page-nav {
    position: relative;
    width: 100%;
}
.diy-page-nav__fixed {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 999;
    width: 100%;
}
.diy-page-nav__bar {
    position: relative;
    box-sizing: border-box;
    display: flex;
    align-items: center;
    justify-content: center;
    padding-left: 96px;
}
.diy-page-nav__bar.is-left {
    justify-content: flex-start;
    padding-left: 88rpx;
}
.diy-page-nav__back {
    position: absolute;
    left: 28rpx;
    top: 50%;
    width: 22rpx;
    height: 22rpx;
    border-left: 4rpx solid currentColor;
    border-bottom: 4rpx solid currentColor;
    transform: translateY(-50%) rotate(45deg);
}
.diy-page-nav__title {
    max-width: 320rpx;
    overflow: hidden;
    font-size: 32rpx;
    font-weight: 600;
    line-height: 1;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.diy-page-nav__title-img {
    height: 40rpx;
    max-width: 300rpx;
}
</style>
