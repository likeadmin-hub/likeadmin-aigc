import { useUserStore } from '@/stores/user'

type OpenPcLoginOptions = {
    redirect?: string
    returnTo?: string
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
    const loginReturnTo = useState<string>('pc-login-modal-return-to', () => '')

    const openPcLoginModal = (options: OpenPcLoginOptions = {}) => {
        if (userStore.isLogin) return true
        const currentPath = resolveCurrentFullPath() || '/'
        loginRedirect.value = options.redirect || loginRedirect.value || currentPath
        loginReturnTo.value = options.returnTo || loginReturnTo.value || currentPath
        showLoginModal.value = true
        return false
    }

    const closePcLoginModal = async () => {
        showLoginModal.value = false
        const target = loginReturnTo.value
        loginRedirect.value = ''
        loginReturnTo.value = ''

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
        showLoginModal.value = false
        const target = loginRedirect.value
        loginRedirect.value = ''
        loginReturnTo.value = ''

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

    const handlePcLoginFailure = () => {
        userStore.logout()

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
