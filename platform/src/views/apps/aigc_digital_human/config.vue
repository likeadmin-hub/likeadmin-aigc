<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="text-lg font-medium">数字人视频平台配置</div>
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
        <el-card class="!border-none mt-4" shadow="never" v-loading="pricingLoading">
            <template #header>
                <div class="font-medium">平台给租户定价</div>
            </template>
            <el-form label-width="150px">
                <div class="text-sm text-tx-secondary mb-3">视频合成按模型在“通道管理”中配置每秒价格。</div>
                <el-form-item label="形象克隆成本">
                    <el-input-number v-model="pricing.avatar_clone.platform_unit_cost" :min="0" :precision="2" />
                    <span class="ml-2 text-tx-secondary">/ 个</span>
                </el-form-item>
                <el-form-item label="形象克隆默认售价">
                    <el-input-number v-model="pricing.avatar_clone.tenant_unit_price" :min="Number(pricing.avatar_clone.platform_unit_cost || 0)" :precision="2" />
                    <span class="ml-2 text-tx-secondary">/ 个</span>
                </el-form-item>
                <el-form-item label="音色克隆成本">
                    <el-input-number v-model="pricing.voice_clone.platform_unit_cost" :min="0" :precision="2" />
                    <span class="ml-2 text-tx-secondary">/ 个</span>
                </el-form-item>
                <el-form-item label="音色克隆默认售价">
                    <el-input-number v-model="pricing.voice_clone.tenant_unit_price" :min="Number(pricing.voice_clone.platform_unit_cost || 0)" :precision="2" />
                    <span class="ml-2 text-tx-secondary">/ 个</span>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" :loading="pricingSaving" @click="handlePricingSubmit">保存定价</el-button>
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

<script lang="ts" setup name="platform-aigc-digital-human-config">
import { getAigcDigitalHumanPlatformConfig, getAigcDigitalHumanPricing, getAigcDigitalHumanTenantStat, setAigcDigitalHumanPlatformConfig, setAigcDigitalHumanPricing } from '@/apps/aigc_digital_human/api'

const loading = ref(false)
const pricingLoading = ref(false)
const pricingSaving = ref(false)
const stat = ref<any>({})
const pricing = reactive<any>({
    generate_models: [],
    generate: { platform_unit_cost: 0.2, tenant_unit_price: 0.3 },
    avatar_clone: { platform_unit_cost: 2, tenant_unit_price: 3 },
    voice_clone: { platform_unit_cost: 1, tenant_unit_price: 2 }
})
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
const statCards = computed(() => [
    { label: '任务数', value: stat.value.task_total || 0 },
    { label: '成功任务', value: stat.value.task_success || 0 },
    { label: '失败任务', value: stat.value.task_failed || 0 },
    { label: '作品数', value: stat.value.result_total || 0 }
])
const getData = async () => {
    loading.value = true
    try {
        Object.assign(formData, await getAigcDigitalHumanPlatformConfig())
        Object.assign(baseConfig, (formData as any)?.base_config || (formData as any)?.config_json?.base_config || {})
        Object.assign(pricing, await getAigcDigitalHumanPricing())
        stat.value = await getAigcDigitalHumanTenantStat()
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcDigitalHumanPlatformConfig({
        ...formData,
        base_config: baseConfig,
        config_json: {
            ...(formData.config_json || {}),
            base_config: baseConfig
        }
    })
    getData()
}
const handlePricingSubmit = async () => {
    pricingSaving.value = true
    try {
        await setAigcDigitalHumanPricing(pricing)
        await getData()
    } finally {
        pricingSaving.value = false
    }
}
getData()
</script>
