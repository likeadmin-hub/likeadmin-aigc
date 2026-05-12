<template>
    <el-card class="!border-none" shadow="never">
        <el-table v-loading="loading" size="large" :data="lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="用户ID" prop="user_id" width="100" />
            <el-table-column label="项目ID" prop="project_id" width="100" />
            <el-table-column label="节点ID" prop="node_id" min-width="160" show-overflow-tooltip />
            <el-table-column label="类型" prop="run_type" width="90" />
            <el-table-column label="调用应用" prop="source_app_code" width="120" />
            <el-table-column label="任务ID" prop="source_task_id" width="100" />
            <el-table-column label="提示词" prop="prompt" min-width="220" show-overflow-tooltip />
            <el-table-column label="耗时(ms)" prop="duration_ms" width="110" />
            <el-table-column label="状态" width="100">
                <template #default="{ row }">
                    <el-tag :type="row.status === 'success' ? 'success' : row.status === 'failed' ? 'danger' : 'warning'">{{ row.status }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="错误" prop="error" min-width="180" show-overflow-tooltip />
        </el-table>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-canvas-run">
import { getAigcCanvasRuns } from '@/apps/aigc_canvas/api'

const loading = ref(false)
const lists = ref<any[]>([])
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcCanvasRuns()
    } finally {
        loading.value = false
    }
}
getLists()
</script>
