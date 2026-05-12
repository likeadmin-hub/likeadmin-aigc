<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="套餐名称">
                    <el-input v-model="queryParams.name" class="w-[240px]" placeholder="请输入套餐名称" clearable @keyup.enter="resetPage" />
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
                    <el-button v-perms="['finance.recharge_package/add']" type="primary" @click="openEdit()">新增套餐</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-table size="large" v-loading="pager.loading" :data="pager.lists">
                <el-table-column label="套餐名称" prop="name" min-width="140" />
                <el-table-column label="到账点数" prop="points" min-width="110" />
                <el-table-column label="售价" prop="amount" min-width="100">
                    <template #default="{ row }">¥{{ row.amount }}</template>
                </el-table-column>
                <el-table-column label="划线价" min-width="100">
                    <template #default="{ row }">
                        <span v-if="Number(row.market_amount || 0) > 0">¥{{ row.market_amount }}</span>
                        <span v-else class="text-tx-secondary">-</span>
                    </template>
                </el-table-column>
                <el-table-column label="推荐" min-width="90">
                    <template #default="{ row }">
                        <el-tag v-if="row.is_recommend == 1" type="warning">推荐</el-tag>
                        <span v-else class="text-tx-secondary">-</span>
                    </template>
                </el-table-column>
                <el-table-column label="状态" min-width="90">
                    <template #default="{ row }">
                        <el-tag :type="row.status == 1 ? 'success' : 'info'">{{ row.status == 1 ? '启用' : '停用' }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="排序" prop="sort" min-width="80" />
                <el-table-column label="操作" width="150" fixed="right">
                    <template #default="{ row }">
                        <el-button v-perms="['finance.recharge_package/edit']" type="primary" link @click="openEdit(row)">编辑</el-button>
                        <el-button v-perms="['finance.recharge_package/delete']" type="danger" link @click="handleDelete(row.id)">删除</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>

        <el-dialog v-model="showEdit" :title="formData.id ? '编辑算力套餐' : '新增算力套餐'" width="560px">
            <el-form :model="formData" label-width="100px">
                <el-form-item label="套餐名称" required>
                    <el-input v-model="formData.name" placeholder="请输入套餐名称" />
                </el-form-item>
                <el-form-item label="到账点数" required>
                    <el-input-number v-model="formData.points" :min="0.01" :precision="2" class="w-[180px]" />
                </el-form-item>
                <el-form-item label="售价" required>
                    <el-input-number v-model="formData.amount" :min="0" :precision="2" class="w-[180px]" />
                    <span class="ml-2 text-tx-secondary">元</span>
                </el-form-item>
                <el-form-item label="划线价">
                    <el-input-number v-model="formData.market_amount" :min="0" :precision="2" class="w-[180px]" />
                    <span class="ml-2 text-tx-secondary">元</span>
                </el-form-item>
                <el-form-item label="推荐">
                    <el-switch v-model="formData.is_recommend" :active-value="1" :inactive-value="0" />
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

<script lang="ts" setup name="rechargePackage">
import {
    rechargePackageAdd,
    rechargePackageDelete,
    rechargePackageEdit,
    rechargePackageLists
} from '@/api/finance'
import { usePaging } from '@/hooks/usePaging'
import feedback from '@/utils/feedback'

const queryParams = reactive({
    name: '',
    status: ''
})
const { pager, getLists, resetPage, resetParams } = usePaging({
    fetchFun: rechargePackageLists,
    params: queryParams
})

const showEdit = ref(false)
const saving = ref(false)
const defaultForm = () => ({
    id: 0,
    name: '',
    points: 0,
    amount: 0,
    market_amount: 0,
    is_recommend: 0,
    status: 1,
    sort: 0
})
const formData = reactive(defaultForm())

const openEdit = (row?: any) => {
    Object.assign(formData, defaultForm(), row || {})
    showEdit.value = true
}

const handleSave = async () => {
    if (!formData.name) return feedback.msgError('请输入套餐名称')
    if (Number(formData.points || 0) <= 0) return feedback.msgError('到账点数必须大于0')
    saving.value = true
    try {
        if (formData.id) {
            await rechargePackageEdit(formData)
        } else {
            await rechargePackageAdd(formData)
        }
        showEdit.value = false
        getLists()
    } finally {
        saving.value = false
    }
}

const handleDelete = async (id: number) => {
    await feedback.confirm('确认删除该算力套餐？')
    await rechargePackageDelete({ id })
    getLists()
}

getLists()
</script>
