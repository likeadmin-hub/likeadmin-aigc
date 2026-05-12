<template>
    <el-card class="!border-none" shadow="never">
        <el-table v-loading="loading" size="large" :data="specRows">
            <el-table-column label="通道" prop="channel_name" width="130" />
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
</template>

<script lang="ts" setup name="tenant-aigc-video-channel-price">
import { getAigcVideoChannels, saveAigcVideoChannel } from '@/apps/aigc_video/api'

const loading = ref(false)
const channels = ref<any[]>([])
const specRows = computed(() =>
    channels.value.flatMap((channel) =>
        (channel.specs || []).map((spec: any) => ({
            ...spec,
            channel_name: channel.name,
            channel_status: channel.tenant_status
        }))
    )
)
const getLists = async () => {
    loading.value = true
    try {
        channels.value = await getAigcVideoChannels()
    } finally {
        loading.value = false
    }
}
const saveSpec = async (row: any) => {
    await saveAigcVideoChannel({
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
