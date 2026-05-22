<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
        <el-alert
            class="mb-4"
            type="info"
            show-icon
            :closable="false"
            title="无限画布直接复用 AIGC生图 和 AIGC视频 的通道、模型、计费和任务配置，本应用不单独配置 API。"
        />
        <el-table :data="dependencies.items || []" size="large">
            <el-table-column label="依赖应用" prop="name" min-width="160" />
            <el-table-column label="用于" prop="required_for" min-width="160" />
            <el-table-column label="安装启用" width="120">
                <template #default="{ row }">
                    <el-tag :type="row.installed ? 'success' : 'danger'">{{
                        row.installed ? '已安装' : '未安装'
                    }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="租户状态" width="140">
                <template #default="{ row }">
                    <el-tag :type="row.tenant_enabled ? 'success' : 'danger'">{{
                        row.tenant_enabled ? '已开通上架' : '不可用'
                    }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="通道" width="120">
                <template #default="{ row }">
                    <el-tag :type="row.channel_ready ? 'success' : 'warning'">{{
                        row.channel_ready ? '可用' : '未配置'
                    }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="说明" prop="message" min-width="180" />
        </el-table>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-canvas-dependencies">
import { getAigcCanvasDependencies } from '@/apps/aigc_canvas/api'

const loading = ref(false)
const dependencies = ref<any>({ items: [] })
const getData = async () => {
    loading.value = true
    try {
        dependencies.value = await getAigcCanvasDependencies()
    } finally {
        loading.value = false
    }
}
getData()
</script>
