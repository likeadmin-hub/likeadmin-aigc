<template>
    <PcLoginModal
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
const lastPublicRoute = useState<string>('pc-login-modal-last-public-route', () => '/')
const { showLoginModal, openPcLoginModal, closePcLoginModal, handlePcLoginSuccess } = usePcLoginGate()

const getRouteWithoutLoginQuery = () => {
    const query = { ...route.query }
    delete query.login
    delete query.redirect
    const search = new URLSearchParams()
    Object.entries(query).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            value.forEach((item) => {
                if (item != null) search.append(key, String(item))
            })
            return
        }
        if (value != null) search.set(key, String(value))
    })
    const queryText = search.toString()
    return `${route.path}${queryText ? `?${queryText}` : ''}${route.hash || ''}`
}

const visible = computed({
    get: () => showLoginModal.value,
    set: (value: boolean) => {
        if (value) {
            openPcLoginModal({
                redirect: typeof route.query.redirect === 'string' ? route.query.redirect : route.fullPath,
                returnTo: getRouteWithoutLoginQuery()
            })
            return
        }
        void closePcLoginModal()
    }
})

const syncRouteAuthLoginModal = () => {
    if (userStore.isLogin) return
    if (route.query.login) {
        openPcLoginModal({
            redirect: typeof route.query.redirect === 'string' ? route.query.redirect : route.fullPath,
            returnTo: getRouteWithoutLoginQuery()
        })
    }
}

const handleLoginSuccess = async () => {
    await handlePcLoginSuccess()
}

watch(
    () => [route.fullPath, route.meta.auth, route.query.login, route.query.redirect, userStore.isLogin],
    () => {
        if (!route.query.login) {
            lastPublicRoute.value = route.fullPath || '/'
        }
        syncRouteAuthLoginModal()
    },
    { immediate: true }
)
</script>
