<template>
    <button
        :class="['site-global-back-top', { 'is-hidden': !visible }]"
        type="button"
        aria-label="返回顶部"
        @click="scrollToTop"
    >
        ↑
    </button>
</template>

<script lang="ts" setup>
const visible = ref(false)

const syncVisibility = () => {
    if (typeof window === 'undefined') return
    visible.value = window.scrollY > 220
}

const scrollToTop = () => {
    if (typeof window === 'undefined') return
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

onMounted(() => {
    syncVisibility()
    window.addEventListener('scroll', syncVisibility, { passive: true })
})

onBeforeUnmount(() => {
    window.removeEventListener('scroll', syncVisibility)
})
</script>

<style lang="scss" scoped>
.site-global-back-top {
    position: fixed;
    left: 92px;
    bottom: 56px;
    z-index: 30;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 60px;
    height: 60px;
    border: 0;
    border-radius: 12px;
    background: #222;
    color: #fff;
    font-size: 28px;
    line-height: 1;
    box-shadow: 0 10px 24px rgba(34, 34, 34, 0.18);
    cursor: pointer;
    transition: opacity 0.2s ease;

    &.is-hidden {
        opacity: 0;
        pointer-events: none;
    }
}
</style>
