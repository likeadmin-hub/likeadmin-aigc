<template>
    <view class="page">
        <view class="head">
            <view class="back" @click="goBack">‹</view>
            <view class="title">剪辑任务</view>
            <view class="refresh" @click="getData">刷新</view>
        </view>
        <view v-for="item in lists" :key="item.id" class="item">
            <view class="top">
                <text>{{ item.title || typeText(item.clip_type) }}</text>
                <text :class="`status is-${item.status}`">{{ statusText(item.status) }}</text>
            </view>
            <view class="meta">{{ typeText(item.clip_type) }} · {{ item.duration || 0 }}秒 · {{ item.user_charge_points || 0 }}点</view>
            <view v-if="item.error" class="error">{{ item.error }}</view>
        </view>
        <view v-if="!lists.length" class="empty">暂无任务</view>
    </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getSmartClipTasks } from '@/apps/smart_clip/api'

const lists = ref<any[]>([])
const getData = async () => {
    const rows: any = await getSmartClipTasks()
    lists.value = Array.isArray(rows) ? rows : []
}
const goBack = () => uni.navigateBack()
const typeText = (type: string) => ({ realman_broadcast: '真人口播', broadcast_mixcut: '素材混剪', news_mixcut: '新闻体' }[type] || type || '-')
const statusText = (status: string) => ({ pending: '排队中', running: '剪辑中', success: '已完成', failed: '失败', canceled: '已取消' }[status] || status || '-')
onShow(getData)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; padding: var(--status-bar-height) 24rpx 24rpx; background: #050505; color: #fff; }
.head { display: flex; align-items: center; justify-content: space-between; height: 92rpx; }
.back { width: 88rpx; font-size: 56rpx; color: rgba(255,255,255,.72); }
.title { font-size: 32rpx; font-weight: 700; }
.refresh { width: 88rpx; text-align: right; color: rgba(255,255,255,.68); font-size: 24rpx; }
.item { padding: 24rpx; margin-bottom: 18rpx; border: 1rpx solid rgba(255,255,255,.08); background: #101012; border-radius: 16rpx; }
.top { display: flex; justify-content: space-between; gap: 18rpx; font-size: 28rpx; font-weight: 700; }
.status { flex: none; font-size: 23rpx; color: rgba(255,255,255,.64); }
.status.is-success { color: #7ee2a8; }
.status.is-failed { color: #ff8a8a; }
.status.is-running, .status.is-pending { color: #ffd37a; }
.meta { margin-top: 12rpx; color: rgba(255,255,255,.54); font-size: 24rpx; }
.error { margin-top: 12rpx; color: #ff8a8a; font-size: 23rpx; }
.empty { display: flex; align-items: center; justify-content: center; height: 520rpx; color: rgba(255,255,255,.48); }
</style>
