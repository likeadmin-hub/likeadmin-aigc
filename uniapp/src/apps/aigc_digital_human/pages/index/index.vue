<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">数字人创作</view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="subtitle">选择形象与音色，输入文案，快速生成你的数字人视频</view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">1. 选择形象</view>
                    <view class="step-link" @click="goAvatarAssets(avatarMode)"
                        >查看全部 <u-icon name="arrow-right" color="#8a93a3" size="24"></u-icon
                    ></view>
                </view>
                <view class="source-tabs">
                    <view
                        class="source-tab"
                        :class="{ 'is-active': avatarMode === 'official' }"
                        @click="avatarMode = 'official'"
                    >
                        <view class="source-tab__mark">官</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">官方形象</view>
                            <view class="source-tab__sub">{{ officialAvatars.length }}个可用</view>
                        </view>
                    </view>
                    <view
                        class="source-tab"
                        :class="{ 'is-active': avatarMode === 'mine' }"
                        @click="avatarMode = 'mine'"
                    >
                        <view class="source-tab__mark">我</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">我的克隆</view>
                            <view class="source-tab__sub">{{
                                mineAvatars.length ? mineAvatars.length + '个形象' : '去克隆形象'
                            }}</view>
                        </view>
                    </view>
                </view>
                <scroll-view class="asset-scroll" scroll-x>
                    <view class="avatar-list">
                        <view
                            v-for="item in displayedAvatars"
                            :key="item.id"
                            class="avatar-item"
                            :class="{ 'is-active': form.avatar_id === item.id }"
                            @click="selectAvatar(item)"
                        >
                            <view class="check-badge" v-if="form.avatar_id === item.id">
                                <u-icon name="checkmark" color="#ffffff" size="26"></u-icon>
                            </view>
                            <image
                                v-if="item.cover_url || item.media_url"
                                class="avatar-img"
                                :src="item.cover_url || item.media_url"
                                mode="aspectFill"
                            />
                            <view v-else class="avatar-empty">{{
                                item.source === 'mine' ? '我' : 'A'
                            }}</view>
                            <view class="asset-name">{{ item.name }}</view>
                        </view>
                        <view
                            v-if="!displayedAvatars.length"
                            class="create-inline"
                            @click="goAvatarAssets(avatarMode)"
                        >
                            <view class="create-plus">+</view>
                            <view>{{ avatarMode === 'mine' ? '创建形象' : '添加形象' }}</view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">2. 选择音色</view>
                    <view class="step-link" @click="goVoiceAssets(voiceSource)"
                        >全部音色
                        <u-icon name="arrow-right" color="rgba(255,255,255,0.72)" size="24"></u-icon
                    ></view>
                </view>
                <view class="source-tabs">
                    <view
                        class="source-tab"
                        :class="{ 'is-active': voiceSource === 'official' }"
                        @click="voiceSource = 'official'"
                    >
                        <view class="source-tab__mark">声</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">官方音色</view>
                            <view class="source-tab__sub">{{ officialVoices.length }}个可用</view>
                        </view>
                    </view>
                    <view
                        class="source-tab"
                        :class="{ 'is-active': voiceSource === 'mine' }"
                        @click="voiceSource = 'mine'"
                    >
                        <view class="source-tab__mark">录</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">我的克隆</view>
                            <view class="source-tab__sub">{{
                                mineVoices.length ? mineVoices.length + '个音色' : '微信/录音克隆'
                            }}</view>
                        </view>
                    </view>
                </view>
                <view class="voice-tabs">
                    <view
                        v-for="item in voiceTabs"
                        :key="item.value"
                        class="voice-tab"
                        :class="{ 'is-active': voiceMode === item.value }"
                        @click="voiceMode = item.value"
                    >
                        {{ item.label }}
                    </view>
                </view>
                <scroll-view class="asset-scroll" scroll-x>
                    <view class="voice-list">
                        <view
                            v-for="(item, index) in displayedVoices"
                            :key="item.id"
                            class="voice-item"
                            :class="{ 'is-active': form.voice_id === item.id }"
                            @click="selectVoice(item)"
                        >
                            <view class="check-badge" v-if="form.voice_id === item.id">
                                <u-icon name="checkmark" color="#ffffff" size="26"></u-icon>
                            </view>
                            <view class="play-circle" :class="`is-${index % 4}`">
                                <u-icon name="play-right-fill" color="#ffffff" size="34"></u-icon>
                            </view>
                            <view class="voice-name">{{ item.name }}</view>
                            <view class="voice-desc">{{ voiceDesc(item, index) }}</view>
                        </view>
                        <view
                            v-if="!displayedVoices.length"
                            class="create-inline create-inline--voice"
                            @click="goVoiceAssets(voiceSource)"
                        >
                            <view class="create-plus">+</view>
                            <view>{{ voiceSource === 'mine' ? '创建音色' : '添加音色' }}</view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">3. 选择模型</view>
                </view>
                <view class="model-list">
                    <view
                        v-for="item in modelOptions"
                        :key="item.value"
                        class="model-item"
                        :class="{ 'is-active': form.channel === item.value }"
                        @click="selectModel(item)"
                    >
                        <view>
                            <view class="model-name">{{ item.description || item.label }}</view>
                            <view class="model-desc">按音频时长计费</view>
                        </view>
                        <view class="model-price">{{ modelPriceText(item) }}</view>
                    </view>
                </view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">4. 输入文案</view>
                    <view class="assistant-pill" @click="fillScriptExample">
                        <u-icon name="edit-pen" color="#ffffff" size="28"></u-icon>
                        <text>文案助手</text>
                    </view>
                </view>
                <view class="script-box">
                    <textarea
                        v-model="form.script_text"
                        maxlength="500"
                        placeholder="请输入或粘贴您想要数字人说的内容..."
                        placeholder-class="input-placeholder"
                    />
                    <view class="counter">{{ form.script_text.length }}/500</view>
                </view>
                <view class="tool-row">
                    <button type="button" @click="form.script_text = ''">
                        <u-icon name="trash" color="#87909f" size="26"></u-icon>
                        <text>清空文案</text>
                    </button>
                    <button type="button" @click="pasteScript">
                        <u-icon name="file-text" color="#87909f" size="26"></u-icon>
                        <text>粘贴文案</text>
                    </button>
                </view>
            </view>

            <view class="page-bottom-space"></view>
        </scroll-view>

        <view class="bottom-bar">
            <button class="history-btn" @click="goResults">
                <u-icon name="clock" color="#ffffff" size="38"></u-icon>
                <view>创作记录</view>
            </button>
            <button
                class="generate-btn"
                :class="{ 'is-disabled': submitting }"
                :loading="submitting"
                :disabled="submitting"
                @click="handleGenerate"
            >
                <view>{{ submitting ? '提交中...' : '立即合成' }}</view>
                <text>{{ estimateText }}</text>
            </button>
        </view>

        <view v-if="submitting" class="submit-mask" @click.stop @touchmove.stop.prevent>
            <view class="submit-loading">
                <u-loading mode="circle" color="#ffffff" size="52"></u-loading>
                <view class="submit-loading__title">正在提交合成任务</view>
                <view class="submit-loading__desc">任务创建成功后将进入合成进度</view>
            </view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getMembershipAppAccess } from '@/api/membership'
import {
    generateAigcDigitalHuman,
    estimateAigcDigitalHuman,
    getAigcDigitalHumanAvatars,
    getAigcDigitalHumanConfig,
    getAigcDigitalHumanVoices
} from '@/apps/aigc_digital_human/api'

const submitting = ref(false)
const estimating = ref(false)
const membershipAccess = ref<any>({
    need_membership: 0,
    allowed: 1,
    member_status: 'none'
})
const estimateInfo = ref<any>({})
const avatars = ref<any[]>([])
const voices = ref<any[]>([])
const avatarMode = ref('official')
const voiceSource = ref('official')
const voiceMode = ref('hot')
const optionConfig = ref<any>({
    defaults: { channel: 'master', quality: '1k', ratio: '9:16' }
})
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const form = reactive({
    avatar_id: 0,
    voice_id: 0,
    title: '数字人创作',
    script_text: '',
    prompt: '',
    channel: 'master',
    quality: '1k',
    ratio: '9:16'
})
const voiceTabs = [
    { label: '热门', value: 'hot' },
    { label: '女声', value: 'female' },
    { label: '男声', value: 'male' },
    { label: '童声', value: 'child' },
    { label: '方言', value: 'dialect' },
    { label: '外语', value: 'foreign' }
]
const voiceDescs = [
    '温柔知性，适合口播',
    '温柔亲切，适合讲解',
    '沉稳磁性，适合介绍',
    '活力阳光，适合短视频'
]

const selectedAvatar = computed(() => avatars.value.find((item) => item.id === form.avatar_id))
const selectedVoice = computed(() => voices.value.find((item) => item.id === form.voice_id))
const modelOptions = computed(() =>
    (optionConfig.value.channels || []).map((item: any) => ({
        label: item.label || item.description || '数字人视频模型',
        value: item.value || item.code,
        description: item.description || item.label || '数字人视频模型',
        tenant_unit_price: item.tenant_unit_price,
        qualities: item.qualities || []
    }))
)
const estimatedDuration = computed(() =>
    Math.max(1, Math.ceil((form.script_text.trim().length || 1) / 4))
)
const estimateText = computed(() => {
    const points = estimateInfo.value.user_charge_points
    if (points !== undefined && points !== null && points !== '') {
        return `约${estimatedDuration.value}秒 · ${points}积分`
    }
    return `约${estimatedDuration.value}秒，按音频时长计费`
})
const officialAvatars = computed(() => avatars.value.filter((item) => item.source === 'official'))
const mineAvatars = computed(() => avatars.value.filter((item) => item.source === 'mine'))
const displayedAvatars = computed(() =>
    (avatarMode.value === 'mine' ? mineAvatars.value : officialAvatars.value).slice(0, 8)
)
const officialVoices = computed(() => voices.value.filter((item) => item.source === 'official'))
const mineVoices = computed(() => voices.value.filter((item) => item.source === 'mine'))
const displayedVoices = computed(() =>
    (voiceSource.value === 'mine' ? mineVoices.value : officialVoices.value).slice(0, 8)
)
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
    navMetrics.navHeight = menuButton.top + menuButton.height + 20
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 10
    navMetrics.menuHeight = 36
    navMetrics.menuWidth = 0
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 20
    // #endif
}

const syncSelection = () => {
    const storedAvatar = uni.getStorageSync('aigc_digital_human_selected_avatar')
    const storedVoice = uni.getStorageSync('aigc_digital_human_selected_voice')
    if (storedAvatar?.id && avatars.value.some((item) => item.id === storedAvatar.id))
        form.avatar_id = storedAvatar.id
    if (storedVoice?.id && voices.value.some((item) => item.id === storedVoice.id))
        form.voice_id = storedVoice.id
    if (!form.avatar_id && officialAvatars.value.length)
        form.avatar_id = officialAvatars.value[0].id
    if (!form.avatar_id && avatars.value.length) form.avatar_id = avatars.value[0].id
    if (!form.voice_id && officialVoices.value.length) form.voice_id = officialVoices.value[0].id
    if (!form.voice_id && voices.value.length) form.voice_id = voices.value[0].id
    const currentAvatar = avatars.value.find((item) => item.id === form.avatar_id)
    const currentVoice = voices.value.find((item) => item.id === form.voice_id)
    avatarMode.value = currentAvatar?.source === 'mine' ? 'mine' : 'official'
    voiceSource.value = currentVoice?.source === 'mine' ? 'mine' : 'official'
}

const getData = async () => {
    const [config, avatarRows, voiceRows] = await Promise.all([
        getAigcDigitalHumanConfig(),
        getAigcDigitalHumanAvatars(),
        getAigcDigitalHumanVoices()
    ])
    optionConfig.value = config?.option_config || optionConfig.value
    const defaults = optionConfig.value.defaults || {}
    form.channel = defaults.channel || form.channel
    form.quality = defaults.quality || form.quality
    form.ratio = defaults.ratio || form.ratio
    avatars.value = avatarRows || []
    voices.value = voiceRows || []
    const reuse = uni.getStorageSync('aigc_digital_human_reuse')
    if (reuse) {
        form.script_text = reuse.script_text || form.script_text
        form.avatar_id = reuse.avatar_id || form.avatar_id
        form.voice_id = reuse.voice_id || form.voice_id
        form.channel = reuse.channel || form.channel
        form.quality = reuse.quality || form.quality
        form.ratio = reuse.ratio || form.ratio
        uni.removeStorageSync('aigc_digital_human_reuse')
    }
    syncSelection()
    refreshEstimate()
}

const selectAvatar = (item: any) => {
    form.avatar_id = item.id
    uni.setStorageSync('aigc_digital_human_selected_avatar', item)
}

const selectVoice = (item: any) => {
    form.voice_id = item.id
    uni.setStorageSync('aigc_digital_human_selected_voice', item)
}

const selectModel = (item: any) => {
    form.channel = item.value
    const quality = item.qualities?.[0]
    const ratio = quality?.ratios?.[0]
    if (quality?.value) form.quality = quality.value
    if (ratio?.value || ratio?.ratio) form.ratio = ratio.value || ratio.ratio
    refreshEstimate()
}

const modelPriceText = (item: any) => {
    const price = item?.tenant_unit_price
    return price !== undefined && price !== null && price !== '' ? `${price}积分/秒` : '按秒计费'
}

const refreshEstimate = async () => {
    if (estimating.value) return
    estimating.value = true
    try {
        estimateInfo.value = await estimateAigcDigitalHuman({
            channel: form.channel,
            quality: form.quality,
            ratio: form.ratio,
            duration: estimatedDuration.value
        })
    } catch (error) {
        estimateInfo.value = {}
    } finally {
        estimating.value = false
    }
}

const voiceDesc = (item: any, index: number) =>
    item.description || voiceDescs[index % voiceDescs.length]

const fillScriptExample = () => {
    form.script_text =
        '大家好，欢迎了解我们的产品。它能帮助你快速完成内容创作，用数字人讲清楚重点，让短视频表达更自然、更高效。'
}

const pasteScript = () => {
    uni.getClipboardData({
        success: (res) => {
            form.script_text = String(res.data || '').slice(0, 500)
        }
    })
}

const handleGenerate = async () => {
    if (!(await ensureMembershipAccess())) return
    if (!form.avatar_id) return uni.$u.toast('请选择形象')
    if (!form.voice_id) return uni.$u.toast('请选择音色')
    if (selectedAvatar.value?.media_type && selectedAvatar.value.media_type !== 'video')
        return uni.$u.toast('请选择可合成的视频形象')
    if (!selectedVoice.value?.provider_asset_id) return uni.$u.toast('当前音色未完成克隆，无法合成')
    if (!form.script_text.trim()) return uni.$u.toast('请输入文案')
    submitting.value = true
    try {
        const payload = {
            avatar_id: form.avatar_id,
            voice_id: form.voice_id,
            title: selectedAvatar.value?.name || form.title,
            script_text: form.script_text.trim(),
            prompt: form.prompt,
            channel: form.channel,
            quality: form.quality,
            ratio: form.ratio,
            duration: estimatedDuration.value
        }
        const task = await generateAigcDigitalHuman(payload)
        uni.setStorageSync('aigc_digital_human_latest_generate', {
            ...payload,
            ...task,
            avatar: selectedAvatar.value,
            voice: selectedVoice.value
        })
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/tasks/tasks' })
    } finally {
        submitting.value = false
    }
}

const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) return uni.navigateBack()
    uni.switchTab({ url: '/pages/index/index' })
}
const goAvatarAssets = (source: string) => {
    if (source === 'mine') {
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/avatar/avatar' })
        return
    }
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/assets/avatar/avatar?source=official' })
}
const goVoiceAssets = (source: string) => {
    if (source === 'mine') {
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/voice/voice' })
        return
    }
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/assets/voice/voice?source=official' })
}
const goResults = () => uni.navigateTo({ url: '/apps/aigc_digital_human/pages/results/results' })

const ensureMembershipAccess = async () => {
    try {
        const data = await getMembershipAppAccess({ app_code: 'aigc_digital_human' })
        membershipAccess.value = data || membershipAccess.value
        if (Number(data?.need_membership || 0) === 1 && Number(data?.allowed || 0) !== 1) {
            uni.showModal({
                title: '会员专享应用',
                content: '该应用需开通会员后使用',
                confirmText: '去开通',
                success: (res) => {
                    if (res.confirm) {
                        uni.navigateTo({ url: '/packages/pages/membership/membership' })
                    }
                }
            })
            return false
        }
    } catch (error) {
        return false
    }
    return true
}

initNavMetrics()
onShow(() => {
    ensureMembershipAccess()
    getData()
})
watch(() => [form.channel, form.quality, form.ratio, form.script_text], refreshEstimate)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #ffffff;
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
    opacity: 0.34;
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
    justify-content: center;
    width: 52rpx;
    height: 100%;
}

.page-title {
    max-width: 310rpx;
    overflow: hidden;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 36rpx;
    font-weight: 900;
}

.content {
    position: relative;
    z-index: 1;
    box-sizing: border-box;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 18rpx 32rpx 226rpx;
}

.subtitle {
    margin: 8rpx 0 28rpx;
    color: rgba(255, 255, 255, 0.58);
    text-align: center;
    font-size: 28rpx;
    line-height: 1.35;
}

.step-card {
    margin-bottom: 42rpx;
    padding: 30rpx 28rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.06);
    border-radius: 24rpx;
    background: rgba(34, 34, 34, 0.96);
}

.step-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24rpx;
}

.step-title {
    color: #ffffff;
    font-size: 31rpx;
    font-weight: 900;
}

.step-link,
.assistant-pill {
    display: flex;
    align-items: center;
    gap: 6rpx;
    color: #8a93a3;
    font-size: 25rpx;
    font-weight: 700;
}

.assistant-pill {
    padding: 8rpx 16rpx;
    border-radius: 999rpx;
    background: #eff6ff;
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}

.source-tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12rpx;
    margin-bottom: 24rpx;
    padding: 8rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 22rpx;
    background: rgba(255, 255, 255, 0.035);
}

.source-tab {
    position: relative;
    display: flex;
    align-items: center;
    gap: 14rpx;
    min-height: 88rpx;
    padding: 0 16rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 18rpx;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.58);
    transition: all 0.18s ease;
}

.source-tab__mark {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 46rpx;
    height: 46rpx;
    border-radius: 14rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.76);
    font-size: 22rpx;
    font-weight: 900;
}

.source-tab__body {
    flex: 1;
    min-width: 0;
}

.source-tab__main {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(255, 255, 255, 0.72);
    font-size: 27rpx;
    font-weight: 900;
}

.source-tab__sub {
    overflow: hidden;
    margin-top: 4rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(255, 255, 255, 0.38);
    font-size: 21rpx;
    font-weight: 600;
}

.source-tab.is-active {
    border-color: rgba(255, 255, 255, 0.3);
    background: linear-gradient(180deg, #3b3c3d 0%, #2e2f30 100%);
    box-shadow: inset 0 1rpx 0 rgba(255, 255, 255, 0.15), 0 14rpx 30rpx rgba(0, 0, 0, 0.26);
}

.source-tab.is-active .source-tab__mark {
    background: #ffffff;
    color: #171719;
}

.source-tab.is-active .source-tab__main,
.source-tab.is-active .source-tab__sub {
    color: #ffffff;
}

.asset-scroll {
    width: 100%;
    white-space: nowrap;
}

.avatar-list,
.voice-list {
    display: inline-flex;
    gap: 18rpx;
    min-width: 100%;
}

.avatar-item {
    position: relative;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    overflow: hidden;
    width: 148rpx;
    height: 198rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.avatar-item.is-active,
.voice-item.is-active {
    border-color: #3b82ff;
    box-shadow: 0 0 0 2rpx rgba(59, 130, 255, 0.16);
}

.check-badge {
    position: absolute;
    z-index: 2;
    top: -1rpx;
    right: -1rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44rpx;
    height: 44rpx;
    border-radius: 0 14rpx 0 22rpx;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}

.avatar-img,
.avatar-empty {
    width: 100%;
    height: 142rpx;
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
}

.avatar-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 42rpx;
    font-weight: 900;
}

.asset-name {
    box-sizing: border-box;
    width: 100%;
    overflow: hidden;
    padding: 12rpx 8rpx 0;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 24rpx;
    font-weight: 800;
}

.create-inline {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 148rpx;
    height: 198rpx;
    border: 2rpx dashed rgba(255, 255, 255, 0.42);
    border-radius: 16rpx;
    color: rgba(255, 255, 255, 0.68);
    font-size: 24rpx;
}

.create-plus {
    margin-bottom: 12rpx;
    color: #ffffff;
    font-size: 44rpx;
    font-weight: 500;
}

.voice-tabs {
    display: flex;
    gap: 12rpx;
    margin-bottom: 24rpx;
    overflow: hidden;
}

.voice-tab {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    min-width: 88rpx;
    height: 52rpx;
    padding: 0 18rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 14rpx;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.68);
    font-size: 25rpx;
    font-weight: 700;
}

.voice-tab.is-active {
    border-color: rgba(255, 255, 255, 0.3);
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
}

.voice-item {
    position: relative;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    width: 156rpx;
    height: 174rpx;
    padding: 22rpx 12rpx 14rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.play-circle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72rpx;
    height: 72rpx;
    border-radius: 50%;
    background: linear-gradient(135deg, #8fc0ff, #4f8df7);
}

.play-circle.is-1 {
    background: linear-gradient(135deg, #ff9ac8, #f15ca4);
}

.play-circle.is-2 {
    background: linear-gradient(135deg, #8fc0ff, #4f8df7);
}

.play-circle.is-3 {
    background: linear-gradient(135deg, #b69aff, #7d5df0);
}

.voice-name {
    overflow: hidden;
    width: 100%;
    margin-top: 16rpx;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 25rpx;
    font-weight: 900;
}

.voice-desc {
    overflow: hidden;
    width: 100%;
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.48);
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 20rpx;
}

.create-inline--voice {
    width: 156rpx;
    height: 174rpx;
}

.model-list {
    display: grid;
    gap: 16rpx;
}

.model-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20rpx;
    padding: 22rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.model-item.is-active {
    border-color: #3b82ff;
    background: rgba(59, 130, 255, 0.14);
    box-shadow: 0 0 0 2rpx rgba(59, 130, 255, 0.16);
}

.model-name {
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 900;
}

.model-desc {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 22rpx;
}

.model-price {
    flex: none;
    color: #ffffff;
    font-size: 24rpx;
    font-weight: 800;
}

.script-box {
    position: relative;
    min-height: 210rpx;
    padding: 20rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.04);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.02);
}

.script-box textarea {
    width: 100%;
    height: 170rpx;
    color: #ffffff;
    font-size: 27rpx;
    line-height: 1.55;
}

.input-placeholder {
    color: rgba(255, 255, 255, 0.42);
}

.counter {
    position: absolute;
    right: 20rpx;
    bottom: 18rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
}

.tool-row {
    display: flex;
    gap: 20rpx;
    margin-top: 18rpx;
}

.tool-row button {
    display: flex;
    align-items: center;
    gap: 8rpx;
    height: 52rpx;
    margin: 0;
    padding: 0 18rpx;
    border: 0;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.68);
    font-size: 24rpx;
}

.page-bottom-space {
    height: 1rpx;
}

.bottom-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 5;
    display: grid;
    grid-template-columns: 1fr 1.8fr;
    gap: 22rpx;
    padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
    border-top: 1rpx solid rgba(255, 255, 255, 0.08);
    background: rgba(9, 10, 15, 0.98);
}

.history-btn,
.generate-btn {
    height: 104rpx;
    margin: 0;
    border-radius: 16rpx;
    color: #ffffff;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    font-size: 27rpx;
    line-height: 1.15;
    font-weight: 700;
}

.history-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
    background: rgba(255, 255, 255, 0.08);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
}

.generate-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
}

.generate-btn text {
    color: rgba(255, 255, 255, 0.72);
    font-size: 22rpx;
}

.generate-btn.is-disabled,
.generate-btn[disabled] {
    color: #ffffff;
    background: linear-gradient(180deg, rgba(58, 59, 60, 0.72) 0%, rgba(47, 48, 49, 0.72) 100%);
    opacity: 0.86;
}

.submit-mask {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 48rpx;
    background: rgba(0, 0, 0, 0.52);
    backdrop-filter: blur(6rpx);
    box-sizing: border-box;
}

.submit-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 420rpx;
    padding: 46rpx 34rpx;
    border-radius: 24rpx;
    background: rgba(20, 21, 24, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 28rpx 72rpx rgba(0, 0, 0, 0.38);
}

.submit-loading__title {
    margin-top: 24rpx;
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 700;
}

.submit-loading__desc {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 24rpx;
}
</style>
