import { getApiUrl } from '@/utils/env'
import { normalizeFileUrl } from '@/utils/file-url'

export const resolvePcDownloadUrl = (url: unknown) => {
    const raw = String(url || '').trim()
    if (!raw) return ''
    if (/^\/\//.test(raw)) {
        if (typeof window !== 'undefined' && window.location?.protocol) {
            return `${window.location.protocol}${raw}`
        }
        return `https:${raw}`
    }

    if (/^https?:\/\//i.test(raw) || raw.startsWith('data:') || raw.startsWith('blob:')) {
        return raw
    }

    const normalized = normalizeFileUrl(raw)

    if (/^\/\//.test(normalized)) {
        if (typeof window !== 'undefined' && window.location?.protocol) {
            return `${window.location.protocol}${normalized}`
        }
        return `https:${normalized}`
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

const safeDownloadFileName = (fileName: string) => {
    const normalized = String(fileName || 'download')
        .replace(/[\\/:*?"<>|\u0000-\u001f\u007f]/g, '_')
        .trim()
    return normalized || 'download'
}

const clickDownloadLink = (href: string, fileName: string) => {
    const link = document.createElement('a')
    link.href = href
    link.download = safeDownloadFileName(fileName)
    link.rel = 'noopener noreferrer'
    link.style.display = 'none'
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
}

export const downloadPcAsset = (url: unknown, fileName = 'download') => {
    if (!import.meta.client || typeof window === 'undefined') return false
    const href = resolvePcDownloadUrl(url)
    if (!href) return false

    clickDownloadLink(href, fileName)
    return true
}

export const openPcAsset = (url: unknown) => {
    if (!import.meta.client || typeof window === 'undefined') return false
    const href = resolvePcDownloadUrl(url)
    if (!href) return false
    const win = window.open(href, '_blank', 'noopener,noreferrer')
    if (!win) {
        window.location.href = href
        return true
    }
    return true
}
