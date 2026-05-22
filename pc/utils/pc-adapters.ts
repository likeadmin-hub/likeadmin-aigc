export const extractListData = <T = any>(payload: any): T[] => {
    if (Array.isArray(payload)) return payload
    if (!payload || typeof payload !== 'object') return []

    const candidates = [
        payload.lists,
        payload.list,
        payload.items,
        payload.rows,
        payload.data,
        payload.data?.lists,
        payload.data?.list,
        payload.data?.items,
        payload.data?.rows
    ]

    return candidates.find(Array.isArray) || []
}
