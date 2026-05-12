<template>
    <section class="layout-default">
        <LayoutHeader />
        <div class="main-contain">
            <LayoutMain class="flex-1 min-h-0 flex">
                <slot />
            </LayoutMain>
            <LayoutFooter />
        </div>
        <PcGlobalLoginModal />
        <Account />
    </section>
</template>
<script lang="ts" setup>
import LayoutHeader from './components/header/index.vue'
import LayoutMain from './components/main/index.vue'
import LayoutFooter from './components/footer/index.vue'
import Account from './components/account/index.vue'
import PcGlobalLoginModal from '@/components/pc-global-login-modal.vue'
</script>
<style lang="scss" scoped>
.layout-default {
    min-width: var(--pc-min-width, 768px);
}

.main-contain {
    min-height: calc(100vh - var(--header-height));
    @apply flex flex-col;
}
</style>
