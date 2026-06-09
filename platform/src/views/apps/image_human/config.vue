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
                    <el-input-number v-model="baseConfig.script_max_length" :min="0" :precision="0" />
                    <span class="ml-2 text-tx-secondary">0 表示不限制</span>
                </el-form-item>
                <el-form-item label="提示词字数限制">
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
                    <el-table-column label="上游价格 / 秒" min-width="180">
                        <template #default="{ row }">
                            <span class="font-medium">{{ upstreamModePrices[row.key] || '-' }}</span>
                        </template>
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
                    <el-button :loading="pricingQueryLoading" @click="queryUpstreamPricing">查询上游价格</el-button>
                    <el-button type="primary" @click="handleSubmit">保存</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-dialog v-model="pricingVisible" title="上游价格" width="860px" destroy-on-close>
            <el-table :data="pricingRows" size="large">
                <el-table-column label="上游接口" min-width="140">
                    <template #default="{ row }">{{ row.label }}</template>
                </el-table-column>
                <el-table-column label="本地平台定价" min-width="130">
                    <template #default="{ row }">{{ row.localPlatform }}</template>
                </el-table-column>
                <el-table-column label="本地默认售价" min-width="130">
                    <template #default="{ row }">{{ row.localTenant }}</template>
                </el-table-column>
                <el-table-column label="上游状态" min-width="100">
                    <template #default="{ row }">{{ row.available ? '可用' : '不可用' }}</template>
                </el-table-column>
                <el-table-column label="上游实际单价" min-width="180" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.upstreamPrice }}</template>
                </el-table-column>
                <el-table-column label="计费说明" min-width="260" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.billingNote }}</template>
                </el-table-column>
                <el-table-column label="来源" min-width="120">
                    <template #default="{ row }">{{ row.pricing_source?.name || '-' }}</template>
                </el-table-column>
            </el-table>
            <template #footer>
                <el-button type="primary" @click="pricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>
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
import { getImageHumanPlatformConfig, getImageHumanTenantStat, getImageHumanUpstreamPricingBatch, setImageHumanPlatformConfig } from '@/apps/image_human/api'

const loading = ref(false)
const pricingVisible = ref(false)
const pricingQueryLoading = ref(false)
const stat = ref<any>({})
const pricingRows = ref<any[]>([])
const upstreamModePrices = reactive<Record<string, string>>({})
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
    script_max_length: 200,
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
const formatPoint = (value: any, precision = 6) => {
    const number = Number(value)
    if (!Number.isFinite(number)) {
        return '-'
    }
    return number.toFixed(precision).replace(/\.?0+$/, '')
}
const imageHumanModeRate = (matrix: any, mode: string) => {
    const row = matrix && typeof matrix === 'object' && !Array.isArray(matrix) ? matrix[mode] : null
    if (!row || typeof row !== 'object' || Array.isArray(row)) {
        return null
    }
    const rate = Number(row.without_video ?? row.with_video ?? row.base ?? 0)
    return Number.isFinite(rate) ? rate : null
}
const resolveLockedParams = (row: any) => {
    const params = row?.locked_params || row?.locked_params_json || {}
    if (typeof params === 'string') {
        try {
            const parsed = JSON.parse(params)
            return parsed && typeof parsed === 'object' ? parsed : {}
        } catch {
            return {}
        }
    }
    return params && typeof params === 'object' ? params : {}
}
const imageHumanModeRateFromV2 = (item: any, mode: string) => {
    const rows = item?.pricing_v2?.items || item?.raw?.pricing_v2?.items || []
    if (!Array.isArray(rows)) {
        return null
    }
    const matched = rows.find((row: any) => {
        const params = resolveLockedParams(row)
        return String(params?.mode || '').toLowerCase() === mode
    })
    const points = Number(matched?.price?.points ?? matched?.points ?? 0)
    return Number.isFinite(points) && points > 0 ? points : null
}
const buildImageHumanUpstreamRows = (item: any) => {
    if (item.local_key !== 'submit') {
        const fixed = Number(item?.pricing?.fixed_points || 0)
        return [{
            label: '查询结果',
            localPlatform: '不单独计费',
            localTenant: '不单独计费',
            upstreamPrice: fixed > 0 ? `${formatPoint(fixed)} 点 / 次` : (item.price_view?.formula || item.message || '-'),
            billingNote: item.price_view?.formula || item.message || '-',
            ...item
        }]
    }
    return modeRows.map((mode) => {
        const local = (pricing.modes as any)[mode.key] || {}
        const rate = imageHumanModeRateFromV2(item, mode.key) ?? imageHumanModeRate(item?.pricing?.pricing_matrix, mode.key)
        const upstreamPrice = rate !== null
            ? `${formatPoint(rate)} 点 / 秒`
            : (item.price_view?.formula || item.message || '-')
        return {
            label: `提交生成 / ${mode.label}`,
            localPlatform: `${local.platform_unit_cost || 0} / 秒`,
            localTenant: `${local.tenant_unit_price || 0} / 秒`,
            upstreamPrice,
            billingNote: rate !== null
                ? `实际扣点 = 输入音频秒数 × ${formatPoint(rate)} 点/秒`
                : (item.price_view?.formula || item.message || '-'),
            ...item
        }
    })
}
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
const queryUpstreamPricing = async () => {
    pricingQueryLoading.value = true
    try {
        const result = await getImageHumanUpstreamPricingBatch()
        pricingRows.value = (result.items || []).flatMap(buildImageHumanUpstreamRows)
        Object.keys(upstreamModePrices).forEach((key) => delete upstreamModePrices[key])
        pricingRows.value.forEach((row: any) => {
            const mode = modeRows.find((item) => row.label?.includes(item.label))
            if (mode && row.upstreamPrice && row.upstreamPrice !== '-') {
                upstreamModePrices[mode.key] = row.upstreamPrice
            }
        })
        pricingVisible.value = true
    } finally {
        pricingQueryLoading.value = false
    }
}
getData()
</script>
