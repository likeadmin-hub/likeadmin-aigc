<template>
    <div class="smart-clip-page">
        <section class="clip-workspace">
            <div class="clip-panel clip-panel--form">
                <header class="clip-head">
                    <div>
                        <p>SMART CLIP</p>
                        <h1>AI视频剪辑</h1>
                    </div>
                    <button type="button" class="icon-button" title="刷新" @click="refreshAll">↻</button>
                </header>

                <div class="clip-tabs">
                    <button v-for="item in clipTypes" :key="item.value" :class="{ 'is-active': form.api === item.value }" type="button" @click="switchType(item.value)">{{ item.label }}</button>
                </div>

                <TemplatePicker :templates="templates" :selected-id="form.styleId" :loading="templateLoading" @refresh="loadNextTemplates" @select="selectTemplate" />

                <MaterialManager
                    :realman="form.api === 'realman_broadcast'"
                    :video-url="form.videoUrl"
                    :materials="form.materials"
                    @upload-video="triggerVideoUpload"
                    @upload-material="triggerMaterialUpload"
                    @upload-audio="triggerAudioUpload"
                    @remove="removeMaterial"
                />
                <input ref="videoInputRef" class="sr-only" type="file" accept="video/mp4,video/quicktime" @change="handleVideoUpload" />
                <input ref="materialInputRef" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime" multiple @change="handleMaterialUpload" />
                <input ref="audioInputRef" class="sr-only" type="file" accept="audio/*,.mp3,.wav,.m4a,.aac,.ogg,.flac,.opus" @change="handleAudioUpload" />

                <label class="field-label">标题</label>
                <ElInput v-model="form.title" maxlength="120" show-word-limit placeholder="新闻体视频必填，其他类型可留空" />

                <IntroduceCardForm v-model:name="form.introduceCard.name" v-model:description="form.introduceCard.description" />

                <div class="settings-panel">
                    <details open>
                        <summary>包装与处理</summary>
                        <PackRulesPanel :rules="form.packRules" />
                        <ProcessRulesPanel :clip-type="form.api" :rules="form.processRules" />
                        <StructLayersPanel :layers="form.structLayers" />
                    </details>
                </div>

                <div class="submit-bar">
                    <div><span>预计消耗</span><strong>{{ estimateInfo.user_charge_points || '0.00' }} 点</strong></div>
                    <ElButton type="primary" size="large" :loading="submitting" :disabled="!canSubmit" @click="submitClip">提交剪辑</ElButton>
                </div>
            </div>

            <TaskResultPanel
                :results="results"
                :latest-video="latestVideo"
                :template-name="selectedTemplate?.name || ''"
                :type-label="activeType?.label || ''"
                :status-text="statusText"
                :type-text="typeText"
                @refresh="refreshAll"
                @reuse="reuseTask"
                @delete="deleteItem"
            />
        </section>
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElButton, ElInput } from 'element-plus'
import { useRoute } from 'vue-router'
import { uploadFile, uploadImage, uploadVideo } from '@/api/app'
import feedback from '@/utils/feedback'
import { normalizeFileUrl } from '@/utils/file-url'
import { deleteSmartClipResult, estimateSmartClip, generateSmartClip, getSmartClipResults, getSmartClipTemplates } from '@/apps/smart_clip/api'
import TemplatePicker from '@/apps/smart_clip/components/TemplatePicker.vue'
import MaterialManager from '@/apps/smart_clip/components/MaterialManager.vue'
import IntroduceCardForm from '@/apps/smart_clip/components/IntroduceCardForm.vue'
import PackRulesPanel from '@/apps/smart_clip/components/PackRulesPanel.vue'
import ProcessRulesPanel from '@/apps/smart_clip/components/ProcessRulesPanel.vue'
import StructLayersPanel from '@/apps/smart_clip/components/StructLayersPanel.vue'
import TaskResultPanel from '@/apps/smart_clip/components/TaskResultPanel.vue'

const route = useRoute()
const DEFAULT_IMPORTED_VIDEO_DURATION = 30
const TEMPLATE_BATCH_SIZE = 12
const TEMPLATE_FETCH_SIZE = 36
const clipTypes = [
    { value: 'realman_broadcast', label: '真人口播混剪', scene: 'realMan' },
    { value: 'broadcast_mixcut', label: '素材混剪', scene: 'oralMixCutting' },
    { value: 'news_mixcut', label: '新闻体视频', scene: 'newsMixCutting' }
]
const form = reactive<any>({
    api: 'realman_broadcast', styleId: '', title: '', videoUrl: '', duration: 0, audioUrl: '', language: 'zh-CN',
    materials: [], introduceCard: { name: '', description: '' },
    packRules: { headerSwitch: true, materialSwitch: true, subtitleSwitch: true, keywordSwitch: true, backgroundMusic: { audioSwitch: false, volume: 0.3 } },
    processRules: { watermarkShow: true, firstFrameCover: false, resourcePreprocessMethod: '', materialMatchWay: 'preciseMatch', materialComposition: 'random', videoDuration: 30 },
    structLayers: [], subtitle: [], source_app: '', source_result_id: 0
})
const templates = ref<any[]>([])
const selectedTemplate = ref<any>(null)
const templateLoading = ref(false)
const templateSid = reactive<Record<string, string>>({})
const templatePools = reactive<Record<string, any[]>>({})
const templateBatchIndex = reactive<Record<string, number>>({})
const results = ref<any[]>([])
const estimateInfo = ref<any>({})
const submitting = ref(false)
const pollingTimer = ref<any>(null)
const videoInputRef = ref<HTMLInputElement>()
const materialInputRef = ref<HTMLInputElement>()
const audioInputRef = ref<HTMLInputElement>()
const activeType = computed(() => clipTypes.find((item) => item.value === form.api) || clipTypes[0])
const latestVideo = computed(() => (Array.isArray(results.value) ? results.value : []).find((item) => item.video_url)?.video_url || '')
const canSubmit = computed(() => {
    if (!form.styleId || submitting.value) return false
    if (form.api === 'realman_broadcast') return Boolean(form.videoUrl)
    if (form.api === 'broadcast_mixcut') return Boolean(form.audioUrl) && form.materials.length > 0
    return form.materials.length > 0
})
const inputDuration = computed(() => {
    if (form.api === 'news_mixcut') return normalizeDurationValue(form.processRules.videoDuration)
    if (form.api === 'realman_broadcast') return normalizeDurationValue(form.duration)
    const audioMaterial = form.materials.find((item: any) => item.type === 'audio' && item.fileUrl === form.audioUrl)
    const audioDuration = normalizeDurationValue(audioMaterial?.duration)
    if (audioDuration > 0) return audioDuration
    return materialDuration()
})
const canEstimate = computed(() => {
    if (!form.styleId || inputDuration.value <= 0) return false
    if (form.api === 'realman_broadcast') return Boolean(form.videoUrl)
    if (form.api === 'broadcast_mixcut') return form.materials.length > 0
    return true
})

watch(() => [form.api, form.styleId, form.videoUrl, form.duration, form.audioUrl, form.materials.length, form.processRules.videoDuration], () => updateEstimate(), { deep: true })

onMounted(async () => {
    hydrateFromRoute()
    await loadTemplates()
    await refreshAll()
})
onBeforeUnmount(() => pollingTimer.value && clearTimeout(pollingTimer.value))

function hydrateFromRoute() {
    const q: any = route.query || {}
    if (clipTypes.some((item) => item.value === q.type)) form.api = String(q.type)
    if (q.type === 'material') form.api = 'broadcast_mixcut'
    if (q.type === 'news') form.api = 'news_mixcut'
    if (q.video_url) {
        const url = String(q.video_url)
        const duration = normalizeDurationValue(q.duration || q.media_duration) || DEFAULT_IMPORTED_VIDEO_DURATION
        form.videoUrl = url
        form.duration = duration
        form.materials.push({ type: 'video', fileUrl: url, duration, soundSwitch: true, name: '导入视频' })
        detectRemoteVideoDuration(url)
    }
    form.source_app = String(q.source_app || '')
    form.source_result_id = Number(q.source_result_id || 0)
}
function normalizeList(res: any): any[] {
    const list = res?.lists
        || res?.items
        || res?.results
        || res?.data?.lists
        || res?.data?.items
        || res?.data?.results
        || res?.result?.data?.lists
        || res?.result?.data?.items
        || res?.result?.data?.results
        || res?.data
        || res
        || []
    return Array.isArray(list) ? list : []
}
function extractTemplateSid(res: any): string {
    const candidates = [
        res?.sid,
        res?.nextSid,
        res?.next_sid,
        res?.cursor,
        res?.nextCursor,
        res?.next_cursor,
        res?.data?.sid,
        res?.data?.nextSid,
        res?.data?.next_sid,
        res?.data?.cursor,
        res?.data?.nextCursor,
        res?.data?.next_cursor,
        res?.result?.sid,
        res?.result?.nextSid,
        res?.result?.next_sid,
        res?.result?.cursor,
        res?.result?.nextCursor,
        res?.result?.next_cursor,
        res?.result?.data?.sid,
        res?.result?.data?.nextSid,
        res?.result?.data?.next_sid,
        res?.result?.data?.cursor,
        res?.result?.data?.nextCursor,
        res?.result?.data?.next_cursor
    ]
    return String(candidates.find((item) => item !== undefined && item !== null && item !== '') || '')
}
async function loadTemplates(options: { next?: boolean } = {}) {
    if (templateLoading.value) return
    const api = form.api
    if (options.next && renderNextLocalTemplateBatch(api)) return
    const type = activeType.value
    const params: any = { scene: type.scene, pageSize: TEMPLATE_FETCH_SIZE, sortBy: 'desc' }
    if (options.next && templateSid[api]) params.sid = templateSid[api]
    templateLoading.value = true
    const res: any = await getSmartClipTemplates(params, { suppressErrorMessage: true }).catch(() => null)
    templateLoading.value = false
    if (api !== form.api) return
    const rows = normalizeList(res)
    const nextSid = extractTemplateSid(res)
    if (!rows.length && options.next) {
        templateSid[api] = ''
        templateBatchIndex[api] = 0
        renderTemplateBatch(api, true)
        await nextTick()
        return
    }
    if (!rows.length) return
    templateSid[api] = nextSid
    templatePools[api] = rows
    templateBatchIndex[api] = 0
    renderTemplateBatch(api, options.next)
}
async function loadNextTemplates() {
    await loadTemplates({ next: true })
}
function templateId(item: any) { return String(item.id || item.styleId || item.style_id || '') }
function selectTemplate(item: any) { selectedTemplate.value = item; form.styleId = templateId(item) }
function switchType(type: string) { form.api = type; form.styleId = ''; selectedTemplate.value = null; estimateInfo.value = {}; loadTemplates() }
function renderTemplateBatch(api: string, forceSelect = false) {
    const pool = templatePools[api] || []
    const index = Math.max(0, templateBatchIndex[api] || 0)
    const rows = pool.slice(index * TEMPLATE_BATCH_SIZE, (index + 1) * TEMPLATE_BATCH_SIZE)
    if (!rows.length) return false
    templates.value = rows
    const hasSelected = rows.some((item) => templateId(item) === form.styleId)
    if (forceSelect || !form.styleId || !hasSelected) selectTemplate(rows[0])
    return true
}
function renderNextLocalTemplateBatch(api: string) {
    const pool = templatePools[api] || []
    const nextIndex = (templateBatchIndex[api] || 0) + 1
    if (nextIndex * TEMPLATE_BATCH_SIZE >= pool.length) return false
    templateBatchIndex[api] = nextIndex
    return renderTemplateBatch(api, true)
}
function triggerVideoUpload() { videoInputRef.value?.click() }
function triggerMaterialUpload() { materialInputRef.value?.click() }
function triggerAudioUpload() { audioInputRef.value?.click() }
async function handleVideoUpload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    const res: any = await uploadVideo({ file })
    form.videoUrl = normalizeFileUrl(res.uri || res.url || '')
    form.duration = await mediaDuration(file) || DEFAULT_IMPORTED_VIDEO_DURATION
}
async function handleMaterialUpload(e: Event) {
    const files = Array.from((e.target as HTMLInputElement).files || [])
    for (const file of files) {
        const isVideo = file.type.startsWith('video/')
        const res: any = isVideo ? await uploadVideo({ file }) : await uploadImage({ file })
        form.materials.push({ type: isVideo ? 'video' : 'image', fileUrl: normalizeFileUrl(res.uri || res.url || ''), duration: isVideo ? await mediaDuration(file) : 2, soundSwitch: false, name: file.name })
    }
}
async function handleAudioUpload(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]
    if (!file) return
    const res: any = await uploadFile({ file })
    const audioUrl = normalizeFileUrl(res.uri || res.url || '')
    form.audioUrl = audioUrl
    form.materials.push({ type: 'audio', fileUrl: audioUrl, duration: await audioDuration(file), soundSwitch: false, name: file.name })
}
function mediaDuration(file: File): Promise<number> {
    return new Promise((resolve) => {
        const el = document.createElement('video')
        el.preload = 'metadata'
        el.onloadedmetadata = () => { URL.revokeObjectURL(el.src); resolve(Math.ceil(el.duration || 0)) }
        el.onerror = () => resolve(0)
        el.src = URL.createObjectURL(file)
    })
}
function audioDuration(file: File): Promise<number> {
    return new Promise((resolve) => {
        const el = document.createElement('audio')
        el.preload = 'metadata'
        el.onloadedmetadata = () => { URL.revokeObjectURL(el.src); resolve(Math.ceil(el.duration || 0)) }
        el.onerror = () => resolve(0)
        el.src = URL.createObjectURL(file)
    })
}
function removeMaterial(index: number) { form.materials.splice(index, 1) }
async function updateEstimate() {
    if (!canEstimate.value) {
        estimateInfo.value = {}
        return
    }
    estimateInfo.value = await estimateSmartClip(buildPayload(), { suppressErrorMessage: true }).catch(() => ({}))
}
function buildPayload() {
    return JSON.parse(JSON.stringify({
        ...form,
        duration: inputDuration.value || undefined,
        media_duration: inputDuration.value || undefined,
        video_duration: form.api === 'realman_broadcast' ? inputDuration.value || undefined : undefined,
        audio_duration: form.api === 'broadcast_mixcut' && form.audioUrl ? inputDuration.value || undefined : undefined
    }))
}
async function submitClip() {
    if (!inputDuration.value) {
        feedback.msgError('请先上传或选择带时长的视频/音频素材')
        return
    }
    submitting.value = true
    try {
        const res: any = await generateSmartClip(buildPayload())
        feedback.msgSuccess('已提交剪辑任务')
        await refreshAll()
        pollUntilDone(res.task_id)
    } catch (e: any) { feedback.msgError(e?.message || '提交失败') } finally { submitting.value = false }
}
async function refreshAll() { await loadResults() }
async function loadResults() {
    const res = await getSmartClipResults().catch(() => [])
    results.value = normalizeList(res)
}
function pollUntilDone(taskId?: number) {
    pollingTimer.value && clearTimeout(pollingTimer.value)
    pollingTimer.value = setTimeout(async () => {
        await loadResults()
        const task = (Array.isArray(results.value) ? results.value : []).find((item) => Number(item.task_id) === Number(taskId))
        if (task && ['success', 'failed', 'canceled'].includes(task.status)) return
        pollUntilDone(taskId)
    }, 5000)
}
async function deleteItem(item: any) { await deleteSmartClipResult({ task_id: item.task_id || item.id }); await loadResults() }
function reuseTask(item: any) {
    Object.assign(form, { api: item.clip_type || form.api, title: item.title || '', styleId: item.style_id || '', duration: item.duration || 0 })
}
function statusText(status: string) { return ({ running: '处理中', success: '已完成', failed: '已失败', pending: '排队中' } as any)[status] || status || '-' }
function typeText(type: string) { return clipTypes.find((item) => item.value === type)?.label || '剪辑作品' }
function normalizeDurationValue(value: any): number {
    if (typeof value === 'number') return Number.isFinite(value) && value > 0 ? Math.ceil(value) : 0
    const raw = String(value || '').trim()
    if (!raw) return 0
    if (/^\d+(\.\d+)?$/.test(raw)) return Math.ceil(Number(raw))
    const parts = raw.split(':').map((item) => Number(item))
    if (parts.length > 1 && parts.every((item) => Number.isFinite(item))) {
        return Math.ceil(parts.reduce((total, part) => total * 60 + part, 0))
    }
    const numeric = Number(raw.replace(/[^\d.]+/g, ''))
    return Number.isFinite(numeric) && numeric > 0 ? Math.ceil(numeric) : 0
}
function materialDuration() {
    return form.materials.reduce((total: number, item: any) => {
        const duration = normalizeDurationValue(item.duration)
        if (duration > 0) return total + duration
        return total + (item.type === 'image' ? 2 : 0)
    }, 0)
}
function detectRemoteVideoDuration(url: string) {
    if (typeof document === 'undefined' || !url) return
    const el = document.createElement('video')
    el.preload = 'metadata'
    el.muted = true
    el.playsInline = true
    el.onloadedmetadata = () => {
        const duration = normalizeDurationValue(el.duration)
        if (duration > 0) {
            form.duration = duration
            const imported = form.materials.find((item: any) => item.fileUrl === url)
            if (imported) imported.duration = duration
        }
        el.src = ''
    }
    el.onerror = () => { el.src = '' }
    el.src = url
}
</script>

<style scoped>
.smart-clip-page { min-height: 100%; background: transparent; color: #fff; padding: 40px 0 24px; box-sizing: border-box; }
.clip-workspace { display: grid; grid-template-columns: minmax(420px, 520px) minmax(0, 1fr); gap: 18px; max-width: 1480px; margin: 0 auto; }
.clip-panel { background: #101012; border: 1px solid rgba(255,255,255,.1); border-radius: 8px; padding: 18px; }
.clip-head, .submit-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.clip-head p { color: rgba(255,255,255,.45); margin: 0 0 4px; font-size: 12px; } .clip-head h1 { margin: 0; font-size: 28px; }
.icon-button { background: #222; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; padding: 8px 10px; }
.clip-tabs { display: flex; gap: 8px; margin: 18px 0; }
.clip-tabs button { flex: 1; height: 38px; border-radius: 6px; border: 1px solid rgba(255,255,255,.12); background: #171719; color: rgba(255,255,255,.72); }
.clip-tabs .is-active { background: #fff; color: #050505; }
.field-label { display: block; margin: 16px 0 8px; color: rgba(255,255,255,.76); }
.settings-panel { margin-top: 16px; background: #171719; border-radius: 8px; padding: 12px; }
.submit-bar { margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.08); }
.sr-only { display: none; }
@media (max-width: 980px) { .clip-workspace { grid-template-columns: 1fr; } }
</style>
