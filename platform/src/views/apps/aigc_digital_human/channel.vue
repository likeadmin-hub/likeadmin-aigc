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
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
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
    </div>
</template>

<script lang="ts" setup name="platform-aigc-digital-human-channel">
import { getAigcDigitalHumanChannels, saveAigcDigitalHumanChannel, setAigcDigitalHumanChannelStatus } from '@/apps/aigc_digital_human/api'
import { getAigcDigitalHumanPricing, setAigcDigitalHumanPricing } from '@/apps/aigc_digital_human/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const pricingSaving = ref(false)
const editVisible = ref(false)
const showDisabled = ref(false)
const statusLoadingId = ref(0)
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
        const [rows, pricingRows] = await Promise.all([getAigcDigitalHumanChannels(), getAigcDigitalHumanPricing()])
        Object.assign(pricing, pricingRows)
        const priceMap = Object.fromEntries((pricingRows.generate_models || []).map((item: any) => [item.code, item]))
        lists.value = rows.map((row: any) => ({
            ...row,
            description: row.description || row.config_json?.description || '',
            platform_unit_cost: priceMap[row.code]?.platform_unit_cost || 0,
            tenant_unit_price: priceMap[row.code]?.tenant_unit_price || 0
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
