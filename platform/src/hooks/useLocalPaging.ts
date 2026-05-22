import { computed, reactive, ref } from 'vue'

export function useLocalPaging<T = any>(options: { page?: number; size?: number } = {}) {
    const pager = reactive({
        page: options.page || 1,
        size: options.size || 15,
        loading: false,
        count: 0,
        lists: [] as T[]
    })
    const isRemotePage = ref(false)
    const sourceLists = ref<T[]>([])

    const normalizeResult = (result: any) => {
        if (Array.isArray(result)) {
            return {
                lists: result,
                count: result.length,
                remote: false
            }
        }
        const lists = result?.lists || result?.data?.lists || []
        const count = Number(result?.count ?? result?.data?.count ?? lists.length)
        return {
            lists: Array.isArray(lists) ? lists : [],
            count,
            remote: count > lists.length
        }
    }

    const tableLists = computed(() => {
        if (isRemotePage.value) {
            return sourceLists.value
        }
        const start = (pager.page - 1) * pager.size
        return sourceLists.value.slice(start, start + pager.size)
    })

    const setLists = (result: any) => {
        const data = normalizeResult(result)
        sourceLists.value = data.lists
        pager.count = data.count
        isRemotePage.value = data.remote
        const maxPage = Math.max(1, Math.ceil(pager.count / pager.size))
        if (!isRemotePage.value && pager.page > maxPage) {
            pager.page = maxPage
        }
        pager.lists = tableLists.value
    }

    const getPagingParams = () => ({
        page_no: pager.page,
        page_size: pager.size
    })

    const resetPage = () => {
        pager.page = 1
    }

    return {
        pager,
        tableLists,
        setLists,
        getPagingParams,
        resetPage
    }
}
