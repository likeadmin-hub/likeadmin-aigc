import cache from './cache'

const TENANT_ID_KEY = 'tenant_id'

function parseTenantIdFromPath(path = '') {
    const match = path.match(/\/t\/(\d+)(?:\/|$)/)
    return match?.[1] || ''
}

export function normalizeTenantPath(path = '') {
    return path.replace(/^\/t\/\d+\/admin(?=\/|$)/, '') || '/'
}

export function parseTenantIdFromLocation() {
    const query = new URLSearchParams(window.location.search)
    const queryTenantId = query.get('tenant_id') || query.get('tenantId')
    if (queryTenantId) {
        setTenantId(queryTenantId)
        return queryTenantId
    }
    const pathTenantId = parseTenantIdFromPath(window.location.pathname)
    if (pathTenantId) {
        setTenantId(pathTenantId)
        return pathTenantId
    }
    return getTenantId()
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
        return `${nextPath}${params.toString() ? `?${params.toString()}` : ''}${hash ? `#${hash}` : ''}` as T
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

export function getTenantId() {
    return cache.get(TENANT_ID_KEY)
}

export function setTenantId(tenantId: string | number) {
    if (tenantId) {
        cache.set(TENANT_ID_KEY, String(tenantId))
    }
}

export function getSsoTicket() {
    return new URLSearchParams(window.location.search).get('sso_ticket') || ''
}

export function clearSsoTicketFromUrl() {
    const url = new URL(window.location.href)
    url.searchParams.delete('sso_ticket')
    const tenantId = getTenantId()
    if (tenantId && !url.searchParams.get('tenant_id') && !url.searchParams.get('tenantId')) {
        url.searchParams.set('tenant_id', tenantId)
    }
    window.history.replaceState({}, document.title, url.pathname + url.search + url.hash)
}
