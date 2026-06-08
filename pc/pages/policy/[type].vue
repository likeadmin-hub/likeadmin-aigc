<template>
    <main class="policy-page">
        <NuxtLink class="policy-page__back" to="/">返回首页</NuxtLink>
        <article class="policy-page__panel">
            <h1>{{ policy.title }}</h1>
            <div class="policy-page__content render-html" v-html="policy.content"></div>
        </article>
    </main>
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
const policy = computed(() => ({
    title: data.value?.title || '用户服务协议',
    content: data.value?.content || ''
}))

useHead(() => ({
    title: policy.value.title
}))

definePageMeta({
    layout: 'blank'
})
</script>
<style lang="scss" scoped>
.policy-page {
    min-height: 100vh;
    padding: 34px max(24px, calc((100vw - 920px) / 2));
    background: #07080a;
    color: #fff;
    box-sizing: border-box;
}

.policy-page__back {
    display: inline-flex;
    align-items: center;
    height: 36px;
    padding: 0 14px;
    margin-bottom: 18px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 999px;
    color: rgba(255, 255, 255, 0.72);
    font-size: 14px;
    text-decoration: none;
    background: rgba(255, 255, 255, 0.04);
}

.policy-page__back:hover {
    color: #fff;
    border-color: rgba(255, 255, 255, 0.2);
}

.policy-page__panel {
    min-height: calc(100vh - 104px);
    padding: clamp(24px, 4vw, 42px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: #111214;
    box-shadow: 0 28px 80px rgba(0, 0, 0, 0.34);
    box-sizing: border-box;
}

.policy-page__panel h1 {
    margin: 0 0 24px;
    font-size: clamp(26px, 4vw, 38px);
    line-height: 1.2;
    letter-spacing: 0;
}

.policy-page__content {
    color: rgba(255, 255, 255, 0.76);
    font-size: 15px;
    line-height: 1.9;
    word-break: break-word;
}

.policy-page__content :deep(h2) {
    margin: 28px 0 10px;
    color: #fff;
    font-size: 19px;
    line-height: 1.45;
}

.policy-page__content :deep(h2:first-child) {
    margin-top: 0;
}

.policy-page__content :deep(p) {
    margin: 0 0 12px;
}

.policy-page__content :deep(a) {
    color: #4debff;
}

@media (max-width: 768px) {
    .policy-page {
        padding: 18px;
    }

    .policy-page__panel {
        border-radius: 14px;
    }
}
</style>
