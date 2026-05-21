<template>
    <footer class="layout-footer">
        <div class="text-center p-2 text-xs text-tx-secondary max-w-[900px] mx-auto">
            <a
                class="mx-1 hover:underline"
                :href="item.value || undefined"
                target="_blank"
                v-for="item in copyrightConfig"
                :key="item.key"
            >
                {{ item.key }}
            </a>
        </div>
    </footer>
</template>

<script setup lang="ts">
import useAppStore from '@/stores/modules/app'

const appStore = useAppStore()
const defaultCopyright = [{ key: '贵州猿创科技有限责任公司', value: '' }]
const copyrightConfig = computed(() =>
    appStore.config.copyright_config?.length ? appStore.config.copyright_config : defaultCopyright
)
</script>
