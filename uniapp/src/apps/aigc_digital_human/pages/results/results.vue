<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="42"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">创作记录</view>
                <view class="nav-spacer" :style="navPlaceholderStyle"></view>
            </view>
        </view>

        <view class="content">
            <view class="summary">
                <view>
                    <view class="summary__label">DIGITAL HUMAN HISTORY</view>
                    <view class="summary__title">数字人视频</view>
                </view>
                <view class="summary__count">{{ lists.length }}条</view>
            </view>

            <scroll-view class="status-scroll" scroll-x>
                <view class="status-tabs">
                    <view
                        v-for="item in statusTabs"
                        :key="item.value"
                        class="status-tab"
                        :class="{ 'is-active': status === item.value }"
                        @click="changeStatus(item.value)"
                    >
                        {{ item.label }}
                    </view>
                </view>
            </scroll-view>

            <scroll-view
                class="record-scroll"
                scroll-y
                refresher-enabled
                :refresher-triggered="refreshing"
                @refresherrefresh="refreshData"
            >
                <view v-if="!loading && !lists.length" class="empty">
                    <view class="empty__icon">A</view>
                    <view class="empty__title">暂无创作记录</view>
                    <view class="empty__desc">完成数字人视频合成后会展示在这里</view>
                </view>

                <view
                    v-for="item in lists"
                    :key="item.task_id || item.id"
                    class="record-card"
                    @click="goDetail(item)"
                >
                    <view class="thumb">
                        <video
                            v-if="item.video_url"
                            class="thumb-video"
                            :src="item.video_url"
                            muted
                            :controls="false"
                            object-fit="cover"
                        />
                        <image
                            v-else-if="item.cover_url"
                            class="thumb-img"
                            :src="item.cover_url"
                            mode="aspectFill"
                        />
                        <view v-else class="thumb-empty">{{ statusIcon(item.status) }}</view>
                        <view class="status-badge" :class="`is-${item.status || 'running'}`">{{
                            statusText(item.status)
                        }}</view>
                    </view>
                    <view class="record-main">
                        <view class="record-title">{{ item.title || '数字人视频' }}</view>
                        <view class="record-script">{{
                            item.script_text || item.error || stageText(item.provider_stage)
                        }}</view>
                        <view class="record-meta">
                            <text>{{ stageText(item.provider_stage) }}</text>
                            <text>{{ item.duration || 0 }}秒</text>
                            <text>{{ formatTime(item.create_time) }}</text>
                        </view>
                    </view>
                    <view class="record-actions">
                        <view class="icon-btn" @click.stop="deleteRecord(item)">
                            <u-icon name="trash" color="rgba(255,255,255,0.7)" size="30"></u-icon>
                        </view>
                        <u-icon
                            name="arrow-right"
                            color="rgba(255,255,255,0.42)"
                            size="30"
                        ></u-icon>
                    </view>
                </view>
                <view class="bottom-space"></view>
            </scroll-view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
    deleteAigcDigitalHumanResult,
    getAigcDigitalHumanResults
} from '@/apps/aigc_digital_human/api'

const loading = ref(false)
const refreshing = ref(false)
const status = ref('')
const lists = ref<any[]>([])
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const statusTabs = [
    { label: '全部', value: '' },
    { label: '合成中', value: 'running' },
    { label: '已完成', value: 'success' },
    { label: '失败', value: 'failed' }
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
    navMetrics.navHeight = menuButton.top + menuButton.height + 18
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 10
    navMetrics.menuHeight = 36
    navMetrics.menuWidth = 88
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 18
    // #endif
}

const getData = async () => {
    loading.value = true
    try {
        lists.value = await getAigcDigitalHumanResults({ status: status.value })
    } finally {
        loading.value = false
    }
}

const refreshData = async () => {
    refreshing.value = true
    await getData()
    refreshing.value = false
}

const changeStatus = (value: string) => {
    status.value = value
    getData()
}

const goBack = () => uni.navigateBack()
const goDetail = (item: any) =>
    uni.navigateTo({
        url: `/apps/aigc_digital_human/pages/results/detail/detail?task_id=${
            item.task_id || item.id
        }`
    })

const deleteRecord = (item: any) => {
    uni.showModal({
        title: '删除记录',
        content: '删除后将不在创作记录中展示，确认删除吗？',
        confirmColor: '#ffffff',
        success: async (res) => {
            if (!res.confirm) return
            await deleteAigcDigitalHumanResult({ task_id: item.task_id || item.id })
            uni.$u.toast('删除成功')
            getData()
        }
    })
}

const statusText = (value: string) => {
    const map: Record<string, string> = {
        running: '合成中',
        success: '已完成',
        failed: '失败',
        pending: '排队中'
    }
    return map[value] || '合成中'
}

const stageText = (stage: string) => {
    const map: Record<string, string> = {
        created: '准备音频',
        tts_running: '音频合成中',
        tts_failed: '音频失败',
        lipsync_submitted: '视频已提交',
        lipsync_running: '视频合成中',
        lipsync_failed: '视频失败',
        storing: '保存作品中',
        success: '合成完成',
        failed: '合成失败'
    }
    return map[stage] || '处理中'
}

const statusIcon = (value: string) => (value === 'failed' ? '!' : value === 'success' ? '▶' : '...')

const formatTime = (time?: number | string) => {
    if (!time) return '刚刚'
    const date = new Date(Number(time) * 1000)
    if (Number.isNaN(date.getTime())) return '刚刚'
    const month = `${date.getMonth() + 1}`.padStart(2, '0')
    const day = `${date.getDate()}`.padStart(2, '0')
    const hour = `${date.getHours()}`.padStart(2, '0')
    const minute = `${date.getMinutes()}`.padStart(2, '0')
    return `${month}-${day} ${hour}:${minute}`
}

initNavMetrics()
onShow(getData)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #fff;
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
}
.nav-row {
    position: absolute;
    left: 28rpx;
    right: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.nav-btn {
    display: flex;
    align-items: center;
    width: 72rpx;
    height: 100%;
}
.nav-spacer {
    position: absolute;
    right: 0;
}
.page-title {
    position: absolute;
    left: 96rpx;
    right: 120rpx;
    text-align: center;
    font-size: 32rpx;
    font-weight: 800;
}
.content {
    position: relative;
    z-index: 1;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 18rpx 28rpx 0;
    box-sizing: border-box;
}
.summary {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    padding: 28rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 24rpx;
    background: rgba(34, 34, 34, 0.94);
}
.summary__label {
    color: rgba(255, 255, 255, 0.42);
    font-size: 20rpx;
}
.summary__title {
    margin-top: 8rpx;
    font-size: 38rpx;
    font-weight: 900;
}
.summary__count {
    color: rgba(255, 255, 255, 0.72);
    font-size: 26rpx;
    font-weight: 800;
}
.status-scroll {
    margin: 24rpx -28rpx 0;
    white-space: nowrap;
}
.status-tabs {
    display: inline-flex;
    gap: 14rpx;
    padding: 0 28rpx;
}
.status-tab {
    padding: 18rpx 30rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.64);
    font-size: 24rpx;
    font-weight: 800;
}
.status-tab.is-active {
    background: #fff;
    color: #050505;
}
.record-scroll {
    height: calc(100vh - 330rpx - var(--status-bar-height));
    margin-top: 24rpx;
}
.record-card {
    display: flex;
    gap: 20rpx;
    margin-bottom: 18rpx;
    padding: 18rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 24rpx;
    background: rgba(23, 23, 25, 0.96);
}
.thumb {
    position: relative;
    flex: none;
    overflow: hidden;
    width: 154rpx;
    height: 202rpx;
    border-radius: 18rpx;
    background: #101012;
}
.thumb-video,
.thumb-img,
.thumb-empty {
    width: 100%;
    height: 100%;
}
.thumb-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.68);
    font-size: 34rpx;
    font-weight: 900;
}
.status-badge {
    position: absolute;
    left: 10rpx;
    top: 10rpx;
    padding: 6rpx 12rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.88);
    color: #08080a;
    font-size: 20rpx;
    font-weight: 900;
}
.status-badge.is-failed {
    background: #ff4d5d;
    color: #fff;
}
.status-badge.is-running {
    background: #2f80ff;
    color: #fff;
}
.record-main {
    flex: 1;
    min-width: 0;
    padding-top: 4rpx;
}
.record-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 30rpx;
    font-weight: 900;
}
.record-script {
    display: -webkit-box;
    overflow: hidden;
    min-height: 68rpx;
    margin-top: 14rpx;
    color: rgba(255, 255, 255, 0.56);
    font-size: 24rpx;
    line-height: 1.42;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.record-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10rpx;
    margin-top: 22rpx;
}
.record-meta text {
    padding: 8rpx 12rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.07);
    color: rgba(255, 255, 255, 0.58);
    font-size: 20rpx;
}
.record-actions {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    padding: 6rpx 0;
}
.icon-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 58rpx;
    height: 58rpx;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
}
.empty {
    padding-top: 120rpx;
    text-align: center;
}
.empty__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 132rpx;
    height: 132rpx;
    margin: 0 auto;
    border-radius: 34rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.74);
    font-size: 54rpx;
    font-weight: 900;
}
.empty__title {
    margin-top: 28rpx;
    font-size: 30rpx;
    font-weight: 900;
}
.empty__desc {
    margin-top: 12rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
}
.bottom-space {
    height: calc(32rpx + env(safe-area-inset-bottom));
}
</style>
