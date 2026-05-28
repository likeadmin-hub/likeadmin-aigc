<template>
    <div class="pc-visual-editor">
        <div v-if="!hideToolbar" class="pc-visual-toolbar">
            <el-radio-group v-model="editMode" size="small" @change="handleModeChange">
                <el-radio-button label="flow">区块流</el-radio-button>
                <el-radio-button label="free">自由画布</el-radio-button>
            </el-radio-group>
            <span>{{ editMode === 'flow' ? '拖动区块调整上下顺序' : '拖动画布组件，右下角可缩放' }}</span>
        </div>

        <div v-if="editMode === 'flow'" class="pc-flow-stage">
            <draggable
                v-model="localData"
                class="pc-flow-list"
                :style="stageScaleStyle"
                item-key="id"
                :group="{ name: 'decoration-widgets', pull: true, put: true }"
                handle=".pc-widget-drag"
                :animation="180"
                ghost-class="pc-flow-ghost"
                @change="handleFlowChange"
            >
                <template #item="{ element: widget, index }">
                    <div
                        v-if="!widget?.disabled"
                        class="pc-flow-widget"
                        :class="{ active: index === modelValue, hidden: isHidden(widget) }"
                        :style="flowWidgetShellStyle(widget)"
                        @click="selectWidget(index)"
                    >
                        <div class="pc-widget-head">
                            <span class="pc-widget-drag">拖动</span>
                            <strong>{{ widget.title || widget.name }}</strong>
                            <div class="pc-widget-actions">
                                <el-button link type="primary" @click.stop="openSetting(index)">编辑</el-button>
                                <el-button link @click.stop="toggleHidden(index)">{{ isHidden(widget) ? '显示' : '隐藏' }}</el-button>
                                <el-button link @click.stop="emit('copyWidget', index)">复制</el-button>
                                <el-button link type="danger" @click.stop="emit('deleteWidget', index)">删除</el-button>
                            </div>
                        </div>
                        <div class="pc-flow-content">
                            <component
                                :is="widgets[widget?.name]?.content"
                                v-if="widgets[widget?.name]?.content"
                                :ref="(el) => setWidgetRef(el, index)"
                                :content="widget.content"
                                :styles="widget.styles"
                            />
                            <div v-else class="pc-widget-empty">{{ widget.name }}</div>
                        </div>
                    </div>
                </template>
                <template #footer>
                    <div v-if="!pageData.length" class="pc-empty-drop">拖动左侧组件到这里</div>
                </template>
            </draggable>
        </div>

        <div v-else class="pc-free-scroll">
            <div
                class="pc-free-canvas"
                :style="{
                    ...stageScaleStyle,
                    width: `${canvasWidth}px`,
                    height: `${canvasHeight}px`
                }"
            >
                <div
                    v-for="(widget, index) in pageData"
                    :key="widget.id || index"
                    class="pc-free-widget"
                    :class="{ active: index === modelValue, hidden: isHidden(widget), locked: layoutOf(widget).locked }"
                    :style="freeWidgetStyle(widget)"
                    @pointerdown="startMove($event, index)"
                    @click.stop="selectWidget(index)"
                >
                    <component
                        :is="widgets[widget?.name]?.content"
                        v-if="widgets[widget?.name]?.content"
                        :ref="(el) => setWidgetRef(el, index)"
                        :content="widget.content"
                        :styles="widget.styles"
                    />
                    <div v-else class="pc-widget-empty">{{ widget.name }}</div>
                    <div v-if="index === modelValue" class="pc-free-actions">
                        <el-button link type="primary" @click.stop="openSetting(index)">编辑</el-button>
                        <el-button link @click.stop="toggleLock(index)">{{ layoutOf(widget).locked ? '解锁' : '锁定' }}</el-button>
                        <el-button link @click.stop="bringForward(index)">上移</el-button>
                        <el-button link @click.stop="toggleHidden(index)">{{ isHidden(widget) ? '显示' : '隐藏' }}</el-button>
                        <el-button link @click.stop="emit('copyWidget', index)">复制</el-button>
                        <el-button link type="danger" @click.stop="emit('deleteWidget', index)">删除</el-button>
                    </div>
                    <button
                        v-if="index === modelValue && !layoutOf(widget).locked"
                        class="pc-resize-handle"
                        type="button"
                        @pointerdown.stop="startResize($event, index)"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { useEventListener } from '@vueuse/core'
import { cloneDeep } from 'lodash-es'
import Draggable from 'vuedraggable'
import type { PropType } from 'vue'

import widgets from '../widgets'

type EditMode = 'flow' | 'free'

const props = defineProps({
    pageData: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    modelValue: {
        type: Number,
        default: 0
    },
    mode: {
        type: String as PropType<EditMode>,
        default: 'flow'
    },
    canvasWidth: {
        type: Number,
        default: 1200
    },
    zoom: {
        type: Number,
        default: 1
    },
    hideToolbar: {
        type: Boolean,
        default: false
    }
})

const emit = defineEmits<{
    (event: 'update:modelValue', value: number): void
    (event: 'update:mode', value: EditMode): void
    (event: 'updatePageData', value: any[]): void
    (event: 'copyWidget', value: number): void
    (event: 'deleteWidget', value: number): void
}>()

const editMode = computed<EditMode>({
    get: () => props.mode,
    set: (value) => emit('update:mode', value)
})
const widgetRefs = shallowRef<Record<number, any>>({})
const dragState = ref<any>(null)

const localData = computed({
    get: () => props.pageData,
    set: (value) => {
        const next = cloneDeep(value || []).map((item: any) => withLayout(item, editMode.value))
        emit('updatePageData', next)
    }
})

const handleFlowChange = (event: any) => {
    if (event?.added?.newIndex !== undefined) {
        emit('update:modelValue', event.added.newIndex)
    }
}

const numberValue = (value: any, fallback: number) => {
    const parsed = Number(String(value ?? '').replace('px', ''))
    return Number.isFinite(parsed) ? parsed : fallback
}

const layoutOf = (widget: any) => {
    const styles = widget?.styles || {}
    const layout = styles.layout || {}
    return {
        mode: layout.mode || (styles.position === 'absolute' ? 'free' : 'flow'),
        x: numberValue(layout.x ?? styles.left, 60),
        y: numberValue(layout.y ?? styles.top, 60),
        w: numberValue(layout.w ?? styles.width, 360),
        h: numberValue(layout.h ?? styles.height, 200),
        z: numberValue(layout.z, 1),
        locked: !!layout.locked,
        hidden: !!layout.hidden,
        snap: numberValue(layout.snap, 8)
    }
}

const withLayout = (widget: any, mode: EditMode) => {
    const next = cloneDeep(widget)
    const layout = layoutOf(next)
    next.styles = {
        ...(next.styles || {}),
        layout: {
            ...layout,
            mode
        }
    }
    return next
}

const patchWidgetLayout = (index: number, patch: Record<string, any>) => {
    const next = cloneDeep(props.pageData)
    if (!next[index]) return
    const layout = layoutOf(next[index])
    next[index].styles = {
        ...(next[index].styles || {}),
        layout: {
            ...layout,
            mode: editMode.value,
            ...patch
        }
    }
    emit('updatePageData', next)
}

const patchWidgetContent = (index: number, patch: Record<string, any>) => {
    const next = cloneDeep(props.pageData)
    if (!next[index]) return
    next[index].content = {
        ...(next[index].content || {}),
        ...patch
    }
    emit('updatePageData', next)
}

const handleModeChange = () => {
    emit('update:mode', editMode.value)
    emit(
        'updatePageData',
        cloneDeep(props.pageData || []).map((item: any) => withLayout(item, editMode.value))
    )
}

const selectWidget = (index: number) => {
    if (props.pageData[index]?.disabled) return
    emit('update:modelValue', index)
}

const isHidden = (widget: any) => widget?.content?.enabled === 0 || layoutOf(widget).hidden

const freeWidgetStyle = (widget: any) => {
    const layout = layoutOf(widget)
    return {
        transform: `translate(${layout.x}px, ${layout.y}px)`,
        width: `${layout.w}px`,
        minHeight: `${layout.h}px`,
        zIndex: layout.z,
        ...designStyle(widget)
    }
}

const designStyle = (widget: any) => {
    const styles = widget?.styles || {}
    const radius = numberValue(styles.border_radius, NaN)
    const borderWidth = numberValue(styles.border_width, NaN)
    const opacity = numberValue(styles.opacity, NaN)
    return {
        marginTop:
            styles.margin_top !== undefined ? `${numberValue(styles.margin_top, 0)}px` : undefined,
        marginBottom:
            styles.margin_bottom !== undefined
                ? `${numberValue(styles.margin_bottom, 0)}px`
                : undefined,
        padding: styles.padding !== undefined ? `${numberValue(styles.padding, 0)}px` : undefined,
        background: styles.background || undefined,
        color: styles.color || undefined,
        borderRadius: Number.isFinite(radius) ? `${radius}px` : undefined,
        border:
            Number.isFinite(borderWidth) && borderWidth > 0
                ? `${borderWidth}px solid ${styles.border_color || 'rgba(255,255,255,0.18)'}`
                : undefined,
        boxShadow: styles.shadow || undefined,
        opacity: Number.isFinite(opacity) ? opacity / 100 : undefined
    }
}

const flowWidgetShellStyle = (widget: any) => ({
    width: `${Math.min(props.canvasWidth, 1200)}px`,
    ...designStyle(widget)
})

const stageScaleStyle = computed(() => ({
    transform: `scale(${props.zoom})`,
    transformOrigin: 'top center'
}))

const snap = (value: number, size: number) => Math.round(value / size) * size

const startMove = (event: PointerEvent, index: number) => {
    const widget = props.pageData[index]
    const layout = layoutOf(widget)
    if (layout.locked) return
    selectWidget(index)
    ;(event.currentTarget as HTMLElement)?.setPointerCapture?.(event.pointerId)
    dragState.value = {
        type: 'move',
        index,
        startX: event.clientX,
        startY: event.clientY,
        layout
    }
}

const startResize = (event: PointerEvent, index: number) => {
    const layout = layoutOf(props.pageData[index])
    dragState.value = {
        type: 'resize',
        index,
        startX: event.clientX,
        startY: event.clientY,
        layout
    }
}

useEventListener(window, 'pointermove', (event: PointerEvent) => {
    const state = dragState.value
    if (!state) return
    const dx = event.clientX - state.startX
    const dy = event.clientY - state.startY
    const size = state.layout.snap || 8
    if (state.type === 'move') {
        patchWidgetLayout(state.index, {
            x: Math.max(0, snap(state.layout.x + dx, size)),
            y: Math.max(0, snap(state.layout.y + dy, size))
        })
    } else {
        patchWidgetLayout(state.index, {
            w: Math.max(180, snap(state.layout.w + dx, size)),
            h: Math.max(80, snap(state.layout.h + dy, size))
        })
    }
})

useEventListener(window, 'pointerup', () => {
    dragState.value = null
})

const openSetting = (index: number) => {
    selectWidget(index)
    widgetRefs.value[index]?.open?.()
}

const setWidgetRef = (el: any, index: number) => {
    if (!el) {
        delete widgetRefs.value[index]
        return
    }
    widgetRefs.value[index] = el
}

const toggleHidden = (index: number) => {
    const widget = props.pageData[index]
    if (!widget) return
    patchWidgetContent(index, { enabled: widget.content?.enabled === 0 ? 1 : 0 })
}

const toggleLock = (index: number) => {
    const layout = layoutOf(props.pageData[index])
    patchWidgetLayout(index, { locked: !layout.locked })
}

const bringForward = (index: number) => {
    const maxZ = Math.max(1, ...props.pageData.map((item: any) => layoutOf(item).z))
    patchWidgetLayout(index, { z: maxZ + 1 })
}

const canvasHeight = computed(() => {
    const bottoms = props.pageData.map((item: any) => {
        const layout = layoutOf(item)
        return layout.y + layout.h
    })
    return Math.max(720, ...bottoms, 720) + 120
})
</script>

<style scoped lang="scss">
.pc-visual-editor {
    width: 100%;
    height: 100%;
    min-width: 0;
    display: flex;
    flex-direction: column;
    background: #f4f6fa;
}
.pc-visual-toolbar {
    flex: none;
    display: flex;
    align-items: center;
    gap: 12px;
    height: 52px;
    padding: 0 16px;
    border-bottom: 1px solid var(--el-border-color-light);
    background: #fff;
    color: var(--el-text-color-secondary);
    font-size: 13px;
}
.pc-flow-stage,
.pc-free-scroll {
    flex: 1;
    min-height: 0;
    overflow: auto;
    padding: 18px;
}
.pc-flow-stage {
    :deep(.sortable-chosen) {
        opacity: 0.92;
    }
}
.pc-flow-list {
    min-height: 520px;
    width: fit-content;
    margin: 0 auto;
}
.pc-empty-drop {
    display: flex;
    min-height: 420px;
    align-items: center;
    justify-content: center;
    border: 1px dashed var(--el-border-color);
    border-radius: 8px;
    background: #fff;
    color: var(--el-text-color-secondary);
}
.pc-flow-widget {
    margin: 0 auto 14px;
    max-width: 100%;
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    background: #fff;
    overflow: hidden;
    &.active {
        border-color: var(--el-color-primary);
        box-shadow: 0 0 0 2px var(--el-color-primary-light-8);
    }
    &.hidden {
        opacity: 0.48;
    }
}
.pc-widget-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--el-border-color-lighter);
    background: #fff;
}
.pc-widget-drag {
    padding: 4px 8px;
    border-radius: 6px;
    background: var(--el-fill-color-light);
    color: var(--el-text-color-secondary);
    cursor: grab;
    font-size: 12px;
}
.pc-widget-actions {
    margin-left: auto;
    display: flex;
    align-items: center;
}
.pc-flow-content {
    min-height: 96px;
    padding: 14px;
    background: #080a10;
}
.pc-flow-ghost {
    opacity: 0.42;
}
.pc-free-canvas {
    position: relative;
    margin: 0 auto;
    border-radius: 8px;
    background:
        linear-gradient(rgba(25, 32, 52, 0.08) 1px, transparent 1px),
        linear-gradient(90deg, rgba(25, 32, 52, 0.08) 1px, transparent 1px),
        #080a10;
    background-size: 24px 24px;
    box-shadow: 0 18px 42px rgba(20, 28, 48, 0.16);
}
.pc-free-widget {
    position: absolute;
    left: 0;
    top: 0;
    border: 1px dashed transparent;
    border-radius: 8px;
    cursor: move;
    touch-action: none;
    &.active {
        border-color: var(--el-color-primary);
    }
    &.hidden {
        opacity: 0.42;
    }
    &.locked {
        cursor: default;
    }
}
.pc-free-actions {
    position: absolute;
    left: 0;
    top: -36px;
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 0 8px;
    height: 30px;
    border-radius: 8px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(20, 28, 48, 0.12);
    white-space: nowrap;
}
.pc-resize-handle {
    position: absolute;
    right: -5px;
    bottom: -5px;
    width: 12px;
    height: 12px;
    border: 2px solid #fff;
    border-radius: 4px;
    background: var(--el-color-primary);
    cursor: nwse-resize;
}
.pc-widget-empty {
    min-height: 120px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #111827;
    color: rgba(255, 255, 255, 0.62);
}
</style>
