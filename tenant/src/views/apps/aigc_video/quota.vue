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
            <el-table v-loading="pager.loading" size="large" :data="pager.lists">
                <el-table-column label="用户ID" prop="user_id" />
                <el-table-column label="总额度" prop="total_quota" />
                <el-table-column label="已使用" prop="used_quota" />
                <el-table-column label="过期时间" prop="expire_time" />
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-video-quota">
import { getAigcVideoQuota, setAigcVideoQuota } from '@/apps/aigc_video/api'
import { usePaging } from '@/hooks/usePaging'

const formData = reactive({ user_id: '', total_quota: 0, used_quota: 0, expire_time: 0 })
const { pager, getLists } = usePaging({
    fetchFun: getAigcVideoQuota
})
const handleSubmit = async () => {
    await setAigcVideoQuota(formData)
    getLists()
}
getLists()
</script>
