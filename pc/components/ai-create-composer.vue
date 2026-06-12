<template>
    <div
        ref="rootRef"
        :class="[
            'ai-create-composer',
            `is-menu-${menuPlacement}`,
            { 'is-collapsed': isCollapsed }
        ]"
        @click="handleComposerClick"
        @focusin="expandComposer"
        @focusout="collapseComposerIfEmpty"
        @paste="handleComposerPaste"
    >
        <div class="prompt-card">
            <div
                :class="['upload-panel', { 'is-filled': uploadedAssets.length }]"
                :style="getUploadPanelStyle()"
                role="button"
                tabindex="0"
                @click.stop="handleUploadPanelClick"
                @keydown.enter.prevent="handleUploadPanelClick"
                @keydown.space.prevent="handleUploadPanelClick"
                @mouseenter="uploadPreviewExpanded = true"
                @mouseleave="uploadPreviewExpanded = false"
            >
                <template v-if="uploadedAssets.length">
                    <span class="upload-panel__preview" :style="getUploadPreviewStyle()">
                        <span class="upload-panel__preview-stack">
                            <span
                                v-for="(asset, index) in uploadedAssets"
                                :key="asset.id"
                                class="upload-panel__preview-frame"
                                :style="getUploadPreviewFrameStyle(index)"
                            >
                                <span
                                    class="upload-panel__preview-card"
                                    role="button"
                                    tabindex="0"
                                    @click.stop="openImagePreview(index)"
                                    @keydown.enter.prevent.stop="openImagePreview(index)"
                                    @keydown.space.prevent.stop="openImagePreview(index)"
                                >
                                    <img :src="asset.url" :alt="asset.name || '已上传图片'" />
                                    <span v-if="asset.uploading" class="upload-panel__loading" aria-label="上传中">
                                        <span class="upload-panel__spinner"></span>
                                    </span>
                                    <button class="upload-panel__delete" type="button" aria-label="删除图片" @click.stop="emit('remove-asset', asset.id)">
                                        ×
                                    </button>
                                </span>
                            </span>
                        </span>
                        <span
                            class="upload-panel__badge"
                            :class="{ 'is-expanded': uploadPreviewExpanded && uploadedAssets.length > 1 }"
                            :style="getUploadPreviewBadgeStyle()"
                            role="button"
                            tabindex="0"
                            @click.stop="emit('upload')"
                            @keydown.enter.prevent.stop="emit('upload')"
                            @keydown.space.prevent.stop="emit('upload')"
                        >
                            <span class="upload-panel__badge-plus">+</span>
                        </span>
                    </span>
                </template>
                <span v-else class="upload-panel__tilt">
                    <span class="upload-panel__plus">+</span>
                    <span v-if="uploading" class="upload-panel__loading" aria-label="上传中">
                        <span class="upload-panel__spinner"></span>
                    </span>
                </span>
            </div>

            <div class="prompt-card__body">
                <textarea
                    ref="textareaRef"
                    v-model="promptValue"
                    class="prompt-card__textarea"
                    :placeholder="placeholder"
                    @input="syncTextareaHeight"
                    @keydown.meta.enter.prevent="emit('submit')"
                    @keydown.ctrl.enter.prevent="emit('submit')"
                ></textarea>

                <div class="prompt-card__footer">
                    <div class="prompt-tools">
                        <div class="mode-switch">
                            <button :class="['prompt-toggle', { 'is-active': modeValue === 'image' }]" type="button" @click="setGenerationMode('image')">
                                <img :src="modeValue === 'image' ? imageIconActive : imageIcon" alt="" />
                                <span>图片</span>
                            </button>
                            <button :class="['prompt-toggle', { 'is-active': modeValue === 'video' }]" type="button" @click="setGenerationMode('video')">
                                <img :src="modeValue === 'video' ? videoIconActive : videoIcon" alt="" />
                                <span>视频</span>
                            </button>
                        </div>

                        <div v-for="item in configOptions" :key="item.key" class="select-chip-wrap">
                            <button :class="['select-chip', { 'is-open': openedOption === item.key }]" type="button" @click.stop="toggleOption(item.key)">
                                <span>{{ item.value }}</span>
                                <img class="select-chip__arrow" :src="downIcon" alt="" />
                            </button>

                            <div v-if="openedOption === item.key" class="select-chip-menu">
                                <button
                                    v-for="value in selectOptionValues[item.key]"
                                    :key="`${item.key}-${value}`"
                                    :class="['select-chip-menu__item', { 'is-active': optionState[item.key] === value }]"
                                    type="button"
                                    @click.stop="setOption(item.key, value)"
                                >
                                    <span v-if="item.key === 'ratio'" class="select-chip__ratio-preview" aria-hidden="true">
                                        <span class="select-chip__ratio-shape" :style="getAiCreateRatioPreviewStyle(value)"></span>
                                    </span>
                                    {{ value }}
                                </button>
                                <div v-if="!selectOptionValues[item.key]?.length" class="select-chip-menu__empty">
                                    暂无可用选项
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="prompt-submit">
                        <span class="prompt-submit__credit">
                            <img class="prompt-submit__spark" :src="sparkIcon" alt="" />
                            {{ unitPriceLabel }}
                        </span>
                        <button class="prompt-submit__button" type="button" aria-label="生成" :disabled="!canSubmit" @click="emit('submit')">
                            <span aria-hidden="true">↑</span>
                        </button>
                    </div>
                </div>
            </div>

            <button v-if="isCollapsed" class="composer__collapsed-submit" type="button" aria-label="生成" :disabled="!canSubmit" @click.stop="emit('submit')">
                <span aria-hidden="true">↑</span>
            </button>
        </div>

        <Teleport to="body">
            <div v-if="activePreviewAsset" class="image-preview-modal" role="dialog" aria-modal="true" @click.self="closeImagePreview">
                <button class="image-preview-modal__close" type="button" aria-label="关闭预览" @click="closeImagePreview">×</button>
                <button
                    v-if="uploadedAssets.length > 1"
                    class="image-preview-modal__nav image-preview-modal__nav--prev"
                    type="button"
                    aria-label="上一张"
                    @click.stop="switchImagePreview(-1)"
                >
                    ‹
                </button>
                <img class="image-preview-modal__image" :src="activePreviewAsset.url" :alt="activePreviewAsset.name || '预览图片'" />
                <button
                    v-if="uploadedAssets.length > 1"
                    class="image-preview-modal__nav image-preview-modal__nav--next"
                    type="button"
                    aria-label="下一张"
                    @click.stop="switchImagePreview(1)"
                >
                    ›
                </button>
            </div>
        </Teleport>
    </div>
</template>
<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { AiCreateOptionKey, AiCreateOptionState, AiGenerationMode } from '~/composables/useAiCreateWorks'
import {
    aiCreateOptionValues,
    type AiCreateOptionValues,
    getAiCreateRatioPreviewStyle
} from '~/composables/useAiCreateWorks'
import sparkIcon from '@/assets/images/icon/lingganzhi.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import imageIcon from '@/assets/images/icon/New-picture -yuanshi.svg'
import imageIconActive from '@/assets/images/icon/New-picture -gaoliang.svg'
import videoIcon from '@/assets/images/icon/shipin-yuanshi.svg'
import videoIconActive from '@/assets/images/icon/shipin-gaoliang.svg'

type OptionKey = AiCreateOptionKey
type ConfigOption = {
    key: OptionKey
    value: string
}

export interface AiCreateComposerAsset {
    id: string
    name: string
    url: string
    isObjectUrl: boolean
    uploading?: boolean
}

const UPLOAD_PANEL_BASE_WIDTH = 88
const UPLOAD_PREVIEW_CARD_WIDTH = 60
const UPLOAD_PREVIEW_GAP = 4
const UPLOAD_PREVIEW_STEP = UPLOAD_PREVIEW_CARD_WIDTH + UPLOAD_PREVIEW_GAP
const TEXTAREA_MIN_HEIGHT = 92
const TEXTAREA_MAX_HEIGHT = 220
const COLLAPSED_TEXTAREA_HEIGHT = 44
const TEXTAREA_LAYOUT_SETTLE_DELAYS = [40, 120, 320]

const props = withDefaults(defineProps<{
    modelValue: string
    mode: AiGenerationMode
    optionState: AiCreateOptionState
    configOptions: ConfigOption[]
    optionValues?: AiCreateOptionValues
    uploadedAssets: AiCreateComposerAsset[]
    placeholder: string
    canGenerate: boolean
    canSubmit?: boolean
    uploading?: boolean
    unitPriceLabel?: string
    collapsed?: boolean
    menuPlacement?: 'top' | 'bottom'
}>(), {
    unitPriceLabel: '0/次',
    uploading: false,
    collapsed: false,
    menuPlacement: 'bottom'
})

const emit = defineEmits<{
    'update:modelValue': [value: string]
    'update:mode': [value: AiGenerationMode]
    'update:optionState': [value: AiCreateOptionState]
    'update:option-state': [value: AiCreateOptionState]
    upload: []
    'paste-images': [files: File[]]
    'remove-asset': [id: string]
    submit: []
}>()

const rootRef = ref<HTMLElement | null>(null)
const textareaRef = ref<HTMLTextAreaElement | null>(null)
const composerId = `ai-composer-${Math.random().toString(36).slice(2, 10)}`
const openedOption = ref<OptionKey | ''>('')
const uploadPreviewExpanded = ref(false)
const composerFocused = ref(false)
const previewImageIndex = ref<number | null>(null)
let textareaLayoutSettleTimers: ReturnType<typeof setTimeout>[] = []
let textareaResizeObserver: ResizeObserver | null = null
let lastTextareaWidth = 0

const promptValue = computed({
    get: () => props.modelValue,
    set: (value) => emit('update:modelValue', value)
})

const modeValue = computed({
    get: () => props.mode,
    set: (value) => emit('update:mode', value)
})

const isCollapsed = computed(() =>
    props.collapsed && !composerFocused.value && !props.modelValue.trim() && !props.uploadedAssets.length
)
const activePreviewAsset = computed(() => (
    previewImageIndex.value === null
        ? null
        : props.uploadedAssets[previewImageIndex.value] || null
))
const selectOptionValues = computed(() => props.optionValues || aiCreateOptionValues)
const canSubmit = computed(() => props.canSubmit ?? props.canGenerate)
const uploading = computed(() => props.uploading || props.uploadedAssets.some((asset) => asset.uploading))

const expandComposer = () => {
    composerFocused.value = true
    document.documentElement.dataset.activeAiCreateComposer = composerId
}

const isInteractiveTarget = (target: EventTarget | null) => {
    if (!(target instanceof Element)) return false
    return Boolean(target.closest('button, a, input, select, [role="button"], .select-chip-wrap, .image-preview-modal'))
}

const focusTextarea = async () => {
    composerFocused.value = true
    await nextTick()
    textareaRef.value?.focus()
    await syncTextareaHeight()
}

const handleComposerClick = (event: MouseEvent) => {
    expandComposer()
    if (isInteractiveTarget(event.target)) return
    void focusTextarea()
}

const isImageClipboardFile = (file: File) => (
    file.type.startsWith('image/') ||
    /\.(avif|bmp|gif|jpe?g|png|svg|webp)$/i.test(file.name)
)

const readCssPixelValue = (element: HTMLElement, property: string, fallback: number) => {
    const value = Number.parseFloat(window.getComputedStyle(element).getPropertyValue(property))
    return Number.isFinite(value) ? value : fallback
}

const applyTextareaHeight = () => {
    const textarea = textareaRef.value
    if (!textarea) return

    const isCompact = isCollapsed.value
    const minHeight = isCompact
        ? COLLAPSED_TEXTAREA_HEIGHT
        : readCssPixelValue(textarea, '--prompt-textarea-min-height', TEXTAREA_MIN_HEIGHT)
    const maxHeight = isCompact
        ? COLLAPSED_TEXTAREA_HEIGHT
        : readCssPixelValue(textarea, '--prompt-textarea-max-height', TEXTAREA_MAX_HEIGHT)

    textarea.style.height = 'auto'
    const nextHeight = Math.min(Math.max(textarea.scrollHeight, minHeight), maxHeight)
    textarea.style.height = `${nextHeight}px`
    textarea.style.overflowY = textarea.scrollHeight > maxHeight ? 'auto' : 'hidden'
}

const syncTextareaHeight = async () => {
    await nextTick()
    applyTextareaHeight()
}

const syncTextareaHeightAfterLayout = () => {
    void syncTextareaHeight()
    if (typeof window === 'undefined') return
    textareaLayoutSettleTimers.forEach((timer) => window.clearTimeout(timer))
    textareaLayoutSettleTimers = TEXTAREA_LAYOUT_SETTLE_DELAYS.map((delay) => (
        window.setTimeout(() => {
            applyTextareaHeight()
        }, delay)
    ))
}

const handleComposerTransitionEnd = (event: TransitionEvent) => {
    if (event.target !== rootRef.value) return
    if (!['width', 'max-width'].includes(event.propertyName)) return
    applyTextareaHeight()
}

const handleUploadPanelClick = () => {
    expandComposer()
    if (props.uploadedAssets.length) return
    emit('upload')
}

const openImagePreview = (index: number) => {
    previewImageIndex.value = index
}

const closeImagePreview = () => {
    previewImageIndex.value = null
}

const switchImagePreview = (offset: number) => {
    if (!props.uploadedAssets.length || previewImageIndex.value === null) return
    previewImageIndex.value = (previewImageIndex.value + offset + props.uploadedAssets.length) % props.uploadedAssets.length
}

const collapseComposerIfEmpty = () => {
    window.setTimeout(() => {
        if (!props.collapsed) return
        if (props.modelValue.trim() || props.uploadedAssets.length) return
        if (rootRef.value?.contains(document.activeElement)) return
        composerFocused.value = false
        openedOption.value = ''
    }, 0)
}

const collapseIfEmpty = () => {
    if (!props.collapsed) return
    if (props.modelValue.trim() || props.uploadedAssets.length) return
    if (composerFocused.value || rootRef.value?.contains(document.activeElement)) return
    composerFocused.value = false
    openedOption.value = ''
}

const setGenerationMode = (mode: AiGenerationMode) => {
    modeValue.value = mode
    openedOption.value = ''
    void focusTextarea()
}

const toggleOption = (key: OptionKey) => {
    openedOption.value = openedOption.value === key ? '' : key
}

const setOption = (key: OptionKey, value: string) => {
    const nextState = {
        ...props.optionState,
        [key]: value
    }
    emit('update:optionState', nextState)
    emit('update:option-state', nextState)
    openedOption.value = ''
    void focusTextarea()
}

const getClipboardImageFiles = (event: ClipboardEvent) => {
    const itemFiles = Array.from(event.clipboardData?.items || [])
        .filter((item) => item.kind === 'file' && (!item.type || item.type.startsWith('image/')))
        .map((item) => item.getAsFile())
        .filter((file): file is File => Boolean(file) && isImageClipboardFile(file))

    const clipboardFiles = Array.from(event.clipboardData?.files || [])
        .filter(isImageClipboardFile)

    return [...itemFiles, ...clipboardFiles].filter((file, index, list) => (
        list.findIndex((target) => (
            target.size === file.size &&
            target.type === file.type &&
            (
                target.name === file.name ||
                target.lastModified === file.lastModified ||
                (!target.name && !file.name)
            )
        )) === index
    ))
}

const uploadPastedImages = (event: ClipboardEvent) => {
    const files = getClipboardImageFiles(event)

    if (!files.length) return
    event.preventDefault()
    event.stopPropagation()
    composerFocused.value = true
    emit('paste-images', files)
}

const handleComposerPaste = (event: ClipboardEvent) => {
    uploadPastedImages(event)
}

const handleDocumentPaste = (event: ClipboardEvent) => {
    if (document.documentElement.dataset.activeAiCreateComposer !== composerId) return
    if (event.target instanceof Node && rootRef.value.contains(event.target)) return
    uploadPastedImages(event)
}

const getExpandedUploadPreviewWidth = () => {
    const total = props.uploadedAssets.length
    if (total <= 1) return UPLOAD_PANEL_BASE_WIDTH
    return total * UPLOAD_PREVIEW_STEP + UPLOAD_PREVIEW_CARD_WIDTH
}

const getUploadPanelStyle = () => ({
    width: `${UPLOAD_PANEL_BASE_WIDTH}px`,
    flexBasis: `${UPLOAD_PANEL_BASE_WIDTH}px`
})

const getUploadPreviewStyle = () => {
    const isExpanded = uploadPreviewExpanded.value && props.uploadedAssets.length > 1
    const width = isExpanded ? getExpandedUploadPreviewWidth() : UPLOAD_PANEL_BASE_WIDTH

    return {
        width: `${width}px`,
        justifyContent: isExpanded ? 'flex-start' : 'center'
    }
}

const getUploadPreviewFrameStyle = (index: number) => {
    const total = props.uploadedAssets.length
    const isSingle = total <= 1
    const isExpanded = uploadPreviewExpanded.value && total > 1
    const collapsedRotatePattern = [-18, -8, 10, -6, 12, 4]
    const collapsedRotate = isSingle ? -8.5 : collapsedRotatePattern[index % collapsedRotatePattern.length]
    const expandedX = index * UPLOAD_PREVIEW_STEP

    return {
        '--upload-preview-transform': `translate3d(${isExpanded ? expandedX : 0}px, 0, 0) rotate(${isExpanded ? 0 : collapsedRotate}deg)`,
        '--upload-preview-hover-translate-y': isExpanded ? '-4px' : '0px',
        zIndex: index + 1,
        opacity: 1
    }
}

const getUploadPreviewBadgeStyle = () => {
    const total = props.uploadedAssets.length
    const isExpanded = uploadPreviewExpanded.value && total > 1

    if (isExpanded) {
        return {
            transform: `translate3d(${total * UPLOAD_PREVIEW_STEP}px, 3px, 0) rotate(0deg)`,
            width: `${UPLOAD_PREVIEW_CARD_WIDTH}px`,
            height: '80px',
            borderRadius: '14px',
            background: 'linear-gradient(180deg, #313233 0%, #2a2b2c 100%)',
            boxShadow: 'inset 0 1px 0 rgba(255, 255, 255, 0.03)',
            color: '#a5a5a6',
            fontSize: '32px'
        }
    }

    return {
        transform: 'translate3d(52px, 56px, 0)',
        width: '28px',
        height: '28px',
        borderRadius: '50%',
        background: '#5a5d64',
        color: '#fff',
        fontSize: '20px',
        boxShadow: '0 8px 18px rgba(0, 0, 0, 0.24), 0 0 0 0.5px rgba(255, 255, 255, 0.08)'
    }
}

watch([() => props.modelValue, isCollapsed], () => {
    syncTextareaHeightAfterLayout()
}, { immediate: true })

onMounted(() => {
    document.addEventListener('paste', handleDocumentPaste)
    rootRef.value?.addEventListener('transitionend', handleComposerTransitionEnd)
    if (typeof ResizeObserver !== 'undefined' && rootRef.value) {
        textareaResizeObserver = new ResizeObserver(() => {
            const textarea = textareaRef.value
            if (!textarea) return
            const width = textarea.getBoundingClientRect().width
            if (Math.abs(width - lastTextareaWidth) < 0.5) return
            lastTextareaWidth = width
            syncTextareaHeightAfterLayout()
        })
        textareaResizeObserver.observe(rootRef.value)
    }
})

onBeforeUnmount(() => {
    document.removeEventListener('paste', handleDocumentPaste)
    rootRef.value?.removeEventListener('transitionend', handleComposerTransitionEnd)
    textareaResizeObserver?.disconnect()
    textareaResizeObserver = null
    if (typeof window !== 'undefined') {
        textareaLayoutSettleTimers.forEach((timer) => window.clearTimeout(timer))
    }
    textareaLayoutSettleTimers = []
})

defineExpose({
    focusTextarea,
    collapseIfEmpty,
    rootRef
})
</script>

<style lang="scss" scoped>
.ai-create-composer {
    width: 100%;
    transition:
        width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        max-width 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.prompt-card {
    position: relative;
    z-index: 4;
    display: flex;
    gap: 16px;
    width: 100%;
    margin: 0;
    padding: 18px 18px 14px 10px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 22px;
    background: rgba(34, 34, 34, 0.96);
    box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22);
    backdrop-filter: blur(10px);
    overflow: visible;
    transition:
        min-height 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        padding 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        border-radius 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        gap 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        box-shadow 0.28s ease;
}

.prompt-card__body {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    min-width: 0;
    transition:
        min-height 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        gap 0.28s ease;
}

.prompt-card__textarea {
    --prompt-textarea-min-height: 92px;
    --prompt-textarea-max-height: 220px;

    width: 100%;
    min-height: 92px;
    max-height: 220px;
    height: auto;
    border: 0;
    outline: none;
    resize: none;
    background: transparent;
    color: #fff;
    font-size: 15px;
    line-height: 1.7;
    transition:
        min-height 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
    overflow-y: hidden;
    scrollbar-width: thin;
    scrollbar-color: #555 transparent;
}

.prompt-card__textarea::placeholder {
    color: #7f7f80;
}

.prompt-card__textarea::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.prompt-card__textarea::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #555;
}

.prompt-card__textarea::-webkit-scrollbar-track {
    background: transparent;
}

.prompt-card__footer {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    margin-top: 18px;
    padding-left: 34px;
    opacity: 1;
    max-height: 64px;
    overflow: visible;
    transition:
        opacity 0.2s ease 0.08s,
        max-height 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        margin-top 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.upload-panel {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 88px;
    height: 86px;
    flex-shrink: 0;
    border: 0;
    background: transparent;
    color: #a5a5a6;
    transform: translateX(2px);
    overflow: visible;
    cursor: pointer;
    transition: all 0.2s ease;
}

.upload-panel__tilt {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 80px;
    border-radius: 14px;
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
    transform: rotate(-8.5deg) translateY(1px);
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.upload-panel__plus {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    font-size: 32px;
    line-height: 1;
    transform: rotate(9deg);
}

.upload-panel__preview {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 88px;
    height: 86px;
    overflow: visible;
}

.upload-panel__preview-stack {
    position: relative;
    display: block;
    width: 60px;
    height: 80px;
    overflow: visible;
}

.upload-panel__preview-frame {
    position: absolute;
    left: 0;
    top: 0;
    display: inline-flex;
    align-items: center;
    justify-content: flex-start;
    width: 64px;
    height: 80px;
    transition: transform 0.2s ease;
    transform-origin: center center;
    transform: var(--upload-preview-transform);
    overflow: visible;
}

.upload-panel__preview-frame:hover {
    z-index: 20 !important;
    transform: var(--upload-preview-transform) translateY(var(--upload-preview-hover-translate-y));
}

.upload-panel__preview-card {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 80px;
    box-sizing: border-box;
    padding: 0;
    border: 0.2px solid rgba(255, 255, 255, 0.92);
    border-radius: 14px;
    background: #111214;
    overflow: hidden;
    cursor: zoom-in;
}

.upload-panel__preview-card img {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: 13px;
    background: #101010;
    object-fit: cover;
}

.upload-panel__loading {
    position: absolute;
    inset: 0;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: inherit;
    background: rgba(0, 0, 0, 0.46);
    backdrop-filter: blur(2px);
    pointer-events: none;
}

.upload-panel__spinner {
    width: 22px;
    height: 22px;
    border: 2px solid rgba(255, 255, 255, 0.28);
    border-top-color: #fff;
    border-radius: 50%;
    animation: upload-panel-spin 0.78s linear infinite;
}

@keyframes upload-panel-spin {
    to {
        transform: rotate(360deg);
    }
}

.upload-panel__badge {
    position: absolute;
    left: 0;
    top: 0;
    z-index: 24;
    box-sizing: border-box;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 80px;
    border-radius: 50%;
    background: #5a5d64;
    color: #fff;
    font-size: 32px;
    line-height: 1;
    flex-shrink: 0;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        width 0.2s ease,
        height 0.2s ease,
        border-radius 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease,
        color 0.2s ease;
}

.upload-panel__badge-plus {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    line-height: 1;
    transition: transform 0.2s ease;
}

.upload-panel.is-filled {
    color: #fff;
    transform: none;
    overflow: visible;
}

.upload-panel:hover {
    color: #fff;
}

.upload-panel:hover .upload-panel__tilt {
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    transform: rotate(-8.5deg) translateY(-1px);
}

.upload-panel.is-filled:hover .upload-panel__badge {
    background: #6a6e75;
}

.upload-panel.is-filled .upload-panel__preview-frame:hover .upload-panel__delete {
    opacity: 1;
}

.upload-panel__delete {
    position: absolute;
    top: 6px;
    right: 6px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border: 0;
    border-radius: 50%;
    background: rgba(10, 10, 10, 0.72);
    color: #fff;
    font-size: 14px;
    line-height: 1;
    opacity: 0;
    cursor: pointer;
    transition:
        opacity 0.2s ease,
        background 0.2s ease;
}

.upload-panel__delete:hover {
    background: rgba(10, 10, 10, 0.92);
}

.image-preview-modal {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 56px;
    background: rgba(0, 0, 0, 0.82);
    backdrop-filter: blur(12px);
}

.image-preview-modal__image {
    display: block;
    max-width: min(86vw, 1180px);
    max-height: 82vh;
    border-radius: 18px;
    object-fit: contain;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.42);
}

.image-preview-modal__close,
.image-preview-modal__nav {
    position: fixed;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.12);
    color: #fff;
    cursor: pointer;
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.image-preview-modal__close:hover,
.image-preview-modal__nav:hover {
    background: rgba(255, 255, 255, 0.2);
    transform: scale(1.04);
}

.image-preview-modal__close {
    top: 28px;
    right: 32px;
    width: 40px;
    height: 40px;
    font-size: 24px;
    line-height: 1;
}

.image-preview-modal__nav {
    top: 50%;
    width: 48px;
    height: 48px;
    font-size: 36px;
    line-height: 1;
    transform: translateY(-50%);
}

.image-preview-modal__nav:hover {
    transform: translateY(-50%) scale(1.04);
}

.image-preview-modal__nav--prev {
    left: 32px;
}

.image-preview-modal__nav--next {
    right: 32px;
}

.prompt-tools {
    display: flex;
    align-items: flex-end;
    gap: 6px;
    margin-left: -128px;
    flex-wrap: nowrap;
}

.mode-switch {
    display: inline-flex;
    align-items: center;
    width: auto;
    height: 32px;
    padding: 2px;
    border-radius: 32px;
    background: #050505;
}

.mode-switch .prompt-toggle {
    min-width: 78px;
    height: 28px;
}

.prompt-toggle,
.select-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border: 0;
    border-radius: 32px;
    background: #292a2b;
    color: #a5a5a6;
    font-size: 14px;
    line-height: 14px;
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.prompt-toggle:hover,
.select-chip:hover {
    color: #fff;
}

.prompt-toggle img,
.select-chip img {
    width: 16px;
    height: 16px;
    object-fit: contain;
    flex-shrink: 0;
}

.prompt-toggle.is-active,
.select-chip.is-open {
    background: #050505;
    color: #fff;
}

.mode-switch .prompt-toggle {
    background: transparent;
}

.mode-switch .prompt-toggle.is-active {
    background: #292a2b;
}

.select-chip-wrap {
    position: relative;
    display: inline-flex;
}

.select-chip-wrap:has(.select-chip-menu) {
    z-index: 8;
}

.select-chip__arrow {
    width: 14px !important;
    height: 14px !important;
    opacity: 0.65;
}

.select-chip-menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    min-width: 148px;
    max-height: min(260px, calc(100vh - 140px));
    padding: 6px;
    border-radius: 12px;
    background: rgba(21, 21, 21, 0.98);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.32);
    z-index: 20;
    overflow-y: auto;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #555 rgba(255, 255, 255, 0.04);
}

.select-chip-menu::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.select-chip-menu::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #555;
}

.select-chip-menu::-webkit-scrollbar-track {
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.04);
}

.is-menu-top .select-chip-menu {
    top: auto;
    bottom: calc(100% + 8px);
}

.select-chip-menu__item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 34px;
    padding: 0 10px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    text-align: left;
    white-space: nowrap;
    cursor: pointer;
}

.select-chip-menu__item:hover,
.select-chip-menu__item.is-active {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.select-chip-menu__empty {
    display: flex;
    align-items: center;
    min-height: 34px;
    padding: 0 10px;
    color: rgba(255, 255, 255, 0.5);
    font-size: 13px;
    white-space: nowrap;
}

.select-chip__ratio-preview {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
}

.select-chip__ratio-shape {
    display: block;
    border: 1px solid rgba(255, 255, 255, 0.48);
    border-radius: 2px;
    background: rgba(255, 255, 255, 0.06);
}

.prompt-submit {
    display: flex;
    align-items: center;
    gap: 12px;
    padding-bottom: 0;
}

.prompt-submit__credit {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #a5a5a6;
    font-size: 14px;
    line-height: 32px;
    white-space: nowrap;
}

.prompt-submit__spark {
    width: 11px;
    height: 11px;
    object-fit: contain;
}

.prompt-submit__button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 48px;
    height: 32px;
    padding: 0 12px;
    border: 0;
    border-radius: 16px;
    background: #fff;
    color: #222;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    flex-shrink: 0;
    white-space: nowrap;
}

.prompt-submit__button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.composer__collapsed-submit {
    display: none;
    opacity: 0;
    pointer-events: none;
    transform: scale(0.9);
    transition:
        opacity 0.18s ease,
        transform 0.22s ease;
}

.ai-create-composer.is-collapsed .prompt-card {
    align-items: center;
    gap: 12px;
    min-height: 76px;
    padding: 8px 12px 8px 10px;
    border-radius: 24px;
}

.ai-create-composer.is-collapsed .upload-panel {
    width: 50px !important;
    flex-basis: 50px !important;
    height: 54px;
}

.ai-create-composer.is-collapsed .upload-panel__tilt {
    width: 40px;
    height: 50px;
    border-radius: 9px;
}

.ai-create-composer.is-collapsed .upload-panel__plus {
    font-size: 20px;
}

.ai-create-composer.is-collapsed .prompt-card__body {
    min-height: 44px;
    flex-direction: row;
    align-items: center;
    flex: 1 1 auto;
    width: 100%;
    gap: 10px;
}

.ai-create-composer.is-collapsed .prompt-card__textarea {
    display: block;
    flex: 1 1 auto;
    min-height: 44px;
    height: 44px;
    min-width: 0;
    width: 100%;
    line-height: 44px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: clip;
}

.ai-create-composer.is-collapsed .prompt-card__footer {
    max-height: 0;
    margin-top: 0;
    opacity: 0;
    pointer-events: none;
    overflow: hidden;
    transition:
        opacity 0.12s ease,
        max-height 0.28s cubic-bezier(0.2, 0.8, 0.2, 1),
        margin-top 0.28s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.ai-create-composer.is-collapsed .prompt-submit__button {
    display: none;
}

.ai-create-composer.is-collapsed .composer__collapsed-submit {
    display: inline-flex;
    opacity: 1;
    pointer-events: auto;
    transform: scale(1);
}

.composer__collapsed-submit {
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: #fff;
    color: #222;
    font-size: 18px;
    font-weight: 700;
    cursor: pointer;
    flex-shrink: 0;
}

.composer__collapsed-submit:disabled {
    background: rgba(255, 255, 255, 0.18);
    color: rgba(255, 255, 255, 0.72);
    opacity: 1;
    cursor: not-allowed;
}

@media (max-width: 1100px) {
    .ai-create-composer {
        width: 100%;
        max-width: 100%;
    }

    .prompt-card {
        gap: 12px;
        padding: 16px 16px 12px 10px;
    }

    .upload-panel {
        width: 76px;
        height: 80px;
        flex-basis: 76px;
    }

    .upload-panel__tilt {
        width: 56px;
        height: 74px;
    }

    .upload-panel__preview {
        width: 76px;
        height: 80px;
    }

    .upload-panel__preview-stack {
        width: 56px;
        height: 74px;
    }

    .upload-panel__preview-frame {
        width: 56px;
        height: 74px;
    }

    .upload-panel__preview-card {
        width: 56px;
        height: 74px;
    }

    .upload-panel__badge {
        width: 56px;
        height: 74px;
        font-size: 30px;
    }

    .prompt-card__textarea {
        --prompt-textarea-min-height: 92px;

        min-height: 92px;
        font-size: 14px;
    }

    .prompt-card__footer {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
        margin-top: 14px;
        padding-left: 0;
    }

    .prompt-tools {
        margin-left: 0;
        flex-wrap: wrap;
        gap: 8px;
    }

    .mode-switch {
        flex-wrap: nowrap;
    }

    .select-chip-wrap {
        flex: 0 1 auto;
    }

    .prompt-submit {
        justify-content: space-between;
    }
}

@media (max-width: 900px) {
    .prompt-card {
        align-items: flex-start;
    }

    .prompt-card__body {
        min-width: 0;
    }

    .prompt-tools {
        width: 100%;
        margin-left: 0;
    }

    .mode-switch {
        width: 100%;
    }

    .mode-switch .prompt-toggle {
        min-width: 0;
        flex: 1 1 0;
    }

    .select-chip-wrap {
        min-width: 0;
    }

    .select-chip {
        max-width: 100%;
        padding-inline: 10px;
    }

    .select-chip span {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .prompt-submit__credit {
        font-size: 13px;
    }
}

@media (max-width: 760px) {
    .prompt-card {
        flex-wrap: wrap;
        padding: 14px 14px 12px 10px;
    }

    .upload-panel {
        width: 64px;
        height: 72px;
    }

    .upload-panel__tilt {
        width: 50px;
        height: 68px;
    }

    .prompt-card__body {
        width: 100%;
    }

    .prompt-card__textarea {
        --prompt-textarea-min-height: 92px;

        min-height: 92px;
        font-size: 14px;
    }

    .prompt-card__footer {
        width: 100%;
    }

    .prompt-tools {
        width: 100%;
        flex-direction: column;
        align-items: stretch;
    }

    .mode-switch {
        width: 100%;
    }

    .select-chip-wrap {
        width: 100%;
    }

    .select-chip {
        width: 100%;
        justify-content: space-between;
    }

    .prompt-submit {
        width: 100%;
    }

    .prompt-submit__button {
        min-width: 56px;
    }
}
</style>
