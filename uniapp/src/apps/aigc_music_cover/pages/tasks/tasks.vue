<template>
    <view class="page"
        ><view class="topbar" :style="topbarStyle"
            ><view class="nav-row" :style="navRowStyle"
                ><view class="nav-button" @click="goBack"
                    ><u-icon name="arrow-left" color="#111" size="38" /></view
                ><view class="topbar-title" :style="titleStyle">创作记录</view
                ><view class="nav-placeholder" :style="navPlaceholderStyle" /></view></view
        ><scroll-view class="content" scroll-y :show-scrollbar="false"
            ><view class="tabs"
                ><text
                    v-for="tab in tabs"
                    :key="tab.key"
                    :class="{ active: activeTab === tab.key }"
                    @click="activeTab = tab.key"
                    >{{ tab.label }}</text
                ></view
            ><view class="tab-line" /><view class="toolbar"
                ><view class="play-all" @click="playAll">▶</view><text>播放全部</text
                ><text class="list-icon">☷</text></view
            ><view v-if="loading && !tasks.length" class="empty">正在加载...</view
            ><view v-else-if="!filteredTasks.length" class="empty"
                >暂无{{ tabs.find((t) => t.key === activeTab)?.label || '' }}记录</view
            ><view v-for="(task, index) in filteredTasks" :key="task.id" class="task-row"
                ><text class="index">{{ index + 1 }}</text
                ><view class="task-main"
                    ><text class="task-title">{{ task.title || '音乐翻唱' }}</text
                    ><text class="task-date">{{ task.created_at || '' }}</text></view
                ><view class="row-actions"
                    ><text
                        v-if="task.results?.[0]?.audio_url"
                        @click="playResult(task.results[0])"
                        >{{
                            currentPlaying === (task.results[0].id || task.results[0].audio_url)
                                ? 'Ⅱ'
                                : '▶'
                        }}</text
                    ><text v-if="task.results?.[0]?.audio_url" @click="download(task.results[0])"
                        >⇩</text
                    ><text @click="remove(task)">♧</text></view
                ></view
            ><view class="more">— 没有更多了 —</view><view class="bottom-space" /></scroll-view
    ></view>
</template>
<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import {
    deleteMusicCoverTask,
    getMusicCoverTasks,
    retryMusicCoverTask
} from '@/apps/aigc_music_cover/api'
const tasks = ref<any[]>([])
const loading = ref(false)
const activeTab = ref('success')
const currentPlaying = ref<any>(null)
const tabs = [
    { key: 'running', label: '创作中' },
    { key: 'success', label: '已完成' },
    { key: 'failed', label: '创作失败' }
]
let timer: ReturnType<typeof setInterval> | undefined
let player: UniApp.InnerAudioContext | undefined
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const status = (t: any) => String(t?.status || '').toLowerCase()
const filteredTasks = computed(() =>
    tasks.value.filter((t) =>
        activeTab.value === 'running'
            ? ['pending', 'running'].includes(status(t))
            : activeTab.value === 'failed'
            ? ['failed', 'canceled', 'cancelled'].includes(status(t))
            : status(t) === 'success'
    )
)
const load = async () => {
    loading.value = true
    try {
        const data: any = await getMusicCoverTasks({ page_no: 1, page_size: 30 })
        tasks.value = data?.lists || []
        if (tasks.value.some((t) => ['pending', 'running'].includes(status(t))) && !timer)
            timer = setInterval(load, 8000)
    } finally {
        loading.value = false
    }
}
const playResult = (item: any) => {
    const id = item?.id || item?.audio_url
    if (!item?.audio_url) return uni.$u.toast('音频地址不可用')
    if (currentPlaying.value === id && player) {
        player.pause()
        currentPlaying.value = null
        return
    }
    player?.stop()
    player?.destroy()
    player = uni.createInnerAudioContext()
    player.src = item.audio_url
    currentPlaying.value = id
    player.onEnded(() => {
        currentPlaying.value = null
    })
    player.onError(() => {
        currentPlaying.value = null
        uni.$u.toast('播放失败')
    })
    player.play()
}
const playAll = () => {
    const item = filteredTasks.value.find((t) => t.results?.[0]?.audio_url)?.results?.[0]
    if (item) playResult(item)
}
const download = (item: any) => {
    if (item?.download_url || item?.audio_url)
        uni.setClipboardData({
            data: item.download_url || item.audio_url,
            success: () => uni.$u.toast('音频地址已复制')
        })
}
const remove = (task: any) =>
    uni.showModal({
        title: '删除记录',
        content: '删除后将不再显示，是否继续？',
        success: async (r) => {
            if (!r.confirm) return
            await deleteMusicCoverTask({ id: Number(task.id || task.task_id) })
            uni.$u.toast('已删除')
            load()
        }
    })
const retry = async (task: any) => {
    await retryMusicCoverTask({ id: Number(task.id || task.task_id) })
    load()
}
const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) return uni.navigateBack({ delta: 1 })
    uni.switchTab({ url: '/pages/index/index' })
}
const topbarStyle = computed(() => ({ height: `${navMetrics.navHeight}px` }))
const navRowStyle = computed(() => ({
    top: `${navMetrics.menuTop}px`,
    height: `${navMetrics.menuHeight}px`
}))
const titleStyle = computed(() => ({
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
    const i = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = i.statusBarHeight || 24
    /* #ifdef MP-WEIXIN */ const m = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = m.top
    navMetrics.menuHeight = m.height
    navMetrics.menuWidth = i.windowWidth - m.left
    navMetrics.navHeight = m.top + m.height + 10 /* #endif */
}
initNavMetrics()
onShow(load)
onMounted(initNavMetrics)
onUnmounted(() => {
    if (timer) clearInterval(timer)
    player?.destroy()
})
</script>
<style scoped lang="scss">
.page {
    min-height: 100vh;
    background: #fff;
    color: #090909;
}
.topbar {
    position: relative;
    background: #fff;
}
.nav-row {
    position: absolute;
    left: 28rpx;
    right: 28rpx;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.nav-button {
    width: 72rpx;
    height: 100%;
    display: flex;
    align-items: center;
}
.topbar-title {
    position: absolute;
    top: 0;
    bottom: 0;
    text-align: center;
    font-size: 32rpx;
    font-weight: 700;
}
.content {
    height: calc(100vh - 112rpx);
    padding: 18rpx 40rpx;
    box-sizing: border-box;
}
.tabs {
    display: flex;
    gap: 42rpx;
    align-items: center;
    margin: 0 0 8rpx;
    font-size: 31rpx;
    color: #999;
}
.tabs text.active {
    color: #000;
    font-weight: 700;
    border-bottom: 6rpx solid #111;
    padding-bottom: 12rpx;
}
.tab-line {
    height: 4rpx;
    width: 100%;
    margin-bottom: 24rpx;
    background: #f2f2f2;
}
.toolbar {
    display: flex;
    align-items: center;
    gap: 20rpx;
    font-size: 30rpx;
    font-weight: 700;
}
.play-all {
    width: 68rpx;
    height: 68rpx;
    border-radius: 50%;
    background: #36df91;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
}
.list-icon {
    margin-left: auto;
    color: #999;
    font-size: 46rpx;
}
.task-row {
    display: flex;
    align-items: center;
    gap: 18rpx;
    padding: 30rpx 0;
}
.index {
    color: #999;
    font-size: 28rpx;
    width: 30rpx;
}
.task-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 6rpx;
}
.task-title {
    font-size: 30rpx;
    font-weight: 700;
}
.task-date {
    color: #999;
    font-size: 24rpx;
}
.row-actions {
    display: flex;
    gap: 24rpx;
    font-size: 38rpx;
}
.empty {
    text-align: center;
    color: #aaa;
    padding: 100rpx 0;
}
.more {
    text-align: center;
    color: #aaa;
    font-size: 24rpx;
    padding: 34rpx 0;
}
.bottom-space {
    height: 40rpx;
}
</style>
