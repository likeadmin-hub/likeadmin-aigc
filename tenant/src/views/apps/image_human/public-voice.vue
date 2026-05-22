<template>
    <el-card class="!border-none" shadow="never">
        <div class="mb-4 flex items-center justify-between">
            <el-button type="primary" @click="handleAdd">新增公共音色</el-button>
            <span class="text-sm text-tx-secondary">与对口型数字人共用同一份音色数据</span>
        </div>
        <el-table v-loading="pager.loading" size="large" :data="pager.lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column
                label="音色ID"
                prop="provider_asset_id"
                min-width="200"
                show-overflow-tooltip
            />
            <el-table-column
                label="音频样本"
                prop="audio_uri"
                min-width="220"
                show-overflow-tooltip
            />
            <el-table-column label="排序" prop="sort" width="90" />
            <el-table-column label="操作" width="150" fixed="right">
                <template #default="{ row }">
                    <el-button type="primary" link @click="handleEdit(row)">编辑</el-button>
                    <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                </template>
            </el-table-column>
        </el-table>
        <div class="flex justify-end mt-4">
            <pagination v-model="pager" @change="getLists" />
        </div>
        <el-dialog
            v-model="dialogVisible"
            :title="form.id ? '编辑公共音色' : '新增公共音色'"
            width="560px"
        >
            <el-form label-width="120px">
                <el-form-item label="名称">
                    <el-input v-model="form.name" placeholder="请输入音色名称" />
                </el-form-item>
                <el-form-item label="音色ID">
                    <el-input v-model="form.provider_asset_id" placeholder="已有音色ID可直接填写" />
                </el-form-item>
                <el-form-item label="克隆音频URI">
                    <el-input
                        v-model="form.audio_uri"
                        placeholder="未填音色ID时，使用该音频调用克隆接口"
                    />
                </el-form-item>
                <el-form-item label="性别">
                    <el-input v-model="form.gender" placeholder="female / male" />
                </el-form-item>
                <el-form-item label="年龄段">
                    <el-input v-model="form.age_group" placeholder="young / middle" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="form.sort" :min="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
            </template>
        </el-dialog>
    </el-card>
</template>

<script lang="ts" setup name="tenant-image-human-public-voice">
import {
    deleteImageHumanPublicVoice,
    getImageHumanPublicVoices,
    saveImageHumanPublicVoice
} from '@/apps/image_human/api'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const dialogVisible = ref(false)
const form = reactive<any>({
    id: 0,
    name: '',
    provider_asset_id: '',
    audio_uri: '',
    gender: '',
    age_group: '',
    sort: 0
})
const resetForm = (row: any = {}) =>
    Object.assign(
        form,
        {
            id: 0,
            name: '',
            provider_asset_id: '',
            audio_uri: '',
            gender: '',
            age_group: '',
            sort: 0
        },
        row
    )
const { pager, getLists } = usePaging({
    fetchFun: getImageHumanPublicVoices
})
const handleAdd = () => {
    resetForm()
    dialogVisible.value = true
}
const handleEdit = (row: any) => {
    resetForm(row)
    dialogVisible.value = true
}
const handleSave = async () => {
    saving.value = true
    try {
        await saveImageHumanPublicVoice(form)
        dialogVisible.value = false
        getLists()
    } finally {
        saving.value = false
    }
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该公共音色？')
    await deleteImageHumanPublicVoice({ id })
    getLists()
}
getLists()
</script>
