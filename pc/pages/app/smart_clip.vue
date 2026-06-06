<template>
    <div class="smart-clip-redirect"></div>
</template>

<script lang="ts" setup>
definePageMeta({ layout: 'blank' })

const route = useRoute()

await navigateTo({
    path: '/ai/smart_clip',
    query: route.query
}, { replace: true })
</script>

<style scoped>
.smart-clip-redirect {
    min-height: 100vh;
    background: #050505;
}
</style>
