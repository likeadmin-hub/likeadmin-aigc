<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="任务ID">
                    <el-input
                        v-model="queryParams.task_id"
                        class="w-[180px]"
                        placeholder="请输入任务ID"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="完成状态">
                    <el-select
                        v-model="queryParams.status"
                        class="!w-[160px]"
                        placeholder="全部"
                        clearable
                        @change="resetPage"
                    >
                        <el-option label="全部" value="all" />
                        <el-option label="生成中" value="running" />
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
                <el-table-column label="人物图片" width="118">
                    <template #default="{ row }">
                        <el-image
                            v-if="row.image_url"
                            class="w-[72px] h-[72px] rounded bg-page object-cover"
                            :src="row.image_url"
                            :preview-src-list="[row.image_url]"
                            preview-teleported
                            fit="cover"
                        />
                        <span v-else class="text-tx-secondary">暂无图片</span>
                    </template>
                </el-table-column>
                <el-table-column label="生成视频" width="156">
                    <template #default="{ row }">
                        <video
                            v-if="row.video_url"
                            class="w-[124px] h-[70px] rounded bg-black object-contain"
                            :src="row.video_url"
                            controls
                            preload="metadata"
                        ></video>
                        <span v-else class="text-tx-secondary">暂无视频</span>
                    </template>
                </el-table-column>
                <el-table-column
                    label="任务ID"
                    prop="provider_task_id"
                    min-width="180"
                    show-overflow-tooltip
                />
                <el-table-column label="用户ID" prop="user_id" width="100" />
                <el-table-column
                    label="提示词"
                    prop="prompt"
                    min-width="260"
                    show-overflow-tooltip
                />
                <el-table-column label="模式" width="100">
                    <template #default="{ row }">{{ modeText(row.mode) }}</template>
                </el-table-column>
                <el-table-column label="时长" width="100">
                    <template #default="{ row }">{{ formatDuration(row.duration) }}</template>
                </el-table-column>
                <el-table-column label="租户成本" prop="tenant_cost_points" width="110" />
                <el-table-column label="用户消费" prop="user_charge_points" width="110" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="statusTagType(row.status)">{{
                            statusText(row.status)
                        }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column
                    label="错误信息"
                    prop="error"
                    min-width="180"
                    show-overflow-tooltip
                />
                <el-table-column label="创建时间" width="170">
                    <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="140" fixed="right">
                    <template #default="{ row }">
                        <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-image-human-task">
import { deleteImageHumanTask, getImageHumanTaskLists } from '@/apps/image_human/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const queryParams = reactive({
    task_id: '',
    status: 'all'
})

const { pager, getLists, resetPage } = usePaging({
    fetchFun: (params: any) =>
        getImageHumanTaskLists({
            ...params,
            status: params.status === 'all' ? '' : params.status
        }),
    params: queryParams
})

const resetQuery = () => {
    Object.assign(queryParams, {
        task_id: '',
        status: 'all'
    })
    resetPage()
}

const statusText = (status: string) => {
    const map: Record<string, string> = {
        pending: '排队中',
        running: '生成中',
        success: '已完成',
        failed: '已失败',
        canceled: '已取消'
    }
    return map[status] || status || '-'
}

const statusTagType = (status: string) => {
    const map: Record<string, '' | 'success' | 'warning' | 'danger' | 'info'> = {
        pending: 'info',
        running: 'warning',
        success: 'success',
        failed: 'danger',
        canceled: 'info'
    }
    return map[status] || 'info'
}

const modeText = (mode: string) => (mode === 'standard' ? '标准模式' : '快速模式')

const formatDuration = (duration: number | string) => {
    const value = Number(duration || 0)
    return value > 0 ? `${value.toFixed(value % 1 === 0 ? 0 : 2)}秒` : '-'
}

const formatTime = (time: number | string) => {
    const value = Number(time || 0)
    return value > 0 ? new Date(value * 1000).toLocaleString() : '-'
}

const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该任务？')
    await deleteImageHumanTask({ id })
    feedback.msgSuccess('已删除')
    getLists()
}

getLists()
</script>
