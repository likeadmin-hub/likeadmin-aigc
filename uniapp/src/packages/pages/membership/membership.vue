<template>
    <page-meta :page-style="$theme.pageStyle">
        <!-- #ifndef H5 -->
        <navigation-bar :front-color="$theme.navColor" :background-color="$theme.navBgColor" />
        <!-- #endif -->
    </page-meta>
    <view class="membership-page">
        <view class="hero">
            <view>
                <view class="hero__label">会员中心</view>
                <view class="hero__title">{{ memberTitle }}</view>
                <view class="hero__desc">{{ memberDesc }}</view>
            </view>
            <view class="hero__badge">{{ isActive ? '已开通' : '待开通' }}</view>
        </view>

        <view class="cycle-tabs">
            <view
                class="cycle-tab"
                :class="{ 'is-active': cycle === 'monthly' }"
                @click="cycle = 'monthly'"
            >
                月付
            </view>
            <view
                class="cycle-tab"
                :class="{ 'is-active': cycle === 'yearly' }"
                @click="cycle = 'yearly'"
            >
                年付
            </view>
        </view>

        <page-status :status="pageStatus" :fixed="false">
            <template #empty>
                <u-empty text="暂无可购买会员套餐" mode="list"></u-empty>
            </template>
            <template #default>
                <view class="plan-list">
                    <view
                        v-for="plan in plans"
                        :key="plan.id"
                        class="plan-card"
                        :class="{
                            'is-active': selectedPlanId === plan.id,
                            'is-recommend': Number(plan.is_recommend) === 1
                        }"
                        @click="selectedPlanId = plan.id"
                    >
                        <view class="plan-card__head">
                            <view>
                                <view class="plan-card__name">{{ plan.name }}</view>
                                <view class="plan-card__desc">{{
                                    plan.description || '开通后解锁会员专享应用能力'
                                }}</view>
                            </view>
                            <view v-if="Number(plan.is_recommend) === 1" class="plan-card__tag"
                                >推荐</view
                            >
                        </view>
                        <view class="plan-card__price">
                            <text>¥</text>
                            <strong>{{ priceOf(plan) }}</strong>
                            <span>/{{ cycle === 'yearly' ? '年' : '月' }}</span>
                            <del v-if="marketPriceOf(plan)">¥{{ marketPriceOf(plan) }}</del>
                        </view>
                        <view v-if="bonusOf(plan)" class="plan-card__bonus"
                            >赠送 {{ bonusOf(plan) }} 积分</view
                        >
                        <view v-if="plan.apps?.length" class="plan-card__apps">
                            <view v-for="app in plan.apps" :key="app.app_code" class="app-chip">{{
                                app.name
                            }}</view>
                        </view>
                        <view v-if="plan.features?.length" class="plan-card__features">
                            <view
                                v-for="feature in plan.features"
                                :key="feature"
                                class="feature-item"
                            >
                                <u-icon name="checkmark-circle" color="#ffffff" size="24"></u-icon>
                                <text>{{ feature }}</text>
                            </view>
                        </view>
                    </view>
                </view>
            </template>
        </page-status>

        <view class="bottom-action">
            <u-button
                type="primary"
                shape="circle"
                :loading="isLock"
                :disabled="!selectedPlan"
                @click="createOrderLock"
            >
                {{ isActive ? '立即续费' : '立即开通' }}
            </u-button>
        </view>

        <payment
            v-model:show="payState.showPay"
            v-model:show-check="payState.showCheck"
            :order-id="payState.orderId"
            :from="payState.from"
            :redirect="payState.redirect"
            @success="handlePaySuccess"
            @fail="handlePayFail"
        />
    </view>
</template>

<script lang="ts" setup>
import { computed, reactive, ref } from 'vue'
import { onLoad, onShow } from '@dcloudio/uni-app'
import { PageStatusEnum } from '@/enums/appEnums'
import { useLockFn } from '@/hooks/useLockFn'
import { createMembershipOrder, getMembershipPlans, getMembershipStatus } from '@/api/membership'
import { useUserStore } from '@/stores/user'

const userStore = useUserStore()
const plans = ref<any[]>([])
const status = ref<any>({})
const pageStatus = ref(PageStatusEnum.LOADING)
const cycle = ref<'monthly' | 'yearly'>('monthly')
const selectedPlanId = ref<number | string>('')
const payState = reactive({
    orderId: 0,
    from: 'membership',
    showPay: false,
    showCheck: false,
    redirect: '/packages/pages/membership/membership'
})

const selectedPlan = computed(() => plans.value.find((plan) => plan.id === selectedPlanId.value))
const isActive = computed(() => status.value.member_status === 'active')
const memberTitle = computed(() => {
    if (!isActive.value) return '开通会员，解锁专享应用'
    return status.value.membership_plan || '会员已开通'
})
const memberDesc = computed(() => {
    if (!isActive.value) return '按月或按年订阅，支付成功后自动开通会员并赠送积分'
    return `有效期至 ${formatTime(status.value.member_expire_time)}`
})

const priceOf = (plan: any) =>
    String(cycle.value === 'yearly' ? plan.yearly_price : plan.monthly_price)
const marketPriceOf = (plan: any) => {
    const value = cycle.value === 'yearly' ? plan.yearly_market_price : plan.monthly_market_price
    return Number(value || 0) > 0 ? String(value) : ''
}
const bonusOf = (plan: any) => {
    const value = cycle.value === 'yearly' ? plan.yearly_bonus_points : plan.monthly_bonus_points
    return Number(value || 0) > 0 ? String(Number(value)) : ''
}
const formatTime = (time: number) => {
    if (!time) return '未开通'
    const date = new Date(time * 1000)
    const y = date.getFullYear()
    const m = String(date.getMonth() + 1).padStart(2, '0')
    const d = String(date.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
}

const loadData = async () => {
    pageStatus.value = PageStatusEnum.LOADING
    try {
        const [planRows, memberStatus] = await Promise.all([
            getMembershipPlans(),
            getMembershipStatus().catch(() => ({}))
        ])
        plans.value = Array.isArray(planRows) ? planRows : []
        status.value = memberStatus || {}
        if (!selectedPlanId.value && plans.value.length) {
            selectedPlanId.value =
                plans.value.find((plan) => Number(plan.is_recommend) === 1)?.id || plans.value[0].id
        }
        pageStatus.value = plans.value.length ? PageStatusEnum.NORMAL : PageStatusEnum.EMPTY
    } catch (error) {
        pageStatus.value = PageStatusEnum.ERROR
    }
}

const { isLock, lockFn: createOrderLock } = useLockFn(async () => {
    if (!selectedPlan.value) return uni.$u.toast('请选择会员套餐')
    const data = await createMembershipOrder({
        plan_id: selectedPlan.value.id,
        cycle: cycle.value
    })
    payState.orderId = Number(data.order_id || 0)
    payState.from = data.from || 'membership'
    payState.showPay = true
})

const handlePaySuccess = async () => {
    payState.showPay = false
    payState.showCheck = false
    await userStore.getUser()
    uni.navigateTo({
        url: `/pages/payment_result/payment_result?id=${payState.orderId}&from=${payState.from}`
    })
}

const handlePayFail = () => {
    uni.$u.toast('支付未完成')
}

onLoad((options: any) => {
    if (options?.checkPay) {
        payState.orderId = Number(options.id || 0)
        payState.from = options.from || 'membership'
        payState.showCheck = true
    }
})

onShow(loadData)
</script>

<style lang="scss" scoped>
.membership-page {
    min-height: 100vh;
    padding: 24rpx 24rpx 160rpx;
    background: #050505;
    color: #ffffff;
    box-sizing: border-box;
}

.hero {
    display: flex;
    justify-content: space-between;
    gap: 24rpx;
    padding: 36rpx 30rpx;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 18rpx;
    background: linear-gradient(135deg, #18191c, #0c0d0f);
}

.hero__label {
    color: rgba(255, 255, 255, 0.62);
    font-size: 24rpx;
}

.hero__title {
    margin-top: 14rpx;
    font-size: 38rpx;
    font-weight: 700;
}

.hero__desc {
    margin-top: 14rpx;
    color: rgba(255, 255, 255, 0.68);
    font-size: 24rpx;
}

.hero__badge {
    flex: none;
    height: 48rpx;
    padding: 0 20rpx;
    border-radius: 999rpx;
    background: #ffffff;
    color: #111111;
    line-height: 48rpx;
    font-size: 24rpx;
}

.cycle-tabs {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12rpx;
    margin: 24rpx 0;
    padding: 8rpx;
    border-radius: 999rpx;
    background: #171719;
}

.cycle-tab {
    height: 70rpx;
    border-radius: 999rpx;
    color: rgba(255, 255, 255, 0.62);
    text-align: center;
    line-height: 70rpx;
}

.cycle-tab.is-active {
    background: #ffffff;
    color: #111111;
    font-weight: 700;
}

.plan-list {
    display: flex;
    flex-direction: column;
    gap: 20rpx;
}

.plan-card {
    padding: 30rpx;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18rpx;
    background: #111214;
}

.plan-card.is-active {
    border-color: rgba(255, 255, 255, 0.62);
}

.plan-card__head {
    display: flex;
    justify-content: space-between;
    gap: 20rpx;
}

.plan-card__name {
    font-size: 34rpx;
    font-weight: 700;
}

.plan-card__desc {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 24rpx;
}

.plan-card__tag,
.app-chip {
    flex: none;
    height: 42rpx;
    padding: 0 16rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.12);
    color: rgba(255, 255, 255, 0.82);
    line-height: 42rpx;
    font-size: 22rpx;
}

.plan-card__price {
    display: flex;
    align-items: baseline;
    gap: 8rpx;
    margin-top: 26rpx;
}

.plan-card__price text {
    font-size: 26rpx;
}

.plan-card__price strong {
    font-size: 54rpx;
}

.plan-card__price span,
.plan-card__price del {
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
}

.plan-card__bonus {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.82);
    font-size: 24rpx;
}

.plan-card__apps,
.plan-card__features {
    display: flex;
    flex-wrap: wrap;
    gap: 12rpx;
    margin-top: 20rpx;
}

.feature-item {
    display: flex;
    align-items: center;
    gap: 8rpx;
    width: 100%;
    color: rgba(255, 255, 255, 0.7);
    font-size: 24rpx;
}

.bottom-action {
    position: fixed;
    right: 0;
    bottom: 0;
    left: 0;
    padding: 20rpx 24rpx calc(20rpx + env(safe-area-inset-bottom));
    background: rgba(5, 5, 5, 0.92);
    backdrop-filter: blur(12rpx);
}
</style>
