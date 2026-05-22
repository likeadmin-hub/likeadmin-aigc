<template>
    <div class="ai-workspace-chrome">
        <header class="app-header">
            <NuxtLink class="app-logo" to="/" :aria-label="texts.backHome" @click.stop="emit('go-home')">
                <img v-if="siteLogo" class="app-logo__image" :src="siteLogo" :alt="siteName" />
                <span v-if="!siteLogo" class="app-logo__text">{{ siteName }}</span>
            </NuxtLink>

            <div class="app-header__actions">
                <div class="header-popover-wrap">
                    <button class="app-pill" type="button" @click.stop="emit('toggle-popover', 'share')">
                        <img class="app-pill__asset app-pill__asset--sm" :src="giftIcon" alt="" />
                        <span>{{ texts.shareGift }}</span>
                    </button>
                    <div v-if="activePopover === 'share'" class="header-popover" @click.stop>
                        <strong>{{ popoverContent.share.title }}</strong>
                        <p>{{ popoverContent.share.text }}</p>
                    </div>
                </div>

                <div class="header-popover-wrap">
                    <button class="app-pill" type="button" @click.stop="emit('toggle-popover', 'api')">
                        {{ texts.apiCall }}
                    </button>
                    <div v-if="activePopover === 'api'" class="header-popover" @click.stop>
                        <strong>{{ popoverContent.api.title }}</strong>
                        <p>{{ popoverContent.api.text }}</p>
                    </div>
                </div>

                <button class="app-pill" type="button" @click.stop="openCreditsUsageModal">
                    <img class="app-pill__asset app-pill__asset--xs" :src="sparkIcon" alt="" />
                    <span>{{ remainingCredits }}</span>
                </button>

                <button class="app-pill" type="button" @click.stop="openMembershipModal">
                    <img class="app-pill__asset app-pill__asset--sm" :src="vipIcon" alt="" />
                    <span>{{ membershipEnabled ? texts.membershipOpened : texts.openMembership }}</span>
                </button>

                <div class="header-popover-wrap">
                    <button class="app-icon-button" type="button" :aria-label="texts.noticeCenter" @click.stop="emit('toggle-popover', 'notice')">
                        <img class="app-icon-button__asset" :src="cardIcon" alt="" />
                    </button>
                    <div
                        v-if="activePopover === 'notice'"
                        :class="['header-popover', { 'header-popover--compact': popoverContent.notice.compact }]"
                        @click.stop
                    >
                        <strong>{{ popoverContent.notice.title }}</strong>
                        <p>{{ popoverContent.notice.text }}</p>
                    </div>
                </div>

                <button class="app-avatar" type="button" :aria-label="texts.profileCenter" @click.stop="openLoginModal">
                    <img :src="displayAvatarUrl" alt="" />
                </button>
            </div>
        </header>

        <AiWorkspaceSidebar :active-sidebar="activeSidebar" @navigate="emit('navigate', $event)" />
        <AiWorkspaceCreditUsageModal
            v-model="showCreditsUsageModal"
            :remaining-credits="remainingCredits"
            @purchase="openCreditsPurchaseModal('usage')"
        />
        <AiWorkspaceCreditPurchaseModal
            :model-value="showCreditsPurchaseModal"
            :remaining-credits="remainingCredits"
            :membership-enabled="membershipEnabled"
            @update:model-value="handleCreditsPurchaseModalVisibleChange"
            @purchase="handleCreditsPurchase"
            @renew-membership="handlePurchaseRenewMembership"
            @view-agreement="showCreditAgreementModal = true"
        />
        <AiWorkspaceCreditAgreementModal v-model="showCreditAgreementModal" />
        <AiWorkspaceMembershipModal
            v-model="showMembershipModal"
            :membership-enabled="membershipEnabled"
            :current-plan="selectedMembershipPlan"
            :plans="membershipPlans"
            @recharge="handleMembershipRecharge"
            @subscribe="handleMembershipSubscribe"
        />
        <AiWorkspaceMembershipPayModal
            v-model="showMembershipPayModal"
            :order-id="membershipPayOrderId"
            :from="payModalFrom"
            @success="handleMembershipPaySuccess"
        />
        <AiWorkspaceUserPanel v-model="showUserPanel" />
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import type { MembershipPlanDefinition, MembershipPlanId } from '@/constants/membership-plans'
import { createMembershipOrder, getMembershipPlans } from '@/api/membership'
import feedback from '@/utils/feedback'
import type { SidebarKey } from '~/utils/ai-sidebar'
import { useAiUserDisplay } from '~/composables/useAiUserDisplay'
import giftIcon from '@/assets/images/icon/Gift.svg'
import vipIcon from '@/assets/images/icon/Vip-one (vip).svg'
import sparkIcon from '@/assets/images/icon/lingganzhi.svg'
import cardIcon from '@/assets/images/icon/card.svg'
type PopoverKey = '' | 'share' | 'api' | 'notice'
type PurchaseModalSource = 'usage' | 'membership' | 'standalone' | ''
type PurchaseModalCloseAction = 'restore-source' | 'open-membership' | 'stay-closed'

interface PopoverInfo {
    title: string
    text: string
    compact?: boolean
}

interface Props {
    activeSidebar: SidebarKey
    remainingCredits: number
    membershipEnabled: boolean
    activePopover: PopoverKey
    popoverContent: Record<'share' | 'api' | 'notice', PopoverInfo>
}

const props = defineProps<Props>()

const { displayAvatarUrl } = useAiUserDisplay()
const appStore = useAppStore()
const userStore = useUserStore()
const route = useRoute()
const { openPcLoginModal } = usePcLoginGate()
const siteLogo = computed(() => appStore.getWebsiteConfig.pc_logo || '')
const siteName = computed(() => appStore.getWebsiteConfig.pc_title || appStore.getWebsiteConfig.shop_name || 'A. PART')

const emit = defineEmits<{
    (e: 'toggle-popover', key: Exclude<PopoverKey, ''>): void
    (e: 'increment-credits'): void
    (e: 'purchase-credits'): void
    (e: 'toggle-membership'): void
    (e: 'go-home'): void
    (e: 'navigate', key: SidebarKey): void
}>()

const showCreditsUsageModal = ref(false)
const showCreditsPurchaseModal = ref(false)
const showCreditAgreementModal = ref(false)
const showMembershipModal = ref(false)
const showMembershipPayModal = ref(false)
const showUserPanel = ref(false)
const headerSolid = ref(false)
const selectedMembershipPlan = ref<MembershipPlanId>(props.membershipEnabled ? 'advanced' : 'free')
const membershipPlans = ref<MembershipPlanDefinition[]>([])
const membershipPayOrderId = ref(0)
const payModalFrom = ref<'membership' | 'recharge'>('membership')
const pendingMembershipPlan = ref<MembershipPlanId | null>(null)
const purchaseModalSource = ref<PurchaseModalSource>('')
const purchaseModalCloseAction = ref<PurchaseModalCloseAction>('restore-source')

const getWorkspaceScrollTop = () => {
    if (typeof window === 'undefined') return 0

    const selectors = [
        '.ai-app-page',
        '.avatar-main',
        '.ai-page',
        '.app-main'
    ]

    const containerTop = selectors.reduce((maxTop, selector) => {
        const node = document.querySelector<HTMLElement>(selector)
        return Math.max(maxTop, node?.scrollTop || 0)
    }, 0)

    return Math.max(window.scrollY || 0, document.documentElement.scrollTop || 0, document.body.scrollTop || 0, containerTop)
}

const syncHeaderSolid = () => {
    headerSolid.value = getWorkspaceScrollTop() > 12
}

const syncBodyScrollWithVisibleModals = () => {
    if (typeof window === 'undefined') return

    const shouldLock = (
        showCreditsUsageModal.value
        || showCreditsPurchaseModal.value
        || showCreditAgreementModal.value
        || showMembershipModal.value
        || showMembershipPayModal.value
        || showUserPanel.value
    )

    document.documentElement.style.overflow = shouldLock ? 'hidden' : ''
    document.body.style.overflow = shouldLock ? 'hidden' : ''
}

const openCreditsUsageModal = () => {
    if (props.activePopover) emit('toggle-popover', props.activePopover)
    purchaseModalSource.value = 'usage'
    showCreditsPurchaseModal.value = false
    showCreditAgreementModal.value = false
    showMembershipModal.value = false
    showCreditsUsageModal.value = true
}

const openCreditsPurchaseModal = (source: PurchaseModalSource = 'standalone') => {
    if (props.activePopover) emit('toggle-popover', props.activePopover)
    purchaseModalSource.value = source
    purchaseModalCloseAction.value = 'restore-source'
    showCreditsPurchaseModal.value = true
}

const openMembershipModal = () => {
    if (props.activePopover) emit('toggle-popover', props.activePopover)
    loadMembershipPlans()
    purchaseModalSource.value = 'membership'
    showCreditsPurchaseModal.value = false
    showCreditAgreementModal.value = false
    showCreditsUsageModal.value = false
    showMembershipModal.value = true
}

const openLoginModal = () => {
    if (userStore.isLogin) {
        if (props.activePopover) emit('toggle-popover', props.activePopover)
        showCreditsPurchaseModal.value = false
        showCreditAgreementModal.value = false
        showCreditsUsageModal.value = false
        showMembershipModal.value = false
        showUserPanel.value = true
        return
    }
    if (props.activePopover) emit('toggle-popover', props.activePopover)
    showCreditsPurchaseModal.value = false
    showCreditAgreementModal.value = false
    showCreditsUsageModal.value = false
    showMembershipModal.value = false
    showUserPanel.value = false
    openPcLoginModal({ redirect: route.fullPath })
}

const handleMembershipRecharge = () => {
    openCreditsPurchaseModal('membership')
}

const reopenPurchaseSourceModal = () => {
    if (purchaseModalSource.value === 'membership') {
        showMembershipModal.value = true
        return
    }

    if (purchaseModalSource.value === 'usage') {
        showCreditsUsageModal.value = true
    }
}

const handleCreditsPurchaseModalVisibleChange = (visible: boolean) => {
    if (visible) {
        showCreditsPurchaseModal.value = true
        return
    }

    const wasVisible = showCreditsPurchaseModal.value
    showCreditsPurchaseModal.value = false
    showCreditAgreementModal.value = false

    if (!wasVisible) return

    if (purchaseModalCloseAction.value === 'open-membership') {
        purchaseModalCloseAction.value = 'restore-source'
        openMembershipModal()
        return
    }

    if (purchaseModalCloseAction.value === 'restore-source') {
        reopenPurchaseSourceModal()
    }

    purchaseModalCloseAction.value = 'restore-source'
}

const handlePurchaseRenewMembership = () => {
    purchaseModalCloseAction.value = 'open-membership'
}

const handleCreditsPurchase = async (order?: { orderId?: number; from?: string }) => {
    purchaseModalCloseAction.value = 'stay-closed'
    if (order?.orderId) {
        membershipPayOrderId.value = Number(order.orderId)
        payModalFrom.value = order.from === 'membership' ? 'membership' : 'recharge'
        showMembershipPayModal.value = true
        return
    }
    if (userStore.isLogin) {
        await userStore.getUser().catch(() => undefined)
    }
    emit('purchase-credits')
}

const handleMembershipSubscribe = async (plan: MembershipPlanId, cycle: 'monthly' | 'yearly') => {
    try {
        const order = await createMembershipOrder({ plan_id: plan, cycle })
        membershipPayOrderId.value = Number(order?.order_id || 0)
        payModalFrom.value = 'membership'
        pendingMembershipPlan.value = plan
        showMembershipPayModal.value = true
    } catch (error: any) {
        ElMessage.error(error?.msg || error?.message || error || '会员订单创建失败')
    }
}

const handleMembershipPaySuccess = async () => {
    if (userStore.isLogin) {
        await userStore.getUser().catch(() => undefined)
    }
    if (payModalFrom.value === 'membership') {
        if (pendingMembershipPlan.value != null) {
            selectedMembershipPlan.value = pendingMembershipPlan.value
            pendingMembershipPlan.value = null
        }
        feedback.msgSuccess('订阅成功')
        emit('toggle-membership')
        return
    }
    emit('purchase-credits')
}

const mapMembershipPlan = (row: any): MembershipPlanDefinition => ({
    id: row.id,
    name: row.name,
    title: row.name,
    description: row.description || '',
    monthlyPrice: String(row.monthly_price ?? '0.00'),
    yearlyPrice: String(row.yearly_price ?? '0.00'),
    button: Number(row.monthly_price || 0) <= 0 && Number(row.yearly_price || 0) <= 0 ? '当前套餐' : `订阅${row.name || '会员'}`,
    outline: !row.is_recommend,
    free: Number(row.monthly_price || 0) <= 0 && Number(row.yearly_price || 0) <= 0,
    isRecommended: Number(row.is_recommend || 0) === 1,
    monthlyMarketPrice: Number(row.monthly_market_price || 0) > 0 ? String(row.monthly_market_price) : '',
    yearlyMarketPrice: Number(row.yearly_market_price || 0) > 0 ? String(row.yearly_market_price) : '',
    monthlyBonus: Number(row.monthly_bonus_points || 0) > 0 ? String(Number(row.monthly_bonus_points)) : '',
    yearlyBonus: Number(row.yearly_bonus_points || 0) > 0 ? String(Number(row.yearly_bonus_points)) : '',
    monthlyBonusTip: '会员套餐赠送积分',
    yearlyBonusTip: '会员套餐赠送积分',
    features: Array.isArray(row.features) ? row.features : []
})

const loadMembershipPlans = async () => {
    try {
        const rows = await getMembershipPlans()
        membershipPlans.value = Array.isArray(rows) ? rows.map(mapMembershipPlan) : []
    } catch (error) {
        console.error('load membership plans failed', error)
    }
}

watch(() => props.membershipEnabled, (enabled) => {
    if (!enabled) {
        selectedMembershipPlan.value = 'free'
        return
    }

    if (selectedMembershipPlan.value === 'free') {
        selectedMembershipPlan.value = 'advanced'
    }
}, { immediate: true })

watch(() => showMembershipPayModal.value, (visible) => {
    if (!visible && payModalFrom.value === 'membership') {
        pendingMembershipPlan.value = null
    }
})

watch(
    [showCreditsUsageModal, showCreditsPurchaseModal, showCreditAgreementModal, showMembershipModal, showMembershipPayModal, showUserPanel],
    syncBodyScrollWithVisibleModals,
    { immediate: true }
)

onMounted(() => {
    if (typeof window === 'undefined') return
    loadMembershipPlans()
    syncHeaderSolid()
    window.addEventListener('scroll', syncHeaderSolid, { passive: true })
    window.addEventListener('resize', syncHeaderSolid, { passive: true })
    document.addEventListener('scroll', syncHeaderSolid, true)
})

onBeforeUnmount(() => {
    if (typeof window === 'undefined') return
    window.removeEventListener('scroll', syncHeaderSolid)
    window.removeEventListener('resize', syncHeaderSolid)
    document.removeEventListener('scroll', syncHeaderSolid, true)
    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
})

const texts = {
    backHome: '\u8fd4\u56de\u9996\u9875',
    shareGift: '\u5206\u4eab\u6709\u793c',
    apiCall: 'API\u8c03\u7528',
    membershipOpened: '\u4f1a\u5458\u5df2\u5f00\u901a',
    openMembership: '\u5f00\u901a\u4f1a\u5458',
    noticeCenter: '\u6d88\u606f\u4e2d\u5fc3',
    profileCenter: '\u4e2a\u4eba\u4e2d\u5fc3'
} as const
</script>

<style lang="scss" scoped>
.ai-workspace-chrome {
    position: relative;
    z-index: 15;
}

.app-header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 12;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    height: 56px;
    padding: 0 40px;
    box-sizing: border-box;
    background: #060608;
    backdrop-filter: none;
}

.app-logo {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    text-decoration: none;
}

.app-logo__image {
    width: auto;
    max-width: 160px;
    height: 28px;
    object-fit: contain;
}

.app-logo__text {
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
}

.app-header__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
    min-width: 0;
}

.header-popover-wrap {
    position: relative;
}

.app-pill,
.app-icon-button,
.app-avatar {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.app-pill,
.app-icon-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 14px;
    border-radius: 999px;
    background: rgba(30, 31, 32, 0.96);
    color: #fff;
    font-size: 14px;
}

.app-pill:hover,
.app-icon-button:hover {
    background: rgba(46, 47, 48, 0.96);
}

.app-pill__asset--xs {
    width: 11px;
    height: 11px;
}

.app-pill__asset--sm {
    width: 16px;
    height: 16px;
}

.app-icon-button {
    position: relative;
    justify-content: center;
    min-width: 32px;
    padding: 0 10px;
}

.app-icon-button__asset {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.app-avatar {
    width: 40px;
    height: 40px;
    padding: 0;
    border-radius: 50%;
    background: transparent;
    overflow: hidden;
}

.app-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.header-popover {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 220px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(17, 17, 17, 0.96);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
}

.header-popover--compact {
    width: 220px;
}

.header-popover strong {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    font-weight: 600;
}

.header-popover p {
    margin: 0;
    color: rgba(255, 255, 255, 0.68);
    font-size: 13px;
    line-height: 1.6;
}

@media (max-width: 1100px) {
    .app-header {
        align-items: center;
        flex-direction: row;
        min-height: 56px;
        padding: 0 20px;
    }

    .app-header__actions {
        width: auto;
        flex: 1;
        justify-content: flex-end;
        flex-wrap: nowrap;
        overflow-x: auto;
        scrollbar-width: none;
    }

    .app-header__actions::-webkit-scrollbar {
        display: none;
    }

    .header-popover {
        right: auto;
        left: 0;
    }
}

@media (max-width: 760px) {
    .app-header {
        gap: 12px;
        padding: 0 12px;
    }

    .app-logo__text {
        display: none;
    }

    .app-header__actions {
        gap: 6px;
    }

    .app-pill,
    .app-icon-button {
        padding-inline: 10px;
        white-space: nowrap;
    }
}

@media (max-width: 520px) {
    .app-pill span {
        max-width: 72px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
}
</style>
