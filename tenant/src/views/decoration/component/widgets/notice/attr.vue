<template>
    <el-form label-width="80px">
        <el-card shadow="never" class="!border-none">
            <el-form-item label="公告内容">
                <el-input v-model="contentData.text" type="textarea" :rows="3" />
            </el-form-item>
            <el-form-item label="链接">
                <link-picker v-model="contentData.link" @update:modelValue="handleLinkChange" />
            </el-form-item>
            <el-form-item label="背景">
                <el-color-picker v-model="styleData.background" />
            </el-form-item>
            <el-form-item label="文字">
                <el-color-picker v-model="styleData.color" />
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
</script>
