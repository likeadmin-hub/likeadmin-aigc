<template>
    <div class="user-center-page">
        <MarketingHeader />
        <main class="uc-main">
            <div class="uc-shell">
                <header class="uc-header">
                    <div class="uc-eyebrow">{{ breadcrumb }}</div>
                    <h1 class="uc-title">{{ pageTitle }}</h1>
                    <p v-if="pageDesc" class="uc-desc">{{ pageDesc }}</p>
                </header>

                <nav class="uc-tabs">
                    <NuxtLink
                        v-for="tab in tabs"
                        :key="tab.path"
                        :to="tab.path"
                        :class="[
                            'uc-tab',
                            { 'is-active': isActive(tab.path) }
                        ]"
                    >
                        {{ tab.label }}
                    </NuxtLink>
                </nav>

                <div class="uc-content">
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
<script lang="ts" setup>
const route = useRoute()

defineProps({
    pageTitle: {
        type: String,
        default: '您的个人资料'
    },
    pageDesc: {
        type: String,
        default: '请在此处填写更多关于您的信息。'
    },
    breadcrumb: {
        type: String,
        default: '个人中心'
    }
})

const tabs = [
    { path: '/user/info', label: '公开资料' },
    { path: '/user/collection', label: '我的收藏' },
    { path: '/account/security', label: '账号安全' }
]

const isActive = (path: string) => route.path === path
</script>
<style lang="scss" scoped>
.user-center-page {
    position: relative;
    min-width: 0;
    min-height: 100vh;
    background: #f8f8f8;
    color: #222;
    overflow-x: hidden;
    overflow-y: visible;
}

.uc-main {
    position: relative;
    z-index: 1;
    padding: 72px 0 128px;
    overflow: visible;
}

.uc-shell {
    width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto;
    overflow: visible;
}

.uc-header {
    margin-bottom: 32px;
}

.uc-eyebrow {
    color: #a1a1a1;
    font-size: 14px;
    line-height: 14px;
    letter-spacing: 0.02em;
    margin-bottom: 18px;
}

.uc-title {
    margin: 0;
    color: #222;
    font-size: 44px;
    line-height: 48px;
    font-weight: 700;
    letter-spacing: -0.02em;
}

.uc-desc {
    margin: 16px 0 0;
    max-width: 540px;
    color: #686868;
    font-size: 14px;
    line-height: 24px;
}

.uc-tabs {
    display: flex;
    align-items: center;
    gap: 40px;
    overflow-x: auto;
    border-bottom: 1px solid rgba(34, 34, 34, 0.1);
    scrollbar-width: none;
}

.uc-tabs::-webkit-scrollbar {
    display: none;
}

.uc-tab {
    position: relative;
    padding: 14px 0;
    font-size: 14px;
    font-weight: 500;
    color: #8b8b8b;
    text-decoration: none;
    transition: color 0.2s ease;

    &:hover {
        color: #222;
    }

    &.is-active {
        color: #222;
        font-weight: 600;

        &::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 2px;
            background: #222;
        }
    }
}

.uc-content {
    margin-top: 40px;
    overflow: visible;
}

@media (max-width: 820px) {
    .uc-main {
        padding-top: 48px;
    }

    .uc-title {
        font-size: 36px;
        line-height: 1.15;
    }

    .uc-tabs {
        gap: 24px;
    }

    .uc-tab {
        flex: 0 0 auto;
    }
}
</style>
