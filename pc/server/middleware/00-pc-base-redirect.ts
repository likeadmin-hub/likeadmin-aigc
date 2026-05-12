/**
 * 当 app.baseURL 为 /pc/ 时，访问 http://localhost:3000/ai 会找不到路由（易 503）。
 * 将未带前缀的路径 302 到 /pc/...，与 Nuxt 路由一致。
 */
export default defineEventHandler((event) => {
    if (event.method && event.method !== 'GET' && event.method !== 'HEAD') {
        return
    }

    const config = useRuntimeConfig(event)
    const raw = String((config.public as { baseUrl?: string }).baseUrl ?? '/pc/').trim()
    /** 部署在站点根路径（NUXT_BASE_URL=/）时不做重定向 */
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
