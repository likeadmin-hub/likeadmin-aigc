<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <el-table v-loading="pager.loading" size="large" :data="tableLists" height="100%">
                <el-table-column label="编码" prop="code" min-width="140" />
                <el-table-column label="名称" prop="name" min-width="150" />
                <el-table-column label="供应商" prop="provider" min-width="120" />
                <el-table-column label="模型" prop="model" min-width="160" />
                <el-table-column label="参考图上限" prop="max_reference_images" min-width="110" />
                <el-table-column label="轮询" min-width="140">
                    <template #default="{ row }">
                        {{ row.config_json?.poll_interval || 2 }}s / {{ row.config_json?.poll_attempts || 30 }}次
                    </template>
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
                        <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
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
                    <el-input v-model="formData.code" disabled />
                </el-form-item>
                <el-form-item label="通道名称">
                    <el-input v-model="formData.name" />
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
                <el-form-item label="参考图上限">
                    <el-input-number v-model="formData.max_reference_images" :min="0" :max="16" class="w-full" />
                </el-form-item>
                <el-form-item label="轮询间隔">
                    <el-input-number v-model="formData.config_json.poll_interval" :min="1" :max="10" class="w-full" />
                </el-form-item>
                <el-form-item label="轮询次数">
                    <el-input-number v-model="formData.config_json.poll_attempts" :min="1" :max="120" class="w-full" />
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

<script lang="ts" setup name="platform-aigc-video-channel">
import { getAigcVideoChannels, saveAigcVideoChannel, setAigcVideoChannelStatus } from '@/apps/aigc_video/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const editVisible = ref(false)
const statusLoadingId = ref(0)
const lists = ref<any[]>([])
const { pager, tableLists, setLists } = useLocalPaging({ size: 15 })
const formData = reactive({
    code: '',
    name: '',
    provider: 'mock',
    model: 'mock-video',
    max_reference_images: 4,
    config_json: {
        poll_interval: 2,
        poll_attempts: 30
    },
    status: 1,
    sort: 0
})

const normalizeForm = (row: any = {}) => ({
    code: row.code || '',
    name: row.name || '',
    provider: row.provider || 'mock',
    model: row.model || 'mock-video',
    max_reference_images: Number(row.max_reference_images ?? 4),
    config_json: {
        poll_interval: Number(row.config_json?.poll_interval || 2),
        poll_attempts: Number(row.config_json?.poll_attempts || 30)
    },
    status: Number(row.status ?? 1),
    sort: Number(row.sort ?? 0)
})

const getLists = async () => {
    pager.loading = true
    try {
        lists.value = await getAigcVideoChannels()
        setLists(lists.value)
    } finally {
        pager.loading = false
    }
}

const handlePageChange = () => {
    setLists(lists.value)
}

const openEdit = (row: any) => {
    Object.assign(formData, normalizeForm(row))
    editVisible.value = true
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await saveAigcVideoChannel(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

const handleStatus = async (row: any, status: number) => {
    statusLoadingId.value = row.id
    try {
        await setAigcVideoChannelStatus({ id: row.id, status })
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
    height: calc(100vh - 118px);
}

.table-card {
    height: 100%;
}

:deep(.el-card__body) {
    display: flex;
    flex-direction: column;
    height: 100%;
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
