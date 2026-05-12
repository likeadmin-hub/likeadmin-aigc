<template>
    <div class="aigc-table-page">
        <el-card class="!border-none table-card" shadow="never">
            <el-table v-loading="loading" size="large" :data="specRows" height="100%">
                <el-table-column label="通道" prop="channel_name" min-width="150" />
                <el-table-column label="质量" prop="quality_label" min-width="120" />
                <el-table-column label="比例" prop="ratio" min-width="100" />
                <el-table-column label="宽高" min-width="140">
                    <template #default="{ row }">{{ row.width }}×{{ row.height }}</template>
                </el-table-column>
                <el-table-column label="平台成本价" prop="platform_unit_cost" min-width="130" />
                <el-table-column label="Provider参数" min-width="260" show-overflow-tooltip>
                    <template #default="{ row }">{{ formatProviderParams(row.provider_params_json) }}</template>
                </el-table-column>
                <el-table-column label="排序" prop="sort" min-width="90" />
                <el-table-column label="状态" width="110" fixed="right">
                    <template #default="{ row }">
                        <el-switch
                            :model-value="row.status"
                            :active-value="1"
                            :inactive-value="0"
                            :loading="statusLoadingId === row.id"
                            @change="(value) => handleStatus(row, value)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openEdit(row)">编辑</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="editVisible" title="编辑规格价格" width="620px" destroy-on-close>
            <el-form label-width="110px" :model="formData">
                <el-form-item label="通道">
                    <el-input :model-value="channelNameMap[formData.channel_code] || formData.channel_code" disabled />
                </el-form-item>
                <el-form-item label="质量">
                    <el-input v-model="formData.quality_label" />
                </el-form-item>
                <el-form-item label="质量编码">
                    <el-input v-model="formData.quality" disabled />
                </el-form-item>
                <el-form-item label="比例">
                    <el-input v-model="formData.ratio" disabled />
                </el-form-item>
                <el-form-item label="宽高">
                    <div class="flex items-center gap-2">
                        <el-input-number v-model="formData.width" :min="0" />
                        <span>×</span>
                        <el-input-number v-model="formData.height" :min="0" />
                    </div>
                </el-form-item>
                <el-form-item label="平台成本价">
                    <el-input-number v-model="formData.platform_unit_cost" :min="0" :precision="2" class="w-full" />
                </el-form-item>
                <el-form-item label="Provider参数">
                    <el-input
                        v-model="providerParamsText"
                        type="textarea"
                        :rows="5"
                        placeholder='{"resolution":"1k","aspect_ratio":"1:1"}'
                    />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="formData.sort" class="w-full" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="editVisible = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSubmit">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-video-spec">
import { getAigcVideoSpecs, saveAigcVideoSpec, setAigcVideoSpecStatus } from '@/apps/aigc_video/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const editVisible = ref(false)
const statusLoadingId = ref(0)
const channels = ref<any[]>([])
const providerParamsText = ref('{}')
const formData = reactive({
    type: 'spec',
    channel_code: '',
    quality: '1k',
    quality_label: '普通1K',
    ratio: '1:1',
    width: 1024,
    height: 1024,
    platform_unit_cost: 4,
    tenant_unit_price: 4,
    provider_params_json: {},
    status: 1,
    sort: 0
})

const channelNameMap = computed(() =>
    channels.value.reduce((map, channel) => {
        map[channel.code] = channel.name
        return map
    }, {} as Record<string, string>)
)

const specRows = computed(() =>
    channels.value.flatMap((channel) =>
        (channel.specs || []).map((spec: any) => ({
            ...spec,
            channel_name: channel.name
        }))
    )
)

const formatProviderParams = (value: any) => {
    if (!value || !Object.keys(value).length) {
        return '{}'
    }
    return JSON.stringify(value)
}

const getLists = async () => {
    loading.value = true
    try {
        channels.value = await getAigcVideoSpecs()
    } finally {
        loading.value = false
    }
}

const openEdit = (row: any) => {
    Object.assign(formData, {
        type: 'spec',
        channel_code: row.channel_code,
        quality: row.quality,
        quality_label: row.quality_label,
        ratio: row.ratio,
        width: Number(row.width || 0),
        height: Number(row.height || 0),
        platform_unit_cost: Number(row.platform_unit_cost || 0),
        tenant_unit_price: Number(row.platform_unit_cost || 0),
        provider_params_json: row.provider_params_json || {},
        status: Number(row.status ?? 1),
        sort: Number(row.sort ?? 0)
    })
    providerParamsText.value = JSON.stringify(row.provider_params_json || {}, null, 2)
    editVisible.value = true
}

const handleSubmit = async () => {
    try {
        formData.provider_params_json = providerParamsText.value.trim() ? JSON.parse(providerParamsText.value) : {}
    } catch (e) {
        feedback.msgError('Provider参数必须是合法JSON')
        return
    }
    saving.value = true
    try {
        await saveAigcVideoSpec(formData)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        await getLists()
    } finally {
        saving.value = false
    }
}

const handleStatus = async (row: any, status: number) => {
    statusLoadingId.value = row.id
    try {
        await setAigcVideoSpecStatus({ id: row.id, status })
        row.status = status
        feedback.msgSuccess('设置成功')
    } finally {
        statusLoadingId.value = 0
    }
}

getLists()
</script>

<style scoped>
.aigc-table-page {
    height: calc(100vh - 118px);
}

.table-card {
    height: 100%;
}

:deep(.el-card__body) {
    height: 100%;
    padding: 0;
}
</style>
