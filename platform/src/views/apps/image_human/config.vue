<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="text-lg font-medium">全驱数字人平台配置</div>
            <div class="text-sm text-tx-secondary mt-1">平台默认图片数字人 Provider 配置，租户未单独配置时使用平台配置。</div>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never" v-loading="loading">
            <el-form label-width="140px" :model="formData">
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
                <el-form-item label="文案字数限制">
                    <el-input-number v-model="baseConfig.prompt_max_length" :min="0" :precision="0" />
                    <span class="ml-2 text-tx-secondary">0 表示不限制</span>
                </el-form-item>
                <el-divider content-position="left">接口路径</el-divider>
                <el-form-item label="提交路径">
                    <el-input v-model="providerConfig.submit_path" />
                </el-form-item>
                <el-form-item label="查询路径">
                    <el-input v-model="providerConfig.query_path" />
                </el-form-item>
                <el-form-item label="超时时间">
                    <el-input-number v-model="providerConfig.timeout" :min="5" :precision="0" />
                    <span class="ml-2 text-tx-secondary">秒</span>
                </el-form-item>
                <el-divider content-position="left">计费配置</el-divider>
                <el-table :data="modeRows" border class="mb-4">
                    <el-table-column label="模式" min-width="120">
                        <template #default="{ row }">{{ row.label }}</template>
                    </el-table-column>
                    <el-table-column label="平台定价 / 秒" min-width="180">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="pricing.modes[row.key].platform_unit_cost"
                                :min="0"
                                :precision="6"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column label="租户默认售价 / 秒" min-width="180">
                        <template #default="{ row }">
                            <el-input-number
                                v-model="pricing.modes[row.key].tenant_unit_price"
                                :min="0"
                                :precision="6"
                            />
                        </template>
                    </el-table-column>
                </el-table>
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

<script lang="ts" setup name="platform-image-human-config">
import { getImageHumanPlatformConfig, getImageHumanTenantStat, setImageHumanPlatformConfig } from '@/apps/image_human/api'

const loading = ref(false)
const stat = ref<any>({})
const formData = reactive<any>({
    provider: 'xhadmin',
    model: 'image_human',
    status: 1,
    config_json: {}
})
const providerConfig = reactive({
    submit_path: '/api/v1/apps/image_human/submit',
    query_path: '/api/v1/apps/image_human/query',
    timeout: 60
})
const baseConfig = reactive({
    prompt_max_length: 200
})
const pricing = reactive({
    platform_unit_cost: 1.666667,
    tenant_unit_price: 2,
    billing_unit: 'second',
    modes: {
        fast: {
            label: '快速模式',
            platform_unit_cost: 1.666667,
            tenant_unit_price: 2
        },
        standard: {
            label: '标准模式',
            platform_unit_cost: 2.5,
            tenant_unit_price: 3
        }
    }
})
const modeRows = [
    { key: 'fast', label: '快速模式' },
    { key: 'standard', label: '标准模式' }
]
const statCards = computed(() => [
    { label: '任务数', value: stat.value.task_total || 0 },
    { label: '成功任务', value: stat.value.task_success || 0 },
    { label: '失败任务', value: stat.value.task_failed || 0 },
    { label: '作品数', value: stat.value.result_total || 0 },
    { label: '形象素材', value: stat.value.avatar_total || 0 },
    { label: '参考音频', value: stat.value.voice_total || 0 },
    { label: '租户成本扣点', value: stat.value.tenant_cost_points || 0 },
    { label: '用户消费扣点', value: stat.value.user_charge_points || 0 }
])
const getData = async () => {
    loading.value = true
    try {
        const data = await getImageHumanPlatformConfig()
        Object.assign(formData, data)
        Object.assign(baseConfig, data?.base_config || data?.config_json?.base_config || {})
        Object.assign(providerConfig, data?.config_json?.provider || {})
        Object.assign(pricing, normalizePricing(data?.config_json?.pricing || {}))
        stat.value = await getImageHumanTenantStat()
    } finally {
        loading.value = false
    }
}
const normalizePricing = (value: any) => {
    const fast = value?.modes?.fast || {}
    const standard = value?.modes?.standard || {}
    const fastPlatform = Number(fast.platform_unit_cost ?? value?.platform_unit_cost ?? 1.666667)
    const fastTenant = Number(fast.tenant_unit_price ?? value?.tenant_unit_price ?? 2)
    return {
        platform_unit_cost: fastPlatform,
        tenant_unit_price: fastTenant,
        billing_unit: value?.billing_unit || 'second',
        modes: {
            fast: {
                label: '快速模式',
                platform_unit_cost: fastPlatform,
                tenant_unit_price: fastTenant
            },
            standard: {
                label: '标准模式',
                platform_unit_cost: Number(standard.platform_unit_cost ?? 2.5),
                tenant_unit_price: Number(standard.tenant_unit_price ?? 3)
            }
        }
    }
}
const handleSubmit = async () => {
    pricing.platform_unit_cost = pricing.modes.fast.platform_unit_cost
    pricing.tenant_unit_price = pricing.modes.fast.tenant_unit_price
    await setImageHumanPlatformConfig({
        ...formData,
        config_json: {
            ...(formData.config_json || {}),
            base_config: baseConfig,
            provider: providerConfig,
            pricing
        },
        base_config: baseConfig
    })
    getData()
}
getData()
</script>
