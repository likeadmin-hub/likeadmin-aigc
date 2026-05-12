<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
        <el-form label-width="140px" :model="formData">
            <el-form-item label="应用状态">
                <el-radio-group v-model="formData.status">
                    <el-radio :value="1">启用</el-radio>
                    <el-radio :value="0">停用</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="默认供应商">
                <el-input v-model="formData.provider" />
            </el-form-item>
            <el-form-item label="默认模型">
                <el-input v-model="formData.model" />
            </el-form-item>
            <el-form-item label="系统提示词">
                <el-input v-model="formData.config_json.system_prompt" type="textarea" :rows="5" />
            </el-form-item>
            <el-form-item label="上下文消息数">
                <el-input-number v-model="formData.config_json.max_context_messages" :min="2" :max="100" />
            </el-form-item>
            <el-form-item label="标题截取字数">
                <el-input-number v-model="formData.config_json.auto_title_chars" :min="8" :max="50" />
            </el-form-item>
            <el-form-item>
                <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
            </el-form-item>
        </el-form>
    </el-card>
</template>

<script lang="ts" setup name="platform-aigc-llm-config">
import { getAigcLlmPlatformConfig, setAigcLlmPlatformConfig } from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const formData = reactive({
    provider_mode: 'platform',
    provider: 'openai_compatible',
    model: 'qwen3_6_plus',
    status: 1,
    config_json: {
        system_prompt: '',
        max_context_messages: 12,
        auto_title_chars: 18
    }
})

const normalize = (data: any = {}) => ({
    provider_mode: data.provider_mode || 'platform',
    provider: data.provider || 'openai_compatible',
    model: data.model || 'qwen3_6_plus',
    status: Number(data.status ?? 1),
    config_json: {
        system_prompt: data.config_json?.system_prompt || '',
        max_context_messages: Number(data.config_json?.max_context_messages || 12),
        auto_title_chars: Number(data.config_json?.auto_title_chars || 18)
    }
})

const getData = async () => {
    loading.value = true
    try {
        Object.assign(formData, normalize(await getAigcLlmPlatformConfig()))
    } finally {
        loading.value = false
    }
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await setAigcLlmPlatformConfig(formData)
        feedback.msgSuccess('保存成功')
        await getData()
    } finally {
        saving.value = false
    }
}

getData()
</script>
