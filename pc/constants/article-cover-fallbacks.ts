/**
 * 与首页 `index.vue` 资讯区块所用 `/figma-home/*` 配图一致，
 * 无封面或加载失败时按文章 id 轮换，避免空白/裂图。
 */
export const HOME_ARTICLE_COVER_FALLBACKS = [
    '/figma-home/76_259.png',
    '/figma-home/76_297.png',
    '/figma-home/76_313.png',
    '/figma-home/76_321.png',
    '/figma-home/9_253.png'
] as const

export function homeCoverFallbackById(id: unknown): string {
    const n =
        typeof id === 'number'
            ? id
            : typeof id === 'string'
              ? parseInt(id, 10) || 0
              : 0
    const i = Math.abs(n) % HOME_ARTICLE_COVER_FALLBACKS.length
    return HOME_ARTICLE_COVER_FALLBACKS[i]
}

export function homeCoverFallbackByIndex(index: number): string {
    const i = Math.abs(index) % HOME_ARTICLE_COVER_FALLBACKS.length
    return HOME_ARTICLE_COVER_FALLBACKS[i]
}
