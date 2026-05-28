<template>
    <el-form label-width="90px">
        <el-form-item label="标题"><el-input v-model="form.title" /></el-form-item>
        <el-form-item label="数据源">
            <el-select v-model="form.source_key" class="w-full">
                <el-option label="图片案例" value="image_cases" />
                <el-option label="视频案例" value="video_cases" />
                <el-option label="数字人案例" value="digital_human_cases" />
            </el-select>
        </el-form-item>
        <el-form-item label="数量">
            <el-input-number v-model="form.source_params.limit" :min="1" :max="50" />
        </el-form-item>
    </el-form>
</template>

<script lang="ts" setup>
const props = defineProps({ content: { type: Object, default: () => ({}) } })
const emit = defineEmits<(event: 'update:content', value: any) => void>()
const form = computed({
    get: () => {
        if (!props.content.source_params) props.content.source_params = { limit: 20 }
        return props.content
    },
    set: (value) => emit('update:content', value)
})
</script>
