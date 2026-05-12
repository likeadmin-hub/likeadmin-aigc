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

<script lang="ts" setup name="tenant-aigc-canvas-stat">
import { getAigcCanvasStat } from '@/apps/aigc_canvas/api'

const loading = ref(false)
const stat = ref<any>({})
const cards = computed(() => [
    { label: '运行次数', value: stat.value.run_total || 0 },
    { label: '运行用户', value: stat.value.run_user_total || 0 },
    { label: '成功运行', value: stat.value.run_success || 0 },
    { label: '失败运行', value: stat.value.run_failed || 0 },
    { label: '生图调用', value: stat.value.image_run_total || 0 },
    { label: '生视频调用', value: stat.value.video_run_total || 0 },
    { label: '最近运行', value: stat.value.recent_run_time ? new Date(stat.value.recent_run_time * 1000).toLocaleString() : '-' },
    { label: '依赖状态', value: stat.value.dependencies?.ready ? '正常' : '需处理' }
])
const getData = async () => {
    loading.value = true
    try {
        stat.value = await getAigcCanvasStat()
    } finally {
        loading.value = false
    }
}
getData()
</script>
