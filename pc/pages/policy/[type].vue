<template>
    <div class="policy-page bg-white render-html mx-auto min-h-screen">
        <h1 class="text-center">{{ data.title }}</h1>
        <div class="mx-auto" v-html="data.content"></div>
    </div>
</template>
<script lang="ts" setup>
import { getPolicy } from '~~/api/app'

const route = useRoute()
const { data } = await useAsyncData(
    () =>
        getPolicy({
            type: route.params.type
        }),
    {
        initialCache: false
    }
)

definePageMeta({
    layout: 'blank'
})
</script>
<style lang="scss" scoped>
.policy-page {
    width: min(var(--pc-shell-max), calc(100vw - var(--pc-page-gutter-total)));
    min-width: 0;
    padding: clamp(20px, 4vw, 30px);
    box-sizing: border-box;
    word-break: break-word;
}
</style>
