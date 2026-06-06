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

            <el-empty v-if="!visibleChannels.length && !pagerLoading" description="暂无生图通道" />
            <template v-else>
                <div class="group-toolbar">
                    <el-segmented v-model="activeResolution" :options="resolutionOptions" />
                    <div class="batch-tools">
                        <span class="muted">当前分组 {{ currentDisplayCount }} 项</span>
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
                        <el-table-column label="上游成本" width="190">
                            <template #default="{ row }">
                                <el-input-number
                                    :model-value="row.upstream_unit_cost"
                                    :min="0"
                                    :precision="2"
                                    :step="0.01"
                                    controls-position="right"
                                    class="!w-full"
                                    @change="(value) => setClearRowField(row, 'upstream_unit_cost', value)"
                                />
                            </template>
                        </el-table-column>
                        <el-table-column label="平台供给价" width="190">
                            <template #default="{ row }">
                                <el-input-number
                                    :model-value="row.platform_unit_cost"
                                    :min="0"
                                    :precision="2"
                                    :step="0.01"
                                    controls-position="right"
                                    class="!w-full"
                                    @change="(value) => setClearRowField(row, 'platform_unit_cost', value)"
                                />
                            </template>
                        </el-table-column>
                        <el-table-column label="毛利" width="140">
                            <template #default="{ row }">
                                <span :class="marginClass(row.margin)">
                                    {{ formatPoint(row.margin, 2) }} 点
                                </span>
                            </template>
                        </el-table-column>
                        <el-table-column label="状态" width="100">
                            <template #default="{ row }">
                                <el-switch
                                    :model-value="row.status"
                                    :active-value="1"
                                    :inactive-value="0"
                                    @change="(value) => setClearRowField(row, 'status', value)"
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
                                        <div class="price-line">
                                            <span>上游</span>
                                            <el-input-number
                                                v-model="matrixMap[`${quality}|${ratio}`].upstream_unit_cost"
                                                :min="0"
                                                :precision="2"
                                                :step="0.01"
                                                controls-position="right"
                                                @change="markDirty(matrixMap[`${quality}|${ratio}`])"
                                            />
                                        </div>
                                        <div class="price-line">
                                            <span>供给</span>
                                            <el-input-number
                                                v-model="matrixMap[`${quality}|${ratio}`].platform_unit_cost"
                                                :min="0"
                                                :precision="2"
                                                :step="0.01"
                                                controls-position="right"
                                                @change="markDirty(matrixMap[`${quality}|${ratio}`])"
                                            />
                                        </div>
                                        <div
                                            v-if="matrixMap[`${quality}|${ratio}`].upstream_pricing"
                                            class="upstream-price"
                                            :class="{ 'is-error': isUpstreamPriceError(matrixMap[`${quality}|${ratio}`].upstream_pricing) }"
                                        >
                                            <span class="upstream-label">上游</span>
                                            <span class="upstream-value">{{ upstreamPriceText(matrixMap[`${quality}|${ratio}`].upstream_pricing) }}</span>
                                        </div>
                                        <div class="cell-meta">
                                            <span>{{ matrixMap[`${quality}|${ratio}`].width }}×{{ matrixMap[`${quality}|${ratio}`].height }}</span>
                                            <span :class="marginClass(platformMargin(matrixMap[`${quality}|${ratio}`]))">
                                                毛利 {{ formatPoint(platformMargin(matrixMap[`${quality}|${ratio}`]), 2) }}
                                            </span>
                                            <el-switch
                                                v-model="matrixMap[`${quality}|${ratio}`].status"
                                                :active-value="1"
                                                :inactive-value="0"
                                                @change="markDirty(matrixMap[`${quality}|${ratio}`])"
                                            />
                                            <el-button type="primary" link @click="openEdit(matrixMap[`${quality}|${ratio}`])">高级</el-button>
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
                <el-form-item label="上游成本">
                    <el-input-number v-model="formData.upstream_unit_cost" :min="0" :precision="2" class="w-full" />
                </el-form-item>
                <el-form-item label="平台供给价">
                    <el-input-number v-model="formData.platform_unit_cost" :min="0" :precision="2" class="w-full" />
                </el-form-item>
                <el-form-item label="成本说明">
                    <el-input v-model="formData.upstream_cost_text" placeholder="如：上游 4K 固定价 / 次" />
                </el-form-item>
                <el-form-item label="成本来源">
                    <el-input v-model="formData.cost_source_url" placeholder="https://..." />
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

        <el-dialog v-model="pricingVisible" title="上游价格" width="1160px" destroy-on-close class="upstream-pricing-dialog">
            <el-table :data="pricingRows" size="large" max-height="560" row-key="local_key" class="upstream-pricing-table">
                <el-table-column label="规格" min-width="210" fixed>
                    <template #default="{ row }">
                        <div class="pricing-spec-name">{{ row.local?.quality_label || row.local?.quality }} / {{ row.local?.ratio }}</div>
                        <div class="pricing-spec-meta">{{ row.local?.width || 0 }}×{{ row.local?.height || 0 }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="上游报价" min-width="330">
                    <template #default="{ row }">
                        <div class="pricing-quote" :class="{ 'is-error': !row.available }">
                            <el-tag :type="row.available ? 'success' : 'danger'" effect="light" size="small">
                                {{ row.available ? '可用' : '不可用' }}
                            </el-tag>
                            <span class="pricing-quote-main">{{ upstreamPriceText(row) }}</span>
                        </div>
                        <div v-if="pricingRulePreview(row)" class="pricing-rule-preview">{{ pricingRulePreview(row) }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="计费方式" min-width="180">
                    <template #default="{ row }">
                        <div>{{ row.price_view?.billing_type_desc || '-' }}</div>
                        <div class="pricing-spec-meta">{{ row.pricing_source?.name || '-' }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="本地价格对照" min-width="260">
                    <template #default="{ row }">
                        <div class="pricing-compare">
                            <span>已录入上游成本</span>
                            <strong>{{ formatPoint(row.local?.upstream_unit_cost || 0, 6) }} 点</strong>
                        </div>
                        <div class="pricing-compare">
                            <span>平台供给价</span>
                            <strong>{{ formatPoint(row.local?.platform_unit_cost || 0, 6) }} 点</strong>
                        </div>
                        <div class="pricing-compare">
                            <span>平台毛利</span>
                            <strong :class="marginClass(localMargin(row))">{{ formatPoint(localMargin(row), 6) }} 点</strong>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="成本说明" min-width="220" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.local?.upstream_cost_text || '-' }}</template>
                </el-table-column>
                <el-table-column label="来源链接" min-width="180" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.local?.cost_source_url || row.source_base_url || '-' }}</template>
                </el-table-column>
            </el-table>
            <template #footer>
                <el-button type="primary" @click="pricingVisible = false">知道了</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="platform-aigc-image-spec">
import { batchSaveAigcImageSpecs, getAigcImageSpecs, getAigcImageUpstreamPricingBatch, saveAigcImageSpec } from '@/apps/aigc_image/api'
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
    upstream_unit_cost: 0,
    platform_unit_cost: 0,
    tenant_unit_price: 0,
    upstream_cost_text: '',
    cost_source_url: '',
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
const currentQualities = computed(() => uniqueSorted(currentGroupSpecs.value.map((item: any) => qualityLabel(item))))
const currentRatios = computed(() => uniqueRatios(currentGroupSpecs.value.map((item: any) => item.ratio)))
const clearPricingRows = computed(() => buildClearPricingRows(currentGroupSpecs.value))
const useClearPricingTable = computed(() => shouldUseClearPricingTable(currentGroupSpecs.value, clearPricingRows.value))
const currentDisplayCount = computed(() => useClearPricingTable.value ? clearPricingRows.value.length : currentGroupSpecs.value.length)
const clearPricingSummary = computed(() => {
    if (!useClearPricingTable.value || !currentRatios.value.length) {
        return ''
    }
    return `多比例共用同一计费价：${currentRatios.value.join('、')}`
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
    pagerLoading.value = true
    try {
        channels.value = await getAigcImageSpecs()
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
        row.platform_unit_cost = toPrice(platformCostBase(row) * Number(batchRate.value || 0))
        markDirty(row)
    })
}

const fillByAdd = () => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.platform_unit_cost = toPrice(platformCostBase(row) + Number(batchAdd.value || 0))
        markDirty(row)
    })
}

const setGroupStatus = (status: number) => {
    currentGroupSpecs.value.forEach((row: any) => {
        row.status = status
        markDirty(row)
    })
}

const setClearRowField = (row: any, field: string, value: any) => {
    const normalized = field === 'status' ? Number(value) : toPrice(Number(value || 0))
    ;(row.items || []).forEach((item: any) => {
        item[field] = normalized
        markDirty(item)
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
        await batchSaveAigcImageSpecs({
            specs: rows.map((row) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                ratio: row.ratio,
                upstream_unit_cost: row.upstream_unit_cost,
                platform_unit_cost: row.platform_unit_cost,
                upstream_cost_text: row.upstream_cost_text,
                cost_source_url: row.cost_source_url,
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
        upstream_unit_cost: Number(row.upstream_unit_cost || 0),
        platform_unit_cost: Number(row.platform_unit_cost || 0),
        tenant_unit_price: Number(row.platform_unit_cost || 0),
        upstream_cost_text: row.upstream_cost_text || '',
        cost_source_url: row.cost_source_url || '',
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
        await saveAigcImageSpec(formData)
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
        const result = await getAigcImageUpstreamPricingBatch({
            items: rows.map((row: any) => ({
                channel_code: row.channel_code,
                quality: row.quality,
                quality_label: row.quality_label,
                ratio: row.ratio,
                width: row.width,
                height: row.height,
                resolution: row.provider_params_json?.resolution || row.provider_params_json?.size || resolutionLabel(row),
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

function toPrice(value: number) {
    return Math.max(0, Number(value.toFixed(2)))
}

function platformCostBase(row: any) {
    return Number(row.upstream_unit_cost || row.platform_unit_cost || 0)
}

function platformMargin(row: any) {
    return Number(row.platform_unit_cost || 0) - Number(row.upstream_unit_cost || 0)
}

function marginClass(value: number) {
    return value >= 0 ? 'margin-plus' : 'margin-minus'
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
    const pricingV2Items = item.pricing_v2?.items || item.raw?.pricing_v2?.items || []
    if (Array.isArray(pricingV2Items) && pricingV2Items.length) {
        return pricingV2Items
            .slice(0, 3)
            .map((row: any) => `${row.title || row.sku_key || 'SKU'} ${formatPoint(row.price?.points || 0)} 点`)
            .join('，') + (pricingV2Items.length > 3 ? ` 等 ${pricingV2Items.length} 个 SKU` : '')
    }
    const fixed = Number(item.pricing?.fixed_points ?? item.pricing?.unit_points ?? item.pricing?.points ?? 0)
    if (fixed > 0) {
        return `${formatPoint(fixed)} 点 / 次`
    }
    return item.price_view?.formula || item.message || '-'
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
            const upstream = mergedFieldValue(items, 'upstream_unit_cost')
            const platform = mergedFieldValue(items, 'platform_unit_cost')
            return {
                key,
                title: qualityLabel(first),
                meta: resolutionLabel(first),
                upstream_unit_cost: upstream,
                platform_unit_cost: platform,
                margin: platform - upstream,
                status: items.every((item) => Number(item.status) === 0) ? 0 : 1,
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
        return hasSameFieldValue(items, 'upstream_unit_cost')
            && hasSameFieldValue(items, 'platform_unit_cost')
            && hasSameFieldValue(items, 'status')
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

function pricingRulePreview(item: any) {
    const rules = item?.price_view?.rules
    if (!Array.isArray(rules) || !rules.length) {
        return ''
    }
    return rules
        .slice(0, 4)
        .map((rule: any) => {
            const label = rule.label || rule.type || '规则'
            const value = rule.value || (rule.points !== undefined ? `${formatPoint(rule.points)} 点` : '')
            return value ? `${label}：${value}` : label
        })
        .join('；') + (rules.length > 4 ? `；等 ${rules.length} 条` : '')
}

function localMargin(row: any) {
    return Number(row.local?.platform_unit_cost || 0) - Number(row.local?.upstream_unit_cost || 0)
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

.resolution-col {
    width: 120px;
    background: #fafafa;
}

.price-cell {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 230px;
}

.price-line {
    display: grid;
    grid-template-columns: 34px minmax(0, 1fr);
    align-items: center;
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

.margin-plus {
    color: #18a058;
}

.margin-minus {
    color: #d03050;
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

.pricing-spec-name {
    color: #1f2329;
    font-weight: 600;
}

.pricing-spec-meta,
.pricing-rule-preview {
    margin-top: 4px;
    color: #86909c;
    font-size: 12px;
    line-height: 18px;
}

.pricing-quote {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    color: #24745c;
    line-height: 22px;
}

.pricing-quote.is-error {
    color: #d03050;
}

.pricing-quote-main {
    min-width: 0;
    font-weight: 600;
    word-break: break-word;
}

.pricing-compare {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    color: #667085;
    line-height: 24px;
}

.pricing-compare strong {
    color: #1f2329;
    font-weight: 600;
}

.pricing-compare strong.margin-plus {
    color: #18a058;
}

.pricing-compare strong.margin-minus {
    color: #d03050;
}
</style>
