import { computed } from 'vue'
import {
    MEMBERSHIP_PLANS,
    type MembershipPlanId
} from '@/constants/membership-plans'
import { useUserStore } from '@/stores/user'

const PLAN_ORDER: MembershipPlanId[] = ['free', 'basic', 'advanced']

function parsePlanId(raw: unknown): MembershipPlanId {
    const value = String(raw ?? '').trim().toLowerCase()
    if (value === 'free' || value === '免费会员' || value === '0' || value === '1') return 'free'
    if (value === 'basic' || value === '基础会员' || value === '2') return 'basic'
    if (value === 'advanced' || value === 'pro' || value === '高级会员' || value === '3') return 'advanced'
    return 'free'
}

/**
 * PC 顶栏会员展示（可与后端 userInfo.member_plan_id / membership_plan 对接）
 */
export function usePcMembership() {
    const userStore = useUserStore()

    const currentPlanId = computed<MembershipPlanId>(() =>
        parsePlanId(
            userStore.userInfo?.member_plan_id ??
                userStore.userInfo?.membership_plan ??
                userStore.userInfo?.memberPlan
        )
    )

    const currentPlanTitle = computed(() => {
        const p = MEMBERSHIP_PLANS.find((x) => x.id === currentPlanId.value)
        return p?.title ?? '会员'
    })

    const canUpgrade = computed(() => {
        const i = PLAN_ORDER.indexOf(currentPlanId.value)
        return i >= 0 && i < PLAN_ORDER.length - 1
    })

    const nextPlanTitle = computed(() => {
        const i = PLAN_ORDER.indexOf(currentPlanId.value)
        if (i < 0 || i >= PLAN_ORDER.length - 1) return ''
        return MEMBERSHIP_PLANS.find((x) => x.id === PLAN_ORDER[i + 1])?.title ?? ''
    })

    return {
        currentPlanId,
        currentPlanTitle,
        canUpgrade,
        nextPlanTitle
    }
}
