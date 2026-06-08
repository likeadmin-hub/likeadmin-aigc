<template>
    <div v-loading="loading">
        <app-display-config v-model="displayConfig" />
        <el-card class="!border-none" shadow="never">
        <el-form label-width="120px" :model="formData">
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
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-config">
import { getAigcDigitalHumanConfig, setAigcDigitalHumanConfig } from '@/apps/aigc_digital_human/api'
import AppDisplayConfig from '@/views/apps/components/app-display-config.vue'

const loading = ref(false)
const formData = reactive({
    status: 1,
    config_json: {}
})
const baseConfig = reactive({
    script_max_length: 200,
    voice_preview_text: '欢迎使用 A. PART 声音实验室，这是一段数字人音色试听。'
})
const displayConfig = ref<Record<string, any>>({})
const getData = async () => {
    loading.value = true
    try {
        const data: any = await getAigcDigitalHumanConfig()
        Object.assign(formData, data)
        Object.assign(baseConfig, data?.base_config || data?.config_json?.base_config || {})
        displayConfig.value = data?.display_config || {}
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcDigitalHumanConfig({
        status: formData.status,
        base_config: baseConfig,
        config_json: {
            ...(formData.config_json || {}),
            base_config: baseConfig
        },
        display_config: displayConfig.value
    })
    getData()
}
getData()
</script>
