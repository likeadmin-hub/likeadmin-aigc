<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="42"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">记录详情</view>
                <view class="nav-spacer" :style="navPlaceholderStyle"></view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="hero-card">
                <view class="video-box">
                    <video
                        v-if="videoUrl"
                        class="video"
                        :src="videoUrl"
                        :controls="true"
                        :show-center-play-btn="true"
                        object-fit="cover"
                    />
                    <view v-else class="video-empty">
                        <view class="video-empty__icon">{{
                            task.status === 'failed' ? '!' : '...'
                        }}</view>
                        <view>{{ task.error || stageText(task.provider_stage) }}</view>
                    </view>
                    <view class="status-badge" :class="`is-${task.status || 'running'}`">{{
                        statusText(task.status)
                    }}</view>
                </view>
                <view class="title-row">
                    <view>
                        <view class="title">{{ task.title || '数字人视频' }}</view>
                        <view class="subtitle">{{ stageText(task.provider_stage) }}</view>
                    </view>
                    <view class="points">{{ task.user_charge_points || '0.00' }}点</view>
                </view>
                <view class="script">{{ task.script_text || task.error || '暂无文案' }}</view>
            </view>

            <view class="info-card">
                <view class="info-title">详细信息</view>
                <view class="info-grid">
                    <view class="info-item">
                        <text>任务ID</text>
                        <strong>{{ task.id || task.task_id || '-' }}</strong>
                    </view>
                    <view class="info-item">
                        <text>音频时长</text>
                        <strong>{{ task.duration || 0 }}秒</strong>
                    </view>
                    <view class="info-item">
                        <text>画面比例</text>
                        <strong>{{ task.ratio || '-' }}</strong>
                    </view>
                    <view class="info-item">
                        <text>清晰度</text>
                        <strong>{{ task.quality || '-' }}</strong>
                    </view>
                    <view class="info-item">
                        <text>尺寸</text>
                        <strong>{{ sizeText }}</strong>
                    </view>
                    <view class="info-item">
                        <text>创建时间</text>
                        <strong>{{ formatTime(task.create_time) }}</strong>
                    </view>
                </view>
            </view>

            <view v-if="task.error" class="error-card">
                <view class="info-title">失败原因</view>
                <view class="error-text">{{ task.error }}</view>
            </view>

            <view class="action-card">
                <!-- #ifdef MP-WEIXIN -->
                <button class="primary-btn" :disabled="!videoUrl" @click="downloadVideo">
                    <u-icon name="download" color="#050505" size="30"></u-icon>
                    <text>下载视频</text>
                </button>
                <!-- #endif -->
                <!-- #ifndef MP-WEIXIN -->
                <button class="primary-btn" :disabled="!videoUrl" @click="copyLink">
                    <u-icon name="file-text" color="#050505" size="30"></u-icon>
                    <text>复制链接</text>
                </button>
                <!-- #endif -->
                <button class="ghost-btn" @click="reuseCurrent">
                    <u-icon name="reload" color="#ffffff" size="30"></u-icon>
                    <text>重新合成</text>
                </button>
                <button class="danger-btn" @click="deleteRecord">
                    <u-icon name="trash" color="#ff6b78" size="30"></u-icon>
                    <text>删除记录</text>
                </button>
            </view>

            <view class="bottom-space"></view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import {
    deleteAigcDigitalHumanResult,
    getAigcDigitalHumanTask
} from '@/apps/aigc_digital_human/api'

const taskId = ref(0)
const task = ref<any>({})
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
const firstResult = computed(() => (task.value.results || [])[0] || {})
const videoUrl = computed(() => task.value.video_url || firstResult.value.video_url || '')
const sizeText = computed(() => {
    const width = task.value.width || firstResult.value.width || 0
    const height = task.value.height || firstResult.value.height || 0
    return width && height ? `${width}x${height}` : '-'
})

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
    if (!taskId.value) return
    task.value = await getAigcDigitalHumanTask({ id: taskId.value })
}

const goBack = () => uni.navigateBack()

const reuseCurrent = () => {
    uni.setStorageSync('aigc_digital_human_reuse', {
        script_text: task.value.script_text || '参考这条作品继续生成同风格数字人口播视频',
        avatar_id: task.value.avatar_id || 0,
        voice_id: task.value.voice_id || 0,
        ratio: task.value.ratio || '9:16',
        quality: task.value.quality || '1k',
        channel: task.value.channel || 'master'
    })
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/index/index' })
}

const copyLink = () => {
    if (!videoUrl.value) return uni.$u.toast('暂无可复制的视频链接')
    uni.setClipboardData({
        data: videoUrl.value,
        success: () => uni.$u.toast('链接已复制')
    })
}

const downloadVideo = () => {
    if (!videoUrl.value) return uni.$u.toast('视频生成后可下载')
    // #ifdef MP-WEIXIN
    uni.showLoading({ title: '下载中' })
    uni.downloadFile({
        url: videoUrl.value,
        success: (res) => {
            if (res.statusCode !== 200 || !res.tempFilePath) {
                uni.$u.toast('下载失败')
                return
            }
            uni.saveVideoToPhotosAlbum({
                filePath: res.tempFilePath,
                success: () => uni.$u.toast('已保存到相册'),
                fail: () => uni.$u.toast('保存失败，请检查相册权限')
            })
        },
        fail: () => uni.$u.toast('下载失败'),
        complete: () => uni.hideLoading()
    })
    // #endif
}

const deleteRecord = () => {
    uni.showModal({
        title: '删除记录',
        content: '删除后将不在创作记录中展示，确认删除吗？',
        confirmColor: '#ffffff',
        success: async (res) => {
            if (!res.confirm) return
            await deleteAigcDigitalHumanResult({ task_id: task.value.id || taskId.value })
            uni.$u.toast('删除成功')
            setTimeout(() => uni.navigateBack(), 300)
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

const formatTime = (time?: number | string) => {
    if (!time) return '-'
    const date = new Date(Number(time) * 1000)
    if (Number.isNaN(date.getTime())) return '-'
    const month = `${date.getMonth() + 1}`.padStart(2, '0')
    const day = `${date.getDate()}`.padStart(2, '0')
    const hour = `${date.getHours()}`.padStart(2, '0')
    const minute = `${date.getMinutes()}`.padStart(2, '0')
    return `${month}-${day} ${hour}:${minute}`
}

initNavMetrics()
onLoad((options: any) => {
    taskId.value = Number(options?.task_id || options?.id || 0)
})
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
.hero-card,
.info-card,
.error-card,
.action-card {
    margin-bottom: 22rpx;
    padding: 22rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 26rpx;
    background: rgba(23, 23, 25, 0.96);
}
.video-box {
    position: relative;
    overflow: hidden;
    height: 760rpx;
    border-radius: 22rpx;
    background: #101012;
}
.video,
.video-empty {
    width: 100%;
    height: 100%;
}
.video-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 24rpx;
}
.video-empty__icon {
    font-size: 52rpx;
    font-weight: 900;
}
.status-badge {
    position: absolute;
    left: 18rpx;
    top: 18rpx;
    padding: 8rpx 16rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.88);
    color: #08080a;
    font-size: 22rpx;
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
.title-row {
    display: flex;
    justify-content: space-between;
    gap: 20rpx;
    margin-top: 24rpx;
}
.title {
    font-size: 34rpx;
    font-weight: 900;
}
.subtitle {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.5);
    font-size: 24rpx;
}
.points {
    flex: none;
    color: rgba(255, 255, 255, 0.78);
    font-size: 28rpx;
    font-weight: 900;
}
.script {
    margin-top: 22rpx;
    color: rgba(255, 255, 255, 0.66);
    font-size: 26rpx;
    line-height: 1.55;
}
.info-title {
    margin-bottom: 18rpx;
    font-size: 30rpx;
    font-weight: 900;
}
.info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16rpx;
}
.info-item {
    min-height: 108rpx;
    padding: 18rpx;
    border-radius: 18rpx;
    background: rgba(255, 255, 255, 0.06);
}
.info-item text {
    color: rgba(255, 255, 255, 0.44);
    font-size: 22rpx;
}
.info-item strong {
    display: block;
    overflow: hidden;
    margin-top: 12rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 25rpx;
}
.error-text {
    color: #ff9aa3;
    font-size: 25rpx;
    line-height: 1.55;
}
.action-card {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16rpx;
}
.primary-btn,
.ghost-btn,
.danger-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10rpx;
    height: 88rpx;
    border: 0;
    border-radius: 18rpx;
    font-size: 28rpx;
    font-weight: 900;
}
.primary-btn {
    background: #fff;
    color: #050505;
}
.primary-btn[disabled] {
    opacity: 0.45;
}
.ghost-btn {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}
.danger-btn {
    background: rgba(255, 77, 93, 0.1);
    color: #ff6b78;
}
.bottom-space {
    height: calc(38rpx + env(safe-area-inset-bottom));
}
</style>
