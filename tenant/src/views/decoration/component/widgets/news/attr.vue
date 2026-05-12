<template>
    <el-form label-width="80px">
        <el-card shadow="never" class="!border-none">
            <el-form-item label="是否显示">
                <el-switch v-model="contentData.enabled" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="标题">
                <el-input v-model="contentData.title" />
            </el-form-item>
            <el-form-item label="显示数量">
                <el-input-number v-model="contentData.limit" :min="1" :max="20" />
            </el-form-item>
            <el-form-item label="显示封面">
                <el-switch v-model="contentData.show_image" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="显示摘要">
                <el-switch v-model="contentData.show_desc" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="显示时间">
                <el-switch v-model="contentData.show_time" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="显示浏览">
                <el-switch v-model="contentData.show_click" :active-value="1" :inactive-value="0" />
            </el-form-item>
        </el-card>
        <el-card shadow="never" class="!border-none mt-2">
            <div class="text-base text-[#101010] font-medium mb-4">样式设置</div>
            <el-form-item label="背景颜色">
                <el-color-picker v-model="styleData.background" />
            </el-form-item>
            <el-form-item label="标题颜色">
                <el-color-picker v-model="styleData.title_color" />
            </el-form-item>
            <el-form-item label="文字颜色">
                <el-color-picker v-model="styleData.text_color" />
            </el-form-item>
        </el-card>
    </el-form>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import type options from './options'

type OptionsType = ReturnType<typeof options>
const emit = defineEmits<(event: 'update:content', data: OptionsType['content']) => void>()
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    },
    styles: {
        type: Object as PropType<OptionsType['styles']>,
        default: () => ({})
    }
})

const defaultContent = {
    enabled: 1,
    title: '最新资讯',
    limit: 10,
    show_image: 1,
    show_desc: 1,
    show_time: 1,
    show_click: 1
}
const defaultStyles = {
    background: '#f8f8f8',
    title_color: '#101010',
    text_color: '#333333'
}

const ensureDefaults = () => {
    let changed = false
    Object.keys(defaultContent).forEach((key) => {
        if ((props.content as any)[key] === undefined) {
            ;(props.content as any)[key] = (defaultContent as any)[key]
            changed = true
        }
    })
    Object.keys(defaultStyles).forEach((key) => {
        if ((props.styles as any)[key] === undefined) {
            ;(props.styles as any)[key] = (defaultStyles as any)[key]
        }
    })
    if (changed) {
        emit('update:content', props.content)
    }
}

const contentData = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})
const styleData = computed(() => props.styles)

watch(
    () => props.content,
    () => ensureDefaults(),
    { immediate: true, deep: true }
)
</script>
