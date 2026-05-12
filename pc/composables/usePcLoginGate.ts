import { useUserStore } from '@/stores/user'

type OpenPcLoginOptions = {
    redirect?: string
}

const PC_LOGIN_REQUIRED_NAME = 'PcLoginRequiredError'

const resolveCurrentFullPath = () => {
    if (import.meta.client && typeof window !== 'undefined') {
        return `${window.location.pathname}${window.location.search}${window.location.hash}`
    }

    try {
        return useRoute().fullPath
    } catch (_error) {
        return ''
    }
}

export const createPcLoginRequiredError = () => {
    const error = new Error('') as Error & { __pcLoginRequired?: boolean }
    error.name = PC_LOGIN_REQUIRED_NAME
    error.__pcLoginRequired = true
    return error
}

export const isPcLoginRequiredError = (error: unknown) => {
    return Boolean(
        error
        && typeof error === 'object'
        && (
            (error as { __pcLoginRequired?: boolean }).__pcLoginRequired
            || (error as Error).name === PC_LOGIN_REQUIRED_NAME
        )
    )
}

export const usePcLoginGate = () => {
    const userStore = useUserStore()
    const showLoginModal = useState<boolean>('pc-login-modal-visible', () => false)
    const loginRedirect = useState<string>('pc-login-modal-redirect', () => '')
    const lastAuthFailureAt = useState<number>('pc-login-modal-last-auth-failure-at', () => 0)

    const openPcLoginModal = (options: OpenPcLoginOptions = {}) => {
        if (userStore.isLogin) return true
        loginRedirect.value = options.redirect || loginRedirect.value || resolveCurrentFullPath() || '/'
        showLoginModal.value = true
        return false
    }

    const closePcLoginModal = () => {
        showLoginModal.value = false
    }

    const ensurePcLogin = (options: OpenPcLoginOptions = {}) => {
        if (userStore.isLogin) return true
        openPcLoginModal(options)
        return false
    }

    const clearLegacyLoginQuery = async () => {
        try {
            const route = useRoute()
            const router = useRouter()
            if (!route.query.login && !route.query.redirect) return
            const query = { ...route.query }
            delete query.login
            delete query.redirect
            await router.replace({ path: route.path, query, hash: route.hash })
        } catch (_error) {
            // ignore outside route-aware contexts
        }
    }

    const handlePcLoginSuccess = async () => {
        closePcLoginModal()
        const target = loginRedirect.value
        loginRedirect.value = ''

        try {
            const route = useRoute()
            const router = useRouter()
            await clearLegacyLoginQuery()
            if (target && target.startsWith('/') && target !== route.fullPath) {
                await router.replace(target)
            }
        } catch (_error) {
            // ignore outside route-aware contexts
        }
    }

    const handlePcLoginFailure = (options: OpenPcLoginOptions = {}) => {
        userStore.logout()

        const now = Date.now()
        if (now - lastAuthFailureAt.value > 600) {
            lastAuthFailureAt.value = now
            openPcLoginModal(options)
        }

        return createPcLoginRequiredError()
    }

    return {
        showLoginModal,
        loginRedirect,
        openPcLoginModal,
        closePcLoginModal,
        ensurePcLogin,
        handlePcLoginSuccess,
        handlePcLoginFailure
    }
}
