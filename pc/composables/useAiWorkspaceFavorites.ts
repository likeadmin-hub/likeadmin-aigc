const favoriteKey = (category: string, id: unknown) => `${category}:${String(id || '')}`
const favoriteStorageKey = 'ai-workspace-favorite-ids'
const favoriteItemsStorageKey = 'ai-workspace-favorite-items'

export interface AiWorkspaceFavoriteItem {
    category: string
    id: string
    title: string
    desc?: string
    image?: string
    url?: string
    collect_time?: string
}

export const useAiWorkspaceFavorites = () => {
    const favoriteIds = useState<string[]>('ai-workspace-favorite-ids', () => [])
    const favoriteItems = useState<AiWorkspaceFavoriteItem[]>('ai-workspace-favorite-items', () => [])
    const hydrated = useState<boolean>('ai-workspace-favorite-hydrated', () => false)

    if (import.meta.client && !hydrated.value) {
        hydrated.value = true
        try {
            const saved = JSON.parse(window.localStorage.getItem(favoriteStorageKey) || '[]')
            if (Array.isArray(saved)) favoriteIds.value = saved.filter((item) => typeof item === 'string')
            const savedItems = JSON.parse(window.localStorage.getItem(favoriteItemsStorageKey) || '[]')
            if (Array.isArray(savedItems)) {
                favoriteItems.value = savedItems.filter((item) => item && typeof item === 'object')
            }
        } catch (error) {
            window.localStorage.removeItem(favoriteStorageKey)
            window.localStorage.removeItem(favoriteItemsStorageKey)
        }
    }

    const syncStorage = () => {
        if (!import.meta.client) return
        window.localStorage.setItem(favoriteStorageKey, JSON.stringify(favoriteIds.value))
        window.localStorage.setItem(favoriteItemsStorageKey, JSON.stringify(favoriteItems.value))
    }

    const isFavorite = (category: string, id: unknown) => favoriteIds.value.includes(favoriteKey(category, id))

    const setFavorite = (category: string, id: unknown, favorite: boolean) => {
        const key = favoriteKey(category, id)
        if (!key || key.endsWith(':')) return
        favoriteIds.value = favorite
            ? Array.from(new Set([...favoriteIds.value, key]))
            : favoriteIds.value.filter((item) => item !== key)
        if (!favorite) {
            favoriteItems.value = favoriteItems.value.filter((item) => favoriteKey(item.category, item.id) !== key)
        }
        syncStorage()
    }

    const setFavoriteItem = (item: AiWorkspaceFavoriteItem, favorite: boolean) => {
        setFavorite(item.category, item.id, favorite)
        if (!favorite) return
        const key = favoriteKey(item.category, item.id)
        const nextItem = {
            ...item,
            id: String(item.id || ''),
            collect_time: item.collect_time || new Date().toLocaleString('zh-CN', { hour12: false })
        }
        favoriteItems.value = [
            nextItem,
            ...favoriteItems.value.filter((favoriteItem) => favoriteKey(favoriteItem.category, favoriteItem.id) !== key)
        ]
        syncStorage()
    }

    const toggleFavorite = (category: string, id: unknown) => {
        const nextFavorite = !isFavorite(category, id)
        setFavorite(category, id, nextFavorite)
        return nextFavorite
    }

    return {
        favoriteIds,
        favoriteItems,
        isFavorite,
        setFavorite,
        setFavoriteItem,
        toggleFavorite
    }
}
