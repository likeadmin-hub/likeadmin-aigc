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
                <el-table-column label="模型" min-width="160">
                    <template #default="{ row }">{{ row.channel }} / {{ row.model }}</template>
                </el-table-column>
                <el-table-column label="TTS任务ID" prop="tts_task_id" min-width="190" show-overflow-tooltip />
                <el-table-column label="视频任务ID" prop="provider_task_id" min-width="190" show-overflow-tooltip />
                <el-table-column label="阶段" width="130">
                    <template #default="{ row }">{{ stageText(row.provider_stage) }}</template>
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
                <el-descriptions-item label="TTS任务ID">{{ detail.tts_task_id || '-' }}</el-descriptions-item>
                <el-descriptions-item label="视频任务ID">{{ detail.provider_task_id || '-' }}</el-descriptions-item>
                <el-descriptions-item label="供应商 / 模型">{{ detail.provider }} / {{ detail.model }}</el-descriptions-item>
                <el-descriptions-item label="阶段 / 状态">{{ stageText(detail.provider_stage) }} / {{ detail.status }}</el-descriptions-item>
                <el-descriptions-item label="错误" :span="2">{{ detail.error || '-' }}</el-descriptions-item>
                <el-descriptions-item label="文案" :span="2">{{ detail.script_text || '-' }}</el-descriptions-item>
            </el-descriptions>
            <div class="mt-4 text-base font-medium">上游载荷</div>
            <el-tabs class="mt-2">
                <el-tab-pane
                    v-for="item in detail.provider_payload_summary || []"
                    :key="item.stage"
                    :label="payloadStageText(item.stage)"
                >
                    <pre class="payload">{{ JSON.stringify(item.payload, null, 2) }}</pre>
                </el-tab-pane>
            </el-tabs>
            <el-empty v-if="!(detail.provider_payload_summary || []).length" description="暂无上游载荷" />
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-digital-human-task-log">
import { getAigcDigitalHumanTaskLogDetail, getAigcDigitalHumanTaskLogs } from '@/apps/aigc_digital_human/api'
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
        const data = await getAigcDigitalHumanTaskLogs({
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
    detail.value = await getAigcDigitalHumanTaskLogDetail({ id })
    detailVisible.value = true
}

const formatTime = (time: number) => {
    if (!time) return '-'
    return new Date(time * 1000).toLocaleString()
}

const stageText = (stage: string) => {
    const map: Record<string, string> = {
        created: '待提交音频',
        tts_submitted: '音频已提交',
        tts_running: '音频合成中',
        tts_failed: '音频合成失败',
        lipsync_submitted: '视频已提交',
        lipsync_running: '视频合成中',
        lipsync_failed: '视频合成失败',
        storing: '保存作品中',
        success: '已完成',
        failed: '已失败'
    }
    return map[stage] || stage || '-'
}

const payloadStageText = (stage: string) => {
    const map: Record<string, string> = {
        tts_submit: 'TTS提交',
        tts_query: 'TTS查询',
        lipsync_submit: '视频提交',
        lipsync_query: '视频查询'
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
