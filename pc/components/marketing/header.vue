<template>
    <div class="site-marketing-top">
        <section class="top-bar">
            <div class="top-bar__track">
                <div class="top-bar__marquee">
                    <div class="top-bar__group" v-for="group in marqueeRepeats" :key="group">
                        <span
                            v-for="(item, index) in marqueeItems"
                            :key="`${group}-${index}`"
                            :class="[
                                'top-bar__item',
                                {
                                    'is-highlight': item.type === 'highlight',
                                    'is-divider': item.type === 'divider'
                                }
                            ]"
                        >
                            {{ item.text }}
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <header class="site-header">
            <div class="shell site-header__inner">
                <NuxtLink class="site-brand site-brand--header" to="/">
                    <img class="site-brand__image site-brand__image--header" :src="headerLogo" alt="A. PART" />
                </NuxtLink>

                <nav class="site-nav">
                    <template v-for="item in navItems" :key="item.label">
                        <NuxtLink
                            v-if="item.to"
                            :class="['site-nav__item', { 'is-active': item.active }]"
                            :to="item.to"
                        >
                            {{ item.label }}
                        </NuxtLink>
                        <a
                            v-else
                            :class="['site-nav__item', { 'is-active': item.active }]"
                            :href="item.href"
                            :target="item.target || '_self'"
                            :rel="item.target === '_blank' ? 'noopener noreferrer' : undefined"
                        >
                            {{ item.label }}
                        </a>
                    </template>
                </nav>

                <div class="site-actions">
                    <label class="search-box">
                        <el-icon class="search-box__icon"><Search /></el-icon>
                        <input type="text" placeholder="搜索灵感、技能或课程" />
                    </label>
                    <div
                        v-if="userStore.isLogin"
                        class="avatar-menu"
                    >
                        <button
                            class="avatar-button"
                            type="button"
                            aria-label="进入个人中心"
                            aria-haspopup="true"
                            @click.stop="goSettings"
                        >
                            <img class="site-actions__avatar" :src="avatarUrl" alt="avatar" />
                        </button>
                        <div class="avatar-dropdown" role="menu" @click.stop>
                            <div class="avatar-dropdown__bridge" aria-hidden="true"></div>
                            <div class="avatar-dropdown__section avatar-dropdown__section--member">
                                <span class="avatar-dropdown__label">当前会员</span>
                                <span class="avatar-dropdown__value">{{
                                    currentPlanTitle
                                }}</span>
                                <button
                                    v-if="canUpgrade"
                                    type="button"
                                    class="avatar-dropdown__upgrade"
                                    role="menuitem"
                                    @click="goUpgrade"
                                >
                                    升级
                                </button>
                            </div>
                            <div class="avatar-dropdown__divider"></div>
                            <button
                                type="button"
                                class="avatar-dropdown__link"
                                role="menuitem"
                                @click="goSettings"
                            >
                                个人中心
                            </button>
                            <div class="avatar-dropdown__divider"></div>
                            <button
                                type="button"
                                class="avatar-dropdown__link avatar-dropdown__link--danger"
                                role="menuitem"
                                @click="handleLogout"
                            >
                                退出登录
                            </button>
                        </div>
                    </div>
                    <button v-else class="login-button" type="button" @click="handleAccountClick">
                        登录 / 注册
                    </button>
                </div>
            </div>
        </header>
    </div>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import { Search } from '@element-plus/icons-vue'
import headerLogo from '@/assets/images/logo1.png'
import defaultAvatarUrl from '@/assets/images/default-avatar.svg'
import { logout } from '~~/api/account'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { usePcMembership } from '@/composables/usePcMembership'
import { useUserStore } from '@/stores/user'
import feedback from '~~/utils/feedback'
import { normalizeFileUrl } from '@/utils/file-url'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const { openPcLoginModal } = usePcLoginGate()
const {
    currentPlanTitle,
    canUpgrade
} = usePcMembership()

const defaultAvatar = defaultAvatarUrl
const marqueeRepeats = 6
const marqueeItems = [
    { text: '拥有技能、课程与 AI 应用的完整访问权限', type: 'normal' },
    { text: '会员仅需 ¥15 / 月', type: 'highlight' },
    { text: '/', type: 'divider' },
    { text: '拥有技能、课程与 AI 应用的完整访问权限', type: 'normal' },
    { text: '会员仅需 ¥15 / 月', type: 'highlight' },
    { text: '/', type: 'divider' },
    { text: '拥有技能、课程与 AI 应用的完整访问权限', type: 'normal' },
    { text: '会员仅需 ¥15 / 月', type: 'highlight' },
    { text: '/', type: 'divider' },
    { text: '拥有技能、课程与 AI 应用的完整访问权限', type: 'normal' },
    { text: '会员仅需 ¥15 / 月', type: 'highlight' },
    { text: '/', type: 'divider' }
]

const navItems = computed(() => [
    {
        label: '技能广场',
        to: '/skills',
        active: route.path.startsWith('/skills')
    },
    {
        label: '学院中心',
        to: '/academy',
        active: route.path.startsWith('/academy')
    },
    {
        label: 'AI 应用',
        href: '/ai',
        target: '_blank',
        active: route.path.startsWith('/ai')
    },
    {
        label: 'OpenClaw',
        href: '#',
        active: false
    },
    {
        label: '资讯',
        to: '/news',
        active: route.path.startsWith('/news')
    },
    {
        label: 'API',
        href: '#',
        active: false
    }
])

const avatarUrl = computed(
    () =>
        normalizeFileUrl(
            userStore.userInfo?.avatar,
            userStore.avatarVersion
        ) || defaultAvatar
)

const handleAccountClick = () => {
    if (userStore.isLogin) {
        router.push('/user/info')
        return
    }

    openPcLoginModal({ redirect: route.fullPath })
}

const goSettings = () => {
    router.push('/user/info')
}

const goUpgrade = () => {
    router.push({ path: '/', hash: '#pricing-section' })
}

const handleLogout = async () => {
    try {
        await feedback.confirm('确定退出登录吗？')
        await logout()
        userStore.logout()
        await router.push('/')
    } catch {
        /* 用户取消 */
    }
}

</script>

<style lang="scss" scoped>
.site-marketing-top {
    position: relative;
    z-index: 50;
    max-width: 100vw;
    overflow-x: clip;
}

.shell {
    width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto;
}

.top-bar {
    height: 50px;
    overflow: hidden;
    max-width: 100vw;
    contain: paint;
    background: #ececec;
    color: #666;
    font-size: 12px;
    border-bottom: 1px solid rgba(34, 34, 34, 0.04);

    &__track {
        position: relative;
        display: flex;
        align-items: center;
        height: 100%;
        overflow: hidden;
        contain: layout paint;
        white-space: nowrap;
    }

    &__marquee {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        display: flex;
        align-items: center;
        height: 100%;
        width: 100%;
        min-width: 0;
        max-width: 100%;
        overflow: hidden;
        will-change: transform;
        animation: top-bar-marquee 18s linear infinite;
    }

    &__group {
        display: flex;
        align-items: center;
        flex-shrink: 0;
        min-width: 100%;
    }

    &__item {
        display: inline-flex;
        align-items: center;
        flex-shrink: 0;
        margin-right: 18px;
        line-height: 1;
        color: #8b8b8b;

        &.is-highlight {
            color: #222;
            font-weight: 700;
        }

        &.is-divider {
            color: #b7b7b7;
        }
    }
}

@keyframes top-bar-marquee {
    0% {
        transform: translate3d(0, 0, 0);
    }
    100% {
        transform: translate3d(-16.6666667%, 0, 0);
    }
}

.site-brand {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;

    &__image {
        display: block;
        width: 78px;
        height: 20px;
        object-fit: contain;
    }
}

.site-header {
    min-height: 62px;
    background: rgba(248, 248, 248, 0.92);
    border-bottom: 1px solid rgba(34, 34, 34, 0.08);
    backdrop-filter: blur(20px);

    &__inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 62px;
        gap: 22px;
    }
}

.site-nav {
    display: flex;
    align-items: center;
    gap: 40px;
    margin-left: 60px;
    margin-right: auto;

    &__item {
        position: relative;
        color: #222;
        font-size: 14px;
        line-height: 1;
        text-decoration: none;

        &.is-active::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -21px;
            height: 2px;
            border-radius: 999px;
            background: #ff7a00;
        }
    }
}

.site-actions {
    display: flex;
    align-items: center;
    gap: 20px;

    &__avatar {
        width: 46px;
        height: 46px;
        border-radius: 50%;
        object-fit: cover;
        background: #222;
    }
}

.avatar-menu {
    position: relative;
    z-index: 20;

    &:hover .avatar-dropdown {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
        pointer-events: auto;
    }
}

.avatar-button {
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}

.avatar-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 10px);
    min-width: 240px;
    padding: 12px 0;
    border-radius: 12px;
    background: #1a1a1a;
    color: #f5f5f5;
    opacity: 0;
    visibility: hidden;
    transform: translateY(-6px);
    transition:
        opacity 0.18s ease,
        transform 0.18s ease,
        visibility 0.18s;
    pointer-events: none;

    &__bridge {
        position: absolute;
        left: 0;
        right: 0;
        bottom: 100%;
        height: 14px;
    }

    &__section {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px 10px;
        padding: 6px 16px 10px;
    }

    &__section--member {
        align-items: baseline;
    }

    &__label {
        width: 100%;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #8a8a8a;
    }

    &__value {
        flex: 1;
        min-width: 0;
        font-size: 14px;
        font-weight: 600;
        color: #fff;
    }

    &__upgrade {
        flex-shrink: 0;
        padding: 4px 12px;
        border: 1px solid rgba(255, 255, 255, 0.35);
        border-radius: 999px;
        background: #fff;
        color: #111;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        transition:
            background 0.15s ease,
            color 0.15s ease;

        &:hover {
            background: #e8e8e8;
        }
    }

    &__divider {
        height: 1px;
        margin: 6px 0;
        background: rgba(255, 255, 255, 0.08);
    }

    &__link {
        display: block;
        width: 100%;
        padding: 10px 16px;
        border: 0;
        background: transparent;
        text-align: left;
        font-size: 14px;
        font-weight: 500;
        color: #f0f0f0;
        cursor: pointer;
        transition: background 0.12s ease;

        &:hover {
            background: rgba(255, 255, 255, 0.06);
        }

        &--danger {
            color: #c9c9c9;

            &:hover {
                color: #fff;
                background: rgba(255, 80, 80, 0.15);
            }
        }
    }
}

.search-box {
    width: 321px;
    height: 38px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 0 14px;
    border-radius: 12px;
    background: #ededed;

    &__icon {
        color: #a1a1a1;
        font-size: 16px;
    }

    input {
        flex: 1;
        border: 0;
        outline: none;
        background: transparent;
        color: #222;
        font-size: 14px;
    }
}

.login-button {
    min-width: 136px;
    height: 38px;
    padding: 0 18px;
    border: 0;
    border-radius: 12px;
    background: #222;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

@media (max-width: 1100px) {
    .top-bar__track {
        max-width: 100%;
        overflow: hidden;
    }

    .top-bar__marquee {
        position: absolute;
        flex: 0 1 100% !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow: hidden !important;
        animation: none;
    }

    .top-bar__group:not(:first-child) {
        display: none !important;
    }

    .top-bar__group:first-child {
        display: flex !important;
        flex: 0 1 100% !important;
        width: 100% !important;
        min-width: 0 !important;
        max-width: 100% !important;
        overflow: hidden !important;
    }

    .top-bar__item {
        min-width: 0 !important;
        overflow: hidden !important;
        text-overflow: ellipsis;
    }

    .top-bar__item:nth-child(n + 4) {
        display: none !important;
    }

    .site-header {
        padding: 10px 0;
    }

    .site-header__inner {
        align-items: flex-start;
        flex-wrap: wrap;
        gap: 10px 16px;
    }

    .site-nav {
        order: 3;
        width: 100%;
        min-width: 0;
        margin: 0;
        gap: 24px;
        overflow-x: auto;
        padding: 8px 0 2px;
        scrollbar-width: none;
    }

    .site-nav::-webkit-scrollbar {
        display: none;
    }

    .site-nav__item {
        flex: 0 0 auto;
    }

    .site-nav__item.is-active::after {
        bottom: -10px;
    }

    .site-actions {
        flex: 1 1 auto;
        justify-content: flex-end;
        min-width: 0;
        gap: 12px;
    }

    .search-box {
        width: clamp(180px, 38vw, 280px);
    }
}

@media (max-width: 820px) {
    .site-actions {
        order: 2;
        flex-basis: 100%;
        justify-content: flex-start;
    }

    .search-box {
        flex: 1 1 240px;
        width: auto;
    }
}
</style>
