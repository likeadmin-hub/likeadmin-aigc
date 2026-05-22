<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <el-form class="mb-[-16px]" :model="queryParams" :inline="true">
                <el-form-item label="订单号">
                    <el-input
                        v-model="queryParams.order_sn"
                        class="w-[240px]"
                        placeholder="请输入订单号"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="用户信息">
                    <el-input
                        v-model="queryParams.user_info"
                        class="w-[240px]"
                        placeholder="账号/昵称/手机号"
                        clearable
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="支付状态">
                    <el-select v-model="queryParams.pay_status" class="w-[160px]">
                        <el-option label="全部" value />
                        <el-option label="未支付" :value="0" />
                        <el-option label="已支付" :value="1" />
                    </el-select>
                </el-form-item>
                <el-form-item label="购买周期">
                    <el-select v-model="queryParams.cycle" class="w-[160px]">
                        <el-option label="全部" value />
                        <el-option label="按月购买" value="monthly" />
                        <el-option label="按年购买" value="yearly" />
                    </el-select>
                </el-form-item>
                <el-form-item label="下单时间">
                    <daterange-picker
                        v-model:startTime="queryParams.start_time"
                        v-model:endTime="queryParams.end_time"
                    />
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="resetPage">查询</el-button>
                    <el-button @click="resetParams">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never">
            <el-table size="large" v-loading="pager.loading" :data="pager.lists">
                <el-table-column label="用户信息" min-width="160">
                    <template #default="{ row }">
                        <div class="flex items-center">
                            <image-contain
                                class="flex-none mr-2"
                                :src="row.avatar"
                                :width="40"
                                :height="40"
                                preview-teleported
                                fit="contain"
                            />
                            <div>
                                <div>{{ row.nickname || '-' }}</div>
                                <div class="text-xs text-tx-secondary">{{ row.account }}</div>
                            </div>
                        </div>
                    </template>
                </el-table-column>
                <el-table-column label="订单号" prop="order_sn" min-width="190" />
                <el-table-column label="套餐" prop="plan_name" min-width="130" />
                <el-table-column label="周期" prop="cycle_text" min-width="100" />
                <el-table-column label="金额" prop="order_amount" min-width="100" />
                <el-table-column label="赠送积分" prop="bonus_points" min-width="100" />
                <el-table-column label="支付方式" prop="pay_way_text" min-width="100" />
                <el-table-column label="支付状态" min-width="100">
                    <template #default="{ row }">
                        <span :class="{ 'text-error': row.pay_status == 0 }">{{
                            row.pay_status_text
                        }}</span>
                    </template>
                </el-table-column>
                <el-table-column label="会员到期" prop="after_expire_time_text" min-width="180" />
                <el-table-column label="提交时间" prop="create_time" min-width="180" />
                <el-table-column label="支付时间" prop="pay_time" min-width="180" />
            </el-table>
            <div class="flex justify-end mt-4">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="membershipOrder">
import { membershipOrderLists } from '@/api/finance'
import { usePaging } from '@/hooks/usePaging'

const queryParams = reactive({
    order_sn: '',
    user_info: '',
    pay_status: '',
    cycle: '',
    start_time: '',
    end_time: ''
})

const { pager, getLists, resetPage, resetParams } = usePaging({
    fetchFun: membershipOrderLists,
    params: queryParams
})

getLists()
</script>
