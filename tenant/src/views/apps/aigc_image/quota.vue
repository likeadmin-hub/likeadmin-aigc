<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :inline="true" :model="formData">
                <el-form-item label="用户ID">
                    <el-input v-model="formData.user_id" class="w-[160px]" />
                </el-form-item>
                <el-form-item label="总额度">
                    <el-input-number v-model="formData.total_quota" :min="0" />
                </el-form-item>
                <el-form-item label="已用">
                    <el-input-number v-model="formData.used_quota" :min="0" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSubmit">保存</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
                <el-table-column label="用户ID" prop="user_id" />
                <el-table-column label="总额度" prop="total_quota" />
                <el-table-column label="已使用" prop="used_quota" />
                <el-table-column label="过期时间" prop="expire_time" />
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-image-quota">
import { getAigcImageQuota, setAigcImageQuota } from '@/apps/aigc_image/api'

const loading = ref(false)
const lists = ref<any[]>([])
const formData = reactive({ user_id: '', total_quota: 0, used_quota: 0, expire_time: 0 })
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcImageQuota()
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcImageQuota(formData)
    getLists()
}
getLists()
</script>
