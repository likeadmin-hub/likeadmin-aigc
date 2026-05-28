<template>
    <div class="website-banner">
        <el-card shadow="never" class="!border-none" v-loading="loading">
            <div class="flex justify-between mb-4">
                <div>
                    <div class="text-xl font-medium">网站轮播</div>
                    <div class="form-tips mt-1">用于 PC 首页顶部轮播，支持图片和视频</div>
                </div>
                <el-button type="primary" @click="handleAdd">新增轮播</el-button>
            </div>
            <el-table :data="banners" border>
                <el-table-column label="媒体" width="160">
                    <template #default="{ row }">
                        <video
                            v-if="row.media_type === 'video'"
                            class="banner-media"
                            :src="row.media_url"
                            :poster="row.poster_url || undefined"
                            muted
                            playsinline
                        />
                        <el-image
                            v-else
                            class="banner-media"
                            :src="row.media_url"
                            fit="cover"
                            :preview-src-list="row.media_url ? [row.media_url] : []"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="标题" min-width="180">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.title || '-' }}</div>
                        <div class="text-tx-secondary text-xs line-clamp-2">{{ row.description || '-' }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="跳转" min-width="160">
                    <template #default="{ row }">
                        <el-tag>{{ linkTypeText(row.link_type) }}</el-tag>
                        <div class="text-tx-secondary text-xs mt-1">
                            {{ linkTargetText(row) || '-' }}
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="排序" prop="sort" width="90" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="row.status === 1"
                            @change="(value: boolean) => handleStatus(row, value)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="展示时间" min-width="180">
                    <template #default="{ row }">
                        <div>{{ row.start_time || '不限开始' }}</div>
                        <div>{{ row.end_time || '不限结束' }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
                        <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="dialogVisible" :title="formData.id ? '编辑轮播' : '新增轮播'" width="720px">
            <el-form ref="formRef" :model="formData" label-width="110px">
                <el-form-item label="标题">
                    <el-input v-model.trim="formData.title" maxlength="80" show-word-limit />
                </el-form-item>
                <el-form-item label="描述">
                    <el-input
                        v-model.trim="formData.description"
                        type="textarea"
                        :rows="3"
                        maxlength="200"
                        show-word-limit
                    />
                </el-form-item>
                <el-form-item label="媒体类型">
                    <el-radio-group v-model="formData.media_type">
                        <el-radio value="image">图片</el-radio>
                        <el-radio value="video">视频</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="媒体资源">
                    <material-picker
                        v-model="formData.media_uri"
                        :type="formData.media_type"
                        :limit="1"
                        width="180px"
                        height="108px"
                    />
                </el-form-item>
                <el-form-item v-if="formData.media_type === 'video'" label="视频封面">
                    <material-picker
                        v-model="formData.poster_uri"
                        :limit="1"
                        width="180px"
                        height="108px"
                    />
                </el-form-item>
                <el-form-item label="跳转类型">
                    <el-radio-group v-model="formData.link_type">
                        <el-radio value="none">不跳转</el-radio>
                        <el-radio value="path">站内路径</el-radio>
                        <el-radio value="url">外链</el-radio>
                        <el-radio value="app">应用入口</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item v-if="formData.link_type === 'path'" label="站内路径">
                    <el-input v-model.trim="formData.link_path" placeholder="/ai/create" />
                </el-form-item>
                <el-form-item v-if="formData.link_type === 'url'" label="外链地址">
                    <el-input v-model.trim="formData.link_url" placeholder="https://example.com" />
                </el-form-item>
                <el-form-item v-if="formData.link_type === 'app'" label="应用入口">
                    <el-select v-model="formData.link_app_code" class="w-80">
                        <el-option
                            v-for="item in appOptions"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="formData.sort" :precision="0" />
                </el-form-item>
                <el-form-item label="状态">
                    <el-radio-group v-model="formData.status">
                        <el-radio :value="1">启用</el-radio>
                        <el-radio :value="0">停用</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item label="展示时间">
                    <div class="flex gap-3">
                        <el-date-picker
                            v-model="formData.start_time"
                            type="datetime"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            placeholder="开始时间"
                        />
                        <el-date-picker
                            v-model="formData.end_time"
                            type="datetime"
                            value-format="YYYY-MM-DD HH:mm:ss"
                            placeholder="结束时间"
                        />
                    </div>
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="dialogVisible = false">取消</el-button>
                <el-button type="primary" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="webBanner">
import {
    deleteWebsiteBanner,
    getWebsiteBanners,
    saveWebsiteBanner,
    setWebsiteBannerStatus
} from '@/api/setting/website'
import feedback from '@/utils/feedback'

const loading = ref(false)
const dialogVisible = ref(false)
const banners = ref<any[]>([])
const appOptions = [
    { label: '图片生成', value: 'aigc_image' },
    { label: '视频生成', value: 'aigc_video' },
    { label: '数字人视频', value: 'aigc_digital_human' },
    { label: '无限画布', value: 'aigc_canvas' },
    { label: 'AIGC对话', value: 'aigc_llm' },
    { label: '全驱数字人', value: 'image_human' }
]

const defaultForm = () => ({
    id: '',
    title: '',
    description: '',
    media_type: 'image',
    media_uri: '',
    poster_uri: '',
    link_type: 'none',
    link_path: '',
    link_url: '',
    link_app_code: '',
    sort: 0,
    status: 1,
    start_time: '',
    end_time: ''
})
const formData = reactive(defaultForm())

const getData = async () => {
    loading.value = true
    try {
        banners.value = await getWebsiteBanners()
    } finally {
        loading.value = false
    }
}

const resetForm = (row?: Record<string, any>) => {
    Object.assign(formData, defaultForm(), row || {})
    formData.media_uri = row?.media_uri || row?.media_url || ''
    formData.poster_uri = row?.poster_uri || row?.poster_url || ''
}

const handleAdd = () => {
    resetForm()
    dialogVisible.value = true
}

const handleEdit = (row: Record<string, any>) => {
    resetForm(row)
    dialogVisible.value = true
}

const handleSubmit = async () => {
    await saveWebsiteBanner(formData)
    dialogVisible.value = false
    feedback.msgSuccess('保存成功')
    getData()
}

const handleDelete = async (row: Record<string, any>) => {
    await feedback.confirm('确定删除该轮播？')
    await deleteWebsiteBanner({ id: row.id })
    feedback.msgSuccess('删除成功')
    getData()
}

const handleStatus = async (row: Record<string, any>, value: boolean) => {
    await setWebsiteBannerStatus({ id: row.id, status: value ? 1 : 0 })
    getData()
}

const linkTypeText = (type: string) => ({
    none: '不跳转',
    path: '站内路径',
    url: '外链',
    app: '应用入口'
}[type] || '不跳转')

const linkTargetText = (row: Record<string, any>) => {
    if (row.link_type === 'path') return row.link_path
    if (row.link_type === 'url') return row.link_url
    if (row.link_type === 'app') {
        return appOptions.find((item) => item.value === row.link_app_code)?.label || row.link_app_code
    }
    return ''
}

getData()
</script>

<style lang="scss" scoped>
.banner-media {
    display: block;
    width: 120px;
    height: 72px;
    border-radius: 6px;
    object-fit: cover;
    background: var(--el-fill-color-light);
}
</style>
