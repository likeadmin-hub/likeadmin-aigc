<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-lg font-medium">规格价格</div>
                    <div class="text-sm text-tx-secondary mt-1">按输入媒体计费时长折算剪辑成本和租户默认售价。</div>
                </div>
                <el-button type="primary" :loading="saving" @click="saveAll">保存全部改动</el-button>
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="specRows">
                <el-table-column label="通道" min-width="180">
                    <template #default="{ row }">{{ channelNameMap[row.channel_code] || row.channel_code }}</template>
                </el-table-column>
                <el-table-column label="计费单位" min-width="120">
                    <template #default="{ row }">{{ row.quality_label || `${row.unit_seconds || row.quality}秒计费` }}</template>
                </el-table-column>
                <el-table-column label="上游成本/单位" min-width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="row.upstream_unit_cost"
                            :min="0"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            @change="markDirty(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="平台供给价/单位" min-width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="row.platform_unit_cost"
                            :min="0"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            @change="markDirty(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="租户默认售价/单位" min-width="180">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="row.tenant_unit_price"
                            :min="0"
                            :precision="2"
                            :step="0.01"
                            controls-position="right"
                            @change="markDirty(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="平台毛利" min-width="120">
                    <template #default="{ row }">
                        <span :class="marginClass(Number(row.platform_unit_cost || 0) - Number(row.upstream_unit_cost || 0))">
                            {{ formatPoint(Number(row.platform_unit_cost || 0) - Number(row.upstream_unit_cost || 0)) }}
                        </span>
                    </template>
                </el-table-column>
                <el-table-column label="租户默认毛利" min-width="130">
                    <template #default="{ row }">
                        <span :class="marginClass(Number(row.tenant_unit_price || 0) - Number(row.platform_unit_cost || 0))">
                            {{ formatPoint(Number(row.tenant_unit_price || 0) - Number(row.platform_unit_cost || 0)) }}
                        </span>
                    </template>
                </el-table-column>
                <el-table-column label="成本说明" min-width="220">
                    <template #default="{ row }">
                        <el-input v-model="row.upstream_cost_text" placeholder="上游计费说明" @change="markDirty(row)" />
                    </template>
                </el-table-column>
                <el-table-column label="状态" width="120">
                    <template #default="{ row }">
                        <el-switch
                            v-model="row.status"
                            :active-value="1"
                            :inactive-value="0"
                            @change="markDirty(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="排序" width="150">
                    <template #default="{ row }">
                        <el-input-number
                            v-model="row.sort"
                            :step="1"
                            controls-position="right"
                            @change="markDirty(row)"
                        />
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="120" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link :loading="savingKey === rowKey(row)" @click="saveRows([row])">保存</el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="platform-smart-clip-spec">
import { batchSaveSmartClipSpecs, getSmartClipSpecs } from '@/apps/smart_clip/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const savingKey = ref('')
const channels = ref<any[]>([])
const dirtyKeys = ref<string[]>([])

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
            channel_name: channel.name,
        }))
    )
)

const rowKey = (row: any) => `${row.channel_code}|${row.quality}|${row.ratio || 'duration'}`
const markDirty = (row: any) => {
    const key = rowKey(row)
    if (!dirtyKeys.value.includes(key)) {
        dirtyKeys.value = [...dirtyKeys.value, key]
    }
}

const getLists = async () => {
    loading.value = true
    try {
        channels.value = await getSmartClipSpecs()
        dirtyKeys.value = []
    } finally {
        loading.value = false
    }
}

const saveRows = async (rows: any[]) => {
    if (!rows.length) {
        feedback.msgWarning('暂无改动需要保存')
        return
    }
    saving.value = true
    savingKey.value = rows.length === 1 ? rowKey(rows[0]) : ''
    try {
        await batchSaveSmartClipSpecs({
            specs: rows.map((row) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                ratio: row.ratio || 'duration',
                upstream_unit_cost: row.upstream_unit_cost,
                platform_unit_cost: row.platform_unit_cost,
                tenant_unit_price: row.tenant_unit_price,
                upstream_cost_text: row.upstream_cost_text,
                cost_source_url: row.cost_source_url,
                status: row.status,
                sort: row.sort,
            })),
        })
        feedback.msgSuccess('保存成功')
        dirtyKeys.value = dirtyKeys.value.filter((key) => !rows.some((row) => rowKey(row) === key))
        await getLists()
    } finally {
        saving.value = false
        savingKey.value = ''
    }
}

const saveAll = () => {
    const keys = new Set(dirtyKeys.value)
    saveRows(specRows.value.filter((row) => keys.has(rowKey(row))))
}

function formatPoint(value: any) {
    const number = Number(value || 0)
    return number.toFixed(2).replace(/\.?0+$/, '') || '0'
}

function marginClass(value: number) {
    return value >= 0 ? 'margin-plus' : 'margin-minus'
}

getLists()
</script>

<style scoped>
.margin-plus {
    color: #18a058;
}

.margin-minus {
    color: #d03050;
}
</style>
