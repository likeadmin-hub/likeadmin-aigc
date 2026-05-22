<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="套餐名称">
                    <el-input
                        v-model="queryParams.name"
                        class="w-[240px]"
                        placeholder="请输入套餐名称"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="状态">
                    <el-select v-model="queryParams.status" class="w-[160px]">
                        <el-option label="全部" value />
                        <el-option label="启用" :value="1" />
                        <el-option label="停用" :value="0" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="resetPage">查询</el-button>
                    <el-button @click="resetParams">重置</el-button>
                    <el-button
                        v-perms="['finance.membership_plan/add']"
                        type="primary"
                        @click="openEdit()"
                        >新增套餐</el-button
                    >
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table size="large" v-loading="pager.loading" :data="pager.lists">
                <el-table-column label="套餐名称" prop="name" min-width="140" />
                <el-table-column label="月付价格" prop="monthly_price" min-width="100" />
                <el-table-column label="年付价格" prop="yearly_price" min-width="100" />
                <el-table-column label="赠送积分" min-width="150">
                    <template #default="{ row }">
                        月 {{ row.monthly_bonus_points }} / 年 {{ row.yearly_bonus_points }}
                    </template>
                </el-table-column>
                <el-table-column label="关联应用" min-width="220">
                    <template #default="{ row }">
                        <el-tag
                            v-for="app in row.apps || []"
                            :key="app.app_code"
                            class="mr-1 mb-1"
                            size="small"
                        >
                            {{ app.name }}
                        </el-tag>
                        <span v-if="!(row.apps || []).length" class="text-tx-secondary"
                            >未关联</span
                        >
                    </template>
                </el-table-column>
                <el-table-column label="状态" min-width="90">
                    <template #default="{ row }">
                        <el-tag :type="row.status == 1 ? 'success' : 'info'">{{
                            row.status == 1 ? '启用' : '停用'
                        }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="排序" prop="sort" min-width="80" />
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            v-perms="['finance.membership_plan/edit']"
                            type="primary"
                            link
                            @click="openEdit(row)"
                            >编辑</el-button
                        >
                        <el-button
                            v-perms="['finance.membership_plan/delete']"
                            type="danger"
                            link
                            @click="handleDelete(row.id)"
                            >删除</el-button
                        >
                    </template>
                </el-table-column>
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>

        <el-dialog v-model="showEdit" :title="formData.id ? '编辑套餐' : '新增套餐'" width="720px">
            <el-form ref="formRef" :model="formData" label-width="110px">
                <el-form-item label="套餐名称" required>
                    <el-input v-model="formData.name" placeholder="请输入套餐名称" />
                </el-form-item>
                <el-form-item label="套餐简介">
                    <el-input v-model="formData.description" placeholder="请输入套餐简介" />
                </el-form-item>
                <el-form-item label="月付价格" required>
                    <el-input-number
                        v-model="formData.monthly_price"
                        :min="0"
                        :precision="2"
                        class="w-[180px]"
                    />
                    <span class="ml-2 text-tx-secondary">元</span>
                </el-form-item>
                <el-form-item label="年付价格" required>
                    <el-input-number
                        v-model="formData.yearly_price"
                        :min="0"
                        :precision="2"
                        class="w-[180px]"
                    />
                    <span class="ml-2 text-tx-secondary">元</span>
                </el-form-item>
                <el-form-item label="划线价">
                    <el-input-number
                        v-model="formData.monthly_market_price"
                        :min="0"
                        :precision="2"
                        class="w-[160px]"
                    />
                    <span class="mx-2 text-tx-secondary">月</span>
                    <el-input-number
                        v-model="formData.yearly_market_price"
                        :min="0"
                        :precision="2"
                        class="w-[160px]"
                    />
                    <span class="ml-2 text-tx-secondary">年</span>
                </el-form-item>
                <el-form-item label="赠送积分">
                    <el-input-number
                        v-model="formData.monthly_bonus_points"
                        :min="0"
                        :precision="2"
                        class="w-[160px]"
                    />
                    <span class="mx-2 text-tx-secondary">月</span>
                    <el-input-number
                        v-model="formData.yearly_bonus_points"
                        :min="0"
                        :precision="2"
                        class="w-[160px]"
                    />
                    <span class="ml-2 text-tx-secondary">年</span>
                </el-form-item>
                <el-form-item label="关联应用">
                    <el-select
                        v-model="formData.app_codes"
                        multiple
                        filterable
                        class="w-full"
                        placeholder="请选择已开通应用"
                    >
                        <el-option
                            v-for="app in appOptions"
                            :key="app.app_code"
                            :label="app.name"
                            :value="app.app_code"
                        />
                    </el-select>
                </el-form-item>
                <el-form-item label="权益说明">
                    <el-input
                        v-model="featuresText"
                        type="textarea"
                        :rows="5"
                        placeholder="一行一条权益"
                    />
                </el-form-item>
                <el-form-item label="推荐">
                    <el-switch
                        v-model="formData.is_recommend"
                        :active-value="1"
                        :inactive-value="0"
                    />
                </el-form-item>
                <el-form-item label="状态">
                    <el-switch v-model="formData.status" :active-value="1" :inactive-value="0" />
                </el-form-item>
                <el-form-item label="排序">
                    <el-input-number v-model="formData.sort" :min="0" />
                </el-form-item>
            </el-form>
            <template #footer>
                <el-button @click="showEdit = false">取消</el-button>
                <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="membershipPlan">
import {
    membershipPlanAdd,
    membershipPlanApps,
    membershipPlanDelete,
    membershipPlanEdit,
    membershipPlanLists
} from '@/api/finance'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const queryParams = reactive({
    name: '',
    status: ''
})
const { pager, getLists, resetPage, resetParams } = usePaging({
    fetchFun: membershipPlanLists,
    params: queryParams
})

const showEdit = ref(false)
const saving = ref(false)
const appOptions = ref<any[]>([])
const featuresText = ref('')
const defaultForm = () => ({
    id: 0,
    name: '',
    description: '',
    monthly_price: 0,
    yearly_price: 0,
    monthly_market_price: 0,
    yearly_market_price: 0,
    monthly_bonus_points: 0,
    yearly_bonus_points: 0,
    app_codes: [] as string[],
    is_recommend: 0,
    status: 1,
    sort: 0
})
const formData = reactive(defaultForm())

const loadApps = async () => {
    appOptions.value = await membershipPlanApps()
}

const openEdit = async (row?: any) => {
    Object.assign(formData, defaultForm(), row || {})
    formData.app_codes = [...(row?.app_codes || [])]
    featuresText.value = Array.isArray(row?.features) ? row.features.join('\n') : ''
    await loadApps()
    showEdit.value = true
}

const handleSave = async () => {
    if (!formData.name) return feedback.msgError('请输入套餐名称')
    saving.value = true
    try {
        const params = {
            ...formData,
            features: featuresText.value
        }
        if (formData.id) {
            await membershipPlanEdit(params)
        } else {
            await membershipPlanAdd(params)
        }
        showEdit.value = false
        getLists()
    } finally {
        saving.value = false
    }
}

const handleDelete = async (id: number) => {
    await feedback.confirm('确认删除该会员套餐？已有订单的套餐会自动停用。')
    await membershipPlanDelete({ id })
    getLists()
}

getLists()
</script>
