<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
        <el-form label-width="140px">
            <el-form-item label="文案字数限制">
                <el-input-number v-model="baseConfig.prompt_max_length" :min="0" :precision="0" />
                <span class="ml-2 text-tx-secondary">0 表示不限制</span>
            </el-form-item>
            <el-form-item label="计费单位">
                <el-tag>{{ unitText(pricing.billing_unit) }}</el-tag>
            </el-form-item>
            <el-table :data="modeRows" border class="mb-4">
                <el-table-column label="模式" min-width="120">
                    <template #default="{ row }">{{ row.label }}</template>
                </el-table-column>
                <el-table-column label="成本参考 / 秒" min-width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="pricing.modes[row.key].platform_unit_cost"
                            :precision="6"
                            disabled
                        />
                    </template>
                </el-table-column>
                <el-table-column label="用户价格 / 秒" min-width="180">
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
</template>

<script lang="ts" setup name="tenant-image-human-config">
import { getImageHumanConfig, setImageHumanConfig } from '@/apps/image_human/api'

const loading = ref(false)
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
const getData = async () => {
    loading.value = true
    try {
        const data = await getImageHumanConfig()
        Object.assign(baseConfig, data?.base_config || data?.config_json?.base_config || {})
        Object.assign(
            pricing,
            normalizePricing(data?.option_config?.pricing || data?.config_json?.pricing || {})
        )
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
    await setImageHumanConfig({
        base_config: baseConfig,
        config_json: {
            base_config: baseConfig,
            pricing: {
                tenant_unit_price: pricing.modes.fast.tenant_unit_price,
                modes: {
                    fast: {
                        tenant_unit_price: pricing.modes.fast.tenant_unit_price
                    },
                    standard: {
                        tenant_unit_price: pricing.modes.standard.tenant_unit_price
                    }
                }
            }
        }
    })
    getData()
}
const unitText = (unit: string) => {
    const map: Record<string, string> = {
        second: '按秒'
    }
    return map[unit] || unit || '-'
}
getData()
</script>
