<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <template #header>
                <el-button type="primary" @click="openEdit()">新增通道</el-button>
            </template>
            <el-table v-loading="pager.loading" size="large" :data="tableLists" height="100%">
                <el-table-column label="编码" prop="code" min-width="140" />
                <el-table-column label="名称" prop="name" min-width="150" />
                <el-table-column label="供应商" prop="provider" min-width="120" />
                <el-table-column label="排序" prop="sort" min-width="90" />
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
            <div class="pagination-wrap">
                <pagination v-model="pager" @change="handlePageChange" />
            </div>
        </el-card>

        <el-dialog v-model="editVisible" title="通道配置" width="560px" destroy-on-close>
            <el-form label-width="100px" :model="formData">
                <el-form-item label="通道编码">
                    <el-input v-model="formData.code" :disabled="!!formData.id" placeholder="dashscope_compatible" />
                </el-form-item>
                <el-form-item label="通道名称">
                    <el-input v-model="formData.name" />
                </el-form-item>
                <el-form-item label="供应商">
                    <el-select v-model="formData.provider" class="w-full">
                        <el-option label="OpenAI兼容" value="openai_compatible" />
                        <el-option label="DeepSeek" value="deepseek" />
                        <el-option label="千问" value="qwen" />
                        <el-option label="豆包" value="doubao" />
                    </el-select>
                </el-form-item>
                <el-form-item label="Base URL">
                    <el-input v-model="formData.config_json.base_url" placeholder="请输入兼容 OpenAI 的接口域名" />
                </el-form-item>
                <el-form-item label="流式路径">
                    <el-input v-model="formData.config_json.stream_path" placeholder="/api/v1/chat/completions" />
                </el-form-item>
                <el-form-item label="API Key">
                    <el-input v-model="formData.config_json.api_key" show-password />
                </el-form-item>
                <el-form-item label="SSL校验">
                    <el-switch v-model="formData.config_json.ssl_verify" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="超时时间">
                    <el-input-number v-model="formData.config_json.timeout" :min="10" :max="300" class="w-full" />
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

<script lang="ts" setup name="platform-aigc-llm-channel">
import { deleteAigcLlmChannel, getAigcLlmChannels, saveAigcLlmChannel, setAigcLlmChannelStatus } from '@/apps/aigc_llm/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const editVisible = ref(false)
const lists = ref<any[]>([])
const { pager, tableLists, setLists } = useLocalPaging({ size: 15 })
const formData = reactive<any>({})

const normalizeForm = (row: any = {}) => ({
    id: row.id || 0,
    code: row.code || '',
    name: row.name || '',
    provider: row.provider || 'openai_compatible',
    config_json: {
        base_url: row.config_json?.base_url || row.config_json?.endpoint || '',
        stream_path: row.config_json?.stream_path || '/api/v1/chat/completions',
        api_key: row.config_json?.api_key || '',
        timeout: Number(row.config_json?.timeout || 120),
        ssl_verify: Number(row.config_json?.ssl_verify ?? 0),
        remark: row.config_json?.remark || ''
    },
    status: Number(row.status ?? 1),
    sort: Number(row.sort ?? 0)
})

const getLists = async () => {
    pager.loading = true
    try {
        lists.value = await getAigcLlmChannels()
        setLists(lists.value)
    } finally {
        pager.loading = false
    }
}

const handlePageChange = () => {
    setLists(lists.value)
}

const openEdit = (row: any = {}) => {
    Object.assign(formData, normalizeForm(row))
    editVisible.value = true
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await saveAigcLlmChannel(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

const handleDelete = async (row: any) => {
    await feedback.confirm('确定删除该通道？关联模型也会删除。')
    await deleteAigcLlmChannel({ id: row.id })
    feedback.msgSuccess('删除成功')
    getLists()
}

const handleStatus = async (row: any, status: number) => {
    await setAigcLlmChannelStatus({ id: row.id, status })
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
</style>
