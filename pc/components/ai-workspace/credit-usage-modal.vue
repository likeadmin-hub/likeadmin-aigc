<template>
    <Teleport to="body">
        <Transition name="credits-usage-fade">
            <div v-if="modelValue" class="credits-usage-mask" @click.self="close">
                <section class="credits-usage-modal" aria-modal="true" role="dialog">
                    <header class="credits-usage-modal__header">
                        <h3>积分明细</h3>
                        <button class="credits-usage-modal__close" type="button" aria-label="关闭积分明细" @click="close">
                            <span></span>
                            <span></span>
                        </button>
                    </header>

                    <section class="credits-usage-modal__summary">
                        <div class="credits-usage-modal__summary-item">
                            <span>剩余积分</span>
                            <strong :title="formatAmountText(creditSummary.remaining)">{{ formatCompactAmount(creditSummary.remaining) }}</strong>
                        </div>
                        <div class="credits-usage-modal__summary-item">
                            <span>累计获得</span>
                            <strong :title="formatAmountText(creditSummary.income)">{{ formatCompactAmount(creditSummary.income) }}</strong>
                        </div>
                        <div class="credits-usage-modal__summary-item">
                            <span>累计消耗</span>
                            <strong :title="formatAmountText(creditSummary.consume)">{{ formatCompactAmount(creditSummary.consume) }}</strong>
                        </div>
                        <div class="credits-usage-modal__summary-item">
                            <span>流水记录</span>
                            <strong :title="String(creditSummary.count)">{{ formatCompactAmount(creditSummary.count, 0) }}</strong>
                        </div>
                    </section>

                    <nav class="credits-usage-modal__tabs" aria-label="积分筛选">
                        <button
                            v-for="tab in tabs"
                            :key="tab.key"
                            :class="{ 'is-active': activeTab === tab.key }"
                            type="button"
                            @click="activeTab = tab.key"
                        >
                            {{ tab.label }}
                        </button>
                    </nav>

                    <section class="credits-usage-modal__list">
                        <div v-if="loading" class="credits-usage-modal__empty">
                            <strong>正在加载积分明细</strong>
                            <span>请稍候。</span>
                        </div>
                        <div v-else-if="loadError" class="credits-usage-modal__empty">
                            <strong>积分明细加载失败</strong>
                            <span>{{ loadError }}</span>
                        </div>
                        <template v-else>
                            <article v-for="record in filteredRecords" :key="record.id" class="credits-usage-modal__record">
                                <div class="credits-usage-modal__record-main">
                                    <strong>{{ record.title }}</strong>
                                    <span>{{ record.time }}</span>
                                </div>
                                <div class="credits-usage-modal__record-side">
                                    <strong :class="record.amount >= 0 ? 'is-positive' : 'is-negative'">{{ formatAmount(record.amount) }}</strong>
                                    <span>{{ record.expireAt ? `到期时间：${record.expireAt}` : record.note }}</span>
                                </div>
                            </article>
                        </template>

                        <div v-if="!loading && !loadError && !filteredRecords.length" class="credits-usage-modal__empty">
                            <strong>当前筛选下暂无记录</strong>
                            <span>真实积分消费与获取明细会显示在这里。</span>
                        </div>
                    </section>

                    <footer class="credits-usage-modal__footer">
                        <p class="credits-usage-modal__tip">
                            <span class="credits-usage-modal__tip-icon">i</span>
                            <span>图片与视频的生成由于生成数量、模式、时长等参数不同，费用会存在差异。</span>
                            <button class="credits-usage-modal__tip-link" type="button" @click="emit('view-rules')">积分规则</button>
                        </p>
                        <button class="credits-usage-modal__action" type="button" @click="handlePurchase">去购买积分</button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getAccountLogs } from '@/api/user'
import { useUserStore } from '@/stores/user'

type CreditsTabKey = 'all' | 'consume' | 'purchase' | 'income'
type CreditsRecordType = Exclude<CreditsTabKey, 'all'>

interface CreditsRecord {
    id: string
    title: string
    type: CreditsRecordType
    amount: number
    time: string
    note?: string
    expireAt?: string
}

interface CreditsSummary {
    remaining: number
    income: number
    consume: number
    count: number
}

interface Props {
    modelValue: boolean
    remainingCredits: number
}

const props = defineProps<Props>()
const userStore = useUserStore()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'purchase'): void
    (e: 'view-rules'): void
}>()

const activeTab = ref<CreditsTabKey>('all')
const loading = ref(false)
const loadError = ref('')
const creditRecords = ref<CreditsRecord[]>([])
const remoteSummary = ref<Partial<CreditsSummary>>({})
let requestSeq = 0

const tabs = [
    { key: 'all', label: '全部' },
    { key: 'consume', label: '消耗' },
    { key: 'purchase', label: '购买' },
    { key: 'income', label: '获得' }
] as const

const creditSummary = computed(() => {
    const remaining = Math.max(props.remainingCredits, 0)

    return {
        remaining: Number(remoteSummary.value.remaining ?? remaining),
        income: Number(remoteSummary.value.income ?? 0),
        consume: Number(remoteSummary.value.consume ?? 0),
        count: Number(remoteSummary.value.count ?? creditRecords.value.length)
    }
})

const filteredRecords = computed(() => (
    activeTab.value === 'all'
        ? creditRecords.value
        : creditRecords.value.filter((record) => record.type === activeTab.value)
))

const close = () => emit('update:modelValue', false)

const handlePurchase = () => {
    emit('purchase')
}

const formatAmount = (amount: number) => `${amount >= 0 ? '+' : ''}${amount.toFixed(2)}`
const formatAmountText = (amount: number) => {
    const value = Number(amount)
    if (!Number.isFinite(value)) return '0'
    return Number.isInteger(value) ? String(value) : value.toFixed(2)
}
const formatCompactAmount = (amount: number, fractionDigits = 2) => {
    const value = Number(amount)
    if (!Number.isFinite(value)) return '0'
    const abs = Math.abs(value)
    const sign = value < 0 ? '-' : ''
    if (abs >= 100000000) return `${sign}${Number((abs / 100000000).toFixed(fractionDigits))}亿`
    if (abs >= 10000) return `${sign}${Number((abs / 10000).toFixed(fractionDigits))}万`
    return fractionDigits === 0 ? String(Math.round(value)) : formatAmountText(value)
}

const formatTime = (value: unknown) => {
    if (typeof value === 'number' && value > 0) {
        return new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }).replace(/\//g, '-')
    }
    if (typeof value === 'string' && value.trim()) return value.trim()
    return '--'
}

const parseExtra = (value: unknown): Record<string, any> => {
    if (!value) return {}
    if (typeof value === 'object' && !Array.isArray(value)) return value as Record<string, any>
    if (typeof value !== 'string') return {}
    try {
        const parsed = JSON.parse(value)
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {}
    } catch {
        return {}
    }
}

const resolveRecordType = (row: Record<string, any>): CreditsRecordType => {
    if (Number(row.action) === 2) return 'consume'
    if ([101, 201].includes(Number(row.change_type)) || String(row.source_sn || '').trim()) return 'purchase'
    return 'income'
}

const mapRecord = (row: Record<string, any>, index: number): CreditsRecord => {
    const extra = parseExtra(row.extra)
    const action = Number(row.action)
    const amount = Math.abs(Number(row.change_amount ?? 0))
    const signedAmount = action === 2 ? -amount : amount
    const noteParts = [
        row.remark,
        extra.app_code,
        extra.provider,
        extra.model,
        extra.ratio,
        extra.quality,
        row.source_sn
    ]
        .map((item) => String(item ?? '').trim())
        .filter(Boolean)

    return {
        id: String(row.id ?? row.sn ?? `${row.create_time || 'log'}-${index}`),
        title: String(row.remark || row.type_desc || (action === 2 ? '积分消耗' : '积分获得')),
        type: resolveRecordType(row),
        amount: signedAmount,
        time: formatTime(row.create_time),
        note: noteParts.length ? Array.from(new Set(noteParts)).join(' · ') : `剩余积分 ${formatAmountText(Number(row.left_amount ?? 0))}`
    }
}

const loadCreditLogs = async () => {
    if (!userStore.isLogin) {
        creditRecords.value = []
        remoteSummary.value = {}
        loadError.value = ''
        loading.value = false
        return
    }
    const seq = ++requestSeq
    loading.value = true
    loadError.value = ''
    try {
        const [response] = await Promise.all([
            getAccountLogs({ type: 'um', page_no: 1, page_size: 50 }),
            userStore.isLogin ? userStore.getUser() : Promise.resolve()
        ])
        if (seq !== requestSeq) return
        const lists = Array.isArray(response?.lists) ? response.lists : []
        creditRecords.value = lists.map(mapRecord)
        const extend = response?.extend || {}
        remoteSummary.value = {
            remaining: Number(extend.user_money ?? userStore.userInfo?.user_money ?? props.remainingCredits),
            income: Number(extend.total_income ?? 0),
            consume: Number(extend.total_consume ?? 0),
            count: Number(response?.count ?? lists.length)
        }
    } catch (error: any) {
        if (seq !== requestSeq) return
        loadError.value = String(error?.msg || error?.message || error || '请稍后重试')
        creditRecords.value = []
        remoteSummary.value = {}
    } finally {
        if (seq === requestSeq) loading.value = false
    }
}

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.modelValue) {
        close()
    }
}

watch(() => props.modelValue, (visible) => {
    if (!visible) return
    activeTab.value = 'all'
    loadCreditLogs()
})

onMounted(() => window.addEventListener('keydown', handleKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown))
</script>

<style lang="scss" scoped>
.credits-usage-mask {
    position: fixed;
    inset: 0;
    z-index: 80;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
}

.credits-usage-modal {
    width: 880px;
    max-width: calc(100vw - 64px);
    height: min(860px, calc(100vh - 64px));
    display: flex;
    flex-direction: column;
    gap: 22px;
    padding: 22px 22px 18px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    color: #fff;
    box-sizing: border-box;
}

.credits-usage-modal__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.credits-usage-modal__header h3 {
    margin: 0;
    font-size: 28px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
}

.credits-usage-modal__close {
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
        transform 0.2s ease,
        opacity 0.2s ease;
}

.credits-usage-modal__close:hover {
    background: #262626;
    transform: translateY(-1px);
}

.credits-usage-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.credits-usage-modal__close span:first-child {
    transform: rotate(45deg);
}

.credits-usage-modal__close span:last-child {
    transform: rotate(-45deg);
}

.credits-usage-modal__summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    align-items: center;
    gap: 12px;
    padding: 18px 20px 16px;
    border-radius: 18px;
    background: #171717;
}

.credits-usage-modal__summary-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
    max-width: 100%;
    align-items: center;
    text-align: center;
    overflow: hidden;
}

.credits-usage-modal__summary-item span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1.2;
    white-space: nowrap;
}

.credits-usage-modal__summary-item strong {
    display: block;
    width: 100%;
    overflow: hidden;
    color: #fff;
    font-size: clamp(22px, 2.2vw, 30px);
    font-weight: 700;
    line-height: 1;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.credits-usage-modal__summary-symbol {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.42);
    font-size: 26px;
    font-weight: 600;
    line-height: 1;
}

.credits-usage-modal__tabs {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 0 2px 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.credits-usage-modal__tabs button {
    position: relative;
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.56);
    font-size: 16px;
    font-weight: 600;
    line-height: 1.4;
    white-space: nowrap;
    cursor: pointer;
    transition: color 0.2s ease;
}

.credits-usage-modal__tabs button::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -11px;
    width: 100%;
    height: 3px;
    border-radius: 999px;
    background: transparent;
    transition: background 0.2s ease;
}

.credits-usage-modal__tabs button:hover,
.credits-usage-modal__tabs button.is-active {
    color: #fff;
}

.credits-usage-modal__tabs button.is-active::after {
    background: #fff;
}

.credits-usage-modal__list {
    flex: 1;
    overflow-y: auto;
    padding: 10px 16px;
    border-radius: 18px;
    background: #171717;
}

.credits-usage-modal__list::-webkit-scrollbar {
    width: 8px;
}

.credits-usage-modal__list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.12);
}

.credits-usage-modal__record {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 18px;
    padding: 16px 0;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.credits-usage-modal__record:last-child {
    border-bottom: 0;
}

.credits-usage-modal__record-main,
.credits-usage-modal__record-side {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.credits-usage-modal__record-main strong,
.credits-usage-modal__record-side strong {
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.credits-usage-modal__record-main span,
.credits-usage-modal__record-side span {
    color: rgba(255, 255, 255, 0.42);
    font-size: 13px;
    line-height: 1.5;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.credits-usage-modal__record-side {
    align-items: flex-end;
    text-align: right;
}

.credits-usage-modal__record-side strong.is-positive {
    color: #76ff57;
}

.credits-usage-modal__record-side strong.is-negative {
    color: #ff7448;
}

.credits-usage-modal__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 240px;
    text-align: center;
}

.credits-usage-modal__empty strong {
    font-size: 18px;
    font-weight: 600;
    white-space: nowrap;
}

.credits-usage-modal__empty span {
    color: rgba(255, 255, 255, 0.44);
    font-size: 14px;
    white-space: nowrap;
}

.credits-usage-modal__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.credits-usage-modal__tip {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    color: rgba(255, 255, 255, 0.52);
    font-size: 13px;
    line-height: 1.6;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
}

.credits-usage-modal__tip-icon {
    width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: 50%;
    color: rgba(255, 255, 255, 0.7);
    font-size: 12px;
    font-style: normal;
    flex-shrink: 0;
}

.credits-usage-modal__tip > span:not(.credits-usage-modal__tip-icon) {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.credits-usage-modal__tip-link {
    padding: 0;
    border: 0;
    background: transparent;
    color: #ff6600;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
}

.credits-usage-modal__action {
    min-width: 136px;
    min-height: 38px;
    padding: 0 16px;
    border: 0;
    border-radius: 10px;
    background: #fff;
    color: #111;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    flex-shrink: 0;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.credits-usage-modal__action:hover {
    transform: translateY(-1px);
}

.credits-usage-fade-enter-active,
.credits-usage-fade-leave-active {
    transition: opacity 0.22s ease;
}

.credits-usage-fade-enter-from,
.credits-usage-fade-leave-to {
    opacity: 0;
}

@media (max-width: 860px) {
    .credits-usage-mask {
        padding: 16px;
    }

    .credits-usage-modal {
        width: 100%;
        height: calc(100vh - 32px);
        padding: 18px 16px 16px;
    }

    .credits-usage-modal__header h3 {
        font-size: 22px;
    }

    .credits-usage-modal__summary {
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px 14px;
    }

    .credits-usage-modal__summary-symbol {
        display: none;
    }

    .credits-usage-modal__record {
        grid-template-columns: minmax(0, 1fr);
    }

    .credits-usage-modal__record-side {
        align-items: flex-start;
        text-align: left;
    }

    .credits-usage-modal__footer {
        flex-direction: column;
        align-items: stretch;
    }

    .credits-usage-modal__tip {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .credits-usage-modal__action {
        width: 100%;
    }
}
</style>
