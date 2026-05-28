<template>
    <el-form label-width="86px">
        <el-form-item label="标题"><el-input v-model="form.title" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" type="textarea" :rows="3" /></el-form-item>
        <el-form-item label="主按钮"><el-input v-model="form.primary_text" /></el-form-item>
        <el-form-item label="副按钮"><el-input v-model="form.secondary_text" /></el-form-item>
    </el-form>
</template>

<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'

const props = defineProps({ content: { type: Object, default: () => ({}) } })
const emit = defineEmits<(event: 'update:content', value: any) => void>()
const form = computed({
    get: () => props.content,
    set: (value) => emit('update:content', cloneDeep(value))
})
</script>
