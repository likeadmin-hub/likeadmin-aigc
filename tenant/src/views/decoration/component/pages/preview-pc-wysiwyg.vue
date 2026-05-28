<template>
    <div class="pc-wysiwyg">
        <div ref="scrollRef" class="pc-wysiwyg-scroll">
            <div
                class="pc-wysiwyg-stage"
                :style="{
                    width: `${canvasWidth * displayZoom}px`,
                    minHeight: `${pageMinHeight * displayZoom}px`
                }"
            >
                <div
                    class="pc-wysiwyg-page"
                    :style="{
                        width: `${canvasWidth}px`,
                        minHeight: `${pageMinHeight}px`,
                        transform: `scale(${displayZoom})`
                    }"
                >
                    <PcDecorationRenderer
                        :widgets="normalizedPageData"
                        :page-meta="pageMeta"
                        :resolved-sources="resolvedSources"
                        mode="edit"
                        :selected-id="selectedId"
                        :adapters="rendererAdapters"
                        @select-widget="handleSelect"
                        @move-widget="handleMove"
                        @copy-widget="handleCopy"
                        @delete-widget="handleDelete"
                        @toggle-hidden="handleToggleHidden"
                        @toggle-locked="handleToggleLocked"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { cloneDeep } from 'lodash-es'
import type { PropType } from 'vue'
import PcDecorationRenderer from '@pc-decoration'
import { getWidgetLayout, normalizePcDecorationWidgets } from '@decoration-core'
import useAppStore from '@/stores/modules/app'

const props = defineProps({
    pageData: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    pageMeta: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    modelValue: {
        type: Number,
        default: 0
    },
    canvasWidth: {
        type: Number,
        default: 2048
    },
    zoom: {
        type: Number,
        default: 0.6
    },
    resolvedSources: {
        type: Object as PropType<Record<string, any>>,
        default: () => ({})
    }
})

const emit = defineEmits<{
    (event: 'update:modelValue', value: number): void
    (event: 'updatePageData', value: any[]): void
    (event: 'copyWidget', value: number): void
    (event: 'deleteWidget', value: number): void
}>()

const appStore = useAppStore()
const scrollRef = ref<HTMLElement | null>(null)
const wrapWidth = ref(0)
const normalizedPageData = computed(() => normalizePcDecorationWidgets(props.pageData))
const pageMinHeight = computed(() => Number(props.pageMeta?.[0]?.content?.pc_min_height || 1080))
const selectedWidget = computed(() => normalizedPageData.value[props.modelValue])
const selectedId = computed(() => String(selectedWidget.value?.id || selectedWidget.value?.name || ''))
const displayZoom = computed(() => {
    const available = Math.max(320, wrapWidth.value - 48)
    const fitZoom = available / Number(props.canvasWidth || 1366)
    return Math.min(Number(props.zoom || 1), Math.max(0.25, fitZoom))
})
const rendererAdapters = {
    resolveImage: (url: string) => {
        if (!url || /^(https?:\/\/|data:|blob:)/i.test(url)) return url
        const resolved = appStore.getImageUrl(url)
        if (resolved && !String(resolved).startsWith('undefined')) return resolved
        const baseUrl = String(import.meta.env.VITE_APP_BASE_URL || '').replace(/\/$/, '')
        return baseUrl ? `${baseUrl}${url.startsWith('/') ? url : `/${url}`}` : url
    }
}

const findIndex = (widget: any) => {
    const id = String(widget?.id || '')
    const byId = props.pageData.findIndex((item: any) => String(item?.id || '') === id)
    if (byId >= 0) return byId
    const name = String(widget?.name || '')
    return props.pageData.findIndex((item: any) => String(item?.name || '') === name)
}
const updateData = (next: any[]) => {
    emit('updatePageData', normalizePcDecorationWidgets(next))
}
const handleSelect = (widget: any) => {
    const index = findIndex(widget)
    if (index >= 0) emit('update:modelValue', index)
}
const handleMove = ({ widget, direction }: { widget: any; direction: 'up' | 'down' }) => {
    const index = findIndex(widget)
    if (index < 0) return
    const nextIndex = direction === 'up' ? index - 1 : index + 1
    if (nextIndex < 0 || nextIndex >= props.pageData.length) return
    const next = cloneDeep(props.pageData)
    ;[next[index], next[nextIndex]] = [next[nextIndex], next[index]]
    updateData(next)
    emit('update:modelValue', nextIndex)
}
const patchLayout = (widget: any, patch: Record<string, any>) => {
    const index = findIndex(widget)
    if (index < 0) return
    const next = cloneDeep(props.pageData)
    const layout = getWidgetLayout(next[index], index)
    next[index].styles = {
        ...(next[index].styles || {}),
        layout: {
            ...layout,
            ...patch
        }
    }
    updateData(next)
}
const handleToggleHidden = (widget: any) => {
    const layout = getWidgetLayout(widget, findIndex(widget))
    patchLayout(widget, { hidden: !layout.hidden })
}
const handleToggleLocked = (widget: any) => {
    const layout = getWidgetLayout(widget, findIndex(widget))
    patchLayout(widget, { locked: !layout.locked })
}
const handleCopy = (widget: any) => {
    const index = findIndex(widget)
    if (index >= 0) emit('copyWidget', index)
}
const handleDelete = (widget: any) => {
    const index = findIndex(widget)
    if (index >= 0) emit('deleteWidget', index)
}

let resizeObserver: ResizeObserver | null = null
const updateWrapWidth = () => {
    wrapWidth.value = scrollRef.value?.clientWidth || 0
}
onMounted(() => {
    updateWrapWidth()
    if (scrollRef.value && typeof ResizeObserver !== 'undefined') {
        resizeObserver = new ResizeObserver(updateWrapWidth)
        resizeObserver.observe(scrollRef.value)
    }
})
onBeforeUnmount(() => {
    resizeObserver?.disconnect()
    resizeObserver = null
})
</script>

<style scoped lang="scss">
.pc-wysiwyg {
    width: 100%;
    height: 100%;
    min-width: 0;
    background: #edf1f7;
}
.pc-wysiwyg-scroll {
    width: 100%;
    height: 100%;
    overflow: auto;
    padding: 24px;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    box-sizing: border-box;
}
.pc-wysiwyg-stage {
    flex: none;
    position: relative;
    margin: 0;
}
.pc-wysiwyg-page {
    position: absolute;
    left: 0;
    top: 0;
    overflow: visible;
    transform-origin: top left;
    box-shadow: 0 22px 60px rgba(17, 24, 39, 0.18);
}
</style>
