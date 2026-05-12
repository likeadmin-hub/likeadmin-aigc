import { computed } from 'vue'
import { useUserStore } from '~~/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'
import defaultAvatarUrl from '@/assets/images/default-avatar.svg'

/**
 * AI 应用内展示的用户信息，与「个人中心 /user/info」字段来源一致（userStore，来自 /user/center）。
 */
export function useAiUserDisplay() {
    const userStore = useUserStore()

    const displayAvatarUrl = computed(
        () =>
            normalizeFileUrl(
                userStore.userInfo?.avatar,
                userStore.avatarVersion
            ) || defaultAvatarUrl
    )

    const displayNickname = computed(() => {
        const nick = userStore.userInfo?.nickname
        const account = userStore.userInfo?.account
        if (nick != null && String(nick).trim() !== '') return String(nick)
        if (account != null && String(account).trim() !== '') return String(account)
        return '游客'
    })

    const displayAccount = computed(() => {
        const a = userStore.userInfo?.account
        return a != null && String(a).trim() !== '' ? String(a) : ''
    })

    return {
        displayAvatarUrl,
        displayNickname,
        displayAccount
    }
}
