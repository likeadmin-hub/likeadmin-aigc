<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">克隆形象</view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="hero-card" @click="chooseAvatarVideo">
                <view class="hero-icon">
                    <u-icon name="play-circle" color="#ffffff" size="54"></u-icon>
                </view>
                <view class="hero-title">创建我的形象</view>
                <view class="hero-desc">上传一段正面清晰视频，生成当前用户专属数字人形象</view>
                <button
                    class="primary-btn"
                    :loading="uploading"
                    :disabled="uploading"
                    @click.stop="chooseAvatarVideo"
                >
                    上传视频
                </button>
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
                    <u-icon name="arrow-right" color="rgba(255,255,255,0.46)" size="26"></u-icon>
                </view>
            </view>
            <view v-else class="empty">
                <view class="empty-title">还没有克隆形象</view>
                <view class="empty-desc">创建成功后会自动带回创作页</view>
            </view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { uploadVideo } from '@/api/app'
import {
    getAigcDigitalHumanAvatars,
    saveAigcDigitalHumanAvatar
} from '@/apps/aigc_digital_human/api'

const lists = ref<any[]>([])
const uploading = ref(false)
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
    lists.value = await getAigcDigitalHumanAvatars({ source: 'mine' })
}

const selectAvatar = (item: any) => {
    uni.setStorageSync('aigc_digital_human_selected_avatar', item)
    uni.navigateBack()
}

const chooseVideoPath = async () => {
    // #ifdef MP-WEIXIN
    if (typeof (uni as any).chooseMedia === 'function') {
        const res: any = await (uni as any).chooseMedia({
            count: 1,
            mediaType: ['video'],
            sourceType: ['album', 'camera'],
            maxDuration: 60,
            camera: 'back'
        })
        return res?.tempFiles?.[0]?.tempFilePath || res?.tempFiles?.[0]?.path || ''
    }
    // #endif
    const chooseRes: any = await uni.chooseVideo({
        sourceType: ['album', 'camera'],
        compressed: true,
        maxDuration: 60
    })
    return chooseRes?.tempFilePath || ''
}

const chooseAvatarVideo = async () => {
    if (uploading.value) return
    try {
        const path = await chooseVideoPath()
        if (!path) return
        uploading.value = true
        uni.showLoading({ title: '上传中...' })
        const res: any = await uploadVideo(path)
        const uri = res?.uri || res?.url || res?.path
        if (!uri) {
            uni.$u.toast('视频上传失败')
            return
        }
        const count = `${lists.value.length + 1}`.padStart(2, '0')
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
        if (!String(error?.errMsg || error).includes('cancel'))
            uni.$u.toast(error?.errMsg || error || '上传失败')
    } finally {
        uploading.value = false
        uni.hideLoading()
    }
}

const goBack = () => uni.navigateBack()

initNavMetrics()
onShow(getData)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #ffffff;
}

.page-bg {
    position: fixed;
    inset: 0;
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
}

.topbar,
.content {
    position: relative;
    z-index: 1;
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
    width: 72rpx;
    height: 100%;
}

.page-title {
    color: #ffffff;
    font-size: 32rpx;
    font-weight: 800;
}

.content {
    box-sizing: border-box;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 28rpx 32rpx 48rpx;
}

.hero-card,
.mine-card,
.empty {
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 24rpx;
    background: rgba(34, 34, 34, 0.96);
}

.hero-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 54rpx 34rpx;
}

.hero-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 112rpx;
    height: 112rpx;
    border-radius: 50%;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}

.hero-title,
.section-title,
.empty-title {
    color: #ffffff;
    font-size: 31rpx;
    font-weight: 800;
}

.hero-title {
    margin-top: 24rpx;
}

.hero-desc,
.mine-meta,
.empty-desc {
    margin-top: 12rpx;
    color: rgba(255, 255, 255, 0.54);
    font-size: 24rpx;
    line-height: 1.5;
    text-align: center;
}

.primary-btn {
    width: 260rpx;
    height: 82rpx;
    margin-top: 34rpx;
    border: 0;
    border-radius: 16rpx;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 800;
}

.section-title {
    margin: 42rpx 0 20rpx;
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
    width: 98rpx;
    height: 98rpx;
    border-radius: 16rpx;
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
}

.mine-cover--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 32rpx;
    font-weight: 900;
}

.mine-info {
    flex: 1;
    min-width: 0;
}

.mine-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 800;
}

.mine-meta {
    text-align: left;
}

.empty {
    padding: 72rpx 30rpx;
    text-align: center;
}
</style>
