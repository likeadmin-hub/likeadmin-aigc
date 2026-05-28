import { useAppStore } from '~~/stores/app'
import { useUserStore } from '~~/stores/user'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { normalizeTenantPath, parseTenantIdFromRoute, withTenantQuery } from '~~/utils/tenant'
import { isEmptyObject } from '~~/utils/validate'

const MOBILE_BASE_PATH = '/mobile'
const MOBILE_REDIRECT_SKIP_KEYS = ['pc', 'desktop', 'force_pc', 'no_mobile_redirect']
let mobileRedirectResizeBound = false
let mobileRedirectResizeTimer: ReturnType<typeof window.setTimeout> | null = null
let currentMobileRedirectRoute: {
    to: any
    tenantId: string
    normalizedPath: string
} | null = null

const PC_TO_MOBILE_ROUTES = [
    {
        matches: ['/ai/avatar', '/app/aigc_digital_human'],
        target: '/apps/aigc_digital_human/pages/index/index'
    },
    {
        matches: ['/app/aigc_image'],
        target: '/apps/aigc_image/pages/index/index'
    },
    {
        matches: ['/app/aigc_video'],
        target: '/apps/aigc_video/pages/index/index'
    },
    {
        matches: ['/app/aigc_llm'],
        target: '/apps/aigc_llm/pages/index/index'
    },
    {
        matches: ['/user/info'],
        target: '/pages/user/user'
    },
    {
        matches: ['/user/collection'],
        target: '/pages/collection/collection'
    },
    {
        matches: ['/account/security'],
        target: '/pages/user_set/user_set'
    }
]

const isMobileVisitor = () => {
    if (!import.meta.client) return false
    const ua = window.navigator.userAgent || ''
    const isIpadOS = window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1
    const isMobileUa = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(ua)
    const viewportWidth = window.visualViewport?.width || window.innerWidth || document.documentElement.clientWidth || 0
    const isMobileViewport = viewportWidth > 0 && viewportWidth <= 640
    return isIpadOS || isMobileUa || isMobileViewport
}

const shouldSkipMobileRedirect = (query: Record<string, any>) => {
    return MOBILE_REDIRECT_SKIP_KEYS.some((key) => {
        const value = query[key]
        if (Array.isArray(value)) return value.some((item) => item !== undefined && item !== '0' && item !== 'false')
        return value !== undefined && value !== '0' && value !== 'false'
    })
}

const getMobileTargetPath = (path: string) => {
    const matched = PC_TO_MOBILE_ROUTES.find((item) => {
        return item.matches.some((prefix) => path === prefix || path.startsWith(`${prefix}/`))
    })
    return matched?.target || '/pages/index/index'
}

const appendQueryValue = (params: URLSearchParams, key: string, value: unknown) => {
    if (value === undefined || value === null) return
    if (Array.isArray(value)) {
        value.forEach((item) => appendQueryValue(params, key, item))
        return
    }
    params.append(key, String(value))
}

const buildMobileRedirectUrl = (to: any, tenantId: string, normalizedPath: string) => {
    if (!import.meta.client || !isMobileVisitor() || shouldSkipMobileRedirect(to.query || {})) {
        return ''
    }
    if (window.location.pathname === MOBILE_BASE_PATH || window.location.pathname.startsWith(`${MOBILE_BASE_PATH}/`)) {
        return ''
    }

    const params = new URLSearchParams()
    Object.entries(to.query || {}).forEach(([key, value]) => {
        if (MOBILE_REDIRECT_SKIP_KEYS.includes(key) || key === 'tenant_id' || key === 'tenantId') return
        appendQueryValue(params, key, value)
    })
    if (tenantId) {
        params.set('tenant_id', tenantId)
    }
    params.set('pc_redirect', to.fullPath || normalizedPath || '/')

    const mobilePath = `${MOBILE_BASE_PATH}${getMobileTargetPath(normalizedPath)}`
    const query = params.toString()
    return `${mobilePath}${query ? `?${query}` : ''}`
}

const watchMobileRedirect = (to: any, tenantId: string, normalizedPath: string) => {
    if (!import.meta.client) return
    currentMobileRedirectRoute = { to, tenantId, normalizedPath }
    if (mobileRedirectResizeBound) return
    mobileRedirectResizeBound = true
    const redirectOnResize = () => {
        if (mobileRedirectResizeTimer) window.clearTimeout(mobileRedirectResizeTimer)
        mobileRedirectResizeTimer = window.setTimeout(() => {
            if (!currentMobileRedirectRoute) return
            const mobileRedirectUrl = buildMobileRedirectUrl(
                currentMobileRedirectRoute.to,
                currentMobileRedirectRoute.tenantId,
                currentMobileRedirectRoute.normalizedPath
            )
            if (mobileRedirectUrl) {
                window.location.replace(mobileRedirectUrl)
            }
        }, 120)
    }
    window.addEventListener('resize', redirectOnResize, { passive: true })
    window.addEventListener('orientationchange', redirectOnResize, { passive: true })
}

export default defineNuxtRouteMiddleware(async (to, from) => {
    const userStore = useUserStore()
    const appStore = useAppStore()
    const { openPcLoginModal } = usePcLoginGate()
    const tenantId = parseTenantIdFromRoute(to)
    const normalizedPath = normalizeTenantPath(to.path)
    watchMobileRedirect(to, tenantId, normalizedPath)
    const mobileRedirectUrl = buildMobileRedirectUrl(to, tenantId, normalizedPath)
    if (mobileRedirectUrl) {
        return navigateTo(mobileRedirectUrl, { replace: true, external: true })
    }

    if (normalizedPath !== to.path) {
        return navigateTo(withTenantQuery({
            path: normalizedPath,
            query: to.query,
            hash: to.hash
        }, tenantId), { replace: true })
    }

    const shouldLoadConfig =
        (!appStore.configLoaded && !appStore.configLoadError && isEmptyObject(appStore.config)) ||
        appStore.configTenantId !== (tenantId ? String(tenantId) : '')

    if (shouldLoadConfig) {
        if (import.meta.client) {
            appStore.getConfig(tenantId).catch((error) => {
                console.warn('[pc-config] load failed, skip blocking navigation', error)
            })
        } else {
            try {
                await appStore.getConfig(tenantId)
            } catch (error) {
                console.warn('[pc-config] load failed, skip blocking navigation', error)
            }
        }
    }

    try {
        if (userStore.isLogin && isEmptyObject(userStore.userInfo)) {
            await userStore.getUser()
        }
    } catch (error) {
        userStore.$reset()
    }

    if (to.meta.auth && !userStore.isLogin) {
        const redirect = withTenantQuery(to.fullPath, tenantId)
        if (to.path !== '/login' && to.path !== '/register') {
            return navigateTo(withTenantQuery({
                path: '/login',
                query: {
                    redirect
                }
            }, tenantId))
        }
        if (import.meta.client) {
            openPcLoginModal({ redirect })
        }
    }
})
