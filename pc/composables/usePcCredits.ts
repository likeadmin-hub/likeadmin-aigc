import { computed } from 'vue'
import { useUserStore } from '@/stores/user'

const toAmount = (value: unknown) => {
    const amount = Number(value)
    return Number.isFinite(amount) ? amount : 0
}

export function usePcCredits() {
    const userStore = useUserStore()

    const remainingCredits = computed(() =>
        toAmount(userStore.userInfo?.user_money ?? userStore.userInfo?.ai_credit_balance)
    )

    const membershipEnabled = computed(() => Boolean(
        userStore.userInfo?.member_plan_id
        || userStore.userInfo?.membership_plan
        || userStore.userInfo?.memberPlan
    ))

    const refreshCredits = async () => {
        if (!userStore.isLogin) return
        await userStore.getUser()
    }

    return {
        remainingCredits,
        membershipEnabled,
        refreshCredits
    }
}
