<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form :inline="true" class="mb-[-16px]">
                <el-form-item label="租户ID">
                    <el-input v-model="tenantId" class="w-[180px]" placeholder="留空查看全部" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="getData">查询</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never" v-loading="loading">
            <div class="grid grid-cols-4 gap-4 mb-4">
                <div v-for="item in cards" :key="item.label" class="p-4 bg-page rounded">
                    <div class="text-sm text-tx-secondary">{{ item.label }}</div>
                    <div class="text-2xl font-medium mt-2">{{ item.value }}</div>
                </div>
            </div>
            <el-table :data="lists" size="large">
                <el-table-column label="租户ID" prop="tenant_id" width="120" />
                <el-table-column label="运行次数" prop="run_total" width="120" />
                <el-table-column label="运行用户" prop="run_user_total" width="120" />
                <el-table-column label="成功运行" prop="run_success" width="120" />
                <el-table-column label="失败运行" prop="run_failed" width="120" />
                <el-table-column label="最近运行" min-width="180">
                    <template #default="{ row }">{{ formatTime(row.last_run_time) }}</template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-canvas-tenant-usage">
import { getAigcCanvasTenantLists, getAigcCanvasTenantStat } from '@/apps/aigc_canvas/api'

const loading = ref(false)
const tenantId = ref('')
const stat = ref<any>({})
const lists = ref<any[]>([])
const cards = computed(() => [
    { label: '运行次数', value: stat.value.run_total || 0 },
    { label: '运行用户', value: stat.value.run_user_total || 0 },
    { label: '成功运行', value: stat.value.run_success || 0 },
    { label: '失败运行', value: stat.value.run_failed || 0 }
])
const formatTime = (value: number) => (value ? new Date(value * 1000).toLocaleString() : '-')
const getData = async () => {
    loading.value = true
    try {
        const params = { tenant_id: tenantId.value || 0 }
        stat.value = await getAigcCanvasTenantStat(params)
        lists.value = await getAigcCanvasTenantLists(params)
    } finally {
        loading.value = false
    }
}
getData()
</script>
