<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <template #header>
                <div class="table-header">
                    <el-button type="primary" @click="openEdit()">新增模型</el-button>
                    <el-switch v-model="showDisabled" active-text="显示停用模型" />
                </div>
            </template>
            <el-table v-loading="pager.loading" size="large" :data="tableLists" height="100%">
                <el-table-column label="编码" prop="code" min-width="150" />
                <el-table-column label="名称" prop="name" min-width="150" />
                <el-table-column label="通道" prop="channel_name" min-width="140" />
                <el-table-column label="模型标识" prop="model" min-width="160" />
                <el-table-column label="上下文" prop="context_limit" min-width="100" />
                <el-table-column label="平台输入定价" prop="platform_input_unit_cost" min-width="130" />
                <el-table-column label="平台输出定价" prop="platform_output_unit_cost" min-width="130" />
                <el-table-column label="用户输入价" prop="tenant_input_unit_price" min-width="120" />
                <el-table-column label="用户输出价" prop="tenant_output_unit_price" min-width="120" />
                <el-table-column label="状态" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" @change="(value) => handleStatus(row, value)" />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="220" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link :loading="pricingLoadingId === row.id" @click="openPricing(row)">上游价格</el-button>
                        <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
                        <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <div class="pagination-wrap">
                <pagination v-model="pager" @change="handlePageChange" />
            </div>
        </el-card>

        <el-dialog v-model="editVisible" title="模型配置" width="620px" destroy-on-close>
            <el-form label-width="110px" :model="formData">
                <el-form-item label="模型编码"><el-input v-model="formData.code" :disabled="!!formData.id" /></el-form-item>
                <el-form-item label="模型名称"><el-input v-model="formData.name" /></el-form-item>
                <el-form-item label="通道编码"><el-input v-model="formData.channel_code" /></el-form-item>
                <el-form-item label="供应商"><el-input v-model="formData.provider" /></el-form-item>
                <el-form-item label="模型标识"><el-input v-model="formData.model" /></el-form-item>
                <el-form-item label="上下文消息数"><el-input-number v-model="formData.context_limit" :min="1" :max="200" class="w-full" /></el-form-item>
                <el-form-item label="平台输入定价"><el-input-number v-model="formData.platform_input_unit_cost" :min="0" :precision="4" class="w-full" /></el-form-item>
                <el-form-item label="平台输出定价"><el-input-number v-model="formData.platform_output_unit_cost" :min="0" :precision="4" class="w-full" /></el-form-item>
                <el-form-item label="用户输入价"><el-input-number v-model="formData.tenant_input_unit_price" :min="0" :precision="4" class="w-full" /></el-form-item>
                <el-form-item label="用户输出价"><el-input-number v-model="formData.tenant_output_unit_price" :min="0" :precision="4" class="w-full" /></el-form-item>
                <el-form-item label="计费单位"><el-input value="点 / 百万 Token" disabled /></el-form-item>
                <el-form-item label="排序"><el-input-number v-model="formData.sort" class="w-full" /></el-form-item>
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
                <el-descriptions-item label="本地输入定价">{{ pricingRow.platform_input_unit_cost || 0 }}</el-descriptions-item>
                <el-descriptions-item label="本地输出定价">{{ pricingRow.platform_output_unit_cost || 0 }}</el-descriptions-item>
                <el-descriptions-item label="上游状态">{{ pricingResult.available ? '可用' : '不可用' }}</el-descriptions-item>
                <el-descriptions-item label="价格来源">{{ pricingResult.pricing_source?.name || '-' }}</el-descriptions-item>
                <el-descriptions-item label="计费方式">{{ pricingResult.price_view?.billing_type_desc || '-' }}</el-descriptions-item>
                <el-descriptions-item label="上游渠道">{{ pricingResult.resource?.channel_name || pricingResult.resource?.channel_code || '-' }}</el-descriptions-item>
                <el-descriptions-item label="价格公式" :span="2">{{ pricingResult.price_view?.formula || pricingResult.message || '-' }}</el-descriptions-item>
            </el-descriptions>
            <template #footer>
                <el-button type="primary" @click="pricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-llm-model">
import { deleteAigcLlmModel, getAigcLlmModels, getAigcLlmUpstreamModelPricing, saveAigcLlmModel, setAigcLlmModelStatus } from '@/apps/aigc_llm/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const editVisible = ref(false)
const pricingVisible = ref(false)
const showDisabled = ref(false)
const pricingLoadingId = ref(0)
const lists = ref<any[]>([])
const { pager, tableLists, setLists } = useLocalPaging({ size: 15 })
const formData = reactive<any>({})
const pricingRow = reactive<any>({})
const pricingResult = reactive<any>({})

const visibleLists = computed(() => lists.value.filter((row) => showDisabled.value || Number(row.status) === 1))

const normalizeForm = (row: any = {}) => ({
    id: row.id || 0,
    code: row.code || '',
    name: row.name || '',
    channel_code: row.channel_code || 'dashscope_compatible',
    provider: row.provider || 'openai_compatible',
    model: row.model || '',
    context_limit: Number(row.context_limit || 12),
    platform_unit_cost: Number(row.platform_unit_cost || 0),
    tenant_unit_price: Number(row.tenant_unit_price || 0),
    platform_input_unit_cost: Number(row.platform_input_unit_cost || row.platform_unit_cost || 0),
    platform_output_unit_cost: Number(row.platform_output_unit_cost || row.platform_unit_cost || 0),
    tenant_input_unit_price: Number(row.tenant_input_unit_price || row.tenant_unit_price || 0),
    tenant_output_unit_price: Number(row.tenant_output_unit_price || row.tenant_unit_price || 0),
    billing_unit: row.billing_unit || 'tokens_1m',
    config_json: row.config_json || {},
    status: Number(row.status ?? 1),
    sort: Number(row.sort ?? 0)
})

const getLists = async () => {
    pager.loading = true
    try {
        lists.value = await getAigcLlmModels()
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

const openEdit = (row: any = {}) => {
    Object.assign(formData, normalizeForm(row))
    editVisible.value = true
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await saveAigcLlmModel(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

const handleDelete = async (row: any) => {
    await feedback.confirm('确定删除该模型？')
    await deleteAigcLlmModel({ id: row.id })
    feedback.msgSuccess('删除成功')
    getLists()
}

const handleStatus = async (row: any, status: number) => {
    await setAigcLlmModelStatus({ id: row.id, status })
    row.status = status
}

const openPricing = async (row: any) => {
    pricingLoadingId.value = row.id
    try {
        const result = await getAigcLlmUpstreamModelPricing({ id: row.id })
        Object.keys(pricingRow).forEach((key) => delete pricingRow[key])
        Object.keys(pricingResult).forEach((key) => delete pricingResult[key])
        Object.assign(pricingRow, row)
        Object.assign(pricingResult, result || {})
        pricingVisible.value = true
    } finally {
        pricingLoadingId.value = 0
    }
}

getLists()
</script>

<style scoped>
.aigc-table-page {
    height: calc(100vh - 118px);
}
.table-card {
    height: 100%;
}
:deep(.el-card__body) {
    display: flex;
    flex-direction: column;
    height: calc(100% - 57px);
    padding: 0;
}

:deep(.el-table) {
    flex: 1;
}

.pagination-wrap {
    display: flex;
    justify-content: flex-end;
    padding: 12px 16px;
}

.table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
</style>
