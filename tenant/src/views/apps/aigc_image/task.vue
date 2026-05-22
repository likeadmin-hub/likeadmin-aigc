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
                <el-form-item label="用户搜索">
                    <el-input
                        v-model="queryParams.user_keyword"
                        class="w-[240px]"
                        placeholder="用户ID/昵称/账号/手机号"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="完成状态">
                    <el-select v-model="queryParams.status" class="w-[160px]" clearable @change="resetPage">
                        <el-option label="全部" value="" />
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
                <el-table-column
                    label="任务ID"
                    prop="provider_task_id"
                    min-width="180"
                    show-overflow-tooltip
                />
                <el-table-column label="结果图" width="180">
                    <template #default="{ row }">
                        <div v-if="row.results?.length" class="flex flex-wrap gap-2">
                            <el-image
                                v-for="item in uniqueResults(row.results).slice(0, 3)"
                                :key="item.id"
                                class="w-[52px] h-[52px] rounded"
                                :src="item.image_url"
                                :preview-src-list="
                                    uniqueResults(row.results)
                                        .map((result: any) => result.image_url)
                                        .filter(Boolean)
                                "
                                preview-teleported
                                fit="cover"
                            />
                        </div>
                        <span v-else class="text-tx-secondary">暂无图片</span>
                    </template>
                </el-table-column>
                <el-table-column label="用户" min-width="180" show-overflow-tooltip>
                    <template #default="{ row }">
                        <div>
                            {{ row.user_nickname || row.user_account || `用户#${row.user_id}` }}
                        </div>
                        <div class="text-xs text-tx-secondary">
                            {{ row.user_mobile || `ID: ${row.user_id}` }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column
                    label="提示词"
                    prop="prompt"
                    min-width="240"
                    show-overflow-tooltip
                />
                <el-table-column label="通道" prop="channel" width="110" />
                <el-table-column label="质量" prop="quality" width="90" />
                <el-table-column label="比例" prop="ratio" width="100" />
                <el-table-column label="数量" prop="quantity" width="80" />
                <el-table-column label="租户成本" prop="tenant_cost_points" width="110" />
                <el-table-column label="用户消费" prop="user_charge_points" width="110" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="statusTagType(row.status)">{{
                            statusText(row.status)
                        }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="260" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            type="success"
                            link
                            :disabled="!canCreateCase(row)"
                            @click="handleCreateCase(row.id)"
                        >
                            设为案例
                        </el-button>
                        <el-button type="primary" link @click="handleRetry(row.id)">重试</el-button>
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

<script lang="ts" setup name="tenant-aigc-image-task">
import {
    createAigcImageCaseFromTask,
    deleteAigcImageTask,
    getAigcImageTaskLists,
    retryAigcImageTask
} from '@/apps/aigc_image/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const queryParams = reactive({
    task_id: '',
    user_keyword: '',
    status: ''
})

const { pager, getLists, resetPage } = usePaging({
    fetchFun: getAigcImageTaskLists,
    params: queryParams
})

const resetQuery = () => {
    Object.assign(queryParams, {
        task_id: '',
        user_keyword: '',
        status: ''
    })
    resetPage()
}

const uniqueResults = (results: any[] = []) => {
    const seen = new Set<string>()
    return results.filter((item) => {
        const key = String(item?.image_uri || item?.image_url || item?.id || '')
        if (!key || seen.has(key)) return false
        seen.add(key)
        return true
    })
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
    await createAigcImageCaseFromTask({ task_id: id })
    feedback.msgSuccess('已设为案例')
    getLists()
}
const handleRetry = async (id: number) => {
    await retryAigcImageTask({ id })
    getLists()
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该任务？')
    await deleteAigcImageTask({ id })
    getLists()
}
getLists()
</script>
