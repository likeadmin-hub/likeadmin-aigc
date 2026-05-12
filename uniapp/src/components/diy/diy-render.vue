<template>
    <view class="diy-render">
        <template v-for="(item, index) in pages" :key="item.id || index">
            <w-search
                v-if="item.name == 'search'"
                :pageMeta="meta"
                :content="item.content"
                :styles="item.styles"
                :percent="percent"
                :isLargeScreen="isLargeScreen"
            />
            <w-banner
                v-else-if="item.name == 'banner'"
                :content="item.content"
                :styles="item.styles"
                :isLargeScreen="isLargeScreen"
                @change="$emit('bannerChange', $event)"
            />
            <w-nav v-else-if="item.name == 'nav'" :content="item.content" :styles="item.styles" />
            <w-middle-banner
                v-else-if="item.name == 'middle-banner'"
                :content="item.content"
                :styles="item.styles"
            />
            <view
                v-else-if="item.name == 'title-bar' && item.content?.enabled"
                class="title-bar"
                :style="titleStyle(item)"
            >
                <view class="title" :class="`text-${item.content.align || 'left'}`">{{
                    item.content.title
                }}</view>
                <view
                    v-if="item.content.sub_title"
                    class="sub-title"
                    :class="`text-${item.content.align || 'left'}`"
                >
                    {{ item.content.sub_title }}
                </view>
            </view>
            <view
                v-else-if="item.name == 'divider' && item.content?.enabled"
                class="divider"
                :style="{
                    margin: `${item.styles?.margin_top || 0}rpx 28rpx ${
                        item.styles?.margin_bottom || 0
                    }rpx`,
                    borderTop: `1px ${item.content?.style || 'solid'} ${
                        item.styles?.color || '#eeeeee'
                    }`
                }"
            />
            <view
                v-else-if="item.name == 'notice' && item.content?.enabled"
                class="notice"
                :style="{
                    background: item.styles?.background || '#fff7e6',
                    color: item.styles?.color || '#8a5a00'
                }"
                @click="handleLink(item.content?.link)"
            >
                <u-icon name="bell" size="32" />
                <view class="notice-text">{{ item.content.text }}</view>
            </view>
            <view v-else-if="item.name == 'list-nav' && item.content?.enabled" class="list-nav">
                <view
                    v-for="(nav, navIndex) in (item.content.data || []).filter((row: any) => row.is_show !== '0')"
                    :key="navIndex"
                    class="list-nav-item"
                    @click="handleLink(nav.link)"
                >
                    <u-image
                        v-if="nav.image"
                        width="56"
                        height="56"
                        :src="getImageUrl(nav.image)"
                    />
                    <view class="list-main">
                        <view class="list-name">{{ nav.name }}</view>
                        <view v-if="nav.desc" class="list-desc">{{ nav.desc }}</view>
                    </view>
                    <u-icon name="arrow-right" color="#999" />
                </view>
            </view>
            <view
                v-else-if="item.name == 'image-hotspot' && item.content?.enabled"
                class="image-hotspot"
                :style="{ height: `${item.content.height || 180}rpx` }"
            >
                <image
                    v-if="item.content.image"
                    class="hotspot-image"
                    :src="getImageUrl(item.content.image)"
                    mode="aspectFill"
                />
                <view
                    v-for="(area, areaIndex) in item.content.areas || []"
                    :key="areaIndex"
                    class="hotspot-area"
                    :style="{
                        left: `${area.left}%`,
                        top: `${area.top}%`,
                        width: `${area.width}%`,
                        height: `${area.height}%`
                    }"
                    @click="handleLink(area.link)"
                />
            </view>
        </template>
    </view>
</template>

<script setup lang="ts">
import { useAppStore } from '@/stores/app'
import { navigateTo } from '@/utils/util'
import { computed } from 'vue'

defineProps({
    pages: {
        type: Array,
        default: () => []
    },
    meta: {
        type: Array,
        default: () => []
    },
    percent: {
        type: Number,
        default: 0
    },
    isLargeScreen: {
        type: Boolean,
        default: false
    }
})
defineEmits(['bannerChange'])

const { getImageUrl } = useAppStore()
const handleLink = (link: any) => {
    if (link?.path) {
        navigateTo(link)
    }
}
const titleStyle = (item: any) => ({
    background: item.styles?.background || '#ffffff',
    color: item.styles?.color || '#101010',
    padding: `${item.styles?.padding_top || 14}rpx 28rpx ${item.styles?.padding_bottom || 14}rpx`
})
</script>

<style scoped lang="scss">
.title {
    font-size: 34rpx;
    font-weight: 600;
}
.sub-title {
    margin-top: 8rpx;
    font-size: 24rpx;
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
    display: flex;
    align-items: center;
    gap: 16rpx;
    margin: 20rpx 24rpx;
    padding: 20rpx 24rpx;
    border-radius: 16rpx;
}
.notice-text {
    flex: 1;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.list-nav {
    margin: 20rpx 24rpx;
    background: #fff;
    border-radius: 16rpx;
    overflow: hidden;
}
.list-nav-item {
    min-height: 100rpx;
    display: flex;
    align-items: center;
    gap: 20rpx;
    padding: 24rpx;
    border-bottom: 1px solid #f2f2f2;
}
.list-nav-item:last-child {
    border-bottom: 0;
}
.list-main {
    flex: 1;
    min-width: 0;
}
.list-name {
    color: #222;
    font-size: 28rpx;
}
.list-desc {
    margin-top: 6rpx;
    color: #999;
    font-size: 24rpx;
}
.image-hotspot {
    position: relative;
    margin: 20rpx 24rpx;
    border-radius: 16rpx;
    overflow: hidden;
    background: #f5f7fa;
}
.hotspot-image {
    width: 100%;
    height: 100%;
}
.hotspot-area {
    position: absolute;
}
</style>
