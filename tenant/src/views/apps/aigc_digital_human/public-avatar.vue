<template>
    <el-card class="!border-none" shadow="never">
        <div class="mb-4">
            <el-button type="primary" @click="handleAdd">新增公共形象</el-button>
        </div>
        <el-table v-loading="pager.loading" size="large" :data="pager.lists">
            <el-table-column label="ID" prop="id" width="80" />
            <el-table-column label="封面" width="110">
                <template #default="{ row }">
                    <el-image
                        class="w-[64px] h-[64px] rounded"
                        :src="row.cover_url || row.media_url"
                        fit="cover"
                    />
                </template>
            </el-table-column>
            <el-table-column label="名称" prop="name" min-width="160" />
            <el-table-column
                label="视频素材"
                prop="media_uri"
                min-width="240"
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
            :title="form.id ? '编辑公共形象' : '新增公共形象'"
            width="560px"
        >
            <el-form label-width="110px">
                <el-form-item label="名称">
                    <el-input v-model="form.name" placeholder="请输入形象名称" />
                </el-form-item>
                <el-form-item label="视频素材">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3">
                            <upload
                                type="video"
                                :multiple="false"
                                :limit="1"
                                :show-progress="true"
                                @success="handleMediaUpload"
                            >
                                <el-button type="primary">上传视频</el-button>
                            </upload>
                            <el-input
                                v-model="form.media_uri"
                                class="w-[330px]"
                                placeholder="必须填写可访问的视频素材 URI"
                            />
                        </div>
                        <video
                            v-if="mediaPreviewUrl"
                            class="w-[220px] h-[124px] rounded object-cover bg-black"
                            :src="mediaPreviewUrl"
                            controls
                            preload="metadata"
                        />
                    </div>
                </el-form-item>
                <el-form-item label="封面">
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center gap-3">
                            <upload
                                type="image"
                                :multiple="false"
                                :limit="1"
                                :show-progress="true"
                                @success="handleCoverUpload"
                            >
                                <el-button>上传封面</el-button>
                            </upload>
                            <el-input
                                v-model="form.cover_uri"
                                class="w-[330px]"
                                placeholder="可选，不填则使用视频素材"
                            />
                        </div>
                        <el-image
                            v-if="coverPreviewUrl"
                            class="w-[96px] h-[96px] rounded"
                            :src="coverPreviewUrl"
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

<script lang="ts" setup name="tenant-aigc-digital-human-public-avatar">
import {
    deleteAigcDigitalHumanPublicAvatar,
    getAigcDigitalHumanPublicAvatars,
    saveAigcDigitalHumanPublicAvatar
} from '@/apps/aigc_digital_human/api'
import Upload from '@/components/upload/index.vue'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const saving = ref(false)
const dialogVisible = ref(false)
const form = reactive<any>({
    id: 0,
    name: '',
    media_uri: '',
    cover_uri: '',
    gender: '',
    scene: '',
    sort: 0
})
const mediaPreviewUrl = ref('')
const coverPreviewUrl = ref('')
const resetForm = (row: any = {}) => {
    Object.assign(
        form,
        { id: 0, name: '', media_uri: '', cover_uri: '', gender: '', scene: '', sort: 0 },
        row
    )
    mediaPreviewUrl.value = row.media_url || ''
    coverPreviewUrl.value = row.cover_url || ''
}
const getUploadData = (response: any) => response?.data || response || {}
const handleMediaUpload = (response: any) => {
    const data = getUploadData(response)
    form.media_uri = data.uri || data.url || ''
    mediaPreviewUrl.value = data.url || data.uri || form.media_uri
}
const handleCoverUpload = (response: any) => {
    const data = getUploadData(response)
    form.cover_uri = data.uri || data.url || ''
    coverPreviewUrl.value = data.url || data.uri || form.cover_uri
}
const { pager, getLists } = usePaging({
    fetchFun: getAigcDigitalHumanPublicAvatars
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
        await saveAigcDigitalHumanPublicAvatar(form)
        dialogVisible.value = false
        getLists()
    } finally {
        saving.value = false
    }
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该公共形象？')
    await deleteAigcDigitalHumanPublicAvatar({ id })
    getLists()
}
getLists()
</script>
