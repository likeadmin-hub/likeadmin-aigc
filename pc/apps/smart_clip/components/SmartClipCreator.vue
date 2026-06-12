<template>
    <div class="smart-clip-page">
        <section class="clip-workspace">
            <div class="clip-panel clip-panel--form">
                <header class="clip-head">
                    <div>
                        <p>SMART CLIP</p>
                        <h1>AI视频剪辑</h1>
                    </div>
                </header>

                <div class="clip-panel__body">
                    <div class="clip-tabs">
                        <button v-for="item in clipTypes" :key="item.value" :class="{ 'is-active': form.api === item.value }" type="button" @click="switchType(item.value)">{{ item.label }}</button>
                    </div>

                    <TemplatePicker :templates="visibleTemplates" :selected-id="form.styleId" :loading="templateLoading" @open-more="openTemplateModal" @select="selectTemplate" />

                    <MaterialManager
                        :realman="form.api === 'realman_broadcast'"
                        :video-url="form.videoUrl"
                        :video-upload-preview="videoUploadPreview"
                        :video-uploading="videoUploading"
                        :material-uploading="materialUploading || audioUploading"
                        :materials="form.materials"
                        @upload-video="triggerVideoUpload"
                        @upload-material="triggerMaterialUpload"
                        @upload-audio="triggerAudioUpload"
                        @remove="removeMaterial"
                    />
                    <input ref="videoInputRef" class="sr-only" type="file" accept="video/mp4,video/quicktime" @change="handleVideoUpload" />
                    <input ref="materialInputRef" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp,video/mp4,video/quicktime" multiple @change="handleMaterialUpload" />
                    <input ref="audioInputRef" class="sr-only" type="file" accept="audio/*,.mp3,.wav,.m4a,.aac,.ogg,.flac,.opus" @change="handleAudioUpload" />

                    <div class="field-block">
                        <label class="field-label">标题</label>
                        <ElInput v-model="form.title" maxlength="120" show-word-limit placeholder="新闻体视频必填，其他类型可留空" />
                    </div>

                    <IntroduceCardForm v-model:name="form.introduceCard.name" v-model:description="form.introduceCard.description" />

                    <div class="settings-panel">
                        <details open>
                            <summary>视频包装</summary>
                            <PackRulesPanel :rules="form.packRules" />
                        </details>
                        <details open>
                            <summary>处理设置</summary>
                            <ProcessRulesPanel :clip-type="form.api" :rules="form.processRules" />
                        </details>
                        <details open>
                            <summary>图层设置</summary>
                            <StructLayersPanel :layers="form.structLayers" />
                        </details>
                    </div>
                </div>

                <div class="submit-bar">
                    <div><span>预计消耗</span><strong>{{ estimateInfo.user_charge_points || '0.00' }} 点</strong></div>
                    <ElButton type="primary" size="large" :loading="submitting" :disabled="!canSubmit" @click="submitClip">提交剪辑</ElButton>
                </div>
            </div>

            <TaskResultPanel
                :results="results"
                :latest-video="latestVideo"
                :template-preview-video="selectedTemplatePreviewVideo"
                :template-cover="selectedTemplateCover"
                :template-canvas="selectedTemplateCanvas"
                :template-name="selectedTemplate?.name || ''"
                :type-label="activeType?.label || ''"
                :status-text="statusText"
                :type-text="typeText"
                @refresh="refreshAll"
                @reuse="reuseTask"
                @delete="deleteItem"
            />
        </section>

        <Teleport to="body">
            <Transition name="template-modal-fade">
                <div v-if="showTemplateModal" class="template-modal-mask" @click.self="closeTemplateModal">
                    <section class="template-modal" aria-modal="true" role="dialog">
                        <header class="template-modal__header">
                            <div class="template-modal__title">
                                <strong>选择剪辑模板</strong>
                                <span>{{ activeType?.label || 'AI视频剪辑' }} · 已加载 {{ currentTemplatePool.length }} 个模板</span>
                            </div>
                            <button class="template-modal__close" type="button" aria-label="关闭模板弹窗" @click="closeTemplateModal">
                                <span></span>
                                <span></span>
                            </button>
                        </header>

                        <div class="template-modal__grid">
                            <button
                                v-for="item in currentTemplatePool"
                                :key="templateId(item)"
                                type="button"
                                class="template-modal-card"
                                :class="{ 'is-active': form.styleId === templateId(item) }"
                                @click="selectTemplateFromModal(item)"
                            >
                                <img v-if="item.coverUrl || item.cover_url" :src="item.coverUrl || item.cover_url" :alt="item.name || '剪辑模板'" />
                                <div v-else class="template-modal-card__empty">模板</div>
                                <span class="template-modal-card__shade"></span>
                                <div class="template-modal-card__meta">
                                    <strong>{{ item.name || '未命名模板' }}</strong>
                                    <span>{{ activeType?.label || '剪辑模板' }}</span>
                                </div>
                            </button>

                            <div v-if="!currentTemplatePool.length && !templateLoading" class="template-modal__empty">
                                <strong>暂无可选模板</strong>
                                <span>请稍后刷新或切换剪辑类型</span>
                            </div>
                        </div>

                        <footer class="template-modal__footer">
                            <span>{{ templateSid[form.api] ? '还有更多模板可加载' : '已展示当前可用模板' }}</span>
                            <button type="button" :disabled="templateLoading || !templateSid[form.api]" @click="loadMoreTemplatesInModal">
                                {{ templateLoading ? '加载中' : '加载更多' }}
                            </button>
                        </footer>
                    </section>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElButton, ElInput } from 'element-plus'
import { useRoute } from 'vue-router'
import { uploadFile, uploadImage, uploadVideo } from '@/api/app'
import feedback from '@/utils/feedback'
import { normalizeFileUrl } from '@/utils/file-url'
import { deleteSmartClipResult, estimateSmartClip, generateSmartClip, getSmartClipResults, getSmartClipTemplateDetail, getSmartClipTemplates } from '@/apps/smart_clip/api'
import TemplatePicker from '@/apps/smart_clip/components/TemplatePicker.vue'
import MaterialManager from '@/apps/smart_clip/components/MaterialManager.vue'
import IntroduceCardForm from '@/apps/smart_clip/components/IntroduceCardForm.vue'
import PackRulesPanel from '@/apps/smart_clip/components/PackRulesPanel.vue'
import ProcessRulesPanel from '@/apps/smart_clip/components/ProcessRulesPanel.vue'
import StructLayersPanel from '@/apps/smart_clip/components/StructLayersPanel.vue'
import TaskResultPanel from '@/apps/smart_clip/components/TaskResultPanel.vue'

const route = useRoute()
const DEFAULT_IMPORTED_VIDEO_DURATION = 30
const VISIBLE_TEMPLATE_SIZE = 6
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
const selectedTemplate = ref<any>(null)
const selectedTemplateCanvas = ref<{ width: number; height: number } | null>(null)
const templateLoading = ref(false)
const showTemplateModal = ref(false)
const templateSid = reactive<Record<string, string>>({})
const templatePools = reactive<Record<string, any[]>>({})
const results = ref<any[]>([])
const estimateInfo = ref<any>({})
const submitting = ref(false)
const videoUploading = ref(false)
const materialUploading = ref(false)
const audioUploading = ref(false)
const videoUploadPreview = ref('')
const pollingTimer = ref<any>(null)
const videoInputRef = ref<HTMLInputElement>()
const materialInputRef = ref<HTMLInputElement>()
const audioInputRef = ref<HTMLInputElement>()
const activeType = computed(() => clipTypes.find((item) => item.value === form.api) || clipTypes[0])
const currentTemplatePool = computed(() => templatePools[form.api] || [])
const visibleTemplates = computed(() => {
    const pool = currentTemplatePool.value
    const visible = pool.slice(0, VISIBLE_TEMPLATE_SIZE)
    if (!form.styleId || visible.some((item) => templateId(item) === form.styleId)) return visible
    const selected = pool.find((item) => templateId(item) === form.styleId)
    return selected ? [selected, ...visible.slice(0, VISIBLE_TEMPLATE_SIZE - 1)] : visible
})
const latestVideo = computed(() => (Array.isArray(results.value) ? results.value : []).find((item) => item.video_url)?.video_url || '')
const selectedTemplatePreviewVideo = computed(() => templatePreviewVideo(selectedTemplate.value))
const selectedTemplateCover = computed(() => String(selectedTemplate.value?.coverUrl || selectedTemplate.value?.cover_url || ''))
const canSubmit = computed(() => {
    if (!form.styleId || submitting.value || isUploading.value) return false
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
    if (!form.styleId || inputDuration.value <= 0 || isUploading.value) return false
    if (form.api === 'realman_broadcast') return Boolean(form.videoUrl)
    if (form.api === 'broadcast_mixcut') return form.materials.length > 0
    return true
})
const isUploading = computed(() => videoUploading.value || materialUploading.value || audioUploading.value)
let templateDetailRequestId = 0

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
        await nextTick()
        return
    }
    if (!rows.length) return
    templateSid[api] = nextSid
    templatePools[api] = options.next ? mergeTemplates(templatePools[api] || [], rows) : rows
    if (!form.styleId) selectTemplate(templatePools[api][0])
}
async function loadMoreTemplatesInModal() {
    await loadTemplates({ next: true })
}
function templateId(item: any) { return String(item.id || item.styleId || item.style_id || '') }
function templatePreviewVideo(item: any) {
    return String(item?.previewUrl || item?.preview_url || item?.videoUrl || item?.video_url || item?.demoUrl || item?.demo_url || '')
}
function templateCanvas(item: any): { width: number; height: number } | null {
    const candidates = [
        item?.videoStructInfo?.editInfo?.canvas,
        item?.video_struct_info?.edit_info?.canvas,
        item?.video_struct_info?.editInfo?.canvas,
        item?.videoStructInfo?.canvas,
        item?.canvas,
        item?.detail?.videoStructInfo?.editInfo?.canvas,
        item?.data?.videoStructInfo?.editInfo?.canvas
    ]
    const canvas = candidates.find((value) => value && typeof value === 'object')
    const width = Number(canvas?.width || canvas?.w || canvas?.canvasWidth || canvas?.canvas_width)
    const height = Number(canvas?.height || canvas?.h || canvas?.canvasHeight || canvas?.canvas_height)
    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) return null
    return { width, height }
}
function normalizeTemplateDetail(res: any) {
    return res?.data?.data || res?.result?.data || res?.data || res?.result || res || null
}
function selectTemplate(item: any) {
    selectedTemplate.value = item
    selectedTemplateCanvas.value = templateCanvas(item)
    form.styleId = templateId(item)
    loadTemplateDetail(item)
}
async function loadTemplateDetail(item: any) {
    const id = templateId(item)
    if (!id) return
    const requestId = ++templateDetailRequestId
    const api = form.api
    const res: any = await getSmartClipTemplateDetail({ id }, { suppressErrorMessage: true }).catch(() => null)
    if (requestId !== templateDetailRequestId || api !== form.api || form.styleId !== id || !res) return
    const detail = normalizeTemplateDetail(res)
    if (!detail || typeof detail !== 'object') return
    selectedTemplate.value = { ...item, ...detail }
    selectedTemplateCanvas.value = templateCanvas(detail) || templateCanvas(selectedTemplate.value)
}
function switchType(type: string) { form.api = type; form.styleId = ''; selectedTemplate.value = null; selectedTemplateCanvas.value = null; estimateInfo.value = {}; loadTemplates() }
function mergeTemplates(base: any[], incoming: any[]) {
    const seen = new Set(base.map((item) => templateId(item)).filter(Boolean))
    const next = [...base]
    incoming.forEach((item) => {
        const id = templateId(item)
        if (id && seen.has(id)) return
        if (id) seen.add(id)
        next.push(item)
    })
    return next
}
function openTemplateModal() {
    showTemplateModal.value = true
}
function closeTemplateModal() {
    showTemplateModal.value = false
}
function selectTemplateFromModal(item: any) {
    selectTemplate(item)
    closeTemplateModal()
}
function triggerVideoUpload() {
    if (videoUploading.value) return
    videoInputRef.value?.click()
}
function triggerMaterialUpload() {
    if (materialUploading.value) return
    materialInputRef.value?.click()
}
function triggerAudioUpload() {
    if (audioUploading.value) return
    audioInputRef.value?.click()
}
async function handleVideoUpload(e: Event) {
    const target = e.target as HTMLInputElement
    const file = target.files?.[0]
    target.value = ''
    if (!file) return
    const objectUrl = URL.createObjectURL(file)
    videoUploadPreview.value = objectUrl
    videoUploading.value = true
    try {
        const [res, duration] = await Promise.all([
            uploadVideo({ file }),
            mediaDuration(file)
        ])
        form.videoUrl = normalizeFileUrl((res as any).uri || (res as any).url || '')
        form.duration = duration || DEFAULT_IMPORTED_VIDEO_DURATION
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || '视频上传失败')
    } finally {
        videoUploading.value = false
        videoUploadPreview.value = ''
        URL.revokeObjectURL(objectUrl)
    }
}
async function handleMaterialUpload(e: Event) {
    const target = e.target as HTMLInputElement
    const files = Array.from(target.files || [])
    target.value = ''
    if (!files.length) return
    materialUploading.value = true
    try {
        for (const file of files) {
            const isVideo = file.type.startsWith('video/')
            const objectUrl = URL.createObjectURL(file)
            const pending = {
                type: isVideo ? 'video' : 'image',
                fileUrl: objectUrl,
                duration: isVideo ? 0 : 2,
                soundSwitch: false,
                name: file.name,
                uploading: true,
                isObjectUrl: true
            }
            form.materials.push(pending)
            try {
                const [res, duration] = await Promise.all([
                    isVideo ? uploadVideo({ file }) : uploadImage({ file }),
                    isVideo ? mediaDuration(file) : Promise.resolve(2)
                ])
                Object.assign(pending, {
                    fileUrl: normalizeFileUrl((res as any).uri || (res as any).url || ''),
                    duration: isVideo ? duration : 2,
                    uploading: false,
                    isObjectUrl: false
                })
            } catch (error: any) {
                const index = form.materials.indexOf(pending)
                if (index >= 0) form.materials.splice(index, 1)
                feedback.msgError(error?.msg || error?.message || '素材上传失败')
            } finally {
                URL.revokeObjectURL(objectUrl)
            }
        }
    } finally {
        materialUploading.value = false
    }
}
async function handleAudioUpload(e: Event) {
    const target = e.target as HTMLInputElement
    const file = target.files?.[0]
    target.value = ''
    if (!file) return
    const objectUrl = URL.createObjectURL(file)
    const pending = { type: 'audio', fileUrl: objectUrl, duration: 0, soundSwitch: false, name: file.name, uploading: true, isObjectUrl: true }
    audioUploading.value = true
    form.materials.push(pending)
    try {
        const [res, duration] = await Promise.all([
            uploadFile({ file }),
            audioDuration(file)
        ])
        const audioUrl = normalizeFileUrl((res as any).uri || (res as any).url || '')
        Object.assign(pending, { fileUrl: audioUrl, duration, uploading: false, isObjectUrl: false })
        form.audioUrl = audioUrl
    } catch (error: any) {
        const index = form.materials.indexOf(pending)
        if (index >= 0) form.materials.splice(index, 1)
        feedback.msgError(error?.msg || error?.message || '音频上传失败')
    } finally {
        audioUploading.value = false
        URL.revokeObjectURL(objectUrl)
    }
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
function removeMaterial(index: number) {
    const item = form.materials[index]
    if (item?.isObjectUrl && item.fileUrl) URL.revokeObjectURL(item.fileUrl)
    form.materials.splice(index, 1)
}
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
.smart-clip-page {
    height: 100%;
    min-height: 0;
    padding: 0;
    background: transparent;
    color: #fff;
    box-sizing: border-box;
}

.clip-workspace {
    display: grid;
    grid-template-columns: 403px minmax(0, 1fr);
    align-items: stretch;
    gap: 24px;
    width: 100%;
    height: 100%;
    min-width: calc(403px + 24px + 560px);
    min-height: 0;
    margin: 0 auto;
    box-sizing: border-box;
}

.clip-panel {
    position: relative;
    min-width: 0;
    min-height: 0;
    border: 0;
    border-radius: 20px;
    background: #0f0f0f;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    overflow: hidden;
    box-sizing: border-box;
}

.clip-panel--form {
    display: flex;
    flex-direction: column;
    height: 100%;
}

.clip-head,
.submit-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 12px;
}

.clip-head {
    min-height: 56px;
    padding: 0 20px;
    border-bottom: 0;
}

.clip-head p {
    display: none;
    margin: 0;
    color: rgba(255, 255, 255, 0.38);
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
}

.clip-head h1 {
    margin: 0;
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
}

.clip-panel__body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: 32px;
    min-height: 0;
    padding: 20px 20px 112px;
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scroll-padding-bottom: 124px;
    scrollbar-color: #242424 transparent;
    scrollbar-width: thin;
}

.clip-panel__body::-webkit-scrollbar {
    width: 6px;
}

.clip-panel__body::-webkit-scrollbar-track {
    background: transparent;
}

.clip-panel__body::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.clip-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.clip-tabs button {
    min-width: 0;
    height: 48px;
    padding: 0 8px;
    border: 1px solid #222;
    border-radius: 10px;
    background: #171719;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.clip-tabs button:hover {
    border-color: rgba(255, 255, 255, 0.28);
    background: #171719;
    color: #fff;
}

.clip-tabs .is-active {
    border-color: #fff;
    background: #fff;
    color: #050505;
}

.field-block {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.field-label {
    display: block;
    color: rgba(255, 255, 255, 0.86);
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
}

.settings-panel {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 14px 12px;
    border: 1px solid #222;
    border-radius: 8px;
    background: #0f0f0f;
}

.settings-panel details {
    display: block;
}

.settings-panel summary {
    color: rgba(255, 255, 255, 0.9);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
}

.submit-bar {
    position: sticky;
    right: 0;
    bottom: 0;
    left: 0;
    z-index: 3;
    margin-top: -84px;
    padding: 20px 20px calc(20px + env(safe-area-inset-bottom));
    border-top: 0;
    background: #0f0f0f;
    box-shadow: 0 -18px 24px rgba(15, 15, 15, 0.92);
}

.submit-bar div {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.submit-bar span {
    color: rgba(255, 255, 255, 0.42);
    font-size: 12px;
    line-height: 1;
}

.submit-bar strong {
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.1;
}

.submit-bar :deep(.el-button) {
    min-width: 132px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    background: #fff;
    color: #111;
    font-size: 14px;
    font-weight: 600;
}

.submit-bar :deep(.el-button.is-disabled) {
    background: rgba(255, 255, 255, 0.24);
    color: rgba(255, 255, 255, 0.5);
}

.field-block :deep(.el-input__wrapper),
:deep(.two-grid .el-input__wrapper),
.settings-panel :deep(.el-input__wrapper),
.settings-panel :deep(.el-input-number__decrease),
.settings-panel :deep(.el-input-number__increase) {
    min-height: 40px;
    border: 1px solid #222;
    border-radius: 8px;
    background: #0f0f0f;
    box-shadow: none;
}

.field-block :deep(.el-input__inner),
:deep(.two-grid .el-input__inner),
.settings-panel :deep(.el-input__inner) {
    color: #fff;
}

.field-block :deep(.el-input__count),
:deep(.two-grid .el-input__count),
.field-block :deep(.el-input__count-inner),
:deep(.two-grid .el-input__count-inner) {
    background: transparent;
    color: rgba(255, 255, 255, 0.46);
}

.sr-only {
    display: none;
}

.template-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 2147483646;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
    box-sizing: border-box;
}

.template-modal {
    display: flex;
    flex-direction: column;
    width: min(920px, calc(100vw - 48px));
    height: min(760px, calc(100vh - 48px));
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    overflow: hidden;
    box-sizing: border-box;
}

.template-modal__header,
.template-modal__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 20px;
}

.template-modal__header {
    align-items: flex-start;
    margin-bottom: 20px;
}

.template-modal__title {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.template-modal__title strong {
    color: #fff;
    font-size: 22px;
    font-weight: 600;
    line-height: 1.2;
}

.template-modal__title span,
.template-modal__footer span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 14px;
}

.template-modal__close {
    position: relative;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: #1f1f1f;
    cursor: pointer;
}

.template-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.template-modal__close span:first-child {
    transform: rotate(45deg);
}

.template-modal__close span:last-child {
    transform: rotate(-45deg);
}

.template-modal__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-auto-rows: 238px;
    flex: 1;
    gap: 16px;
    align-content: start;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-color: #242424 transparent;
    scrollbar-width: thin;
}

.template-modal__grid::-webkit-scrollbar {
    width: 6px;
}

.template-modal__grid::-webkit-scrollbar-track {
    background: transparent;
}

.template-modal__grid::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.template-modal-card {
    position: relative;
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 16px;
    background: #1a1a1a;
    overflow: hidden;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.template-modal-card:hover,
.template-modal-card.is-active {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.16);
    box-shadow: 0 18px 28px rgba(0, 0, 0, 0.22);
}

.template-modal-card.is-active {
    border-color: rgba(255, 255, 255, 0.78);
}

.template-modal-card img,
.template-modal-card__empty,
.template-modal-card__shade {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.template-modal-card img {
    object-fit: cover;
}

.template-modal-card__empty {
    display: grid;
    place-items: center;
    color: rgba(255, 255, 255, 0.42);
    background: #242424;
}

.template-modal-card__shade {
    background: linear-gradient(180deg, rgba(0, 0, 0, 0) 46%, rgba(0, 0, 0, 0.78) 100%);
}

.template-modal-card__meta {
    position: absolute;
    right: 12px;
    bottom: 12px;
    left: 12px;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}

.template-modal-card__meta strong {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
}

.template-modal-card__meta span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 12px;
}

.template-modal__empty {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 220px;
    border: 1px dashed rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
}

.template-modal__empty strong {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
}

.template-modal__empty span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 13px;
}

.template-modal__footer {
    margin-top: 18px;
}

.template-modal__footer button {
    min-height: 36px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: #fff;
    color: #111;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}

.template-modal__footer button:disabled {
    cursor: not-allowed;
    background: #2a2a2a;
    color: rgba(255, 255, 255, 0.42);
}

.template-modal-fade-enter-active,
.template-modal-fade-leave-active {
    transition: opacity 0.18s ease;
}

.template-modal-fade-enter-from,
.template-modal-fade-leave-to {
    opacity: 0;
}

@media (max-width: 1200px) {
    .clip-workspace {
        grid-template-columns: 403px minmax(0, 1fr);
        height: 100%;
    }

    .clip-panel--form {
        height: 100%;
        max-height: none;
    }

    .clip-panel__body {
        overflow-x: hidden;
        overflow-y: auto;
    }

    .template-modal__grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 820px) {
    .smart-clip-page {
        padding-top: 12px;
    }

    .clip-workspace {
        gap: 12px;
    }

    .clip-tabs,
    .template-modal__grid {
        grid-template-columns: 1fr;
    }

    .template-modal {
        width: calc(100vw - 24px);
        height: calc(100vh - 24px);
        padding: 16px;
    }
}
</style>
