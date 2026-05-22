<template>
    <el-form label-width="80px">
        <el-card shadow="never" class="!border-none">
            <el-form-item label="线型">
                <el-radio-group v-model="contentData.style">
                    <el-radio value="solid">实线</el-radio>
                    <el-radio value="dashed">虚线</el-radio>
                    <el-radio value="dotted">点线</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="颜色">
                <el-color-picker v-model="styleData.color" />
            </el-form-item>
            <el-form-item label="上间距">
                <el-input-number v-model="styleData.margin_top" :min="0" :max="80" />
            </el-form-item>
            <el-form-item label="下间距">
                <el-input-number v-model="styleData.margin_bottom" :min="0" :max="80" />
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
const contentData = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})
const styleData = computed(() => props.styles)
</script>
