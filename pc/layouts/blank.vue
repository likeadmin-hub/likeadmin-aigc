<template>
    <section class="layout-blank">
        <slot />
        <template v-if="showMarketingChrome">
            <MarketingBackTop />
            <MarketingVideoPopup />
            <MarketingFooter />
        </template>
        <PcGlobalLoginModal />
        <Account />
    </section>
</template>
<script lang="ts" setup>
import Account from './components/account/index.vue'
import PcGlobalLoginModal from '@/components/pc-global-login-modal.vue'

const route = useRoute()

const showMarketingChrome = computed(
    () => route.path !== '/' && !route.path.startsWith('/ai') && !route.path.startsWith('/app')
)
</script>
