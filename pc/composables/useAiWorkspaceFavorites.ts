const favoriteKey = (category: string, id: unknown) => `${category}:${String(id || '')}`
const favoriteStorageKey = 'ai-workspace-favorite-ids'

export const useAiWorkspaceFavorites = () => {
    const favoriteIds = useState<string[]>('ai-workspace-favorite-ids', () => [])
    const hydrated = useState<boolean>('ai-workspace-favorite-hydrated', () => false)

    if (import.meta.client && !hydrated.value) {
        hydrated.value = true
        try {
            const saved = JSON.parse(window.localStorage.getItem(favoriteStorageKey) || '[]')
            if (Array.isArray(saved)) favoriteIds.value = saved.filter((item) => typeof item === 'string')
        } catch (error) {
            window.localStorage.removeItem(favoriteStorageKey)
        }
    }

    const syncStorage = () => {
        if (!import.meta.client) return
        window.localStorage.setItem(favoriteStorageKey, JSON.stringify(favoriteIds.value))
    }

    const isFavorite = (category: string, id: unknown) => favoriteIds.value.includes(favoriteKey(category, id))

    const setFavorite = (category: string, id: unknown, favorite: boolean) => {
        const key = favoriteKey(category, id)
        if (!key || key.endsWith(':')) return
        favoriteIds.value = favorite
            ? Array.from(new Set([...favoriteIds.value, key]))
            : favoriteIds.value.filter((item) => item !== key)
        syncStorage()
    }

    const toggleFavorite = (category: string, id: unknown) => {
        const nextFavorite = !isFavorite(category, id)
        setFavorite(category, id, nextFavorite)
        return nextFavorite
    }

    return {
        favoriteIds,
        isFavorite,
        setFavorite,
        toggleFavorite
    }
}
