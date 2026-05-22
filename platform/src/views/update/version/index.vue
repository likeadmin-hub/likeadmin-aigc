<template>
    <div class="update-version">
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">版本更新</div>
                    <div class="text-sm text-tx-secondary mt-1">
                        系统版本检测、环境预检和可视化更新流程
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <el-button :loading="loading" @click="getOverview">刷新</el-button>
                    <el-button v-if="overview.is_ignored" type="primary" @click="handleIgnore('')"
                        >恢复提醒</el-button
                    >
                </div>
            </div>
        </el-card>

        <el-card v-loading="loading" class="!border-none mt-4" shadow="never">
            <div class="update-overview">
                <div class="version-panel">
                    <div class="version-panel__label">当前系统版本</div>
                    <div class="version-panel__version">{{ overview.current_version || '-' }}</div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <el-tag :type="updateAvailable ? 'warning' : 'success'" size="large">
                            {{ updateAvailable ? `发现新版本 ${latestVersion}` : '已是最新版本' }}
                        </el-tag>
                        <el-tag v-if="latest.update_type" type="info" size="large">
                            {{ updateTypeText(latest.update_type) }}
                        </el-tag>
                    </div>
                    <div v-if="overview.error" class="mt-4 text-sm text-error">
                        {{ overview.error }}
                    </div>
                    <div class="version-panel__actions">
                        <el-button
                            v-if="updateAvailable"
                            type="primary"
                            size="large"
                            :loading="updating || applying"
                            @click="handleUpdate"
                        >
                            立即更新
                        </el-button>
                        <el-button
                            v-if="updateAvailable"
                            size="large"
                            @click="handleIgnore(latestVersion)"
                            >忽略</el-button
                        >
                        <el-button v-if="latestVersion" size="large" @click="openVersionLog"
                            >查看日志</el-button
                        >
                    </div>
                </div>

                <div class="progress-panel">
                    <div class="progress-panel__header">
                        <div>
                            <div class="progress-panel__title">{{ updateStatusTitle }}</div>
                            <div class="progress-panel__desc">{{ updateStatusDesc }}</div>
                        </div>
                        <div class="progress-panel__percent">{{ progressPercent }}%</div>
                    </div>
                    <el-progress
                        class="mt-4"
                        :percentage="progressPercent"
                        :stroke-width="10"
                        :status="progressStatus"
                        :striped="updating || applying"
                        :striped-flow="updating || applying"
                    />
                    <div class="progress-metrics">
                        <div>
                            <span>目标版本</span>
                            <strong>{{ latestVersion || '-' }}</strong>
                        </div>
                        <div>
                            <span>包大小</span>
                            <strong>{{
                                formatSize(latest.package_size || currentPackage.package_size)
                            }}</strong>
                        </div>
                        <div>
                            <span>当前阶段</span>
                            <strong>{{ activeStep?.title || '等待检测' }}</strong>
                        </div>
                    </div>
                </div>
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="font-medium">更新流程</div>
                        <div class="text-sm text-tx-secondary mt-1">
                            下载、预检、验签、写入、同步全流程可见
                        </div>
                    </div>
                    <el-button
                        v-if="updateSessionStarted"
                        type="primary"
                        link
                        @click="preflightVisible = true"
                    >
                        打开控制台
                    </el-button>
                </div>
            </template>
            <div class="update-steps">
                <div
                    v-for="(step, index) in updateSteps"
                    :key="step.key"
                    class="update-step"
                    :class="[`is-${step.status}`, { 'is-active': step.key === currentStepKey }]"
                >
                    <div class="update-step__index">
                        <icon v-if="step.status === 'success'" name="el-icon-Check" :size="16" />
                        <icon v-else-if="step.status === 'error'" name="el-icon-Close" :size="16" />
                        <span v-else>{{ index + 1 }}</span>
                    </div>
                    <div class="update-step__body">
                        <div class="update-step__title">{{ step.title }}</div>
                        <div class="update-step__desc">{{ step.desc }}</div>
                    </div>
                </div>
            </div>
        </el-card>

        <el-card v-if="latestVersion" class="!border-none mt-4" shadow="never">
            <template #header>
                <div class="flex items-center justify-between gap-3">
                    <div class="font-medium">版本内容</div>
                    <el-button type="primary" link @click="openVersionLog">查看日志</el-button>
                </div>
            </template>
            <el-descriptions :column="2" border>
                <el-descriptions-item label="最新版本">{{ latestVersion }}</el-descriptions-item>
                <el-descriptions-item label="发布时间">{{
                    formatTime(latest.release_time || latest.publish_time)
                }}</el-descriptions-item>
            </el-descriptions>
            <div class="mt-4 whitespace-pre-line text-sm leading-6 text-tx-regular">
                {{ versionContent || '暂无版本说明' }}
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <template #header>
                <div class="font-medium">环境检测</div>
            </template>
            <el-table :data="envRows(overview.environment)" size="large">
                <el-table-column label="检测项" prop="name" min-width="180" />
                <el-table-column label="状态" width="110">
                    <template #default="{ row }">
                        <el-tag :type="row.tagType">
                            {{ row.statusText }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="结果" prop="value" min-width="260" show-overflow-tooltip />
            </el-table>
        </el-card>

        <el-dialog
            v-model="preflightVisible"
            title="系统更新控制台"
            width="920px"
            :close-on-click-modal="!isUpdating"
        >
            <div class="console-layout">
                <div class="console-main">
                    <div class="console-progress">
                        <div>
                            <div class="console-progress__title">{{ updateStatusTitle }}</div>
                            <div class="console-progress__desc">{{ updateStatusDesc }}</div>
                        </div>
                        <div class="console-progress__value">{{ progressPercent }}%</div>
                    </div>
                    <el-progress
                        class="mt-4"
                        :percentage="progressPercent"
                        :stroke-width="12"
                        :status="progressStatus"
                        :striped="updating || applying"
                        :striped-flow="updating || applying"
                    />
                    <div class="console-step-list">
                        <div
                            v-for="step in updateSteps"
                            :key="step.key"
                            class="console-step"
                            :class="[
                                `is-${step.status}`,
                                { 'is-active': step.key === currentStepKey }
                            ]"
                        >
                            <div class="console-step__icon">
                                <icon
                                    v-if="step.status === 'success'"
                                    name="el-icon-Check"
                                    :size="15"
                                />
                                <icon
                                    v-else-if="step.status === 'error'"
                                    name="el-icon-Close"
                                    :size="15"
                                />
                                <icon v-else :name="step.icon" :size="16" />
                            </div>
                            <div class="console-step__content">
                                <div class="console-step__title">{{ step.title }}</div>
                                <div class="console-step__desc">
                                    {{ step.message || step.desc }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="console-side">
                    <el-alert
                        v-if="preflightResult.passed === false"
                        type="error"
                        :closable="false"
                        :title="(preflightResult.errors || []).join('；') || '预检未通过'"
                    />
                    <el-alert
                        v-else-if="waitingApply"
                        type="success"
                        :closable="false"
                        title="预检通过，正在继续写入更新。"
                    />
                    <el-alert
                        v-else-if="updateFinished"
                        type="success"
                        :closable="false"
                        title="系统更新已完成，请按需刷新后台页面。"
                    />
                    <el-alert
                        v-else-if="updateFailed"
                        type="error"
                        :closable="false"
                        title="更新流程中断，请根据错误信息处理后重试。"
                    />
                    <el-descriptions class="mt-4" :column="1" border>
                        <el-descriptions-item label="目标版本">{{
                            currentPackage.version || latestVersion || '-'
                        }}</el-descriptions-item>
                        <el-descriptions-item label="解压驱动">{{
                            preflightResult.extract?.driver || '-'
                        }}</el-descriptions-item>
                        <el-descriptions-item label="包大小">{{
                            packageSize
                        }}</el-descriptions-item>
                        <el-descriptions-item label="临时目录">
                            <span class="path-text">{{
                                preflightResult.extract?.path || '-'
                            }}</span>
                        </el-descriptions-item>
                    </el-descriptions>
                    <div class="console-log">
                        <div class="console-log__title">执行记录</div>
                        <div v-if="actionLogs.length === 0" class="console-log__empty">
                            等待开始
                        </div>
                        <div
                            v-for="(log, index) in actionLogs"
                            :key="index"
                            class="console-log__item"
                        >
                            <span>{{ log.time }}</span>
                            <strong>{{ log.text }}</strong>
                        </div>
                    </div>
                </div>
            </div>
            <el-table
                class="mt-4"
                :data="envRows(preflightResult.environment)"
                size="small"
                max-height="220"
            >
                <el-table-column label="检测项" prop="name" min-width="180" />
                <el-table-column label="状态" width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.tagType" size="small">
                            {{ row.statusText }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="结果" prop="value" min-width="220" show-overflow-tooltip />
            </el-table>
            <template #footer>
                <el-button :disabled="isUpdating" @click="preflightVisible = false">
                    {{ updateFinished || updateFailed ? '关闭' : '取消' }}
                </el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="versionLogVisible" title="版本日志" width="760px">
            <el-descriptions :column="2" border>
                <el-descriptions-item label="版本">{{ latestVersion || '-' }}</el-descriptions-item>
                <el-descriptions-item label="标题">{{
                    latest.title || `系统版本 ${latestVersion}`
                }}</el-descriptions-item>
                <el-descriptions-item label="类型">{{
                    updateTypeText(latest.update_type)
                }}</el-descriptions-item>
                <el-descriptions-item label="发布时间">{{
                    formatTime(latest.release_time || latest.publish_time)
                }}</el-descriptions-item>
                <el-descriptions-item label="强制更新">{{
                    latest.force ? '是' : '否'
                }}</el-descriptions-item>
                <el-descriptions-item label="包大小">{{
                    formatSize(latest.package_size)
                }}</el-descriptions-item>
                <el-descriptions-item label="最低系统版本">{{
                    latest.require_core || '-'
                }}</el-descriptions-item>
                <el-descriptions-item label="版本状态">{{
                    updateAvailable ? '可更新' : '仅查看'
                }}</el-descriptions-item>
            </el-descriptions>
            <el-alert
                v-if="latest.summary"
                class="mt-4"
                type="info"
                :closable="false"
                :title="latest.summary"
            />
            <div class="mt-4 version-log-content">
                <div
                    v-for="(item, index) in versionLogItems(latest)"
                    :key="index"
                    class="version-log-content__item"
                >
                    {{ item }}
                </div>
                <el-empty v-if="versionLogItems(latest).length === 0" description="暂无更新内容" />
            </div>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="update-version">
import {
    updateApplyPackage,
    updateDownloadPackage,
    updateIgnoreVersion,
    updateOverview,
    updatePreflightPackage
} from '@/api/update_service'
import feedback from '@/utils/feedback'

const loading = ref(false)
const updating = ref(false)
const applying = ref(false)
const overview = ref<any>({})
const currentPackage = ref<any>({})
const preflightResult = ref<any>({})
const preflightVisible = ref(false)
const versionLogVisible = ref(false)
const progressPercent = ref(0)
const currentStepKey = ref('')
const updateSessionStarted = ref(false)
const updateFinished = ref(false)
const updateFailed = ref(false)
const waitingApply = ref(false)
const actionLogs = ref<Array<{ time: string; text: string }>>([])
let progressTimer: ReturnType<typeof setInterval> | null = null

type UpdateStepStatus = 'wait' | 'process' | 'success' | 'error'
type UpdateStep = {
    key: string
    title: string
    desc: string
    icon: string
    status: UpdateStepStatus
    message: string
}

const createUpdateSteps = (): UpdateStep[] => [
    {
        key: 'download',
        title: '下载版本包',
        desc: '连接更新源并校验包信息',
        icon: 'el-icon-Download',
        status: 'wait',
        message: ''
    },
    {
        key: 'preflight',
        title: '环境预检',
        desc: '检测目录权限、磁盘空间和解压能力',
        icon: 'el-icon-Monitor',
        status: 'wait',
        message: ''
    },
    {
        key: 'signature',
        title: '签名验真',
        desc: '解压临时包并核对清单签名',
        icon: 'el-icon-Lock',
        status: 'wait',
        message: ''
    },
    {
        key: 'apply',
        title: '写入更新',
        desc: '执行数据脚本并替换运行文件',
        icon: 'el-icon-UploadFilled',
        status: 'wait',
        message: ''
    },
    {
        key: 'sync',
        title: '同步应用',
        desc: '刷新内置应用和租户运行状态',
        icon: 'el-icon-Connection',
        status: 'wait',
        message: ''
    },
    {
        key: 'finish',
        title: '完成',
        desc: '写入本地版本并完成收尾',
        icon: 'el-icon-CircleCheck',
        status: 'wait',
        message: ''
    }
]
const updateSteps = ref<UpdateStep[]>(createUpdateSteps())

const latest = computed(() => overview.value.latest || {})
const latestVersion = computed(() => latest.value.version || latest.value.version_no || '')
const updateAvailable = computed(() => overview.value.has_update && !overview.value.is_ignored)
const isUpdating = computed(() => updating.value || applying.value)
const activeStep = computed(() =>
    updateSteps.value.find((item) => item.key === currentStepKey.value)
)
const progressStatus = computed(() => {
    if (updateFailed.value) return 'exception'
    if (updateFinished.value) return 'success'
    return undefined
})
const updateStatusTitle = computed(() => {
    if (updateFailed.value) return '更新中断'
    if (updateFinished.value) return '更新完成'
    if (waitingApply.value) return '预检通过，正在写入'
    if (isUpdating.value) return activeStep.value?.title || '正在更新'
    return updateAvailable.value ? '准备更新' : '状态正常'
})
const updateStatusDesc = computed(() => {
    if (updateFailed.value) return activeStep.value?.message || '请查看执行记录和接口错误信息'
    if (updateFinished.value)
        return `已更新到 ${currentPackage.value.version || latestVersion.value || '目标版本'}`
    if (waitingApply.value) return '系统包已下载并通过预检，正在继续写入数据库和文件'
    if (isUpdating.value)
        return activeStep.value?.message || activeStep.value?.desc || '更新流程执行中'
    return updateAvailable.value ? '建议先确认备份，再执行更新' : '当前系统无需更新'
})
const versionContent = computed(() => {
    const content =
        latest.value.update_content ||
        latest.value.content ||
        latest.value.description ||
        latest.value.changelog
    return Array.isArray(content)
        ? content.map((item: any) => item.update_function || item.content || item).join('\n')
        : content
})
const packageSize = computed(() => {
    const size = Number(currentPackage.value.package_size || 0)
    return size ? `${Math.ceil(size / 1024 / 1024)} MB` : '-'
})

const openVersionLog = () => {
    versionLogVisible.value = true
}

const nowTime = () => new Date().toLocaleTimeString()

const addLog = (text: string) => {
    actionLogs.value.unshift({ time: nowTime(), text })
}

const resetUpdateSession = () => {
    stopProgressTicker()
    updateSteps.value = createUpdateSteps()
    currentStepKey.value = ''
    currentPackage.value = {}
    preflightResult.value = {}
    progressPercent.value = 0
    updateSessionStarted.value = true
    updateFinished.value = false
    updateFailed.value = false
    waitingApply.value = false
    actionLogs.value = []
}

const setStepStatus = (key: string, status: UpdateStepStatus, message = '') => {
    updateSteps.value = updateSteps.value.map((item) =>
        item.key === key ? { ...item, status, message: message || item.message } : item
    )
    currentStepKey.value = key
}

const beginStep = (key: string, percent: number, message: string) => {
    setStepStatus(key, 'process', message)
    progressPercent.value = Math.max(progressPercent.value, percent)
    addLog(message)
}

const finishStep = (key: string, percent: number, message: string) => {
    setStepStatus(key, 'success', message)
    progressPercent.value = Math.max(progressPercent.value, percent)
    addLog(message)
}

const failStep = (key: string, message: string) => {
    setStepStatus(key, 'error', message)
    updateFailed.value = true
    addLog(message)
}

const startProgressTicker = (maxPercent: number) => {
    stopProgressTicker()
    progressTimer = setInterval(() => {
        if (progressPercent.value >= maxPercent) return
        const step = progressPercent.value < 70 ? 2 : 1
        progressPercent.value = Math.min(maxPercent, progressPercent.value + step)
    }, 900)
}

const stopProgressTicker = () => {
    if (progressTimer) {
        clearInterval(progressTimer)
        progressTimer = null
    }
}

const updateTypeText = (type: string) =>
    ({ patch: '补丁', minor: '小版本', major: '大版本', full: '完整包' })[type] || type || '-'

const formatTime = (value: number | string) => {
    if (!value) return '-'
    if (typeof value === 'string' && Number.isNaN(Number(value))) {
        const date = new Date(value)
        return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString()
    }
    const timestamp = Number(value)
    const milliseconds = timestamp > 1000000000000 ? timestamp : timestamp * 1000
    return new Date(milliseconds).toLocaleString()
}

const formatSize = (value: number | string) => {
    const size = Number(value || 0)
    if (!size) return '-'
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(2)} MB`
    return `${Math.ceil(size / 1024)} KB`
}

const versionLogItems = (row: any) => {
    const content = row?.changelog || row?.update_content || row?.content || row?.description || []
    if (Array.isArray(content)) {
        return content
            .map((item: any) => item?.update_function || item?.content || item?.title || item)
            .filter(Boolean)
    }
    return String(content || '')
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean)
}

const getOverview = async () => {
    loading.value = true
    try {
        overview.value = await updateOverview()
    } finally {
        loading.value = false
    }
}

const handleIgnore = async (version: string) => {
    await updateIgnoreVersion({ version })
    getOverview()
}

const handleUpdate = async () => {
    await feedback.confirm('更新前请确认已完成数据库和代码备份，确定继续？')
    resetUpdateSession()
    preflightVisible.value = true
    updating.value = true
    try {
        beginStep('download', 8, '正在连接云端更新源并下载系统包')
        startProgressTicker(28)
        const pkg = await updateDownloadPackage({
            target_version: latestVersion.value,
            current_version: overview.value.current_version
        })
        stopProgressTicker()
        currentPackage.value = pkg
        finishStep('download', 32, '系统包下载完成')

        beginStep('preflight', 38, '正在检测服务器环境和目录权限')
        startProgressTicker(48)
        preflightResult.value = await updatePreflightPackage({ package_id: pkg.id })
        stopProgressTicker()
        if (!preflightResult.value.passed) {
            failStep('preflight', (preflightResult.value.errors || []).join('；') || '预检未通过')
            return
        }
        finishStep('preflight', 52, '环境预检通过')
        finishStep('signature', 62, '更新包签名和文件清单校验通过')
        waitingApply.value = true
        await handleApply()
    } catch (e: any) {
        if (!updateFailed.value) {
            failStep(currentStepKey.value || 'download', e?.message || '更新准备失败')
        }
        throw e
    } finally {
        stopProgressTicker()
        updating.value = false
    }
}

const handleApply = async () => {
    if (!currentPackage.value.id) return
    applying.value = true
    waitingApply.value = false
    try {
        beginStep('apply', 70, '正在写入数据库脚本并替换系统文件')
        startProgressTicker(86)
        const result = await updateApplyPackage({ package_id: currentPackage.value.id })
        stopProgressTicker()
        finishStep('apply', 88, '系统文件和数据库更新完成')
        finishStep('sync', 94, '内置应用与租户运行状态同步完成')
        finishStep('finish', 100, '更新流程完成')
        updateFinished.value = true
        currentPackage.value = { ...currentPackage.value, ...result }
        preflightVisible.value = false
        feedback.msgSuccess('更新完成，正在刷新页面')
        window.setTimeout(() => {
            window.location.reload()
        }, 800)
    } catch (e: any) {
        failStep(currentStepKey.value || 'apply', e?.message || '系统更新失败')
        throw e
    } finally {
        stopProgressTicker()
        applying.value = false
    }
}

const envRows = (env: any = {}) => {
    const paths = env.paths || {}
    const driverAvailable = !!(
        env.zip_archive ||
        env.command?.unzip ||
        env.command?.['7z'] ||
        env.command?.tar ||
        env.phar_data
    )
    const row = (name: string, value: string, ok = true, optional = false) => ({
        name,
        value,
        statusText: ok ? '正常' : optional ? '备用' : '异常',
        tagType: ok ? 'success' : optional ? 'info' : 'danger'
    })
    return [
        row(
            '解压能力',
            driverAvailable ? '至少一个解压驱动可用' : '未检测到可用解压驱动',
            driverAvailable
        ),
        row('PHP ZipArchive', env.zip_archive ? '可用' : '不可用', !!env.zip_archive, true),
        row('PHP PharData', env.phar_data ? '可用' : '不可用', !!env.phar_data, true),
        row('unzip 命令', env.command?.unzip ? '可用' : '不可用', !!env.command?.unzip, true),
        row('7z 命令', env.command?.['7z'] ? '可用' : '不可用', !!env.command?.['7z'], true),
        row('tar 命令', env.command?.tar ? '可用' : '不可用', !!env.command?.tar, true),
        row('禁用函数', (env.disabled_functions || []).join(', ') || '-'),
        row('open_basedir', env.open_basedir || '-'),
        row(
            '剩余磁盘',
            env.disk_free ? `${Math.floor(env.disk_free / 1024 / 1024)} MB` : '-',
            Number(env.disk_free || 0) > 0
        ),
        row(
            '临时目录',
            `${paths.runtime_update_temp?.path || '-'} / ${paths.runtime_update_temp?.writable ? '可写' : '不可写'}`,
            !!paths.runtime_update_temp?.writable
        ),
        row(
            '更新工作目录',
            `${paths.runtime_update_workspace?.path || '-'} / ${paths.runtime_update_workspace?.writable ? '可写' : '不可写'}`,
            !!paths.runtime_update_workspace?.writable
        ),
        row(
            '版本状态目录',
            `${paths.version_state?.path || '-'} / ${paths.version_state?.writable ? '可写' : '不可写'}`,
            !!paths.version_state?.writable
        ),
        row(
            '目标目录',
            `${paths.target?.path || '-'} / ${paths.target?.writable ? '可写' : '不可写'}`,
            !!paths.target?.writable
        )
    ]
}

getOverview()
onUnmounted(stopProgressTicker)
</script>

<style lang="scss" scoped>
.update-version {
    --update-gradient-panel: linear-gradient(135deg, #f7fbff 0%, #ffffff 54%, #f8fff8 100%);
    --update-surface-bg: #ffffff;
    --update-soft-bg: var(--el-fill-color-lighter);
    --update-step-bg: var(--el-fill-color-lighter);
    --update-step-active-bg: var(--el-color-primary-light-9);
    --update-step-active-border: var(--el-color-primary-light-5);
    --update-step-success-bg: #f2fbf6;
    --update-step-success-border: #b8e8ca;
    --update-step-error-bg: #fff5f5;
    --update-step-error-border: #f6c2c2;
    --update-index-bg: #ffffff;
    --update-console-step-bg: rgba(255, 255, 255, 0.82);
}

:global(.dark) .update-version {
    --update-gradient-panel: linear-gradient(135deg, #202326 0%, #1d2124 56%, #1b2420 100%);
    --update-surface-bg: var(--el-bg-color);
    --update-soft-bg: #191919;
    --update-step-bg: #1b1b1b;
    --update-step-active-bg: rgba(239, 196, 155, 0.14);
    --update-step-active-border: rgba(239, 196, 155, 0.48);
    --update-step-success-bg: rgba(103, 194, 58, 0.14);
    --update-step-success-border: rgba(103, 194, 58, 0.45);
    --update-step-error-bg: rgba(245, 108, 108, 0.14);
    --update-step-error-border: rgba(245, 108, 108, 0.45);
    --update-index-bg: #252729;
    --update-console-step-bg: rgba(31, 34, 36, 0.88);
}

.update-overview {
    display: grid;
    grid-template-columns: minmax(280px, 0.9fr) minmax(360px, 1.3fr);
    gap: 18px;
}

.version-panel,
.progress-panel {
    min-height: 210px;
    padding: 24px;
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
}

.version-panel {
    background: var(--update-gradient-panel);
}

.version-panel__label {
    font-size: 14px;
    color: var(--el-text-color-secondary);
}

.version-panel__version {
    margin-top: 10px;
    font-size: 42px;
    font-weight: 700;
    line-height: 1.1;
    color: var(--el-text-color-primary);
}

.version-panel__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 28px;
}

.progress-panel {
    background: var(--update-surface-bg);
}

.progress-panel__header,
.console-progress {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
}

.progress-panel__title,
.console-progress__title {
    font-size: 18px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}

.progress-panel__desc,
.console-progress__desc {
    margin-top: 6px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
}

.progress-panel__percent,
.console-progress__value {
    font-size: 34px;
    font-weight: 700;
    color: var(--el-color-primary);
}

.progress-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 22px;
}

.progress-metrics > div {
    min-width: 0;
    padding: 14px;
    background: var(--update-soft-bg);
    border-radius: 8px;
}

.progress-metrics span {
    display: block;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}

.progress-metrics strong {
    display: block;
    margin-top: 6px;
    overflow: hidden;
    font-size: 15px;
    color: var(--el-text-color-primary);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.update-steps {
    display: grid;
    grid-template-columns: repeat(6, minmax(120px, 1fr));
    gap: 12px;
}

.update-step {
    position: relative;
    display: flex;
    gap: 10px;
    min-width: 0;
    padding: 14px;
    background: var(--update-step-bg);
    border: 1px solid transparent;
    border-radius: 8px;
}

.update-step.is-active {
    background: var(--update-step-active-bg);
    border-color: var(--update-step-active-border);
}

.update-step.is-success {
    background: var(--update-step-success-bg);
    border-color: var(--update-step-success-border);
}

.update-step.is-error {
    background: var(--update-step-error-bg);
    border-color: var(--update-step-error-border);
}

.update-step__index {
    display: flex;
    flex: 0 0 28px;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-secondary);
    background: var(--update-index-bg);
    border: 1px solid var(--el-border-color);
    border-radius: 50%;
}

.update-step.is-active .update-step__index {
    color: #ffffff;
    background: var(--el-color-primary);
    border-color: var(--el-color-primary);
}

.update-step.is-success .update-step__index {
    color: #ffffff;
    background: var(--el-color-success);
    border-color: var(--el-color-success);
}

.update-step.is-error .update-step__index {
    color: #ffffff;
    background: var(--el-color-danger);
    border-color: var(--el-color-danger);
}

.update-step__body {
    min-width: 0;
}

.update-step__title {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}

.update-step__desc {
    margin-top: 5px;
    overflow: hidden;
    font-size: 12px;
    line-height: 18px;
    color: var(--el-text-color-secondary);
    text-overflow: ellipsis;
}

.console-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) 340px;
    gap: 18px;
}

.console-main,
.console-side {
    min-width: 0;
}

.console-main {
    padding: 18px;
    background: var(--update-gradient-panel);
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
}

.console-step-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 18px;
}

.console-step {
    display: flex;
    gap: 10px;
    min-width: 0;
    padding: 12px;
    background: var(--update-console-step-bg);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
}

.console-step.is-active {
    border-color: var(--update-step-active-border);
    box-shadow: 0 10px 24px rgb(64 158 255 / 10%);
}

.console-step.is-success {
    border-color: var(--update-step-success-border);
}

.console-step.is-error {
    border-color: var(--update-step-error-border);
}

.console-step__icon {
    display: flex;
    flex: 0 0 30px;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    color: var(--el-text-color-secondary);
    background: var(--el-fill-color-lighter);
    border-radius: 8px;
}

.console-step.is-active .console-step__icon {
    color: #ffffff;
    background: var(--el-color-primary);
}

.console-step.is-success .console-step__icon {
    color: #ffffff;
    background: var(--el-color-success);
}

.console-step.is-error .console-step__icon {
    color: #ffffff;
    background: var(--el-color-danger);
}

.console-step__content {
    min-width: 0;
}

.console-step__title {
    font-size: 14px;
    font-weight: 600;
}

.console-step__desc {
    margin-top: 5px;
    overflow: hidden;
    font-size: 12px;
    line-height: 18px;
    color: var(--el-text-color-secondary);
    text-overflow: ellipsis;
}

.path-text {
    display: inline-block;
    max-width: 210px;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: bottom;
    white-space: nowrap;
}

.console-log {
    padding: 14px;
    margin-top: 14px;
    background: var(--update-soft-bg);
    border-radius: 8px;
}

.console-log__title {
    font-size: 14px;
    font-weight: 600;
}

.console-log__empty {
    margin-top: 12px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
}

.console-log__item {
    display: flex;
    gap: 10px;
    margin-top: 12px;
    font-size: 13px;
    line-height: 20px;
}

.console-log__item span {
    flex: 0 0 72px;
    color: var(--el-text-color-secondary);
}

.console-log__item strong {
    min-width: 0;
    font-weight: 500;
    color: var(--el-text-color-primary);
}

.version-log-content {
    padding: 14px 16px;
    background: var(--update-soft-bg);
    border-radius: 8px;
}

.version-log-content__item {
    position: relative;
    padding-left: 14px;
    font-size: 14px;
    line-height: 28px;
    color: var(--el-text-color-regular);

    &::before {
        position: absolute;
        top: 12px;
        left: 0;
        width: 5px;
        height: 5px;
        content: '';
        background: var(--el-color-primary);
        border-radius: 50%;
    }
}

@media (max-width: 1280px) {
    .update-overview,
    .console-layout {
        grid-template-columns: 1fr;
    }

    .update-steps {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .progress-metrics,
    .console-step-list,
    .update-steps {
        grid-template-columns: 1fr;
    }

    .version-panel__version {
        font-size: 34px;
    }
}
</style>
