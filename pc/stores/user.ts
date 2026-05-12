import { getUserCenter } from '@/api/user'
import { TOKEN_KEY } from '@/enums/cacheEnums'
import { defineStore } from 'pinia'

interface UserSate {
    userInfo: Record<string, any>
    token: string | null
    temToken: string | null
    avatarVersion: number
}
export const useUserStore = defineStore({
    id: 'userStore',
    state: (): UserSate => {
        const TOKEN = useCookie(TOKEN_KEY)
        return {
            userInfo: {},
            token: TOKEN.value || null,
            temToken: null,
            avatarVersion: Date.now()
        }
    },
    getters: {
        isLogin: (state) => !!state.token
    },
    actions: {
        async getUser() {
            const data = await getUserCenter()
            this.userInfo = data
            this.avatarVersion = Date.now()
        },
        setUser(userInfo) {
            this.userInfo = userInfo
            this.avatarVersion = Date.now()
        },
        login(token: string) {
            const TOKEN = useCookie(TOKEN_KEY)
            this.token = token
            TOKEN.value = token
        },
        logout() {
            const TOKEN = useCookie(TOKEN_KEY)
            this.token = null
            this.userInfo = {}
            this.avatarVersion = Date.now()
            TOKEN.value = null
        }
    }
})
