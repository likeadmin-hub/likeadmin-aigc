<template>
    <view class="page">
        <view class="topbar">
            <view class="nav-btn" @click="goBack">‹</view>
            <view class="title">AI视频剪辑</view>
            <view class="nav-link" @click="goResults">作品</view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="tabs">
                <view v-for="item in clipTypes" :key="item.value" class="tab" :class="{ 'is-active': form.api === item.value }" @click="switchType(item.value)">
                    {{ item.label }}
                </view>
            </view>

            <view class="section">
                <view class="section-head">
                    <text>模板</text>
                    <text class="muted">{{ selectedTemplate?.name || '请选择' }}</text>
                </view>
                <scroll-view class="template-scroll" scroll-x>
                    <view class="template-list">
                        <view v-for="item in templates" :key="templateId(item)" class="template-card" :class="{ 'is-active': form.styleId === templateId(item) }" @click="selectTemplate(item)">
                            <image v-if="item.coverUrl || item.cover_url" class="template-cover" :src="item.coverUrl || item.cover_url" mode="aspectFill" />
                            <view v-else class="template-cover template-empty">模板</view>
                            <view class="template-name">{{ item.name || '剪辑模板' }}</view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view class="section">
                <view class="section-head">
                    <text>素材</text>
                    <text class="muted">{{ materialSummary }}</text>
                </view>
                <view class="upload-grid">
                    <view class="upload-card" @click="chooseVideo">
                        <text class="upload-plus">+</text>
                        <text>{{ form.api === 'realman_broadcast' ? '主视频' : '视频素材' }}</text>
                    </view>
                    <view v-if="form.api !== 'realman_broadcast'" class="upload-card" @click="chooseImage">
                        <text class="upload-plus">+</text>
                        <text>图片素材</text>
                    </view>
                    <view v-if="form.api !== 'realman_broadcast'" class="upload-card" @click="chooseAudio">
                        <text class="upload-plus">+</text>
                        <text>{{ form.api === 'broadcast_mixcut' ? '驱动音频' : '背景音频' }}</text>
                    </view>
                </view>
                <view v-if="form.videoUrl" class="material-row">
                    <text>主视频</text>
                    <text>{{ form.duration || 0 }}秒</text>
                    <text class="danger" @click="clearVideo">删除</text>
                </view>
                <view v-for="(item, index) in form.materials" :key="`${item.fileUrl}-${index}`" class="material-row">
                    <text>{{ item.type === 'image' ? '图片' : '视频' }}</text>
                    <text>{{ item.duration || (item.type === 'image' ? 2 : 0) }}秒</text>
                    <text class="danger" @click="removeMaterial(index)">删除</text>
                </view>
                <view v-if="form.audioUrl" class="material-row">
                    <text>{{ form.api === 'broadcast_mixcut' ? '驱动音频' : '背景音频' }}</text>
                    <text>{{ form.audioDuration || 0 }}秒</text>
                    <text class="danger" @click="clearAudio">删除</text>
                </view>
            </view>

            <view class="section">
                <view class="section-head"><text>内容</text></view>
                <input v-model="form.title" class="input" placeholder="视频标题" />
                <view class="two-input">
                    <input v-model="form.introduceCard.name" class="input" placeholder="身份栏姓名" />
                    <input v-model="form.introduceCard.description" class="input" placeholder="身份描述" />
                </view>
                <view v-if="form.api === 'news_mixcut'" class="duration-row">
                    <text>视频时长</text>
                    <input v-model.number="form.processRules.videoDuration" class="duration-input" type="number" />
                    <text>秒</text>
                </view>
            </view>

            <view class="section">
                <view class="section-head"><text>包装</text></view>
                <view class="switch-row" @click="togglePack('title')">
                    <text>标题包装</text>
                    <switch :checked="form.packRules.title" color="#ffffff" @change="togglePack('title')" />
                </view>
                <view class="switch-row" @click="togglePack('subtitle')">
                    <text>字幕包装</text>
                    <switch :checked="form.packRules.subtitle" color="#ffffff" @change="togglePack('subtitle')" />
                </view>
                <view class="switch-row" @click="form.processRules.watermarkShow = !form.processRules.watermarkShow">
                    <text>AI水印</text>
                    <switch :checked="form.processRules.watermarkShow" color="#ffffff" @change="form.processRules.watermarkShow = !form.processRules.watermarkShow" />
                </view>
            </view>

            <view v-if="estimateInfo.user_charge_points" class="cost-card">
                <text>预计消耗</text>
                <text>{{ estimateInfo.user_charge_points }} 点 · {{ estimateInfo.duration || 0 }}秒</text>
            </view>

            <view v-if="results.length" class="section">
                <view class="section-head">
                    <text>最近作品</text>
                    <text class="muted" @click="goResults">全部</text>
                </view>
                <view v-for="item in results.slice(0, 2)" :key="item.task_id || item.id" class="result-card">
                    <video v-if="item.video_url" class="result-video" :src="item.video_url" controls />
                    <view v-else class="result-placeholder">{{ statusText(item.status) }}</view>
                    <view class="result-body">
                        <text>{{ item.title || typeText(item.clip_type) }}</text>
                        <text class="muted">{{ statusText(item.status) }}</text>
                    </view>
                </view>
            </view>
        </scroll-view>

        <view class="bottom-bar">
            <button class="secondary" @click="goTasks">任务</button>
            <button class="primary" :disabled="submitting || !canSubmit" @click="handleSubmit">{{ submitting ? '提交中...' : '提交剪辑' }}</button>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { uploadFile, uploadImage, uploadVideo } from '@/api/app'
import { estimateSmartClip, generateSmartClip, getSmartClipResults, getSmartClipTemplates } from '@/apps/smart_clip/api'

const clipTypes = [
    { label: '真人口播', value: 'realman_broadcast', scene: 'realMan' },
    { label: '素材混剪', value: 'broadcast_mixcut', scene: 'oralMixCutting' },
    { label: '新闻体', value: 'news_mixcut', scene: 'newsMixCutting' },
]
const templates = ref<any[]>([])
const selectedTemplate = ref<any>(null)
const results = ref<any[]>([])
const estimateInfo = ref<any>({})
const submitting = ref(false)
let estimateTimer: ReturnType<typeof setTimeout> | null = null

const form = reactive<any>({
    api: 'realman_broadcast',
    styleId: '',
    title: '',
    videoUrl: '',
    duration: 0,
    audioUrl: '',
    audioDuration: 0,
    materials: [],
    introduceCard: { name: '', description: '' },
    packRules: { title: true, subtitle: true, material: true, keyword: false, bgm: true },
    processRules: { watermarkShow: true, materialMatchWay: 'preciseMatch', materialComposition: 'random', videoDuration: 30 },
    structLayers: [],
    source_app: '',
    source_result_id: 0,
})

const scene = computed(() => clipTypes.find((item) => item.value === form.api)?.scene || 'realMan')
const materialSummary = computed(() => {
    if (form.api === 'realman_broadcast') return form.videoUrl ? '已选择主视频' : '未选择主视频'
    const count = form.materials.length
    return count ? `${count}个素材` : '未添加素材'
})
const canSubmit = computed(() => {
    if (!form.styleId) return false
    if (form.api === 'realman_broadcast') return Boolean(form.videoUrl)
    if (form.api === 'broadcast_mixcut') return Boolean(form.audioUrl) && form.materials.length > 0
    return form.materials.length > 0
})

const templateId = (item: any) => String(item.id || item.styleId || item.style_id || '')
const typeText = (type: string) => ({ realman_broadcast: '真人口播', broadcast_mixcut: '素材混剪', news_mixcut: '新闻体' }[type] || type || '-')
const statusText = (value: string) => ({ success: '已完成', failed: '剪辑失败', running: '剪辑中', pending: '排队中' }[value] || value || '剪辑中')
const pickUri = (res: any) => res?.uri || res?.url || res?.path || res?.file_url || ''

const loadTemplates = async () => {
    const data: any = await getSmartClipTemplates({ scene: scene.value, pageSize: 20 })
    const list = data?.lists || data?.items || data?.data || data || []
    templates.value = Array.isArray(list) ? list : []
    if (!form.styleId && templates.value.length) {
        selectTemplate(templates.value[0])
    }
}

const loadResults = async () => {
    const rows: any = await getSmartClipResults()
    results.value = Array.isArray(rows) ? rows : []
}

const selectTemplate = (item: any) => {
    selectedTemplate.value = item
    form.styleId = templateId(item)
}

const switchType = async (type: string) => {
    form.api = type
    form.styleId = ''
    selectedTemplate.value = null
    await loadTemplates()
    updateEstimate()
}

const mediaDuration = (path: string, fallback = 0) => new Promise<number>((resolve) => {
    // UniApp has no universal metadata API across all terminals; use chooser duration when available.
    resolve(Math.ceil(Number(fallback || 0)))
})

const chooseVideo = async () => {
    const res: any = await uni.chooseVideo({ sourceType: ['album', 'camera'], maxDuration: form.api === 'realman_broadcast' ? 300 : 60 })
    const path = res?.tempFilePath || res?.tempFiles?.[0]?.tempFilePath
    if (!path) return
    uni.showLoading({ title: '上传中' })
    try {
        const upload: any = await uploadVideo(path)
        const url = pickUri(upload)
        const duration = await mediaDuration(path, res?.duration || 0)
        if (form.api === 'realman_broadcast') {
            form.videoUrl = url
            form.duration = duration
        } else {
            form.materials.push({ type: 'video', fileUrl: url, duration, soundSwitch: false, name: '视频素材' })
        }
        updateEstimate()
    } finally {
        uni.hideLoading()
    }
}

const chooseImage = async () => {
    const res: any = await uni.chooseImage({ count: 9, sizeType: ['compressed'] })
    const files = res?.tempFilePaths || []
    if (!files.length) return
    uni.showLoading({ title: '上传中' })
    try {
        for (const path of files) {
            const upload: any = await uploadImage(path)
            form.materials.push({ type: 'image', fileUrl: pickUri(upload), duration: 2, soundSwitch: false, name: '图片素材' })
        }
        updateEstimate()
    } finally {
        uni.hideLoading()
    }
}

const chooseAudio = async () => {
    const res: any = await uni.chooseMessageFile({ count: 1, type: 'file', extension: ['mp3', 'wav', 'm4a', 'aac'] })
    const path = res?.tempFiles?.[0]?.path
    if (!path) return
    uni.showLoading({ title: '上传中' })
    try {
        const upload: any = await uploadFile(path)
        form.audioUrl = pickUri(upload)
        form.audioDuration = Number(res?.tempFiles?.[0]?.duration || 0)
        updateEstimate()
    } finally {
        uni.hideLoading()
    }
}

const clearVideo = () => {
    form.videoUrl = ''
    form.duration = 0
    updateEstimate()
}
const clearAudio = () => {
    form.audioUrl = ''
    form.audioDuration = 0
    updateEstimate()
}
const removeMaterial = (index: number) => {
    form.materials.splice(index, 1)
    updateEstimate()
}
const togglePack = (key: string) => {
    form.packRules[key] = !form.packRules[key]
    updateEstimate()
}

const submitPayload = () => ({
    ...form,
    duration: form.api === 'realman_broadcast' ? form.duration : undefined,
    audio_duration: form.audioDuration || undefined,
})

const updateEstimate = () => {
    if (estimateTimer) clearTimeout(estimateTimer)
    estimateTimer = setTimeout(async () => {
        if (!form.styleId) return
        try {
            estimateInfo.value = await estimateSmartClip(submitPayload())
        } catch (e) {
            estimateInfo.value = {}
        }
    }, 300)
}

const handleSubmit = async () => {
    if (!canSubmit.value || submitting.value) return
    submitting.value = true
    uni.showLoading({ title: '提交中', mask: true })
    try {
        const res: any = await generateSmartClip(submitPayload())
        uni.$u.toast(res?.status === 'success' ? '剪辑完成' : '任务已提交')
        await loadResults()
        uni.navigateTo({ url: '/apps/smart_clip/pages/results/results' })
    } finally {
        submitting.value = false
        uni.hideLoading()
    }
}

const reuseResult = (item: any) => {
    form.api = item.clip_type || form.api
    form.title = item.title || ''
    form.styleId = item.style_id || ''
    form.duration = Number(item.duration || 0)
    updateEstimate()
}

const goBack = () => {
    const pages = getCurrentPages()
    pages.length > 1 ? uni.navigateBack() : uni.switchTab({ url: '/pages/index/index' })
}
const goResults = () => uni.navigateTo({ url: '/apps/smart_clip/pages/results/results' })
const goTasks = () => uni.navigateTo({ url: '/apps/smart_clip/pages/tasks/tasks' })

watch(() => [form.title, form.introduceCard.name, form.introduceCard.description, form.processRules.videoDuration], updateEstimate, { deep: true })

onLoad(async (query: any = {}) => {
    if (query.type) form.api = String(query.type)
    if (query.video_url) {
        form.videoUrl = String(query.video_url)
        form.duration = Number(query.duration || 0)
        if (form.api !== 'realman_broadcast') {
            form.materials.push({ type: 'video', fileUrl: form.videoUrl, duration: form.duration, soundSwitch: false, name: '导入视频' })
        }
    }
    form.source_app = String(query.source_app || '')
    form.source_result_id = Number(query.source_result_id || 0)
    await loadTemplates()
    updateEstimate()
})
onShow(loadResults)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #050505; color: #fff; padding-bottom: calc(132rpx + env(safe-area-inset-bottom)); }
.topbar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; height: 96rpx; padding: var(--status-bar-height) 28rpx 0; background: rgba(5, 5, 5, 0.94); }
.nav-btn, .nav-link { width: 96rpx; color: rgba(255, 255, 255, 0.76); font-size: 28rpx; }
.nav-btn { font-size: 56rpx; }
.nav-link { text-align: right; }
.title { font-size: 34rpx; font-weight: 700; }
.content { height: calc(100vh - 96rpx - 132rpx); padding: 24rpx 28rpx; box-sizing: border-box; }
.tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12rpx; padding: 8rpx; border-radius: 18rpx; background: #101012; }
.tab { height: 68rpx; line-height: 68rpx; text-align: center; border-radius: 14rpx; color: rgba(255,255,255,.62); font-size: 24rpx; font-weight: 700; }
.tab.is-active { background: #fff; color: #050505; }
.section, .cost-card { margin-top: 24rpx; padding: 24rpx; border: 1rpx solid rgba(255,255,255,.08); border-radius: 18rpx; background: #101012; }
.section-head, .material-row, .switch-row, .cost-card, .result-body, .duration-row { display: flex; align-items: center; justify-content: space-between; gap: 16rpx; }
.section-head { margin-bottom: 20rpx; font-size: 28rpx; font-weight: 700; }
.muted { color: rgba(255,255,255,.52); font-size: 23rpx; font-weight: 400; }
.template-scroll { width: 100%; white-space: nowrap; }
.template-list { display: flex; gap: 16rpx; }
.template-card { width: 220rpx; padding: 10rpx; border: 1rpx solid rgba(255,255,255,.08); border-radius: 14rpx; background: #171719; }
.template-card.is-active { border-color: #fff; }
.template-cover { width: 200rpx; height: 112rpx; border-radius: 10rpx; background: #2a2b2c; }
.template-empty { display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.5); }
.template-name { margin-top: 10rpx; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 23rpx; }
.upload-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14rpx; }
.upload-card { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 150rpx; gap: 8rpx; border: 1rpx dashed rgba(255,255,255,.2); border-radius: 14rpx; background: #171719; color: rgba(255,255,255,.72); font-size: 23rpx; }
.upload-plus { font-size: 44rpx; line-height: 1; }
.material-row { min-height: 64rpx; margin-top: 12rpx; padding: 0 16rpx; border-radius: 12rpx; background: #171719; color: rgba(255,255,255,.78); font-size: 24rpx; }
.danger { color: #ff8a8a; }
.input { box-sizing: border-box; width: 100%; height: 76rpx; margin-top: 12rpx; padding: 0 20rpx; border-radius: 12rpx; background: #171719; color: #fff; font-size: 25rpx; }
.two-input { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12rpx; }
.duration-row { margin-top: 16rpx; color: rgba(255,255,255,.74); font-size: 25rpx; }
.duration-input { width: 160rpx; height: 68rpx; text-align: center; border-radius: 12rpx; background: #171719; color: #fff; }
.switch-row { min-height: 76rpx; color: rgba(255,255,255,.82); font-size: 25rpx; }
.cost-card { font-size: 26rpx; font-weight: 700; }
.result-card { overflow: hidden; border-radius: 14rpx; background: #171719; }
.result-video, .result-placeholder { width: 100%; height: 360rpx; background: #06070a; }
.result-placeholder { display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.62); }
.result-body { padding: 16rpx; font-size: 24rpx; }
.bottom-bar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 10; display: grid; grid-template-columns: 170rpx minmax(0, 1fr); gap: 16rpx; padding: 18rpx 28rpx calc(18rpx + env(safe-area-inset-bottom)); background: rgba(5,5,5,.96); border-top: 1rpx solid rgba(255,255,255,.08); }
.secondary, .primary { height: 84rpx; border-radius: 14rpx; font-size: 27rpx; font-weight: 700; }
.secondary { background: #222; color: #fff; }
.primary { background: #fff; color: #050505; }
.primary[disabled] { opacity: .45; }
</style>
