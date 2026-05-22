<template>
    <el-card class="!border-none" shadow="never" v-loading="loading">
        <div class="grid grid-cols-4 gap-4">
            <div v-for="item in cards" :key="item.label" class="p-4 bg-page rounded">
                <div class="text-sm text-tx-secondary">{{ item.label }}</div>
                <div class="text-2xl font-medium mt-2">{{ item.value }}</div>
            </div>
        </div>
    </el-card>
</template>

<script lang="ts" setup name="platform-aigc-video-overview">
import { getAigcVideoTenantStat } from '@/apps/aigc_video/api'

const loading = ref(false)
const stat = ref<any>({})
const cards = computed(() => [
    { label: '任务数', value: stat.value.task_total || 0 },
    { label: '成功任务', value: stat.value.task_success || 0 },
    { label: '失败任务', value: stat.value.task_failed || 0 },
    { label: '作品数', value: stat.value.result_total || 0 },
    { label: '租户成本扣点', value: stat.value.tenant_cost_points || 0 },
    { label: '租户消费', value: stat.value.user_charge_points || 0 }
])
const getData = async () => {
    loading.value = true
    try {
        stat.value = await getAigcVideoTenantStat()
    } finally {
        loading.value = false
    }
}
getData()
</script>
