<template>
    <div class="pc-diy-page">
        <PcDecorationRenderer
            :widgets="pages"
            :page-meta="meta"
            :resolved-sources="data?.resolved_sources || {}"
            :adapters="rendererAdapters"
        />
    </div>
</template>

<script lang="ts" setup>
import { getDecorate } from '@/api/shop'
import { useAppStore } from '@/stores/app'
import PcDecorationRenderer from '@pc-decoration'
import { normalizePcDecorationWidgets, normalizePcPageMeta } from '@decoration-core'

const route = useRoute()
const { data } = await useAsyncData(() =>
    getDecorate({
        terminal: 'pc',
        page_code: route.params.code,
        preview: route.query.preview,
        template_id: route.query.template_id,
        page_id: route.query.page_id
    })
)
const liveData = ref<any[] | null>(null)
const liveMeta = ref<any[] | null>(null)
const router = useRouter()
const appStore = useAppStore()

const pages = computed(() => {
    try {
        return normalizePcDecorationWidgets(liveData.value || JSON.parse(data.value?.data || '[]'))
    } catch (error) {
        return normalizePcDecorationWidgets([])
    }
})
const meta = computed(() => {
    try {
        return normalizePcPageMeta(liveMeta.value || (data.value?.meta ? JSON.parse(data.value.meta) : []))
    } catch (error) {
        return normalizePcPageMeta([])
    }
})
const rendererAdapters = computed(() => ({
    resolveImage: (url: string) => appStore.getImageUrl(url),
    navigate: (path: string) => {
        if (!path) return
        router.push(path)
    }
}))
const parseJsonArray = (value: any) => {
    if (Array.isArray(value)) return value
    if (typeof value !== 'string') return null
    try {
        const parsed = JSON.parse(value)
        return Array.isArray(parsed) ? parsed : null
    } catch (error) {
        return null
    }
}
const handlePcDecoratePreviewMessage = (event: MessageEvent) => {
    const payload = event.data || {}
    if (payload?.type !== 'LIKEADMIN_PC_DECORATE_PREVIEW') return
    if (payload.page_code && payload.page_code !== route.params.code) return
    liveData.value = parseJsonArray(payload.data) || []
    liveMeta.value = parseJsonArray(payload.meta) || []
}

onMounted(() => {
    window.addEventListener('message', handlePcDecoratePreviewMessage)
})

onBeforeUnmount(() => {
    window.removeEventListener('message', handlePcDecoratePreviewMessage)
})
</script>

<style scoped lang="scss">
.pc-diy-page {
    min-height: 100vh;
}
</style>
