<template>
    <el-card class="!border-none" shadow="never">
        <el-table v-loading="loading" :data="lists" size="large">
            <el-table-column label="租户ID" prop="tenant_id" min-width="100" />
            <el-table-column label="会话数" prop="session_count" min-width="120" />
            <el-table-column label="消息数" prop="message_count" min-width="120" />
            <el-table-column label="用户数" prop="user_count" min-width="120" />
            <el-table-column label="总Token" prop="total_tokens" min-width="130" />
            <el-table-column label="租户成本点" min-width="130">
                <template #default="{ row }">{{ formatNumber(row.tenant_cost_points) }}</template>
            </el-table-column>
            <el-table-column label="用户扣点" min-width="130">
                <template #default="{ row }">{{ formatNumber(row.user_charge_points) }}</template>
            </el-table-column>
            <el-table-column label="更新时间" min-width="170">
                <template #default="{ row }">{{ formatTime(row.update_time) }}</template>
            </el-table-column>
        </el-table>
    </el-card>
</template>

<script lang="ts" setup name="platform-aigc-llm-tenant-usage">
import { getAigcLlmTenantStat } from '@/apps/aigc_llm/api'

const loading = ref(false)
const lists = ref<any[]>([])
const formatTime = (time: number) => (time ? new Date(time * 1000).toLocaleString() : '-')
const formatNumber = (value: number) => {
    const numberValue = Number(value || 0)
    return Number.isFinite(numberValue) ? numberValue.toFixed(4).replace(/\.?0+$/, '') : '0'
}

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcLlmTenantStat()
    } finally {
        loading.value = false
    }
}

getLists()
</script>
