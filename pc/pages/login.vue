<template>
    <PcLoginModal
        v-model="visible"
        initial-view="login"
        page-mode
        @login-success="handleLoginSuccess"
    />
</template>

<script lang="ts" setup>
import { ref, watch } from 'vue'
import { usePcLoginGate } from '@/composables/usePcLoginGate'

definePageMeta({ layout: 'blank' })

const router = useRouter()
const visible = ref(true)
const { handlePcLoginSuccess } = usePcLoginGate()

const handleLoginSuccess = async () => {
    await handlePcLoginSuccess()
}

watch(visible, (value) => {
    if (!value) router.push('/ai')
})
</script>
