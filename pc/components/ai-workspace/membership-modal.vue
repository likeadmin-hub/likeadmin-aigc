<template>
    <Teleport to="body">
        <Transition name="membership-modal-fade">
            <div v-if="modelValue" class="membership-modal-shell">
                <div class="membership-modal-shell__background"></div>
                <div class="membership-modal-shell__noise"></div>
                <div class="membership-modal-shell__stars membership-modal-shell__stars--near"></div>
                <div class="membership-modal-shell__stars membership-modal-shell__stars--far"></div>
                <section class="membership-modal" aria-modal="true" role="dialog">
                    <button class="membership-modal__close" type="button" aria-label="关闭会员弹窗" @click="close">
                        <span></span>
                        <span></span>
                    </button>

                    <div class="membership-modal__inner">
                        <header class="membership-modal__hero">
                            <h2>订阅会员畅想更多权益</h2>
                            <p>
                                <span>选择适合你的会员套餐，或</span>
                                <button type="button" @click="handleRecharge">购买积分</button>
                            </p>
                        </header>

                        <div class="membership-modal__billing" role="tablist" aria-label="会员计费周期">
                            <button
                                :class="{ 'is-active': billingCycle === 'monthly' }"
                                type="button"
                                @click="billingCycle = 'monthly'"
                            >
                                按月购买
                            </button>
                            <button
                                :class="{ 'is-active': billingCycle === 'yearly' }"
                                type="button"
                                @click="billingCycle = 'yearly'"
                            >
                                按年购买
                            </button>
                        </div>

                        <section class="membership-modal__plans">
                            <article
                                v-for="plan in pricedPlans"
                                :key="plan.id"
                                :class="[
                                    'membership-plan',
                                    {
                                        'is-current': isCurrentPlan(plan),
                                        'is-recommended': plan.isRecommended
                                    }
                                ]"
                            >
                                <div class="membership-plan__content">
                                    <div class="membership-plan__head">
                                        <div class="membership-plan__head-top">
                                            <h3>{{ plan.title }}</h3>
                                            <span v-if="plan.isRecommended" class="membership-plan__badge">推荐</span>
                                        </div>
                                        <p>{{ plan.description }}</p>
                                    </div>

                                    <div class="membership-plan__price-block">
                                        <div v-if="plan.priceLabel === '免费'" class="membership-plan__price membership-plan__price--free">
                                            免费
                                        </div>
                                        <div v-else class="membership-plan__price-row">
                                            <div class="membership-plan__price">
                                                <span class="membership-plan__currency">¥</span>
                                                <strong>{{ plan.priceLabel }}</strong>
                                            </div>
                                            <span v-if="plan.marketPrice" class="membership-plan__market-price">¥{{ plan.marketPrice }}</span>
                                        </div>

                                        <template v-if="plan.bonus">
                                            <strong class="membership-plan__bonus">赠送 {{ plan.bonus }} 积分</strong>
                                            <span class="membership-plan__bonus-tip">{{ plan.bonusTip }}</span>
                                        </template>
                                    </div>

                                    <ul class="membership-plan__features">
                                        <li v-for="feature in plan.features" :key="feature">{{ feature }}</li>
                                    </ul>
                                </div>

                                <button
                                    :class="['membership-plan__action', { 'is-current': isCurrentPlan(plan) }]"
                                    :disabled="isCurrentPlan(plan)"
                                    type="button"
                                    @click="handleSubscribe(plan.id)"
                                >
                                    {{ isCurrentPlan(plan) ? '当前套餐' : '订阅计划' }}
                                </button>
                            </article>
                        </section>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { MEMBERSHIP_PLANS, type MembershipPlanDefinition, type MembershipPlanId } from '@/constants/membership-plans'

type BillingCycle = 'monthly' | 'yearly'

interface Props {
    modelValue: boolean
    membershipEnabled: boolean
    currentPlan: MembershipPlanId
    plans?: MembershipPlanDefinition[]
}

const props = defineProps<Props>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'recharge'): void
    (e: 'subscribe', plan: MembershipPlanId, cycle: BillingCycle): void
}>()

const billingCycle = ref<BillingCycle>('monthly')

const membershipPlans = computed<MembershipPlanDefinition[]>(() => {
    if (props.plans?.length) {
        const hasFreePlan = props.plans.some((plan) => plan.free || (Number(plan.monthlyPrice) <= 0 && Number(plan.yearlyPrice) <= 0))
        return hasFreePlan ? props.plans : [MEMBERSHIP_PLANS[0], ...props.plans]
    }
    return MEMBERSHIP_PLANS
})

const pricedPlans = computed(() => membershipPlans.value.map((plan) => {
    if (plan.free) {
        return {
            ...plan,
            priceLabel: '免费',
            marketPrice: '',
            bonus: '',
            bonusTip: ''
        }
    }

    return {
        ...plan,
        priceLabel: billingCycle.value === 'monthly' ? plan.monthlyPrice ?? '' : plan.yearlyPrice ?? '',
        marketPrice: billingCycle.value === 'monthly' ? plan.monthlyMarketPrice ?? '' : plan.yearlyMarketPrice ?? '',
        bonus: billingCycle.value === 'monthly' ? plan.monthlyBonus ?? '' : plan.yearlyBonus ?? '',
        bonusTip: billingCycle.value === 'monthly' ? plan.monthlyBonusTip ?? '' : plan.yearlyBonusTip ?? ''
    }
}))

const isCurrentPlan = (plan: MembershipPlanDefinition) => {
    return props.currentPlan === plan.id || (plan.free && props.currentPlan === 'free')
}

const close = () => emit('update:modelValue', false)

const handleSubscribe = (plan: MembershipPlanId) => {
    emit('subscribe', plan, billingCycle.value)
    close()
}

const handleRecharge = () => {
    emit('recharge')
}

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.modelValue) close()
}

const syncBodyScroll = (visible: boolean) => {
    if (typeof window === 'undefined') return
    document.documentElement.style.overflow = visible ? 'hidden' : ''
    document.body.style.overflow = visible ? 'hidden' : ''
}

watch(() => props.modelValue, (visible) => {
    if (visible) billingCycle.value = 'monthly'
    syncBodyScroll(visible)
}, { immediate: true })

onMounted(() => window.addEventListener('keydown', handleKeydown))

onBeforeUnmount(() => {
    syncBodyScroll(false)
    if (typeof window !== 'undefined') window.removeEventListener('keydown', handleKeydown)
})
</script>

<style lang="scss" scoped>
.membership-modal-shell {
    position: fixed;
    inset: 0;
    z-index: 90;
    overflow-y: auto;
    overscroll-behavior: contain;
    background: #050505;
}

.membership-modal-shell__background,
.membership-modal-shell__noise,
.membership-modal-shell__stars {
    position: fixed;
    inset: 0;
    pointer-events: none;
    will-change: opacity;
}

.membership-modal-shell__background {
    background-image: linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
}

.membership-modal-shell__noise {
    background-image:
        radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.65) 0 1px, transparent 1.8px),
        radial-gradient(circle at 12% 54%, rgba(255, 255, 255, 0.4) 0 1px, transparent 1.8px),
        radial-gradient(circle at 18% 32%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.6px),
        radial-gradient(circle at 26% 12%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.6px),
        radial-gradient(circle at 34% 58%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.8px),
        radial-gradient(circle at 42% 18%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.5px),
        radial-gradient(circle at 52% 10%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.5px),
        radial-gradient(circle at 61% 44%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.5px),
        radial-gradient(circle at 72% 20%, rgba(255, 255, 255, 0.48) 0 1px, transparent 1.7px),
        radial-gradient(circle at 84% 38%, rgba(255, 255, 255, 0.42) 0 1px, transparent 1.7px),
        radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.52) 0 1px, transparent 1.7px),
        radial-gradient(circle at 96% 52%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.8px);
    opacity: 0.24;
}

.membership-modal-shell__stars {
    opacity: 0.95;
    mix-blend-mode: screen;
}

.membership-modal-shell__stars--near {
    background-image:
        radial-gradient(circle at 10% 22%, rgba(255, 255, 255, 0.95) 0 1.4px, transparent 2.2px),
        radial-gradient(circle at 21% 68%, rgba(255, 255, 255, 0.92) 0 1.2px, transparent 2px),
        radial-gradient(circle at 36% 36%, rgba(255, 255, 255, 0.98) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 48% 14%, rgba(255, 255, 255, 0.9) 0 1.3px, transparent 2px),
        radial-gradient(circle at 57% 60%, rgba(255, 255, 255, 0.9) 0 1.1px, transparent 1.8px),
        radial-gradient(circle at 69% 28%, rgba(255, 255, 255, 0.96) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 82% 58%, rgba(255, 255, 255, 0.94) 0 1.25px, transparent 2px),
        radial-gradient(circle at 92% 20%, rgba(255, 255, 255, 0.9) 0 1.35px, transparent 2px);
    animation: membershipStarTwinkle 4.8s ease-in-out infinite alternate;
}

.membership-modal-shell__stars--far {
    background-image:
        radial-gradient(circle at 14% 10%, rgba(160, 203, 255, 0.8) 0 1px, transparent 1.8px),
        radial-gradient(circle at 30% 48%, rgba(255, 255, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 44% 72%, rgba(178, 193, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 60% 8%, rgba(255, 255, 255, 0.68) 0 1px, transparent 1.6px),
        radial-gradient(circle at 78% 42%, rgba(181, 220, 255, 0.75) 0 0.95px, transparent 1.6px),
        radial-gradient(circle at 88% 74%, rgba(255, 255, 255, 0.7) 0 1px, transparent 1.6px);
    animation: membershipStarTwinkle 6.2s ease-in-out infinite alternate-reverse;
}

.membership-modal {
    position: relative;
    z-index: 1;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    padding: 36px 40px 72px;
    box-sizing: border-box;
}

.membership-modal__close {
    position: fixed;
    top: 20px;
    right: 24px;
    z-index: 2;
    width: 42px;
    height: 42px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.08);
    cursor: pointer;
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.membership-modal__close:hover {
    background: rgba(255, 255, 255, 0.14);
    transform: translateY(-1px);
}

.membership-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.78);
}

.membership-modal__close span:first-child {
    transform: rotate(45deg);
}

.membership-modal__close span:last-child {
    transform: rotate(-45deg);
}

.membership-modal__inner {
    width: min(100%, 1360px);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 38px;
    padding-top: 132px;
}

.membership-modal__hero {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 18px;
    text-align: center;
}

.membership-modal__hero h2 {
    margin: 0;
    color: #fff;
    font-size: 58px;
    font-weight: 700;
    line-height: 1.14;
    white-space: nowrap;
}

.membership-modal__hero p {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    color: rgba(255, 255, 255, 0.76);
    font-size: 18px;
    line-height: 1.4;
    white-space: nowrap;
}

.membership-modal__hero button {
    padding: 0;
    border: 0;
    background: transparent;
    color: #ff6600;
    font-size: 18px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}

.membership-modal__billing {
    display: inline-grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px;
    padding: 6px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 999px;
    background: rgba(20, 20, 20, 0.92);
}

.membership-modal__billing button {
    min-width: 116px;
    height: 44px;
    padding: 0 20px;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: rgba(255, 255, 255, 0.64);
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.membership-modal__billing button.is-active {
    background: #fff;
    color: #111;
}

.membership-modal__plans {
    width: 100%;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 18px;
}

.membership-plan {
    position: relative;
    min-height: 406px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: rgba(26, 26, 26, 0.92);
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.28);
    color: #fff;
    box-sizing: border-box;
    overflow: hidden;
}

.membership-plan::before {
    content: '';
    position: absolute;
    inset: 0;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0)),
        radial-gradient(circle at 50% -28%, rgba(255, 255, 255, 0.06), transparent 55%);
    pointer-events: none;
}

.membership-plan.is-recommended {
    border-color: rgba(255, 149, 84, 0.26);
    background:
        radial-gradient(circle at 50% -10%, rgba(255, 122, 48, 0.3), transparent 48%),
        linear-gradient(180deg, rgba(109, 53, 28, 0.96) 0%, rgba(53, 30, 20, 0.88) 28%, rgba(26, 26, 26, 0.96) 56%, rgba(24, 24, 24, 0.94) 100%);
    box-shadow:
        inset 0 1px 0 rgba(255, 196, 152, 0.12),
        0 24px 60px rgba(0, 0, 0, 0.32);
}

.membership-plan.is-recommended::before {
    background:
        linear-gradient(180deg, rgba(255, 210, 180, 0.06), rgba(255, 255, 255, 0) 38%),
        radial-gradient(circle at 50% -18%, rgba(255, 150, 84, 0.3), transparent 44%);
}

.membership-plan.is-current {
    box-shadow:
        inset 0 0 0 1px rgba(255, 255, 255, 0.04),
        0 24px 60px rgba(0, 0, 0, 0.28);
}

.membership-plan.is-current.is-recommended {
    box-shadow:
        inset 0 0 0 1px rgba(255, 186, 140, 0.12),
        inset 0 1px 0 rgba(255, 210, 180, 0.08),
        0 24px 60px rgba(0, 0, 0, 0.34);
}

.membership-plan__content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.membership-plan__head {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.membership-plan__head-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.membership-plan__head h3 {
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
}

.membership-plan__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 28px;
    padding: 0 12px;
    border: 1px solid rgba(255, 153, 89, 0.16);
    border-radius: 999px;
    background: linear-gradient(180deg, rgba(62, 28, 15, 0.92), rgba(48, 20, 11, 0.96));
    color: #ff8a47;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
    box-shadow: inset 0 1px 0 rgba(255, 193, 142, 0.08);
}

.membership-plan__head p {
    margin: 0;
    color: rgba(255, 255, 255, 0.56);
    font-size: 14px;
    line-height: 1.4;
    white-space: nowrap;
}

.membership-plan__price-block {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 104px;
}

.membership-plan__price {
    display: inline-flex;
    align-items: flex-end;
    gap: 6px;
}

.membership-plan__price strong,
.membership-plan__price--free {
    font-size: 34px;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
}

.membership-plan__currency {
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
}

.membership-plan__price-row {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    white-space: nowrap;
}

.membership-plan__market-price {
    color: rgba(255, 255, 255, 0.42);
    font-size: 14px;
    line-height: 1.6;
    text-decoration: line-through;
}

.membership-plan__bonus {
    color: rgba(255, 255, 255, 0.94);
    font-size: 18px;
    font-weight: 600;
    line-height: 1.4;
    white-space: nowrap;
}

.membership-plan__bonus-tip {
    color: rgba(255, 255, 255, 0.46);
    font-size: 14px;
    line-height: 1.4;
    white-space: nowrap;
}

.membership-plan__features {
    display: flex;
    flex-direction: column;
    gap: 14px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.membership-plan__features li {
    color: rgba(255, 255, 255, 0.8);
    font-size: 14px;
    line-height: 1.4;
    white-space: nowrap;
}

.membership-plan__action {
    width: 100%;
    height: 44px;
    margin-top: 28px;
    border: 0;
    border-radius: 12px;
    background: #fff;
    color: #111;
    font-size: 16px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        opacity 0.2s ease,
        transform 0.2s ease;
    position: relative;
    z-index: 1;
}

.membership-plan__action:hover {
    transform: translateY(-1px);
}

.membership-plan__action.is-current,
.membership-plan__action:disabled {
    border: 1px solid rgba(255, 255, 255, 0.06);
    background: rgba(255, 255, 255, 0.02);
    color: rgba(255, 255, 255, 0.44);
    cursor: default;
    transform: none;
}

.membership-modal-fade-enter-active,
.membership-modal-fade-leave-active {
    transition: opacity 0.2s ease;
}

.membership-modal-fade-enter-from,
.membership-modal-fade-leave-to {
    opacity: 0;
}

@keyframes membershipStarTwinkle {
    0% {
        opacity: 0.52;
        transform: scale(1);
    }

    100% {
        opacity: 1;
        transform: scale(1.015);
    }
}

@media (max-width: 1100px) {
    .membership-modal {
        padding-inline: 24px;
    }

    .membership-modal__inner {
        gap: 30px;
        padding-top: 104px;
    }

    .membership-modal__hero h2 {
        font-size: 44px;
        white-space: normal;
    }

    .membership-modal__hero p {
        flex-wrap: wrap;
        justify-content: center;
        white-space: normal;
    }

    .membership-modal__plans {
        grid-template-columns: 1fr;
        max-width: none;
    }

    .membership-plan__head p,
    .membership-plan__bonus-tip,
    .membership-plan__features li {
        white-space: normal;
    }
}

@media (max-width: 680px) {
    .membership-modal {
        padding: 24px 16px 48px;
    }

    .membership-modal__close {
        top: 16px;
        right: 16px;
    }

    .membership-modal__inner {
        padding-top: 84px;
    }

    .membership-modal__hero h2 {
        font-size: 34px;
    }

    .membership-modal__hero p,
    .membership-modal__hero button {
        font-size: 16px;
    }

    .membership-modal__billing {
        width: 100%;
    }

    .membership-modal__billing button {
        min-width: 0;
    }
}
</style>
