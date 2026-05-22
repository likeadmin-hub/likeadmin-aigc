<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="text-lg font-medium">应用市场</div>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table v-loading="loading" size="large" :data="lists">
                <el-table-column label="应用" min-width="220">
                    <template #default="{ row }">
                        <div class="font-medium">{{ row.name }}</div>
                        <div class="text-xs text-tx-secondary">{{ row.app_code }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="版本" prop="version" min-width="100" />
                <el-table-column label="平台状态" prop="platform_status" min-width="100" />
                <el-table-column label="上架状态" min-width="100">
                    <template #default="{ row }">
                        <el-tag :type="row.shelf_status === 'on' ? 'success' : 'info'">
                            {{ row.shelf_status === 'on' ? '已上架' : '已下架' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="到期时间" min-width="180">
                    <template #default="{ row }">
                        <div>{{ formatExpire(row.expire_time) }}</div>
                        <el-tag
                            v-if="row.is_expired"
                            class="mt-1"
                            :type="row.expire_policy === 'allow' ? 'warning' : 'danger'"
                            size="small"
                        >
                            {{
                                row.expire_policy === 'allow'
                                    ? '已过期，仍允许使用'
                                    : '已过期，不可使用'
                            }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" width="230" fixed="right">
                    <template #default="{ row }">
                        <template v-if="!row.is_builtin && row.app_code !== 'system_default'">
                            <el-button type="primary" link @click="openPlanDialog(row)"
                                >续签</el-button
                            >
                            <el-button
                                v-if="row.shelf_status !== 'on'"
                                type="primary"
                                link
                                @click="handleShelf(row.app_code, 'on')"
                                >上架</el-button
                            >
                            <el-button
                                v-else
                                type="warning"
                                link
                                @click="handleShelf(row.app_code, 'off')"
                                >下架</el-button
                            >
                        </template>
                        <el-tag v-else>系统应用</el-tag>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>

        <el-dialog v-model="planVisible" title="续签应用" width="560px">
            <div>
                <div class="font-medium">{{ currentApp.name }}</div>
                <div class="text-xs text-tx-secondary mt-1">{{ currentApp.app_code }}</div>
                <el-radio-group v-model="selectedPlanId" class="plan-list mt-4">
                    <el-radio-button
                        v-for="plan in currentApp.plans || []"
                        :key="plan.id"
                        :label="plan.id"
                    >
                        <div class="plan-item">
                            <div class="font-medium">{{ plan.name }}</div>
                            <div class="text-xs mt-1">{{ plan.duration_months }} 个月</div>
                            <div class="text-sm mt-2 text-primary">{{ plan.renew_points }} 点</div>
                        </div>
                    </el-radio-button>
                </el-radio-group>
                <el-empty v-if="(currentApp.plans || []).length === 0" description="暂无可用套餐" />
                <div class="text-xs text-tx-secondary mt-4">
                    当前到期时间：{{ formatExpire(currentApp.expire_time) }}
                </div>
            </div>
            <template #footer>
                <el-button @click="planVisible = false">取消</el-button>
                <el-button
                    type="primary"
                    :disabled="!selectedPlanId"
                    :loading="renewing"
                    @click="submitRenew"
                    >确认续签</el-button
                >
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="tenant-my-app">
import { buyApp, myApps, shelfApp } from '@/api/app_center'
import feedback from '@/utils/feedback'
import { timeFormat } from '@/utils/util'

const defaultAppCodes = [
    'aigc_image',
    'aigc_video',
    'aigc_digital_human',
    'aigc_canvas',
    'aigc_llm'
]
const loading = ref(false)
const renewing = ref(false)
const lists = ref<any[]>([])
const planVisible = ref(false)
const currentApp = ref<any>({})
const selectedPlanId = ref<number | string>('')
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await myApps()
    } finally {
        loading.value = false
    }
}
const handleShelf = async (appCode: string, status: string) => {
    if (defaultAppCodes.includes(appCode)) return
    if (status === 'off') {
        await feedback.confirm('下架后用户端入口和 API 将不可用，后台仍可管理历史数据。')
    }
    await shelfApp({ app_code: appCode, shelf_status: status })
    getLists()
}
const openPlanDialog = (row: any) => {
    if (row.is_builtin || defaultAppCodes.includes(row.app_code)) return
    currentApp.value = row
    selectedPlanId.value = (row.plans || [])[0]?.id || ''
    planVisible.value = true
}
const selectedPlan = computed(() =>
    (currentApp.value.plans || []).find(
        (item: any) => String(item.id) === String(selectedPlanId.value)
    )
)
const submitRenew = async () => {
    const plan = selectedPlan.value
    if (!plan) return
    await feedback.confirm(`本次将扣除租户点数 ${plan.renew_points}，确定继续？`)
    renewing.value = true
    try {
        await buyApp({ app_code: currentApp.value.app_code, plan_id: selectedPlanId.value })
        planVisible.value = false
        await getLists()
    } finally {
        renewing.value = false
    }
}
const formatExpire = (value: number) => {
    if (!value) return '永久'
    return timeFormat(Number(value), 'yyyy-mm-dd hh:MM')
}
getLists()
</script>

<style lang="scss" scoped>
.plan-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    width: 100%;
    gap: 12px;
}

.plan-list :deep(.el-radio-button__inner) {
    width: 100%;
    padding: 14px;
    border-left: var(--el-border);
    border-radius: 8px;
    text-align: left;
}

.plan-item {
    min-height: 72px;
}

@media (max-width: 768px) {
    .plan-list {
        grid-template-columns: 1fr;
    }
}
</style>
