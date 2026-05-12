<template>
    <Teleport to="body">
        <Transition name="credit-purchase-fade">
            <div v-if="modelValue" class="credit-purchase-mask" @click.self="close">
                <section class="credit-purchase-modal" aria-modal="true" role="dialog">
                    <header class="credit-purchase-modal__topbar">
                        <div class="credit-purchase-modal__account">
                            <img class="credit-purchase-modal__avatar" :src="displayAvatarUrl" alt="" />
                            <div class="credit-purchase-modal__account-text">
                                <strong>{{ displayMemberName }}</strong>
                                <p>
                                    <span>{{ membershipMessage }}</span>
                                    <button type="button" @click="handleRenew">
                                        {{ membershipEnabled ? '点击续费' : '开通会员' }}
                                    </button>
                                </p>
                            </div>
                        </div>

                        <div class="credit-purchase-modal__topbar-side">
                            <div class="credit-purchase-modal__balance">
                                <span>我的积分</span>
                                <strong>
                                    <img :src="sparkIcon" alt="" />
                                    {{ remainingCredits }}
                                </strong>
                            </div>

                            <button class="credit-purchase-modal__close" type="button" aria-label="关闭购买积分弹窗" @click="close">
                                <span></span>
                                <span></span>
                            </button>
                        </div>
                    </header>

                    <div class="credit-purchase-modal__panel">
                        <div class="credit-purchase-modal__title">
                            <span></span>
                            <strong>积分购买</strong>
                            <span></span>
                        </div>

                        <div class="credit-purchase-modal__content">
                            <div class="credit-purchase-modal__packs">
                                <button
                                    v-for="pack in creditPackages"
                                    :key="pack.id || pack.credits"
                                    :class="['credit-pack-card', { 'is-active': selectedCredits === pack.credits }]"
                                    type="button"
                                    @click="selectedCredits = pack.credits"
                                >
                                    <em v-if="pack.isRecommend">推荐</em>
                                    <strong>✦ {{ pack.credits }}</strong>
                                    <span>￥{{ pack.price }}</span>
                                    <del v-if="pack.marketPrice">￥{{ pack.marketPrice }}</del>
                                </button>
                            </div>

                            <aside class="credit-purchase-pay">
                                <div class="credit-purchase-pay__qr">
                                    <div class="credit-purchase-pay__qr-pattern"></div>
                                    <div class="credit-purchase-pay__overlay">
                                        <div class="credit-purchase-pay__title">
                                            <strong>支付前请阅读</strong>
                                            <button class="credit-purchase-pay__title-link" type="button" @click="handleViewAgreement">
                                                《付费用户协议》
                                            </button>
                                        </div>
                                        <button class="credit-purchase-pay__action" type="button" :disabled="paying" @click="handlePay">
                                            {{ paying ? '正在创建订单' : '同意并支付' }}
                                        </button>
                                    </div>
                                </div>

                                <div class="credit-purchase-pay__hint">
                                    <span>请扫码完成支付</span>
                                    <img :src="wechatIcon" alt="微信支付" />
                                    <span class="credit-purchase-pay__alipay">支</span>
                                </div>

                                <button class="credit-purchase-pay__agreement" type="button" @click="handleViewAgreement">
                                    购买说明与支付规则
                                </button>
                            </aside>
                        </div>

                        <footer class="credit-purchase-modal__footer">
                            温馨提示：积分仅支持站内使用，不可兑换会员、转赠、提现或退款；充值到账后有效期为2年，请按需购买并参考积分规则。
                        </footer>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createRechargeOrder, getRechargeConfig } from '@/api/user'
import { useAiUserDisplay } from '~/composables/useAiUserDisplay'
import feedback from '@/utils/feedback'
import wechatIcon from '@/assets/images/icon/icon_wx.png'
import sparkIcon from '@/assets/images/icon/lingganzhi.svg'

interface CreditPackage {
    id?: number
    name?: string
    credits: number
    price: string
    marketPrice?: string
    isRecommend?: boolean
}

interface Props {
    modelValue: boolean
    remainingCredits: number
    membershipEnabled: boolean
    memberName?: string
    creditPackages?: CreditPackage[]
}

const props = defineProps<Props>()

const { displayAvatarUrl, displayNickname } = useAiUserDisplay()

const displayMemberName = computed(() => props.memberName ?? displayNickname.value)

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'purchase', value: CreditPackage & { orderId?: number; from?: string }): void
    (e: 'renew-membership'): void
    (e: 'view-agreement'): void
}>()

const remoteCreditPackages = ref<CreditPackage[]>([])
const rechargeEnabled = ref(true)
const paying = ref(false)

const creditPackages = computed<CreditPackage[]>(() => {
    if (props.creditPackages?.length) return props.creditPackages
    if (remoteCreditPackages.value.length) return remoteCreditPackages.value
    return [
        { credits: 10, price: '10.00' },
        { credits: 30, price: '30.00' },
        { credits: 50, price: '50.00' },
        { credits: 100, price: '100.00' },
        { credits: 300, price: '300.00' },
        { credits: 500, price: '500.00' }
    ]
})

const selectedCredits = ref(10)
const selectedPackage = computed(() => (
    creditPackages.value.find((pack) => pack.credits === selectedCredits.value) ?? creditPackages.value[0]
))

const membershipMessage = computed(() => (
    props.membershipEnabled
        ? '会员每月可领取专属赠送积分。'
        : '开通会员可享每月赠送积分。'
))

const close = () => emit('update:modelValue', false)

const handlePay = async () => {
    if (paying.value) return
    if (!rechargeEnabled.value) {
        feedback.msgError('充值功能暂未开启')
        return
    }
    paying.value = true
    try {
        const orderParams = selectedPackage.value.id
            ? { package_id: selectedPackage.value.id }
            : { money: selectedPackage.value.credits }
        const order = await createRechargeOrder(orderParams)
        emit('purchase', {
            ...selectedPackage.value,
            orderId: Number(order?.order_id || 0),
            from: String(order?.from || 'recharge')
        })
        feedback.msgSuccess('充值订单已创建，请继续完成支付')
        close()
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '充值订单创建失败')
    } finally {
        paying.value = false
    }
}

const handleRenew = () => {
    emit('renew-membership')
    close()
}

const handleViewAgreement = () => {
    emit('view-agreement')
}

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.modelValue) {
        close()
    }
}

const syncBodyScroll = (visible: boolean) => {
    if (typeof window === 'undefined') return
    document.documentElement.style.overflow = visible ? 'hidden' : ''
    document.body.style.overflow = visible ? 'hidden' : ''
}

watch(() => props.modelValue, (visible) => {
    if (visible) {
        selectedCredits.value = creditPackages.value[0]?.credits ?? 0
    }

    syncBodyScroll(visible)
}, { immediate: true })

watch(creditPackages, (packages) => {
    if (!packages.length) return
    if (!packages.some((pack) => pack.credits === selectedCredits.value)) {
        selectedCredits.value = packages[0].credits
    }
}, { immediate: true })

const loadCreditPackages = async () => {
    if (props.creditPackages?.length) return
    try {
        const config = await getRechargeConfig()
        const minAmount = Math.max(1, Number(config?.min_amount || 0))
        rechargeEnabled.value = Number(config?.status ?? 1) === 1
        if (Array.isArray(config?.packages) && config.packages.length) {
            remoteCreditPackages.value = config.packages.map((item: any) => ({
                id: Number(item.id || 0),
                name: String(item.name || ''),
                credits: Number(item.points || 0),
                price: Number(item.amount || 0).toFixed(2),
                marketPrice: Number(item.market_amount || 0) > 0 ? Number(item.market_amount).toFixed(2) : '',
                isRecommend: Number(item.is_recommend || 0) === 1
            })).filter((item: CreditPackage) => item.credits > 0)
            return
        }
        const basePackages = [10, 30, 50, 100, 300, 500].filter((amount) => amount >= minAmount)
        const packages = basePackages.length ? basePackages : [minAmount]
        remoteCreditPackages.value = packages.map((amount) => ({
            credits: amount,
            price: Number(amount).toFixed(2)
        }))
    } catch (error) {
        console.error('load recharge config failed', error)
    }
}

onMounted(loadCreditPackages)
onMounted(() => window.addEventListener('keydown', handleKeydown))

onBeforeUnmount(() => {
    syncBodyScroll(false)
    window.removeEventListener('keydown', handleKeydown)
})
</script>

<style lang="scss" scoped>
.credit-purchase-mask {
    position: fixed;
    inset: 0;
    z-index: 96;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
}

.credit-purchase-modal {
    width: 880px;
    max-width: calc(100vw - 64px);
    display: flex;
    flex-direction: column;
    gap: 16px;
    padding: 18px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    color: #fff;
    box-sizing: border-box;
}

.credit-purchase-modal__topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 8px 10px 4px;
}

.credit-purchase-modal__account {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.credit-purchase-modal__avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.credit-purchase-modal__account-text {
    min-width: 0;
}

.credit-purchase-modal__account-text strong {
    display: block;
    margin-bottom: 4px;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
}

.credit-purchase-modal__account-text p {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
    min-width: 0;
}

.credit-purchase-modal__account-text p span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1.2;
    white-space: nowrap;
}

.credit-purchase-modal__account-text p button {
    padding: 0;
    border: 0;
    background: transparent;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    cursor: pointer;
}

.credit-purchase-modal__topbar-side {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-shrink: 0;
}

.credit-purchase-modal__balance {
    display: flex;
    align-items: center;
    gap: 12px;
}

.credit-purchase-modal__balance > span {
    color: rgba(255, 255, 255, 0.72);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
}

.credit-purchase-modal__balance strong {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 30px;
    padding: 0 12px;
    border-radius: 999px;
    background: #222222;
    color: #fff;
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
}

.credit-purchase-modal__balance strong img {
    width: 14px;
    height: 14px;
}

.credit-purchase-modal__close {
    position: relative;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: #1f1f1f;
    cursor: pointer;
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.credit-purchase-modal__close:hover {
    background: #262626;
    transform: translateY(-1px);
}

.credit-purchase-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.credit-purchase-modal__close span:first-child {
    transform: rotate(45deg);
}

.credit-purchase-modal__close span:last-child {
    transform: rotate(-45deg);
}

.credit-purchase-modal__panel {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 22px 28px 18px;
    border-radius: 18px;
    background: #171718;
}

.credit-purchase-modal__title {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
}

.credit-purchase-modal__title span {
    width: 80px;
    height: 1px;
    background: rgba(255, 255, 255, 0.08);
}

.credit-purchase-modal__title strong {
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
}

.credit-purchase-modal__content {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) 1px minmax(0, 0.8fr);
    gap: 22px;
    align-items: stretch;
}

.credit-purchase-modal__content::before {
    content: '';
    grid-column: 2;
    grid-row: 1;
    width: 1px;
    height: auto;
    background:
        linear-gradient(to bottom, transparent 0%, rgba(255, 255, 255, 0.12) 12%, rgba(255, 255, 255, 0.12) 88%, transparent 100%);
}

.credit-purchase-modal__packs {
    grid-column: 1;
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.credit-pack-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 86px;
    padding: 0 10px;
    border: 1px solid transparent;
    border-radius: 12px;
    background: #222222;
    color: #fff;
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        transform 0.2s ease;
}

.credit-pack-card:hover {
    transform: translateY(-1px);
    background: #2a2a2a;
}

.credit-pack-card.is-active {
    border-color: rgba(255, 255, 255, 0.82);
    background: #222222;
}

.credit-pack-card strong,
.credit-pack-card span,
.credit-pack-card del {
    white-space: nowrap;
}

.credit-pack-card em {
    position: absolute;
    top: 8px;
    right: 8px;
    height: 20px;
    padding: 0 7px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.92);
    color: #151515;
    font-size: 11px;
    font-style: normal;
    font-weight: 700;
    line-height: 20px;
}

.credit-pack-card strong {
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
}

.credit-pack-card span {
    color: rgba(255, 255, 255, 0.6);
    font-size: 14px;
    font-weight: 600;
    line-height: 1;
}

.credit-pack-card del {
    margin-top: -4px;
    color: rgba(255, 255, 255, 0.36);
    font-size: 12px;
    line-height: 1;
}

.credit-purchase-pay {
    grid-column: 3;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    min-width: 0;
}

.credit-purchase-pay__qr {
    position: relative;
    width: min(100%, 270px);
    aspect-ratio: 1 / 1;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 14px;
    overflow: hidden;
    background:
        radial-gradient(circle at 20% 20%, rgba(255, 255, 255, 0.1), transparent 36%),
        radial-gradient(circle at 78% 18%, rgba(255, 255, 255, 0.08), transparent 32%),
        linear-gradient(180deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.01));
}

.credit-purchase-pay__qr-pattern {
    position: absolute;
    inset: 12px;
    border-radius: 12px;
    opacity: 0.2;
    background-image:
        radial-gradient(circle at 16px 16px, #fff 0 4px, transparent 4px),
        radial-gradient(circle at calc(100% - 16px) 16px, #fff 0 4px, transparent 4px),
        radial-gradient(circle at 16px calc(100% - 16px), #fff 0 4px, transparent 4px),
        repeating-linear-gradient(0deg, rgba(255, 255, 255, 0.88) 0 4px, transparent 4px 8px),
        repeating-linear-gradient(90deg, rgba(255, 255, 255, 0.88) 0 4px, transparent 4px 8px);
    filter: blur(1.8px);
}

.credit-purchase-pay__overlay {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 22px;
    min-height: 100%;
    padding: 22px 18px;
    text-align: center;
    background: linear-gradient(180deg, rgba(17, 17, 17, 0.36), rgba(17, 17, 17, 0.74));
    backdrop-filter: blur(3px);
}

.credit-purchase-pay__overlay strong,
.credit-purchase-pay__overlay span {
    white-space: nowrap;
}

.credit-purchase-pay__title {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    max-width: 100%;
}

.credit-purchase-pay__overlay .credit-purchase-pay__title strong,
.credit-purchase-pay__overlay .credit-purchase-pay__title button {
    overflow: hidden;
    text-overflow: ellipsis;
}

.credit-purchase-pay__overlay .credit-purchase-pay__title strong {
    font-size: 16px;
    font-weight: 700;
    line-height: 1.2;
}

.credit-purchase-pay__overlay .credit-purchase-pay__title button {
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.82);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    cursor: pointer;
    transition:
        color 0.2s ease,
        opacity 0.2s ease;
}

.credit-purchase-pay__overlay .credit-purchase-pay__title button:hover {
    color: #fff;
}

.credit-purchase-pay__action {
    min-width: 132px;
    min-height: 38px;
    padding: 0 18px;
    border: 0;
    border-radius: 999px;
    background: #fff;
    color: #111;
    font-size: 15px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        filter 0.2s ease;
}

.credit-purchase-pay__action:hover {
    filter: brightness(1.04);
    transform: translateY(-1px);
}

.credit-purchase-pay__action:disabled {
    cursor: not-allowed;
    opacity: 0.58;
    transform: none;
}

.credit-purchase-pay__hint {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: rgba(255, 255, 255, 0.64);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
}

.credit-purchase-pay__hint img,
.credit-purchase-pay__alipay {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.credit-purchase-pay__alipay {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #10d36a;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}

.credit-purchase-pay__agreement {
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    cursor: pointer;
    text-align: center;
}

.credit-purchase-modal__footer {
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
    text-align: center;
    white-space: nowrap;
}

.credit-purchase-fade-enter-active,
.credit-purchase-fade-leave-active {
    transition: opacity 0.22s ease;
}

.credit-purchase-fade-enter-from,
.credit-purchase-fade-leave-to {
    opacity: 0;
}

@media (max-width: 860px) {
    .credit-purchase-mask {
        padding: 16px;
    }

    .credit-purchase-modal {
        width: 100%;
        max-width: none;
    }

    .credit-purchase-modal__topbar {
        flex-direction: column;
        align-items: stretch;
    }

    .credit-purchase-modal__topbar-side {
        justify-content: space-between;
    }

    .credit-purchase-modal__panel {
        padding: 18px;
    }

    .credit-purchase-modal__content {
        grid-template-columns: 1fr;
    }

    .credit-purchase-modal__content::before {
        display: none;
    }

    .credit-purchase-modal__packs,
    .credit-purchase-pay {
        grid-column: auto;
    }
}
</style>
