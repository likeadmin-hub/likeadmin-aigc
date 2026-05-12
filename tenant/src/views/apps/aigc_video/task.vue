<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="任务ID">
                    <el-input v-model="queryParams.task_id" class="w-[180px]" placeholder="请输入任务ID" clearable @keyup.enter="getLists" />
                </el-form-item>
                <el-form-item label="用户搜索">
                    <el-input v-model="queryParams.user_keyword" class="w-[240px]" placeholder="用户ID/昵称/账号/手机号" clearable @keyup.enter="getLists" />
                </el-form-item>
                <el-form-item label="完成状态">
                    <el-select v-model="queryParams.status" class="w-[160px]" clearable>
                        <el-option label="全部" value="" />
                        <el-option label="运行中" value="running" />
                        <el-option label="已完成" value="success" />
                        <el-option label="已失败" value="failed" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="getLists">查询</el-button>
                    <el-button @click="resetQuery">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="生成视频" width="150">
                <template #default="{ row }">
                    <video
                        v-if="row.video_url"
                        class="w-[120px] h-[68px] rounded bg-black object-contain"
                        :src="row.video_url"
                        controls
                        preload="metadata"
                    ></video>
                    <span v-else class="text-tx-secondary">暂无视频</span>
                </template>
            </el-table-column>
            <el-table-column label="任务ID" prop="provider_task_id" min-width="180" show-overflow-tooltip />
            <el-table-column label="用户" min-width="180" show-overflow-tooltip>
                <template #default="{ row }">
                    <div>{{ row.user_nickname || row.user_account || `用户#${row.user_id}` }}</div>
                    <div class="text-xs text-tx-secondary">{{ row.user_mobile || `ID: ${row.user_id}` }}</div>
                </template>
            </el-table-column>
            <el-table-column label="用户ID" prop="user_id" width="100" />
            <el-table-column label="提示词" prop="prompt" min-width="240" show-overflow-tooltip />
            <el-table-column label="通道" prop="channel" width="110" />
            <el-table-column label="视频下载地址" min-width="260" show-overflow-tooltip>
                <template #default="{ row }">
                    <el-link v-if="row.video_url" type="primary" :href="row.video_url" target="_blank">
                        {{ row.video_url }}
                    </el-link>
                    <span v-else>-</span>
                </template>
            </el-table-column>
            <el-table-column label="质量" prop="quality" width="90" />
            <el-table-column label="比例" prop="ratio" width="100" />
            <el-table-column label="数量" prop="quantity" width="80" />
            <el-table-column label="租户成本" prop="tenant_cost_points" width="110" />
            <el-table-column label="用户消费" prop="user_charge_points" width="110" />
            <el-table-column label="状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="260" fixed="right">
                <template #default="{ row }">
                    <el-button type="success" link :disabled="!canCreateCase(row)" @click="handleCreateCase(row.id)">
                        设为案例
                    </el-button>
                    <el-button type="primary" link @click="handleRetry(row.id)">重试</el-button>
                    <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                </template>
            </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-video-task">
import {
    createAigcVideoCaseFromTask,
    deleteAigcVideoTask,
    getAigcVideoTaskLists,
    retryAigcVideoTask
} from '@/apps/aigc_video/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const lists = ref<any[]>([])
const queryParams = reactive({
    task_id: '',
    user_keyword: '',
    status: ''
})
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcVideoTaskLists(queryParams)
    } finally {
        loading.value = false
    }
}
const resetQuery = () => {
    Object.assign(queryParams, {
        task_id: '',
        user_keyword: '',
        status: ''
    })
    getLists()
}
const statusText = (status: string) => {
    const map: Record<string, string> = {
        running: '运行中',
        success: '已完成',
        failed: '已失败'
    }
    return map[status] || status || '-'
}
const statusTagType = (status: string) => {
    const map: Record<string, '' | 'success' | 'warning' | 'danger' | 'info'> = {
        running: 'warning',
        success: 'success',
        failed: 'danger'
    }
    return map[status] || 'info'
}
const canCreateCase = (row: any) => row.status === 'success' && Number(row.result_id || 0) > 0
const handleCreateCase = async (id: number) => {
    await feedback.confirm('确定将该任务设为案例？')
    await createAigcVideoCaseFromTask({ task_id: id })
    feedback.msgSuccess('已设为案例')
    getLists()
}
const handleRetry = async (id: number) => {
    await retryAigcVideoTask({ id })
    getLists()
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该任务？')
    await deleteAigcVideoTask({ id })
    getLists()
}
getLists()
</script>
