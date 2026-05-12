<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="text-lg font-medium">AIGC视频平台配置</div>
            <div class="text-sm text-tx-secondary mt-1">平台默认 Mock Provider 配置，租户未单独配置时使用平台配置。</div>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never" v-loading="loading">
            <el-form label-width="120px" :model="formData">
                <el-form-item label="供应商">
                    <el-input v-model="formData.provider" />
                </el-form-item>
                <el-form-item label="模型">
                    <el-input v-model="formData.model" />
                </el-form-item>
                <el-form-item label="状态">
                    <el-radio-group v-model="formData.status">
                        <el-radio :value="1">启用</el-radio>
                        <el-radio :value="0">停用</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSubmit">保存</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <div class="grid grid-cols-4 gap-4">
                <div v-for="item in statCards" :key="item.label" class="p-4 bg-page rounded">
                    <div class="text-sm text-tx-secondary">{{ item.label }}</div>
                    <div class="text-2xl font-medium mt-2">{{ item.value }}</div>
                </div>
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-video-config">
import { getAigcVideoPlatformConfig, getAigcVideoTenantStat, setAigcVideoPlatformConfig } from '@/apps/aigc_video/api'

const loading = ref(false)
const stat = ref<any>({})
const formData = reactive({
    provider_mode: 'platform',
    provider: 'mock',
    model: 'mock-video',
    status: 1,
    config_json: {}
})
const statCards = computed(() => [
    { label: '任务数', value: stat.value.task_total || 0 },
    { label: '成功任务', value: stat.value.task_success || 0 },
    { label: '失败任务', value: stat.value.task_failed || 0 },
    { label: '作品数', value: stat.value.result_total || 0 }
])
const getData = async () => {
    loading.value = true
    try {
        Object.assign(formData, await getAigcVideoPlatformConfig())
        stat.value = await getAigcVideoTenantStat()
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcVideoPlatformConfig(formData)
    getData()
}
getData()
</script>
