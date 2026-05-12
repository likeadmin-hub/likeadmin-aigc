<template>
    <view class="page">
        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="42"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">视频合成中</view>
                <view class="nav-spacer" :style="navPlaceholderStyle"></view>
            </view>
        </view>

        <view class="content">
            <view class="task-card">
                <view class="task-head">
                    <image v-if="coverUrl" class="avatar-cover" :src="coverUrl" mode="aspectFill" />
                    <view v-else class="avatar-cover avatar-cover--empty">A</view>
                    <view class="task-copy">
                        <view class="task-title">{{ currentTitle }}</view>
                        <view class="task-voice">音色：{{ currentVoice }}</view>
                    </view>
                </view>

                <view class="center-state">
                    <view class="progress-ring">
                        <view class="progress-text">{{ currentProgress }}%</view>
                    </view>
                    <view class="state-title">{{ stateTitle }}</view>
                    <view class="state-desc">{{ stateDesc }}</view>
                </view>

                <button class="result-btn" @click="goResults">查看合成结果</button>
            </view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getAigcDigitalHumanTasks } from '@/apps/aigc_digital_human/api'

const lists = ref<any[]>([])
const latest = ref<any>({})
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
    lineHeight: `${navMetrics.menuHeight}px`
}))
const navPlaceholderStyle = computed(() => ({
    width: `${navMetrics.menuWidth}px`,
    height: `${navMetrics.menuHeight}px`
}))
const current = computed(() => lists.value[0] || latest.value || {})
const coverUrl = computed(
    () =>
        current.value.avatar?.cover_url ||
        current.value.avatar?.media_url ||
        current.value.cover_url ||
        current.value.media_url ||
        ''
)
const currentTitle = computed(
    () => current.value.title || current.value.avatar?.name || '数字人视频'
)
const currentVoice = computed(
    () => current.value.voice?.name || current.value.voice_name || '知性女声'
)
const currentProgress = computed(() =>
    Number(current.value.progress || latest.value.progress || 20)
)
const stageText = (stage: string) => {
    const map: Record<string, string> = {
        created: '准备合成音频',
        tts_submitted: '音频任务已提交',
        tts_running: '音频合成中',
        tts_failed: '音频合成失败',
        lipsync_submitted: '视频任务已提交',
        lipsync_running: '视频合成中',
        lipsync_failed: '视频合成失败',
        storing: '保存作品中',
        success: '合成完成',
        failed: '合成失败'
    }
    return map[stage] || '正在合成数字人视频'
}
const stateTitle = computed(() =>
    current.value.status === 'failed' ? '合成失败' : stageText(current.value.provider_stage)
)
const stateDesc = computed(
    () =>
        current.value.error ||
        (current.value.status === 'success'
            ? '作品已生成，可以查看合成结果'
            : '后台会自动完成音频、视频合成和作品保存')
)

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
    latest.value = uni.getStorageSync('aigc_digital_human_latest_generate') || {}
    const rows = await getAigcDigitalHumanTasks()
    lists.value = rows || []
}

const goBack = () => uni.navigateBack()
const goResults = () => uni.navigateTo({ url: '/apps/aigc_digital_human/pages/results/results' })

initNavMetrics()
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

.nav-spacer {
    position: absolute;
    right: 0;
}

.page-title {
    max-width: 360rpx;
    overflow: hidden;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 34rpx;
    font-weight: 900;
}

.content {
    padding: 32rpx 28rpx 0;
}

.task-card {
    min-height: 760rpx;
    padding: 30rpx;
    border: 1px solid #e1e8f2;
    border-radius: 24rpx;
    background: #ffffff;
    box-shadow: 0 14rpx 44rpx rgba(41, 75, 120, 0.07);
}

.task-head {
    display: flex;
    align-items: center;
    gap: 20rpx;
}

.avatar-cover {
    flex: none;
    width: 112rpx;
    height: 112rpx;
    border-radius: 18rpx;
    background: #edf2f7;
}

.avatar-cover--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2f80ff;
    font-size: 42rpx;
    font-weight: 900;
}

.task-copy {
    flex: 1;
    min-width: 0;
}

.task-title {
    overflow: hidden;
    color: #172033;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 30rpx;
    font-weight: 900;
}

.task-voice {
    margin-top: 12rpx;
    color: #8a93a3;
    font-size: 24rpx;
}

.center-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 120rpx;
}

.progress-ring {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 178rpx;
    height: 178rpx;
    border: 10rpx solid rgba(59, 130, 255, 0.14);
    border-top-color: #3b82ff;
    border-right-color: #3b82ff;
    border-radius: 50%;
    box-shadow: 0 16rpx 34rpx rgba(59, 130, 255, 0.12);
}

.progress-text {
    color: #172033;
    font-size: 42rpx;
    font-weight: 900;
}

.state-title {
    margin-top: 42rpx;
    color: #172033;
    font-size: 30rpx;
    font-weight: 900;
}

.state-desc {
    margin-top: 16rpx;
    color: #8a93a3;
    font-size: 24rpx;
}

.result-btn {
    width: 100%;
    height: 84rpx;
    margin-top: 110rpx;
    border: 0;
    border-radius: 999rpx;
    background: linear-gradient(90deg, #38a5ff 0%, #4c6dff 100%);
    color: #ffffff;
    font-size: 29rpx;
    font-weight: 900;
}

.page {
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
    color: #ffffff;
}

.page-title,
.task-title,
.progress-text,
.state-title {
    color: #ffffff;
}

.task-card {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(34, 34, 34, 0.96);
}

.avatar-cover,
.avatar-cover--empty {
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
    color: #ffffff;
}

.task-voice,
.state-desc {
    color: rgba(255, 255, 255, 0.52);
}

.progress-ring {
    border-color: rgba(255, 255, 255, 0.14);
    border-top-color: #ffffff;
    border-right-color: #ffffff;
}

.result-btn {
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}
</style>
