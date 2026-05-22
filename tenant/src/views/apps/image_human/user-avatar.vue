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
            <el-form-item label="状态">
                <el-select
                    v-model="query.status"
                    class="w-[160px]"
                    clearable
                    placeholder="全部状态"
                >
                    <el-option label="可用" value="ready" />
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
            <el-table-column label="图片形象" width="110">
                <template #default="{ row }">
                    <el-image
                        class="w-[64px] h-[64px] rounded"
                        :src="row.image_url || row.cover_url || row.media_url"
                        :preview-src-list="[row.image_url || row.cover_url || row.media_url]"
                        preview-teleported
                        fit="cover"
                    />
                </template>
            </el-table-column>
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column label="图片素材" min-width="240" show-overflow-tooltip>
                <template #default="{ row }">{{ row.image_url || row.image_uri || '-' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100">
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

<script lang="ts" setup name="tenant-image-human-user-avatar">
import { deleteImageHumanUserAvatar, getImageHumanUserAvatars } from '@/apps/image_human/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'
import { timeFormat } from '@/utils/util'

const query = reactive({ user_id: '', keyword: '', status: '' })
const { pager, getLists, resetPage } = usePaging({
    fetchFun: getImageHumanUserAvatars,
    params: query
})
const resetQuery = () => {
    Object.assign(query, { user_id: '', keyword: '', status: '' })
    resetPage()
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该用户形象？')
    await deleteImageHumanUserAvatar({ id })
    getLists()
}
const statusMap: Record<string, string> = {
    ready: '可用',
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
const formatTime = (time: number | string) => {
    if (!time) return '-'
    if (typeof time === 'string')
        return /^\d+$/.test(time) ? timeFormat(Number(time), 'yyyy-mm-dd hh:MM:ss') : time
    return timeFormat(time, 'yyyy-mm-dd hh:MM:ss')
}
getLists()
</script>
