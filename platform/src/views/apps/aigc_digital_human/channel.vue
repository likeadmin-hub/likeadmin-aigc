<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <div class="billing-head">
                <div>
                    <div class="text-base font-medium">数字人视频计费配置</div>
                    <div class="text-sm text-tx-secondary mt-1">每个模型按合成音频时长计费，单位为点/秒。</div>
                </div>
                <el-switch v-model="showDisabled" active-text="显示停用模型" />
            </div>
            <div class="clone-pricing">
                <div class="text-base font-medium mb-4">克隆单价</div>
                <el-form label-width="150px">
                    <el-form-item label="形象克隆平台定价">
                        <el-input-number v-model="pricing.avatar_clone.platform_unit_cost" :min="0" :precision="2" />
                        <span class="ml-2 text-tx-secondary">/ 个</span>
                    </el-form-item>
                    <el-form-item label="形象克隆默认售价">
                        <el-input-number v-model="pricing.avatar_clone.tenant_unit_price" :min="Number(pricing.avatar_clone.platform_unit_cost || 0)" :precision="2" />
                        <span class="ml-2 text-tx-secondary">/ 个</span>
                    </el-form-item>
                    <el-form-item label="音色克隆平台定价">
                        <el-input-number v-model="pricing.voice_clone.platform_unit_cost" :min="0" :precision="2" />
                        <span class="ml-2 text-tx-secondary">/ 个</span>
                    </el-form-item>
                    <el-form-item label="音色克隆默认售价">
                        <el-input-number v-model="pricing.voice_clone.tenant_unit_price" :min="Number(pricing.voice_clone.platform_unit_cost || 0)" :precision="2" />
                        <span class="ml-2 text-tx-secondary">/ 个</span>
                    </el-form-item>
                    <el-form-item>
                        <el-button :loading="clonePricingLoading" @click="openClonePricing">查询上游价格</el-button>
                        <el-button type="primary" :loading="pricingSaving" @click="saveClonePricing">保存克隆单价</el-button>
                    </el-form-item>
                </el-form>
            </div>
            <el-divider />
            <el-table v-loading="pager.loading" size="large" :data="tableLists" max-height="520">
                <el-table-column label="模型编码" prop="code" min-width="140" />
                <el-table-column label="模型名称" prop="name" min-width="150" />
                <el-table-column label="模型描述" prop="description" min-width="220" show-overflow-tooltip />
                <el-table-column label="供应商" prop="provider" min-width="120" />
                <el-table-column label="Provider模型" prop="model" min-width="160" />
                <el-table-column label="上游价格" min-width="130">
                    <template #default="{ row }">{{ row.upstream_price_text || '-' }}</template>
                </el-table-column>
                <el-table-column label="平台定价" min-width="130">
                    <template #default="{ row }">
                        <el-input-number v-model="row.platform_unit_cost" :min="0" :precision="2" size="small" />
                    </template>
                </el-table-column>
                <el-table-column label="默认售价" min-width="130">
                    <template #default="{ row }">
                        <el-input-number v-model="row.tenant_unit_price" :min="Number(row.platform_unit_cost || 0)" :precision="2" size="small" />
                    </template>
                </el-table-column>
                <el-table-column label="单位" min-width="80">
                    <template #default>/ 秒</template>
                </el-table-column>
                <el-table-column label="规格数" min-width="100">
                    <template #default="{ row }">{{ row.specs?.length || 0 }}</template>
                </el-table-column>
                <el-table-column label="排序" prop="sort" min-width="90" />
                <el-table-column label="状态" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="row.status"
                            :active-value="1"
                            :inactive-value="0"
                            :loading="statusLoadingId === row.id"
                            @change="(value) => handleStatus(row, value)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="180" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link :loading="pricingLoadingId === row.id" @click="openPricing(row)">上游价格</el-button>
                        <el-button type="primary" link @click="saveRowPricing(row)">保存价格</el-button>
                        <el-button type="primary" link @click="openEdit(row)">配置</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <div class="pagination-wrap">
                <pagination v-model="pager" @change="handlePageChange" />
            </div>
        </el-card>

        <el-dialog v-model="editVisible" title="编辑通道" width="560px" destroy-on-close>
            <el-form label-width="110px" :model="formData">
                <el-form-item label="通道编码">
                    <el-input v-model="formData.code" />
                </el-form-item>
                <el-form-item label="通道名称">
                    <el-input v-model="formData.name" />
                </el-form-item>
                <el-form-item label="模型描述">
                    <el-input v-model="formData.description" type="textarea" :rows="3" placeholder="展示给用户看的模型说明，不显示内部模型名称" />
                </el-form-item>
                <el-form-item label="供应商">
                    <el-select v-model="formData.provider" class="w-full">
                        <el-option label="Mock" value="mock" />
                        <el-option label="内置服务" value="xhadmin" />
                    </el-select>
                </el-form-item>
                <el-form-item label="模型">
                    <el-input v-model="formData.model" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="formData.sort" class="w-full" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="pricingVisible" title="上游价格" width="720px" destroy-on-close>
            <el-descriptions :column="2" border>
                <el-descriptions-item label="本地模型">{{ pricingRow.name || '-' }}</el-descriptions-item>
                <el-descriptions-item label="Provider模型">{{ pricingRow.model || '-' }}</el-descriptions-item>
                <el-descriptions-item label="本地平台定价">{{ pricingRow.platform_unit_cost || 0 }}</el-descriptions-item>
                <el-descriptions-item label="本地默认售价">{{ pricingRow.tenant_unit_price || 0 }}</el-descriptions-item>
                <el-descriptions-item label="上游接口">{{ pricingResult.resource?.app_code || '-' }} / {{ pricingResult.resource?.api_code || '-' }}</el-descriptions-item>
                <el-descriptions-item label="上游状态">{{ pricingResult.available ? '可用' : '不可用' }}</el-descriptions-item>
                <el-descriptions-item label="价格来源">{{ pricingResult.pricing_source?.name || '-' }}</el-descriptions-item>
                <el-descriptions-item label="计费方式">{{ pricingResult.price_view?.billing_type_desc || '-' }}</el-descriptions-item>
                <el-descriptions-item label="上游渠道">{{ pricingResult.resource?.channel_name || pricingResult.resource?.channel_code || '-' }}</el-descriptions-item>
                <el-descriptions-item label="上游实际单价" :span="2">{{ pricingResult.unit_price_desc || '-' }}</el-descriptions-item>
                <el-descriptions-item label="计费说明" :span="2">{{ pricingResult.billing_note || pricingResult.price_view?.formula || pricingResult.message || '-' }}</el-descriptions-item>
            </el-descriptions>
            <template #footer>
                <el-button type="primary" @click="pricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="clonePricingVisible" title="克隆上游价格" width="820px" destroy-on-close>
            <el-table :data="clonePricingRows" size="large">
                <el-table-column label="项目" min-width="140">
                    <template #default="{ row }">{{ row.label }}</template>
                </el-table-column>
                <el-table-column label="本地平台定价" min-width="130">
                    <template #default="{ row }">{{ row.localPlatform }}</template>
                </el-table-column>
                <el-table-column label="本地默认售价" min-width="130">
                    <template #default="{ row }">{{ row.localTenant }}</template>
                </el-table-column>
                <el-table-column label="上游状态" min-width="100">
                    <template #default="{ row }">{{ row.pricing?.available ? '可用' : '不可用' }}</template>
                </el-table-column>
                <el-table-column label="上游实际单价" min-width="160" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.unitPriceDesc }}</template>
                </el-table-column>
                <el-table-column label="计费说明" min-width="220" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.billingNote }}</template>
                </el-table-column>
                <el-table-column label="来源" min-width="120">
                    <template #default="{ row }">{{ row.pricing?.pricing_source?.name || '-' }}</template>
                </el-table-column>
            </el-table>
            <template #footer>
                <el-button type="primary" @click="clonePricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-digital-human-channel">
import { getAigcDigitalHumanChannels, saveAigcDigitalHumanChannel, setAigcDigitalHumanChannelStatus } from '@/apps/aigc_digital_human/api'
import { getAigcDigitalHumanPricing, getAigcDigitalHumanUpstreamClonePricing, getAigcDigitalHumanUpstreamPricing, getAigcDigitalHumanUpstreamPricingBatch, setAigcDigitalHumanPricing } from '@/apps/aigc_digital_human/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const pricingSaving = ref(false)
const editVisible = ref(false)
const pricingVisible = ref(false)
const clonePricingVisible = ref(false)
const showDisabled = ref(false)
const statusLoadingId = ref(0)
const pricingLoadingId = ref(0)
const clonePricingLoading = ref(false)
const lists = ref<any[]>([])
const { pager, tableLists, setLists } = useLocalPaging({ size: 15 })
const pricing = reactive<any>({
    generate_models: [],
    avatar_clone: { platform_unit_cost: 2, tenant_unit_price: 3 },
    voice_clone: { platform_unit_cost: 1, tenant_unit_price: 2 }
})
const formData = reactive({
    id: 0,
    code: '',
    name: '',
    description: '',
    provider: 'mock',
    model: 'mock-digital-human',
    config_json: {},
    status: 1,
    sort: 0
})
const pricingRow = reactive<any>({})
const pricingResult = reactive<any>({})
const clonePricingRows = ref<any[]>([])
const upstreamPricing = ref<any>({})

const formatPoint = (value: any, precision = 6) => {
    const number = Number(value)
    if (!Number.isFinite(number)) {
        return '-'
    }
    return number.toFixed(precision).replace(/\.?0+$/, '')
}

const resolveModelRate = (modelRates: any, model: string) => {
    if (!modelRates || typeof modelRates !== 'object' || Array.isArray(modelRates)) {
        return null
    }
    const key = String(model || '')
    if (key && modelRates[key] !== undefined) {
        return Number(modelRates[key])
    }
    const aliases: Record<string, string> = {
        '1.0': 'xiaojiayu1.0',
        '2.0': 'xiaojiayu2.0',
        '3.0': 'xiaojiayu3.0',
        'xiaojiayu1.0': '1.0',
        'xiaojiayu2.0': '2.0',
        'xiaojiayu3.0': '3.0'
    }
    const alias = aliases[key.toLowerCase()]
    return alias && modelRates[alias] !== undefined ? Number(modelRates[alias]) : null
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

const buildDigitalHumanPricingView = (result: any, row: any) => {
    const v2Rate = resolveModelRateFromV2(result, row?.model)
    if (v2Rate !== null && Number.isFinite(v2Rate)) {
        return {
            ...result,
            unit_price_desc: `${formatPoint(v2Rate)} 点 / 秒`,
            billing_note: `实际扣点 = 输入音频秒数 × ${formatPoint(v2Rate)} 点/秒（按 Provider 模型 ${row?.model || '-'} 匹配）`
        }
    }
    const rate = resolveModelRate(result?.pricing?.model_rates, row?.model)
    if (rate !== null && Number.isFinite(rate)) {
        return {
            ...result,
            unit_price_desc: `${formatPoint(rate)} 点 / 秒`,
            billing_note: `实际扣点 = 输入音频秒数 × ${formatPoint(rate)} 点/秒（按 Provider 模型 ${row?.model || '-'} 匹配）`
        }
    }
    const fallbackRate = Number(result?.pricing?.per_1k_input || 0)
    if (fallbackRate > 0) {
        return {
            ...result,
            unit_price_desc: `${formatPoint(fallbackRate)} 点 / 秒`,
            billing_note: `实际扣点 = 输入音频秒数 × ${formatPoint(fallbackRate)} 点/秒`
        }
    }
    return {
        ...result,
        unit_price_desc: result?.price_view?.formula || result?.message || '-',
        billing_note: result?.price_view?.formula || result?.message || '-'
    }
}

const upstreamPriceText = (row: any) => {
    if (!upstreamPricing.value?.available) {
        return ''
    }
    return buildDigitalHumanPricingView(upstreamPricing.value, row).unit_price_desc || ''
}

const resolveModelRateFromV2 = (result: any, model: string) => {
    const rows = result?.pricing_v2?.items || result?.raw?.pricing_v2?.items || []
    if (!Array.isArray(rows)) {
        return null
    }
    const normalizedModel = String(model || '').toLowerCase()
    const matched = rows.find((row: any) => {
        const params = resolveLockedParams(row)
        return String(params?.model || row?.title || '').toLowerCase() === normalizedModel
    })
    const points = Number(matched?.price?.points ?? matched?.points ?? 0)
    return Number.isFinite(points) && points > 0 ? points : null
}

const fixedUnitPriceDesc = (result: any) => {
    if (!result?.available) {
        return result?.message || '-'
    }
    const fixed = Number(result?.pricing?.fixed_points || 0)
    if (fixed > 0) {
        return `${formatPoint(fixed)} 点 / 次`
    }
    return result?.price_view?.formula || '-'
}

const normalizeForm = (row: any = {}) => ({
    id: Number(row.id || 0),
    code: row.code || '',
    name: row.name || '',
    description: row.description || row.config_json?.description || '',
    provider: row.provider || 'mock',
    model: row.model || 'mock-digital-human',
    config_json: row.config_json || {},
    status: Number(row.status ?? 1),
    sort: Number(row.sort ?? 0)
})
const visibleLists = computed(() => lists.value.filter((row) => showDisabled.value || Number(row.status) === 1))

const getLists = async () => {
    pager.loading = true
    try {
        const [rows, pricingRows, upstreamRows] = await Promise.all([
            getAigcDigitalHumanChannels(),
            getAigcDigitalHumanPricing(),
            getAigcDigitalHumanUpstreamPricingBatch().catch(() => null)
        ])
        Object.assign(pricing, pricingRows)
        const upstreamGenerate = (upstreamRows?.items || []).find((item: any) => item.local_key === 'generate')
        upstreamPricing.value = upstreamGenerate || {}
        const priceMap = Object.fromEntries((pricingRows.generate_models || []).map((item: any) => [item.code, item]))
        lists.value = rows.map((row: any) => ({
            ...row,
            description: row.description || row.config_json?.description || '',
            platform_unit_cost: priceMap[row.code]?.platform_unit_cost || 0,
            tenant_unit_price: priceMap[row.code]?.tenant_unit_price || 0,
            upstream_price_text: upstreamPriceText(row)
        }))
        setLists(visibleLists.value)
    } finally {
        pager.loading = false
    }
}

const handlePageChange = () => {
    setLists(visibleLists.value)
}

watch(
    () => [showDisabled.value, lists.value.length, lists.value.map((row) => row.status).join('|')].join('|'),
    () => setLists(visibleLists.value)
)

const openEdit = (row: any) => {
    Object.assign(formData, normalizeForm(row))
    editVisible.value = true
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await saveAigcDigitalHumanChannel(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

const saveRowPricing = async (row: any) => {
    const modelMap = Object.fromEntries((pricing.generate_models || []).map((item: any) => [item.code, { ...item }]))
    modelMap[row.code] = {
        ...(modelMap[row.code] || {}),
        code: row.code,
        platform_unit_cost: row.platform_unit_cost,
        tenant_unit_price: row.tenant_unit_price
    }
    await setAigcDigitalHumanPricing({
        ...pricing,
        generate_models: Object.values(modelMap)
    })
    feedback.msgSuccess('保存成功')
    await getLists()
}

const saveClonePricing = async () => {
    pricingSaving.value = true
    try {
        await setAigcDigitalHumanPricing(pricing)
        feedback.msgSuccess('保存成功')
        await getLists()
    } finally {
        pricingSaving.value = false
    }
}

const handleStatus = async (row: any, status: number) => {
    statusLoadingId.value = row.id
    try {
        await setAigcDigitalHumanChannelStatus({ id: row.id, status })
        row.status = status
        feedback.msgSuccess('设置成功')
    } finally {
        statusLoadingId.value = 0
    }
}

const openPricing = async (row: any) => {
    pricingLoadingId.value = row.id
    try {
        const result = await getAigcDigitalHumanUpstreamPricing({ id: row.id })
        Object.keys(pricingRow).forEach((key) => delete pricingRow[key])
        Object.keys(pricingResult).forEach((key) => delete pricingResult[key])
        Object.assign(pricingRow, row)
        const view = buildDigitalHumanPricingView(result || {}, row)
        Object.assign(pricingResult, view)
        row.upstream_price_text = view.unit_price_desc || '-'
        pricingVisible.value = true
    } finally {
        pricingLoadingId.value = 0
    }
}

const openClonePricing = async () => {
    clonePricingLoading.value = true
    try {
        const result = await getAigcDigitalHumanUpstreamClonePricing()
        clonePricingRows.value = [
            {
                label: '形象克隆',
                localPlatform: pricing.avatar_clone?.platform_unit_cost || 0,
                localTenant: pricing.avatar_clone?.tenant_unit_price || 0,
                pricing: result?.avatar_clone || {},
                unitPriceDesc: fixedUnitPriceDesc(result?.avatar_clone || {}),
                billingNote: result?.avatar_clone?.message || result?.avatar_clone?.price_view?.formula || '-'
            },
            {
                label: '音色克隆',
                localPlatform: pricing.voice_clone?.platform_unit_cost || 0,
                localTenant: pricing.voice_clone?.tenant_unit_price || 0,
                pricing: result?.voice_clone || {},
                unitPriceDesc: fixedUnitPriceDesc(result?.voice_clone || {}),
                billingNote: result?.voice_clone?.price_view?.formula || result?.voice_clone?.message || '-'
            }
        ]
        clonePricingVisible.value = true
    } finally {
        clonePricingLoading.value = false
    }
}

getLists()
</script>

<style scoped>
.aigc-table-page {
    min-height: calc(100vh - 118px);
}

.table-card {
    min-height: 100%;
}

:deep(.el-card__body) {
    padding: 16px;
}

.billing-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}

.clone-pricing {
    max-width: 720px;
    padding: 16px;
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
}

.pagination-wrap {
    display: flex;
    justify-content: flex-end;
    padding: 12px 0 0;
}
</style>
