<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">克隆音色</view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="method-grid">
                <view class="method-card" @click="chooseUploadAudio">
                    <view class="method-icon">
                        <u-icon name="plus" color="#ffffff" size="44"></u-icon>
                    </view>
                    <view class="method-title">上传音频文件</view>
                    <view class="method-desc">选择本地 mp3、wav、m4a、aac 音频样本创建音色</view>
                </view>
                <view class="method-card" @click="chooseWechatAudio">
                    <view class="method-icon">
                        <u-icon name="file-text" color="#ffffff" size="44"></u-icon>
                    </view>
                    <view class="method-title">微信聊天记录</view>
                    <view class="method-desc">从微信聊天文件中选择一段音频作为克隆样本</view>
                </view>
                <view class="method-card" @click="toggleRecord">
                    <view class="method-icon" :class="{ 'is-recording': recording }">
                        <u-icon name="mic" color="#ffffff" size="44"></u-icon>
                    </view>
                    <view class="method-title">{{ recording ? '正在录音' : '录制声音' }}</view>
                    <view class="method-desc">{{
                        recording
                            ? `${recordSeconds}s，点击结束并创建音色`
                            : '直接录制一段清晰语音，生成我的克隆音色'
                    }}</view>
                </view>
            </view>

            <view v-if="sampleName" class="sample-card">
                <view class="sample-title">已选择样本</view>
                <view class="sample-name">{{ sampleName }}</view>
                <button
                    class="primary-btn"
                    :loading="creating"
                    :disabled="creating"
                    @click="createVoiceFromSample"
                >
                    创建克隆音色
                </button>
            </view>

            <view class="section-title">我的音色</view>
            <view v-if="lists.length" class="voice-list">
                <view
                    v-for="item in lists"
                    :key="item.id"
                    class="voice-card"
                    @click="selectVoice(item)"
                >
                    <view class="wave-icon">⌁</view>
                    <view class="voice-info">
                        <view class="voice-name">{{ item.name }}</view>
                        <view class="voice-meta">{{
                            item.status === 'ready' ? '可用于合成' : item.status
                        }}</view>
                    </view>
                    <u-icon name="arrow-right" color="rgba(255,255,255,0.46)" size="26"></u-icon>
                </view>
            </view>
            <view v-else class="empty">
                <view class="empty-title">还没有克隆音色</view>
                <view class="empty-desc">使用微信聊天记录或录音创建后，会自动带回创作页</view>
            </view>
        </scroll-view>
    </view>
</template>

<script setup lang="ts">
import { computed, onUnmounted, reactive, ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { uploadFile } from '@/api/app'
import { getAigcDigitalHumanVoices, saveAigcDigitalHumanVoice } from '@/apps/aigc_digital_human/api'
import appConfig from '@/config'
import { getToken } from '@/utils/auth'
import { getTenantId } from '@/utils/tenant'

declare const wx: any

const lists = ref<any[]>([])
const samplePath = ref('')
const sampleName = ref('')
const sampleFile = ref<File | null>(null)
const recording = ref(false)
const recordSeconds = ref(0)
const creating = ref(false)
let recordTimer: ReturnType<typeof setInterval> | null = null
let h5Recorder: MediaRecorder | null = null
let h5RecordStream: MediaStream | null = null
let h5RecordChunks: Blob[] = []
const recorderManager =
    typeof uni.getRecorderManager === 'function' ? uni.getRecorderManager() : null
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
    lists.value = await getAigcDigitalHumanVoices({ source: 'mine' })
}

const stopTimer = () => {
    if (!recordTimer) return
    clearInterval(recordTimer)
    recordTimer = null
}

const getH5RecordCapabilityReason = () => {
    // #ifdef H5
    if (typeof window === 'undefined') return '当前环境不支持录音'
    const isLocalHost = ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname) ||
        window.location.hostname.endsWith('.localhost')
    if (!window.isSecureContext && !isLocalHost) {
        return '浏览器要求 HTTPS 或 localhost 环境才可以录音'
    }
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        return '当前浏览器缺少录音能力，请更换 Chrome / Edge 或上传音频'
    }
    return ''
    // #endif
    // #ifndef H5
    return recorderManager ? '' : '当前端暂不支持录音'
    // #endif
}

const stopH5RecordStream = () => {
    // #ifdef H5
    h5RecordStream?.getTracks().forEach((track) => track.stop())
    h5RecordStream = null
    h5Recorder = null
    // #endif
}

recorderManager?.onStop((res: any) => {
    recording.value = false
    stopTimer()
    if (!res?.tempFilePath) return
    samplePath.value = res.tempFilePath
    sampleFile.value = null
    sampleName.value = `录音样本 ${Math.max(recordSeconds.value, 1)}s`
})

recorderManager?.onError(() => {
    recording.value = false
    stopTimer()
    uni.$u.toast('录音失败，请重新尝试')
})

const audioExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'flac', 'opus']
const isCancelError = (error: any) =>
    String(error?.errMsg || error || '')
        .toLowerCase()
        .includes('cancel')
const showChooseAudioError = (error: any) => {
    console.error('[aigc_digital_human] choose audio failed', error)
    const message = String(error?.errMsg || error || '')
    if (message.includes('chooseMessageFile:fail')) {
        uni.$u.toast('请选择微信聊天中的音频文件')
        return
    }
    uni.$u.toast('选择音频失败')
}
const chooseWechatFile = () =>
    new Promise<any>((resolve, reject) => {
        // #ifdef MP-WEIXIN
        const chooseMessageFile =
            typeof wx !== 'undefined' && wx?.chooseMessageFile
                ? wx.chooseMessageFile
                : (uni as any).chooseMessageFile
        if (typeof chooseMessageFile !== 'function') {
            reject(new Error('当前微信版本不支持选择聊天文件'))
            return
        }
        chooseMessageFile({
            count: 1,
            type: 'file',
            extension: audioExtensions,
            success: resolve,
            fail: reject
        })
        // #endif
        // #ifndef MP-WEIXIN
        reject(new Error('请在微信小程序中选择聊天记录音频'))
        // #endif
    })
const applySelectedAudio = (file: any, fallbackName: string) => {
    const path = file?.path || file?.tempFilePath
    if (!path) {
        uni.$u.toast('未获取到音频文件')
        return
    }
    samplePath.value = path
    sampleFile.value = null
    sampleName.value = file?.name || fallbackName
    recordSeconds.value = 0
}

const chooseWechatAudio = async () => {
    try {
        // #ifdef MP-WEIXIN
        const res: any = await chooseWechatFile()
        applySelectedAudio(res?.tempFiles?.[0], '微信聊天音频')
        // #endif
        // #ifndef MP-WEIXIN
        uni.$u.toast('请在微信小程序中选择聊天记录音频')
        // #endif
    } catch (error: any) {
        if (!isCancelError(error)) showChooseAudioError(error)
    }
}

const chooseUploadAudio = async () => {
    try {
        // #ifdef H5
        const res: any = await (uni as any).chooseFile({
            count: 1,
            type: 'audio',
            extension: ['.mp3', '.wav', '.m4a', '.aac']
        })
        const file = res?.tempFiles?.[0]
        if (!file?.path) return
        samplePath.value = file.path
        sampleFile.value = file?.file || null
        sampleName.value = file.name || '上传音频样本'
        recordSeconds.value = 0
        // #endif
        // #ifdef MP-WEIXIN
        const res: any = await chooseWechatFile()
        applySelectedAudio(res?.tempFiles?.[0], '上传音频样本')
        // #endif
        // #ifndef H5 || MP-WEIXIN
        uni.$u.toast('当前端暂不支持文件上传')
        // #endif
    } catch (error: any) {
        if (!isCancelError(error)) showChooseAudioError(error)
    }
}

const toggleRecord = async () => {
    // #ifdef H5
    if (recording.value) {
        h5Recorder?.stop()
        return
    }
    const reason = getH5RecordCapabilityReason()
    if (reason) {
        uni.$u.toast(reason)
        return
    }
    samplePath.value = ''
    sampleName.value = ''
    sampleFile.value = null
    recordSeconds.value = 0
    h5RecordChunks = []
    recording.value = true
    stopTimer()
    recordTimer = setInterval(() => {
        recordSeconds.value += 1
    }, 1000)
    try {
        h5RecordStream = await navigator.mediaDevices.getUserMedia({ audio: true })
        h5Recorder = new MediaRecorder(h5RecordStream)
        h5Recorder.ondataavailable = (event) => {
            if (event.data?.size) h5RecordChunks.push(event.data)
        }
        h5Recorder.onstop = () => {
            const duration = Math.max(recordSeconds.value, 1)
            const blobType = h5Recorder?.mimeType || h5RecordChunks[0]?.type || 'audio/webm'
            const blob = h5RecordChunks.length ? new Blob(h5RecordChunks, { type: blobType }) : null
            recording.value = false
            stopTimer()
            stopH5RecordStream()
            if (!blob?.size) {
                uni.$u.toast('未采集到有效音频，请重试')
                return
            }
            const extension = blob.type.includes('webm') ? 'webm' : blob.type.includes('ogg') ? 'ogg' : 'wav'
            sampleFile.value = new File([blob], `record-${Date.now()}.${extension}`, { type: blob.type || blobType })
            samplePath.value = ''
            sampleName.value = `录音样本 ${duration}s`
        }
        h5Recorder.start()
    } catch (error: any) {
        recording.value = false
        stopTimer()
        stopH5RecordStream()
        const name = error?.name || ''
        uni.$u.toast(
            name === 'NotAllowedError' || name === 'PermissionDeniedError'
                ? '未授权麦克风，请允许录音或改用上传音频'
                : '录音失败，请重新尝试'
        )
    }
    return
    // #endif

    if (!recorderManager) {
        uni.$u.toast(getH5RecordCapabilityReason())
        return
    }
    if (recording.value) {
        recorderManager.stop()
        return
    }
    samplePath.value = ''
    sampleName.value = ''
    sampleFile.value = null
    recordSeconds.value = 0
    recording.value = true
    recorderManager.start({
        duration: 60000,
        sampleRate: 16000,
        numberOfChannels: 1,
        encodeBitRate: 96000,
        format: 'mp3'
    })
    stopTimer()
    recordTimer = setInterval(() => {
        recordSeconds.value += 1
    }, 1000)
}

const uploadH5AudioFile = async (file: File) => {
    const formData = new FormData()
    formData.append('file', file)
    const baseUrl = appConfig.baseUrl || '/'
    const response = await fetch(`${baseUrl}api/upload/file`, {
        method: 'POST',
        headers: {
            token: getToken() || '',
            'tenant-id': getTenantId() || '',
            version: appConfig.version
        },
        body: formData
    })
    const result = await response.json()
    if (result?.code === 1) return result.data
    throw new Error(result?.msg || '音频上传失败')
}

const createVoiceFromSample = async () => {
    if (!samplePath.value && !sampleFile.value) return uni.$u.toast('请选择或录制音频样本')
    creating.value = true
    try {
        uni.showLoading({ title: '创建中...' })
        let audioUri = ''
        const uploadRes: any = sampleFile.value ? await uploadH5AudioFile(sampleFile.value) : await uploadFile(samplePath.value)
        audioUri = uploadRes?.uri || uploadRes?.url || uploadRes?.path || ''
        if (!audioUri) return uni.$u.toast('音频上传失败')
        const row: any = await saveAigcDigitalHumanVoice({
            name: `我的克隆音色 ${lists.value.length + 1}`,
            audio_uri: audioUri,
            duration: recordSeconds.value,
            gender: 'female',
            age_group: 'young'
        })
        uni.setStorageSync('aigc_digital_human_selected_voice', row)
        uni.$u.toast('音色已创建')
        uni.navigateBack()
    } finally {
        creating.value = false
        uni.hideLoading()
    }
}

const selectVoice = (item: any) => {
    uni.setStorageSync('aigc_digital_human_selected_voice', item)
    uni.navigateBack()
}

const goBack = () => {
    if (recording.value) {
        // #ifdef H5
        h5Recorder?.stop()
        // #endif
        recorderManager?.stop()
    }
    uni.navigateBack()
}

initNavMetrics()
onShow(getData)
onUnmounted(() => {
    stopTimer()
    if (recording.value) {
        // #ifdef H5
        h5Recorder?.stop()
        // #endif
        recorderManager?.stop()
    }
    stopH5RecordStream()
})
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

.method-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18rpx;
}

.method-card,
.sample-card,
.voice-card,
.empty {
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 24rpx;
    background: rgba(34, 34, 34, 0.96);
}

.method-card {
    min-height: 240rpx;
    padding: 26rpx 16rpx;
    text-align: center;
}

.method-icon,
.wave-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    border-radius: 50%;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}

.method-icon {
    width: 88rpx;
    height: 88rpx;
}

.method-icon.is-recording {
    box-shadow: 0 0 0 10rpx rgba(255, 255, 255, 0.08);
}

.method-title,
.section-title,
.sample-title,
.empty-title {
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 800;
}

.method-title {
    margin-top: 20rpx;
}

.method-desc,
.voice-meta,
.empty-desc,
.sample-name {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.54);
    font-size: 23rpx;
    line-height: 1.45;
}

@media (max-width: 360px) {
    .method-grid {
        grid-template-columns: 1fr;
    }

    .method-card {
        min-height: auto;
    }
}

.sample-card {
    margin-top: 22rpx;
    padding: 28rpx;
}

.primary-btn {
    width: 100%;
    height: 82rpx;
    margin-top: 26rpx;
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

.voice-list {
    display: grid;
    gap: 16rpx;
}

.voice-card {
    display: flex;
    align-items: center;
    gap: 18rpx;
    padding: 20rpx;
}

.wave-icon {
    flex: none;
    width: 86rpx;
    height: 86rpx;
    color: #ffffff;
    font-size: 56rpx;
    font-weight: 900;
}

.voice-info {
    flex: 1;
    min-width: 0;
}

.voice-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 800;
}

.empty {
    padding: 72rpx 30rpx;
    text-align: center;
}
</style>
