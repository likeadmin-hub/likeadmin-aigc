import { useAppStore } from '~~/stores/app'
import { useUserStore } from '~~/stores/user'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { normalizeTenantPath, parseTenantIdFromRoute, withTenantQuery } from '~~/utils/tenant'
import { isEmptyObject } from '~~/utils/validate'

export default defineNuxtRouteMiddleware(async (to, from) => {
    const userStore = useUserStore()
    const appStore = useAppStore()
    const { openPcLoginModal } = usePcLoginGate()
    const tenantId = parseTenantIdFromRoute(to)
    const normalizedPath = normalizeTenantPath(to.path)
    if (normalizedPath !== to.path) {
        return navigateTo(withTenantQuery({
            path: normalizedPath,
            query: to.query,
            hash: to.hash
        }, tenantId), { replace: true })
    }

    if (!appStore.configLoaded && !appStore.configLoadError && isEmptyObject(appStore.config)) {
        if (import.meta.client) {
            appStore.getConfig().catch((error) => {
                console.warn('[pc-config] load failed, skip blocking navigation', error)
            })
        } else {
            try {
                await appStore.getConfig()
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
        if (import.meta.client) {
            openPcLoginModal({
                redirect: withTenantQuery(to.fullPath, tenantId)
            })
        }
    }
})
