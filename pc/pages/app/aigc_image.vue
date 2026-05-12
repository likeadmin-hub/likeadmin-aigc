<template>
    <div class="aigc-image">
        <div class="bg-white rounded-[8px] p-5">
            <div class="text-xl font-medium">AIGC生图</div>
            <div class="mt-4">
                <ElInput v-model="form.prompt" type="textarea" :rows="4" placeholder="输入提示词" />
            </div>
            <div class="image-form-grid mt-4">
                <ElSelect v-model="form.ratio">
                    <ElOption label="1:1" value="1:1" />
                    <ElOption label="16:9" value="16:9" />
                    <ElOption label="9:16" value="9:16" />
                </ElSelect>
                <ElSelect v-model="form.style">
                    <ElOption label="通用" value="general" />
                    <ElOption label="写实" value="realistic" />
                    <ElOption label="插画" value="illustration" />
                </ElSelect>
                <ElInputNumber v-model="form.quantity" :min="1" :max="4" class="!w-full" />
            </div>
            <div class="mt-4 text-sm text-tx-secondary">预计消耗 {{ form.quantity }} 点数</div>
            <ElButton class="mt-2" type="primary" :loading="submitting || isGenerateLocked" @click="handleGenerateLock">生成图片</ElButton>
        </div>
        <div class="image-result-grid mt-5">
            <div v-for="item in results" :key="item.id" class="bg-white rounded-[8px] overflow-hidden">
                <ElImage class="w-full aspect-square bg-page" :src="item.image_url" fit="cover" />
                <div class="p-3 flex justify-between items-center">
                    <span class="text-xs text-tx-secondary">{{ item.storage_engine }}</span>
                    <ElButton type="danger" link @click="handleDelete(item.id)">删除</ElButton>
                </div>
            </div>
        </div>
        <div class="mt-5 bg-white rounded-[8px] p-5">
            <div class="font-medium mb-3">最近任务</div>
            <div class="image-table-wrap">
                <ElTable :data="tasks" size="large">
                    <ElTableColumn label="ID" prop="id" width="80" />
                    <ElTableColumn label="提示词" prop="prompt" min-width="220" show-overflow-tooltip />
                    <ElTableColumn label="比例" prop="ratio" width="100" />
                    <ElTableColumn label="数量" prop="quantity" width="80" />
                    <ElTableColumn label="状态" prop="status" width="100" />
                </ElTable>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ElButton, ElImage, ElInput, ElInputNumber, ElOption, ElSelect, ElTable, ElTableColumn } from 'element-plus'
import { deleteAigcImageResult, generateAigcImage, getAigcImageResults, getAigcImageTasks } from '@/apps/aigc_image/api'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'

const submitting = ref(false)
const tasks = ref<any[]>([])
const results = ref<any[]>([])
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const form = reactive({
    prompt: '',
    ratio: '1:1',
    style: 'general',
    quantity: 1
})
const getData = async () => {
    if (!userStore.isLogin) {
        tasks.value = []
        results.value = []
        return
    }
    try {
        tasks.value = await getAigcImageTasks()
        results.value = await getAigcImageResults()
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}
const handleGenerate = async () => {
    if (submitting.value) return
    if (!ensurePcLogin()) return
    submitting.value = true
    try {
        await generateAigcImage(form)
        form.prompt = ''
        await getData()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交生成任务失败')
    } finally {
        submitting.value = false
    }
}
const { lockFn: handleGenerateLock, isLock: isGenerateLocked } = useLockFn(handleGenerate)
const handleDelete = async (id: number) => {
    if (!ensurePcLogin()) return
    try {
        await deleteAigcImageResult({ id })
        feedback.msgSuccess('删除成功')
        getData()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '删除失败')
    }
}
getData()

watch(() => userStore.isLogin, getData)
</script>

<style lang="scss" scoped>
.aigc-image {
    min-width: 0;
}

.image-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
}

.image-result-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
}

.image-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.image-table-wrap :deep(.el-table) {
    min-width: 580px;
}
</style>
