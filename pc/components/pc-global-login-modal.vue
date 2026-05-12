<template>
    <MarketingLoginModal
        v-model="visible"
        @login-success="handleLoginSuccess"
    />
</template>

<script lang="ts" setup>
import { computed, watch } from 'vue'
import { useUserStore } from '@/stores/user'
import { usePcLoginGate } from '@/composables/usePcLoginGate'

const route = useRoute()
const userStore = useUserStore()
const { showLoginModal, openPcLoginModal, closePcLoginModal, handlePcLoginSuccess } = usePcLoginGate()

const visible = computed({
    get: () => showLoginModal.value,
    set: (value: boolean) => {
        if (value) {
            openPcLoginModal({
                redirect: typeof route.query.redirect === 'string' ? route.query.redirect : route.fullPath
            })
            return
        }
        closePcLoginModal()
    }
})

const syncRouteAuthLoginModal = () => {
    if (userStore.isLogin) return
    if (route.meta.auth) {
        openPcLoginModal({ redirect: route.fullPath })
        return
    }
    if (route.query.login) {
        openPcLoginModal({
            redirect: typeof route.query.redirect === 'string' ? route.query.redirect : route.fullPath
        })
    }
}

const handleLoginSuccess = async () => {
    await handlePcLoginSuccess()
}

watch(
    () => [route.fullPath, route.meta.auth, route.query.login, route.query.redirect, userStore.isLogin],
    syncRouteAuthLoginModal,
    { immediate: true }
)
</script>
