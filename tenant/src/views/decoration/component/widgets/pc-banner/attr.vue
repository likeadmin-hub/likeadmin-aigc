<template>
    <el-form label-width="82px">
        <el-card shadow="never" class="!border-none">
            <div class="flex items-center justify-between mb-4">
                <div class="text-base text-[#101010] dark:text-[#ffffff] font-medium">轮播图片</div>
                <el-button link type="primary" @click="handleAdd">添加轮播</el-button>
            </div>
            <draggable
                v-model="contentData.data"
                item-key="index"
                handle=".drag-move"
                animation="180"
            >
                <template #item="{ element: item, index }">
                    <del-wrap :key="index" class="w-full" @close="handleDelete(index)">
                        <div class="pc-banner-attr-item">
                            <div class="pc-banner-attr-media">
                                <material-picker
                                    width="122px"
                                    height="122px"
                                    v-model="item.image"
                                    upload-class="bg-body"
                                    exclude-domain
                                >
                                    <template #upload>
                                        <div class="pc-banner-attr-upload">轮播图</div>
                                    </template>
                                </material-picker>
                            </div>
                            <div class="pc-banner-attr-body">
                                <el-input v-model="item.name" placeholder="轮播标题" />
                                <el-input
                                    v-model="item.description"
                                    type="textarea"
                                    :rows="2"
                                    resize="none"
                                    placeholder="轮播描述"
                                />
                                <el-form-item label="链接" class="!mb-0">
                                    <link-picker v-model="item.link" type="pc" />
                                </el-form-item>
                                <div class="pc-banner-attr-foot">
                                    <el-switch
                                        v-model="item.is_show"
                                        active-value="1"
                                        inactive-value="0"
                                    />
                                    <div class="drag-move cursor-move ml-auto">
                                        <icon name="el-icon-Rank" size="18" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </del-wrap>
                </template>
            </draggable>
        </el-card>
    </el-form>
</template>

<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'
import Draggable from 'vuedraggable'
import type { PropType } from 'vue'

import feedback from '@/utils/feedback'
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
        props.content.data.forEach((item: any) => {
            if (!item.link) item.link = {}
            if (item.is_show === undefined) item.is_show = '1'
        })
        return props.content
    },
    set: (value) => emits('update:content', value)
})

const handleAdd = () => {
    if ((props.content.data || []).length >= 10) {
        return feedback.msgError('最多添加10张图片')
    }
    const content = cloneDeep(props.content)
    if (!Array.isArray(content.data)) content.data = []
    content.data.push({
        image: '',
        name: '',
        description: '',
        is_show: '1',
        link: {}
    })
    emits('update:content', content)
}

const handleDelete = (index: number) => {
    if ((props.content.data || []).length <= 1) {
        return feedback.msgError('最少保留一个轮播图')
    }
    const content = cloneDeep(props.content)
    content.data.splice(index, 1)
    emits('update:content', content)
}
</script>

<style lang="scss" scoped>
.pc-banner-attr-item {
    display: flex;
    gap: 12px;
    width: 100%;
    padding: 14px;
    margin-bottom: 12px;
    border-radius: 8px;
    background: var(--el-fill-color-light);
}
.pc-banner-attr-upload {
    width: 122px;
    height: 122px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px dashed var(--el-border-color);
    border-radius: 8px;
    color: var(--el-text-color-secondary);
}
.pc-banner-attr-body {
    display: flex;
    flex: 1;
    min-width: 0;
    flex-direction: column;
    gap: 10px;
}
.pc-banner-attr-foot {
    display: flex;
    align-items: center;
}
</style>
