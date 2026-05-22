<template>
    <section class="layout-blank">
        <slot />
        <PcGlobalLoginModal />
        <Account />
    </section>
</template>
<script lang="ts" setup>
import Account from './components/account/index.vue'
import PcGlobalLoginModal from '@/components/pc-global-login-modal.vue'
</script>
