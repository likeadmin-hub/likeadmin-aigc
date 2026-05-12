<template>
    <el-card class="!border-none" shadow="never">
        <div class="text-base font-medium mb-4">数字人视频调价</div>
        <el-form v-loading="pricingLoading" label-width="140px">
            <div class="text-sm text-tx-secondary mb-3">模型合成价格</div>
            <el-table :data="pricing.generate_models" size="large" class="mb-6">
                <el-table-column label="模型" min-width="150">
                    <template #default="{ row }">
                        <div>{{ row.name }}</div>
                        <div class="text-xs text-tx-secondary">{{ row.model }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="平台成本" width="130">
                    <template #default="{ row }">{{ row.platform_unit_cost }} / 秒</template>
                </el-table-column>
                <el-table-column label="用户售价" width="180">
                    <template #default="{ row }">
                        <el-input-number v-model="row.tenant_unit_price" :min="Number(row.platform_unit_cost || 0)" :precision="2" />
                    </template>
                </el-table-column>
            </el-table>
            <el-form-item label="形象克隆成本">
                <span>{{ pricing.avatar_clone.platform_unit_cost }} / 个</span>
            </el-form-item>
            <el-form-item label="形象克隆用户售价">
                <el-input-number v-model="pricing.avatar_clone.tenant_unit_price" :min="Number(pricing.avatar_clone.platform_unit_cost || 0)" :precision="2" />
            </el-form-item>
            <el-form-item label="音色克隆成本">
                <span>{{ pricing.voice_clone.platform_unit_cost }} / 个</span>
            </el-form-item>
            <el-form-item label="音色克隆用户售价">
                <el-input-number v-model="pricing.voice_clone.tenant_unit_price" :min="Number(pricing.voice_clone.platform_unit_cost || 0)" :precision="2" />
            </el-form-item>
            <el-form-item>
                <el-button type="primary" :loading="pricingSaving" @click="savePricing">保存调价</el-button>
            </el-form-item>
        </el-form>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-channel-price">
import { getAigcDigitalHumanPricing, setAigcDigitalHumanPricing } from '@/apps/aigc_digital_human/api'

const pricingLoading = ref(false)
const pricingSaving = ref(false)
const pricing = reactive<any>({
    generate_models: [],
    generate: { platform_unit_cost: 0, tenant_unit_price: 0 },
    avatar_clone: { platform_unit_cost: 0, tenant_unit_price: 0 },
    voice_clone: { platform_unit_cost: 0, tenant_unit_price: 0 }
})
const getLists = async () => {
    pricingLoading.value = true
    try {
        Object.assign(pricing, await getAigcDigitalHumanPricing())
    } finally {
        pricingLoading.value = false
    }
}
const savePricing = async () => {
    pricingSaving.value = true
    try {
        await setAigcDigitalHumanPricing(pricing)
        getLists()
    } finally {
        pricingSaving.value = false
    }
}
getLists()
</script>
