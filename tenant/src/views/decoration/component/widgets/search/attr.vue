<template>
    <el-form label-width="80px">
        <el-card shadow="never" class="!border-none">
            <el-form-item label="是否显示">
                <el-switch v-model="contentData.enabled" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="提示文字">
                <el-input v-model="contentData.placeholder" />
            </el-form-item>
            <el-form-item label="链接">
                <link-picker v-model="contentData.link" @update:modelValue="handleLinkChange" />
            </el-form-item>
        </el-card>
        <el-card shadow="never" class="!border-none mt-2">
            <div class="text-base text-[#101010] font-medium mb-4">样式设置</div>
            <el-form-item label="背景颜色">
                <el-color-picker v-model="styleData.background" />
            </el-form-item>
            <el-form-item label="搜索框">
                <el-color-picker v-model="styleData.input_background" />
            </el-form-item>
            <el-form-item label="文字颜色">
                <el-color-picker v-model="styleData.text_color" />
            </el-form-item>
        </el-card>
    </el-form>
</template>
<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'
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
const defaults = {
    content: {
        enabled: 1,
        placeholder: '请输入关键词搜索',
        link: {}
    },
    styles: {
        background: '#ffffff',
        input_background: '#f4f4f4',
        text_color: '#999999'
    }
}
const ensureDefaults = () => {
    let changed = false
    Object.keys(defaults.content).forEach((key) => {
        if ((props.content as any)[key] === undefined) {
            ;(props.content as any)[key] = (defaults.content as any)[key]
            changed = true
        }
    })
    Object.keys(defaults.styles).forEach((key) => {
        if ((props.styles as any)[key] === undefined) {
            ;(props.styles as any)[key] = (defaults.styles as any)[key]
        }
    })
    if (changed) emit('update:content', props.content)
}
const contentData = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})
const styleData = computed(() => props.styles)
const handleLinkChange = (value: any) => {
    const content = cloneDeep(props.content)
    content.link = value || {}
    emit('update:content', content)
}
watch(
    () => props.content,
    () => ensureDefaults(),
    { immediate: true, deep: true }
)
</script>
