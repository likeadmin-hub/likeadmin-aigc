<template>
    <div class="aigc-price-page">
        <el-card class="!border-none price-card" shadow="never">
            <div class="price-header">
                <div class="channel-area">
                    <el-tabs v-model="activeChannelCode" class="channel-tabs">
                        <el-tab-pane
                            v-for="channel in visibleChannels"
                            :key="channel.code"
                            :label="`${channel.name}（${channel.specs?.length || 0}）`"
                            :name="channel.code"
                        />
                    </el-tabs>
                    <el-switch v-model="showDisabled" active-text="显示停用项" />
                </div>
                <el-button :loading="saving" type="primary" @click="saveAll">保存全部改动</el-button>
            </div>

            <el-empty v-if="!visibleChannels.length && !loading" description="暂无生图通道" />
            <template v-else>
                <div class="group-toolbar">
                    <el-segmented v-model="activeResolution" :options="resolutionOptions" />
                    <div class="batch-tools">
                        <span class="muted">当前分组 {{ currentDisplayCount }} 项</span>
                        <el-input-number v-model="batchRate" :min="0" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByRate">按成本倍率填充</el-button>
                        <el-input-number v-model="batchAdd" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByAdd">成本加价</el-button>
                        <el-button @click="setGroupStatus(1)">启用分组</el-button>
                        <el-button @click="setGroupStatus(0)">停用分组</el-button>
                        <el-button type="primary" :loading="saving" @click="saveCurrentGroup">保存当前分组</el-button>
                    </div>
                </div>

                <div v-loading="loading" class="matrix-wrap">
                    <el-table
                        v-if="useClearPricingTable"
                        :data="clearPricingRows"
                        border
                        size="small"
                        class="clear-price-table"
                        row-key="key"
                        empty-text="暂无规格"
                    >
                        <el-table-column label="规格" min-width="320">
                            <template #default="{ row }">
                                <div class="clear-spec-cell">
                                    <div class="clear-spec-title">{{ row.title }}</div>
                                    <div class="clear-spec-meta">{{ row.meta }}</div>
                                </div>
                            </template>
                        </el-table-column>
                        <el-table-column label="平台供给价" width="160">
                            <template #default="{ row }">{{ formatPoint(row.platform_unit_cost) }} 点</template>
                        </el-table-column>
                        <el-table-column label="我的销售价" width="190">
                            <template #default="{ row }">
                                <el-input-number
                                    :model-value="row.tenant_unit_price"
                                    :min="0"
                                    :precision="2"
                                    :step="0.01"
                                    controls-position="right"
                                    class="!w-full"
                                    @change="(value) => setClearRowField(row, 'tenant_unit_price', value)"
                                />
                            </template>
                        </el-table-column>
                        <el-table-column label="毛利" width="140">
                            <template #default="{ row }">
                                <span :class="marginClass(row.margin)">
                                    {{ formatPoint(row.margin) }} 点
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column label="状态" width="100">
                            <template #default="{ row }">
                                <el-switch
                                    :model-value="row.tenant_status"
                                    :active-value="1"
                                    :inactive-value="0"
                                    @change="(value) => setClearRowField(row, 'tenant_status', value)"
                                />
                            </template>
                        </el-table-column>
                    </el-table>
                    <div v-if="useClearPricingTable && clearPricingSummary" class="clear-price-summary">
                        {{ clearPricingSummary }}
                    </div>
                    <table v-if="!useClearPricingTable" class="price-matrix">
                        <thead>
                            <tr>
                                <th class="resolution-col">分辨率</th>
                                <th v-for="ratio in currentRatios" :key="ratio">{{ ratio }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="quality in currentQualities" :key="quality">
                                <th class="resolution-col">{{ quality }}</th>
                                <td v-for="ratio in currentRatios" :key="`${quality}-${ratio}`">
                                    <div v-if="matrixMap[`${quality}|${ratio}`]" class="price-cell">
                                        <div class="cost-line">
                                            <span>我的成本</span>
                                            <strong>{{ matrixMap[`${quality}|${ratio}`].platform_unit_cost }}</strong>
                                        </div>
                                        <el-input-number
                                            v-model="matrixMap[`${quality}|${ratio}`].tenant_unit_price"
                                            :min="0"
                                            :precision="2"
                                            :step="0.01"
                                            controls-position="right"
                                            @change="markDirty(matrixMap[`${quality}|${ratio}`])"
                                        />
                                        <div class="cell-meta">
                                            <span :class="marginClass(tenantMargin(matrixMap[`${quality}|${ratio}`]))">
                                                毛利 {{ formatPoint(tenantMargin(matrixMap[`${quality}|${ratio}`])) }}
                                            </span>
                                            <el-switch
                                                v-model="matrixMap[`${quality}|${ratio}`].tenant_status"
                                                :active-value="1"
                                                :inactive-value="0"
                                                @change="markDirty(matrixMap[`${quality}|${ratio}`])"
                                            />
                                        </div>
                                    </div>
                                    <span v-else class="empty-cell">-</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="tenant-aigc-image-channel-price">
import { batchSaveAigcImageChannels, getAigcImageChannels } from '@/apps/aigc_image/api'
import feedback from '@/utils/feedback'

const loading = ref(false)
const saving = ref(false)
const showDisabled = ref(false)
const channels = ref<any[]>([])
const activeChannelCode = ref('')
const activeResolution = ref('')
const batchRate = ref(1)
const batchAdd = ref(0)
const dirtyKeys = ref<string[]>([])

const visibleChannels = computed(() =>
    channels.value
        .map((channel) => ({
            ...channel,
            specs: (channel.specs || []).filter((spec: any) => showDisabled.value || Number(spec.status) === 1)
        }))
        .filter((channel) => showDisabled.value || Number(channel.status) === 1)
        .filter((channel) => showDisabled.value || (channel.specs || []).length)
)
const activeChannel = computed(() => visibleChannels.value.find((item) => item.code === activeChannelCode.value) || visibleChannels.value[0] || {})
const resolutionGroups = computed(() => groupSpecs(activeChannel.value?.specs || []))
const resolutionOptions = computed(() => resolutionGroups.value.map((group) => ({ label: group.label, value: group.key })))
const activeGroup = computed(() => resolutionGroups.value.find((group) => group.key === activeResolution.value) || resolutionGroups.value[0] || { specs: [] })
const currentGroupSpecs = computed(() => activeGroup.value.specs || [])
const currentQualities = computed(() => uniqueSorted(currentGroupSpecs.value.map((item: any) => qualityLabel(item))))
const currentRatios = computed(() => uniqueRatios(currentGroupSpecs.value.map((item: any) => item.ratio)))
const clearPricingRows = computed(() => buildClearPricingRows(currentGroupSpecs.value))
const useClearPricingTable = computed(() => shouldUseClearPricingTable(currentGroupSpecs.value, clearPricingRows.value))
const currentDisplayCount = computed(() => useClearPricingTable.value ? clearPricingRows.value.length : currentGroupSpecs.value.length)
const clearPricingSummary = computed(() => {
    if (!useClearPricingTable.value || !currentRatios.value.length) {
        return ''
    }
    return `多比例共用同一销售价：${currentRatios.value.join('、')}`
})
const matrixMap = computed(() =>
    currentGroupSpecs.value.reduce((map: Record<string, any>, spec: any) => {
        map[`${qualityLabel(spec)}|${spec.ratio}`] = spec
        return map
    }, {})
)

watch(
    () => visibleChannels.value.map((item) => item.code).join('|'),
    () => {
        if (!visibleChannels.value.some((item) => item.code === activeChannelCode.value)) {
            activeChannelCode.value = visibleChannels.value[0]?.code || ''
        }
    },
    { immediate: true }
)

watch(
    () => activeChannelCode.value,
    () => {
        activeResolution.value = resolutionOptions.value[0]?.value || ''
    }
)

watch(
    () => resolutionOptions.value.map((item) => item.value).join('|'),
    () => {
        if (!resolutionOptions.value.some((item) => item.value === activeResolution.value)) {
            activeResolution.value = resolutionOptions.value[0]?.value || ''
        }
    }
)

const getLists = async () => {
    loading.value = true
    try {
        channels.value = await getAigcImageChannels()
    } finally {
        loading.value = false
    }
}

const specKey = (row: any) => `${row.channel_code}|${row.quality}|${row.ratio}`
const markDirty = (row: any) => {
    const key = specKey(row)
    if (!dirtyKeys.value.includes(key)) {
        dirtyKeys.value = [...dirtyKeys.value, key]
    }
}

const fillByRate = () => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.tenant_unit_price = toPrice(Number(row.platform_unit_cost || 0) * Number(batchRate.value || 0))
        markDirty(row)
    })
}

const fillByAdd = () => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.tenant_unit_price = toPrice(Number(row.platform_unit_cost || 0) + Number(batchAdd.value || 0))
        markDirty(row)
    })
}

const setGroupStatus = (status: number) => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.tenant_status = status
        markDirty(row)
    })
}

const setClearRowField = (row: any, field: string, value: any) => {
    const normalized = field === 'tenant_status' ? Number(value) : toPrice(Number(value || 0))
    ;(row.items || []).forEach((item: any) => {
        item[field] = normalized
        markDirty(item)
    })
}

const allSpecs = () => channels.value.flatMap((channel) => channel.specs || [])
const dirtySpecs = (scope: any[] = allSpecs()) => {
    const keySet = new Set(dirtyKeys.value)
    return scope.filter((row) => keySet.has(specKey(row)))
}

const saveSpecs = async (rows: any[]) => {
    if (!rows.length) {
        feedback.msgWarning('暂无改动需要保存')
        return
    }
    saving.value = true
    try {
        await batchSaveAigcImageChannels({
            specs: rows.map((row) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                ratio: row.ratio,
                tenant_unit_price: row.tenant_unit_price,
                status: row.tenant_status,
                sort: row.sort
            }))
        })
        feedback.msgSuccess('保存成功')
        dirtyKeys.value = dirtyKeys.value.filter((key) => !rows.some((row) => specKey(row) === key))
        await getLists()
    } finally {
        saving.value = false
    }
}

const saveCurrentGroup = () => saveSpecs(dirtySpecs(currentGroupSpecs.value))
const saveAll = () => saveSpecs(dirtySpecs())

function groupSpecs(specs: any[]) {
    const map: Record<string, any> = {}
    specs.forEach((spec) => {
        const key = resolutionLabel(spec)
        if (!map[key]) {
            map[key] = { key, label: key, specs: [] }
        }
        map[key].specs.push(spec)
    })
    return Object.values(map).sort((a: any, b: any) => resolutionWeight(a.key) - resolutionWeight(b.key))
}

function resolutionLabel(spec: any) {
    const value = String(spec.provider_params_json?.resolution || spec.provider_params_json?.size || spec.quality_label || spec.quality || '').toUpperCase()
    const matched = value.match(/1080P|720P|4K|2K|1K|\d+K/)
    return matched?.[0] || '其它规格'
}

function qualityLabel(spec: any) {
    return spec.quality_label || spec.quality
}

function uniqueSorted(values: string[]) {
    return Array.from(new Set(values)).sort((a, b) => resolutionWeight(a) - resolutionWeight(b))
}

function uniqueRatios(values: string[]) {
    return Array.from(new Set(values)).sort((a, b) => ratioWeight(a) - ratioWeight(b))
}

function ratioWeight(value: string) {
    const order = ['1:1', '16:9', '9:16', '4:3', '3:4', '2:3', '3:2']
    const index = order.indexOf(value)
    return index === -1 ? 99 : index
}

function resolutionWeight(value: string) {
    const normalized = String(value || '').toUpperCase()
    const order = ['720P', '1080P', '1K', '2K', '4K', '其它规格']
    const index = order.indexOf(normalized)
    if (index !== -1) {
        return index
    }
    const size = Number(normalized.match(/\d+/)?.[0] || 0)
    return size ? 20 + size : 90
}

function buildClearPricingRows(specs: any[]) {
    const groups: Record<string, any[]> = {}
    specs.forEach((spec) => {
        const key = qualityLabel(spec)
        if (!groups[key]) {
            groups[key] = []
        }
        groups[key].push(spec)
    })
    return Object.entries(groups)
        .map(([key, items]) => {
            const first = items[0] || {}
            const platform = mergedFieldValue(items, 'platform_unit_cost')
            const tenant = mergedFieldValue(items, 'tenant_unit_price')
            return {
                key,
                title: qualityLabel(first),
                meta: resolutionLabel(first),
                platform_unit_cost: platform,
                tenant_unit_price: tenant,
                margin: tenant - platform,
                tenant_status: items.every((item) => Number(item.tenant_status) === 0) ? 0 : 1,
                items
            }
        })
        .sort((a, b) => resolutionWeight(a.key) - resolutionWeight(b.key))
}

function shouldUseClearPricingTable(specs: any[], rows: any[]) {
    if (!specs.length || rows.length >= specs.length) {
        return false
    }
    return rows.every((row) => {
        const items = row.items || []
        return hasSameFieldValue(items, 'platform_unit_cost')
            && hasSameFieldValue(items, 'tenant_unit_price')
            && hasSameFieldValue(items, 'tenant_status')
    })
}

function mergedFieldValue(items: any[], field: string) {
    const first = items[0]
    return Number(first?.[field] || 0)
}

function hasSameFieldValue(items: any[], field: string) {
    const values = new Set(items.map((item) => String(item?.[field] ?? '')))
    return values.size <= 1
}

function toPrice(value: number) {
    return Math.max(0, Number(value.toFixed(2)))
}

function tenantMargin(row: any) {
    return Number(row.tenant_unit_price || 0) - Number(row.platform_unit_cost || 0)
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
.aigc-price-page {
    height: calc(100vh - 118px);
}

.price-card {
    height: 100%;
}

:deep(.el-card__body) {
    display: flex;
    flex-direction: column;
    gap: 16px;
    height: 100%;
    padding: 16px 20px;
}

.price-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.channel-area {
    display: flex;
    flex: 1;
    min-width: 0;
    align-items: center;
    gap: 16px;
}

.channel-tabs {
    flex: 1;
    min-width: 0;
}

.group-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.batch-tools {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.muted {
    color: #8c8c8c;
    font-size: 13px;
}

.matrix-wrap {
    flex: 1;
    overflow: auto;
    border: 1px solid #edf0f5;
}

.price-matrix {
    width: 100%;
    min-width: 920px;
    border-collapse: collapse;
}

.price-matrix th,
.price-matrix td {
    border-bottom: 1px solid #edf0f5;
    border-right: 1px solid #edf0f5;
    padding: 14px;
    text-align: left;
    vertical-align: top;
}

.price-matrix thead th {
    background: #f7f8fa;
    color: #333;
    font-weight: 600;
}

.resolution-col {
    width: 120px;
    background: #fafafa;
}

.price-cell {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 180px;
}

.cost-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #667085;
    font-size: 12px;
}

.cell-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #8c8c8c;
    font-size: 13px;
}

.empty-cell {
    color: #bbb;
}

.clear-price-table {
    --el-table-border-color: #e5e7eb;
}

.clear-spec-cell {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 42px;
}

.clear-spec-title {
    color: #1f2329;
    font-weight: 600;
}

.clear-spec-meta {
    margin-top: 4px;
    color: #86909c;
    font-size: 12px;
}

.clear-price-summary {
    padding: 10px 0 0;
    color: #86909c;
    font-size: 13px;
}

.margin-plus {
    color: #18a058;
}

.margin-minus {
    color: #d03050;
}
</style>
