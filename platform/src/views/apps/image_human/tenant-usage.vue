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
            <div class="grid grid-cols-4 gap-4">
                <div v-for="item in cards" :key="item.label" class="p-4 bg-page rounded">
                    <div class="text-sm text-tx-secondary">{{ item.label }}</div>
                    <div class="text-2xl font-medium mt-2">{{ item.value }}</div>
                </div>
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="platform-image-human-tenant-usage">
import { getImageHumanTenantStat } from '@/apps/image_human/api'

const loading = ref(false)
const tenantId = ref('')
const stat = ref<any>({})
const cards = computed(() => [
    { label: '任务数', value: stat.value.task_total || 0 },
    { label: '成功任务', value: stat.value.task_success || 0 },
    { label: '失败任务', value: stat.value.task_failed || 0 },
    { label: '作品数', value: stat.value.result_total || 0 },
    { label: '形象素材', value: stat.value.avatar_total || 0 },
    { label: '参考音频', value: stat.value.voice_total || 0 },
    { label: '租户成本扣点', value: stat.value.tenant_cost_points || 0 },
    { label: '用户消费扣点', value: stat.value.user_charge_points || 0 }
])
const getData = async () => {
    loading.value = true
    try {
        stat.value = await getImageHumanTenantStat({ tenant_id: tenantId.value || 0 })
    } finally {
        loading.value = false
    }
}
getData()
</script>
