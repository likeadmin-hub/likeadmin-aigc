<template>
    <div v-loading="loading">
        <app-display-config v-model="displayConfig" />
        <el-card class="!border-none" shadow="never">
            <template #header>
                <div class="font-medium">依赖状态</div>
            </template>
            <el-table :data="dependencies.items || []" border>
                <el-table-column label="依赖应用" prop="name" min-width="160" />
                <el-table-column label="能力" prop="required_for" min-width="140" />
                <el-table-column label="状态" min-width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.ready ? 'success' : 'danger'">
                            {{ row.ready ? '可用' : '不可用' }}
                        </el-tag>
                    </template>
                </el-table-column>
            </el-table>
            <div class="mt-4">
                <el-button type="primary" @click="handleSubmit">保存</el-button>
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-canvas-config">
import { getAigcCanvasConfig, setAigcCanvasConfig } from '@/apps/aigc_canvas/api'
import AppDisplayConfig from '@/views/apps/components/app-display-config.vue'

const loading = ref(false)
const displayConfig = ref<Record<string, any>>({})
const dependencies = ref<Record<string, any>>({
    items: [],
    ready: false
})

const getData = async () => {
    loading.value = true
    try {
        const data: any = await getAigcCanvasConfig()
        displayConfig.value = data?.display_config || {}
        dependencies.value = data?.dependencies || { items: [], ready: false }
    } finally {
        loading.value = false
    }
}

const handleSubmit = async () => {
    await setAigcCanvasConfig({
        display_config: displayConfig.value
    })
    getData()
}

getData()
</script>
