/**
 * 归一化后端返回的文件 URL。
 * - 本站上传资源若返回完整 URL，则仅保留 path，交给同源或代理处理
 * - 其它外链、data/blob、本地相对地址保持原样
 * - 传入 cacheKey 时自动追加版本参数，避免头像等资源命中旧缓存
 */
export function normalizeFileUrl(
    url: string | null | undefined,
    cacheKey?: string | number
): string {
    if (url == null) return ''
    let raw = String(url).trim()
    if (!raw) return ''

    raw = raw.replace(/\\/g, '/')

    if (raw.startsWith('data:') || raw.startsWith('blob:')) {
        return appendCacheKey(raw, cacheKey)
    }

    if (
        !raw.startsWith('/') &&
        !/^https?:\/\//i.test(raw) &&
        /^(uploads|storage|static|public)\b/i.test(raw)
    ) {
        raw = `/${raw.replace(/^\/+/, '')}`
    }

    if (raw.startsWith('/')) return appendCacheKey(raw, cacheKey)

    try {
        const parsed = new URL(raw)
        if (
            (parsed.protocol === 'http:' || parsed.protocol === 'https:') &&
            parsed.pathname.startsWith('/uploads/')
        ) {
            return appendCacheKey(
                parsed.pathname + (parsed.search || '') + (parsed.hash || ''),
                cacheKey
            )
        }
    } catch {
        // 非标准 URL，继续返回原始值
    }

    return appendCacheKey(raw, cacheKey)
}

function appendCacheKey(
    url: string,
    cacheKey?: string | number
): string {
    if (cacheKey === undefined || cacheKey === null || cacheKey === '') {
        return url
    }

    const hashIndex = url.indexOf('#')
    const hash = hashIndex >= 0 ? url.slice(hashIndex) : ''
    const base = hashIndex >= 0 ? url.slice(0, hashIndex) : url
    const joiner = base.includes('?') ? '&' : '?'

    return `${base}${joiner}v=${encodeURIComponent(String(cacheKey))}${hash}`
}
