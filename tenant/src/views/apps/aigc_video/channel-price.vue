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

            <el-empty v-if="!visibleChannels.length && !loading" description="暂无视频通道" />
            <template v-else>
                <div class="group-toolbar">
                    <el-segmented v-if="!useClearPricingTable" v-model="activeResolution" :options="resolutionOptions" />
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
                        :span-method="clearPricingSpanMethod"
                        border
                        size="small"
                        class="clear-price-table"
                        row-key="key"
                        empty-text="暂无规格"
                    >
                        <el-table-column label="清晰度" width="130">
                            <template #default="{ row }">
                                <div class="clear-spec-title">{{ row.resolution }}</div>
                            </template>
                        </el-table-column>
                        <el-table-column :label="clearPricingDimensionLabel" min-width="190">
                            <template #default="{ row }">
                                <div class="clear-spec-title">{{ row.duration }}</div>
                            </template>
                        </el-table-column>
                        <el-table-column :label="clearPricingPlatformLabel" width="160">
                            <template #default="{ row }">{{ clearPricingPointText(row.platform_unit_cost) }}</template>
                        </el-table-column>
                        <el-table-column label="我的销售价" width="190">
                            <template #default="{ row }">
                                <el-input-number
                                    :model-value="row.tenant_unit_price"
                                    :min="0"
                                    :precision="pricePrecision"
                                    :step="priceStep"
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
                                <th class="duration-col">时长</th>
                                <th v-for="ratio in currentRatios" :key="ratio">{{ ratio }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="duration in currentDurations" :key="duration">
                                <th class="duration-col">{{ duration }}</th>
                                <td v-for="ratio in currentRatios" :key="`${duration}-${ratio}`">
                                    <div v-if="matrixMap[`${duration}|${ratio}`]" class="price-cell">
                                        <div class="cost-line">
                                            <span>我的成本</span>
                                            <strong>{{ matrixMap[`${duration}|${ratio}`].platform_unit_cost }}</strong>
                                        </div>
                                        <el-input-number
                                            v-model="matrixMap[`${duration}|${ratio}`].tenant_unit_price"
                                            :min="0"
                                            :precision="pricePrecision"
                                            :step="priceStep"
                                            controls-position="right"
                                            @change="markDirty(matrixMap[`${duration}|${ratio}`])"
                                        />
                                        <div class="cell-meta">
                                            <span :class="marginClass(tenantMargin(matrixMap[`${duration}|${ratio}`]))">
                                                毛利 {{ formatPoint(tenantMargin(matrixMap[`${duration}|${ratio}`])) }}
                                            </span>
                                            <el-switch
                                                v-model="matrixMap[`${duration}|${ratio}`].tenant_status"
                                                :active-value="1"
                                                :inactive-value="0"
                                                @change="markDirty(matrixMap[`${duration}|${ratio}`])"
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

<script lang="ts" setup name="tenant-aigc-video-channel-price">
import { batchSaveAigcVideoChannels, getAigcVideoChannels } from '@/apps/aigc_video/api'
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
const currentDurations = computed(() => uniqueSorted(currentGroupSpecs.value.map((item: any) => durationLabel(item))))
const currentRatios = computed(() => uniqueRatios(currentGroupSpecs.value.map((item: any) => item.ratio)))
const clearPricingSourceSpecs = computed(() => activeChannel.value?.specs || [])
const clearPricingRows = computed(() => buildClearPricingRows(clearPricingSourceSpecs.value))
const useClearPricingTable = computed(() => shouldUseClearPricingTable(clearPricingSourceSpecs.value, clearPricingRows.value))
const editableGroupSpecs = computed(() => useClearPricingTable.value ? clearPricingSourceSpecs.value : currentGroupSpecs.value)
const currentDisplayCount = computed(() => useClearPricingTable.value ? clearPricingRows.value.length : currentGroupSpecs.value.length)
const clearPricingRatios = computed(() => uniqueRatios(clearPricingSourceSpecs.value.map((item: any) => item.ratio)))
const clearPricingDimensionLabel = computed(() => isSeedanceChannel() ? '输入类型' : '时长')
const clearPricingPlatformLabel = computed(() => isSeedanceChannel() ? '平台供给价 / 百万Token' : '平台供给价')
const pricePrecision = computed(() => String(activeChannelCode.value || '').toLowerCase() === 'happy_horse' ? 4 : 2)
const priceStep = computed(() => pricePrecision.value === 4 ? 0.001 : 0.01)
const clearPricingSummary = computed(() => {
    if (!useClearPricingTable.value || !clearPricingRatios.value.length) {
        return ''
    }
    if (isSeedanceChannel()) {
        return `Seedance 按清晰度和输入类型计费，时长只是用户提交参数；多比例共用同一销售价：${clearPricingRatios.value.join('、')}`
    }
    return `同一清晰度和时长下，多比例共用同一销售价：${clearPricingRatios.value.join('、')}`
})
const matrixMap = computed(() =>
    currentGroupSpecs.value.reduce((map: Record<string, any>, spec: any) => {
        map[`${durationLabel(spec)}|${spec.ratio}`] = spec
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
        channels.value = await getAigcVideoChannels()
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
    editableGroupSpecs.value.forEach((row: any) => {
        row.tenant_unit_price = toPrice(Number(row.platform_unit_cost || 0) * Number(batchRate.value || 0))
        markDirty(row)
    })
}

const fillByAdd = () => {
    editableGroupSpecs.value.forEach((row: any) => {
        row.tenant_unit_price = toPrice(Number(row.platform_unit_cost || 0) + Number(batchAdd.value || 0))
        markDirty(row)
    })
}

const setGroupStatus = (status: number) => {
    editableGroupSpecs.value.forEach((row: any) => {
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
        await batchSaveAigcVideoChannels({
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

const saveCurrentGroup = () => saveSpecs(dirtySpecs(editableGroupSpecs.value))
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
    const value = String(spec.provider_params_json?.resolution || spec.provider_params_json?.quality || spec.provider_params_json?.size || spec.quality_label || spec.quality || '').toUpperCase()
    const matched = value.match(/1080P|720P|480P|4K|2K|1K/)
    return matched?.[0] || '其它规格'
}

function durationLabel(spec: any) {
    const duration = explicitDurationNumber(spec)
    return duration > 0 ? `${duration}秒` : spec.quality_label || spec.quality
}

function explicitDurationNumber(spec: any) {
    const fromParams = Number(spec?.provider_params_json?.duration || 0)
    if (fromParams > 0) {
        return fromParams
    }
    const value = String(spec?.quality || spec?.quality_label || '').trim()
    const explicit = value.match(/(\d+)\s*(?:秒|s)\b/i)
    if (explicit) {
        return Number(explicit[1])
    }
    const underscored = value.match(/_(\d+)(?:\D*)$/)
    if (underscored) {
        return Number(underscored[1])
    }
    return /^\d+$/.test(value) ? Number(value) : 0
}

function uniqueSorted(values: string[]) {
    return Array.from(new Set(values)).sort((a, b) => Number(a.match(/\d+/)?.[0] || 0) - Number(b.match(/\d+/)?.[0] || 0))
}

function uniqueRatios(values: string[]) {
    return Array.from(new Set(values)).sort((a, b) => ratioWeight(a) - ratioWeight(b))
}

function ratioWeight(value: string) {
    const order = ['16:9', '9:16', '1:1', '4:3', '3:4', '2:3', '3:2']
    const index = order.indexOf(value)
    return index === -1 ? 99 : index
}

function resolutionWeight(value: string) {
    const order = ['480P', '720P', '1080P', '1K', '2K', '4K', '其它规格']
    const index = order.indexOf(value)
    return index === -1 ? 90 : index
}

function buildClearPricingRows(specs: any[]) {
    const groups: Record<string, any[]> = {}
    specs.forEach((spec) => {
        const key = clearPricingGroupKey(spec)
        if (!groups[key]) {
            groups[key] = []
        }
        groups[key].push(spec)
    })
    const rows = Object.entries(groups)
        .map(([key, items]) => {
            const first = items[0] || {}
            const platform = mergedFieldValue(items, 'platform_unit_cost')
            const tenant = mergedFieldValue(items, 'tenant_unit_price')
            const resolution = resolutionLabel(first)
            return {
                key,
                resolution,
                duration: clearPricingDimensionText(first),
                platform_unit_cost: platform,
                tenant_unit_price: tenant,
                margin: tenant - platform,
                tenant_status: items.every((item) => Number(item.tenant_status) === 0) ? 0 : 1,
                items,
                resolutionRowspan: 1
            }
        })
        .sort((a, b) => {
            const resolutionDiff = resolutionWeight(a.resolution) - resolutionWeight(b.resolution)
            if (resolutionDiff !== 0) {
                return resolutionDiff
            }
            return pricingVariantWeight(a.duration) - pricingVariantWeight(b.duration)
        })
    let index = 0
    while (index < rows.length) {
        let count = 1
        while (rows[index + count] && rows[index + count].resolution === rows[index].resolution) {
            count += 1
        }
        rows[index].resolutionRowspan = count
        for (let offset = 1; offset < count; offset += 1) {
            rows[index + offset].resolutionRowspan = 0
        }
        index += count
    }
    return rows
}

function shouldUseClearPricingTable(specs: any[], rows: any[]) {
    if (!specs.length || !rows.length) {
        return false
    }
    const channelText = `${activeChannelCode.value || ''} ${activeChannel.value?.name || ''}`.toLowerCase()
    if (channelText.includes('grok')) {
        return true
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

function clearPricingGroupKey(spec: any) {
    if (isSeedanceChannel()) {
        return `${resolutionLabel(spec)}|${pricingVariantToken(spec)}`
    }
    return `${resolutionLabel(spec)}|${durationLabel(spec)}`
}

function clearPricingDimensionText(spec: any) {
    if (isSeedanceChannel()) {
        return pricingVariantLabel(spec)
    }
    return durationLabel(spec)
}

function pricingVariantToken(spec: any) {
    return String(spec?.provider_params_json?._pricing_variant || spec?.provider_params_json?.pricing_variant || '').trim().toLowerCase().replace(/[\s_-]+/g, '')
}

function pricingVariantLabel(spec: any) {
    const token = pricingVariantToken(spec)
    if (token.includes('withvideo')) {
        return '含视频输入'
    }
    if (token.includes('withoutvideo') || token.includes('novideo')) {
        return '不含视频输入'
    }
    return '默认输入'
}

function pricingVariantWeight(value: string) {
    const token = String(value || '').trim().toLowerCase().replace(/[\s_-]+/g, '')
    if (token.includes('含视频') && !token.includes('不含')) {
        return 1
    }
    if (token.includes('withvideo')) {
        return 1
    }
    if (token.includes('不含') || token.includes('withoutvideo') || token.includes('novideo')) {
        return 2
    }
    return Number(String(value || '').match(/\d+/)?.[0] || 0)
}

function isSeedanceChannel() {
    return String(activeChannel.value?.code || '').toLowerCase() === 'seedance'
}

function clearPricingPointText(value: any) {
    return `${formatPoint(value)} 点${isSeedanceChannel() ? ' / 百万Token' : ''}`
}

function clearPricingSpanMethod({ row, columnIndex }: { row: any; columnIndex: number }) {
    if (columnIndex !== 0) {
        return { rowspan: 1, colspan: 1 }
    }
    return {
        rowspan: row.resolutionRowspan,
        colspan: row.resolutionRowspan > 0 ? 1 : 0
    }
}

function toPrice(value: number) {
    return Math.max(0, Number(value.toFixed(pricePrecision.value)))
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

.duration-col {
    width: 100px;
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
