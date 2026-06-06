<template>
    <view class="page">
        <view class="head">
            <view class="back" @click="goBack">‹</view>
            <view class="title">作品列表</view>
            <view class="refresh" @click="getData">刷新</view>
        </view>

        <view class="tabs">
            <view v-for="item in statusTabs" :key="item.value" class="tab" :class="{ 'is-active': status === item.value }" @click="switchStatus(item.value)">
                {{ item.label }}
            </view>
        </view>

        <scroll-view class="content" scroll-y refresher-enabled :refresher-triggered="refreshing" @refresherrefresh="refreshData">
            <view v-for="item in lists" :key="item.task_id || item.id" class="card">
                <view class="delete" @click.stop="handleDelete(item.task_id || item.id)">删除</view>
                <video v-if="item.video_url" class="video" :src="item.video_url" controls object-fit="cover" />
                <view v-else class="placeholder" :class="`is-${item.status}`">{{ statusText(item.status) }}</view>
                <view class="body">
                    <view class="top">
                        <text>{{ item.title || typeText(item.clip_type) }}</text>
                        <text class="muted">{{ formatTime(item.create_time) }}</text>
                    </view>
                    <view class="meta">{{ typeText(item.clip_type) }} · {{ item.duration || 0 }}秒 · {{ item.user_charge_points || 0 }}点</view>
                    <view class="actions">
                        <view class="action" @click="reuseResult(item)">再次剪辑</view>
                    </view>
                </view>
            </view>

            <view v-if="!lists.length" class="empty">
                <view class="empty-title">暂无作品</view>
                <view class="empty-desc">剪辑完成后的视频会保存在这里</view>
                <button class="empty-button" @click="goCreate">去创作</button>
            </view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onHide, onShow, onUnload } from '@dcloudio/uni-app'
import { deleteSmartClipResult, getSmartClipResults } from '@/apps/smart_clip/api'

const lists = ref<any[]>([])
const status = ref('')
const refreshing = ref(false)
let pollTimer: ReturnType<typeof setInterval> | null = null
const statusTabs = [
    { label: '全部', value: '' },
    { label: '剪辑中', value: 'running' },
    { label: '成功', value: 'success' },
    { label: '失败', value: 'failed' },
]

const getData = async () => {
    const rows: any = await getSmartClipResults(status.value ? { status: status.value } : undefined)
    lists.value = Array.isArray(rows) ? rows : []
    lists.value.some((item) => item.status === 'running') ? startPolling() : stopPolling()
}
const refreshData = async () => {
    refreshing.value = true
    try {
        await getData()
    } finally {
        refreshing.value = false
    }
}
const startPolling = () => {
    if (pollTimer) return
    pollTimer = setInterval(getData, 5000)
}
const stopPolling = () => {
    if (!pollTimer) return
    clearInterval(pollTimer)
    pollTimer = null
}
const switchStatus = (value: string) => {
    status.value = value
    getData()
}
const goBack = () => {
    const pages = getCurrentPages()
    pages.length > 1 ? uni.navigateBack() : goCreate()
}
const goCreate = () => uni.navigateTo({ url: '/apps/smart_clip/pages/index/index' })
const reuseResult = (item: any) => {
    uni.setStorageSync('smart_clip_reuse', {
        api: item.clip_type,
        title: item.title,
        styleId: item.style_id,
        duration: item.duration,
    })
    goCreate()
}
const handleDelete = async (id: number) => {
    uni.showModal({
        title: '删除作品',
        content: '确认删除该剪辑作品？',
        confirmColor: '#ffffff',
        success: async (res) => {
            if (!res.confirm) return
            await deleteSmartClipResult({ id, task_id: id })
            uni.$u.toast('删除成功')
            getData()
        },
    })
}
const typeText = (type: string) => ({ realman_broadcast: '真人口播', broadcast_mixcut: '素材混剪', news_mixcut: '新闻体' }[type] || type || '-')
const statusText = (value: string) => ({ success: '已完成', failed: '剪辑失败', running: '剪辑中', pending: '排队中' }[value] || value || '剪辑中')
const formatTime = (time?: number | string) => {
    const value = Number(time || 0)
    if (!value) return '刚刚'
    const date = new Date(value > 9999999999 ? value : value * 1000)
    return `${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

onShow(getData)
onHide(stopPolling)
onUnload(stopPolling)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; padding: var(--status-bar-height) 24rpx 24rpx; background: #050505; color: #fff; box-sizing: border-box; }
.head { display: flex; align-items: center; justify-content: space-between; height: 92rpx; }
.back { width: 88rpx; font-size: 56rpx; color: rgba(255,255,255,.72); }
.title { font-size: 32rpx; font-weight: 700; }
.refresh { width: 88rpx; text-align: right; color: rgba(255,255,255,.68); font-size: 24rpx; }
.tabs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12rpx; margin: 10rpx 0 22rpx; }
.tab { height: 64rpx; line-height: 64rpx; text-align: center; border-radius: 999rpx; background: #171719; color: rgba(255,255,255,.62); font-size: 24rpx; }
.tab.is-active { background: #fff; color: #050505; font-weight: 700; }
.content { height: calc(100vh - var(--status-bar-height) - 190rpx); }
.card { position: relative; overflow: hidden; margin-bottom: 20rpx; border: 1rpx solid rgba(255,255,255,.08); border-radius: 18rpx; background: #101012; }
.delete { position: absolute; top: 16rpx; right: 16rpx; z-index: 2; padding: 10rpx 16rpx; border-radius: 999rpx; background: rgba(0,0,0,.55); color: rgba(255,255,255,.8); font-size: 22rpx; }
.video, .placeholder { width: 100%; height: 420rpx; background: #06070a; }
.placeholder { display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.66); font-size: 28rpx; font-weight: 700; }
.placeholder.is-failed { color: #ff8a8a; }
.body { padding: 20rpx; }
.top { display: flex; justify-content: space-between; gap: 16rpx; font-size: 28rpx; font-weight: 700; }
.muted, .meta { color: rgba(255,255,255,.52); font-size: 23rpx; font-weight: 400; }
.meta { margin-top: 12rpx; }
.actions { display: flex; margin-top: 18rpx; }
.action { height: 58rpx; line-height: 58rpx; padding: 0 24rpx; border-radius: 999rpx; background: #222; color: #fff; font-size: 24rpx; }
.empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 680rpx; color: rgba(255,255,255,.54); }
.empty-title { color: #fff; font-size: 30rpx; font-weight: 700; }
.empty-desc { margin-top: 12rpx; font-size: 24rpx; }
.empty-button { margin-top: 28rpx; width: 220rpx; height: 72rpx; border-radius: 14rpx; background: #fff; color: #050505; font-size: 26rpx; font-weight: 700; }
</style>
