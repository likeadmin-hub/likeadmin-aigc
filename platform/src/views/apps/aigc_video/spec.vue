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

            <el-empty v-if="!visibleChannels.length && !pagerLoading" description="暂无视频通道" />
            <template v-else>
                <div class="group-toolbar">
                    <el-segmented v-model="activeResolution" :options="resolutionOptions" />
                    <div class="batch-tools">
                        <span class="muted">当前分组 {{ currentGroupSpecs.length }} 项</span>
                        <el-input-number v-model="batchRate" :min="0" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByRate">按倍率填充</el-button>
                        <el-input-number v-model="batchAdd" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByAdd">固定加价</el-button>
                        <el-button @click="setGroupStatus(1)">启用分组</el-button>
                        <el-button @click="setGroupStatus(0)">停用分组</el-button>
                        <el-button :loading="pricingLoading" @click="queryUpstreamPricing">查询上游价格</el-button>
                        <el-button type="primary" :loading="saving" @click="saveCurrentGroup">保存当前分组</el-button>
                    </div>
                </div>

                <div v-loading="pagerLoading" class="matrix-wrap">
                    <table class="price-matrix">
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
                                        <el-input-number
                                            v-model="matrixMap[`${duration}|${ratio}`].platform_unit_cost"
                                            :min="0"
                                            :precision="2"
                                            :step="0.01"
                                            controls-position="right"
                                            @change="markDirty(matrixMap[`${duration}|${ratio}`])"
                                        />
                                        <div
                                            v-if="matrixMap[`${duration}|${ratio}`].upstream_pricing"
                                            class="upstream-price"
                                            :class="{ 'is-error': isUpstreamPriceError(matrixMap[`${duration}|${ratio}`].upstream_pricing) }"
                                        >
                                            <span class="upstream-label">上游</span>
                                            <span class="upstream-value">{{ upstreamPriceText(matrixMap[`${duration}|${ratio}`].upstream_pricing) }}</span>
                                        </div>
                                        <div class="cell-meta">
                                            <span>{{ matrixMap[`${duration}|${ratio}`].width }}×{{ matrixMap[`${duration}|${ratio}`].height }}</span>
                                            <el-switch
                                                v-model="matrixMap[`${duration}|${ratio}`].status"
                                                :active-value="1"
                                                :inactive-value="0"
                                                @change="markDirty(matrixMap[`${duration}|${ratio}`])"
                                            />
                                            <el-button type="primary" link @click="openEdit(matrixMap[`${duration}|${ratio}`])">高级</el-button>
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

        <el-dialog v-model="editVisible" title="高级编辑" width="620px" destroy-on-close>
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
                <el-form-item label="平台定价">
                    <el-input-number v-model="formData.platform_unit_cost" :min="0" :precision="2" class="w-full" />
                </el-form-item>
                <el-form-item label="Provider参数">
                    <el-input
                        v-model="providerParamsText"
                        type="textarea"
                        :rows="5"
                        placeholder='{"resolution":"720P","duration":5,"ratio":"16:9"}'
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

        <el-dialog v-model="pricingVisible" title="上游价格" width="920px" destroy-on-close>
            <el-table :data="pricingRows" size="large" max-height="520">
                <el-table-column label="规格" min-width="180">
                    <template #default="{ row }">{{ row.local?.quality_label || row.local?.quality }} / {{ row.local?.ratio }}</template>
                </el-table-column>
                <el-table-column label="宽高" min-width="110">
                    <template #default="{ row }">{{ row.local?.width || 0 }}×{{ row.local?.height || 0 }}</template>
                </el-table-column>
                <el-table-column label="本地平台定价" min-width="120">
                    <template #default="{ row }">{{ row.local?.platform_unit_cost || 0 }}</template>
                </el-table-column>
                <el-table-column label="上游状态" min-width="100">
                    <template #default="{ row }">{{ row.available ? '可用' : '不可用' }}</template>
                </el-table-column>
                <el-table-column label="计费方式" min-width="140">
                    <template #default="{ row }">{{ row.price_view?.billing_type_desc || '-' }}</template>
                </el-table-column>
                <el-table-column label="上游价格" min-width="220" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.price_view?.formula || row.message || '-' }}</template>
                </el-table-column>
                <el-table-column label="来源" min-width="120">
                    <template #default="{ row }">{{ row.pricing_source?.name || '-' }}</template>
                </el-table-column>
            </el-table>
            <template #footer>
                <el-button type="primary" @click="pricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-video-spec">
import { batchSaveAigcVideoSpecs, getAigcVideoSpecs, getAigcVideoUpstreamPricingBatch, saveAigcVideoSpec } from '@/apps/aigc_video/api'
import feedback from '@/utils/feedback'

const saving = ref(false)
const pagerLoading = ref(false)
const editVisible = ref(false)
const pricingVisible = ref(false)
const pricingLoading = ref(false)
const showDisabled = ref(false)
const channels = ref<any[]>([])
const activeChannelCode = ref('')
const activeResolution = ref('')
const batchRate = ref(1)
const batchAdd = ref(0)
const dirtyKeys = ref<string[]>([])
const pricingRows = ref<any[]>([])
const providerParamsText = ref('{}')
const formData = reactive({
    type: 'spec',
    channel_code: '',
    quality: '',
    quality_label: '',
    ratio: '',
    width: 0,
    height: 0,
    platform_unit_cost: 0,
    tenant_unit_price: 0,
    provider_params_json: {},
    status: 1,
    sort: 0
})

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
const channelNameMap = computed(() =>
    channels.value.reduce((map, channel) => {
        map[channel.code] = channel.name
        return map
    }, {} as Record<string, string>)
)
const resolutionGroups = computed(() => groupSpecs(activeChannel.value?.specs || []))
const resolutionOptions = computed(() => resolutionGroups.value.map((group) => ({ label: group.label, value: group.key })))
const activeGroup = computed(() => resolutionGroups.value.find((group) => group.key === activeResolution.value) || resolutionGroups.value[0] || { specs: [] })
const currentGroupSpecs = computed(() => activeGroup.value.specs || [])
const currentDurations = computed(() => uniqueSorted(currentGroupSpecs.value.map((item: any) => durationLabel(item))))
const currentRatios = computed(() => uniqueRatios(currentGroupSpecs.value.map((item: any) => item.ratio)))
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
    pagerLoading.value = true
    try {
        channels.value = await getAigcVideoSpecs()
    } finally {
        pagerLoading.value = false
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
        row.platform_unit_cost = toPrice(Number(row.platform_unit_cost || 0) * Number(batchRate.value || 0))
        markDirty(row)
    })
}

const fillByAdd = () => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.platform_unit_cost = toPrice(Number(row.platform_unit_cost || 0) + Number(batchAdd.value || 0))
        markDirty(row)
    })
}

const setGroupStatus = (status: number) => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.status = status
        markDirty(row)
    })
}

const dirtySpecs = (scope: any[] = allSpecs()) => {
    const keySet = new Set(dirtyKeys.value)
    return scope.filter((row) => keySet.has(specKey(row)))
}

const allSpecs = () => channels.value.flatMap((channel) => channel.specs || [])
const saveSpecs = async (rows: any[]) => {
    if (!rows.length) {
        feedback.msgWarning('暂无改动需要保存')
        return
    }
    saving.value = true
    try {
        await batchSaveAigcVideoSpecs({
            specs: rows.map((row) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                ratio: row.ratio,
                platform_unit_cost: row.platform_unit_cost,
                status: row.status,
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
        dirtyKeys.value = dirtyKeys.value.filter((key) => key !== `${formData.channel_code}|${formData.quality}|${formData.ratio}`)
        await getLists()
    } finally {
        saving.value = false
    }
}

const queryUpstreamPricing = async () => {
    const rows = currentGroupSpecs.value
    if (!rows.length) {
        feedback.msgWarning('当前分组暂无规格')
        return
    }
    pricingLoading.value = true
    rows.forEach((row: any) => {
        row.upstream_pricing = { loading: true }
    })
    try {
        const result = await getAigcVideoUpstreamPricingBatch({
            items: rows.map((row: any) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                quality_label: row.quality_label,
                ratio: row.ratio,
                width: row.width,
                height: row.height,
                resolution: row.provider_params_json?.resolution || resolutionLabel(row),
                duration: row.provider_params_json?.duration || durationLabel(row).replace(/\D+/g, ''),
                provider_params: row.provider_params_json || {},
                provider_params_json: row.provider_params_json || {},
                local_key: specKey(row)
            }))
        })
        const localMap = Object.fromEntries(rows.map((row: any) => [specKey(row), row]))
        pricingRows.value = (result.items || []).map((item: any) => ({
            ...item,
            local: localMap[item.local_key] || {}
        }))
        pricingRows.value.forEach((item: any) => {
            if (item.local && Object.keys(item.local).length) {
                item.local.upstream_pricing = item
            }
        })
        pricingVisible.value = true
    } catch (e: any) {
        rows.forEach((row: any) => {
            row.upstream_pricing = { available: false, message: e?.message || '查询失败' }
        })
        feedback.msgError(e?.message || '查询失败')
    } finally {
        pricingLoading.value = false
    }
}

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
    const value = String(spec.provider_params_json?.resolution || spec.quality_label || spec.quality || '').toUpperCase()
    const matched = value.match(/1080P|720P|4K|2K|1K/)
    return matched?.[0] || '其它规格'
}

function durationLabel(spec: any) {
    const value = String(spec.provider_params_json?.duration || spec.quality_label || spec.quality || '')
    const matched = value.match(/(?:^|_|\s)(\d+)(?:S|秒)?/i)
    return matched ? `${Number(matched[1])}秒` : spec.quality_label || spec.quality
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
    const order = ['720P', '1080P', '1K', '2K', '4K', '其它规格']
    const index = order.indexOf(value)
    return index === -1 ? 90 : index
}

function toPrice(value: number) {
    return Math.max(0, Number(value.toFixed(2)))
}

function formatPoint(value: any, precision = 6) {
    const number = Number(value)
    if (!Number.isFinite(number)) {
        return ''
    }
    return number.toFixed(precision).replace(/\.?0+$/, '')
}

function upstreamPriceText(item: any) {
    if (!item) {
        return '-'
    }
    if (item.loading) {
        return '查询中'
    }
    if (!item.available) {
        return item.message ? `不可用：${item.message}` : '不可用'
    }
    const fixed = Number(item.pricing?.fixed_points ?? item.pricing?.unit_points ?? item.pricing?.points ?? 0)
    if (fixed > 0) {
        return `${formatPoint(fixed)} 点 / 次`
    }
    return item.price_view?.formula || item.message || '-'
}

function isUpstreamPriceError(item: any) {
    return item && !item.loading && !item.available
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
    min-width: 980px;
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
    min-width: 190px;
}

.cell-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #8c8c8c;
    font-size: 13px;
}

.upstream-price {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    min-height: 22px;
    color: #24745c;
    font-size: 12px;
    line-height: 18px;
}

.upstream-price.is-error {
    color: #d03050;
}

.upstream-label {
    flex: none;
    color: #8c8c8c;
}

.upstream-value {
    min-width: 0;
    word-break: break-all;
}

.empty-cell {
    color: #bbb;
}
</style>
