<template>
    <header class="layout-header text-white bg-primary">
        <div class="header-contain">
            <Logo class="header-logo" />
            <Navbar class="header-navbar" />
            <User class="header-user" />
        </div>
    </header>
</template>
<script lang="ts" setup>
import User from './user.vue'
import Logo from './logo.vue'
import Navbar from './navbar.vue'
</script>

<style lang="scss" scoped>
.layout-header {
    min-height: var(--header-height);
    border-bottom: 1px solid var(--el-border-color-extra-light);
    position: sticky;
    top: 0;
    width: 100%;
    z-index: 1999;
    .header-contain {
        min-height: var(--header-height);
        display: flex;
        align-items: center;
        gap: 16px;
        width: min(var(--pc-shell-max), calc(100vw - var(--pc-page-gutter-total)));
        min-width: 0;
        margin: 0 auto;
        .navbar {
            --el-menu-item-font-size: var(--el-font-size-large);
            --el-menu-bg-color: var(--el-color-primary);
            --el-menu-active-color: var(--color-white);
            --el-menu-text-color: var(--color-white);
            --el-menu-item-hover-fill: var(--el-color-primary);
            --el-menu-hover-text-color: var(--color-white);
            --el-menu-hover-bg-color: var(--el-color-primary);
        }
    }
}

.header-logo,
.header-user {
    flex: 0 0 auto;
}

.header-navbar {
    flex: 1 1 560px;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: none;
}

.header-navbar::-webkit-scrollbar {
    display: none;
}

@media (max-width: 1024px) {
    .layout-header {
        padding: 8px 0;
    }

    .layout-header .header-contain {
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 8px 14px;
    }

    .header-logo {
        margin-right: auto;
    }

    .header-navbar {
        order: 3;
        flex-basis: 100%;
    }
}
</style>
