/**
 * PC 端默认挂在站点根路径。仅当显式配置了非根 baseURL 时，才补齐前缀。
 */
export default defineEventHandler((event) => {
    if (event.method && event.method !== 'GET' && event.method !== 'HEAD') {
        return
    }

    const config = useRuntimeConfig(event)
    const raw = String((config.public as { baseUrl?: string }).baseUrl ?? '/').trim()
    if (!raw || raw === '/') {
        return
    }

    const basePath = raw.replace(/\/+$/, '')
    if (!basePath || basePath === '/') {
        return
    }

    const url = getRequestURL(event)
    const path = url.pathname

    if (path === basePath || path === `${basePath}/` || path.startsWith(`${basePath}/`)) {
        return
    }

    const skipPrefixes = ['/api', '/_nuxt', '/__nuxt', '/@', '/node_modules', '/.well-known', '/__webpack_hmr']
    if (skipPrefixes.some((p) => path.startsWith(p))) {
        return
    }

    const dest = `${basePath}${path === '/' ? '/' : path}${url.search}`
    return sendRedirect(event, dest, 302)
})
