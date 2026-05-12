<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
        <el-row :gutter="16">
            <el-col v-for="item in cards" :key="item.label" :span="6" class="mb-4">
                <div class="stat-card">
                    <div class="stat-label">{{ item.label }}</div>
                    <div class="stat-value">{{ item.value }}</div>
                </div>
            </el-col>
        </el-row>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-llm-stat">
import { getAigcLlmStat } from '@/apps/aigc_llm/api'

const loading = ref(false)
const stat = ref<any>({})
const cards = computed(() => [
    { label: '会话数', value: stat.value.session_count || 0 },
    { label: '消息数', value: stat.value.message_count || 0 },
    { label: '用户数', value: stat.value.user_count || 0 },
    { label: '总Token', value: stat.value.total_tokens || 0 },
    { label: '用户扣点', value: formatNumber(Number(stat.value.user_charge_points || 0)) },
    { label: '今日Token', value: stat.value.today_tokens || 0 },
    { label: '今日扣点', value: formatNumber(Number(stat.value.today_user_charge_points || 0)) }
])

const formatNumber = (value: number) => {
    if (!Number.isFinite(value)) return '0'
    return value.toFixed(4).replace(/\.?0+$/, '')
}

const getData = async () => {
    loading.value = true
    try {
        stat.value = await getAigcLlmStat()
    } finally {
        loading.value = false
    }
}

getData()
</script>

<style scoped>
.stat-card {
    border-radius: 8px;
    background: #f7f8fa;
    padding: 18px;
}
.stat-label {
    color: #64748b;
}
.stat-value {
    margin-top: 8px;
    font-size: 28px;
    font-weight: 600;
}
</style>
