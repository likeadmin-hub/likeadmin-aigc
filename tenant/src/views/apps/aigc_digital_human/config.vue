<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
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
            <el-divider content-position="left">基础配置</el-divider>
            <el-form-item label="文案最大字数">
                <el-input-number v-model="baseConfig.script_max_length" :min="0" :precision="0" />
                <span class="ml-2 text-tx-secondary">填 0 表示不限制</span>
            </el-form-item>
            <el-form-item label="试听默认文案">
                <el-input
                    v-model="baseConfig.voice_preview_text"
                    type="textarea"
                    :rows="3"
                    placeholder="用于音色克隆后的默认试听内容"
                />
            </el-form-item>
            <el-form-item>
                <el-button type="primary" @click="handleSubmit">保存</el-button>
            </el-form-item>
        </el-form>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-config">
import { getAigcDigitalHumanConfig, setAigcDigitalHumanConfig } from '@/apps/aigc_digital_human/api'

const loading = ref(false)
const formData = reactive({
    provider_mode: 'platform',
    provider: 'mock',
    model: 'mock-digital-human',
    status: 1,
    config_json: {}
})
const baseConfig = reactive({
    script_max_length: 200,
    voice_preview_text: '欢迎使用 A. PART 声音实验室，这是一段数字人音色试听。'
})
const getData = async () => {
    loading.value = true
    try {
        const data: any = await getAigcDigitalHumanConfig()
        Object.assign(formData, data)
        Object.assign(baseConfig, data?.base_config || data?.config_json?.base_config || {})
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcDigitalHumanConfig({
        ...formData,
        base_config: baseConfig,
        config_json: {
            ...(formData.config_json || {}),
            base_config: baseConfig
        }
    })
    getData()
}
getData()
</script>
