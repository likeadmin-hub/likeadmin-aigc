<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="关键词">
                    <el-input
                        v-model="queryParams.keyword"
                        class="w-[260px]"
                        placeholder="任务ID/用户/提示词"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="用户ID">
                    <el-input v-model="queryParams.user_id" class="w-[120px]" clearable />
                </el-form-item>
                <el-form-item label="状态">
                    <el-select
                        v-model="queryParams.status"
                        class="w-[150px]"
                        placeholder="全部状态"
                        clearable
                    >
                        <el-option label="运行中" value="running" />
                        <el-option label="成功" value="success" />
                        <el-option label="失败" value="failed" />
                        <el-option label="等待中" value="pending" />
                    </el-select>
                </el-form-item>
                <el-form-item label="创建时间">
                    <daterange-picker
                        v-model:startTime="queryParams.create_time_start"
                        v-model:endTime="queryParams.create_time_end"
                    />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="resetPage">查询</el-button>
                    <el-button @click="resetParams">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table size="large" v-loading="pager.loading" :data="pager.lists">
                <el-table-column label="任务编号" prop="task_sn" min-width="140" />
                <el-table-column label="应用" prop="app_name" width="110" />
                <el-table-column label="用户" min-width="150">
                    <template #default="{ row }">
                        <div>{{ row.initiator_name || '--' }}</div>
                        <div class="text-xs text-info">ID: {{ row.user_id }}</div>
                    </template>
                </el-table-column>
                <el-table-column
                    label="提示词"
                    prop="prompt"
                    min-width="260"
                    show-overflow-tooltip
                />
                <el-table-column label="比例" prop="ratio" width="90" />
                <el-table-column label="数量" prop="quantity" width="80" />
                <el-table-column label="点数" prop="point_actual" width="90" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="statusType(row.status)">
                            {{ statusText(row.status) }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="创建时间" prop="create_time_text" min-width="170" />
                <el-table-column label="操作" width="90" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openDetail(row)">详情</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>
        <el-drawer v-model="detailVisible" title="任务详情" size="560px">
            <el-descriptions :column="1" border>
                <el-descriptions-item label="任务编号">{{ detail.task_sn }}</el-descriptions-item>
                <el-descriptions-item label="应用">{{ detail.app_name }}</el-descriptions-item>
                <el-descriptions-item label="用户">{{
                    detail.user_nickname || detail.user_account || detail.user_id
                }}</el-descriptions-item>
                <el-descriptions-item label="状态">{{
                    statusText(detail.status)
                }}</el-descriptions-item>
                <el-descriptions-item label="预计点数">{{
                    detail.point_estimated
                }}</el-descriptions-item>
                <el-descriptions-item label="实际扣点">{{
                    detail.point_actual
                }}</el-descriptions-item>
                <el-descriptions-item label="创建时间">{{
                    detail.create_time_text
                }}</el-descriptions-item>
                <el-descriptions-item label="完成时间">{{
                    detail.finish_time_text || '--'
                }}</el-descriptions-item>
                <el-descriptions-item label="提示词">{{ detail.prompt }}</el-descriptions-item>
                <el-descriptions-item label="错误信息">{{
                    detail.error || '--'
                }}</el-descriptions-item>
            </el-descriptions>
        </el-drawer>
    </div>
</template>

<script lang="ts" setup name="tenantUserTaskRecord">
import { getAiTaskDetail, getAiTaskLists } from '@/api/consumer'
import { usePaging } from '@/hooks/usePaging'

const queryParams = reactive({
    keyword: '',
    user_id: '',
    status: '',
    create_time_start: '',
    create_time_end: ''
})
const detailVisible = ref(false)
const detail = ref<any>({})

const { pager, getLists, resetPage, resetParams } = usePaging({
    fetchFun: getAiTaskLists,
    params: queryParams
})

const statusType = (status: string) => {
    if (status === 'success') return 'success'
    if (status === 'failed') return 'danger'
    return 'warning'
}

const statusText = (status: string) => {
    const map: Record<string, string> = {
        pending: '等待中',
        running: '运行中',
        success: '成功',
        failed: '失败'
    }
    return map[status] || status || '--'
}

const openDetail = async (row: any) => {
    detail.value = await getAiTaskDetail({ id: row.id })
    detailVisible.value = true
}

onActivated(() => {
    getLists()
})
getLists()
</script>
