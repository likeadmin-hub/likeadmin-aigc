<template>
    <div v-loading="loading">
        <app-display-config v-model="displayConfig" />
        <el-card class="!border-none" shadow="never">
            <el-form label-width="120px" :model="formData">
                <el-form-item label="供应商模式">
                    <el-radio-group v-model="formData.provider_mode">
                        <el-radio value="platform">平台默认</el-radio>
                        <el-radio value="tenant">租户自定义</el-radio>
                    </el-radio-group>
                </el-form-item>
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
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-image-config">
import { getAigcImageConfig, setAigcImageConfig } from '@/apps/aigc_image/api'
import AppDisplayConfig from '@/views/apps/components/app-display-config.vue'

const loading = ref(false)
const formData = reactive({
    provider_mode: 'platform',
    provider: 'mock',
    model: 'mock-image',
    status: 1,
    config_json: {}
})
const displayConfig = ref<Record<string, any>>({})
const getData = async () => {
    loading.value = true
    try {
        const data: any = await getAigcImageConfig()
        Object.assign(formData, data)
        displayConfig.value = data?.display_config || {}
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcImageConfig({
        ...formData,
        display_config: displayConfig.value
    })
    getData()
}
getData()
</script>
