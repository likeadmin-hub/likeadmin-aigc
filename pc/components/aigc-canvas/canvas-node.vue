<template>
    <div
        ref="nodeRef"
        class="hb-node"
        :class="[`hb-node--${variant}`, { 'is-running': data.status === 'running', 'is-failed': data.status === 'failed', 'is-quick-open': showQuickMenu }]"
    >
        <Handle v-if="hasTarget" type="target" :position="Position.Left" id="left" class="hb-handle hb-handle--target" />

        <div class="hb-node__header">
            <div class="hb-node__title">
                <input class="nodrag nopan" :value="data.title || '未命名节点'" @change="emitUpdate({ title: ($event.target as HTMLInputElement).value })" @mousedown.stop />
                <label v-if="isImageLike" class="hb-switch" title="公开引用" @mousedown.stop>
                    <input type="checkbox" :checked="data.public !== false" @change="emitUpdate({ public: ($event.target as HTMLInputElement).checked })" />
                    <i></i>
                </label>
            </div>
            <div class="hb-node__actions" @mousedown.stop>
                <button v-if="isImageLike" type="button" title="上传/替换" @click="fileInput?.click()">↔</button>
                <button v-if="variant === 'video'" type="button" title="上传视频" @click="videoInput?.click()">↔</button>
                <button v-if="isImageLike" type="button" title="预览" @click="previewAsset">⊙</button>
                <button v-if="isImageLike || variant === 'video'" type="button" title="下载" @click="downloadAsset">⇩</button>
                <button v-if="canRun" type="button" title="执行" @click="runNode">▶</button>
                <button type="button" title="复制节点" @click="duplicateNode"><CopyDocument /></button>
                <button type="button" title="删除节点" @click="removeNode"><Delete /></button>
            </div>
        </div>

        <template v-if="variant === 'text' || variant === 'imagePrompt'">
            <textarea
                class="hb-text-editor nodrag nopan"
                :value="data.content"
                placeholder="请输入文本内容，输入 @ 可引用公开图片节点"
                @change="emitUpdate({ content: ($event.target as HTMLTextAreaElement).value })"
                @paste="handlePaste"
                @keydown="handleMentionKeydown"
                @input="handleMentionInput"
            />
            <div v-if="showMentions && publicImages.length" class="hb-mentions nodrag nopan">
                <button v-for="item in filteredPublicImages" :key="item.id" type="button" @click="insertMention(item)">
                    <img :src="item.data?.image || item.data?.url" alt="" />
                    <span>{{ item.data?.publicName || item.data?.title || '图片' }}</span>
                </button>
            </div>
        </template>

        <template v-else-if="variant === 'llmConfig'">
            <div class="hb-config-content" @mousedown.stop>
                <div class="hb-config-row">
                    <span>模型</span>
                    <select :value="data.model" @change="emitUpdate({ model: ($event.target as HTMLSelectElement).value })">
                        <option v-for="model in chatModels" :key="model.key" :value="model.key">{{ model.label }}</option>
                    </select>
                </div>
                <div class="hb-config-row">
                    <span>输出格式</span>
                    <select :value="data.outputFormat || 'text'" @change="emitUpdate({ outputFormat: ($event.target as HTMLSelectElement).value })">
                        <option v-for="format in outputFormatOptions" :key="format.key" :value="format.key">{{ format.label }}</option>
                    </select>
                </div>
                <textarea
                    class="hb-small-textarea"
                    :value="data.systemPrompt"
                    placeholder="系统提示词"
                    @change="emitUpdate({ systemPrompt: ($event.target as HTMLTextAreaElement).value })"
                />
                <textarea
                    class="hb-small-textarea"
                    :value="data.prompt"
                    placeholder="补充输入，不连接文本节点时使用"
                    @change="emitUpdate({ prompt: ($event.target as HTMLTextAreaElement).value })"
                    @paste="handlePaste"
                />
                <div v-if="data.outputFormat === 'image_storyboard' && data.outputContent" class="hb-storyboard-image-settings">
                    <div class="hb-config-row">
                        <span>生图模型</span>
                        <select :value="data.imageModel" @change="emitUpdate({ imageModel: ($event.target as HTMLSelectElement).value })">
                            <option v-for="model in imageModels" :key="model.key" :value="model.key">{{ model.label }}</option>
                        </select>
                    </div>
                    <div class="hb-config-row">
                        <span>分辨率</span>
                        <select :value="data.imageQuality" @change="emitUpdate({ imageQuality: ($event.target as HTMLSelectElement).value })">
                            <option v-for="quality in storyboardImageQualities" :key="quality.key" :value="quality.key">{{ quality.label }}</option>
                        </select>
                    </div>
                    <div class="hb-config-row">
                        <span>比例</span>
                        <select :value="data.imageSize" @change="emitUpdate({ imageSize: ($event.target as HTMLSelectElement).value })">
                            <option v-for="size in storyboardImageSizes" :key="size.key" :value="size.key">{{ size.label }}</option>
                        </select>
                    </div>
                </div>
                <div class="hb-tag-row">
                    <em class="hb-tag">参考图 {{ data.referenceCount || 0 }}张</em>
                </div>
                <div v-if="data.outputContent" class="hb-output-content">{{ data.outputContent }}</div>
                <div v-if="data.billingTip" class="hb-billing-tip">{{ data.billingTip }}</div>
                <div v-if="data.splitStatus" class="hb-split-tip" :class="{ 'hb-split-tip--error': data.splitStatus === 'failed' }">
                    {{ data.splitMessage || (data.splitStatus === 'success' ? `已拆分 ${data.splitCount || 0} 个图文节点` : '') }}
                </div>
                <StatusLine :status="data.status" :error="data.error" />
                <div class="hb-action-row hb-action-row--two">
                    <button class="hb-generate-button" type="button" @click="runNode">生成文本</button>
                    <button class="hb-replace-button" type="button" @click="copyOutput">复制</button>
                </div>
                <button v-if="canSplitStoryboard" class="hb-split-button" type="button" @click="splitStoryboard">拆分图文</button>
            </div>
        </template>

        <template v-else-if="variant === 'imageConfig'">
            <div class="hb-config-content" @mousedown.stop>
                <div class="hb-config-row">
                    <span>模型</span>
                    <select :value="data.model" @change="emitUpdate({ model: ($event.target as HTMLSelectElement).value })">
                        <option v-for="model in imageModels" :key="model.key" :value="model.key">{{ model.label }}</option>
                    </select>
                </div>
                <div class="hb-config-row">
                    <span>清晰度</span>
                    <select :value="data.quality" @change="emitUpdate({ quality: ($event.target as HTMLSelectElement).value })">
                        <option v-for="quality in imageQualities" :key="quality.key" :value="quality.key">{{ quality.label }}</option>
                    </select>
                </div>
                <div class="hb-config-row">
                    <span>比例</span>
                    <select :value="data.size" @change="emitUpdate({ size: ($event.target as HTMLSelectElement).value })">
                        <option v-for="size in imageSizes" :key="size.key" :value="size.key">{{ size.label }}</option>
                    </select>
                </div>
                <textarea class="hb-small-textarea" :value="data.prompt" placeholder="补充提示词，不连接文本节点时使用" @change="emitUpdate({ prompt: ($event.target as HTMLTextAreaElement).value })" />
                <div class="hb-tag-row">
                    <em class="hb-tag hb-tag--green">提示词 {{ data.promptCount || 0 }}个</em>
                    <em class="hb-tag">参考图 {{ data.referenceCount || 0 }}张</em>
                </div>
                <StatusLine :status="data.status" :error="data.error" />
                <div class="hb-action-row">
                    <button class="hb-generate-button" type="button" @click="runNode">+ 新建生成</button>
                    <button class="hb-replace-button" type="button" @click="runNode">↻ 替换</button>
                </div>
            </div>
        </template>

        <template v-else-if="variant === 'videoConfig'">
            <div class="hb-config-content" @mousedown.stop>
                <div class="hb-config-row">
                    <span>模型</span>
                    <select :value="data.model" @change="emitUpdate({ model: ($event.target as HTMLSelectElement).value })">
                        <option v-for="model in videoModels" :key="model.key" :value="model.key">{{ model.label }}</option>
                    </select>
                </div>
                <div class="hb-config-row">
                    <span>比例</span>
                    <select :value="data.ratio" @change="emitUpdate({ ratio: ($event.target as HTMLSelectElement).value })">
                        <option v-for="ratio in videoRatios" :key="ratio.key" :value="ratio.key">{{ ratio.label }}</option>
                    </select>
                </div>
                <div class="hb-config-row">
                    <span>{{ videoHasDynamicDuration ? '清晰度' : '时长' }}</span>
                    <select :value="data.quality || data.duration" @change="emitUpdate({ quality: ($event.target as HTMLSelectElement).value })">
                        <option v-for="quality in videoQualities" :key="quality.key" :value="quality.key">{{ quality.label }}</option>
                    </select>
                </div>
                <div v-if="videoHasDynamicDuration" class="hb-config-row">
                    <span>时长</span>
                    <select :value="data.duration" @change="emitUpdate({ duration: Number(($event.target as HTMLSelectElement).value) })">
                        <option v-for="duration in videoDurations" :key="duration.key" :value="duration.key">{{ duration.label }}</option>
                    </select>
                </div>
                <textarea class="hb-small-textarea" :value="data.prompt" placeholder="视频提示词，不连接文本节点时使用" @change="emitUpdate({ prompt: ($event.target as HTMLTextAreaElement).value })" />
                <StatusLine :status="data.status" :error="data.error" />
                <button class="hb-generate-button" type="button" @click="runNode">生成视频</button>
            </div>
        </template>

        <template v-else-if="isImageLike">
            <div class="hb-image-content">
                <span v-if="data.subtitle" class="hb-image-subtitle">{{ data.subtitle }}</span>
                <div v-if="data.image || data.url" class="hb-image-preview">
                    <img :src="data.image || data.url" :alt="data.title || '生成图片'" />
                </div>
                <button v-else class="hb-upload-placeholder" type="button" @mousedown.stop @click="fileInput?.click()">
                    上传图片
                </button>
                <input class="hb-url-input nodrag nopan" :value="data.url" placeholder="或粘贴图片 URL" @change="emitUpdate({ url: ($event.target as HTMLInputElement).value, image: ($event.target as HTMLInputElement).value })" />
                <input v-if="data.public !== false" class="hb-url-input nodrag nopan" :value="data.publicName" placeholder="公开引用名" @change="emitUpdate({ publicName: ($event.target as HTMLInputElement).value })" />
                <input ref="fileInput" type="file" accept="image/*" @change="handleFileChange" />
            </div>
        </template>

        <template v-else-if="variant === 'video'">
            <div class="hb-image-content">
                <span v-if="data.url" class="hb-image-subtitle">{{ data.url }}</span>
                <div class="hb-video-preview">
                    <video v-if="data.url" :src="data.url" controls :poster="data.poster" />
                    <img v-else :src="data.poster" alt="" />
                    <span>{{ data.duration || 5 }}s</span>
                </div>
                <input class="hb-url-input nodrag nopan" :value="data.url" placeholder="或粘贴视频 URL" @change="emitUpdate({ url: ($event.target as HTMLInputElement).value })" />
                <input ref="videoInput" type="file" accept="video/*" @change="handleVideoChange" />
            </div>
        </template>

        <Transition name="hb-menu-fade">
            <div v-if="showQuickMenu" class="hb-quick-menu" @mousedown.stop @mouseenter="cancelQuickClose" @mouseleave="scheduleQuickClose">
                <button v-for="item in quickActions" :key="item.type" type="button" @click="handleAddConnected(item.type)">
                    {{ item.label }}
                </button>
            </div>
        </Transition>

        <button
            v-if="hasSource"
            class="hb-plus-port hb-plus-port--right"
            type="button"
            title="添加后续节点"
            @mousedown.stop
            @mouseenter="openQuickMenuByHover"
            @mouseleave="scheduleQuickClose"
            @click="toggleQuickMenuPinned"
        >
            +
        </button>
        <Handle v-if="hasSource" type="source" :position="Position.Right" id="right" class="hb-handle hb-handle--source" />
    </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, inject, onBeforeUnmount, onMounted, ref, unref } from 'vue'
import { Handle, Position } from '@vue-flow/core'
import { CopyDocument, Delete } from '@element-plus/icons-vue'
import { downloadPcAsset } from '@/utils/download'

const props = defineProps<{
    id: string
    data: Record<string, any>
}>()

const emit = defineEmits<{
    (event: 'update', id: string, data: Record<string, any>): void
    (event: 'duplicate', id: string): void
    (event: 'remove', id: string): void
    (event: 'add-connected', id: string, type: string): void
    (event: 'run', id: string): void
    (event: 'split-storyboard', id: string): void
}>()

const fileInput = ref<HTMLInputElement | null>(null)
const videoInput = ref<HTMLInputElement | null>(null)
const nodeRef = ref<HTMLElement | null>(null)
const showQuickMenu = ref(false)
const quickMenuPinned = ref(false)
const quickCloseTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const showMentions = ref(false)
const mentionKeyword = ref('')
const nodeActions = inject<{
    update?: (id: string, data: Record<string, any>) => void
    duplicate?: (id: string) => void
    remove?: (id: string) => void
    addConnected?: (id: string, type: string) => void
    run?: (id: string) => void
    splitStoryboard?: (id: string) => void
    imageOptionConfig?: any
    videoOptionConfig?: any
    chatModels?: any
    imageModels?: any
    videoModels?: any
    publicImages?: any
}>('aigcCanvasNodeActions', {})
const variant = computed(() => props.data.variant || 'text')
const isImageLike = computed(() => variant.value === 'image' || variant.value === 'imageResult')
const hasTarget = computed(() => variant.value !== 'text')
const hasSource = computed(() => quickActions.value.length > 0)
const canRun = computed(() => ['imageConfig', 'videoConfig', 'llmConfig'].includes(variant.value))
const imageOptionConfig = computed(() => unref(nodeActions.imageOptionConfig) || { channels: [], defaults: {} })
const videoOptionConfig = computed(() => unref(nodeActions.videoOptionConfig) || { channels: [], defaults: {} })
const imageQualities = computed(() => qualityOptions(imageOptionConfig.value, props.data.model))
const imageSizes = computed(() => ratioOptions(imageOptionConfig.value, props.data.model, props.data.quality))
const videoQualities = computed(() => qualityOptions(videoOptionConfig.value, props.data.model))
const videoHasDynamicDuration = computed(() => durationOptions(videoOptionConfig.value, props.data.model).length > 0)
const videoRatios = computed(() => ratioOptions(videoOptionConfig.value, props.data.model, props.data.quality || props.data.duration))
const videoDurations = computed(() => {
    const durations = durationOptions(videoOptionConfig.value, props.data.model)
    return durations.length ? durations : qualityOptions(videoOptionConfig.value, props.data.model)
})
const chatModels = computed(() => unref(nodeActions.chatModels) || [])
const imageModels = computed(() => unref(nodeActions.imageModels) || [])
const videoModels = computed(() => unref(nodeActions.videoModels) || [])
const publicImages = computed(() => unref(nodeActions.publicImages) || [])
const storyboardImageQualities = computed(() => qualityOptions(imageOptionConfig.value, props.data.imageModel || props.data.model))
const storyboardImageSizes = computed(() => ratioOptions(imageOptionConfig.value, props.data.imageModel || props.data.model, props.data.imageQuality))
const outputFormatOptions = [
    { label: '纯文本', key: 'text' },
    { label: 'JSON 结构', key: 'json' },
    { label: 'Markdown', key: 'markdown' },
    { label: '图片分镜', key: 'image_storyboard' }
]
const canSplitStoryboard = computed(() => props.data.outputFormat === 'image_storyboard' && /^#{3}\s*第\s*([0-9０-９一二三四五六七八九十百]+)\s*张\s*$/m.test(String(props.data.outputContent || '')))
const filteredPublicImages = computed(() => {
    const keyword = mentionKeyword.value.trim().replace(/^@/, '')
    return publicImages.value
        .filter((item: any) => item.id !== props.id)
        .filter((item: any) => !keyword || String(item.data?.publicName || item.data?.title || '').includes(keyword))
        .slice(0, 8)
})
const quickActions = computed(() => {
    if (variant.value === 'text') {
        return [
            { type: 'llmConfig', label: 'LLM' },
            { type: 'imageConfig', label: '生图' },
            { type: 'videoConfig', label: '生视频' }
        ]
    }
    if (variant.value === 'llmConfig') {
        return [
            { type: 'imageConfig', label: '生图' },
            { type: 'videoConfig', label: '生视频' }
        ]
    }
    if (variant.value === 'image' || variant.value === 'imageResult') {
        return [
            { type: 'imagePrompt', label: '图生图提示词' },
            { type: 'imageConfig', label: '图生图' },
            { type: 'videoConfig', label: '图生视频' }
        ]
    }
    if (variant.value === 'video') {
        return [{ type: 'videoConfig', label: '生视频' }]
    }
    if (variant.value === 'imageConfig') {
        return [{ type: 'image', label: '结果节点' }]
    }
    if (variant.value === 'videoConfig') {
        return [{ type: 'video', label: '结果节点' }]
    }
    return []
})

function findChannel(optionConfig: any, channelCode = '') {
    const channels = optionConfig?.channels || []
    return channels.find((channel: any) => String(channel.value || channel.code) === String(channelCode || optionConfig?.defaults?.channel)) || channels[0]
}

function qualityOptions(optionConfig: any, channelCode = '') {
    const channel = findChannel(optionConfig, channelCode)
    return (channel?.qualities || []).map((quality: any) => ({
        label: quality.label || quality.quality_label || quality.value,
        key: quality.value || quality.quality
    }))
}

function durationOptions(optionConfig: any, channelCode = '') {
    const channel = findChannel(optionConfig, channelCode)
    return (channel?.duration_options || []).map((duration: any) => ({
        label: `${Number(duration)}秒`,
        key: Number(duration)
    })).filter((duration: any) => duration.key > 0)
}

function ratioOptions(optionConfig: any, channelCode = '', qualityValue = '') {
    const channel = findChannel(optionConfig, channelCode)
    const qualities = channel?.qualities || []
    const quality = qualities.find((item: any) => String(item.value || item.quality) === String(qualityValue || optionConfig?.defaults?.quality)) || qualities[0]
    return (quality?.ratios || []).map((ratio: any) => ({
        label: ratio.label || ratio.ratio || ratio.value,
        key: ratio.value || ratio.ratio
    }))
}

const StatusLine = defineComponent({
    props: {
        status: String,
        error: String
    },
    setup(localProps) {
        return () =>
            localProps.status && localProps.status !== 'idle'
                ? h(
                      'div',
                      {
                          class: ['hb-status-line', `hb-status-line--${localProps.status}`]
                      },
                      localProps.error || (localProps.status === 'running' ? '处理中...' : localProps.status === 'success' ? '已完成' : '处理失败')
                  )
                : null
    }
})

function emitUpdate(data: Record<string, any>) {
    nodeActions.update?.(props.id, data)
    emit('update', props.id, data)
}

function handleAddConnected(type: string) {
    showQuickMenu.value = false
    quickMenuPinned.value = false
    cancelQuickClose()
    nodeActions.addConnected?.(props.id, type)
    emit('add-connected', props.id, type)
}

function openQuickMenuByHover() {
    cancelQuickClose()
    if (!quickActions.value.length) return
    showQuickMenu.value = true
}

function scheduleQuickClose() {
    if (quickMenuPinned.value) return
    cancelQuickClose()
    quickCloseTimer.value = setTimeout(() => {
        showQuickMenu.value = false
    }, 150)
}

function cancelQuickClose() {
    if (!quickCloseTimer.value) return
    clearTimeout(quickCloseTimer.value)
    quickCloseTimer.value = null
}

function toggleQuickMenuPinned() {
    cancelQuickClose()
    if (showQuickMenu.value && quickMenuPinned.value) {
        showQuickMenu.value = false
        quickMenuPinned.value = false
        return
    }
    showQuickMenu.value = true
    quickMenuPinned.value = true
}

function handleDocumentPointerDown(event: PointerEvent) {
    if (!showQuickMenu.value) return
    const target = event.target as Node | null
    if (target && nodeRef.value?.contains(target)) return
    showQuickMenu.value = false
    quickMenuPinned.value = false
}

function duplicateNode() {
    nodeActions.duplicate?.(props.id)
    emit('duplicate', props.id)
}

function removeNode() {
    nodeActions.remove?.(props.id)
    emit('remove', props.id)
}

function runNode() {
    nodeActions.run?.(props.id)
    emit('run', props.id)
}

function splitStoryboard() {
    nodeActions.splitStoryboard?.(props.id)
    emit('split-storyboard', props.id)
}

function previewAsset() {
    const url = props.data.image || props.data.url
    if (url) window.open(url, '_blank')
}

function downloadAsset() {
    const url = props.data.image || props.data.url
    if (url) downloadPcAsset(url, `${props.data.title || 'asset'}.${variant.value === 'video' ? 'mp4' : 'png'}`)
}

function copyOutput() {
    if (props.data.outputContent) navigator.clipboard?.writeText(String(props.data.outputContent))
}

function handleFileChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => {
        emitUpdate({
            image: String(reader.result || ''),
            subtitle: file.name,
            public: true
        })
    }
    reader.readAsDataURL(file)
    ;(event.target as HTMLInputElement).value = ''
}

function handleVideoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) return
    const url = URL.createObjectURL(file)
    emitUpdate({
        url,
        subtitle: file.name,
        status: 'success'
    })
    ;(event.target as HTMLInputElement).value = ''
}

function handlePaste(event: ClipboardEvent) {
    const file = Array.from(event.clipboardData?.files || []).find((item) => item.type.startsWith('image/'))
    if (!file) return
    const reader = new FileReader()
    reader.onload = () => {
        const imageUrl = String(reader.result || '')
        const mention = ` @${file.name || '粘贴图片'} `
        emitUpdate({
            content: `${props.data.content || props.data.prompt || ''}${mention}`,
            pastedImage: imageUrl
        })
    }
    reader.readAsDataURL(file)
}

function handleMentionInput(event: Event) {
    const value = (event.target as HTMLTextAreaElement).value
    const match = value.match(/@([^\s@]*)$/)
    showMentions.value = Boolean(match)
    mentionKeyword.value = match?.[1] || ''
}

function handleMentionKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') showMentions.value = false
}

function insertMention(item: any) {
    const name = item.data?.publicName || item.data?.title || '图片'
    const current = String(props.data.content || '')
    emitUpdate({
        content: current.replace(/@([^\s@]*)$/, `@${name} `)
    })
    showMentions.value = false
}

onMounted(() => {
    document.addEventListener('pointerdown', handleDocumentPointerDown, true)
})

onBeforeUnmount(() => {
    cancelQuickClose()
    document.removeEventListener('pointerdown', handleDocumentPointerDown, true)
})
</script>

<style scoped lang="scss">
.hb-node {
    position: relative;
    width: 304px;
    border: 1px solid #dedbd2;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(17, 24, 39, 0.06);
    color: #171717;
    overflow: visible;
    cursor: grab;
    user-select: none;
}

.hb-node:active {
    cursor: grabbing;
}

.hb-node.is-running {
    border-color: #111;
}

.hb-node.is-failed {
    border-color: #ef4444;
}

.hb-node--image,
.hb-node--imageResult,
.hb-node--video {
    width: 284px;
}

.hb-node--imagePrompt {
    width: 310px;
}

.hb-node--llmConfig,
.hb-node--videoConfig {
    width: 320px;
}

.hb-node__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    min-height: 40px;
    padding: 8px 10px;
    border-bottom: 1px solid #eeeeea;
}

.hb-node__title {
    display: inline-flex;
    align-items: center;
    min-width: 0;
    flex: 1;
    gap: 8px;
}

.hb-node__title input {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #3b3b3b;
    font-size: 13px;
    font-weight: 600;
}

.hb-node__actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.hb-node__actions button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: #4b4b4b;
    font-size: 12px;
    cursor: pointer;
}

.hb-node__actions button:hover {
    background: #f4f3ee;
}

.hb-node__actions svg {
    width: 13px;
    height: 13px;
}

.hb-text-editor {
    display: block;
    width: 100%;
    min-height: 118px;
    padding: 14px 16px 18px;
    border: 0;
    outline: none;
    resize: vertical;
    background: transparent;
    color: #222;
    font-size: 14px;
    line-height: 1.7;
}

.hb-config-content {
    display: grid;
    gap: 11px;
    padding: 12px;
    cursor: default;
}

.hb-config-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    color: #595959;
    font-size: 13px;
}

.hb-config-row select {
    width: 178px;
    max-width: 190px;
    height: 30px;
    padding: 0 30px 0 10px;
    border: 1px solid #dfd9cc;
    border-radius: 9px;
    appearance: none;
    background-color: #fbfaf6;
    background-image:
        linear-gradient(45deg, transparent 50%, #5b5b5b 50%),
        linear-gradient(135deg, #5b5b5b 50%, transparent 50%);
    background-position:
        calc(100% - 14px) 12px,
        calc(100% - 9px) 12px;
    background-size:
        5px 5px,
        5px 5px;
    background-repeat: no-repeat;
    color: #202020;
    font-size: 12px;
    outline: none;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.78);
    cursor: pointer;
}

.hb-config-row select:hover {
    border-color: #c9c0b2;
    background-color: #fff;
}

.hb-config-row select:focus {
    border-color: #111;
    box-shadow: 0 0 0 2px rgba(17, 17, 17, 0.08);
}

.hb-config-row option {
    background: #fff;
    color: #202020;
}

.hb-small-textarea {
    width: 100%;
    min-height: 64px;
    padding: 8px 10px;
    border: 1px solid #ebe7df;
    border-radius: 9px;
    resize: vertical;
    outline: none;
    color: #222;
    font-size: 12px;
    line-height: 1.5;
}

.hb-storyboard-image-settings {
    display: grid;
    gap: 9px;
    padding: 10px;
    border: 1px solid #ebe7df;
    border-radius: 10px;
    background: #f7f1ff;
}

.hb-output-content {
    max-height: 90px;
    overflow: auto;
    padding: 8px 10px;
    border-radius: 9px;
    background: #f7f6f2;
    color: #333;
    font-size: 12px;
    line-height: 1.5;
    white-space: pre-wrap;
}

.hb-billing-tip {
    color: #8f9298;
    font-size: 11px;
    line-height: 1.4;
}

.hb-split-tip {
    padding: 7px 9px;
    border-radius: 8px;
    background: #e8fff5;
    color: #0f8b61;
    font-size: 12px;
    line-height: 1.45;
}

.hb-split-tip--error {
    background: #fee2e2;
    color: #b91c1c;
}

.hb-tag-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding-top: 4px;
}

.hb-tag {
    display: inline-flex;
    align-items: center;
    height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: #f2f2ef;
    color: #77736b;
    font-size: 12px;
    font-style: normal;
    line-height: 1;
}

.hb-tag--green {
    background: #dcf8ef;
    color: #14a06f;
}

.hb-action-row {
    display: grid;
    grid-template-columns: 1fr 64px;
    gap: 8px;
}

.hb-action-row--two {
    grid-template-columns: 1fr 72px;
}

.hb-generate-button,
.hb-replace-button {
    height: 36px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.hb-generate-button {
    border: 1px solid #050505;
    background: #050505;
    color: #fff;
}

.hb-replace-button {
    border: 1px solid #ebe7df;
    background: #fff;
    color: #6b675f;
}

.hb-split-button {
    height: 34px;
    border: 1px solid #050505;
    border-radius: 8px;
    background: #fff;
    color: #050505;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.hb-split-button:hover {
    background: #f4f3ee;
}

.hb-status-line {
    padding: 7px 9px;
    border-radius: 8px;
    background: #f3f3f0;
    color: #66625b;
    font-size: 12px;
}

.hb-status-line--running {
    background: #fef3c7;
    color: #92400e;
}

.hb-status-line--success {
    background: #dcfce7;
    color: #15803d;
}

.hb-status-line--failed {
    background: #fee2e2;
    color: #b91c1c;
}

.hb-image-content {
    padding: 10px;
}

.hb-image-subtitle {
    display: block;
    margin-bottom: 8px;
    color: #4f4f4f;
    font-size: 12px;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.hb-image-preview img {
    display: block;
    width: 100%;
    aspect-ratio: 1 / 1.25;
    border-radius: 8px;
    object-fit: cover;
}

.hb-node--imageResult .hb-image-preview img {
    aspect-ratio: 1.72 / 1;
}

.hb-upload-placeholder {
    width: 100%;
    height: 140px;
    border: 1px dashed #d3cec3;
    border-radius: 10px;
    background: #faf9f4;
    color: #77736b;
    cursor: pointer;
}

.hb-image-content input[type='file'] {
    display: none;
}

.hb-url-input {
    width: 100%;
    height: 30px;
    margin-top: 8px;
    padding: 0 9px;
    border: 1px solid #ebe7df;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font-size: 12px;
    outline: none;
}

.hb-video-preview {
    position: relative;
    border-radius: 10px;
    overflow: hidden;
    background: #111;
}

.hb-video-preview video,
.hb-video-preview img {
    display: block;
    width: 100%;
    aspect-ratio: 16 / 9;
    object-fit: cover;
}

.hb-video-preview span {
    position: absolute;
    right: 8px;
    bottom: 8px;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.68);
    color: #fff;
    font-size: 12px;
}

.hb-switch {
    position: relative;
    display: inline-flex;
    align-items: center;
    width: 30px;
    height: 16px;
    flex-shrink: 0;
}

.hb-switch input {
    position: absolute;
    inset: 0;
    opacity: 0;
}

.hb-switch i {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: #111;
}

.hb-switch input:not(:checked) + i {
    background: #d6d2c8;
}

.hb-switch i::after {
    content: '';
    position: absolute;
    top: 3px;
    right: 3px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #fff;
    transition: transform 0.2s;
}

.hb-switch input:not(:checked) + i::after {
    transform: translateX(-14px);
}

.hb-handle {
    z-index: 12;
    width: 12px;
    height: 12px;
    border: 2px solid #fff;
    border-radius: 999px;
    background: #34d97b !important;
    box-shadow: 0 0 0 2px rgba(52, 217, 123, 0.28), 0 4px 12px rgba(0, 0, 0, 0.22);
    opacity: 1;
    cursor: crosshair;
    pointer-events: all;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.hb-handle:hover {
    transform: scale(1.22);
    box-shadow: 0 0 0 4px rgba(52, 217, 123, 0.22), 0 6px 16px rgba(0, 0, 0, 0.24);
}

.hb-handle--target {
    left: -7px;
}

.hb-handle--source {
    right: -8px;
    top: 50%;
}

.hb-plus-port {
    position: absolute;
    z-index: 13;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border: 1px solid #e2ded6;
    border-radius: 8px;
    background: #fff;
    color: #3a3a3a;
    font-size: 18px;
    line-height: 1;
    box-shadow: 0 5px 14px rgba(17, 24, 39, 0.08);
    cursor: pointer;
    transition: border-color 0.16s ease, background 0.16s ease, color 0.16s ease, box-shadow 0.16s ease, transform 0.16s ease;
}

.hb-plus-port--right {
    right: -20px;
    top: calc(50% - 34px);
    transform: translateY(-50%);
}

.hb-node:hover .hb-plus-port,
.hb-node.is-quick-open .hb-plus-port,
.hb-plus-port:hover {
    border-color: #34d97b;
    background: #34d97b;
    color: #fff;
    box-shadow: 0 8px 18px rgba(52, 217, 123, 0.28);
    transform: translateY(-50%) scale(1.08);
}

.hb-quick-menu {
    position: absolute;
    z-index: 20;
    right: -154px;
    top: calc(50% - 62px);
    display: grid;
    gap: 4px;
    width: 126px;
    padding: 6px;
    border: 1px solid #e2ded6;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 14px 34px rgba(17, 24, 39, 0.12);
}

.hb-quick-menu button {
    display: flex;
    align-items: center;
    min-height: 28px;
    padding: 8px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #222;
    text-align: left;
    cursor: pointer;
}

.hb-quick-menu button:hover {
    background: #f4f3ee;
}

.hb-menu-fade-enter-active,
.hb-menu-fade-leave-active {
    transition: opacity 0.14s ease, transform 0.14s ease;
}

.hb-menu-fade-enter-from,
.hb-menu-fade-leave-to {
    opacity: 0;
    transform: translateX(-6px) scale(0.98);
}

.hb-mentions {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 10px;
    z-index: 30;
    display: grid;
    gap: 4px;
    max-height: 180px;
    overflow: auto;
    padding: 6px;
    border: 1px solid #e2ded6;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 12px 30px rgba(17, 24, 39, 0.14);
}

.hb-mentions button {
    display: flex;
    align-items: center;
    gap: 8px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #222;
    text-align: left;
    cursor: pointer;
    padding: 6px;
}

.hb-mentions button:hover {
    background: #f4f3ee;
}

.hb-mentions img {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    object-fit: cover;
}
</style>
