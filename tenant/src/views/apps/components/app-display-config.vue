<template>
    <el-card class="!border-none mb-4" shadow="never">
        <template #header>
            <div class="font-medium">展示配置</div>
        </template>
        <el-form label-width="120px">
            <el-form-item label="应用标题">
                <div class="w-80">
                    <el-input
                        v-model.trim="formData.title"
                        maxlength="80"
                        show-word-limit
                        placeholder="请输入应用标题"
                    />
                </div>
            </el-form-item>
            <el-form-item label="应用封面">
                <div>
                    <material-picker
                        v-model="formData.cover_uri"
                        :limit="1"
                        width="160px"
                        height="96px"
                    />
                    <div class="form-tips">支持 jpg、png、webp、gif，建议 16:9 封面图</div>
                </div>
            </el-form-item>
            <el-form-item label="应用描述">
                <div class="w-[520px]">
                    <el-input
                        v-model.trim="formData.description"
                        type="textarea"
                        :rows="3"
                        maxlength="500"
                        show-word-limit
                        placeholder="请输入应用描述"
                    />
                </div>
            </el-form-item>
            <el-form-item label="虚拟使用数">
                <div class="w-80">
                    <el-input
                        v-model.trim="formData.virtual_use_count"
                        maxlength="50"
                        placeholder="例如：2.3万"
                    />
                </div>
            </el-form-item>
            <el-form-item label="排序">
                <el-input-number v-model="formData.sort" :precision="0" />
                <span class="ml-2 text-tx-secondary">数字越大越靠前</span>
            </el-form-item>
            <el-form-item label="展示状态">
                <el-radio-group v-model="formData.status">
                    <el-radio :value="1">显示</el-radio>
                    <el-radio :value="0">隐藏</el-radio>
                </el-radio-group>
            </el-form-item>
        </el-form>
    </el-card>
</template>

<script setup lang="ts">
const props = defineProps<{
    modelValue?: Record<string, any>
}>()

const emit = defineEmits<{
    (event: 'update:modelValue', value: Record<string, any>): void
}>()

const defaults = {
    title: '',
    description: '',
    cover_uri: '',
    virtual_use_count: '',
    sort: 0,
    status: 1,
    extra: {}
}

const formData = reactive({ ...defaults })
let syncingFromParent = false

watch(
    () => props.modelValue,
    (value) => {
        syncingFromParent = true
        Object.assign(formData, defaults, value || {})
        formData.cover_uri = value?.cover_uri || value?.cover_url || ''
        formData.virtual_use_count = value?.virtual_use_count || value?.virtualUseCount || ''
        nextTick(() => {
            syncingFromParent = false
        })
    },
    { immediate: true, deep: true }
)

watch(
    formData,
    (value) => {
        if (syncingFromParent) return
        emit('update:modelValue', { ...value })
    },
    { deep: true }
)
</script>
