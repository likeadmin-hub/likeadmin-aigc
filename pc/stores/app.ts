import { defineStore } from 'pinia'
import { getConfig } from '~~/api/app'

interface AppSate {
    config: Record<string, any>
    configLoaded: boolean
    configLoading: boolean
    configLoadError: boolean
    configTenantId: string
}

let configLoadPromise: Promise<Record<string, any>> | null = null
let configLoadTenantId = ''

export const useAppStore = defineStore({
    id: 'appStore',
    state: (): AppSate => ({
        config: {},
        configLoaded: false,
        configLoading: false,
        configLoadError: false,
        configTenantId: ''
    }),
    getters: {
        getImageUrl: (state) => (url: string) => (url ? `${state.config.domain}${url}` : ''),
        getWebsiteConfig: (state) => state.config.website || {},
        getLoginConfig: (state) => state.config.login || {},
        getCopyrightConfig: (state) => state.config.copyright || [],
        getQrcodeConfig: (state) => state.config.qrcode || {},
        getAdminUrl: (state) => state.config.admin_url,
        getSiteStatistics: (state) => state.config.siteStatistics || {}
    },
    actions: {
        async getConfig(tenantId = '', force = false) {
            const normalizedTenantId = tenantId ? String(tenantId) : ''
            if (this.configLoading && configLoadPromise && configLoadTenantId === normalizedTenantId) {
                return configLoadPromise
            }
            if (!force && this.configLoaded && this.configTenantId === normalizedTenantId) {
                return this.config
            }

            this.configLoading = true
            configLoadTenantId = normalizedTenantId
            const requestPromise = (async () => {
                const config = await getConfig(normalizedTenantId ? { tenant_id: normalizedTenantId } : undefined)
                this.config = config || {}
                this.configLoaded = true
                this.configTenantId = normalizedTenantId
                this.configLoadError = false
                return this.config
            })()
            configLoadPromise = requestPromise

            try {
                return await requestPromise
            } catch (error) {
                this.configLoadError = true
                throw error
            } finally {
                if (configLoadPromise === requestPromise) {
                    this.configLoading = false
                    configLoadPromise = null
                    configLoadTenantId = ''
                }
            }
        },
        setConfig(config: Record<string, any>, tenantId = '') {
            this.config = config
            this.configLoaded = true
            this.configTenantId = tenantId ? String(tenantId) : ''
            this.configLoadError = false
        }
    }
})
