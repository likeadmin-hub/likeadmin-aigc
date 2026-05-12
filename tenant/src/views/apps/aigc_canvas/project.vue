<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex justify-between">
                <el-form :inline="true" class="mb-[-16px]">
                    <el-form-item label="关键词">
                        <el-input v-model="query.keyword" class="w-[220px]" placeholder="项目名称" clearable />
                    </el-form-item>
                    <el-form-item>
                        <el-button type="primary" @click="getLists">查询</el-button>
                    </el-form-item>
                </el-form>
                <el-button type="danger" @click="handleClear">清理全部项目</el-button>
            </div>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
                <el-table-column label="ID" prop="id" width="80" />
                <el-table-column label="用户ID" prop="user_id" width="100" />
                <el-table-column label="缩略图" width="110">
                    <template #default="{ row }">
                        <el-image v-if="row.thumbnail" :src="row.thumbnail" fit="cover" class="w-[64px] h-[42px] rounded" />
                        <span v-else class="text-tx-secondary">无</span>
                    </template>
                </el-table-column>
                <el-table-column label="项目名称" prop="name" min-width="180" show-overflow-tooltip />
                <el-table-column label="节点数" width="90">
                    <template #default="{ row }">{{ row.node_count || 0 }}</template>
                </el-table-column>
                <el-table-column label="更新时间" width="170">
                    <template #default="{ row }">{{ formatTime(row.update_time) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="120" fixed="right">
                    <template #default="{ row }">
                        <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-canvas-project">
import { clearAigcCanvasProjects, deleteAigcCanvasProject, getAigcCanvasProjects } from '@/apps/aigc_canvas/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const query = reactive({ keyword: '' })
const lists = ref<any[]>([])
const formatTime = (value: number) => (value ? new Date(value * 1000).toLocaleString() : '-')
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcCanvasProjects(query)
    } finally {
        loading.value = false
    }
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该项目？')
    await deleteAigcCanvasProject({ id })
    getLists()
}
const handleClear = async () => {
    await feedback.confirm('确定清理当前租户全部无限画布项目？')
    await clearAigcCanvasProjects()
    getLists()
}
getLists()
</script>
