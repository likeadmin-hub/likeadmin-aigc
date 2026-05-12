<template>
    <main class="layout-main pc-shell py-4">
        <div
            v-if="sidebar.length"
            class="layout-sidebar mr-4 bg-white rounded-[8px] overflow-hidden"
        >
            <Menu
                :menu="sidebar"
                :default-active="activeMenu"
                mode="vertical"
            />
        </div>
        <div
            :class="[
                'layout-page flex-1 min-w-0 rounded-[8px]',
                {
                    'bg-body': hasSidebar
                }
            ]"
        >
            <slot />
        </div>
    </main>
</template>
<script lang="ts" setup>
import Menu from '../menu/index.vue'
const route = useRoute()
const activeMenu = computed<string>(() => route.meta.activeMenu ?? route.path)
const { sidebar, hasSidebar } = useMenu()
</script>

<style lang="scss" scoped>
.layout-main {
    min-width: 0;
}

.layout-sidebar {
    flex: 0 0 auto;
}

@media (max-width: 900px) {
    .layout-main {
        flex-direction: column;
        gap: 16px;
    }

    .layout-sidebar {
        margin-right: 0;
        overflow-x: auto;
    }
}
</style>
