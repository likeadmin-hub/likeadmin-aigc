import cache from './cache'

const TENANT_ID_KEY = 'tenant_id'

function normalizeTenantId(tenantId: unknown) {
    const value = Array.isArray(tenantId) ? tenantId[0] : tenantId
    return value ? String(value) : ''
}

export function parseTenantIdFromPath(path = '') {
    const match = path.match(/\/t\/(\d+)(?:\/|$)/)
    return match?.[1] || ''
}

export function parseTenantIdFromSearch(search = '') {
    const query = search.startsWith('?') ? search.slice(1) : search
    const params = new URLSearchParams(query)
    return params.get('tenant_id') || params.get('tenantId') || ''
}

export function getTenantId() {
    return cache.get(TENANT_ID_KEY) || ''
}

export function setTenantId(tenantId: string | number) {
    if (tenantId) {
        cache.set(TENANT_ID_KEY, String(tenantId))
    }
}

export function initTenantIdFromLaunch(options?: Record<string, any>) {
    const tenantId = normalizeTenantId(options?.tenant_id || options?.tenantId)
    if (tenantId) {
        setTenantId(tenantId)
    }
    return getTenantId()
}

export function initTenantIdFromH5Location() {
    // #ifdef H5
    if (typeof window === 'undefined') {
        return getTenantId()
    }
    const tenantId =
        parseTenantIdFromSearch(window.location.search) || parseTenantIdFromPath(window.location.pathname)
    if (tenantId) {
        setTenantId(tenantId)
    }
    // #endif
    return getTenantId()
}

export function appendTenantIdToUrl(url: string, tenantId = getTenantId()) {
    // #ifndef H5
    return url
    // #endif

    if (!tenantId || !url || /^(https?:)?\/\//.test(url)) {
        return url
    }
    const [pathWithQuery, hash = ''] = url.split('#')
    const [path, query = ''] = pathWithQuery.split('?')
    const pairs = query ? query.split('&').filter(Boolean) : []
    const hasTenantId = pairs.some((item) => {
        const key = decodeURIComponent(item.split('=')[0] || '')
        return key === 'tenant_id' || key === 'tenantId'
    })
    if (!hasTenantId) {
        pairs.push(`tenant_id=${encodeURIComponent(tenantId)}`)
    }
    return `${path}${pairs.length ? `?${pairs.join('&')}` : ''}${hash ? `#${hash}` : ''}`
}
