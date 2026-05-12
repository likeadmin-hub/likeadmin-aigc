<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="模型名称">
                    <el-input v-model="queryParams.model_name" class="w-[220px]" placeholder="请输入模型名称" clearable />
                </el-form-item>
                <el-form-item label="模型类型">
                    <el-input v-model="queryParams.model_type" class="w-[220px]" placeholder="请输入模型类型" clearable />
                </el-form-item>
                <el-form-item label="是否启动">
                    <el-select v-model="queryParams.status" class="w-[160px]" clearable>
                        <el-option label="全部" value="" />
                        <el-option label="已启动" value="1" />
                        <el-option label="未启动" value="0" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="getLists">查询</el-button>
                    <el-button @click="resetQuery">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="filteredSpecRows">
                <el-table-column label="模型名称" prop="channel_name" min-width="160" />
                <el-table-column label="模型类型" prop="channel_model" min-width="160" show-overflow-tooltip />
                <el-table-column label="质量" prop="quality_label" width="110" />
                <el-table-column label="比例" prop="ratio" width="90" />
                <el-table-column label="宽高" width="130">
                    <template #default="{ row }">{{ row.width }}×{{ row.height }}</template>
                </el-table-column>
                <el-table-column label="平台成本" prop="platform_unit_cost" width="110" />
                <el-table-column label="用户售价" width="180">
                    <template #default="{ row }">
                        <el-input-number v-model="row.tenant_unit_price" :min="0" :precision="2" />
                    </template>
                </el-table-column>
                <el-table-column label="启用" width="90">
                    <template #default="{ row }">
                        <el-switch v-model="row.tenant_status" :active-value="1" :inactive-value="0" @change="saveSpec(row)" />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="saveSpec(row)">保存</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-image-channel-price">
import { getAigcImageChannels, saveAigcImageChannel } from '@/apps/aigc_image/api'

const loading = ref(false)
const channels = ref<any[]>([])
const queryParams = reactive({
    model_name: '',
    model_type: '',
    status: ''
})

const specRows = computed(() =>
    channels.value.flatMap((channel) =>
        (channel.specs || []).map((spec: any) => ({
            ...spec,
            channel_name: channel.name,
            channel_model: channel.model,
            channel_status: channel.tenant_status
        }))
    )
)
const filteredSpecRows = computed(() => {
    const modelName = queryParams.model_name.trim().toLowerCase()
    const modelType = queryParams.model_type.trim().toLowerCase()
    return specRows.value.filter((row) => {
        if (modelName && !String(row.channel_name || '').toLowerCase().includes(modelName)) {
            return false
        }
        if (modelType && !String(row.channel_model || '').toLowerCase().includes(modelType)) {
            return false
        }
        if (queryParams.status !== '' && String(row.tenant_status) !== queryParams.status) {
            return false
        }
        return true
    })
})
const getLists = async () => {
    loading.value = true
    try {
        channels.value = await getAigcImageChannels()
    } finally {
        loading.value = false
    }
}
const resetQuery = () => {
    Object.assign(queryParams, {
        model_name: '',
        model_type: '',
        status: ''
    })
}
const saveSpec = async (row: any) => {
    await saveAigcImageChannel({
        type: 'spec',
        channel_code: row.channel_code,
        quality: row.quality,
        ratio: row.ratio,
        tenant_unit_price: row.tenant_unit_price,
        status: row.tenant_status
    })
    getLists()
}
getLists()
</script>
