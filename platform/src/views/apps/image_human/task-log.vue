<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form :inline="true" :model="query" class="mb-[-16px]">
                <el-form-item label="租户ID">
                    <el-input v-model="query.tenant_id" class="w-[140px]" clearable />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input v-model="query.user_id" class="w-[140px]" clearable />
                </el-form-item>
                <el-form-item label="本地任务ID">
                    <el-input v-model="query.task_id" class="w-[150px]" clearable />
                </el-form-item>
                <el-form-item label="上游任务ID">
                    <el-input v-model="query.provider_task_id" class="w-[220px]" clearable />
                </el-form-item>
                <el-form-item label="状态">
                    <el-select v-model="query.status" class="w-[130px]" clearable>
                        <el-option label="运行中" value="running" />
                        <el-option label="成功" value="success" />
                        <el-option label="失败" value="failed" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSearch">查询</el-button>
                    <el-button @click="resetQuery">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="pager.loading" size="large" :data="tableLists">
                <el-table-column label="本地任务ID" prop="id" width="110" />
                <el-table-column label="租户ID" prop="tenant_id" width="100" />
                <el-table-column label="用户ID" prop="user_id" width="100" />
                <el-table-column label="上游任务ID" prop="provider_task_id" min-width="190" show-overflow-tooltip />
                <el-table-column label="模型" min-width="150">
                    <template #default="{ row }">{{ row.provider }} / {{ row.model }}</template>
                </el-table-column>
                <el-table-column label="模式" width="100">
                    <template #default="{ row }">{{ modeText(row.mode) }}</template>
                </el-table-column>
                <el-table-column label="时长" width="90">
                    <template #default="{ row }">{{ row.duration || 0 }}s</template>
                </el-table-column>
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.status === 'success' ? 'success' : row.status === 'failed' ? 'danger' : 'warning'">{{ row.status }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="错误" prop="error" min-width="220" show-overflow-tooltip />
                <el-table-column label="创建时间" width="170">
                    <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                </el-table-column>
                <el-table-column label="完成时间" width="170">
                    <template #default="{ row }">{{ formatTime(row.finish_time) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="90" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openDetail(row.id)">详情</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <pagination v-model="pager" @change="getLists" />
        </el-card>

        <el-dialog v-model="detailVisible" title="任务日志详情" width="920px" destroy-on-close>
            <el-descriptions :column="2" border>
                <el-descriptions-item label="本地任务ID">{{ detail.id }}</el-descriptions-item>
                <el-descriptions-item label="租户 / 用户">{{ detail.tenant_id }} / {{ detail.user_id }}</el-descriptions-item>
                <el-descriptions-item label="上游任务ID">{{ detail.provider_task_id || '-' }}</el-descriptions-item>
                <el-descriptions-item label="供应商 / 模型">{{ detail.provider }} / {{ detail.model }}</el-descriptions-item>
                <el-descriptions-item label="模式 / 时长">{{ modeText(detail.mode) }} / {{ detail.duration || 0 }}s</el-descriptions-item>
                <el-descriptions-item label="状态 / 进度">{{ detail.status }} / {{ detail.progress || 0 }}%</el-descriptions-item>
                <el-descriptions-item label="提示词" :span="2">{{ detail.prompt || '-' }}</el-descriptions-item>
                <el-descriptions-item label="错误" :span="2">{{ detail.error || '-' }}</el-descriptions-item>
                <el-descriptions-item label="人物图片" :span="2">{{ detail.image_url || detail.image_uri || '-' }}</el-descriptions-item>
                <el-descriptions-item label="参考音频" :span="2">{{ detail.audio_url || detail.audio_uri || '-' }}</el-descriptions-item>
                <el-descriptions-item label="生成视频" :span="2">{{ detail.video_url || detail.video_uri || '-' }}</el-descriptions-item>
            </el-descriptions>
            <div class="mt-4 text-base font-medium">上游载荷</div>
            <el-tabs class="mt-2">
                <el-tab-pane v-for="item in detail.provider_payload_summary || []" :key="item.stage" :label="payloadStageText(item.stage)">
                    <pre class="payload">{{ JSON.stringify(item.payload, null, 2) }}</pre>
                </el-tab-pane>
            </el-tabs>
            <el-empty v-if="!(detail.provider_payload_summary || []).length" description="暂无上游载荷" />
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-image-human-task-log">
import { getImageHumanTaskLogDetail, getImageHumanTaskLogs } from '@/apps/image_human/api'
import { useLocalPaging } from '@/hooks/useLocalPaging'

const detailVisible = ref(false)
const lists = ref<any[]>([])
const detail = ref<any>({})
const { pager, tableLists, setLists, getPagingParams, resetPage } = useLocalPaging({ size: 15 })
const query = reactive({
    tenant_id: '',
    user_id: '',
    task_id: '',
    provider_task_id: '',
    status: ''
})

const getLists = async () => {
    pager.loading = true
    try {
        const data = await getImageHumanTaskLogs({
            ...query,
            ...getPagingParams()
        })
        lists.value = Array.isArray(data) ? data : data?.lists || []
        setLists(data)
    } finally {
        pager.loading = false
    }
}

const handleSearch = () => {
    resetPage()
    getLists()
}

const resetQuery = () => {
    Object.assign(query, { tenant_id: '', user_id: '', task_id: '', provider_task_id: '', status: '' })
    handleSearch()
}

const openDetail = async (id: number) => {
    detail.value = await getImageHumanTaskLogDetail({ id })
    detailVisible.value = true
}

const formatTime = (time: number | string) => {
    if (!time) return '-'
    if (typeof time === 'string' && /[-/]/.test(time)) return time
    const timestamp = Number(time)
    if (!Number.isFinite(timestamp) || timestamp <= 0) return '-'
    return new Date(timestamp * 1000).toLocaleString()
}

const modeText = (mode: string) => {
    const map: Record<string, string> = {
        fast: '快速',
        standard: '标准'
    }
    return map[mode] || mode || '-'
}

const payloadStageText = (stage: string) => {
    const map: Record<string, string> = {
        tts_submit: '音频提交',
        tts_result: '音频查询',
        submit: '提交',
        query: '查询'
    }
    return map[stage] || stage
}

getLists()
</script>

<style scoped>
.payload {
    max-height: 420px;
    overflow: auto;
    padding: 12px;
    border-radius: 6px;
    background: var(--el-fill-color-light);
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-all;
}
</style>
