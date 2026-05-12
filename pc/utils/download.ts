import { getApiUrl } from '@/utils/env'
import { normalizeFileUrl } from '@/utils/file-url'

export const resolvePcDownloadUrl = (url: unknown) => {
    const raw = String(url || '').trim()
    if (!raw) return ''
    const normalized = normalizeFileUrl(raw)

    if (/^\/\//.test(normalized)) {
        return `${window.location.protocol}${normalized}`
    }

    if (/^https?:\/\//i.test(normalized) || normalized.startsWith('data:') || normalized.startsWith('blob:')) {
        return normalized
    }

    if (normalized.startsWith('/')) {
        const apiUrl = String(getApiUrl() || '').replace(/\/+$/, '')
        if (apiUrl) return `${apiUrl}${normalized}`
        if (typeof window !== 'undefined' && window.location?.origin) {
            return `${window.location.origin}${normalized}`
        }
    }

    return normalized
}

export const getPcDownloadExtension = (url: unknown, fallback = 'png') => {
    const pathname = String(url || '').split('?')[0] || ''
    const match = pathname.match(/\.([a-z0-9]{2,8})$/i)
    return match?.[1] || fallback
}

export const downloadPcAsset = (url: unknown, _fileName?: string) => {
    if (!import.meta.client || typeof window === 'undefined') return false
    const href = resolvePcDownloadUrl(url)
    if (!href) return false
    return Boolean(window.open(href, '_blank', 'noopener,noreferrer'))
}

export const openPcAsset = (url: unknown) => {
    if (!import.meta.client || typeof window === 'undefined') return false
    const href = resolvePcDownloadUrl(url)
    if (!href) return false
    window.open(href, '_blank')
    return true
}
