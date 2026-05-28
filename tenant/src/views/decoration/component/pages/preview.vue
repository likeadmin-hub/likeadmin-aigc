<template>
    <el-scrollbar class="pages-preview-container">
        <div v-if="pageMeta !== null" class="absolute right-4 top-4" @click="handleClickPageMeta">
            <el-button>页面配置</el-button>
        </div>
        <div class="shadow mx-[30px] pages-preview" :style="pageBackgroundStyle">
            <video
                v-if="pageBackgroundVideo"
                class="page-bg-video"
                :src="pageBackgroundVideo"
                muted
                loop
                autoplay
                playsinline
            />
            <div v-if="pageMeta !== null" class="mini-program-nav" :style="navPreviewStyle">
                <div class="mini-program-nav__status"></div>
                <div class="mini-program-nav__bar" :class="`is-${titleAlign}`">
                    <div
                        v-if="showBack"
                        class="mini-program-nav__back"
                        :style="{ color: navTitleColor }"
                    ></div>
                    <img
                        v-if="
                            String(pageMetaContent.title_type) === '2' && pageMetaContent.title_img
                        "
                        class="mini-program-nav__title-img"
                        :src="pageMetaContent.title_img"
                    />
                    <div v-else class="mini-program-nav__title" :style="{ color: navTitleColor }">
                        {{ pageMetaContent.title || '首页' }}
                    </div>
                </div>
            </div>
            <draggable
                v-model="editablePageData"
                class="page-widget-list"
                item-key="id"
                :group="{ name: 'decoration-widgets', pull: true, put: true }"
                handle=".drag-handle"
                :animation="180"
                ghost-class="widget-ghost"
                chosen-class="widget-chosen"
                @change="handleDragChange"
            >
                <template #item="{ element: widget, index }">
                    <div
                        class="page-widget"
                        :class="{
                            'cursor-pointer': !widget?.disabled
                        }"
                        @click="handleClick(widget, index)"
                    >
                        <!--  选中的边框  -->
                        <div
                            class="absolute w-full h-full z-[100] border-dashed"
                            :class="{
                                select: index == modelValue,
                                hide: canShowCom(widget.content),
                                'border-[#dcdfe6] border-2': !widget?.disabled
                            }"
                        ></div>
                        <!--  选中的组件  -->
                        <slot>
                            <MobileDecorationRenderer
                                :widgets="[widget]"
                                mode="edit"
                                :adapters="mobileRendererAdapters"
                            />
                        </slot>
                        <!--  部件操作按钮组  -->
                        <div class="widget-btns py-[5px]" v-if="index == modelValue">
                            <div>
                                <el-tooltip effect="dark" content="拖动" placement="right">
                                    <el-button
                                        class="py-[5px] drag-handle"
                                        type="primary"
                                        :icon="Rank"
                                    />
                                </el-tooltip>
                            </div>
                            <div>
                                <el-tooltip
                                    effect="dark"
                                    :content="canShowCom(widget.content) ? '显示' : '隐藏'"
                                    placement="right"
                                >
                                    <el-button
                                        class="py-[5px]"
                                        type="primary"
                                        :icon="canShowCom(widget.content) ? View : Hide"
                                        @click="changeShowCom(widget.content)"
                                    />
                                </el-tooltip>
                            </div>
                            <div>
                                <el-tooltip effect="dark" content="上移" placement="right">
                                    <el-button
                                        class="py-[5px]"
                                        type="primary"
                                        :icon="ArrowUpBold"
                                        :disabled="canMoveUpCom(index)"
                                        @click.stop="rearrangeArray(index, index - 1)"
                                    />
                                </el-tooltip>
                            </div>
                            <div>
                                <el-tooltip effect="dark" content="下移" placement="right">
                                    <el-button
                                        class="py-[5px]"
                                        type="primary"
                                        :icon="ArrowDownBold"
                                        :disabled="canMoveDownCom(index)"
                                        @click.stop="rearrangeArray(index, index + 1)"
                                    />
                                </el-tooltip>
                            </div>
                            <div>
                                <el-tooltip effect="dark" content="复制" placement="right">
                                    <el-button
                                        class="py-[5px]"
                                        type="primary"
                                        :icon="CopyDocument"
                                        @click.stop="emit('copyWidget', index)"
                                    />
                                </el-tooltip>
                            </div>
                            <div>
                                <el-tooltip effect="dark" content="删除" placement="right">
                                    <el-button
                                        class="py-[5px]"
                                        type="primary"
                                        :icon="Delete"
                                        :disabled="widget?.disabled"
                                        @click.stop="emit('deleteWidget', index)"
                                    />
                                </el-tooltip>
                            </div>
                        </div>
                    </div>
                </template>
                <template #footer>
                    <div v-if="!pageData.length" class="empty-drop">拖动左侧组件到这里</div>
                </template>
            </draggable>
        </div>
    </el-scrollbar>
</template>
<script lang="ts" setup>
import {
    ArrowDownBold,
    ArrowUpBold,
    CopyDocument,
    Delete,
    Hide,
    Rank,
    View
} from '@element-plus/icons-vue'
import { cloneDeep } from 'lodash-es'
import type { PropType } from 'vue'
import { computed } from 'vue'
import Draggable from 'vuedraggable'

import MobileDecorationRenderer from '@mobile-decoration-renderer'
import navBarBg from './images/nav-bar-bg.png'

const props = defineProps({
    pageMeta: {
        type: Object as any,
        default: () => null
    },
    pageData: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    modelValue: {
        type: Number,
        default: 0
    }
})

const emit = defineEmits<{
    (event: 'update:modelValue', value: number): void
    (event: 'updatePageData', value: any[]): void
    (event: 'copyWidget', value: number): void
    (event: 'deleteWidget', value: number): void
}>()

const oldModelValue = ref<number>(-1)
const editablePageData = computed({
    get: () => props.pageData,
    set: (value: any[]) => {
        emit('updatePageData', cloneDeep(value || []))
    }
})
const pageMetaContent = computed(() => {
    if (Array.isArray(props.pageMeta)) {
        return props.pageMeta?.[0]?.content || {}
    }
    return props.pageMeta?.content || props.pageMeta?.[0]?.content || {}
})
const mobileRendererAdapters = {
    resolveImage: (url: string) => url
}
const pageBackgroundVideo = computed(() => {
    const content = pageMetaContent.value
    return String(content.bg_type) === '3' ? content.bg_video : ''
})
const gradientValue = computed(() => {
    const content = pageMetaContent.value
    const colors =
        Array.isArray(content.gradient_colors) && content.gradient_colors.length
            ? content.gradient_colors
            : [content.gradient_color_start || '#f8f8f8', content.gradient_color_end || '#ffffff']
    return `linear-gradient(${content.gradient_direction || '180deg'}, ${colors.filter(Boolean).join(', ')})`
})
const pageBackgroundStyle = computed(() => {
    const content = pageMetaContent.value
    const bgType = String(content.bg_type ?? '0')
    if (bgType === '0') {
        return {}
    }
    if (bgType === '2' && content.bg_image) {
        const repeat = content.bg_image_repeat || 'no-repeat'
        const sizeMap: Record<string, string> = {
            cover: 'cover',
            contain: 'contain',
            stretch: '100% 100%',
            auto: 'auto'
        }
        return {
            backgroundColor: content.bg_color || '#f8f8f8',
            backgroundImage: `url(${content.bg_image})`,
            backgroundRepeat: repeat,
            backgroundSize:
                repeat === 'repeat' ? 'auto' : sizeMap[content.bg_image_size] || 'cover',
            backgroundPosition: content.bg_image_position || 'center top'
        }
    }
    if (bgType === '3') {
        return {
            backgroundColor: content.bg_color || '#000000'
        }
    }
    if (bgType === '4') {
        return {
            backgroundImage: gradientValue.value
        }
    }
    return {
        backgroundColor: content.bg_color || '#ffffff'
    }
})
const navTitleColor = computed(() =>
    String(pageMetaContent.value.text_color) === '1' ? '#ffffff' : '#111111'
)
const showBack = computed(() => Number(pageMetaContent.value.show_back || 0) === 1)
const titleAlign = computed(() => pageMetaContent.value.title_align || 'center')
const navPreviewStyle = computed(() => ({
    backgroundColor:
        pageMetaContent.value.nav_bg_color || pageMetaContent.value.bg_color || '#ffffff',
    backgroundImage: `url(${navBarBg})`
}))

const handleClickPageMeta = () => {
    if (props.modelValue === -1) {
        emit('update:modelValue', oldModelValue.value)
    } else {
        oldModelValue.value = props.modelValue
        emit('update:modelValue', -1)
    }
}

const handleClick = (widget: any, index: number) => {
    if (widget.disabled) return
    emit('update:modelValue', index)
}

// 是否可以移动组件
const canMoveUpCom = computed(() => {
    return (index: number) => {
        return index === 0
    }
})

// 是否可以移动组件
const canMoveDownCom = computed(() => {
    return (index: number) => {
        return props.pageData?.length === index + 1
    }
})

// 是否显示组件
const canShowCom = computed(() => {
    return (data: any) => {
        return data?.enabled == 0
    }
})

// 修改组件显示/隐藏
const changeShowCom = (data: any) => {
    if (data.enabled === undefined) return
    data.enabled = data.enabled ? 0 : 1
}

const rearrangeArray = (currentIdx: number, targetIdx: number) => {
    if (
        currentIdx < 0 ||
        currentIdx >= props.pageData.length ||
        targetIdx < 0 ||
        targetIdx >= props.pageData.length
    ) {
        return
    }

    // const element = props.pageData.splice(currentIdx, 1)[0]
    // props.pageData.splice(targetIdx, 0, element)
    const newPageData = cloneDeep(props.pageData)
    const element = newPageData.splice(currentIdx, 1)[0]
    newPageData.splice(targetIdx, 0, element)

    emit('updatePageData', newPageData)
    emit('update:modelValue', targetIdx)
}

const handleDragChange = (event: any) => {
    if (event?.added) {
        emit('update:modelValue', event.added.newIndex)
        return
    }
    if (event?.moved) {
        emit('update:modelValue', event.moved.newIndex)
    }
}
</script>

<style lang="scss" scoped>
.pages-preview-container {
    position: relative;
    :deep(.el-scrollbar__wrap) {
        width: 100%;
        display: flex;
        justify-content: center;
    }

    .pages-preview {
        position: relative;
        background-color: #fff;
        width: 360px;
        min-height: 615px;
        color: #333;
        overflow: visible;
        > .page-widget-list > .page-widget {
            position: relative;
            z-index: 1;
        }
        .page-widget-list {
            min-height: 80px;
        }
        .page-widget {
            position: relative;
        }
        .empty-drop {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 140px;
            margin: 12px;
            color: var(--el-text-color-secondary);
            border: 1px dashed var(--el-border-color);
            border-radius: 6px;
            background: #fafafa;
        }
        .page-bg-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }
        .mini-program-nav {
            position: sticky;
            top: 0;
            z-index: 200;
            height: 88px;
            overflow: hidden;
            background-repeat: no-repeat;
            background-size: 100% 88px;
        }
        .mini-program-nav__status {
            box-sizing: border-box;
            height: 40px;
        }
        .mini-program-nav__bar {
            position: relative;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            padding: 0 110px 0 44px;
        }
        .mini-program-nav__bar.is-left {
            justify-content: flex-start;
        }
        .mini-program-nav__back {
            position: absolute;
            left: 17px;
            top: 16px;
            width: 12px;
            height: 12px;
            border-left: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg);
        }
        .mini-program-nav__title {
            max-width: 150px;
            overflow: hidden;
            font-size: 16px;
            font-weight: 600;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mini-program-nav__title-img {
            max-width: 150px;
            height: 20px;
            object-fit: contain;
        }

        .select {
            @apply border-primary border-solid;
        }

        .hide::before {
            content: '已隐藏';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 14px;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .widget-btns {
            position: absolute;
            top: 10px;
            right: -54px;
            z-index: 220;
            overflow: hidden;

            width: 46px;
            border-radius: 8px;
            @apply bg-primary;

            :deep(.el-button) {
                width: 46px;
                border-radius: 0;
            }
            .drag-handle {
                cursor: move;
            }
        }
        .widget-ghost {
            opacity: 0.55;
            background: var(--el-color-primary-light-9);
        }
        .widget-chosen {
            cursor: move;
        }
    }
}
</style>
