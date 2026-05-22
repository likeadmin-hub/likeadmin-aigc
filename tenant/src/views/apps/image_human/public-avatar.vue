<template>
    <el-card class="!border-none" shadow="never">
        <div class="mb-4">
            <el-button type="primary" @click="handleAdd">新增公共形象</el-button>
        </div>
        <el-table v-loading="pager.loading" size="large" :data="pager.lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="图片形象" width="110">
                <template #default="{ row }">
                    <el-image
                        class="w-[64px] h-[64px] rounded"
                        :src="row.image_url || row.cover_url || row.media_url"
                        :preview-src-list="[row.image_url || row.cover_url || row.media_url]"
                        preview-teleported
                        fit="cover"
                    />
                </template>
            </el-table-column>
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column
                label="图片素材"
                prop="image_uri"
                min-width="240"
                show-overflow-tooltip
            />
            <el-table-column label="场景" prop="scene" width="120" />
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
            :title="form.id ? '编辑公共形象' : '新增公共形象'"
            width="560px"
        >
            <el-form label-width="110px">
                <el-form-item label="名称">
                    <el-input v-model="form.name" placeholder="请输入形象名称" />
                </el-form-item>
                <el-form-item label="图片形象">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3">
                            <upload
                                type="image"
                                :multiple="false"
                                :limit="1"
                                :show-progress="true"
                                @success="handleImageUpload"
                            >
                                <el-button type="primary">上传图片</el-button>
                            </upload>
                            <el-input
                                v-model="form.image_uri"
                                class="w-[330px]"
                                placeholder="必须填写可访问的图片 URI"
                            />
                        </div>
                        <el-image
                            v-if="imagePreviewUrl"
                            class="w-[96px] h-[96px] rounded"
                            :src="imagePreviewUrl"
                            fit="cover"
                        />
                    </div>
                </el-form-item>
                <el-form-item label="性别">
                    <el-input v-model="form.gender" placeholder="female / male" />
                </el-form-item>
                <el-form-item label="场景">
                    <el-input v-model="form.scene" placeholder="口播、讲解等" />
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

<script lang="ts" setup name="tenant-image-human-public-avatar">
import {
    deleteImageHumanPublicAvatar,
    getImageHumanPublicAvatars,
    saveImageHumanPublicAvatar
} from '@/apps/image_human/api'
import Upload from '@/components/upload/index.vue'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const dialogVisible = ref(false)
const form = reactive<any>({
    id: 0,
    name: '',
    image_uri: '',
    gender: '',
    scene: '',
    sort: 0
})
const imagePreviewUrl = ref('')
const resetForm = (row: any = {}) => {
    Object.assign(form, { id: 0, name: '', image_uri: '', gender: '', scene: '', sort: 0 }, row)
    form.image_uri = row.image_uri || row.media_uri || ''
    imagePreviewUrl.value = row.image_url || row.cover_url || row.media_url || ''
}
const getUploadData = (response: any) => response?.data || response || {}
const handleImageUpload = (response: any) => {
    const data = getUploadData(response)
    form.image_uri = data.uri || data.url || ''
    imagePreviewUrl.value = data.url || data.uri || form.image_uri
}
const { pager, getLists } = usePaging({
    fetchFun: getImageHumanPublicAvatars
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
        await saveImageHumanPublicAvatar(form)
        dialogVisible.value = false
        getLists()
    } finally {
        saving.value = false
    }
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该公共形象？')
    await deleteImageHumanPublicAvatar({ id })
    getLists()
}
getLists()
</script>
