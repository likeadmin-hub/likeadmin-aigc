<template>
    <view class="page">
        <view class="topbar" :style="topbarStyle"
            ><view class="nav-row" :style="navRowStyle"
                ><view class="nav-button" @click="goBack"><text class="back-arrow" /></view
                ><view class="nav-placeholder" :style="navPlaceholderStyle" /></view
            ><view class="topbar-title" :style="titleStyle">音乐翻唱</view></view
        >
        <scroll-view class="content" scroll-y :show-scrollbar="false">
            <view class="info-banner"
                ><image class="info-banner-image" :src="bottomBackground" mode="aspectFill" />
                <view class="info-banner-overlay"
                    ><view class="info-content"
                        ><text class="info-title">AI音乐翻唱</text
                        ><text class="info-subtitle">让普通人也能成为歌手</text
                        ><text class="info-desc">上传歌曲，选择喜欢的音色，生成专属翻唱作品。</text
                        ><text class="info-desc">支持音乐链接、音频上传和声音克隆。</text></view
                    ></view
                ></view
            >
            <view class="mode-row"
                ><view
                    class="pill"
                    :class="{ active: sourceMode === 'url' }"
                    @click="sourceMode = 'url'"
                    >音乐链接</view
                ><view
                    class="pill"
                    :class="{ active: sourceMode === 'upload' }"
                    @click="sourceMode = 'upload'"
                    >上传音乐</view
                ><view class="search-entry" @click="openSearch">⌕ 搜索音乐</view></view
            >
            <view v-if="sourceMode === 'url'" class="field-box">
                <textarea
                    v-model="source.url"
                    class="url-area"
                    placeholder="在此处输入歌曲链接..."
                    placeholder-class="placeholder"
                />
            </view>
            <view v-else class="upload-box" @click="chooseAudio('source')"
                ><text class="upload-icon">↥</text><text class="upload-title">点击上传音乐</text
                ><text class="upload-sub">上传音乐音频文件</text></view
            >
            <view class="field-box"
                ><input
                    v-model="title"
                    class="text-input"
                    maxlength="120"
                    placeholder="在此处输入创作歌曲名称..."
                    placeholder-class="placeholder"
            /></view>
            <view class="voice-row" @click="goVoices"
                ><text>我的声音</text
                ><view
                    ><text class="voice-name">{{ reference.name || '请选择' }}</text
                    ><text class="chevron">›</text></view
                ></view
            >
            <view v-if="reference.path || reference.url" class="voice-selected"
                ><text>{{ reference.name }}</text
                ><text @click.stop="clearAudio('reference')">移除</text></view
            >
            <view class="setting-row"
                ><text>伴奏分离</text
                ><switch
                    :checked="instrumental"
                    color="#c7ff00"
                    @change="instrumental = $event.detail.value"
            /></view>
            <view class="setting-row"
                ><text>声音降噪</text
                ><view class="stepper"
                    ><text @click="changeDenoise(-0.1)">−</text><view>{{ denoise.toFixed(1) }}</view
                    ><text @click="changeDenoise(0.1)">＋</text></view
                ></view
            >
            <view v-if="message" class="message" :class="{ error: messageType === 'error' }">{{
                message
            }}</view>
            <view class="consent" @click="consent = !consent"
                ><view class="checkbox" :class="{ checked: consent }">{{ consent ? '✓' : '' }}</view
                ><text>我已阅读并同意 <text class="agreement">《平台创作协议》</text></text></view
            >
            <view class="bottom-space" />
        </scroll-view>
        <view class="bottom-bar"
            ><view class="works-entry" @click="goTasks"
                ><text class="works-icon">✎</text><text>我的作品</text></view
            ><view class="action-stack"
                ><button
                    class="submit-button"
                    :disabled="submitting || !canSubmit"
                    :loading="submitting"
                    @click="submit"
                >
                    {{ submitting ? '提交中...' : '开始创作' }}</button
                ><text class="cost">预计消耗 {{ displayPoints }} 算力点 / 次</text></view
            ></view
        >
        <view v-if="searchVisible" class="search-mask" @click="searchVisible = false"
            ><view class="search-sheet" @click.stop
                ><view class="sheet-title">搜索音乐</view
                ><view class="search-input"
                    ><input
                        v-model="searchQuery"
                        placeholder="输入歌名或歌手"
                        confirm-type="search"
                        @confirm="runMusicSearch"
                    /><button :loading="searchLoading" @click="runMusicSearch">
                        {{ searchLoading ? '搜索中' : '搜索' }}
                    </button></view
                ><view v-if="searchLoading" class="search-empty">正在搜索音乐...</view
                ><view v-else-if="!searchResults.length" class="search-empty"
                    >输入歌名或歌手，搜索可用音乐</view
                ><view v-else class="search-results"
                    ><view
                        v-for="item in searchResults"
                        :key="item.songid || item.url"
                        class="search-result"
                        @click="selectSearchResult(item)"
                        ><view class="result-main"
                            ><text class="result-title">{{ item.title }}</text
                            ><text class="result-author">{{
                                item.author || '未知歌手'
                            }}</text></view
                        ><text class="result-select">选择</text></view
                    ></view
                ></view
            ></view
        >
    </view>
</template>
<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import {
    estimateMusicCover,
    generateMusicCover,
    getMusicCoverConfig,
    searchMusic,
    uploadMusicCoverAudio
} from '@/apps/aigc_music_cover/api'
import bottomBackground from '@/static/images/music-cover/bottom-bg.png'
type AudioInput = { path: string; name: string; url: string }
const title = ref('')
const source = reactive<AudioInput>({ path: '', name: '', url: '' })
const reference = reactive<AudioInput>({ path: '', name: '', url: '' })
const sourceMode = ref<'url' | 'upload'>('url')
const instrumental = ref(true)
const denoise = ref(0.5)
const consent = ref(false)
const submitting = ref(false)
const message = ref('')
const messageType = ref<'error' | 'success'>('error')
const searchVisible = ref(false)
const searchQuery = ref('')
const searchLoading = ref(false)
const searchResults = ref<any[]>([])
const channels = ref<any[]>([])
const selectedChannel = ref<any>({})
const estimatedPoints = ref<number | null>(null)
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const displayPoints = computed(
    () =>
        estimatedPoints.value ??
        selectedChannel.value?.user_charge_points ??
        selectedChannel.value?.tenant_unit_price ??
        selectedChannel.value?.display_points ??
        '--'
)
const canSubmit = computed(() =>
    Boolean(
        consent.value &&
            (source.path || source.url.trim()) &&
            (reference.path || reference.url.trim())
    )
)
const showError = (text: string) => {
    message.value = text
    messageType.value = 'error'
}
const changeDenoise = (step: number) => {
    denoise.value = Math.min(1, Math.max(0, Number((denoise.value + step).toFixed(1))))
}
const clearAudio = (type: 'source' | 'reference') => {
    const target = type === 'source' ? source : reference
    target.path = ''
    target.name = ''
}
const isCancel = (e: any) => /cancel/i.test(String(e?.errMsg || e?.message || e || ''))
const chooseAudio = async (type: 'source' | 'reference') => {
    try {
        let file: any
        /* #ifdef H5 */ file = (await (uni as any).chooseFile({ count: 1, type: 'audio' }))
            ?.tempFiles?.[0]
        /* #endif */ /* #ifdef MP-WEIXIN */ file = (
            await uni.chooseMessageFile({
                count: 1,
                type: 'file',
                extension: ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac']
            })
        )?.tempFiles?.[0]
        /* #endif */ if (!file?.path) return
        const target = type === 'source' ? source : reference
        target.path = file.path
        target.name = file.name || (type === 'source' ? '上传音乐' : '我的声音')
        target.url = ''
        message.value = ''
    } catch (e) {
        if (!isCancel(e)) showError('选择音频失败，请重试')
    }
}
const submit = async () => {
    if (submitting.value || !canSubmit.value) return
    submitting.value = true
    message.value = ''
    uni.showLoading({ title: '提交中...' })
    try {
        const [a, b] = await Promise.all([
            source.path
                ? uploadMusicCoverAudio(source.path, 'cover_source')
                : Promise.resolve({ id: 0 }),
            reference.path
                ? uploadMusicCoverAudio(reference.path, 'cover_reference')
                : Promise.resolve({ id: 0 })
        ])
        await generateMusicCover({
            title: title.value.trim() || '音乐翻唱',
            source_asset_id: Number((a as any)?.id || (a as any)?.asset_id || 0),
            source_url: source.url.trim(),
            reference_asset_id: Number((b as any)?.id || (b as any)?.asset_id || 0),
            reference_url: reference.url.trim(),
            market_product_id: Number(selectedChannel.value?.market_product_id || 0),
            market_sku_id: Number(selectedChannel.value?.market_sku_id || 0),
            channel: selectedChannel.value?.id || selectedChannel.value?.value || '',
            instrumental: instrumental.value ? 1 : 0,
            denoise: denoise.value
        })
        message.value = '作品已提交，可在我的作品中查看'
        messageType.value = 'success'
        clearAudio('source')
        clearAudio('reference')
        source.url = ''
        reference.url = ''
    } catch (e: any) {
        showError(e?.msg || e?.message || '提交失败，请稍后重试')
    } finally {
        submitting.value = false
        uni.hideLoading()
    }
}
const openSearch = () => {
    searchVisible.value = true
}
const runMusicSearch = async () => {
    const keyword = searchQuery.value.trim()
    if (!keyword || searchLoading.value) return
    searchLoading.value = true
    searchResults.value = []
    try {
        const data: any = await searchMusic(keyword)
        searchResults.value = Array.isArray(data?.result?.items) ? data.result.items : []
        if (!searchResults.value.length) uni.$u.toast('未找到相关音乐')
    } catch (e: any) {
        uni.$u.toast(typeof e === 'string' ? e : '音乐搜索失败，请稍后重试')
    } finally {
        searchLoading.value = false
    }
}
const selectSearchResult = (item: any) => {
    if (!item?.url) return uni.$u.toast('该音乐暂无可用播放链接')
    sourceMode.value = 'url'
    source.url = item.url
    if (!title.value.trim()) title.value = item.title || ''
    searchVisible.value = false
}
const goVoices = () => uni.navigateTo({ url: '/apps/aigc_music_cover/pages/voices/voices' })
const loadConfig = async () => {
    try {
        const data: any = await getMusicCoverConfig()
        channels.value = Array.isArray(data?.options) ? data.options : []
        selectedChannel.value = channels.value[0] || {}
        estimatedPoints.value = null
        const channel = selectedChannel.value
        if (channel.market_product_id && channel.market_sku_id) {
            try {
                const quote: any = await estimateMusicCover({
                    market_product_id: Number(channel.market_product_id),
                    market_sku_id: Number(channel.market_sku_id),
                    channel: channel.id || channel.value || ''
                })
                const points = Number(quote?.user_charge_points ?? quote?.display_points)
                estimatedPoints.value = Number.isFinite(points) ? points : null
            } catch {
                // 估价失败时使用配置下发的 SKU 租户售价兜底显示
            }
        }
    } catch (e) {
        showError('加载应用配置失败')
    }
}
const goTasks = () => uni.navigateTo({ url: '/apps/aigc_music_cover/pages/tasks/tasks' })
const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) return uni.navigateBack({ delta: 1 })
    uni.switchTab({ url: '/pages/index/index' })
}
const topbarStyle = computed(() => ({
    top: `${navMetrics.statusBarHeight}px`,
    height: `${Math.max(56, navMetrics.navHeight - navMetrics.statusBarHeight)}px`
}))
const navRowStyle = computed(() => ({
    top: `${Math.max(0, navMetrics.menuTop - navMetrics.statusBarHeight)}px`,
    height: `${navMetrics.menuHeight}px`,
    right: `${navMetrics.menuWidth + 24}px`
}))
const titleStyle = computed(() => ({
    top: `${Math.max(0, navMetrics.menuTop - navMetrics.statusBarHeight)}px`,
    height: `${navMetrics.menuHeight}px`,
    lineHeight: `${navMetrics.menuHeight}px`,
    left: '0',
    right: '0'
}))
const navPlaceholderStyle = computed(() => ({
    width: '136rpx',
    height: `${navMetrics.menuHeight}px`
}))
const onVoiceSelected = (voice: any) => {
    if (!voice) return
    reference.path = voice.path || ''
    reference.name = voice.name || '我的声音'
    reference.url = ''
}
const initNavMetrics = () => {
    const info = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = info.statusBarHeight || 24
    /* #ifdef MP-WEIXIN */ const menu = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = menu.top
    navMetrics.menuHeight = menu.height
    navMetrics.menuWidth = info.windowWidth - menu.left
    navMetrics.navHeight = menu.top + menu.height + 10
    /* #endif */ /* #ifndef MP-WEIXIN */ navMetrics.menuTop = navMetrics.statusBarHeight + 8
    navMetrics.menuHeight = 34
    navMetrics.menuWidth = 88
    navMetrics.navHeight = navMetrics.menuTop + 44 /* #endif */
}
initNavMetrics()
onMounted(() => {
    loadConfig()
    uni.$on('music-cover-voice-selected', onVoiceSelected)
})
onUnmounted(() => uni.$off('music-cover-voice-selected', onVoiceSelected))
</script>
<style scoped lang="scss">
.page {
    min-height: 100vh;
    background: #fff;
    color: #0b0b0b;
}
.topbar {
    position: fixed;
    left: 0;
    right: 0;
    top: 0;
    z-index: 10;
    background: #fff;
}
.nav-row {
    position: absolute;
    left: 28rpx;
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
.nav-placeholder {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}
.topbar-title {
    position: absolute;
    z-index: 1;
    text-align: center;
    font-size: 31rpx;
    font-weight: 700;
    pointer-events: none;
}
.back-arrow {
    display: block;
    width: 22rpx;
    height: 22rpx;
    margin-left: 10rpx;
    border-left: 5rpx solid #111;
    border-bottom: 5rpx solid #111;
    transform: rotate(45deg);
}
.content {
    height: 100vh;
    box-sizing: border-box;
    padding: 170rpx 36rpx 220rpx;
    scrollbar-width: none;
}
.content::-webkit-scrollbar {
    display: none;
    width: 0;
    height: 0;
}
.hero-card {
    display: flex;
    align-items: center;
    gap: 20rpx;
    padding: 22rpx 24rpx;
    margin-bottom: 22rpx;
    border-radius: 20rpx;
    background: #f7f9fb;
}
.hero-icon {
    width: 70rpx;
    height: 70rpx;
    border-radius: 18rpx;
    background: #c7ff00;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40rpx;
}
.hero-title {
    display: block;
    font-size: 30rpx;
    font-weight: 700;
}
.hero-desc {
    display: block;
    margin-top: 6rpx;
    color: #999;
    font-size: 23rpx;
}
.mode-row {
    display: flex;
    align-items: center;
    gap: 20rpx;
    margin: 14rpx 0 28rpx;
}
.pill {
    padding: 17rpx 28rpx;
    border-radius: 40rpx;
    background: #f1f1f1;
    font-size: 29rpx;
    font-weight: 600;
}
.pill.active {
    background: #c7ff00;
}
.search-entry {
    margin-left: auto;
    color: #999;
    font-size: 27rpx;
    white-space: nowrap;
}
.field-box,
.upload-box {
    background: #f8f9fb;
    border-radius: 20rpx;
    margin-bottom: 20rpx;
}
.field-box {
    padding: 8rpx 28rpx;
}
.url-area {
    width: 100%;
    height: 118rpx;
    padding-top: 16rpx;
    font-size: 28rpx;
}
.text-input {
    height: 68rpx;
    width: 100%;
    font-size: 28rpx;
}
.upload-box {
    height: 138rpx;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #62c900;
}
.upload-icon {
    font-size: 48rpx;
    line-height: 42rpx;
}
.upload-title {
    font-size: 30rpx;
}
.upload-sub {
    color: #999;
    font-size: 24rpx;
    margin-top: 8rpx;
}
.placeholder {
    color: #999;
}
.voice-row,
.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 22rpx 0;
    font-size: 32rpx;
    font-weight: 700;
}
.voice-row > view {
    display: flex;
    align-items: center;
    font-weight: 400;
}
.voice-name {
    font-size: 28rpx;
}
.chevron {
    font-size: 54rpx;
    margin-left: 10rpx;
}
.voice-selected {
    display: flex;
    justify-content: space-between;
    color: #888;
    font-size: 24rpx;
}
.voice-selected text:last-child {
    color: #e44;
}
.stepper {
    display: flex;
    align-items: center;
    gap: 18rpx;
}
.stepper > text {
    font-size: 46rpx;
    font-weight: 400;
}
.stepper > view {
    background: #000;
    color: #fff;
    border-radius: 10rpx;
    padding: 8rpx 22rpx;
    font-size: 29rpx;
}
.consent {
    display: flex;
    align-items: center;
    gap: 14rpx;
    padding: 10rpx 0 120rpx;
    font-size: 25rpx;
}
.checkbox {
    width: 42rpx;
    height: 42rpx;
    border: 4rpx solid #ccc;
    border-radius: 50%;
    text-align: center;
    line-height: 36rpx;
    color: #111;
}
.checkbox.checked {
    border-color: #111;
}
.agreement {
    color: #287af5;
}
.message {
    padding: 18rpx 0;
    color: #4c9c00;
    font-size: 24rpx;
}
.message.error {
    color: #e44;
}
.bottom-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 12;
    display: flex;
    align-items: center;
    gap: 16rpx;
    padding: 14rpx 24rpx calc(14rpx + env(safe-area-inset-bottom));
    background: #fff;
    border-top: 1rpx solid #f1f1f1;
}
.works-entry {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 92rpx;
    color: #111;
    font-size: 21rpx;
}
.works-icon {
    font-size: 42rpx;
    line-height: 38rpx;
}
.action-stack {
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
}
.cost {
    color: #999;
    font-size: 19rpx;
    margin-top: 4rpx;
}
.submit-button {
    width: 100%;
    height: 78rpx;
    border-radius: 48rpx;
    background: #000;
    color: #c7ff00;
    font-size: 32rpx;
    font-weight: 700;
}
.submit-button[disabled] {
    background: #777;
    color: #bbb;
}
.search-mask {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.35);
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
}
.search-sheet {
    width: calc(100% - 72rpx);
    box-sizing: border-box;
    background: #fff;
    border-radius: 28rpx;
    padding: 34rpx;
}
.sheet-title {
    font-size: 34rpx;
    font-weight: 700;
    margin-bottom: 24rpx;
}
.search-input {
    display: flex;
    align-items: center;
    gap: 14rpx;
    background: #f5f5f5;
    border-radius: 40rpx;
    padding: 8rpx 8rpx 8rpx 24rpx;
}
.search-input input {
    flex: 1;
    min-width: 0;
}
.search-input button {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 72rpx;
    line-height: 1;
    background: #36df91;
    color: #fff;
    border: 0;
    border-radius: 30rpx;
    padding: 0 28rpx;
}
.search-empty {
    color: #999;
    text-align: center;
    padding: 70rpx 0;
}
.search-results {
    margin-top: 22rpx;
}
.search-result {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18rpx;
    padding: 22rpx 4rpx;
    border-bottom: 1rpx solid #f0f0f0;
}
.result-main {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 6rpx;
}
.result-title {
    overflow: hidden;
    color: #111;
    font-size: 28rpx;
    font-weight: 600;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.result-author {
    overflow: hidden;
    color: #999;
    font-size: 23rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.result-select {
    color: #26d98b;
    font-size: 26rpx;
}
.info-banner {
    position: relative;
    min-height: 300rpx;
    margin: 22rpx 0 18rpx;
    overflow: hidden;
    border-radius: 28rpx;
    background: #d8ff63;
}
.info-banner-image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}
.info-banner-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(216, 255, 99, 0.12), rgba(216, 255, 99, 0));
}
.info-content {
    position: absolute;
    left: 28rpx;
    top: 38rpx;
    width: 54%;
    display: flex;
    flex-direction: column;
    padding: 0;
    background: transparent;
}
.info-title {
    font-size: 40rpx;
    line-height: 1.2;
    font-weight: 800;
    color: #111;
    text-shadow: 0 1rpx 0 rgba(255, 255, 255, 0.35);
}
.info-subtitle {
    margin-top: 5rpx;
    font-size: 28rpx;
    line-height: 1.25;
    font-weight: 700;
    color: #111;
}
.info-desc {
    margin-top: 12rpx;
    color: #3c4b25;
    font-size: 22rpx;
    line-height: 1.35;
}
</style>
