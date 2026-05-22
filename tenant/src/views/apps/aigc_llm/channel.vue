<template>
    <el-card class="!border-none" shadow="never">
        <el-table v-loading="loading" :data="lists" size="large">
            <el-table-column label="编码" prop="code" min-width="140" />
            <el-table-column label="名称" prop="name" min-width="150" />
            <el-table-column label="供应商" prop="provider" min-width="120" />
            <el-table-column label="排序" prop="sort" min-width="90" />
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
                    <el-button type="primary" link @click="openEdit(row)">配置</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="editVisible" title="通道配置" width="560px">
            <el-form label-width="100px" :model="formData">
                <el-form-item label="通道编码"
                    ><el-input v-model="formData.code" disabled
                /></el-form-item>
                <el-form-item label="通道名称"><el-input v-model="formData.name" /></el-form-item>
                <el-form-item label="Base URL"
                    ><el-input
                        v-model="formData.config_json.base_url"
                        placeholder="请输入兼容 OpenAI 的接口域名"
                /></el-form-item>
                <el-form-item label="流式路径"
                    ><el-input
                        v-model="formData.config_json.stream_path"
                        placeholder="/api/v1/chat/completions"
                /></el-form-item>
                <el-form-item label="API Key"
                    ><el-input v-model="formData.config_json.api_key" show-password
                /></el-form-item>
                <el-form-item label="SSL校验">
                    <el-switch
                        v-model="formData.config_json.ssl_verify"
                        :active-value="1"
                        :inactive-value="0"
                    />
                </el-form-item>
                <el-form-item label="超时时间"
                    ><el-input-number
                        v-model="formData.config_json.timeout"
                        :min="10"
                        :max="300"
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

<script lang="ts" setup name="tenant-aigc-llm-channel">
import {
    getAigcLlmChannels,
    saveAigcLlmChannel,
    setAigcLlmChannelStatus
} from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const editVisible = ref(false)
const lists = ref<any[]>([])
const formData = reactive<any>({})

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcLlmChannels()
    } finally {
        loading.value = false
    }
}

const openEdit = (row: any) => {
    Object.assign(formData, {
        code: row.code,
        name: row.name,
        config_json: {
            base_url: row.config_json?.base_url || row.config_json?.endpoint || '',
            stream_path: row.config_json?.stream_path || '/api/v1/chat/completions',
            api_key: row.config_json?.api_key || '',
            timeout: Number(row.config_json?.timeout || 120),
            ssl_verify: Number(row.config_json?.ssl_verify ?? 0)
        },
        status: Number(row.status ?? 1),
        sort: Number(row.sort ?? 0)
    })
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

const handleStatus = async (row: any, status: number) => {
    await setAigcLlmChannelStatus({ id: row.id, status })
    row.status = status
}

getLists()
</script>
