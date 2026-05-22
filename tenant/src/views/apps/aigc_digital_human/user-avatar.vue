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
                    placeholder="形象名称"
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
            <el-table-column label="封面" width="110">
                <template #default="{ row }">
                    <video
                        v-if="isVideo(row.cover_url || row.media_url)"
                        class="w-[64px] h-[64px] rounded object-cover bg-[#f5f7fa]"
                        :src="row.cover_url || row.media_url"
                        muted
                        preload="metadata"
                    />
                    <el-image
                        v-else
                        class="w-[64px] h-[64px] rounded"
                        :src="row.cover_url || row.media_url"
                        fit="cover"
                    />
                </template>
            </el-table-column>
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column label="视频素材" min-width="240" show-overflow-tooltip>
                <template #default="{ row }">{{ row.media_url || row.media_uri || '-' }}</template>
            </el-table-column>
            <el-table-column label="合成状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="statusType(row.status)">{{ statusText(row.status) }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="创建时间" width="170">
                <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="90" fixed="right">
                <template #default="{ row }">
                    <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>
        <div class="flex justify-end mt-4">
            <pagination v-model="pager" @change="getLists" />
        </div>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-user-avatar">
import {
    deleteAigcDigitalHumanUserAvatar,
    getAigcDigitalHumanUserAvatars
} from '@/apps/aigc_digital_human/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'
import { timeFormat } from '@/utils/util'

const query = reactive({ user_id: '', keyword: '', status: '' })
const { pager, getLists, resetPage } = usePaging({
    fetchFun: getAigcDigitalHumanUserAvatars,
    params: query
})
const resetQuery = () => {
    Object.assign(query, { user_id: '', keyword: '', status: '' })
    resetPage()
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该用户形象？')
    await deleteAigcDigitalHumanUserAvatar({ id })
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
const isVideo = (url: string) => /\.(mp4|mov|webm|m4v)(\?|#|$)/i.test(url || '')
const formatTime = (time: number | string) => {
    if (!time) return '-'
    if (typeof time === 'string')
        return /^\d+$/.test(time) ? timeFormat(Number(time), 'yyyy-mm-dd hh:MM:ss') : time
    return timeFormat(time, 'yyyy-mm-dd hh:MM:ss')
}
getLists()
</script>
