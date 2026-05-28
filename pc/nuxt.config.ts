// https://v3.nuxtjs.org/api/configuration/nuxt.config

import { fileURLToPath, URL } from 'node:url'
import { DEFAULT_PC_DEV_API_HOST, PUBLIC_API_HOST } from './constants/public-api'
import { getEnvConfig } from './nuxt/env'

const envConfig = getEnvConfig()

const explicitApiHost = (envConfig.publicApiHost as string | undefined)?.trim()
const publicTenantSn = (envConfig.publicTenantSn as string | undefined)?.trim()
const publicTenantRootDomain =
    (envConfig.publicTenantRootDomain as string | undefined)?.trim() || PUBLIC_API_HOST
const legacyApiProxyTarget = (envConfig.apiProxyTarget as string | undefined)?.trim()
const legacyApiProxyHost = (envConfig.apiProxyHost as string | undefined)?.trim()

/** 与库表 la_tenant 一致：优先 NUXT_PUBLIC_API_HOST；否则 sn.根域名；开发环境再默认 DEFAULT_PC_DEV_API_HOST */
const publicApiHost =
    explicitApiHost ||
    (publicTenantSn ? `${publicTenantSn}.${publicTenantRootDomain}` : '') ||
    (process.env.NODE_ENV === 'development' ? DEFAULT_PC_DEV_API_HOST : '') ||
    PUBLIC_API_HOST
const devProxyHost =
    (envConfig.devProxyHost as string | undefined)?.trim() || legacyApiProxyHost || ''
const hasExplicitDevApiTarget = !!((envConfig.apiUrl as string | undefined)?.trim() || legacyApiProxyTarget)
/** 开发时未配置 NUXT_API_URL 时，本地常见为 http 访问域名，勿默认走 https 以免代理失败（500） */
const devApiTarget =
    (envConfig.apiUrl as string | undefined)?.trim() ||
    legacyApiProxyTarget ||
    (process.env.NODE_ENV === 'development' ? `http://${publicApiHost}` : `https://${publicApiHost}`)

/** Nitro devProxy：当代理目标主机名与租户域名不一致时（如 NUXT_API_URL 指向 IP）改写 Host，以匹配 la_tenant.domain_alias */
function devApiProxyOptions() {
    const base: {
        target: string
        changeOrigin: boolean
        configure?: (proxy: { on: (event: string, fn: (req: { setHeader: (k: string, v: string) => void }) => void) => void }) => void
    } = {
        target: devApiTarget,
        changeOrigin: true
    }
    let hostHeader = devProxyHost
    if (!hostHeader && !hasExplicitDevApiTarget) {
        try {
            const u = new URL(devApiTarget)
            if (publicApiHost && u.hostname !== publicApiHost) {
                hostHeader = publicApiHost
            }
        } catch {
            /* ignore */
        }
    }
    if (hostHeader) {
        base.configure = (proxy) => {
            proxy.on('proxyReq', (proxyReq) => {
                proxyReq.setHeader('Host', hostHeader)
            })
        }
    }
    return base
}

/** 开发时浏览器请求 /api → 由 Vite + Nitro 双通道转发到 PHP（仅任一生效亦可，双写避免版本差异导致 404） */
const pcApiDevProxy = devApiProxyOptions()

const aiToolDetailRoutes = [
    '/ai/tools/tool-card-1',
    '/ai/tools/tool-card-12',
    '/ai/tools/tool-card-13',
    '/ai/tools/tool-card-14',
    '/ai/tools/tool-card-15',
    '/ai/tools/tool-card-16',
    '/ai/tools/tool-card-17',
    '/ai/tools/tool-card-18',
    '/ai/tools/tool-card-19',
    '/ai/tools/tool-card-20',
    '/ai/tools/tool-card-21',
    '/ai/tools/tool-card-22',
    '/ai/tools/tool-card-23',
    '/ai/tools/tool-card-24',
    '/ai/tools/tool-card-25',
    '/ai/tools/tool-card-26',
    '/ai/tools/tool-card-27',
    '/ai/tools/tool-card-31',
    '/ai/tools/tool-card-28',
    '/ai/tools/tool-card-29',
    '/ai/tools/tool-card-30'
]

export default defineNuxtConfig({
    css: ['@/assets/styles/index.scss'],
    modules: ['@pinia/nuxt', '@nuxtjs/tailwindcss', '@element-plus/nuxt'],
    app: {
        baseURL: envConfig.baseUrl || '/'
    },
    nitro: {
        prerender: {
            routes: aiToolDetailRoutes
        },
        devProxy: {
            '/api': pcApiDevProxy,
            '/api/': pcApiDevProxy,
            '/uploads': pcApiDevProxy,
            '/uploads/': pcApiDevProxy
        }
    },
    vite: {
        resolve: {
            alias: {
                '@decoration-core': fileURLToPath(new URL('../packages/decoration-core/index.ts', import.meta.url)),
                '@pc-decoration': fileURLToPath(new URL('../packages/pc-decoration/PcDecorationRenderer.vue', import.meta.url))
            }
        },
        server: {
            proxy: {
                '/api': pcApiDevProxy,
                '/uploads': pcApiDevProxy
            }
        }
    },
    runtimeConfig: {
        public: {
            ...envConfig
        }
    },
    ssr: !!envConfig.ssr
})
