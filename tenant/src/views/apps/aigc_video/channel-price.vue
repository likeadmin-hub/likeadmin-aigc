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
                    <el-segmented v-model="activeResolution" :options="resolutionOptions" />
                    <div class="batch-tools">
                        <span class="muted">当前分组 {{ currentGroupSpecs.length }} 项</span>
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
                                            v-model="matrixMap[`${duration}|${ratio}`].tenant_unit_price"
                                            :min="0"
                                            :precision="2"
                                            :step="0.01"
                                            controls-position="right"
                                            @change="markDirty(matrixMap[`${duration}|${ratio}`])"
                                        />
                                        <div class="cell-meta">
                                            <span>成本 {{ matrixMap[`${duration}|${ratio}`].platform_unit_cost }}</span>
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
</style>
