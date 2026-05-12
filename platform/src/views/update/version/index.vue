<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">版本更新</div>
                    <div class="text-sm text-tx-secondary mt-1">系统版本检测、环境预检和手动更新</div>
                </div>
                <div class="flex items-center gap-2">
                    <el-button @click="getOverview">刷新</el-button>
                    <el-button v-if="overview.is_ignored" type="primary" @click="handleIgnore('')">恢复提醒</el-button>
                </div>
            </div>
        </el-card>

        <el-card v-loading="loading" class="!border-none mt-4" shadow="never">
            <div class="flex flex-col items-center py-8">
                <div class="text-3xl font-semibold">{{ overview.current_version || '-' }}</div>
                <div class="mt-2 text-sm text-tx-secondary">当前系统版本</div>
                <el-tag class="mt-5" :type="updateAvailable ? 'warning' : 'success'" size="large">
                    {{ updateAvailable ? `发现新版本 ${latestVersion}` : '已是最新版本' }}
                </el-tag>
                <div v-if="overview.error" class="mt-4 text-sm text-error">{{ overview.error }}</div>
                <div v-if="updateAvailable" class="mt-6 flex gap-3">
                    <el-button type="primary" :loading="updating" @click="handleUpdate">更新</el-button>
                    <el-button @click="handleIgnore(latestVersion)">忽略</el-button>
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
                <el-descriptions-item label="发布时间">{{ formatTime(latest.release_time || latest.publish_time) }}</el-descriptions-item>
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
                <el-table-column label="结果" prop="value" min-width="260" />
            </el-table>
        </el-card>

        <el-dialog v-model="preflightVisible" title="更新预检" width="780px">
            <el-alert
                v-if="preflightResult.passed === false"
                type="error"
                :closable="false"
                :title="(preflightResult.errors || []).join('；') || '预检未通过'"
            />
            <el-alert v-else-if="preflightResult.passed" type="success" :closable="false" title="预检通过，可以执行更新。" />
            <el-descriptions class="mt-4" :column="2" border>
                <el-descriptions-item label="版本">{{ currentPackage.version || '-' }}</el-descriptions-item>
                <el-descriptions-item label="解压驱动">{{ preflightResult.extract?.driver || '-' }}</el-descriptions-item>
                <el-descriptions-item label="临时目录">{{ preflightResult.extract?.path || '-' }}</el-descriptions-item>
                <el-descriptions-item label="包大小">{{ packageSize }}</el-descriptions-item>
            </el-descriptions>
            <el-table class="mt-4" :data="envRows(preflightResult.environment)" size="small" max-height="260">
                <el-table-column label="检测项" prop="name" min-width="180" />
                <el-table-column label="结果" prop="value" min-width="220" />
            </el-table>
            <template #footer>
                <el-button @click="preflightVisible = false">取消</el-button>
                <el-button type="primary" :disabled="!preflightResult.passed" :loading="applying" @click="handleApply">
                    确认执行更新
                </el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="versionLogVisible" title="版本日志" width="760px">
            <el-descriptions :column="2" border>
                <el-descriptions-item label="版本">{{ latestVersion || '-' }}</el-descriptions-item>
                <el-descriptions-item label="标题">{{ latest.title || `系统版本 ${latestVersion}` }}</el-descriptions-item>
                <el-descriptions-item label="类型">{{ updateTypeText(latest.update_type) }}</el-descriptions-item>
                <el-descriptions-item label="发布时间">{{ formatTime(latest.release_time || latest.publish_time) }}</el-descriptions-item>
                <el-descriptions-item label="强制更新">{{ latest.force ? '是' : '否' }}</el-descriptions-item>
                <el-descriptions-item label="包大小">{{ formatSize(latest.package_size) }}</el-descriptions-item>
                <el-descriptions-item label="最低系统版本">{{ latest.require_core || '-' }}</el-descriptions-item>
                <el-descriptions-item label="版本状态">{{ updateAvailable ? '可更新' : '仅查看' }}</el-descriptions-item>
            </el-descriptions>
            <el-alert
                v-if="latest.summary"
                class="mt-4"
                type="info"
                :closable="false"
                :title="latest.summary"
            />
            <div class="mt-4 version-log-content">
                <div v-for="(item, index) in versionLogItems(latest)" :key="index" class="version-log-content__item">
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

const latest = computed(() => overview.value.latest || {})
const latestVersion = computed(() => latest.value.version || latest.value.version_no || '')
const updateAvailable = computed(() => overview.value.has_update && !overview.value.is_ignored)
const versionContent = computed(() => {
    const content = latest.value.update_content || latest.value.content || latest.value.description || latest.value.changelog
    return Array.isArray(content) ? content.map((item: any) => item.update_function || item.content || item).join('\n') : content
})
const packageSize = computed(() => {
    const size = Number(currentPackage.value.package_size || 0)
    return size ? `${Math.ceil(size / 1024 / 1024)} MB` : '-'
})

const openVersionLog = () => {
    versionLogVisible.value = true
}

const updateTypeText = (type: string) =>
    ({ patch: '补丁', minor: '小版本', major: '大版本', full: '完整包' }[type] || type || '-')

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
        return content.map((item: any) => item?.update_function || item?.content || item?.title || item).filter(Boolean)
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
    updating.value = true
    try {
        const pkg = await updateDownloadPackage({
            target_version: latestVersion.value,
            current_version: overview.value.current_version
        })
        currentPackage.value = pkg
        preflightResult.value = await updatePreflightPackage({ package_id: pkg.id })
        preflightVisible.value = true
    } finally {
        updating.value = false
    }
}

const handleApply = async () => {
    applying.value = true
    try {
        await updateApplyPackage({ package_id: currentPackage.value.id })
        preflightVisible.value = false
        getOverview()
    } finally {
        applying.value = false
    }
}

const envRows = (env: any = {}) => {
    const paths = env.paths || {}
    return [
        { name: 'PHP ZipArchive', value: env.zip_archive ? '可用' : '不可用' },
        { name: 'PHP PharData', value: env.phar_data ? '可用' : '不可用' },
        { name: 'unzip 命令', value: env.command?.unzip ? '可用' : '不可用' },
        { name: '7z 命令', value: env.command?.['7z'] ? '可用' : '不可用' },
        { name: 'tar 命令', value: env.command?.tar ? '可用' : '不可用' },
        { name: '禁用函数', value: (env.disabled_functions || []).join(', ') || '-' },
        { name: 'open_basedir', value: env.open_basedir || '-' },
        { name: '剩余磁盘', value: env.disk_free ? `${Math.floor(env.disk_free / 1024 / 1024)} MB` : '-' },
        { name: '临时目录', value: `${paths.runtime_update_temp?.path || '-'} / ${paths.runtime_update_temp?.writable ? '可写' : '不可写'}` },
        { name: '更新工作目录', value: `${paths.runtime_update_workspace?.path || '-'} / ${paths.runtime_update_workspace?.writable ? '可写' : '不可写'}` },
        { name: '版本状态目录', value: `${paths.version_state?.path || '-'} / ${paths.version_state?.writable ? '可写' : '不可写'}` },
        { name: '目标目录', value: `${paths.target?.path || '-'} / ${paths.target?.writable ? '可写' : '不可写'}` }
    ]
}

getOverview()
</script>

<style lang="scss" scoped>
.version-log-content {
    padding: 14px 16px;
    background: var(--el-fill-color-lighter);
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
</style>
