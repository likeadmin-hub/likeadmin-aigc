<template>
    <view class="page">
        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="42"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">{{
                    source === 'mine' ? '我的克隆音色' : '全部音色'
                }}</view>
            </view>
        </view>

        <view class="tabs">
            <view
                v-for="item in categories"
                :key="item"
                class="tab"
                :class="{ 'is-active': category === item }"
                @click="category = item"
            >
                {{ item }}
            </view>
        </view>

        <scroll-view
            class="content"
            scroll-y
            refresher-enabled
            :refresher-triggered="refreshing"
            @refresherrefresh="refreshData"
        >
            <view v-if="source === 'mine'" class="create-card" @click="createVoice">
                <view class="wave-large">⌁</view>
                <view class="create-title">创建我的音色</view>
                <view class="create-desc">上传音频，克隆当前用户专属音色</view>
            </view>

            <view v-if="lists.length" class="voice-list">
                <view
                    v-for="(item, index) in lists"
                    :key="item.id"
                    class="voice-card"
                    @click="selectVoice(item)"
                >
                    <view class="play-circle" :class="`is-${index % 4}`">
                        <u-icon name="play-right-fill" color="#ffffff" size="30"></u-icon>
                    </view>
                    <view class="voice-info">
                        <view class="voice-name">{{ item.name }}</view>
                        <view class="voice-desc">{{
                            item.source === 'mine' ? '我的克隆音色' : voiceDesc(item, index)
                        }}</view>
                    </view>
                    <view v-if="item.source === 'official'" class="vip-tag">VIP</view>
                    <u-icon name="arrow-right" color="#a4adba" size="26"></u-icon>
                </view>
            </view>
            <view v-else class="empty">
                <view class="empty-title">{{
                    source === 'mine' ? '还没有克隆音色' : '暂无官方音色'
                }}</view>
                <view class="empty-desc">创建音色后会自动带回创作页</view>
                <button class="empty-btn" @click="createVoice">创建音色</button>
            </view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { getAigcDigitalHumanVoices } from '@/apps/aigc_digital_human/api'

const lists = ref<any[]>([])
const source = ref('official')
const category = ref('热门')
const categories = ['热门', '女声', '男声', '童声', '方言']
const refreshing = ref(false)
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const descs = [
    '温柔知性，适合口播',
    '温柔亲切，适合讲解',
    '沉稳磁性，适合介绍',
    '活力阳光，适合短视频'
]

const topbarStyle = computed(() => ({ height: `${navMetrics.navHeight}px` }))
const navRowStyle = computed(() => ({
    top: `${navMetrics.menuTop}px`,
    height: `${navMetrics.menuHeight}px`
}))
const pageTitleStyle = computed(() => ({
    height: `${navMetrics.menuHeight}px`,
    lineHeight: `${navMetrics.menuHeight}px`
}))

const initNavMetrics = () => {
    const systemInfo = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = systemInfo.statusBarHeight || navMetrics.statusBarHeight
    // #ifdef MP-WEIXIN
    const menuButton = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = menuButton.top
    navMetrics.menuHeight = menuButton.height
    navMetrics.menuWidth = systemInfo.windowWidth - menuButton.left
    navMetrics.navHeight = menuButton.top + menuButton.height + 18
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 10
    navMetrics.menuHeight = 36
    navMetrics.menuWidth = 0
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 18
    // #endif
}

const getData = async () => {
    lists.value = await getAigcDigitalHumanVoices({ source: source.value })
}

const refreshData = async () => {
    refreshing.value = true
    try {
        await getData()
    } finally {
        refreshing.value = false
    }
}

const voiceDesc = (item: any, index: number) => item.description || descs[index % descs.length]

const selectVoice = (item: any) => {
    uni.setStorageSync('aigc_digital_human_selected_voice', item)
    uni.navigateBack()
}

const createVoice = async () => {
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/voice/voice' })
}

const goBack = () => uni.navigateBack()

initNavMetrics()
onLoad((options: any) => {
    if (options?.source === 'mine') {
        uni.redirectTo({ url: '/apps/aigc_digital_human/pages/clone/voice/voice' })
        return
    }
    source.value = options?.source === 'mine' ? 'mine' : 'official'
})
onShow(getData)
</script>

<style lang="scss" scoped>
.page {
    min-height: 100vh;
    overflow: hidden;
    background: linear-gradient(180deg, #f7fbff 0%, #f2f6fb 100%);
    color: #172033;
}

.topbar {
    position: relative;
    z-index: 2;
}

.nav-row {
    position: absolute;
    left: 34rpx;
    right: 34rpx;
    display: flex;
    align-items: center;
    justify-content: center;
}

.nav-btn {
    position: absolute;
    left: 0;
    display: flex;
    align-items: center;
    width: 52rpx;
    height: 100%;
}

.page-title {
    max-width: 340rpx;
    overflow: hidden;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 34rpx;
    font-weight: 900;
}

.tabs {
    display: flex;
    gap: 10rpx;
    margin: 18rpx 28rpx 12rpx;
    padding: 8rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 22rpx;
    background: rgba(255, 255, 255, 0.035);
    overflow: hidden;
}

.tab {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 1;
    height: 62rpx;
    border: 1rpx solid transparent;
    border-radius: 16rpx;
    background: #eef3f8;
    color: #5f6b7a;
    font-size: 24rpx;
    font-weight: 700;
}

.tab.is-active {
    background: #e5f0ff;
    color: #2f80ff;
}

.content {
    box-sizing: border-box;
    height: calc(100vh - 170rpx);
    padding: 18rpx 28rpx 34rpx;
}

.create-card,
.voice-card,
.empty {
    border: 1px solid #e1e8f2;
    border-radius: 22rpx;
    background: #ffffff;
    box-shadow: 0 12rpx 32rpx rgba(41, 75, 120, 0.06);
}

.create-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 240rpx;
    margin-bottom: 20rpx;
}

.wave-large {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 88rpx;
    height: 88rpx;
    border-radius: 50%;
    background: linear-gradient(90deg, #38a5ff 0%, #4c6dff 100%);
    color: #ffffff;
    font-size: 56rpx;
    font-weight: 900;
}

.create-title,
.empty-title {
    margin-top: 18rpx;
    color: #172033;
    font-size: 30rpx;
    font-weight: 900;
}

.create-desc,
.voice-desc,
.empty-desc {
    margin-top: 8rpx;
    color: #8a93a3;
    font-size: 24rpx;
}

.voice-list {
    display: grid;
    gap: 16rpx;
}

.voice-card {
    display: flex;
    align-items: center;
    gap: 18rpx;
    padding: 20rpx;
}

.play-circle {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 76rpx;
    height: 76rpx;
    border-radius: 50%;
    background: linear-gradient(135deg, #8fc0ff, #4f8df7);
}

.play-circle.is-1 {
    background: linear-gradient(135deg, #ff9ac8, #f15ca4);
}

.play-circle.is-2 {
    background: linear-gradient(135deg, #82d5ff, #2f80ff);
}

.play-circle.is-3 {
    background: linear-gradient(135deg, #b69aff, #7d5df0);
}

.voice-info {
    flex: 1;
    min-width: 0;
}

.voice-name {
    overflow: hidden;
    color: #172033;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 28rpx;
    font-weight: 900;
}

.voice-desc {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.vip-tag {
    flex: none;
    padding: 5rpx 10rpx;
    border-radius: 8rpx;
    background: #e5f0ff;
    color: #2f80ff;
    font-size: 20rpx;
    font-weight: 900;
}

.empty {
    padding: 90rpx 30rpx;
    text-align: center;
}

.empty-btn {
    width: 180rpx;
    height: 70rpx;
    margin-top: 24rpx;
    border: 0;
    border-radius: 999rpx;
    background: #e5f0ff;
    color: #2f80ff;
    font-size: 26rpx;
    font-weight: 800;
}

.page {
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
    color: #ffffff;
}

.page-title,
.create-title,
.empty-title,
.voice-name {
    color: #ffffff;
}

.tab {
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.62);
}

.tab.is-active,
.empty-btn {
    border-color: rgba(255, 255, 255, 0.26);
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
    box-shadow: inset 0 1rpx 0 rgba(255, 255, 255, 0.14), 0 10rpx 22rpx rgba(0, 0, 0, 0.2);
}

.create-card,
.voice-card,
.empty {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(34, 34, 34, 0.96);
}

.voice-desc,
.create-desc,
.empty-desc {
    color: rgba(255, 255, 255, 0.52);
}

.vip-tag {
    background: rgba(255, 255, 255, 0.08);
    color: #ffffff;
}
</style>
