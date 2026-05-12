<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn nav-btn--back" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">作品列表</view>
                <view class="nav-placeholder" :style="navPlaceholderStyle"></view>
            </view>
        </view>

        <scroll-view
            class="content"
            scroll-y
            refresher-enabled
            :refresher-triggered="refreshing"
            @refresherrefresh="refreshData"
        >
            <view class="summary">
                <view>
                    <view class="summary__label">AIGC VIDEO GALLERY</view>
                    <view class="summary__title">历史视频</view>
                </view>
                <view class="summary__count">{{ lists.length }} 条</view>
            </view>

            <view class="status-tabs">
                <view
                    v-for="item in statusTabs"
                    :key="item.value"
                    class="status-tab"
                    :class="{ 'is-active': status === item.value }"
                    @click="switchStatus(item.value)"
                >
                    {{ item.label }}
                </view>
            </view>

            <view v-if="lists.length" class="grid">
                <view v-for="item in lists" :key="item.id" class="card">
                    <view class="card__delete" @click.stop="handleDelete(item.task_id || item.id)">
                        <u-icon name="trash" color="#ffffff" size="24"></u-icon>
                    </view>
                    <video
                        v-if="item.video_url"
                        class="card__video"
                        :src="item.video_url"
                        :controls="true"
                        :show-center-play-btn="true"
                        object-fit="cover"
                    />
                    <view v-else class="card__placeholder" :class="`is-${item.status}`">
                        <u-icon
                            :name="statusIcon(item.status)"
                            color="rgba(255,255,255,0.76)"
                            size="58"
                        ></u-icon>
                        <text>{{ statusText(item.status) }}</text>
                    </view>
                    <view class="card__body">
                        <view class="card__meta">
                            <text>{{
                                item.video_url
                                    ? `${item.width || 1024}×${item.height || 1024}`
                                    : item.ratio
                            }}</text>
                            <text>{{ formatTime(item.create_time) }}</text>
                        </view>
                        <view class="card__prompt">{{ item.prompt }}</view>
                        <view class="card__actions">
                            <view class="action" @click="reuseResult(item)">
                                <u-icon name="reload" color="#ffffff" size="22"></u-icon>
                                <text>复用</text>
                            </view>
                        </view>
                    </view>
                </view>
            </view>

            <view v-else class="empty">
                <view class="empty__icon">
                    <u-icon name="play-circle" color="rgba(255,255,255,0.74)" size="72"></u-icon>
                </view>
                <view class="empty__title">暂无作品</view>
                <view class="empty__desc">生成后的视频会保存在这里</view>
                <button class="empty__button" @click="goCreate">去创作</button>
            </view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onHide, onShow, onUnload } from '@dcloudio/uni-app'
import { deleteAigcVideoTask, getAigcVideoResults } from '@/apps/aigc_video/api'

const lists = ref<any[]>([])
const status = ref('')
const refreshing = ref(false)
let pollTimer: ReturnType<typeof setInterval> | null = null
const statusTabs = [
    { label: '全部', value: '' },
    { label: '生成中', value: 'running' },
    { label: '成功', value: 'success' },
    { label: '失败', value: 'failed' }
]
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})

const topbarStyle = computed(() => ({ height: `${navMetrics.navHeight}px` }))
const navRowStyle = computed(() => ({
    top: `${navMetrics.menuTop}px`,
    height: `${navMetrics.menuHeight}px`
}))
const pageTitleStyle = computed(() => ({
    height: `${navMetrics.menuHeight}px`,
    lineHeight: `${navMetrics.menuHeight}px`,
    left: `${navMetrics.menuWidth + 8}px`,
    right: `${navMetrics.menuWidth + 8}px`
}))
const navPlaceholderStyle = computed(() => ({
    width: `${navMetrics.menuWidth}px`,
    height: `${navMetrics.menuHeight}px`
}))

const initNavMetrics = () => {
    const systemInfo = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = systemInfo.statusBarHeight || navMetrics.statusBarHeight
    // #ifdef MP-WEIXIN
    const menuButton = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = menuButton.top
    navMetrics.menuHeight = menuButton.height
    navMetrics.menuWidth = systemInfo.windowWidth - menuButton.left
    navMetrics.navHeight = menuButton.top + menuButton.height + 10
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 8
    navMetrics.menuHeight = 34
    navMetrics.menuWidth = 88
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 10
    // #endif
}

const getData = async () => {
    lists.value = await getAigcVideoResults(status.value ? { status: status.value } : undefined)
    const hasRunning = lists.value.some((item) => item.status === 'running')
    if (hasRunning) {
        startPolling()
    } else {
        stopPolling()
    }
}

const stopPolling = () => {
    if (!pollTimer) return
    clearInterval(pollTimer)
    pollTimer = null
}

const startPolling = () => {
    if (pollTimer) return
    pollTimer = setInterval(() => {
        getData()
    }, 5000)
}

const refreshData = async () => {
    refreshing.value = true
    try {
        await getData()
    } finally {
        refreshing.value = false
    }
}

const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) {
        uni.navigateBack()
        return
    }
    goCreate()
}

const goCreate = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) {
        uni.navigateBack()
        return
    }
    uni.navigateTo({ url: '/apps/aigc_video/pages/index/index' })
}

const reuseResult = (item: any) => {
    uni.setStorageSync('aigc_video_reuse', {
        prompt: item.prompt || '参考这条作品继续生成同风格视频',
        reference_images: [],
        ratio: item.ratio || '1:1',
        quality: item.quality || '6',
        quantity: 1,
        channel: item.channel || 'grok_video_xaiq'
    })
    const pages = getCurrentPages()
    const previousPage = pages[pages.length - 2] as any
    if (previousPage?.route === 'apps/aigc_video/pages/index/index') {
        uni.navigateBack()
        return
    }
    uni.navigateTo({ url: '/apps/aigc_video/pages/index/index' })
}

const switchStatus = (value: string) => {
    status.value = value
    getData()
}

const statusText = (value: string) => {
    return value === 'success' ? '已完成' : value === 'failed' ? '生成失败' : '生成中'
}

const statusIcon = (value: string) => {
    return value === 'failed' ? 'close-circle' : value === 'success' ? 'play-circle' : 'clock'
}

const parseTimeDate = (time?: number | string) => {
    if (time === undefined || time === null || time === '') return null
    if (typeof time === 'number' || /^\d+$/.test(String(time).trim())) {
        const timestamp = Number(time)
        if (!Number.isFinite(timestamp) || timestamp <= 0) return null
        const date = new Date(timestamp > 9999999999 ? timestamp : timestamp * 1000)
        return Number.isNaN(date.getTime()) ? null : date
    }
    const date = new Date(String(time).trim().replace(/-/g, '/'))
    return Number.isNaN(date.getTime()) ? null : date
}

const formatTime = (time?: number | string) => {
    const date = parseTimeDate(time)
    if (!date) return '刚刚'
    const month = `${date.getMonth() + 1}`.padStart(2, '0')
    const day = `${date.getDate()}`.padStart(2, '0')
    return `${month}-${day}`
}

const handleDelete = async (id: number) => {
    uni.showModal({
        title: '删除作品',
        content: '删除后将不在作品列表展示，确认删除吗？',
        confirmColor: '#ff2f99',
        success: async (res) => {
            if (!res.confirm) return
            await deleteAigcVideoTask({ id, task_id: id })
            uni.$u.toast('删除成功')
            getData()
        }
    })
}

initNavMetrics()
onShow(getData)
onHide(stopPolling)
onUnload(stopPolling)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #ffffff;
}

.page-bg,
.page-lines {
    position: fixed;
    inset: 0;
    pointer-events: none;
}

.page-bg {
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        radial-gradient(circle at 10% 2%, rgba(255, 255, 255, 0.035), transparent 34%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
}

.page-lines {
    opacity: 0.28;
    background: repeating-radial-gradient(
        circle at 52% -8%,
        transparent 0 42rpx,
        rgba(88, 112, 180, 0.18) 44rpx 46rpx,
        transparent 48rpx 78rpx
    );
}

.topbar {
    position: relative;
    z-index: 2;
    box-sizing: border-box;
}

.nav-row {
    position: absolute;
    left: 28rpx;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.nav-btn--back {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    width: 72rpx;
    height: 100%;
}

.page-title {
    position: absolute;
    top: 0;
    bottom: 0;
    text-align: center;
    font-size: 32rpx;
    font-weight: 700;
}

.content {
    position: relative;
    z-index: 1;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 18rpx 28rpx calc(34rpx + env(safe-area-inset-bottom));
    box-sizing: border-box;
}

.summary {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 24rpx;
    padding: 28rpx;
    border-radius: 22rpx;
    background: rgba(34, 34, 34, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.06);
}

.summary__label {
    color: rgba(255, 255, 255, 0.44);
    font-size: 20rpx;
}

.summary__title {
    margin-top: 8rpx;
    font-size: 36rpx;
    font-weight: 700;
}

.summary__count {
    color: rgba(255, 255, 255, 0.78);
    font-size: 26rpx;
    font-weight: 700;
}

.status-tabs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12rpx;
    margin-bottom: 24rpx;
}

.status-tab {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 64rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.66);
    font-size: 24rpx;
    font-weight: 700;
}

.status-tab.is-active {
    background: #ffffff;
    color: #08080a;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18rpx;
}

.card {
    position: relative;
    overflow: hidden;
    border-radius: 20rpx;
    background: rgba(34, 34, 34, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.06);
}

.card__delete {
    position: absolute;
    top: 14rpx;
    right: 14rpx;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54rpx;
    height: 54rpx;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.46);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
    backdrop-filter: blur(8rpx);
}

.card__video {
    width: 100%;
    height: 332rpx;
    background: #111113;
}

.card__placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18rpx;
    width: 100%;
    height: 332rpx;
    background: #111113;
    color: rgba(255, 255, 255, 0.72);
    font-size: 26rpx;
    font-weight: 700;
}

.card__placeholder.is-running {
    background: linear-gradient(180deg, rgba(45, 47, 51, 0.96), rgba(19, 20, 23, 0.96));
}

.card__placeholder.is-failed {
    background: linear-gradient(180deg, rgba(64, 30, 35, 0.96), rgba(22, 17, 19, 0.96));
}

.card__body {
    padding: 16rpx;
}

.card__meta {
    display: flex;
    justify-content: space-between;
    color: rgba(255, 255, 255, 0.46);
    font-size: 21rpx;
}

.card__prompt {
    margin-top: 12rpx;
    min-height: 64rpx;
    color: rgba(255, 255, 255, 0.82);
    font-size: 24rpx;
    line-height: 1.35;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.card__actions {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 10rpx;
    align-items: center;
    margin-top: 16rpx;
}

.action {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 5rpx;
    min-width: 0;
    height: 48rpx;
    padding: 0 8rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.78);
    font-size: 21rpx;
    line-height: 1;
    text-align: center;
}

.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 640rpx;
    color: rgba(255, 255, 255, 0.54);
}

.empty__icon {
    font-size: 72rpx;
    line-height: 1;
    color: rgba(255, 255, 255, 0.74);
}

.empty__title {
    margin-top: 24rpx;
    color: #ffffff;
    font-size: 32rpx;
    font-weight: 700;
}

.empty__desc {
    margin-top: 10rpx;
    font-size: 25rpx;
}

.empty__button {
    width: 260rpx;
    height: 82rpx;
    margin-top: 34rpx;
    border-radius: 999rpx;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 700;
}
</style>
