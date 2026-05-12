<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="case-filter">
                <el-form class="case-filter__form" :inline="true" :model="queryParams">
                    <el-form-item label="类型">
                        <el-select v-model="queryParams.media_type" class="case-filter__select" @change="getLists">
                            <el-option label="全部类型" value="" />
                            <el-option label="图片" value="image" />
                            <el-option label="视频" value="video" />
                        </el-select>
                    </el-form-item>
                    <el-form-item label="状态">
                        <el-select v-model="queryParams.status" class="case-filter__select" @change="getLists">
                            <el-option label="全部状态" value="" />
                            <el-option label="启用" :value="1" />
                            <el-option label="停用" :value="0" />
                        </el-select>
                    </el-form-item>
                    <el-form-item>
                        <el-button @click="getLists">查询</el-button>
                    </el-form-item>
                </el-form>
                <el-button type="primary" @click="openEdit()">新增案例</el-button>
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
                <el-table-column label="封面" width="96">
                    <template #default="{ row }">
                        <video
                            v-if="row.media_type === 'video' && row.media_url"
                            class="case-media-preview"
                            :src="row.media_url"
                            :poster="row.cover_url || undefined"
                            muted
                            playsinline
                            preload="metadata"
                        />
                        <el-image
                            v-else-if="row.cover_url"
                            class="w-[64px] h-[64px] rounded object-cover"
                            :src="row.cover_url"
                            fit="cover"
                            :preview-src-list="[row.cover_url]"
                            preview-teleported
                        />
                        <span v-else class="text-tx-secondary">-</span>
                    </template>
                </el-table-column>
                <el-table-column label="标题" prop="title" min-width="160" show-overflow-tooltip />
                <el-table-column label="类型" width="90">
                    <template #default="{ row }">
                        <el-tag>{{ row.media_type === 'video' ? '视频' : '图片' }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="提示词" prop="prompt" min-width="260" show-overflow-tooltip />
                <el-table-column label="排序" prop="sort" width="90" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-switch
                            v-model="row.status"
                            :active-value="1"
                            :inactive-value="0"
                            @change="handleStatus(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
                        <el-button type="danger" link @click="handleDelete(row.id)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="dialogVisible" :title="formData.id ? '编辑案例' : '新增案例'" width="720px">
            <el-form label-width="100px" :model="formData">
                <el-form-item label="标题" required>
                    <el-input v-model="formData.title" placeholder="请输入案例标题" />
                </el-form-item>
                <el-form-item label="类型">
                    <el-radio-group v-model="formData.media_type">
                        <el-radio value="image">图片</el-radio>
                        <el-radio value="video">视频</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="封面" required>
                    <material-picker
                        v-model="formData.cover_uri"
                        :limit="1"
                        exclude-domain
                        size="96px"
                    />
                </el-form-item>
                <el-form-item label="作品">
                    <material-picker
                        v-model="formData.media_uri"
                        :limit="1"
                        :type="formData.media_type === 'video' ? 'video' : 'image'"
                        exclude-domain
                        size="96px"
                    />
                    <div class="case-form-tip">不选择作品时默认使用封面</div>
                </el-form-item>
                <el-form-item label="提示词">
                    <el-input v-model="formData.prompt" type="textarea" :rows="4" placeholder="请输入案例提示词" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="formData.sort" :min="0" />
                </el-form-item>
                <el-form-item label="状态">
                    <el-switch v-model="formData.status" :active-value="1" :inactive-value="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-digital-human-case">
import {
    deleteAigcDigitalHumanCase,
    getAigcDigitalHumanCases,
    saveAigcDigitalHumanCase,
    setAigcDigitalHumanCaseStatus
} from '@/apps/aigc_digital_human/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const lists = ref<any[]>([])
const dialogVisible = ref(false)
const queryParams = reactive({
    media_type: '',
    status: ''
})
const defaultForm = () => ({
    id: 0,
    title: '',
    prompt: '',
    media_type: 'image',
    cover_uri: '',
    media_uri: '',
    config_json: {},
    status: 1,
    sort: 0
})
const formData = reactive<any>(defaultForm())

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcDigitalHumanCases(queryParams)
    } finally {
        loading.value = false
    }
}
const resetForm = () => {
    Object.assign(formData, defaultForm())
}
const openEdit = (row?: any) => {
    resetForm()
    if (row) {
        Object.assign(formData, {
            ...row,
            config_json: {}
        })
    }
    dialogVisible.value = true
}
const handleSubmit = async () => {
    await saveAigcDigitalHumanCase({
        ...formData,
        config_json: {}
    })
    dialogVisible.value = false
    getLists()
}
const handleStatus = async (row: any) => {
    await setAigcDigitalHumanCaseStatus({ id: row.id, status: row.status })
}
const handleDelete = async (id: number) => {
    await feedback.confirm('确定删除该案例？')
    await deleteAigcDigitalHumanCase({ id })
    getLists()
}

getLists()
</script>

<style lang="scss" scoped>
.case-filter {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.case-filter__form {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: -16px;
}

.case-filter__select {
    width: 156px;
}

.case-form-tip {
    width: 100%;
    margin-top: 6px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    line-height: 18px;
}

.case-media-preview {
    width: 64px;
    height: 64px;
    border-radius: 6px;
    object-fit: cover;
    background: #000;
}
</style>
