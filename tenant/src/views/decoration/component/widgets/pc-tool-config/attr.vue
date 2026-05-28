<template>
    <el-form label-width="86px">
        <el-card shadow="never" class="!border-none">
            <div class="flex items-center justify-between mb-4">
                <div class="text-base text-[#101010] dark:text-[#ffffff] font-medium">工具配置</div>
                <el-button link type="primary" @click="fillDefaultTools">补齐默认工具</el-button>
            </div>
            <draggable v-model="contentData.data" item-key="id" handle=".drag-move" animation="180">
                <template #item="{ element: item, index }">
                    <del-wrap :key="item.id || index" class="w-full" @close="handleDelete(index)">
                        <div class="pc-tool-attr-item">
                            <material-picker
                                width="96px"
                                height="96px"
                                v-model="item.cover"
                                upload-class="bg-body"
                                exclude-domain
                            >
                                <template #upload>
                                    <div class="pc-tool-attr-upload">封面图</div>
                                </template>
                            </material-picker>
                            <div class="pc-tool-attr-body">
                                <div class="pc-tool-attr-head">
                                    <el-input v-model="item.id" placeholder="工具ID" />
                                    <el-switch v-model="item.enabled" :active-value="1" :inactive-value="0" />
                                    <div class="drag-move cursor-move">
                                        <icon name="el-icon-Rank" size="18" />
                                    </div>
                                </div>
                                <el-input v-model="item.title" placeholder="工具标题" />
                                <el-input v-model="item.badge" placeholder="标签/角标" />
                                <el-input v-model="item.virtual_use_count" placeholder="虚拟使用数" />
                                <el-input
                                    v-model="item.description"
                                    type="textarea"
                                    :rows="2"
                                    resize="none"
                                    placeholder="工具描述"
                                />
                            </div>
                        </div>
                    </del-wrap>
                </template>
            </draggable>
            <el-button class="w-full mt-3" @click="handleAdd">添加工具</el-button>
        </el-card>
    </el-form>
</template>

<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'
import Draggable from 'vuedraggable'
import type { PropType } from 'vue'

import { pcToolConfigDefaults } from './defaults'
import type options from './options'

type OptionsType = ReturnType<typeof options>
const emits = defineEmits<(event: 'update:content', data: OptionsType['content']) => void>()
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    }
})

const contentData = computed({
    get: () => {
        if (!Array.isArray(props.content.data)) props.content.data = []
        return props.content
    },
    set: (value) => emits('update:content', value)
})

const handleAdd = () => {
    const content = cloneDeep(props.content)
    if (!Array.isArray(content.data)) content.data = []
    content.data.push({
        id: `custom-tool-${Date.now()}`,
        title: '自定义工具',
        badge: '',
        description: '',
        cover: '',
        virtual_use_count: '',
        enabled: 1
    })
    emits('update:content', content)
}

const handleDelete = (index: number) => {
    const content = cloneDeep(props.content)
    content.data.splice(index, 1)
    emits('update:content', content)
}

const fillDefaultTools = () => {
    const content = cloneDeep(props.content)
    if (!Array.isArray(content.data)) content.data = []
    const exists = new Set(content.data.map((item: any) => item.id))
    pcToolConfigDefaults.forEach((item) => {
        if (!exists.has(item.id)) {
            content.data.push(cloneDeep(item))
        }
    })
    emits('update:content', content)
}
</script>

<style lang="scss" scoped>
.pc-tool-attr-item {
    display: flex;
    gap: 12px;
    width: 100%;
    padding: 14px;
    margin-bottom: 12px;
    border-radius: 8px;
    background: var(--el-fill-color-light);
}
.pc-tool-attr-upload {
    width: 96px;
    height: 96px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px dashed var(--el-border-color);
    border-radius: 8px;
    color: var(--el-text-color-secondary);
}
.pc-tool-attr-body {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 8px;
}
.pc-tool-attr-head {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 8px;
    align-items: center;
}
</style>
