<template>
    <el-card class="!border-none" shadow="never">
        <template #header>
            <el-button type="primary" @click="openEdit()">新增敏感词</el-button>
        </template>
        <el-table v-loading="loading" :data="lists" size="large">
            <el-table-column label="敏感词" prop="word" min-width="180" />
            <el-table-column label="状态" width="110">
                <template #default="{ row }">
                    <el-tag :type="row.status ? 'success' : 'info'">{{
                        row.status ? '启用' : '停用'
                    }}</el-tag>
                </template>
            </el-table-column>
            <el-table-column label="操作" width="100">
                <template #default="{ row }">
                    <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-dialog v-model="editVisible" title="敏感词" width="420px">
            <el-form label-width="90px" :model="formData">
                <el-form-item label="词语"><el-input v-model="formData.word" /></el-form-item>
                <el-form-item label="状态">
                    <el-radio-group v-model="formData.status">
                        <el-radio :value="1">启用</el-radio>
                        <el-radio :value="0">停用</el-radio>
                    </el-radio-group>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-llm-sensitive-word">
import { getAigcLlmSensitiveWords, setAigcLlmSensitiveWord } from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const editVisible = ref(false)
const lists = ref<any[]>([])
const formData = reactive<any>({ id: 0, word: '', status: 1 })

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcLlmSensitiveWords()
    } finally {
        loading.value = false
    }
}

const openEdit = (row: any = {}) => {
    Object.assign(formData, {
        id: row.id || 0,
        word: row.word || '',
        status: Number(row.status ?? 1)
    })
    editVisible.value = true
}

const handleSubmit = async () => {
    saving.value = true
    try {
        await setAigcLlmSensitiveWord(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

getLists()
</script>
