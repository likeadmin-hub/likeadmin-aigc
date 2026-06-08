<template>
    <div v-loading="loading">
        <app-display-config v-model="displayConfig" />
        <el-card class="!border-none" shadow="never">
            <el-form label-width="120px" :model="formData">
                <el-form-item label="状态">
                    <el-radio-group v-model="formData.status">
                        <el-radio :value="1">启用</el-radio>
                        <el-radio :value="0">停用</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSubmit">保存</el-button>
                </el-form-item>
            </el-form>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-video-config">
import { getAigcVideoConfig, setAigcVideoConfig } from '@/apps/aigc_video/api'
import AppDisplayConfig from '@/views/apps/components/app-display-config.vue'

const loading = ref(false)
const formData = reactive({
    status: 1,
    config_json: {}
})
const displayConfig = ref<Record<string, any>>({})
const getData = async () => {
    loading.value = true
    try {
        const data: any = await getAigcVideoConfig()
        Object.assign(formData, data)
        displayConfig.value = data?.display_config || {}
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcVideoConfig({
        status: formData.status,
        config_json: formData.config_json,
        display_config: displayConfig.value
    })
    getData()
}
getData()
</script>
