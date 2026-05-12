import { defineStore } from 'pinia'
import { getConfig } from '~~/api/app'

interface AppSate {
    config: Record<string, any>
    configLoaded: boolean
    configLoading: boolean
    configLoadError: boolean
}
export const useAppStore = defineStore({
    id: 'appStore',
    state: (): AppSate => ({
        config: {},
        configLoaded: false,
        configLoading: false,
        configLoadError: false
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
        async getConfig() {
            if (this.configLoading) return this.config
            if (this.configLoaded) return this.config

            this.configLoading = true
            try {
                const config = await getConfig()
                this.config = config || {}
                this.configLoaded = true
                this.configLoadError = false
                return this.config
            } catch (error) {
                this.configLoadError = true
                throw error
            } finally {
                this.configLoading = false
            }
        },
        setConfig(config: Record<string, any>) {
            this.config = config
            this.configLoaded = true
            this.configLoadError = false
        }
    }
})
