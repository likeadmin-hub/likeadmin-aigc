<template>
    <div class="aigc-video-page">
        <section class="video-workspace">
            <div class="creator-panel">
                <div class="creator-head">
                    <div>
                        <p>AIGC VIDEO</p>
                        <h1>AIGC视频</h1>
                    </div>
                    <button class="icon-button" type="button" title="刷新" @click="refreshAll">
                        <span>↻</span>
                    </button>
                </div>

                <label class="field-label" for="video-prompt">创作内容描述</label>
                <ElInput
                    id="video-prompt"
                    v-model="form.prompt"
                    type="textarea"
                    :rows="8"
                    maxlength="1000"
                    show-word-limit
                    resize="none"
                    placeholder="描述视频主体、镜头运动、画面风格、光线和节奏..."
                />

                <div class="reference-block">
                    <div class="field-row">
                        <span>参考图</span>
                        <span>{{ referenceImages.length }}/{{ maxReferenceCount }}</span>
                    </div>
                    <div class="reference-list">
                        <button
                            v-for="(image, index) in referenceImages"
                            :key="image.uri"
                            class="reference-item"
                            type="button"
                            @click="previewReference(image.url)"
                        >
                            <img :src="image.url" :alt="image.name || '参考图'" />
                            <span @click.stop="removeReferenceImage(index)">×</span>
                        </button>
                        <button
                            v-if="referenceImages.length < maxReferenceCount"
                            class="reference-add"
                            type="button"
                            :disabled="uploading"
                            @click="triggerUpload"
                        >
                            <span>＋</span>
                            <strong>{{ uploading ? '上传中' : '上传图片' }}</strong>
                        </button>
                    </div>
                    <input ref="fileInputRef" class="sr-only" type="file" accept="image/*" multiple @change="handleUpload" />
                </div>

                <div v-if="channels.length > 1" class="option-group">
                    <div class="field-label">通道</div>
                    <div class="chip-grid">
                        <button
                            v-for="item in channels"
                            :key="item.value"
                            :class="['chip', { 'is-active': form.channel === item.value }]"
                            type="button"
                            @click="selectChannel(item.value)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <div class="option-grid">
                    <div class="option-group">
                        <div class="field-label">视频时长</div>
                        <div class="chip-grid chip-grid--tight">
                            <button
                                v-for="item in qualities"
                                :key="item.value"
                                :class="['chip', { 'is-active': form.quality === item.value }]"
                                type="button"
                                @click="selectQuality(item.value)"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                    </div>

                    <div class="option-group">
                        <div class="field-label">视频比例</div>
                        <div class="chip-grid chip-grid--tight">
                            <button
                                v-for="item in ratios"
                                :key="item.value"
                                :class="['chip', { 'is-active': form.ratio === item.value }]"
                                type="button"
                                @click="selectRatio(item.value)"
                            >
                                {{ item.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="negative-field">
                    <label class="field-label" for="negative-prompt">反向描述</label>
                    <ElInput
                        id="negative-prompt"
                        v-model="form.negative_prompt"
                        placeholder="可选，输入不希望出现的画面元素"
                    />
                </div>

                <div class="submit-bar">
                    <div>
                        <span>预计消耗</span>
                        <strong>{{ estimatedCost }} 点</strong>
                    </div>
                    <ElButton
                        type="primary"
                        size="large"
                        :loading="submitting || isGenerateLocked"
                        :disabled="!canSubmit || isGenerateLocked"
                        @click="handleGenerateLock"
                    >
                        生成视频
                    </ElButton>
                </div>
            </div>

            <div class="result-panel">
                <div class="panel-head">
                    <div>
                        <p>RESULTS</p>
                        <h2>作品与任务</h2>
                    </div>
                    <div class="status-tabs">
                        <button
                            v-for="item in statusTabs"
                            :key="item.value"
                            :class="{ 'is-active': resultStatus === item.value }"
                            type="button"
                            @click="switchStatus(item.value)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <div v-if="results.length" class="result-grid">
                    <article v-for="item in results" :key="item.task_id || item.id" class="result-card">
                        <video
                            v-if="item.video_url"
                            :src="normalizeVideoUrl(item.video_url)"
                            controls
                            playsinline
                            preload="metadata"
                        />
                        <div v-else class="video-placeholder" :class="`is-${item.status}`">
                            <span>{{ statusText(item.status) }}</span>
                        </div>
                        <div class="result-body">
                            <div class="result-meta">
                                <span>{{ formatSpec(item) }}</span>
                                <span>{{ formatTime(item.create_time) }}</span>
                            </div>
                            <p>{{ item.prompt || '视频作品' }}</p>
                            <div class="result-actions">
                                <button type="button" @click="reuseResult(item)">复用</button>
                                <button type="button" @click="handleDelete(item.task_id || item.id)">删除</button>
                            </div>
                        </div>
                    </article>
                </div>
                <div v-else class="empty-state">
                    <span>▶</span>
                    <strong>暂无视频作品</strong>
                    <p>提交任务后，生成进度和结果会在这里刷新。</p>
                </div>

                <div class="task-panel">
                    <div class="task-head">
                        <strong>最近任务</strong>
                        <span v-if="polling">生成中，自动刷新</span>
                    </div>
                    <div class="task-table-wrap">
                        <ElTable :data="tasks" size="large" class="dark-table">
                            <ElTableColumn label="ID" prop="id" width="80" />
                            <ElTableColumn label="提示词" prop="prompt" min-width="240" show-overflow-tooltip />
                            <ElTableColumn label="通道" prop="channel" width="150" />
                            <ElTableColumn label="时长" width="90">
                                <template #default="{ row }">{{ qualityLabel(row.quality) }}</template>
                            </ElTableColumn>
                            <ElTableColumn label="比例" prop="ratio" width="90" />
                            <ElTableColumn label="状态" width="100">
                                <template #default="{ row }">{{ statusText(row.status) }}</template>
                            </ElTableColumn>
                        </ElTable>
                    </div>
                </div>
            </div>
        </section>
        <ElImageViewer
            v-if="previewVisible"
            :url-list="referenceImages.map((item) => item.url)"
            :initial-index="previewIndex"
            @close="previewVisible = false"
        />
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElButton, ElInput, ElTable, ElTableColumn } from 'element-plus'
import { uploadImage as uploadAppImage } from '@/api/app'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import {
    deleteAigcVideoResult,
    generateAigcVideo,
    getAigcVideoConfig,
    getAigcVideoResults,
    getAigcVideoTasks
} from '@/apps/aigc_video/api'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'
import { normalizeFileUrl } from '@/utils/file-url'

interface RatioOption {
    label: string
    value: string
    width?: number
    height?: number
    tenant_unit_price?: string | number
}

interface QualityOption {
    label: string
    value: string
    ratios: RatioOption[]
}

interface ChannelOption {
    label: string
    value: string
    max_reference_images?: number
    quantity_options?: number[]
    qualities: QualityOption[]
}

interface ReferenceImage {
    uri: string
    url: string
    name: string
    objectUrl?: string
}

const submitting = ref(false)
const uploading = ref(false)
const tasks = ref<any[]>([])
const results = ref<any[]>([])
const resultStatus = ref('')
const polling = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const previewVisible = ref(false)
const previewIndex = ref(0)
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
let pollTimer: ReturnType<typeof setInterval> | null = null

const optionConfig = ref<any>({
    channels: [],
    defaults: {
        channel: 'grok_video_xaiq',
        quality: '6',
        ratio: '16:9',
        quantity: 1
    },
    quantity_options: [1],
    max_reference_images: 7
})

const form = reactive({
    prompt: '',
    channel: 'grok_video_xaiq',
    quality: '6',
    ratio: '16:9',
    quantity: 1,
    negative_prompt: ''
})
const referenceImages = ref<ReferenceImage[]>([])

const statusTabs = [
    { label: '全部', value: '' },
    { label: '生成中', value: 'running' },
    { label: '成功', value: 'success' },
    { label: '失败', value: 'failed' }
]

const channels = computed<ChannelOption[]>(() =>
    (optionConfig.value.channels || []).map((channel: any) => ({
        label: channel.label || channel.name || channel.code,
        value: channel.value || channel.code,
        max_reference_images: Number(channel.max_reference_images || optionConfig.value.max_reference_images || 7),
        quantity_options: (channel.quantity_options || optionConfig.value.quantity_options || [1])
            .map((item: any) => Number(item))
            .filter(Boolean),
        qualities: (channel.qualities || []).map((quality: any) => ({
            label: quality.label || quality.quality_label || `${quality.value || quality.quality}秒`,
            value: String(quality.value || quality.quality),
            ratios: (quality.ratios || []).map((ratio: any) => ({
                ...ratio,
                label: ratio.label || ratio.ratio || ratio.value,
                value: ratio.value || ratio.ratio
            }))
        }))
    }))
)
const currentChannel = computed(() => channels.value.find((item) => item.value === form.channel) || channels.value[0])
const qualities = computed<QualityOption[]>(() => currentChannel.value?.qualities || [])
const currentQuality = computed(() => qualities.value.find((item) => item.value === form.quality) || qualities.value[0])
const ratios = computed<RatioOption[]>(() => currentQuality.value?.ratios || [])
const currentSpec = computed(() => ratios.value.find((item) => item.value === form.ratio) || ratios.value[0])
const maxReferenceCount = computed(() => Number(currentChannel.value?.max_reference_images || optionConfig.value.max_reference_images || 7))
const estimatedCost = computed(() => Number((Number(currentSpec.value?.tenant_unit_price || 0) * form.quantity).toFixed(2)))
const canSubmit = computed(() => !!form.prompt.trim() && !!form.channel && !!form.quality && !!form.ratio && !submitting.value)

const normalizeVideoUrl = (url: unknown) => {
    let raw = String(url || '').trim()
    if (!raw) return ''
    raw = raw.replace(/\\/g, '/')
    if (/^(https?:)?\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('blob:')) {
        return raw
    }
    const normalized = normalizeFileUrl(raw)
    const apiUrl = String(getApiUrl() || '').replace(/\/+$/, '')
    if (apiUrl && normalized.startsWith('/uploads/')) {
        return `${apiUrl}${normalized}`
    }
    return normalized
}

const normalizeTimestamp = (value: unknown) => {
    if (typeof value === 'number' && value > 0) return value > 9999999999 ? Math.floor(value / 1000) : value
    if (typeof value === 'string' && value.trim()) {
        const numeric = Number(value)
        if (Number.isFinite(numeric) && numeric > 0) return numeric > 9999999999 ? Math.floor(numeric / 1000) : numeric
        const parsed = Date.parse(value.replace(/-/g, '/'))
        if (Number.isFinite(parsed)) return Math.floor(parsed / 1000)
    }
    return 0
}

const formatTime = (value: unknown) => {
    const timestamp = normalizeTimestamp(value)
    if (!timestamp) return '刚刚'
    return new Intl.DateTimeFormat('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    }).format(timestamp * 1000)
}

const qualityLabel = (value: string) => qualities.value.find((item) => item.value === String(value))?.label || (value ? `${value}秒` : '-')
const statusText = (value: string) => {
    const status = String(value || '')
    if (status === 'success') return '已完成'
    if (status === 'failed') return '生成失败'
    if (status === 'canceled') return '已取消'
    return '生成中'
}
const formatSpec = (item: any) => `${qualityLabel(item.quality)} · ${item.ratio || '-'}`

const syncSelection = () => {
    if (!channels.value.length) return
    if (!channels.value.some((item) => item.value === form.channel)) {
        form.channel = channels.value[0].value
    }
    if (!qualities.value.some((item) => item.value === form.quality)) {
        form.quality = qualities.value[0]?.value || ''
    }
    if (!ratios.value.some((item) => item.value === form.ratio)) {
        form.ratio = ratios.value[0]?.value || ''
    }
    if (referenceImages.value.length > maxReferenceCount.value) {
        referenceImages.value = referenceImages.value.slice(0, maxReferenceCount.value)
    }
}

const loadConfig = async () => {
    const config: any = await getAigcVideoConfig()
    optionConfig.value = config?.option_config || optionConfig.value
    const defaults = optionConfig.value.defaults || {}
    form.channel = defaults.channel || form.channel
    form.quality = defaults.quality || form.quality
    form.ratio = defaults.ratio || form.ratio
    syncSelection()
}

const loadData = async () => {
    if (!userStore.isLogin) {
        tasks.value = []
        results.value = []
        stopPolling()
        return
    }
    try {
        const [taskList, resultList] = await Promise.all([
            getAigcVideoTasks(),
            getAigcVideoResults(resultStatus.value ? { status: resultStatus.value } : undefined)
        ])
        tasks.value = Array.isArray(taskList) ? taskList : []
        results.value = Array.isArray(resultList) ? resultList : []
        const hasRunning = [...tasks.value, ...results.value].some((item) => item.status === 'running')
        if (hasRunning) {
            startPolling()
        } else {
            stopPolling()
        }
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}

const refreshAll = async () => {
    await loadData()
}

const startPolling = () => {
    polling.value = true
    if (pollTimer) return
    pollTimer = setInterval(() => {
        loadData()
    }, 5000)
}

const stopPolling = () => {
    polling.value = false
    if (!pollTimer) return
    clearInterval(pollTimer)
    pollTimer = null
}

const selectChannel = (value: string) => {
    form.channel = value
    form.quality = currentChannel.value?.qualities?.[0]?.value || form.quality
    form.ratio = currentQuality.value?.ratios?.[0]?.value || form.ratio
    syncSelection()
}

const selectQuality = (value: string) => {
    const nextQuality = qualities.value.find((item) => item.value === value)
    form.quality = value
    form.ratio = nextQuality?.ratios?.[0]?.value || form.ratio
    syncSelection()
}

const selectRatio = (value: string) => {
    form.ratio = value
    syncSelection()
}

const triggerUpload = () => {
    if (!ensurePcLogin()) return
    fileInputRef.value?.click()
}

const pickUploadUri = (res: any) => res?.uri || res?.url || res?.path || res?.file_url || ''

const handleUpload = async (event: Event) => {
    const input = event.target as HTMLInputElement
    const files = Array.from(input.files || [])
    if (!files.length) return
    const remain = maxReferenceCount.value - referenceImages.value.length
    if (remain <= 0) {
        feedback.msgError(`最多上传${maxReferenceCount.value}张参考图`)
        input.value = ''
        return
    }
    uploading.value = true
    try {
        for (const file of files.slice(0, remain)) {
            const objectUrl = URL.createObjectURL(file)
            try {
                const res: any = await uploadAppImage({ file })
                const uri = pickUploadUri(res)
                if (!uri) throw new Error('上传接口未返回图片地址')
                referenceImages.value.push({ uri, url: objectUrl, name: file.name, objectUrl })
            } catch (error) {
                URL.revokeObjectURL(objectUrl)
                throw error
            }
        }
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '参考图上传失败')
    } finally {
        uploading.value = false
        input.value = ''
    }
}

const previewReference = (url: string) => {
    previewIndex.value = Math.max(0, referenceImages.value.findIndex((item) => item.url === url))
    previewVisible.value = true
}

const removeReferenceImage = (index: number) => {
    const [removed] = referenceImages.value.splice(index, 1)
    if (removed?.objectUrl) URL.revokeObjectURL(removed.objectUrl)
}

const handleGenerate = async () => {
    if (submitting.value) return
    if (!form.prompt.trim()) return feedback.msgError('请输入创作内容描述')
    if (!ensurePcLogin()) return
    syncSelection()
    submitting.value = true
    try {
        const res: any = await generateAigcVideo({
            prompt: form.prompt.trim(),
            reference_images: referenceImages.value.map((item) => item.uri),
            ratio: form.ratio,
            quality: form.quality,
            quantity: 1,
            channel: form.channel,
            negative_prompt: form.negative_prompt
        })
        if (res?.status === 'failed') {
            feedback.msgError(res?.error || '生成任务提交失败')
        } else {
            feedback.msgSuccess(res?.status === 'running' ? '任务已提交，生成中' : '生成完成')
            form.prompt = ''
            form.negative_prompt = ''
            startPolling()
        }
        await loadData()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交生成任务失败')
    } finally {
        submitting.value = false
    }
}
const { lockFn: handleGenerateLock, isLock: isGenerateLocked } = useLockFn(handleGenerate)

const reuseResult = (item: any) => {
    form.prompt = item.prompt || form.prompt
    form.channel = item.channel || form.channel
    form.quality = item.quality || form.quality
    form.ratio = item.ratio || form.ratio
    syncSelection()
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const handleDelete = async (id: number) => {
    if (!ensurePcLogin()) return
    try {
        await deleteAigcVideoResult({ id, task_id: id })
        feedback.msgSuccess('删除成功')
        await loadData()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '删除失败')
    }
}

const switchStatus = async (value: string) => {
    resultStatus.value = value
    await loadData()
}

onMounted(async () => {
    await loadConfig()
    await loadData()
})

watch(() => userStore.isLogin, async (loggedIn) => {
    if (!loggedIn) stopPolling()
    await loadData()
})

onBeforeUnmount(() => {
    stopPolling()
    referenceImages.value.forEach((item) => {
        if (item.objectUrl) URL.revokeObjectURL(item.objectUrl)
    })
})
</script>

<style lang="scss" scoped>
.aigc-video-page {
    min-height: 100vh;
    padding: 28px;
    background: #050505;
    color: #fff;
}

.video-workspace {
    display: grid;
    grid-template-columns: minmax(380px, 480px) minmax(0, 1fr);
    gap: 24px;
    width: 100%;
    max-width: 1500px;
    margin: 0 auto;
}

.creator-panel,
.result-panel,
.task-panel {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    background: #101012;
}

.creator-panel {
    position: sticky;
    top: 24px;
    display: flex;
    flex-direction: column;
    gap: 22px;
    height: fit-content;
    padding: 22px;
}

.creator-head,
.panel-head,
.submit-bar,
.field-row,
.task-head,
.result-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.creator-head p,
.panel-head p {
    margin: 0 0 6px;
    color: rgba(255, 255, 255, 0.45);
    font-size: 12px;
    letter-spacing: 0;
}

.creator-head h1,
.panel-head h2 {
    margin: 0;
    color: #fff;
    font-size: 24px;
    line-height: 1.2;
}

.icon-button,
.chip,
.reference-item,
.reference-add,
.status-tabs button,
.result-actions button {
    border: 0;
    cursor: pointer;
}

.icon-button {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    background: #222;
    color: #fff;
    font-size: 20px;
}

.field-label,
.field-row {
    color: rgba(255, 255, 255, 0.78);
    font-size: 14px;
    font-weight: 600;
}

.reference-block {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.reference-list {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.reference-item,
.reference-add {
    position: relative;
    aspect-ratio: 1;
    overflow: hidden;
    border-radius: 8px;
    background: #171719;
    color: #fff;
}

.reference-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.reference-item span {
    position: absolute;
    top: 6px;
    right: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.62);
    color: #fff;
    line-height: 1;
}

.reference-add {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    border: 1px dashed rgba(255, 255, 255, 0.26);
}

.reference-add span {
    font-size: 28px;
}

.reference-add strong {
    font-size: 13px;
}

.option-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.option-group {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.chip-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.chip-grid--tight .chip {
    min-width: 72px;
}

.chip {
    min-height: 36px;
    padding: 0 14px;
    border-radius: 8px;
    background: #222;
    color: rgba(255, 255, 255, 0.68);
    font-size: 14px;
}

.chip.is-active {
    background: #fff;
    color: #08080a;
}

.negative-field {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.submit-bar {
    padding: 16px;
    border-radius: 8px;
    background: #171719;
}

.submit-bar span {
    display: block;
    color: rgba(255, 255, 255, 0.5);
    font-size: 13px;
}

.submit-bar strong {
    display: block;
    margin-top: 4px;
    color: #fff;
    font-size: 20px;
}

.result-panel {
    min-width: 0;
    padding: 22px;
}

.panel-head {
    margin-bottom: 20px;
}

.status-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 4px;
    border-radius: 8px;
    background: #171719;
}

.status-tabs button {
    height: 32px;
    padding: 0 14px;
    border-radius: 6px;
    background: transparent;
    color: rgba(255, 255, 255, 0.62);
}

.status-tabs button.is-active {
    background: #313233;
    color: #fff;
}

.result-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 16px;
}

.result-card {
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    background: #171719;
}

.result-card video,
.video-placeholder {
    width: 100%;
    aspect-ratio: 16 / 9;
    background: #06070a;
}

.video-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.62);
    font-weight: 700;
}

.video-placeholder.is-failed {
    color: #ff8a8a;
}

.result-body {
    padding: 14px;
}

.result-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
}

.result-body p {
    height: 44px;
    margin: 10px 0 14px;
    overflow: hidden;
    color: rgba(255, 255, 255, 0.82);
    font-size: 14px;
    line-height: 1.55;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.result-actions button {
    height: 32px;
    padding: 0 12px;
    border-radius: 6px;
    background: #222;
    color: #fff;
}

.result-actions button:last-child {
    color: rgba(255, 255, 255, 0.62);
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 300px;
    border: 1px dashed rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    color: rgba(255, 255, 255, 0.54);
}

.empty-state span {
    font-size: 40px;
}

.empty-state strong {
    margin-top: 12px;
    color: #fff;
    font-size: 18px;
}

.empty-state p {
    margin: 8px 0 0;
}

.task-panel {
    margin-top: 20px;
    padding: 18px;
}

.task-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.task-table-wrap :deep(.el-table) {
    min-width: 750px;
}

.task-head {
    margin-bottom: 14px;
}

.task-head span {
    color: rgba(255, 255, 255, 0.48);
    font-size: 13px;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

:deep(.el-textarea__inner),
:deep(.el-input__wrapper) {
    border-radius: 8px;
    background: #171719;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08) inset;
}

:deep(.el-textarea__inner),
:deep(.el-input__inner) {
    color: #fff;
}

:deep(.el-textarea__inner::placeholder),
:deep(.el-input__inner::placeholder) {
    color: rgba(255, 255, 255, 0.35);
}

:deep(.el-input__count) {
    background: transparent;
    color: rgba(255, 255, 255, 0.42);
}

:deep(.el-button--primary) {
    border-color: #fff;
    background: #fff;
    color: #08080a;
}

:deep(.dark-table),
:deep(.dark-table .el-table__inner-wrapper),
:deep(.dark-table tr),
:deep(.dark-table th.el-table__cell),
:deep(.dark-table td.el-table__cell) {
    background: #101012;
    color: rgba(255, 255, 255, 0.78);
}

:deep(.dark-table th.el-table__cell) {
    color: rgba(255, 255, 255, 0.48);
}

:deep(.dark-table .el-table__border-left-patch),
:deep(.dark-table .el-table__inner-wrapper::before) {
    background: rgba(255, 255, 255, 0.08);
}

@media (max-width: 1100px) {
    .video-workspace {
        grid-template-columns: 1fr;
    }

    .creator-panel {
        position: static;
    }
}

@media (max-width: 820px) {
    .aigc-video-page {
        padding: 18px;
    }

    .creator-panel,
    .result-panel {
        padding: 18px;
    }

    .panel-head,
    .submit-bar,
    .result-meta,
    .result-actions {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .option-grid {
        grid-template-columns: 1fr;
    }

    .result-grid {
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    }
}
</style>
