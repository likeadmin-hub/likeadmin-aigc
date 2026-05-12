<template>
    <div class="pc-diy-page" :style="pageStyle">
        <DiyRender :pages="pages" />
    </div>
</template>

<script lang="ts" setup>
import { getDecorate } from '@/api/shop'

const route = useRoute()
const { data } = await useAsyncData(() =>
    getDecorate({
        terminal: 'pc',
        page_code: route.params.code
    })
)

const pages = computed(() => {
    try {
        return JSON.parse(data.value?.data || '[]')
    } catch (error) {
        return []
    }
})
const meta = computed(() => {
    try {
        return data.value?.meta ? JSON.parse(data.value.meta) : []
    } catch (error) {
        return []
    }
})
const pageStyle = computed(() => {
    const content = meta.value?.[0]?.content || {}
    return content.bg_type == 2 && content.bg_image
        ? { backgroundImage: `url(${content.bg_image})` }
        : { backgroundColor: content.bg_color || '#f5f7fa' }
})
</script>

<style scoped lang="scss">
.pc-diy-page {
    min-height: 680px;
    background-repeat: no-repeat;
    background-size: 100% auto;
}
</style>
