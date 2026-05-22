<template>
    <div class="workbench">
        <el-card class="!border-none dashboard-card mb-4" shadow="never">
            <template #header>
                <div class="flex items-center justify-between">
                    <span class="card-title">系统概览</span>
                    <span class="text-tx-secondary text-xs">全局运营数据</span>
                </div>
            </template>
            <div class="dashboard-grid">
                <div class="dashboard-cell dashboard-cell--meta">
                    <div class="text-tx-secondary text-xs">平台名称</div>
                    <div class="dashboard-meta-value">{{ workbenchData.version.name || '-' }}</div>
                </div>
                <div class="dashboard-cell dashboard-cell--meta">
                    <div class="text-tx-secondary text-xs">当前版本</div>
                    <div class="dashboard-meta-value">{{ workbenchData.version.version || '-' }}</div>
                </div>
                <div class="dashboard-cell dashboard-cell--meta">
                    <div class="text-tx-secondary text-xs">接口渠道</div>
                    <div class="dashboard-channel">
                        <el-tag :type="interfaceChannel.configured ? 'success' : 'warning'">
                            {{ interfaceChannel.configured ? '已配置' : '未配置' }}
                        </el-tag>
                        <el-button type="primary" size="small" @click="handleInterfaceChannel">
                            {{ interfaceChannel.configured ? '进入算力中心' : '去配置' }}
                        </el-button>
                    </div>
                </div>
                <div class="dashboard-cell dashboard-cell--meta">
                    <div class="text-tx-secondary text-xs">更新时间</div>
                    <div class="dashboard-meta-value">{{ workbenchData.today.time || '-' }}</div>
                </div>
                <div v-for="item in topCards" :key="item.title" class="dashboard-cell">
                    <div class="text-tx-secondary text-xs">{{ item.title }}</div>
                    <div class="metric-value">
                        {{ item.value }}
                        <span>{{ item.unit }}</span>
                    </div>
                    <div class="text-tx-secondary text-xs">
                        {{ item.sub_title }}：{{ item.sub_value }}
                    </div>
                </div>
            </div>
        </el-card>

        <el-card class="!border-none mb-4" shadow="never">
            <template #header>
                <span class="card-title">常用功能</span>
            </template>
            <div class="quick-grid">
                <router-link v-for="item in workbenchData.menu" :key="item.name" :to="item.url" class="quick-item">
                    <image-contain width="34px" height="34px" :src="item?.image" />
                    <span>{{ item.name }}</span>
                </router-link>
            </div>
        </el-card>

        <div class="chart-grid mb-4">
            <el-card class="!border-none" shadow="never">
                <template #header>
                    <span class="card-title">近15天任务趋势</span>
                </template>
                <v-charts ref="taskChart" class="chart" :option="taskTrendOption" :autoresize="true" />
            </el-card>
            <el-card class="!border-none" shadow="never">
                <template #header>
                    <span class="card-title">点数消耗 / 用户收费</span>
                </template>
                <v-charts ref="pointChart" class="chart" :option="pointTrendOption" :autoresize="true" />
            </el-card>
        </div>

        <div class="bottom-grid">
            <el-card class="!border-none" shadow="never">
                <template #header>
                    <span class="card-title">应用用量</span>
                </template>
                <el-table :data="workbenchData.app_stats" size="large">
                    <el-table-column prop="app_name" label="应用" min-width="130" />
                    <el-table-column prop="task_total" label="任务" min-width="90" />
                    <el-table-column prop="success_total" label="成功" min-width="90" />
                    <el-table-column prop="failed_total" label="失败" min-width="90" />
                    <el-table-column prop="tenant_cost_points" label="点数消耗" min-width="110" />
                    <el-table-column prop="user_charge_points" label="用户收费" min-width="110" />
                </el-table>
            </el-card>
            <el-card class="!border-none" shadow="never">
                <template #header>
                    <span class="card-title">应用排行</span>
                </template>
                <div class="rank-block">
                    <div class="rank-title">按用户收费</div>
                    <div v-for="item in chargeRanking" :key="`charge-${item.app_code}`" class="rank-row">
                        <span>{{ item.app_name }}</span>
                        <el-progress :percentage="rankPercent(item.user_charge_points, maxCharge)" :show-text="false" />
                        <b>{{ item.user_charge_points }}</b>
                    </div>
                </div>
                <div class="rank-block mt-5">
                    <div class="rank-title">按任务量</div>
                    <div v-for="item in taskRanking" :key="`task-${item.app_code}`" class="rank-row">
                        <span>{{ item.app_name }}</span>
                        <el-progress :percentage="rankPercent(item.task_total, maxTask)" :show-text="false" />
                        <b>{{ item.task_total }}</b>
                    </div>
                </div>
            </el-card>
        </div>
    </div>
</template>

<script lang="ts" setup name="workbench">
import { useDark } from '@vueuse/core'
import vCharts from 'vue-echarts'

import { getWorkbench } from '@/api/app'
import useSettingStore from '@/stores/modules/setting'
import { useComponentRef } from '@/utils/getExposeType'
import { calcColor } from '@/utils/util'

const router = useRouter()
const settingStore = useSettingStore()
const taskChart = useComponentRef(vCharts)
const pointChart = useComponentRef(vCharts)
const isDark = useDark()
const themeColor = ref<string>(isDark.value ? '#ffffff' : settingStore.subTheme)
const textColor = ref<string>(isDark.value ? '#d6d8df' : '#606266')
const axisColor = ref<string>(isDark.value ? '#3f424c' : '#e5e7eb')

const workbenchData: any = reactive({
    version: {
        version: '',
        name: '',
        channel: {
            interface: {
                url: '',
                configured: false,
                route: '/update/channel'
            }
        }
    },
    today: {},
    summary_cards: [],
    menu: [],
    trend: {
        date: [],
        task_total: [],
        success_total: [],
        failed_total: [],
        tenant_cost_points: [],
        user_charge_points: []
    },
    app_stats: [],
    ranking: {
        by_charge: [],
        by_task: []
    }
})

const interfaceChannel = computed(
    () =>
        workbenchData.version?.channel?.interface || {
            url: '',
            configured: false,
            route: '/update/channel'
        }
)
const summaryCards = computed(() => workbenchData.summary_cards || [])
const topCards = computed(() => summaryCards.value.slice(0, 8))
const chargeRanking = computed(() => workbenchData.ranking?.by_charge || [])
const taskRanking = computed(() => workbenchData.ranking?.by_task || [])
const maxCharge = computed(() => Math.max(...chargeRanking.value.map((item: any) => Number(item.user_charge_points || 0)), 0))
const maxTask = computed(() => Math.max(...taskRanking.value.map((item: any) => Number(item.task_total || 0)), 0))

const commonGrid = computed(() => ({
    top: 34,
    left: 40,
    right: 18,
    bottom: 34
}))

const taskTrendOption = computed(() => ({
    color: [themeColor.value, '#16a34a', '#ef4444'],
    grid: commonGrid.value,
    legend: {
        data: ['任务量', '成功量', '失败量'],
        textStyle: { color: textColor.value }
    },
    tooltip: { trigger: 'axis' },
    xAxis: {
        type: 'category',
        data: workbenchData.trend.date,
        axisLine: { lineStyle: { color: axisColor.value } },
        axisLabel: { color: textColor.value }
    },
    yAxis: {
        type: 'value',
        axisLabel: { color: textColor.value },
        splitLine: { lineStyle: { color: axisColor.value } }
    },
    series: [
        { name: '任务量', data: workbenchData.trend.task_total, type: 'line', smooth: true, lineStyle: { width: 2 } },
        { name: '成功量', data: workbenchData.trend.success_total, type: 'line', smooth: true, lineStyle: { width: 2 } },
        { name: '失败量', data: workbenchData.trend.failed_total, type: 'line', smooth: true, lineStyle: { width: 2 } }
    ]
}))

const pointTrendOption = computed(() => ({
    color: [
        {
            type: 'linear',
            x: 0,
            y: 0,
            x2: 0,
            y2: 1,
            colorStops: [
                { offset: 0, color: calcColor(themeColor.value, 0.72) },
                { offset: 1, color: themeColor.value }
            ]
        },
        '#14b8a6'
    ],
    grid: commonGrid.value,
    legend: {
        data: ['点数消耗', '用户收费'],
        textStyle: { color: textColor.value }
    },
    tooltip: { trigger: 'axis' },
    xAxis: {
        type: 'category',
        data: workbenchData.trend.date,
        axisLine: { lineStyle: { color: axisColor.value } },
        axisLabel: { color: textColor.value }
    },
    yAxis: {
        type: 'value',
        axisLabel: { color: textColor.value },
        splitLine: { lineStyle: { color: axisColor.value } }
    },
    series: [
        { name: '点数消耗', data: workbenchData.trend.tenant_cost_points, type: 'bar', barWidth: '34%' },
        { name: '用户收费', data: workbenchData.trend.user_charge_points, type: 'bar', barWidth: '34%' }
    ]
}))

watch(
    () => settingStore.mode,
    (mode) => {
        themeColor.value = mode === 'light' ? settingStore.subTheme : '#ffffff'
        textColor.value = mode === 'light' ? '#606266' : '#d6d8df'
        axisColor.value = mode === 'light' ? '#e5e7eb' : '#3f424c'
        updateCharts()
    }
)

const getData = async () => {
    try {
        const res: any = await getWorkbench()
        workbenchData.version = res.version || workbenchData.version
        workbenchData.today = res.today || {}
        workbenchData.summary_cards = res.summary_cards || []
        workbenchData.menu = res.menu || []
        workbenchData.trend = res.trend || workbenchData.trend
        workbenchData.app_stats = res.app_stats || []
        workbenchData.ranking = res.ranking || { by_charge: [], by_task: [] }
        updateCharts()
    } catch (err) {
        console.log('err', err)
    }
}

const handleInterfaceChannel = () => {
    if (interfaceChannel.value.configured && interfaceChannel.value.url) {
        window.open(getUrlOrigin(interfaceChannel.value.url), '_blank')
        return
    }
    router.push(interfaceChannel.value.route || '/update/channel')
}

const getUrlOrigin = (url: string) => {
    const value = String(url || '').trim()
    if (!value) {
        return ''
    }
    try {
        const normalized = /^https?:\/\//i.test(value) ? value : `https://${value.replace(/^\/+/, '')}`
        return new URL(normalized).origin
    } catch {
        return value.replace(/\/+$/, '')
    }
}

const rankPercent = (value: number, max: number) => {
    if (!max) {
        return 0
    }
    return Math.round((Number(value || 0) / max) * 100)
}

const updateCharts = () => {
    nextTick(() => {
        taskChart.value?.setOption(taskTrendOption.value, true)
        pointChart.value?.setOption(pointTrendOption.value, true)
    })
}

onMounted(() => {
    getData()
})
</script>

<style lang="scss" scoped>
.chart-grid,
.bottom-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 16px;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.dashboard-cell {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    min-width: 0;
    min-height: 92px;
    padding: 12px;
    border-radius: 6px;
    background: var(--el-fill-color-lighter);
}

.dashboard-cell--meta {
    min-height: 82px;
}

.dashboard-meta-value {
    overflow: hidden;
    color: var(--el-text-color-primary);
    font-size: 20px;
    font-weight: 600;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dashboard-channel {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.metric-value {
    overflow: hidden;
    margin: 8px 0 6px;
    font-size: 24px;
    font-weight: 600;
    line-height: 1;
    text-overflow: ellipsis;
    white-space: nowrap;

    span {
        margin-left: 4px;
        font-size: 12px;
        font-weight: 400;
        color: var(--el-text-color-secondary);
    }
}

.quick-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 10px;
}

.quick-item {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 58px;
    padding: 12px;
    border-radius: 6px;
    background: var(--el-fill-color-lighter);
    color: var(--el-text-color-primary);
}

.chart {
    height: 340px;
}

.rank-title {
    margin-bottom: 10px;
    font-weight: 500;
}

.rank-row {
    display: grid;
    grid-template-columns: 92px minmax(0, 1fr) 56px;
    align-items: center;
    gap: 10px;
    min-height: 32px;
    font-size: 13px;

    span {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    b {
        text-align: right;
        font-weight: 500;
    }
}

@media (max-width: 1200px) {
    .chart-grid,
    .bottom-grid {
        grid-template-columns: 1fr;
    }

    .quick-grid,
    .dashboard-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .quick-grid,
    .dashboard-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
