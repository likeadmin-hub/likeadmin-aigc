<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <template #header>
                <el-button type="primary" @click="openEdit()">新增模型</el-button>
            </template>
            <el-table v-loading="loading" size="large" :data="lists" height="100%">
                <el-table-column label="编码" prop="code" min-width="150" />
                <el-table-column label="名称" prop="name" min-width="150" />
                <el-table-column label="通道" prop="channel_name" min-width="140" />
                <el-table-column label="模型标识" prop="model" min-width="160" />
                <el-table-column label="上下文" prop="context_limit" min-width="100" />
                <el-table-column label="平台输入成本" prop="platform_input_unit_cost" min-width="130" />
                <el-table-column label="平台输出成本" prop="platform_output_unit_cost" min-width="130" />
                <el-table-column label="用户输入价" prop="tenant_input_unit_price" min-width="120" />
                <el-table-column label="用户输出价" prop="tenant_output_unit_price" min-width="120" />
                <el-table-column label="状态" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-switch :model-value="row.status" :active-value="1" :inactive-value="0" @change="(value) => handleStatus(row, value)" />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
                        <el-button type="danger" link @click="handleDelete(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="editVisible" title="模型配置" width="620px" destroy-on-close>
            <el-form label-width="110px" :model="formData">
                <el-form-item label="模型编码"><el-input v-model="formData.code" :disabled="!!formData.id" /></el-form-item>
                <el-form-item label="模型名称"><el-input v-model="formData.name" /></el-form-item>
                <el-form-item label="通道编码"><el-input v-model="formData.channel_code" /></el-form-item>
                <el-form-item label="供应商"><el-input v-model="formData.provider" /></el-form-item>
                <el-form-item label="模型标识"><el-input v-model="formData.model" /></el-form-item>
                <el-form-item label="上下文消息数"><el-input-number v-model="formData.context_limit" :min="1" :max="200" class="w-full" /></el-form-item>
                <el-form-item label="平台输入成本"><el-input-number v-model="formData.platform_input_unit_cost" :min="0" :precision="4" class="w-full" /></el-form-item>
                <el-form-item label="平台输出成本"><el-input-number v-model="formData.platform_output_unit_cost" :min="0" :precision="4" class="w-full" /></el-form-item>
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
    </div>
</template>

<script lang="ts" setup name="platform-aigc-llm-model">
import { deleteAigcLlmModel, getAigcLlmModels, saveAigcLlmModel, setAigcLlmModelStatus } from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const editVisible = ref(false)
const lists = ref<any[]>([])
const formData = reactive<any>({})

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
    loading.value = true
    try {
        lists.value = await getAigcLlmModels()
    } finally {
        loading.value = false
    }
}

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
    height: calc(100% - 57px);
    padding: 0;
}
</style>
