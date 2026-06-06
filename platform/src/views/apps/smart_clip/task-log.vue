<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="租户ID">
                    <el-input v-model="queryParams.tenant_id" class="w-[140px]" placeholder="全部" clearable @keyup.enter="resetPage" />
                </el-form-item>
                <el-form-item label="任务ID">
                    <el-input v-model="queryParams.task_id" class="w-[160px]" placeholder="请输入任务ID" clearable @keyup.enter="resetPage" />
                </el-form-item>
                <el-form-item label="用户搜索">
                    <el-input v-model="queryParams.user_keyword" class="w-[220px]" placeholder="用户ID/昵称/账号/手机号" clearable @keyup.enter="resetPage" />
                </el-form-item>
                <el-form-item label="状态">
                    <el-select v-model="queryParams.status" class="!w-[140px]" clearable @change="resetPage">
                        <el-option label="全部" value="all" />
                        <el-option label="运行中" value="running" />
                        <el-option label="已完成" value="success" />
                        <el-option label="已失败" value="failed" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="resetPage">查询</el-button>
                    <el-button @click="resetQuery">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="pager.loading" size="large" :data="pager.lists">
                <el-table-column label="ID" prop="id" width="80" />
                <el-table-column label="租户" prop="tenant_id" width="90" />
                <el-table-column label="用户" min-width="180" show-overflow-tooltip>
                    <template #default="{ row }">
                        <div>{{ row.user_nickname || row.user_account || `用户#${row.user_id}` }}</div>
                        <div class="text-xs text-tx-secondary">{{ row.user_mobile || `ID: ${row.user_id}` }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="标题" prop="title" min-width="220" show-overflow-tooltip />
                <el-table-column label="剪辑类型" width="130">
                    <template #default="{ row }">{{ typeText(row.clip_type) }}</template>
                </el-table-column>
                <el-table-column label="通道" prop="channel" width="120" />
                <el-table-column label="计费时长" width="100">
                    <template #default="{ row }">{{ row.duration || 0 }}秒</template>
                </el-table-column>
                <el-table-column label="扣点" width="150">
                    <template #default="{ row }">{{ row.tenant_cost_points || 0 }} / {{ row.user_charge_points || 0 }}</template>
                </el-table-column>
                <el-table-column label="上游任务" prop="provider_task_id" min-width="180" show-overflow-tooltip />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="statusTagType(row.status)">{{ statusText(row.status) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="作品" width="140">
                    <template #default="{ row }">
                        <el-link v-if="row.video_url" type="primary" :href="row.video_url" target="_blank">查看视频</el-link>
                        <span v-else>-</span>
                    </template>
                </el-table-column>
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="platform-smart-clip-task-log">
import { getSmartClipTaskLogs } from '@/apps/smart_clip/api'
import { usePaging } from '@/hooks/usePaging'

const queryParams = reactive({
    tenant_id: '',
    task_id: '',
    user_keyword: '',
    status: 'all',
})
const { pager, getLists, resetPage } = usePaging({
    fetchFun: (params: any) =>
        getSmartClipTaskLogs({
            ...params,
            tenant_id: params.tenant_id || 0,
            status: params.status === 'all' ? '' : params.status,
        }),
    params: queryParams,
})

const resetQuery = () => {
    Object.assign(queryParams, {
        tenant_id: '',
        task_id: '',
        user_keyword: '',
        status: 'all',
    })
    resetPage()
}
const statusText = (status: string) => ({ running: '运行中', success: '已完成', failed: '已失败', pending: '排队中', canceled: '已取消' }[status] || status || '-')
const statusTagType = (status: string) => ({ running: 'warning', success: 'success', failed: 'danger', pending: 'info', canceled: 'info' }[status] || 'info')
const typeText = (type: string) => ({ realman_broadcast: '真人口播', broadcast_mixcut: '素材混剪', news_mixcut: '新闻体' }[type] || type || '-')

getLists()
</script>
