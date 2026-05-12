<template>
    <view class="page">
        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="42"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">{{
                    source === 'mine' ? '我的克隆形象' : '官方形象'
                }}</view>
            </view>
        </view>

        <template v-if="source === 'official'">
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
                <view v-if="lists.length" class="avatar-grid">
                    <view
                        v-for="item in lists"
                        :key="item.id"
                        class="avatar-card"
                        @click="selectAvatar(item)"
                    >
                        <image
                            v-if="item.cover_url || item.media_url"
                            class="avatar-img"
                            :src="item.cover_url || item.media_url"
                            mode="aspectFill"
                        />
                        <view v-else class="avatar-empty">A</view>
                        <view class="vip-tag">VIP</view>
                        <view class="avatar-name">{{ item.name }}</view>
                    </view>
                </view>
                <view v-else class="empty">
                    <view class="empty-title">暂无官方形象</view>
                    <view class="empty-desc">可先创建一个克隆形象用于合成</view>
                    <button class="empty-btn" @click="goCloneAvatar">去克隆</button>
                </view>
            </scroll-view>
        </template>

        <template v-else>
            <view class="mine-tabs">
                <view class="mine-tab is-active">我的形象</view>
                <view class="mine-tab">创作记录</view>
            </view>
            <scroll-view
                class="content"
                scroll-y
                refresher-enabled
                :refresher-triggered="refreshing"
                @refresherrefresh="refreshData"
            >
                <view class="create-card" @click="chooseAvatarImage">
                    <view class="create-icon">+</view>
                    <view class="create-title">创建我的形象</view>
                    <view class="create-desc">上传照片，生成当前用户专属数字人形象</view>
                </view>

                <view class="section-title">我的形象</view>
                <view v-if="lists.length" class="mine-list">
                    <view
                        v-for="item in lists"
                        :key="item.id"
                        class="mine-card"
                        @click="selectAvatar(item)"
                    >
                        <image
                            v-if="item.cover_url || item.media_url"
                            class="mine-cover"
                            :src="item.cover_url || item.media_url"
                            mode="aspectFill"
                        />
                        <view v-else class="mine-cover mine-cover--empty">我</view>
                        <view class="mine-info">
                            <view class="mine-name">{{ item.name }}</view>
                            <view class="mine-meta">{{
                                item.status === 'ready' ? '可用于合成' : item.status
                            }}</view>
                        </view>
                        <u-icon name="arrow-right" color="#a4adba" size="26"></u-icon>
                    </view>
                </view>
                <view v-else class="empty empty--mine">
                    <view class="empty-title">还没有克隆形象</view>
                    <view class="empty-desc">创建后会自动带回创作页</view>
                </view>
            </scroll-view>
        </template>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { uploadImage } from '@/api/app'
import {
    getAigcDigitalHumanAvatars,
    saveAigcDigitalHumanAvatar
} from '@/apps/aigc_digital_human/api'

const lists = ref<any[]>([])
const source = ref('official')
const category = ref('全部')
const categories = ['全部', '真人', '商务', '卡通', '艺术']
const refreshing = ref(false)
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
    lists.value = await getAigcDigitalHumanAvatars({ source: source.value })
}

const refreshData = async () => {
    refreshing.value = true
    try {
        await getData()
    } finally {
        refreshing.value = false
    }
}

const goCloneAvatar = () => {
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/avatar/avatar' })
}

const selectAvatar = (item: any) => {
    uni.setStorageSync('aigc_digital_human_selected_avatar', item)
    uni.navigateBack()
}

const chooseAvatarImage = async () => {
    try {
        const chooseRes: any = await uni.chooseImage({
            count: 1,
            sizeType: ['compressed'],
            sourceType: ['album', 'camera']
        })
        const path = chooseRes?.tempFilePaths?.[0]
        if (!path) return
        uni.showLoading({ title: '上传中...' })
        const res: any = await uploadImage(path)
        const uri = res?.uri || res?.url || res?.path
        const count = `${lists.value.filter((item) => item.source === 'mine').length + 1}`.padStart(
            2,
            '0'
        )
        const row: any = await saveAigcDigitalHumanAvatar({
            name: `我的数字人形象 ${count}`,
            cover_uri: uri,
            media_uri: uri,
            media_type: 'video'
        })
        uni.setStorageSync('aigc_digital_human_selected_avatar', row)
        uni.$u.toast('形象已创建')
        uni.navigateBack()
    } catch (error: any) {
        if (!String(error?.errMsg || error).includes('cancel')) uni.$u.toast(error || '上传失败')
    } finally {
        uni.hideLoading()
    }
}

const goBack = () => uni.navigateBack()

initNavMetrics()
onLoad((options: any) => {
    if (options?.source === 'mine') {
        uni.redirectTo({ url: '/apps/aigc_digital_human/pages/clone/avatar/avatar' })
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

.tabs,
.mine-tabs {
    display: flex;
    gap: 10rpx;
    margin: 18rpx 28rpx 12rpx;
    padding: 8rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 22rpx;
    background: rgba(255, 255, 255, 0.035);
    overflow: hidden;
}

.tab,
.mine-tab {
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

.tab.is-active,
.mine-tab.is-active {
    background: #e5f0ff;
    color: #2f80ff;
}

.content {
    box-sizing: border-box;
    height: calc(100vh - 170rpx);
    padding: 18rpx 28rpx 34rpx;
}

.avatar-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18rpx;
}

.avatar-card {
    position: relative;
    overflow: hidden;
    border: 1px solid #e1e8f2;
    border-radius: 18rpx;
    background: #ffffff;
    box-shadow: 0 12rpx 32rpx rgba(41, 75, 120, 0.06);
}

.avatar-img,
.avatar-empty {
    width: 100%;
    height: 210rpx;
    background: #edf2f7;
}

.avatar-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2f80ff;
    font-size: 42rpx;
    font-weight: 900;
}

.vip-tag {
    position: absolute;
    top: 10rpx;
    left: 10rpx;
    padding: 4rpx 10rpx;
    border-radius: 999rpx;
    background: rgba(47, 128, 255, 0.92);
    color: #ffffff;
    font-size: 18rpx;
    font-weight: 900;
}

.avatar-name {
    overflow: hidden;
    padding: 14rpx 8rpx;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 23rpx;
    font-weight: 800;
}

.create-card,
.mine-card,
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
    min-height: 260rpx;
}

.create-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 82rpx;
    height: 82rpx;
    border-radius: 50%;
    background: linear-gradient(90deg, #38a5ff 0%, #4c6dff 100%);
    color: #ffffff;
    font-size: 46rpx;
}

.create-title,
.section-title,
.empty-title {
    color: #172033;
    font-size: 30rpx;
    font-weight: 900;
}

.create-title {
    margin-top: 20rpx;
}

.create-desc,
.mine-meta,
.empty-desc {
    margin-top: 10rpx;
    color: #8a93a3;
    font-size: 24rpx;
}

.section-title {
    margin: 30rpx 0 18rpx;
}

.mine-list {
    display: grid;
    gap: 16rpx;
}

.mine-card {
    display: flex;
    align-items: center;
    gap: 18rpx;
    padding: 18rpx;
}

.mine-cover {
    flex: none;
    width: 96rpx;
    height: 96rpx;
    border-radius: 16rpx;
    background: #edf2f7;
}

.mine-cover--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2f80ff;
    font-size: 32rpx;
    font-weight: 900;
}

.mine-info {
    flex: 1;
    min-width: 0;
}

.mine-name {
    overflow: hidden;
    color: #172033;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 27rpx;
    font-weight: 900;
}

.empty {
    padding: 90rpx 30rpx;
    text-align: center;
}

.empty--mine {
    margin-top: 16rpx;
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
.section-title,
.empty-title,
.mine-name {
    color: #ffffff;
}

.tab,
.mine-tab {
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.62);
}

.tab.is-active,
.mine-tab.is-active,
.empty-btn {
    border-color: rgba(255, 255, 255, 0.26);
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
    box-shadow: inset 0 1rpx 0 rgba(255, 255, 255, 0.14), 0 10rpx 22rpx rgba(0, 0, 0, 0.2);
}

.avatar-card,
.create-card,
.mine-card,
.empty {
    border-color: rgba(255, 255, 255, 0.08);
    background: rgba(34, 34, 34, 0.96);
}

.avatar-img,
.avatar-empty,
.mine-cover,
.mine-cover--empty {
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
    color: #ffffff;
}

.create-desc,
.mine-meta,
.empty-desc {
    color: rgba(255, 255, 255, 0.52);
}
</style>
