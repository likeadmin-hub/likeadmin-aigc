const TENANT_ID_KEY = 'tenant_id'

type RouteLike = {
    path?: string
    fullPath?: string
    query?: Record<string, any>
}

function normalizeTenantId(tenantId: unknown) {
    const value = Array.isArray(tenantId) ? tenantId[0] : tenantId
    return value ? String(value) : ''
}

function parseTenantIdFromPath(path = '') {
    const match = path.match(/\/t\/(\d+)(?:\/|$)/)
    return match?.[1] || ''
}

export function normalizeTenantPath(path = '') {
    return path.replace(/^\/t\/\d+\/pc(?=\/|$)/, '') || '/'
}

export function parseTenantIdFromRoute(route?: RouteLike) {
    if (!process.client) {
        return ''
    }
    const queryTenantId = normalizeTenantId(route?.query?.tenant_id || route?.query?.tenantId)
    if (queryTenantId) {
        setTenantId(queryTenantId)
        return queryTenantId
    }
    const pathTenantId = parseTenantIdFromPath(route?.fullPath || route?.path || '')
    if (pathTenantId) {
        setTenantId(pathTenantId)
        return pathTenantId
    }
    const query = new URLSearchParams(window.location.search)
    const browserQueryTenantId = query.get('tenant_id') || query.get('tenantId')
    if (browserQueryTenantId) {
        setTenantId(browserQueryTenantId)
        return browserQueryTenantId
    }
    const browserPathTenantId = parseTenantIdFromPath(window.location.pathname)
    if (browserPathTenantId) {
        setTenantId(browserPathTenantId)
        return browserPathTenantId
    }
    return getTenantId()
}

export function getTenantId() {
    if (!process.client) {
        return ''
    }
    return window.localStorage.getItem(TENANT_ID_KEY) || ''
}

export function setTenantId(tenantId: string | number) {
    if (process.client && tenantId) {
        window.localStorage.setItem(TENANT_ID_KEY, String(tenantId))
    }
}

export function withTenantQuery<T extends string | { path?: string; query?: Record<string, any> }>(
    target: T,
    tenantId = getTenantId()
): T {
    if (!tenantId) {
        return target
    }
    if (typeof target === 'string') {
        if (/^(https?:)?\/\//.test(target)) {
            return target
        }
        const [pathWithQuery, hash = ''] = target.split('#')
        const [path, query = ''] = pathWithQuery.split('?')
        const params = new URLSearchParams(query)
        if (!params.get('tenant_id') && !params.get('tenantId')) {
            params.set('tenant_id', tenantId)
        }
        const nextPath = normalizeTenantPath(path)
        const next = `${nextPath}${params.toString() ? `?${params.toString()}` : ''}${hash ? `#${hash}` : ''}`
        return next as T
    }
    return {
        ...target,
        path: target.path ? normalizeTenantPath(target.path) : target.path,
        query: {
            ...(target.query || {}),
            tenant_id: target.query?.tenant_id || target.query?.tenantId || tenantId
        }
    } as T
}
