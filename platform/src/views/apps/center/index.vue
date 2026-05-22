<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">应用中心</div>
                    <div class="text-sm text-tx-secondary mt-1">开放平台应用安装、更新、预检和本地软卸载</div>
                </div>
                <el-button type="primary" :loading="cloudLoading" @click="handleCloudLists(true)">刷新云端</el-button>
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-form class="ls-form" :model="filterForm" inline>
                <el-form-item label="关键词">
                    <el-input
                        v-model="filterForm.keyword"
                        class="w-[240px]"
                        clearable
                        placeholder="应用名称 / 标识"
                    />
                </el-form-item>
                <el-form-item label="分类">
                    <el-select v-model="filterForm.category" style="width: 180px" clearable placeholder="全部分类">
                        <el-option v-for="item in categoryOptions" :key="item.value" :label="item.label" :value="item.value" />
                    </el-select>
                </el-form-item>
                <el-form-item label="来源">
                    <el-select v-model="filterForm.source" style="width: 160px" clearable placeholder="全部来源">
                        <el-option label="本地" value="local" />
                        <el-option label="云端" value="cloud" />
                    </el-select>
                </el-form-item>
                <el-form-item label="状态">
                    <el-select v-model="filterForm.status" style="width: 160px" clearable placeholder="全部状态">
                        <el-option label="已启用" value="installed" />
                        <el-option label="已禁用" value="disabled" />
                        <el-option label="已卸载" value="removed" />
                        <el-option label="未安装" value="not_installed" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button @click="resetFilter">重置</el-button>
                </el-form-item>
            </el-form>

            <div v-loading="loading || cloudLoading" class="app-grid">
                <div v-for="row in pagedApps" :key="row.key" class="app-card">
                    <div class="app-card__main">
                        <div class="app-card__icon">
                            <img :src="iconSrc(row)" :alt="row.name" @error="handleIconError" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="app-card__name">{{ row.name }}</div>
                                    <div class="app-card__code">{{ row.code }}</div>
                                </div>
                                <el-tag :type="statusType(row.status)">{{ statusText(row.status) }}</el-tag>
                            </div>
                            <div class="app-card__desc">
                                {{ row.description || '暂无说明' }}
                            </div>
                        </div>
                    </div>

                    <div class="app-card__meta">
                        <div>
                            <span class="app-card__meta-label">本地</span>
                            <span class="app-card__meta-value">{{ row.local_version || '-' }}</span>
                        </div>
                        <div>
                            <span class="app-card__meta-label">云端</span>
                            <span class="app-card__meta-value">{{ row.cloud_version || '-' }}</span>
                        </div>
                        <div v-if="row.client_tags">
                            <span class="app-card__meta-label">适用</span>
                            <span class="app-card__meta-value">{{ row.client_tags }}</span>
                        </div>
                    </div>

                    <div class="app-card__tags">
                        <el-tag size="small" :type="row.has_local ? 'success' : 'info'">本地</el-tag>
                        <el-tag v-if="row.has_cloud" size="small" type="primary">云端</el-tag>
                        <el-tag v-if="row.has_cloud" size="small" :type="row.is_opened ? 'success' : 'danger'">
                            {{ row.is_opened ? '已授权' : row.auth_message || authStatusText(row.auth_status) }}
                        </el-tag>
                        <el-tag v-if="row.category" size="small">{{ categoryText(row.category) }}</el-tag>
                        <el-tag v-if="row.is_builtin" size="small">内置</el-tag>
                    </div>

                    <div class="app-card__actions">
                        <el-button type="primary" link @click="openDetail(row)">详情</el-button>
                        <template v-if="!row.is_builtin">
                            <el-button v-if="row.status === 'removed' && row.has_local" type="primary" link @click="handleInstall(row.code)">
                                安装
                            </el-button>
                            <el-button v-else-if="row.status === 'disabled'" type="primary" link @click="handleEnable(row.code)">
                                启用
                            </el-button>
                            <el-button v-if="row.status === 'installed'" type="warning" link @click="handleDisable(row.code)">
                                禁用
                            </el-button>
                            <el-button
                                v-if="row.has_cloud"
                                type="primary"
                                link
                                :disabled="!row.is_opened"
                                @click="handleDownload(row)"
                            >
                                {{ row.has_local ? '云端更新' : '安装' }}
                            </el-button>
                            <el-button v-if="row.has_local && row.status !== 'removed'" type="primary" link @click="openPlanDrawer(row)">
                                套餐设置
                            </el-button>
                            <el-button v-if="row.has_local && row.status !== 'removed'" type="danger" link @click="openUninstall(row.code)">
                                卸载
                            </el-button>
                        </template>
                    </div>
                </div>
            </div>
            <el-empty v-if="!loading && !cloudLoading && filteredApps.length === 0" description="暂无应用" />
            <pagination v-model="pager" @change="handlePageChange" />
        </el-card>

        <el-dialog v-model="detailVisible" title="应用详情" width="760px">
            <el-descriptions :column="2" border>
                <el-descriptions-item label="应用标识">{{ detail.app?.code || detail.manifest?.code || detail.app_code }}</el-descriptions-item>
                <el-descriptions-item label="应用名称">{{ detail.app?.name || detail.manifest?.name || detail.name }}</el-descriptions-item>
                <el-descriptions-item label="当前版本">{{ detail.app?.current_version || detail.manifest?.version || detail.version }}</el-descriptions-item>
                <el-descriptions-item label="租户数量">{{ detail.tenant_count || 0 }}</el-descriptions-item>
            </el-descriptions>
            <el-tabs class="mt-4">
                <el-tab-pane label="API">
                    <el-table :data="detail.apis || []" max-height="260">
                        <el-table-column label="场景" prop="scene" width="120" />
                        <el-table-column label="方法" prop="api_method" width="90" />
                        <el-table-column label="路径" prop="api_path" min-width="220" />
                        <el-table-column label="权限" prop="permission_key" min-width="180" />
                    </el-table>
                </el-tab-pane>
                <el-tab-pane label="前端入口">
                    <el-table :data="detail.frontend_entries || []" max-height="260">
                        <el-table-column label="端" prop="terminal" width="120" />
                        <el-table-column label="名称" prop="name" width="160" />
                        <el-table-column label="路径" prop="path" />
                    </el-table>
                </el-tab-pane>
            </el-tabs>
        </el-dialog>

        <el-dialog v-model="packageVisible" title="应用包预检" width="780px">
            <el-alert
                v-if="preflightResult.passed === false"
                type="error"
                :closable="false"
                :title="(preflightResult.errors || []).join('；') || '预检未通过'"
            />
            <el-alert
                v-else-if="preflightResult.passed"
                type="success"
                :closable="false"
                title="预检通过，可以执行安装或更新。"
            />
            <el-descriptions class="mt-4" :column="2" border>
                <el-descriptions-item label="应用">{{ preflightResult.manifest?.code || currentPackage.app_code || '-' }}</el-descriptions-item>
                <el-descriptions-item label="版本">{{ preflightResult.manifest?.version || currentPackage.version || '-' }}</el-descriptions-item>
                <el-descriptions-item label="解压驱动">{{ preflightResult.extract?.driver || '-' }}</el-descriptions-item>
                <el-descriptions-item label="临时目录">{{ preflightResult.extract?.path || '-' }}</el-descriptions-item>
            </el-descriptions>
            <el-table class="mt-4" :data="envRows(preflightResult.environment)" size="small" max-height="260">
                <el-table-column label="检测项" prop="name" min-width="180" />
                <el-table-column label="结果" prop="value" min-width="220" />
            </el-table>
            <template #footer>
                <el-button @click="packageVisible = false">取消</el-button>
                <el-button type="primary" :disabled="!preflightResult.passed" :loading="applying" @click="handleApply">
                    执行安装/更新
                </el-button>
            </template>
        </el-dialog>

        <el-dialog v-model="uninstallVisible" title="卸载应用" width="420px">
            <el-alert type="warning" :closable="false" title="默认软卸载会保留租户配置、任务和作品数据。" />
            <el-checkbox v-model="clearData" class="mt-4">同时清理租户授权、菜单、API入口和业务数据</el-checkbox>
            <template #footer>
                <el-button @click="uninstallVisible = false">取消</el-button>
                <el-button type="danger" @click="handleUninstall">确认卸载</el-button>
            </template>
        </el-dialog>

        <el-drawer v-model="planVisible" title="套餐设置" size="720px">
            <div v-loading="planLoading">
                <el-descriptions :column="2" border>
                    <el-descriptions-item label="应用名称">{{ planApp.name }}</el-descriptions-item>
                    <el-descriptions-item label="应用标识">{{ planApp.code }}</el-descriptions-item>
                </el-descriptions>

                <div class="mt-5">
                    <div class="section-title">过期策略</div>
                    <div class="flex items-center gap-3 mt-3">
                        <el-radio-group v-model="expirePolicy">
                            <el-radio-button label="block">过期不可用</el-radio-button>
                            <el-radio-button label="allow">过期仍可用</el-radio-button>
                        </el-radio-group>
                        <el-button type="primary" :loading="policySaving" @click="savePolicy">保存策略</el-button>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="flex items-center justify-between">
                        <div class="section-title">套餐列表</div>
                        <el-button type="primary" @click="addPlan">新增套餐</el-button>
                    </div>
                    <el-table class="mt-3" :data="planRows" size="large">
                        <el-table-column label="名称" min-width="130">
                            <template #default="{ row }">
                                <el-input v-model="row.name" placeholder="套餐名称" />
                            </template>
                        </el-table-column>
                        <el-table-column label="月数" width="110">
                            <template #default="{ row }">
                                <el-input-number v-model="row.duration_months" :min="1" :controls="false" class="w-full" />
                            </template>
                        </el-table-column>
                        <el-table-column label="开通点数" width="130">
                            <template #default="{ row }">
                                <el-input-number v-model="row.open_points" :min="0" :precision="2" :controls="false" class="w-full" />
                            </template>
                        </el-table-column>
                        <el-table-column label="续费点数" width="130">
                            <template #default="{ row }">
                                <el-input-number v-model="row.renew_points" :min="0" :precision="2" :controls="false" class="w-full" />
                            </template>
                        </el-table-column>
                        <el-table-column label="状态" width="100">
                            <template #default="{ row }">
                                <el-switch v-model="row.status" :active-value="1" :inactive-value="0" />
                            </template>
                        </el-table-column>
                        <el-table-column label="排序" width="90">
                            <template #default="{ row }">
                                <el-input-number v-model="row.sort" :controls="false" class="w-full" />
                            </template>
                        </el-table-column>
                        <el-table-column label="操作" width="120" fixed="right">
                            <template #default="{ row }">
                                <el-button type="primary" link @click="savePlan(row)">保存</el-button>
                                <el-button type="danger" link @click="deletePlan(row)">删除</el-button>
                            </template>
                        </el-table-column>
                    </el-table>
                    <el-empty v-if="planRows.length === 0" description="暂无套餐" />
                </div>
            </div>
        </el-drawer>
    </div>
</template>

<script lang="ts" setup name="app-center">
import {
    appApplyPackage,
    appCloudDetail,
    appCloudLists,
    appDeletePlan,
    appDetail,
    appDisable,
    appDownloadPackage,
    appEnable,
    appInstall,
    appLists,
    appPlans,
    appPreflightPackage,
    appSaveExpirePolicy,
    appSavePlan,
    appUninstall
} from '@/api/app_center'
import { useLocalPaging } from '@/hooks/useLocalPaging'
import feedback from '@/utils/feedback'

const defaultAppCodes = ['aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_canvas', 'aigc_llm']
const loading = ref(false)
const cloudLoading = ref(false)
const cloudLoaded = ref(false)
const applying = ref(false)
const lists = ref<any[]>([])
const cloudLists = ref<any[]>([])
const { pager, tableLists: pagedApps, setLists, resetPage } = useLocalPaging({ size: 15 })
const detailVisible = ref(false)
const detail = ref<any>({})
const uninstallVisible = ref(false)
const uninstallCode = ref('')
const clearData = ref(false)
const packageVisible = ref(false)
const currentPackage = ref<any>({})
const preflightResult = ref<any>({})
const planVisible = ref(false)
const planLoading = ref(false)
const policySaving = ref(false)
const planApp = ref<any>({})
const planRows = ref<any[]>([])
const expirePolicy = ref('block')
const filterForm = reactive({
    keyword: '',
    category: '',
    source: '',
    status: ''
})

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await appLists()
    } finally {
        loading.value = false
    }
}

const handleCloudLists = async (showSuccess = false) => {
    cloudLoading.value = true
    try {
        const data = await appCloudLists()
        cloudLists.value = normalizeCloudLists(data)
        cloudLoaded.value = true
        if (showSuccess) {
            feedback.msgSuccess('云端应用已刷新')
        }
    } finally {
        cloudLoading.value = false
    }
}

const normalizeCloudLists = (data: any) => {
    if (Array.isArray(data)) return data
    if (Array.isArray(data?.data?.lists)) return data.data.lists
    if (Array.isArray(data?.lists)) return data.lists
    if (Array.isArray(data?.apps)) return data.apps
    return []
}

const displayApps = computed(() => {
    const map = new Map<string, any>()
    lists.value.forEach((item) => {
        const code = item.code || item.app_code
        if (!code) return
        map.set(code, normalizeLocalApp(item))
    })
    if (!cloudLoaded.value) {
        return Array.from(map.values()).sort((a, b) => Number(b.sort || 0) - Number(a.sort || 0))
    }
    const cloudMap = new Map<string, any>()
    cloudLists.value.forEach((item) => {
        const code = item.app_code || item.code
        if (!code) return
        const local = map.get(code)
        cloudMap.set(code, normalizeCloudApp(item, local))
    })
    return Array.from(cloudMap.values()).sort((a, b) => Number(b.sort || 0) - Number(a.sort || 0))
})

const filteredApps = computed(() => {
    const keyword = filterForm.keyword.trim().toLowerCase()
    return displayApps.value.filter((item) => {
        if (keyword && !`${item.name} ${item.code}`.toLowerCase().includes(keyword)) return false
        if (filterForm.category && item.category !== filterForm.category) return false
        if (filterForm.source === 'local' && !item.has_local) return false
        if (filterForm.source === 'cloud' && !item.has_cloud) return false
        if (filterForm.status && item.status !== filterForm.status) return false
        return true
    })
})

watch(filteredApps, (rows) => setLists(rows), { immediate: true })

const categoryOptions = computed(() => {
    const categories = new Set<string>()
    displayApps.value.forEach((item) => {
        if (item.category) categories.add(item.category)
    })
    return Array.from(categories).map((value) => ({ value, label: categoryText(value) }))
})

const normalizeLocalApp = (item: any) => ({
    key: item.code || item.app_code,
    code: item.code || item.app_code,
    name: item.name || item.app_name || item.code,
    description: item.description || '',
    category: item.category || '',
    icon: item.icon || '',
    cover: item.cover || '',
    sort: item.sort || 0,
    status: item.status || 'installed',
    local_version: item.current_version || item.version || '',
    cloud_version: '',
    client_tags: item.client_tags || '',
    is_builtin: Number(item.is_builtin || 0) === 1 || defaultAppCodes.includes(item.code || item.app_code),
    expire_policy: defaultAppCodes.includes(item.code || item.app_code) ? 'allow' : item.expire_policy || 'block',
    has_local: true,
    has_cloud: false,
    local: item,
    cloud: null
})

const normalizeCloudApp = (item: any, local?: any) => {
    const code = item.app_code || item.code
    const latestInfo = item.latest_version_info || {}
    const cloudVersion = item.latest_version || latestInfo.version || item.version || ''
    return {
        key: code,
        code,
        name: local?.name || item.name || item.app_name || code,
        description: item.description || item.summary || local?.description || '',
        category: item.category || local?.category || '',
        icon: local?.icon || item.icon || '',
        cover: item.cover || local?.cover || '',
        sort: item.sort || local?.sort || 0,
        status: local?.status || 'not_installed',
        local_version: local?.local_version || '',
        cloud_version: cloudVersion,
        client_tags: local?.client_tags || item.client_tags || item.frontends || '',
        is_builtin: local?.is_builtin || false,
        expire_policy: local?.expire_policy || item.expire_policy || 'block',
        is_opened: item.is_opened !== false,
        auth_status: item.auth_status || '',
        auth_message: item.auth_message || '',
        require_core: item.require_core || latestInfo.require_core || '',
        latest_version_info: latestInfo,
        changelog: item.changelog || latestInfo.changelog || [],
        has_local: !!local,
        has_cloud: true,
        local: local?.local || null,
        cloud: item
    }
}

const resetFilter = () => {
    filterForm.keyword = ''
    filterForm.category = ''
    filterForm.source = ''
    filterForm.status = ''
    resetPage()
}

const handlePageChange = () => {
    setLists(filteredApps.value)
}

const statusText = (status: string) =>
    ({ installed: '已启用', disabled: '已禁用', removed: '已卸载', not_installed: '未安装' }[status] || status)
const statusType = (status: string) =>
    status === 'installed' ? 'success' : status === 'disabled' ? 'warning' : status === 'not_installed' ? 'info' : 'danger'
const categoryText = (category: string) => ({ aigc: 'AIGC', common: '通用', system: '系统' }[category] || category)
const authStatusText = (status: string) =>
    ({
        available: '已授权',
        tenant_not_opened: '租户未开通',
        tenant_disabled: '租户已禁用',
        tenant_expired: '租户已过期',
        user_not_opened: '用户未开通',
        user_disabled: '用户已禁用',
        user_expired: '用户已过期'
    }[status] || '未授权')
const defaultAppIcon = '/resource/image/common/default_avatar.png'
const iconSrc = (row: any) => {
    const value = String(row.icon || row.cover || '').trim()
    if (!value || value.startsWith('el-icon-')) {
        return defaultAppIcon
    }
    if (/^(https?:)?\/\//.test(value) || value.startsWith('/')) {
        return value
    }
    return `/${value.replace(/^\/+/, '')}`
}
const handleIconError = (event: Event) => {
    const target = event.target as HTMLImageElement
    if (target.src.includes(defaultAppIcon)) return
    target.src = defaultAppIcon
}

const openDetail = async (row: any) => {
    if (row.has_local) {
        detail.value = await appDetail({ app_code: row.code })
    } else {
        detail.value = await appCloudDetail({ app_code: row.code })
    }
    detailVisible.value = true
}
const handleEnable = async (appCode: string) => {
    if (defaultAppCodes.includes(appCode)) return
    await appEnable({ app_code: appCode })
    getLists()
}
const handleInstall = async (appCode: string) => {
    await appInstall({ app_code: appCode })
    getLists()
}
const handleDisable = async (appCode: string) => {
    if (defaultAppCodes.includes(appCode)) return
    await feedback.confirm('禁用后租户端和用户端将不能使用该应用，确定继续？')
    await appDisable({ app_code: appCode })
    getLists()
}
const handleDownload = async (row: any) => {
    if (row.has_cloud && !row.is_opened) {
        feedback.msgError(row.auth_message || authStatusText(row.auth_status))
        return
    }
    const source = row.cloud || row
    const appCode = row.code || source.app_code || source.code
    const targetVersion = row.cloud_version || source.version || source.latest_version
    if (!appCode || !targetVersion) {
        feedback.msgError('云端应用缺少 app_code 或版本号')
        return
    }
    const action = row.has_local ? 'update' : 'install'
    const pkg = await appDownloadPackage({ app_code: appCode, target_version: targetVersion, action })
    currentPackage.value = pkg
    preflightResult.value = await appPreflightPackage({ package_id: pkg.id, app_code: appCode })
    packageVisible.value = true
}
const handleApply = async () => {
    applying.value = true
    try {
        await appApplyPackage({ package_id: currentPackage.value.id })
        packageVisible.value = false
        await getLists()
        await handleCloudLists()
    } finally {
        applying.value = false
    }
}
const openUninstall = (appCode: string) => {
    if (defaultAppCodes.includes(appCode)) return
    uninstallCode.value = appCode
    clearData.value = false
    uninstallVisible.value = true
}
const handleUninstall = async () => {
    await appUninstall({ app_code: uninstallCode.value, clear_data: clearData.value ? 1 : 0 })
    uninstallVisible.value = false
    await getLists()
    await handleCloudLists()
}
const openPlanDrawer = async (row: any) => {
    if (row.is_builtin || defaultAppCodes.includes(row.code)) return
    planApp.value = row
    expirePolicy.value = row.expire_policy || 'block'
    planVisible.value = true
    await loadPlans()
}
const loadPlans = async () => {
    planLoading.value = true
    try {
        planRows.value = await appPlans({ app_code: planApp.value.code })
    } finally {
        planLoading.value = false
    }
}
const addPlan = () => {
    planRows.value.unshift({
        id: 0,
        app_code: planApp.value.code,
        name: '',
        duration_months: 12,
        open_points: 0,
        renew_points: 0,
        status: 1,
        sort: 0
    })
}
const savePlan = async (row: any) => {
    await appSavePlan({
        id: row.id || 0,
        app_code: planApp.value.code,
        name: row.name,
        duration_months: row.duration_months,
        open_points: row.open_points,
        renew_points: row.renew_points,
        status: row.status,
        sort: row.sort
    })
    feedback.msgSuccess('保存成功')
    await loadPlans()
}
const deletePlan = async (row: any) => {
    if (!row.id) {
        planRows.value = planRows.value.filter((item) => item !== row)
        return
    }
    await feedback.confirm('删除已使用的套餐会自动改为禁用，确定继续？')
    await appDeletePlan({ app_code: planApp.value.code, id: row.id })
    feedback.msgSuccess('操作成功')
    await loadPlans()
}
const savePolicy = async () => {
    policySaving.value = true
    try {
        await appSaveExpirePolicy({ app_code: planApp.value.code, expire_policy: expirePolicy.value })
        feedback.msgSuccess('保存成功')
        const local = lists.value.find((item) => item.code === planApp.value.code)
        if (local) local.expire_policy = expirePolicy.value
        planApp.value.expire_policy = expirePolicy.value
    } finally {
        policySaving.value = false
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

const initLists = async () => {
    await Promise.allSettled([getLists(), handleCloudLists()])
}

initLists()
</script>

<style lang="scss" scoped>
.app-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-top: 6px;
}

.app-card {
    display: flex;
    flex-direction: column;
    min-height: 238px;
    padding: 20px;
    background: #fff;
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgb(31 35 41 / 6%);
    transition:
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        transform 0.2s ease;
}

.app-card:hover {
    border-color: var(--el-color-primary-light-5);
    box-shadow: 0 12px 30px rgb(31 35 41 / 10%);
    transform: translateY(-2px);
}

.app-card__main {
    display: flex;
    gap: 14px;
}

.app-card__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 52px;
    width: 52px;
    height: 52px;
    color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    border: 1px solid var(--el-color-primary-light-7);
    border-radius: 8px;
    overflow: hidden;
}

.app-card__icon img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.app-card__name {
    overflow: hidden;
    font-size: 17px;
    font-weight: 600;
    line-height: 24px;
    color: var(--el-text-color-primary);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.app-card__code {
    margin-top: 4px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
}

.app-card__desc {
    display: -webkit-box;
    height: 44px;
    margin-top: 14px;
    overflow: hidden;
    font-size: 14px;
    line-height: 22px;
    color: var(--el-text-color-regular);
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

.app-card__meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    padding: 12px;
    margin-top: 16px;
    background: var(--el-fill-color-lighter);
    border-radius: 8px;
}

.app-card__meta-label {
    display: block;
    margin-bottom: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
}

.app-card__meta-value {
    display: block;
    overflow: hidden;
    font-size: 13px;
    color: var(--el-text-color-primary);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.app-card__tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    min-height: 24px;
    margin-top: 14px;
}

.app-card__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 10px;
    padding-top: 14px;
    margin-top: auto;
    border-top: 1px solid var(--el-border-color-lighter);
}

.section-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--el-text-color-primary);
}

@media (max-width: 1279px) {
    .app-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .app-grid {
        grid-template-columns: 1fr;
    }

    .app-card__meta {
        grid-template-columns: 1fr;
    }
}
</style>
