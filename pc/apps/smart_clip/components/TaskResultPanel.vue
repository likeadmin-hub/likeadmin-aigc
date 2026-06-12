<template>
    <div class="clip-panel clip-panel--result">
        <div ref="previewBoxRef" class="preview-box">
            <div v-if="previewVideo || templateCover" class="preview-stage" :style="previewStageStyle">
                <video
                    v-if="previewVideo"
                    :key="previewVideo"
                    class="preview-media"
                    :src="previewVideo"
                    controls
                    autoplay
                    muted
                    loop
                    playsinline
                    preload="metadata"
                    :poster="templateCover || undefined"
                    @loadedmetadata="updateVideoRatio"
                />
                <img v-else class="preview-media" :src="templateCover" alt="" @load="updateImageRatio" />
            </div>
            <div v-else class="preview-empty">
                <strong>{{ templateName || '未选择模板' }}</strong>
                <span>{{ typeLabel }}</span>
            </div>
        </div>
        <div class="panel-head">
            <strong>任务与作品</strong>
            <button type="button" @click="$emit('refresh')">刷新</button>
        </div>
        <div class="result-list">
            <article v-for="item in results" :key="item.id || item.task_id" class="result-card">
                <video v-if="item.video_url" :src="item.video_url" controls playsinline preload="metadata" />
                <div v-else class="result-placeholder">{{ statusText(item.status) }}</div>
                <div class="result-card__body">
                    <div>
                        <strong>{{ item.title || typeText(item.clip_type) }}</strong>
                        <span>{{ statusText(item.status) }} · {{ item.user_charge_points || 0 }}点</span>
                    </div>
                    <div class="result-card__actions">
                        <button type="button" @click="$emit('reuse', item)">再次剪辑</button>
                        <button type="button" @click="$emit('delete', item)">删除</button>
                    </div>
                </div>
            </article>
            <div v-if="!results.length" class="empty-state">暂无剪辑作品</div>
        </div>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps<{
    results: any[]
    latestVideo: string
    templatePreviewVideo?: string
    templateCover?: string
    templateCanvas?: { width: number; height: number } | null
    templateName: string
    typeLabel: string
    statusText: (status: string) => string
    typeText: (type: string) => string
}>()

const previewVideo = computed(() => props.templatePreviewVideo || props.latestVideo || '')
const previewBoxRef = ref<HTMLElement>()
const previewMediaRatio = ref(0)
const previewBoxSize = reactive({ width: 0, height: 0 })
let previewResizeObserver: ResizeObserver | null = null
const canvasRatio = computed(() => {
    const canvas = props.templateCanvas
    const width = Number(canvas?.width || 0)
    const height = Number(canvas?.height || 0)
    if (Number.isFinite(width) && Number.isFinite(height) && width > 0 && height > 0) {
        return width / height
    }
    return previewMediaRatio.value || 9 / 16
})
const previewStageStyle = computed(() => {
    const ratio = canvasRatio.value
    const boxWidth = previewBoxSize.width
    const boxHeight = previewBoxSize.height
    if (!Number.isFinite(ratio) || ratio <= 0 || boxWidth <= 0 || boxHeight <= 0) {
        return { aspectRatio: String(ratio || 9 / 16) }
    }
    const boxRatio = boxWidth / boxHeight
    if (boxRatio > ratio) {
        return {
            width: `${Math.floor(boxHeight * ratio)}px`,
            height: `${boxHeight}px`,
            aspectRatio: String(ratio)
        }
    }
    return {
        width: `${boxWidth}px`,
        height: `${Math.floor(boxWidth / ratio)}px`,
        aspectRatio: String(ratio)
    }
})

watch(previewVideo, () => {
    previewMediaRatio.value = 0
})

onMounted(() => {
    if (!previewBoxRef.value) return
    const updateSize = () => {
        const rect = previewBoxRef.value?.getBoundingClientRect()
        previewBoxSize.width = rect?.width || 0
        previewBoxSize.height = rect?.height || 0
    }
    updateSize()
    previewResizeObserver = new ResizeObserver(updateSize)
    previewResizeObserver.observe(previewBoxRef.value)
})

onBeforeUnmount(() => {
    previewResizeObserver?.disconnect()
    previewResizeObserver = null
})

function updateVideoRatio(event: Event) {
    const video = event.target as HTMLVideoElement
    if (!props.templateCanvas && video.videoWidth > 0 && video.videoHeight > 0) {
        previewMediaRatio.value = video.videoWidth / video.videoHeight
    }
}

function updateImageRatio(event: Event) {
    const image = event.target as HTMLImageElement
    if (!props.templateCanvas && image.naturalWidth > 0 && image.naturalHeight > 0) {
        previewMediaRatio.value = image.naturalWidth / image.naturalHeight
    }
}

defineEmits<{
    (event: 'refresh'): void
    (event: 'reuse', item: any): void
    (event: 'delete', item: any): void
}>()
</script>

<style scoped>
.clip-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 16px;
    min-width: 0;
    min-height: 0;
    padding: 20px;
    border: 0;
    border-radius: 20px;
    background: #0f0f0f;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    overflow: hidden;
    box-sizing: border-box;
}

.clip-panel--result {
    height: 100%;
}

.panel-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 12px;
}

.panel-head strong {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
}

.panel-head button,
.result-card button {
    height: 34px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: #343434;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.panel-head button:hover,
.result-card button:hover {
    background: #3c3c3c;
    color: #fff;
}

.preview-box {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    width: 100%;
    height: 340px;
    border: 0;
    border-radius: 10px;
    background: #262626;
    overflow: hidden;
}

.preview-stage {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    max-width: 100%;
    max-height: 100%;
    min-width: 0;
    min-height: 0;
    border-radius: 0;
    background: #050505;
    overflow: hidden;
}

.preview-media {
    display: block;
    width: 100%;
    height: 100%;
    background: #050505;
    border-radius: 0;
    object-fit: contain;
}

.preview-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    color: rgba(255, 255, 255, 0.45);
    text-align: center;
}

.preview-empty strong {
    color: #fff;
    font-size: 15px;
    font-weight: 600;
}

.preview-empty span,
.result-card span {
    display: block;
    color: rgba(255, 255, 255, 0.55);
    font-size: 12px;
}

.empty-state {
    color: rgba(255, 255, 255, 0.45);
}

.result-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
    align-content: start;
    flex: 1 1 auto;
    gap: 12px;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    scrollbar-color: #242424 transparent;
    scrollbar-width: thin;
}

.result-list::-webkit-scrollbar {
    width: 6px;
}

.result-list::-webkit-scrollbar-track {
    background: transparent;
}

.result-list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.result-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
    padding: 10px;
    border: 1px solid #222;
    border-radius: 10px;
    background: #0f0f0f;
    box-sizing: border-box;
}

.result-card video,
.result-placeholder {
    display: grid;
    place-items: center;
    width: 100%;
    aspect-ratio: 16 / 9;
    border-radius: 8px;
    background: #050505;
    object-fit: contain;
    overflow: hidden;
}

.result-card__body {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
}

.result-card strong {
    display: block;
    min-width: 0;
    color: rgba(255, 255, 255, 0.9);
    font-size: 13px;
    font-weight: 500;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.result-card__actions {
    display: flex;
    flex-shrink: 0;
    gap: 6px;
}

.empty-state {
    display: grid;
    place-items: center;
    min-height: 180px;
    grid-column: 1 / -1;
    border: 0;
    border-radius: 10px;
    background: #262626;
}

@media (max-width: 1200px) {
    .clip-panel--result {
        height: 100%;
    }

    .result-list {
        overflow-x: hidden;
        overflow-y: auto;
    }
}
</style>
