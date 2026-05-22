<template>
    <div class="redirect-page"></div>
</template>

<script lang="ts" setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'

definePageMeta({ layout: 'blank' })

const router = useRouter()

onMounted(() => {
    router.replace({ path: '/ai/avatar', query: { tab: 'image_human' } })
})
</script>

<style scoped>
.redirect-page {
    min-height: 100vh;
    background: #050505;
}
</style>
