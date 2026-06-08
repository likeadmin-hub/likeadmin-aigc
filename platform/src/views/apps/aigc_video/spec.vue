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
                    <el-segmented v-if="!useClearPricingTable" v-model="activeResolution" :options="resolutionOptions" />
                    <div class="batch-tools">
                        <span class="muted">当前分组 {{ currentDisplayCount }} 项</span>
                        <el-input-number v-model="batchRate" :min="0" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByRate">按倍率填充</el-button>
                        <el-input-number v-model="batchAdd" :precision="2" :step="0.1" controls-position="right" />
                        <el-button @click="fillByAdd">固定加价</el-button>
                        <el-button @click="setGroupStatus(1)">启用分组</el-button>
                        <el-button @click="setGroupStatus(0)">停用分组</el-button>
                        <el-button :loading="pricingLoading" @click="queryUpstreamPricing">查询当前模型上游价格</el-button>
                        <el-button :loading="pricingLoading" @click="queryAllUpstreamPricing">查询全部模型上游价格</el-button>
                        <el-button type="primary" :loading="saving" @click="saveCurrentGroup">保存当前分组</el-button>
                    </div>
                </div>

                <div v-loading="pagerLoading" class="matrix-wrap">
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
                        <el-table-column label="时长" min-width="190">
                            <template #default="{ row }">
                                <div class="clear-spec-title">{{ row.duration }}</div>
                            </template>
                        </el-table-column>
                        <el-table-column label="上游成本" width="190">
                            <template #default="{ row }">
                                <span class="readonly-price">{{ formatPoint(row.upstream_unit_cost, 2) }} 点</span>
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
                                <th class="duration-col">时长</th>
                                <th v-for="ratio in currentRatios" :key="ratio">{{ ratio }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="duration in currentDurations" :key="duration">
                                <th class="duration-col">{{ duration }}</th>
                                <td v-for="ratio in currentRatios" :key="`${duration}-${ratio}`">
                                    <div v-if="matrixMap[`${duration}|${ratio}`]" class="price-cell">
                                        <div class="price-line">
                                            <span>上游</span>
                                            <strong class="readonly-price">{{ formatPoint(matrixMap[`${duration}|${ratio}`].upstream_unit_cost, 2) }} 点</strong>
                                        </div>
                                        <div class="price-line">
                                            <span>供给</span>
                                            <el-input-number
                                                v-model="matrixMap[`${duration}|${ratio}`].platform_unit_cost"
                                                :min="0"
                                                :precision="2"
                                                :step="0.01"
                                                controls-position="right"
                                                @change="markDirty(matrixMap[`${duration}|${ratio}`])"
                                            />
                                        </div>
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
                                            <span :class="marginClass(platformMargin(matrixMap[`${duration}|${ratio}`]))">
                                                毛利 {{ formatPoint(platformMargin(matrixMap[`${duration}|${ratio}`]), 2) }}
                                            </span>
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
                <el-form-item label="上游成本">
                    <el-input :model-value="`${formatPoint(formData.upstream_unit_cost, 2)} 点`" disabled />
                </el-form-item>
                <el-form-item label="平台供给价">
                    <el-input-number v-model="formData.platform_unit_cost" :min="0" :precision="2" class="w-full" />
                </el-form-item>
                <el-form-item label="成本说明">
                    <el-input v-model="formData.upstream_cost_text" placeholder="如：上游 5 秒固定价 / 次" />
                </el-form-item>
                <el-form-item label="成本来源">
                    <el-input v-model="formData.cost_source_url" placeholder="https://..." />
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

        <el-dialog v-model="pricingVisible" title="上游价格" width="1120px" destroy-on-close class="upstream-pricing-dialog">
            <div v-if="pricingDialogTip" class="pricing-dialog-tip">
                {{ pricingDialogTip }}
            </div>
            <el-table :data="pricingDisplayRows" size="large" max-height="560" row-key="key" class="upstream-pricing-table">
                <el-table-column label="上游规格" min-width="210" fixed>
                    <template #default="{ row }">
                        <div class="pricing-spec-name">{{ row.title }}</div>
                        <div class="pricing-spec-meta">{{ row.subtitle }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="上游报价" min-width="330">
                    <template #default="{ row }">
                        <div class="pricing-quote" :class="{ 'is-error': !row.available }">
                            <el-tag :type="row.available ? 'success' : 'danger'" effect="light" size="small">
                                {{ row.available ? '可用' : '不可用' }}
                            </el-tag>
                            <span class="pricing-quote-main">{{ row.quote }}</span>
                        </div>
                        <div v-if="row.rulePreview" class="pricing-rule-preview">{{ row.rulePreview }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="计费方式" min-width="180">
                    <template #default="{ row }">
                        <div>{{ row.billingType }}</div>
                        <div class="pricing-spec-meta">{{ row.sourceName }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="本地规格" min-width="230">
                    <template #default="{ row }">
                        <div class="local-spec-summary">{{ row.localSummary }}</div>
                        <div v-if="row.localDetail" class="pricing-spec-meta">{{ row.localDetail }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="本地价格对照" min-width="240">
                    <template #default="{ row }">
                        <div class="pricing-compare">
                            <span>已录入上游成本</span>
                            <strong>{{ row.upstreamCostRange }}</strong>
                        </div>
                        <div class="pricing-compare">
                            <span>平台供给价</span>
                            <strong>{{ row.platformCostRange }}</strong>
                        </div>
                        <div class="pricing-compare">
                            <span>平台毛利</span>
                            <strong :class="marginClass(row.marginMin)">{{ row.marginRange }}</strong>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="成本说明" min-width="220" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.costText }}</template>
                </el-table-column>
                <el-table-column label="来源链接" min-width="180" show-overflow-tooltip>
                    <template #default="{ row }">{{ row.costSource }}</template>
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
const pricingDisplayRows = computed(() => buildPricingDisplayRows(pricingRows.value))
const pricingDialogTip = computed(() => buildPricingDialogTip(pricingRows.value, pricingDisplayRows.value))
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
const currentDurations = computed(() => uniqueSorted(currentGroupSpecs.value.map((item: any) => durationLabel(item))))
const currentRatios = computed(() => uniqueRatios(currentGroupSpecs.value.map((item: any) => item.ratio)))
const clearPricingSourceSpecs = computed(() => activeChannel.value?.specs || [])
const clearPricingRows = computed(() => buildClearPricingRows(clearPricingSourceSpecs.value))
const useClearPricingTable = computed(() => shouldUseClearPricingTable(clearPricingSourceSpecs.value, clearPricingRows.value))
const editableGroupSpecs = computed(() => useClearPricingTable.value ? clearPricingSourceSpecs.value : currentGroupSpecs.value)
const currentDisplayCount = computed(() => useClearPricingTable.value ? clearPricingRows.value.length : currentGroupSpecs.value.length)
const clearPricingRatios = computed(() => uniqueRatios(clearPricingSourceSpecs.value.map((item: any) => item.ratio)))
const clearPricingSummary = computed(() => {
    if (!useClearPricingTable.value || !clearPricingRatios.value.length) {
        return ''
    }
    return `同一清晰度和时长下，多比例共用同一计费价：${clearPricingRatios.value.join('、')}`
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
    editableGroupSpecs.value.forEach((row: any) => {
        row.platform_unit_cost = toPrice(platformCostBase(row) * Number(batchRate.value || 0))
        markDirty(row)
    })
}

const fillByAdd = () => {
    editableGroupSpecs.value.forEach((row: any) => {
        row.platform_unit_cost = toPrice(platformCostBase(row) + Number(batchAdd.value || 0))
        markDirty(row)
    })
}

const setGroupStatus = (status: number) => {
    editableGroupSpecs.value.forEach((row: any) => {
        row.status = status
        markDirty(row)
    })
}

const setClearRowField = (row: any, field: string, value: any) => {
    if (field === 'upstream_unit_cost') {
        return
    }
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
        await batchSaveAigcVideoSpecs({
            specs: rows.map(buildSaveSpecPayload)
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
        const payload = { ...formData } as Record<string, any>
        delete payload.upstream_unit_cost
        await saveAigcVideoSpec(payload)
        feedback.msgSuccess('保存成功')
        editVisible.value = false
        dirtyKeys.value = dirtyKeys.value.filter((key) => key !== `${formData.channel_code}|${formData.quality}|${formData.ratio}`)
        await getLists()
    } finally {
        saving.value = false
    }
}

const queryUpstreamPricing = () => queryUpstreamPricingRows(activeChannel.value?.specs || [], '当前模型暂无规格')
const queryAllUpstreamPricing = () => queryUpstreamPricingRows(visibleChannels.value.flatMap((channel: any) => channel.specs || []), '暂无可查询规格')

const queryUpstreamPricingRows = async (sourceRows: any[], emptyMessage: string) => {
    const rows = sourceRows.filter((row: any) => row && Number(row.status) === 1)
    if (!rows.length) {
        feedback.msgWarning(emptyMessage)
        return
    }
    pricingLoading.value = true
    rows.forEach((row: any) => {
        row.upstream_pricing = { loading: true }
    })
    try {
        const resultItems: any[] = []
        for (const chunk of chunkArray(rows, 80)) {
            const result = await getAigcVideoUpstreamPricingBatch({
                items: chunk.map(buildPricingQueryItem)
            })
            resultItems.push(...(result.items || []))
        }
        const localMap = Object.fromEntries(rows.map((row: any) => [specKey(row), row]))
        pricingRows.value = resultItems.map((item: any) => ({
            ...item,
            local: localMap[item.local_key] || {}
        }))
        pricingRows.value.forEach((item: any) => {
            if (item.local && Object.keys(item.local).length) {
                item.local.upstream_pricing = item
                applyUpstreamCostFromPricing(item.local, item)
            }
        })
        pricingVisible.value = true
        const syncedCount = rows.filter((row: any) => row.upstream_cost_from_pricing).length
        if (syncedCount > 0) {
            feedback.msgSuccess(`已同步 ${syncedCount} 项上游成本，请保存改动`)
        }
    } catch (e: any) {
        rows.forEach((row: any) => {
            row.upstream_pricing = { available: false, message: e?.message || '查询失败' }
        })
        feedback.msgError(e?.message || '查询失败')
    } finally {
        pricingLoading.value = false
    }
}

function buildPricingQueryItem(row: any) {
    return {
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
    }
}

function chunkArray<T>(items: T[], size: number): T[][] {
    const chunks: T[][] = []
    for (let index = 0; index < items.length; index += size) {
        chunks.push(items.slice(index, index + size))
    }
    return chunks
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
    const value = String(spec.provider_params_json?.resolution || spec.provider_params_json?.quality || spec.quality_label || spec.quality || '').toUpperCase()
    const matched = value.match(/1080P|720P|480P|4K|2K|1K/)
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
    const order = ['480P', '720P', '1080P', '1K', '2K', '4K', '其它规格']
    const index = order.indexOf(value)
    return index === -1 ? 90 : index
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
            .map((row: any) => {
                const points = skuPointValue(row)
                const priceText = points !== null ? `${formatPoint(points)} 点` : '未返回价格'
                return `${row.title || row.sku_key || 'SKU'} ${priceText}`
            })
            .join('，') + (pricingV2Items.length > 3 ? ` 等 ${pricingV2Items.length} 个 SKU` : '')
    }
    const fixed = upstreamPointValue(item)
    if (fixed !== null && fixed > 0) {
        return `${formatPoint(fixed)} 点 / 次`
    }
    return item.price_view?.formula || item.message || '-'
}

function buildClearPricingRows(specs: any[]) {
    const groups: Record<string, any[]> = {}
    specs.forEach((spec) => {
        const key = `${resolutionLabel(spec)}|${durationLabel(spec)}`
        if (!groups[key]) {
            groups[key] = []
        }
        groups[key].push(spec)
    })
    const rows = Object.entries(groups)
        .map(([key, items]) => {
            const first = items[0] || {}
            const upstream = mergedFieldValue(items, 'upstream_unit_cost')
            const platform = mergedFieldValue(items, 'platform_unit_cost')
            const resolution = resolutionLabel(first)
            return {
                key,
                resolution,
                duration: durationLabel(first),
                upstream_unit_cost: upstream,
                platform_unit_cost: platform,
                margin: platform - upstream,
                status: items.every((item) => Number(item.status) === 0) ? 0 : 1,
                items,
                resolutionRowspan: 1
            }
        })
        .sort((a, b) => {
            const resolutionDiff = resolutionWeight(a.resolution) - resolutionWeight(b.resolution)
            if (resolutionDiff !== 0) {
                return resolutionDiff
            }
            return Number(a.duration.match(/\d+/)?.[0] || 0) - Number(b.duration.match(/\d+/)?.[0] || 0)
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
        return hasSameFieldValue(items, 'upstream_unit_cost')
            && hasSameFieldValue(items, 'platform_unit_cost')
            && hasSameFieldValue(items, 'status')
    })
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

function buildPricingDisplayRows(rows: any[]) {
    if (!rows.length) {
        return []
    }
    const first = rows.find((row) => row.available) || rows[0]
    const pricingV2Items = first?.pricing_v2?.items || first?.raw?.pricing_v2?.items || []
    if (Array.isArray(pricingV2Items) && pricingV2Items.length) {
        const locals = rows.map((row) => row.local).filter(Boolean)
        return pricingV2Items.map((sku: any, index: number) => {
            const matchedLocals = locals.filter((local: any) => localMatchesSku(local, sku))
            return displayRowFromSku(sku, first, matchedLocals.length ? matchedLocals : locals, index)
        })
    }

    const groups: Record<string, any> = {}
    rows.forEach((row, index) => {
        const key = [
            row.available ? '1' : '0',
            row.price_view?.billing_type_desc || '',
            row.price_view?.formula || row.message || '',
            row.pricing_source?.name || '',
        ].join('|')
        if (!groups[key]) {
            groups[key] = {
                ...baseDisplayRow(row, [row.local].filter(Boolean), index),
                key: `quote-${index}`,
            }
        } else if (row.local) {
            groups[key].locals.push(row.local)
            decorateLocalSummary(groups[key])
        }
    })
    return Object.values(groups)
}

function buildPricingDialogTip(rows: any[], displayRows: any[]) {
    if (!rows.length || !displayRows.length) {
        return ''
    }
    const pricingV2Items = firstPricingV2Items(rows)
    if (!pricingV2Items.length) {
        return ''
    }
    const quality = firstLockedParam(pricingV2Items, 'quality') || firstLockedParam(pricingV2Items, 'resolution')
    const qualityText = quality ? `${quality} + 时长` : '上游计费 SKU'
    const isGrok = String(activeChannelCode.value || '').toLowerCase().includes('grok')
    if (isGrok) {
        return `Grok Video 上游按 ${qualityText} 计费，宽高比是请求参数，不单独影响上游报价；已合并为 ${displayRows.length} 个上游计费 SKU。`
    }
    return `已按 ${qualityText} 合并展示，本地规格只用于价格对照；当前共 ${displayRows.length} 个上游计费 SKU。`
}

function firstPricingV2Items(rows: any[]) {
    const matched = rows.find((row) => {
        const items = row?.pricing_v2?.items || row?.raw?.pricing_v2?.items || []
        return Array.isArray(items) && items.length
    })
    const items = matched?.pricing_v2?.items || matched?.raw?.pricing_v2?.items || []
    return Array.isArray(items) ? items : []
}

function firstLockedParam(skus: any[], key: string) {
    for (const sku of skus) {
        const params = sku?.locked_params || sku?.locked_params_json || {}
        const value = params?.[key]
        if (value !== undefined && value !== null && String(value).trim()) {
            return String(value)
        }
    }
    return ''
}

function displayRowFromSku(sku: any, source: any, locals: any[], index: number) {
    const points = skuPointValue(sku)
    const title = sku.title || sku.sku_key || `SKU ${index + 1}`
    const row = {
        key: `sku-${sku.sku_key || index}`,
        title,
        subtitle: skuSubtitle(sku),
        available: source.available,
        quote: points !== null ? `${formatPoint(points)} 点 / 次` : '未返回价格',
        rulePreview: lockedParamsText(sku.locked_params || sku.locked_params_json),
        billingType: source.price_view?.billing_type_desc || '清晰计费 SKU',
        sourceName: source.pricing_source?.name || '-',
        costText: '',
        costSource: source.source_base_url || '-',
        marginMin: 0,
        locals,
    }
    decorateLocalSummary(row)
    return row
}

function baseDisplayRow(row: any, locals: any[], index: number) {
    const display = {
        key: `row-${index}`,
        title: row.local ? `${row.local?.quality_label || row.local?.quality} / ${row.local?.ratio}` : '上游报价',
        subtitle: row.local ? `${row.local?.width || 0}×${row.local?.height || 0}` : '-',
        available: row.available,
        quote: upstreamPriceText(row),
        rulePreview: pricingRulePreview(row),
        billingType: row.price_view?.billing_type_desc || '-',
        sourceName: row.pricing_source?.name || '-',
        costText: '',
        costSource: row.source_base_url || '-',
        marginMin: 0,
        locals,
    }
    decorateLocalSummary(display)
    return display
}

function decorateLocalSummary(row: any) {
    const locals = row.locals || []
    const ratios = uniqueRatios(locals.map((local: any) => local.ratio).filter(Boolean))
    const durations = uniqueSorted(locals.map((local: any) => durationLabel(local)).filter(Boolean))
    row.localSummary = locals.length ? `匹配本地规格 ${locals.length} 项` : '未匹配本地规格'
    row.localDetail = [
        durations.length ? `时长 ${durations.join('、')}` : '',
        ratios.length > 1 ? `比例 ${ratios.length} 个：${ratios.join('、')}` : '',
        ratios.length === 1 ? `比例 ${ratios[0]}` : '',
    ].filter(Boolean).join('；')
    row.upstreamCostRange = pointRange(locals.map((local: any) => Number(local.upstream_unit_cost || 0)))
    row.platformCostRange = pointRange(locals.map((local: any) => Number(local.platform_unit_cost || 0)))
    const margins = locals.map((local: any) => Number(local.platform_unit_cost || 0) - Number(local.upstream_unit_cost || 0))
    row.marginRange = pointRange(margins)
    row.marginMin = margins.length ? Math.min(...margins) : 0
    row.costText = firstFilled(locals.map((local: any) => local.upstream_cost_text)) || '-'
    row.costSource = firstFilled(locals.map((local: any) => local.cost_source_url)) || row.costSource || '-'
}

function skuDuration(sku: any) {
    const params = sku.locked_params || sku.locked_params_json || {}
    const fromParams = Number(params.duration || params.seconds || 0)
    if (fromParams > 0) {
        return fromParams
    }
    const text = `${sku.title || ''} ${sku.sku_key || ''}`
    const matched = text.match(/(\d+)\s*(?:秒|s|sec|second)/i)
    return matched ? Number(matched[1]) : 0
}

function specDuration(spec: any) {
    const fromParams = Number(spec?.provider_params_json?.duration || 0)
    if (fromParams > 0) {
        return fromParams
    }
    const matched = String(spec?.quality_label || spec?.quality || '').match(/\d+/)
    return matched ? Number(matched[0]) : 0
}

function applyUpstreamCostFromPricing(local: any, pricing: any) {
    if (!local || !pricing?.available) {
        return
    }
    const points = upstreamPointValue(pricing, local)
    if (points === null || points <= 0) {
        return
    }
    const nextCost = toPrice(points)
    if (Number(local.upstream_unit_cost || 0) === nextCost) {
        return
    }
    local.upstream_unit_cost = nextCost
    local.upstream_cost_from_pricing = true
    local.upstream_cost_text = upstreamPriceText(pricing)
    local.cost_source_url = pricing.source_base_url || local.cost_source_url || ''
    if (Number(local.platform_unit_cost || 0) <= 0) {
        local.platform_unit_cost = nextCost
    }
    markDirty(local)
}

function buildSaveSpecPayload(row: any) {
    const payload: Record<string, any> = {
        channel_code: row.channel_code,
        quality: row.quality,
        ratio: row.ratio,
        platform_unit_cost: row.platform_unit_cost,
        upstream_cost_text: row.upstream_cost_text,
        cost_source_url: row.cost_source_url,
        status: row.status,
        sort: row.sort
    }
    if (row.upstream_cost_from_pricing) {
        payload.upstream_unit_cost = row.upstream_unit_cost
        payload.upstream_cost_from_pricing = true
    }
    return payload
}

function upstreamPointValue(item: any, local?: any): number | null {
    const pricingV2Items = item?.pricing_v2?.items || item?.raw?.pricing_v2?.items || []
    if (Array.isArray(pricingV2Items) && pricingV2Items.length) {
        const matched = local
            ? pricingV2Items.find((sku: any) => localMatchesSku(local, sku))
            : (pricingV2Items.length === 1 ? pricingV2Items[0] : null)
        return matched ? skuPointValue(matched) : null
    }
    return numericPoint([
        item?.pricing?.fixed_points,
        item?.pricing?.unit_points,
        item?.pricing?.points,
        item?.pricing?.price?.points,
        item?.raw?.pricing?.fixed_points,
        item?.raw?.pricing?.unit_points,
        item?.raw?.pricing?.points,
        item?.raw?.price?.points,
    ])
}

function skuPointValue(sku: any): number | null {
    return numericPoint([
        sku?.price?.points,
        sku?.price?.fixed_points,
        sku?.price?.unit_points,
        sku?.points,
        sku?.fixed_points,
        sku?.unit_points,
    ])
}

function numericPoint(values: any[]) {
    for (const value of values) {
        if (value === undefined || value === null || value === '') {
            continue
        }
        const number = Number(value)
        if (Number.isFinite(number) && number > 0) {
            return number
        }
    }
    return null
}

function localMatchesSku(local: any, sku: any) {
    const params = normalizeSkuParams(sku)
    const candidates = normalizeLocalSpecParams(local)
    const keys = Object.keys(params).filter((key) => params[key] !== '')
    if (!keys.length) {
        return false
    }
    return keys.every((key) => {
        const skuValue = params[key]
        if (key === 'duration') {
            return Number(candidates.duration || 0) === Number(skuValue || 0)
        }
        if (key === 'width' || key === 'height') {
            return Number(candidates[key] || 0) === Number(skuValue || 0)
        }
        if (key === 'resolution' || key === 'quality') {
            return normalizeToken(candidates.resolution) === normalizeToken(skuValue)
                || normalizeToken(candidates.quality) === normalizeToken(skuValue)
        }
        if (key === 'ratio' || key === 'aspect_ratio' || key === 'size') {
            return normalizeRatioToken(candidates.ratio) === normalizeRatioToken(skuValue)
                || normalizeRatioToken(candidates.aspect_ratio) === normalizeRatioToken(skuValue)
                || normalizeRatioToken(candidates.size) === normalizeRatioToken(skuValue)
        }
        return normalizeToken(candidates[key]) === normalizeToken(skuValue)
    })
}

function normalizeSkuParams(sku: any) {
    const raw = {
        ...(sku?.locked_params || {}),
        ...(sku?.locked_params_json || {})
    }
    const text = `${sku?.title || ''} ${sku?.sku_key || ''}`
    return cleanParams({
        quality: raw.quality,
        resolution: raw.resolution || raw.quality,
        duration: raw.duration || raw.seconds || skuDuration(sku) || '',
        ratio: raw.ratio || raw.aspect_ratio || raw.size,
        aspect_ratio: raw.aspect_ratio || raw.ratio || raw.size,
        size: raw.size || raw.ratio || raw.aspect_ratio,
        width: raw.width,
        height: raw.height,
        title: text
    })
}

function normalizeLocalSpecParams(spec: any) {
    const params = spec?.provider_params_json || {}
    return cleanParams({
        quality: spec?.quality || params.quality,
        resolution: params.resolution || params.quality || resolutionLabel(spec),
        duration: params.duration || specDuration(spec),
        ratio: spec?.ratio || params.ratio || params.aspect_ratio || params.size,
        aspect_ratio: params.aspect_ratio || params.ratio || spec?.ratio,
        size: params.size || params.ratio || params.aspect_ratio || spec?.ratio,
        width: spec?.width,
        height: spec?.height
    })
}

function cleanParams(params: Record<string, any>) {
    return Object.fromEntries(
        Object.entries(params).map(([key, value]) => [key, value === undefined || value === null ? '' : String(value).trim()])
    ) as Record<string, string>
}

function normalizeToken(value: any) {
    return String(value || '').trim().toLowerCase().replace(/\s+/g, '').replace(/_/g, '').replace(/-/g, '')
}

function normalizeRatioToken(value: any) {
    return normalizeToken(value).replace(/[：x*]/g, ':')
}

function skuSubtitle(sku: any) {
    const params = lockedParamsText(sku.locked_params || sku.locked_params_json)
    const mode = sku.external_billing_mode || sku.billing_mode || ''
    return [modeLabel(mode), params].filter(Boolean).join(' · ') || '上游 SKU'
}

function lockedParamsText(params: any) {
    if (!params || typeof params !== 'object' || Array.isArray(params)) {
        return ''
    }
    return Object.entries(params)
        .map(([key, value]) => `${paramName(key)}=${value}`)
        .join('，')
}

function modeLabel(mode: string) {
    const map: Record<string, string> = {
        fixed: '固定价',
        spec: '多规格价',
        usage: '按用量价',
    }
    return map[mode] || ''
}

function paramName(key: string) {
    const map: Record<string, string> = {
        quality: '清晰度',
        duration: '时长',
        aspect_ratio: '比例',
        ratio: '比例',
        size: '尺寸',
    }
    return map[key] || key
}

function pointRange(values: number[]) {
    const numbers = values.filter((value) => Number.isFinite(value))
    if (!numbers.length) {
        return '-'
    }
    const min = Math.min(...numbers)
    const max = Math.max(...numbers)
    return min === max ? `${formatPoint(min)} 点` : `${formatPoint(min)}-${formatPoint(max)} 点`
}

function firstFilled(values: any[]) {
    return values.map((value) => String(value || '').trim()).find(Boolean) || ''
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

.readonly-price {
    color: #1f2329;
    font-weight: 600;
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

.pricing-dialog-tip {
    margin-bottom: 12px;
    padding: 10px 12px;
    border: 1px solid #d9f0e8;
    border-radius: 6px;
    background: #f4fbf8;
    color: #24745c;
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

.local-spec-summary {
    color: #1f2329;
    font-weight: 600;
}
</style>
