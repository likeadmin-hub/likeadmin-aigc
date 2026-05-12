<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :inline="true" :model="formData">
                <el-form-item label="敏感词">
                    <el-input v-model="formData.word" class="w-[220px]" />
                </el-form-item>
                <el-form-item label="状态">
                    <el-switch v-model="formData.status" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="handleSubmit">保存</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
                <el-table-column label="ID" prop="id" width="100" />
                <el-table-column label="敏感词" prop="word" />
                <el-table-column label="状态" width="120">
                    <template #default="{ row }">
                        <el-tag :type="row.status ? 'success' : 'info'">{{ row.status ? '启用' : '停用' }}</el-tag>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-sensitive-word">
import { getAigcDigitalHumanSensitiveWords, setAigcDigitalHumanSensitiveWord } from '@/apps/aigc_digital_human/api'

const loading = ref(false)
const lists = ref<any[]>([])
const formData = reactive({ word: '', status: 1 })
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcDigitalHumanSensitiveWords()
    } finally {
        loading.value = false
    }
}
const handleSubmit = async () => {
    await setAigcDigitalHumanSensitiveWord(formData)
    formData.word = ''
    getLists()
}
getLists()
</script>
