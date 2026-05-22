<template>
    <el-card class="!border-none" shadow="never">
        <el-form :inline="true" class="mb-4">
            <el-form-item label="用户ID">
                <el-input
                    v-model="query.user_id"
                    class="w-[160px]"
                    clearable
                    placeholder="全部用户"
                />
            </el-form-item>
            <el-form-item label="关键词">
                <el-input
                    v-model="query.keyword"
                    class="w-[220px]"
                    clearable
                    placeholder="音色名称"
                />
            </el-form-item>
            <el-form-item label="合成状态">
                <el-select
                    v-model="query.status"
                    class="w-[160px]"
                    clearable
                    placeholder="全部状态"
                >
                    <el-option label="可用" value="ready" />
                    <el-option label="待处理" value="pending" />
                    <el-option label="克隆中" value="running" />
                    <el-option label="失败" value="failed" />
                    <el-option label="停用" value="disabled" />
                </el-select>
            </el-form-item>
            <el-form-item>
                <el-button type="primary" @click="resetPage">查询</el-button>
                <el-button @click="resetQuery">重置</el-button>
            </el-form-item>
        </el-form>
        <el-table v-loading="pager.loading" size="large" :data="pager.lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="用户ID" prop="user_id" width="100" />
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column label="音色ID" min-width="220" show-overflow-tooltip>
                <template #default="{ row }">{{ formatVoiceId(row) }}</template>
            </el-table-column>
            <el-table-column label="音频样本" min-width="220" show-overflow-tooltip>
                <template #default="{ row }">{{ row.audio_url || row.audio_uri || '-' }}</template>
            </el-table-column>
            <el-table-column label="合成状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="statusType(row.status)">{{ statusText(row.status) }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="创建时间" width="170">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="190" fixed="right">
                <template #default="{ row }">
                    <el-button
                        type="primary"
                        link
                        :disabled="!row.provider_asset_id"
                        @click="handlePublish(row.id)"
                        >设为公共</el-button
                    >
                    <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>
        <div class="flex justify-end mt-4">
            <pagination v-model="pager" @change="getLists" />
        </div>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-user-voice">
import {
    deleteAigcDigitalHumanUserVoice,
    getAigcDigitalHumanUserVoices,
    publishAigcDigitalHumanUserVoice
} from '@/apps/aigc_digital_human/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'
import { timeFormat } from '@/utils/util'

const query = reactive({ user_id: '', keyword: '', status: '' })
const { pager, getLists, resetPage } = usePaging({
    fetchFun: getAigcDigitalHumanUserVoices,
    params: query
})
const resetQuery = () => {
    Object.assign(query, { user_id: '', keyword: '', status: '' })
    resetPage()
}
const handlePublish = async (id: number) => {
    await feedback.confirm('确定将该用户音色复制为公共音色？')
    await publishAigcDigitalHumanUserVoice({ id })
    feedback.msgSuccess('已设为公共音色')
    getLists()
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该用户音色？')
    await deleteAigcDigitalHumanUserVoice({ id })
    getLists()
}
const statusMap: Record<string, string> = {
    ready: '可用',
    pending: '待处理',
    running: '克隆中',
    failed: '失败',
    disabled: '停用'
}
const statusText = (status: string) => statusMap[status] || status || '-'
const statusType = (status: string) => {
    if (status === 'ready') return 'success'
    if (status === 'failed') return 'danger'
    if (status === 'disabled') return 'info'
    return 'warning'
}
const formatVoiceId = (row: any) => {
    const id = row.provider_asset_id || row.voice_id || row.reference_id
    return id && !String(id).startsWith('debug-') ? id : '未获取'
}
const formatTime = (time: number | string) => {
    if (!time) return '-'
    if (typeof time === 'string')
        return /^\d+$/.test(time) ? timeFormat(Number(time), 'yyyy-mm-dd hh:MM:ss') : time
    return timeFormat(time, 'yyyy-mm-dd hh:MM:ss')
}
getLists()
</script>
