<template>
    <el-card class="!border-none" shadow="never">
        <div class="table-header">
            <div class="text-base font-medium">模型调价</div>
            <el-switch v-model="showDisabled" active-text="显示停用模型" />
        </div>
        <el-table v-loading="loading" :data="visibleLists" size="large">
            <el-table-column label="编码" prop="code" min-width="150" />
            <el-table-column label="名称" prop="name" min-width="150" />
            <el-table-column label="通道" prop="channel_name" min-width="140" />
            <el-table-column label="模型标识" prop="model" min-width="160" />
            <el-table-column label="上下文" prop="context_limit" min-width="100" />
            <el-table-column label="输入成本" prop="platform_input_unit_cost" min-width="110" />
            <el-table-column label="输出成本" prop="platform_output_unit_cost" min-width="110" />
            <el-table-column label="输入价" prop="tenant_input_unit_price" min-width="110" />
            <el-table-column label="输出价" prop="tenant_output_unit_price" min-width="110" />
            <el-table-column label="状态" width="110">
                <template #default="{ row }">
                    <el-switch
                        :model-value="row.status"
                        :active-value="1"
                        :inactive-value="0"
                        @change="(value) => handleStatus(row, value)"
                    />
                </template>
            </el-table-column>
            <el-table-column label="操作" width="100">
                <template #default="{ row }">
                    <el-button type="primary" link @click="openEdit(row)">调价</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="editVisible" title="模型配置" width="520px">
            <el-form label-width="110px" :model="formData">
                <el-form-item label="模型编码"
                    ><el-input v-model="formData.code" disabled
                /></el-form-item>
                <el-form-item label="模型名称"><el-input v-model="formData.name" /></el-form-item>
                <el-form-item label="输入成本"><el-input :model-value="formData.platform_input_unit_cost" disabled /></el-form-item>
                <el-form-item label="输出成本"><el-input :model-value="formData.platform_output_unit_cost" disabled /></el-form-item>
                <el-form-item label="输入价"
                    ><el-input-number
                        v-model="formData.tenant_input_unit_price"
                        :min="0"
                        :precision="4"
                        class="w-full"
                /></el-form-item>
                <el-form-item label="输出价"
                    ><el-input-number
                        v-model="formData.tenant_output_unit_price"
                        :min="0"
                        :precision="4"
                        class="w-full"
                /></el-form-item>
                <el-form-item label="计费单位"
                    ><el-input value="点 / 百万 Token" disabled
                /></el-form-item>
                <el-form-item label="上下文消息数"
                    ><el-input-number
                        v-model="formData.context_limit"
                        :min="1"
                        :max="200"
                        class="w-full"
                /></el-form-item>
                <el-form-item label="排序"
                    ><el-input-number v-model="formData.sort" class="w-full"
                /></el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-llm-model">
import { getAigcLlmModels, saveAigcLlmModel } from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const editVisible = ref(false)
const showDisabled = ref(false)
const lists = ref<any[]>([])
const formData = reactive<any>({})
const visibleLists = computed(() => lists.value.filter((row) => showDisabled.value || Number(row.status) === 1))

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcLlmModels()
    } finally {
        loading.value = false
    }
}

const openEdit = (row: any) => {
    Object.assign(formData, {
        ...row,
        tenant_unit_price: Number(row.tenant_unit_price || 0),
        tenant_input_unit_price: Number(row.tenant_input_unit_price || row.tenant_unit_price || 0),
        tenant_output_unit_price: Number(
            row.tenant_output_unit_price || row.tenant_unit_price || 0
        ),
        context_limit: Number(row.context_limit || 12)
    })
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

const handleStatus = async (row: any, status: number) => {
    await saveAigcLlmModel({
        ...row,
        status
    })
    row.status = status
    feedback.msgSuccess('设置成功')
    await getLists()
}

getLists()
</script>

<style scoped>
.table-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
}
</style>
