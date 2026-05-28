<template>
    <el-form label-width="86px">
        <el-form-item label="图片">
            <material-picker v-model="form.image" exclude-domain />
        </el-form-item>
        <el-form-item label="标题"><el-input v-model="form.title" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" type="textarea" :rows="3" /></el-form-item>
        <el-form-item label="链接">
            <link-picker v-model="form.link" />
        </el-form-item>
    </el-form>
</template>

<script lang="ts" setup>
const props = defineProps({ content: { type: Object, default: () => ({}) } })
const emit = defineEmits<(event: 'update:content', value: any) => void>()
const form = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})
</script>
