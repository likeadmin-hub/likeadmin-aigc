<template>
    <Teleport to="body">
        <Transition name="membership-pay-fade">
            <div v-if="modelValue" class="membership-pay-mask" @click.self="close">
                <section class="membership-pay-modal" aria-modal="true" role="dialog">
                    <header class="membership-pay-modal__header">
                        <div>
                            <h3>{{ title }}</h3>
                            <p>{{ description }}</p>
                        </div>
                        <button type="button" aria-label="关闭支付弹窗" @click="close">×</button>
                    </header>

                    <div v-if="loading" class="membership-pay-loading">正在读取订单...</div>
                    <template v-else>
                        <div class="membership-pay-amount">¥{{ orderAmount || '0.00' }}</div>

                        <div class="membership-pay-way">
                            <button
                                v-for="item in payWays"
                                :key="item.pay_way"
                                :class="{ 'is-active': selectedPayWay === item.pay_way }"
                                type="button"
                                @click="selectedPayWay = item.pay_way"
                            >
                                <img v-if="item.icon" :src="item.icon" alt="" />
                                <span>{{ item.name }}</span>
                            </button>
                        </div>

                        <div v-if="wechatCodeSvg" class="membership-pay-qr">
                            <div class="membership-pay-qr__box" v-html="wechatCodeSvg"></div>
                            <p>请使用微信扫码支付，支付完成后会自动确认。</p>
                            <button type="button" @click="queryPayStatus">我已完成支付</button>
                        </div>

                        <button
                            v-else
                            class="membership-pay-submit"
                            type="button"
                            :disabled="paying || !selectedPayWay"
                            @click="handlePay"
                        >
                            {{ paying ? '正在拉起支付' : '立即支付' }}
                        </button>
                    </template>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { renderSVG } from 'uqr'
import { getPayStatus, getPayWay, prepay } from '@/api/pay'
import feedback from '@/utils/feedback'

const PAY_WAY_WECHAT = 2
const PAY_WAY_ALIPAY = 3

const props = defineProps<{
    modelValue: boolean
    orderId: number
    from: string
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'success'): void
}>()

const loading = ref(false)
const paying = ref(false)
const payWays = ref<any[]>([])
const selectedPayWay = ref<number | ''>('')
const orderAmount = ref('')
const wechatCodeUrl = ref('')
let pollTimer: ReturnType<typeof setInterval> | null = null

const wechatCodeSvg = computed(() => (
    wechatCodeUrl.value
        ? renderSVG(wechatCodeUrl.value, { pixelSize: 6, border: 2, whiteColor: '#ffffff', blackColor: '#111111' })
        : ''
))

const close = () => {
    emit('update:modelValue', false)
}

const stopPolling = () => {
    if (!pollTimer) return
    clearInterval(pollTimer)
    pollTimer = null
}

const startPolling = () => {
    stopPolling()
    pollTimer = setInterval(queryPayStatus, 3000)
}

const loadPayWay = async () => {
    if (!props.orderId) return
    loading.value = true
    wechatCodeUrl.value = ''
    try {
        const data = await getPayWay({
            order_id: props.orderId,
            from: props.from
        })
        payWays.value = Array.isArray(data?.lists) ? data.lists : []
        orderAmount.value = String(data?.order_amount || '')
        selectedPayWay.value = payWays.value.find((item) => item.is_default)?.pay_way || payWays.value[0]?.pay_way || ''
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '支付方式读取失败')
    } finally {
        loading.value = false
    }
}

const queryPayStatus = async () => {
    if (!props.orderId) return
    try {
        const data = await getPayStatus({
            order_id: props.orderId,
            from: props.from
        })
        if (Number(data?.pay_status || 0) === 1) {
            stopPolling()
            feedback.msgSuccess(successText.value)
            emit('success')
            close()
        }
    } catch (error) {
        stopPolling()
    }
}

const openAliPay = (html: string) => {
    const win = window.open('', '_blank')
    if (!win) {
        feedback.msgError('浏览器阻止了支付窗口，请允许弹窗后重试')
        return
    }
    win.document.open()
    win.document.write(html)
    win.document.close()
}

const handlePay = async () => {
    if (!selectedPayWay.value) return
    paying.value = true
    try {
        const data = await prepay({
            order_id: props.orderId,
            from: props.from,
            pay_way: selectedPayWay.value,
            redirect: window.location.pathname
        })
        if (Number(data?.pay_way) === PAY_WAY_WECHAT && typeof data?.config === 'string') {
            wechatCodeUrl.value = data.config
            startPolling()
            return
        }
        if (Number(data?.pay_way) === PAY_WAY_ALIPAY && typeof data?.config === 'string') {
            openAliPay(data.config)
            startPolling()
            return
        }
        await queryPayStatus()
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '支付拉起失败')
    } finally {
        paying.value = false
    }
}

watch(() => props.modelValue, (visible) => {
    if (visible) {
        loadPayWay()
        return
    }
    stopPolling()
    wechatCodeUrl.value = ''
}, { immediate: true })

onBeforeUnmount(stopPolling)

const title = computed(() => props.from === 'membership' ? '会员支付' : '积分充值')
const description = computed(() => (
    props.from === 'membership'
        ? '选择支付方式，完成支付后自动刷新会员状态。'
        : '选择支付方式，完成支付后自动刷新积分余额。'
))
const successText = computed(() => props.from === 'membership' ? '会员支付成功' : '积分充值成功')
</script>

<style lang="scss" scoped>
.membership-pay-mask {
    position: fixed;
    inset: 0;
    z-index: 120;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.58);
    backdrop-filter: blur(8px);
}

.membership-pay-modal {
    width: 420px;
    max-width: calc(100vw - 48px);
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    background: #111214;
    color: #fff;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.42);
}

.membership-pay-modal__header {
    display: flex;
    justify-content: space-between;
    gap: 16px;
}

.membership-pay-modal__header h3 {
    margin: 0;
    font-size: 20px;
}

.membership-pay-modal__header p {
    margin: 8px 0 0;
    color: rgba(255, 255, 255, 0.62);
    font-size: 13px;
}

.membership-pay-modal__header button {
    width: 32px;
    height: 32px;
    border: 0;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    cursor: pointer;
}

.membership-pay-loading {
    padding: 50px 0;
    color: rgba(255, 255, 255, 0.62);
    text-align: center;
}

.membership-pay-amount {
    margin: 26px 0 18px;
    font-size: 34px;
    font-weight: 700;
    text-align: center;
}

.membership-pay-way {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.membership-pay-way button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 44px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    background: #1b1c1f;
    color: rgba(255, 255, 255, 0.8);
    cursor: pointer;
}

.membership-pay-way button.is-active {
    border-color: rgba(255, 255, 255, 0.55);
    background: #ffffff;
    color: #111;
}

.membership-pay-way img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.membership-pay-submit,
.membership-pay-qr button {
    width: 100%;
    height: 44px;
    margin-top: 18px;
    border: 0;
    border-radius: 999px;
    background: #ffffff;
    color: #111;
    font-weight: 700;
    cursor: pointer;
}

.membership-pay-submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.membership-pay-qr {
    margin-top: 18px;
    text-align: center;
}

.membership-pay-qr__box {
    display: inline-flex;
    padding: 12px;
    border-radius: 12px;
    background: #fff;
}

.membership-pay-qr :deep(svg) {
    width: 220px;
    height: 220px;
}

.membership-pay-qr p {
    margin: 12px 0 0;
    color: rgba(255, 255, 255, 0.66);
    font-size: 13px;
}

.membership-pay-fade-enter-active,
.membership-pay-fade-leave-active {
    transition: opacity 0.2s ease;
}

.membership-pay-fade-enter-from,
.membership-pay-fade-leave-to {
    opacity: 0;
}
</style>
