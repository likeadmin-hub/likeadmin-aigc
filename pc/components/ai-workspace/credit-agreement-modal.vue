<template>
    <Teleport to="body">
        <Transition name="credit-agreement-fade">
            <div v-if="modelValue" class="credit-agreement-mask" @click.self="close">
                <section class="credit-agreement-modal" aria-modal="true" role="dialog">
                    <header class="credit-agreement-modal__header">
                        <div>
                            <h3>{{ policyTitle }}</h3>
                            <p>请在购买积分前仔细阅读以下说明。</p>
                        </div>
                        <button class="credit-agreement-modal__close" type="button" aria-label="关闭付费用户协议" @click="close">
                            <span></span>
                            <span></span>
                        </button>
                    </header>

                    <div class="credit-agreement-modal__content render-html" v-html="policyContent">
                    </div>

                    <footer class="credit-agreement-modal__footer">
                        <NuxtLink class="credit-agreement-modal__link" :to="`/policy/${PolicyAgreementEnum.PAID}`" target="_blank">
                            新页面查看
                        </NuxtLink>
                        <button type="button" @click="close">我已阅读</button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getPolicy } from '@/api/app'
import { PolicyAgreementEnum } from '@/enums/appEnums'

interface Props {
    modelValue: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
}>()

const policyTitle = ref('付费用户协议')
const policyContent = ref('')
const loaded = ref(false)

const close = () => emit('update:modelValue', false)

const loadPolicy = async () => {
    if (loaded.value) return
    const data = await getPolicy({ type: PolicyAgreementEnum.PAID })
    policyTitle.value = data?.title || policyTitle.value
    policyContent.value = data?.content || ''
    loaded.value = true
}

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        close()
    }
}

onMounted(() => window.addEventListener('keydown', handleKeydown))

watch(() => props.modelValue, (visible) => {
    if (visible) {
        loadPolicy().catch(() => {
            policyContent.value = '<h2>1. 购买说明</h2><p>积分为站内虚拟权益，支付完成后将自动充值至当前账号，仅可用于站内指定功能消耗。</p><h2>2. 使用规则</h2><p>积分不可转赠、不可提现，也不可兑换现金或其他未明确支持的权益。</p><h2>3. 退款说明</h2><p>虚拟权益一经充值成功通常不支持退款，若因系统异常导致未到账，可联系平台客服协助处理。</p>'
        })
    }
}, { immediate: true })

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
})
</script>

<style lang="scss" scoped>
.credit-agreement-mask {
    position: fixed;
    inset: 0;
    z-index: 98;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
}

.credit-agreement-modal {
    width: 720px;
    max-width: calc(100vw - 64px);
    max-height: calc(100vh - 64px);
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    color: #fff;
    box-sizing: border-box;
}

.credit-agreement-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
}

.credit-agreement-modal__header h3 {
    margin: 0 0 6px;
    font-size: 24px;
    font-weight: 700;
    line-height: 1.2;
}

.credit-agreement-modal__header p {
    margin: 0;
    color: rgba(255, 255, 255, 0.52);
    font-size: 14px;
    line-height: 1.5;
}

.credit-agreement-modal__close {
    position: relative;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: #1f1f1f;
    cursor: pointer;
}

.credit-agreement-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.credit-agreement-modal__close span:first-child {
    transform: rotate(45deg);
}

.credit-agreement-modal__close span:last-child {
    transform: rotate(-45deg);
}

.credit-agreement-modal__content {
    flex: 1;
    overflow-y: auto;
    padding: 4px 2px 4px 0;
}

.credit-agreement-modal__content :deep(h2) {
    margin: 0 0 8px;
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    line-height: 1.4;
}

.credit-agreement-modal__content :deep(h2:not(:first-child)) {
    margin-top: 18px;
}

.credit-agreement-modal__content :deep(p) {
    margin: 0;
    color: rgba(255, 255, 255, 0.74);
    font-size: 14px;
    line-height: 1.8;
}

.credit-agreement-modal__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 14px;
}

.credit-agreement-modal__link {
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    text-decoration: none;
}

.credit-agreement-modal__link:hover {
    color: #fff;
}

.credit-agreement-modal__footer button {
    min-width: 112px;
    min-height: 40px;
    padding: 0 18px;
    border: 0;
    border-radius: 999px;
    background: #fff;
    color: #111;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
}

.credit-agreement-fade-enter-active,
.credit-agreement-fade-leave-active {
    transition: opacity 0.22s ease;
}

.credit-agreement-fade-enter-from,
.credit-agreement-fade-leave-to {
    opacity: 0;
}
</style>
