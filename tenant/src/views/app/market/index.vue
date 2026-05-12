<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form :inline="true" :model="queryParams" class="mb-[-16px]">
                <el-form-item label="应用名称">
                    <el-input v-model="queryParams.name" class="w-[240px]" clearable placeholder="名称/编码" @keyup.enter="getLists" />
                </el-form-item>
                <el-form-item label="是否开通">
                    <el-select v-model="queryParams.is_buy" class="w-[160px]" clearable placeholder="全部状态">
                        <el-option label="已开通" :value="1" />
                        <el-option label="未开通" :value="0" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="getLists">查询</el-button>
                    <el-button @click="resetQuery">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <div v-loading="loading" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 mt-4">
            <el-card v-for="row in lists" :key="row.code" class="app-card" shadow="never">
                <div class="flex justify-between gap-4">
                    <div class="min-w-0">
                        <div class="text-lg font-medium truncate">{{ row.name }}</div>
                        <div class="text-xs text-tx-secondary mt-1">{{ row.code }}</div>
                    </div>
                    <el-tag :type="row.is_buy ? 'success' : 'info'">
                        {{ row.is_buy ? '已开通' : '未开通' }}
                    </el-tag>
                </div>
                <div class="text-sm text-tx-secondary mt-4 h-[42px] line-clamp-2">{{ row.description || '暂无说明' }}</div>
                <div class="flex items-center gap-2 mt-4 text-xs text-tx-secondary">
                    <span>版本 {{ row.current_version || '-' }}</span>
                    <el-tag v-if="row.is_builtin" size="small">系统应用</el-tag>
                    <el-tag v-if="row.is_buy && !row.is_builtin" :type="row.shelf_status === 'on' ? 'success' : 'info'" size="small">
                        {{ row.shelf_status === 'on' ? '已上架' : '已下架' }}
                    </el-tag>
                    <el-tag v-if="row.is_expired" :type="row.expire_policy === 'allow' ? 'warning' : 'danger'" size="small">
                        {{ row.expire_policy === 'allow' ? '已过期仍可用' : '已过期' }}
                    </el-tag>
                </div>
                <div v-if="row.is_buy && !row.is_builtin" class="mt-3 text-xs text-tx-secondary">
                    到期时间：{{ formatExpire(row.expire_time) }}
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <el-button v-if="!row.is_buy" type="primary" @click="openPlanDialog(row, 'open')">开通</el-button>
                    <template v-else-if="!row.is_builtin">
                        <el-button type="primary" @click="openPlanDialog(row, 'renew')">续签</el-button>
                        <el-button v-if="row.shelf_status !== 'on'" type="primary" @click="handleShelf(row.code, 'on')">
                            上架
                        </el-button>
                        <el-button v-else type="warning" @click="handleShelf(row.code, 'off')">
                            下架
                        </el-button>
                    </template>
                    <el-button v-else type="primary" disabled>系统应用</el-button>
                </div>
            </el-card>
        </div>

        <el-dialog v-model="planVisible" :title="planAction === 'renew' ? '续签应用' : '开通应用'" width="560px">
            <div>
                <div class="font-medium">{{ currentApp.name }}</div>
                <div class="text-xs text-tx-secondary mt-1">{{ currentApp.code }}</div>
                <el-radio-group v-model="selectedPlanId" class="plan-list mt-4">
                    <el-radio-button v-for="plan in currentApp.plans || []" :key="plan.id" :label="plan.id">
                        <div class="plan-item">
                            <div class="font-medium">{{ plan.name }}</div>
                            <div class="text-xs mt-1">{{ plan.duration_months }} 个月</div>
                            <div class="text-sm mt-2 text-primary">
                                {{ planAction === 'renew' ? plan.renew_points : plan.open_points }} 点
                            </div>
                        </div>
                    </el-radio-button>
                </el-radio-group>
                <el-empty v-if="(currentApp.plans || []).length === 0" description="暂无可用套餐" />
                <div v-if="currentApp.is_buy" class="text-xs text-tx-secondary mt-4">
                    当前到期时间：{{ formatExpire(currentApp.expire_time) }}
                </div>
            </div>
            <template #footer>
                <el-button @click="planVisible = false">取消</el-button>
                <el-button type="primary" :disabled="!selectedPlanId" :loading="buying" @click="submitPlan">
                    确认{{ planAction === 'renew' ? '续签' : '开通' }}
                </el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="tenant-app-market">
import { appMarket, buyApp, shelfApp } from '@/api/app_center'
import feedback from '@/utils/feedback'
import { timeFormat } from '@/utils/util'

const defaultAppCodes = ['aigc_image', 'aigc_video', 'aigc_digital_human', 'aigc_canvas', 'aigc_llm']
const loading = ref(false)
const buying = ref(false)
const lists = ref<any[]>([])
const planVisible = ref(false)
const currentApp = ref<any>({})
const selectedPlanId = ref<number | string>('')
const planAction = ref<'open' | 'renew'>('open')
const queryParams = reactive({
    name: '',
    is_buy: ''
})
const getLists = async () => {
    loading.value = true
    try {
        lists.value = await appMarket(queryParams)
    } finally {
        loading.value = false
    }
}
const resetQuery = () => {
    Object.assign(queryParams, {
        name: '',
        is_buy: ''
    })
    getLists()
}
const openPlanDialog = (row: any, action: 'open' | 'renew') => {
    if (row.is_builtin || defaultAppCodes.includes(row.code)) return
    currentApp.value = row
    planAction.value = action
    selectedPlanId.value = (row.plans || [])[0]?.id || ''
    planVisible.value = true
}
const selectedPlan = computed(() =>
    (currentApp.value.plans || []).find((item: any) => String(item.id) === String(selectedPlanId.value))
)
const submitPlan = async () => {
    const plan = selectedPlan.value
    if (!plan) return
    const points = planAction.value === 'renew' ? plan.renew_points : plan.open_points
    await feedback.confirm(`本次将扣除租户点数 ${points}，确定继续？`)
    buying.value = true
    try {
        await buyApp({ app_code: currentApp.value.code, plan_id: selectedPlanId.value })
        planVisible.value = false
        await getLists()
    } finally {
        buying.value = false
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
const formatExpire = (value: number) => {
    if (!value) return '永久'
    return timeFormat(Number(value), 'yyyy-mm-dd hh:MM')
}
getLists()
</script>

<style lang="scss" scoped>
.app-card {
    border: 1px solid var(--el-border-color-light);
    border-radius: 8px;
    box-shadow: 0 8px 24px rgb(31 35 41 / 6%);
}

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
