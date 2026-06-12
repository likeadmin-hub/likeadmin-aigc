<template>
    <aside class="app-sidebar">
        <NuxtLink class="sidebar-logo" to="/" aria-label="返回首页">
            <img v-if="siteLogo" :src="siteLogo" :alt="siteName" />
            <span v-else>{{ siteNameInitial }}</span>
        </NuxtLink>

        <nav class="sidebar-nav" aria-label="AI 工作台导航">
            <button
                v-for="item in sidebarItems"
                :key="item.key"
                :class="['sidebar-item', { 'is-active': item.route === activeSidebar }]"
                type="button"
                @click="handleSidebarItemClick(item)"
            >
                <span class="sidebar-item__icon">
                    <img v-if="item.icon" :src="item.route === activeSidebar ? item.activeIcon : item.icon" :alt="item.label" />
                    <ElIcon v-else-if="item.iconComponent">
                        <component :is="item.iconComponent" />
                    </ElIcon>
                </span>
                <span>{{ item.label }}</span>
                <small v-if="item.badge">{{ item.badge }}</small>
            </button>
        </nav>

        <div class="sidebar-bottom">
            <button v-if="!isLogin" class="sidebar-login" type="button" @click="emit('action', 'user')">
                登录
            </button>

            <template v-else>
                <button class="sidebar-member-card" type="button" @click="emit('action', 'membership')">
                    <span class="sidebar-member-card__credits">
                        <span class="sidebar-credit-icon sidebar-credit-icon--member" :style="creditIconStyle" aria-hidden="true"></span>
                        <span :title="remainingCreditsText">{{ limitedRemainingCredits }}</span>
                    </span>
                    <strong>开会员</strong>
                </button>

                <button class="sidebar-avatar" type="button" aria-label="个人中心" title="个人中心" @click="emit('action', 'user')">
                    <img v-if="avatarUrl" :src="avatarUrl" alt="" />
                    <ElIcon v-else><UserFilled /></ElIcon>
                </button>

                <button class="sidebar-credit-analysis" type="button" aria-label="积分分析" title="积分分析" @click="emit('action', 'credits')">
                    <span class="sidebar-credit-icon sidebar-credit-icon--analysis" :style="creditIconStyle" aria-hidden="true"></span>
                </button>
            </template>

            <div class="sidebar-utility" aria-label="快捷入口">
                <button type="button" aria-label="小程序码" title="小程序码" @click="emit('action', 'mobile')">
                    <ElIcon><Iphone /></ElIcon>
                </button>
                <button v-if="isLogin" class="sidebar-utility__notice" type="button" aria-label="消息" title="消息" @click="emit('action', 'notice')">
                    <span v-if="hasUnreadNotice" class="sidebar-utility__dot" aria-hidden="true"></span>
                    <ElIcon><Bell /></ElIcon>
                </button>

                <div class="sidebar-more" @click.stop>
                    <button
                        ref="moreTriggerRef"
                        class="sidebar-more__trigger"
                        type="button"
                        :aria-expanded="showMoreMenu"
                        aria-label="更多"
                        title="更多"
                        @click="toggleMoreMenu"
                    >
                        <span></span>
                        <span></span>
                        <span></span>
                    </button>
                </div>
            </div>
        </div>
    </aside>

    <Teleport to="body">
        <div v-if="showMoreMenu" class="sidebar-more__menu" :style="moreMenuStyle" @click.stop>
            <div class="sidebar-more__group">
                <button class="sidebar-more__item" type="button">
                    <span class="sidebar-more__item-main">
                        <ElIcon><Document /></ElIcon>
                        <span>平台协议</span>
                    </span>
                    <ElIcon class="sidebar-more__arrow"><ArrowRight /></ElIcon>
                </button>
                <div class="sidebar-more__submenu">
                    <NuxtLink
                        v-for="item in policyAgreementOptions"
                        :key="item.type"
                        class="sidebar-more__subitem"
                        :to="`/policy/${item.type}`"
                        target="_blank"
                        @click="closeMoreMenu"
                    >
                        {{ item.label }}
                    </NuxtLink>
                </div>
            </div>
            <button class="sidebar-more__item" type="button" @click="handleLanguageClick">
                <span class="sidebar-more__item-main">
                    <ElIcon><ChromeFilled /></ElIcon>
                    <span>语言</span>
                </span>
                <ElIcon class="sidebar-more__arrow"><ArrowRight /></ElIcon>
            </button>
            <div class="sidebar-more__copyright">
                <a
                    v-for="item in copyrightConfig"
                    :key="item.key"
                    :href="item.value || undefined"
                    :target="item.value ? '_blank' : undefined"
                >
                    {{ item.key }}
                </a>
            </div>
        </div>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowRight, Bell, ChromeFilled, Document, Film, Iphone, UserFilled } from '@element-plus/icons-vue'
import { useAppStore } from '@/stores/app'
import { policyAgreementOptions } from '@/enums/appEnums'
import type { SidebarKey } from '~/utils/ai-sidebar'
import inspirationIcon from '@/assets/images/icon/linggan2.svg'
import inspirationIconActive from '@/assets/images/icon/linggan.svg'
import createIcon from '@/assets/images/icon/chuangzuo2.svg'
import createIconActive from '@/assets/images/icon/chuangzuo.svg'
import avatarIcon from '@/assets/images/icon/shuziren2.svg'
import avatarIconActive from '@/assets/images/icon/shuziren.svg'
import toolsIcon from '@/assets/images/icon/gongju2.svg'
import toolsIconActive from '@/assets/images/icon/gongju.svg'
import assetsIcon from '@/assets/images/icon/Folder-minus2.svg'
import assetsIconActive from '@/assets/images/icon/Folder-minus.svg'
import creditPointIcon from '@/assets/images/icon/credit-point.svg'

interface Props {
    activeSidebar: SidebarKey
    isLogin: boolean
    avatarUrl: string
    remainingCredits: number
    hasUnreadNotice: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
    (e: 'navigate', key: SidebarKey): void
    (e: 'action', key: 'membership' | 'user' | 'credits' | 'notice' | 'mobile' | 'language' | 'short_drama'): void
}>()
const appStore = useAppStore()
const showMoreMenu = ref(false)
const moreTriggerRef = ref<HTMLElement | null>(null)
const moreMenuStyle = ref<Record<string, string>>({})
const siteLogo = computed(() => appStore.getWebsiteConfig.pc_logo || '')
const siteName = computed(() => appStore.getWebsiteConfig.pc_title || appStore.getWebsiteConfig.shop_name || 'AI')
const siteNameInitial = computed(() => siteName.value.trim().slice(0, 1).toUpperCase() || 'A')
const defaultCopyright = [{ key: '贵州猿创科技有限责任公司', value: '' }]
const creditIconStyle = computed(() => ({
    '--sidebar-credit-icon': `url(${creditPointIcon})`
}))
const remainingCreditsText = computed(() => String(props.remainingCredits ?? 0))
const limitedRemainingCredits = computed(() => {
    const text = remainingCreditsText.value.trim()
    let digitCount = 0
    let output = ''

    for (const char of text) {
        if (/\d/.test(char)) {
            digitCount += 1
            if (digitCount > 4) return `${output.replace(/[.]$/, '')}...`
        }
        output += char
    }

    return output
})
const copyrightConfig = computed(() =>
    appStore.getCopyrightConfig.length ? appStore.getCopyrightConfig : defaultCopyright
)

const texts = {
    inspiration: '灵感',
    create: '创作',
    avatar: '数字人',
    tools: '工具',
    assets: '资产'
} as const

type SidebarActionKey = 'short_drama'
type SidebarItem = {
    key: string
    label: string
    route?: SidebarKey
    action?: SidebarActionKey
    icon?: string
    activeIcon?: string
    iconComponent?: any
    badge?: string
}

const sidebarItems: SidebarItem[] = [
    { key: 'inspiration', route: 'inspiration', label: texts.inspiration, icon: inspirationIcon, activeIcon: inspirationIconActive },
    { key: 'create', route: 'create', label: texts.create, icon: createIcon, activeIcon: createIconActive },
    { key: 'short_drama', action: 'short_drama', label: '短剧', iconComponent: Film },
    { key: 'avatar', route: 'avatar', label: texts.avatar, icon: avatarIcon, activeIcon: avatarIconActive },
    { key: 'tools', route: 'tools', label: texts.tools, icon: toolsIcon, activeIcon: toolsIconActive },
    { key: 'assets', route: 'assets', label: texts.assets, icon: assetsIcon, activeIcon: assetsIconActive }
]

const handleSidebarItemClick = (item: SidebarItem) => {
    if (item.route) {
        emit('navigate', item.route)
        return
    }
    if (item.action) emit('action', item.action)
}

const closeMoreMenu = () => {
    showMoreMenu.value = false
}

const updateMoreMenuPosition = () => {
    if (typeof window === 'undefined' || !moreTriggerRef.value) return

    const rect = moreTriggerRef.value.getBoundingClientRect()
    const menuWidth = 252
    const gap = 16
    const viewportMargin = 12
    const left = Math.min(rect.right + gap, window.innerWidth - menuWidth - viewportMargin)
    const bottom = Math.max(viewportMargin, window.innerHeight - rect.bottom)

    moreMenuStyle.value = {
        left: `${Math.max(viewportMargin, left)}px`,
        bottom: `${bottom}px`
    }
}

const toggleMoreMenu = async () => {
    showMoreMenu.value = !showMoreMenu.value
    if (showMoreMenu.value) {
        await nextTick()
        updateMoreMenuPosition()
    }
}

const handleLanguageClick = () => {
    emit('action', 'language')
    closeMoreMenu()
}

const handleViewportChange = () => {
    if (showMoreMenu.value) updateMoreMenuPosition()
}

onMounted(() => {
    window.addEventListener('click', closeMoreMenu)
    window.addEventListener('resize', handleViewportChange)
    window.addEventListener('scroll', handleViewportChange, true)
})

onBeforeUnmount(() => {
    window.removeEventListener('click', closeMoreMenu)
    window.removeEventListener('resize', handleViewportChange)
    window.removeEventListener('scroll', handleViewportChange, true)
})
</script>

<style lang="scss" scoped>
.app-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    z-index: 2147483645;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 74px;
    height: 100vh;
    padding: 12px 6px;
    border-right: 1px solid rgba(255, 255, 255, 0.06);
    background: #08090b;
    box-sizing: border-box;
}

.sidebar-logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 12px;
    color: #fff;
    text-decoration: none;
    background:
        radial-gradient(circle at 30% 20%, rgba(77, 235, 255, 0.38), transparent 42%),
        rgba(255, 255, 255, 0.04);
}

.sidebar-logo img {
    display: block;
    max-width: 28px;
    max-height: 28px;
    object-fit: contain;
}

.sidebar-logo span {
    font-size: 18px;
    font-weight: 800;
}

.sidebar-nav,
.sidebar-bottom,
.sidebar-utility {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
}

.sidebar-nav {
    width: 100%;
}

.sidebar-bottom {
    width: 100%;
    gap: 20px;
}

.sidebar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    width: 62px;
    min-height: 54px;
    padding: 6px 4px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: rgba(255, 255, 255, 0.72);
    font-size: 11px;
    line-height: 1;
    box-sizing: border-box;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.sidebar-item small {
    height: 14px;
    padding: 1px 4px 0;
    border-radius: 4px;
    color: #4debff;
    background: rgba(77, 235, 255, 0.1);
    font-size: 9px;
    line-height: 14px;
}

.sidebar-item__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    flex-shrink: 0;
}

.sidebar-item__icon img {
    display: block;
    width: 22px;
    height: 22px;
    object-fit: contain;
    object-position: center center;
}

.sidebar-item__icon .el-icon {
    color: currentColor;
    font-size: 20px;
}

.sidebar-item.is-active,
.sidebar-item:hover {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.sidebar-item.is-active {
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
}

.sidebar-login,
.sidebar-member-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 62px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    color: #fff;
    cursor: pointer;
    transition: all 0.2s ease;
}

.sidebar-login {
    height: 34px;
    padding: 0 8px;
    font-size: 14px;
    font-weight: 600;
}

.sidebar-member-card {
    gap: 6px;
    min-height: 64px;
    padding: 8px 5px;
}

.sidebar-login:hover,
.sidebar-member-card:hover {
    border-color: rgba(77, 235, 255, 0.62);
    background: rgba(77, 235, 255, 0.08);
}

.sidebar-member-card__credits {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #0de8ff;
    font-size: 12px;
    font-weight: 700;
}

.sidebar-credit-icon {
    display: block;
    flex-shrink: 0;
    background: currentColor;
    mask: var(--sidebar-credit-icon) center / contain no-repeat;
    -webkit-mask: var(--sidebar-credit-icon) center / contain no-repeat;
}

.sidebar-credit-icon--member {
    width: 13px;
    height: 13px;
}

.sidebar-member-card strong {
    color: #0de8ff;
    font-size: 10px;
    font-weight: 600;
    line-height: 1.1;
}

.sidebar-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 38px;
    height: 38px;
    border: 0;
    border-radius: 50%;
    padding: 0;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.72);
    overflow: hidden;
    cursor: pointer;
}

.sidebar-avatar img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sidebar-avatar .el-icon {
    font-size: 20px;
}

.sidebar-credit-analysis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: rgba(171, 184, 194, 0.9);
    cursor: pointer;
    transition: all 0.2s ease;
}

.sidebar-credit-icon--analysis {
    width: 18px;
    height: 18px;
}

.sidebar-credit-analysis:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.06);
}

.sidebar-utility {
    gap: 20px;
}

.sidebar-utility > button,
.sidebar-more__trigger {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 24px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: rgba(171, 184, 194, 0.9);
    font-size: 19px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.sidebar-utility > button:hover,
.sidebar-more__trigger:hover,
.sidebar-more__trigger[aria-expanded='true'] {
    color: #fff;
    background: rgba(255, 255, 255, 0.06);
}

.sidebar-utility__notice {
    overflow: visible;
}

.sidebar-utility__dot {
    position: absolute;
    top: 1px;
    right: 10px;
    width: 6px;
    height: 6px;
    border: 2px solid #08090b;
    border-radius: 50%;
    background: #ff3b30;
    box-sizing: content-box;
}

.sidebar-more {
    position: relative;
}

.sidebar-more__trigger {
    gap: 4px;
}

.sidebar-more__trigger span {
    display: block;
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background: currentColor;
}

.sidebar-more__menu {
    position: fixed;
    left: 90px;
    bottom: 12px;
    z-index: 2147483646;
    width: 252px;
    max-height: calc(100vh - 24px);
    padding: 12px 14px 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: rgba(25, 26, 29, 0.98);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.36);
    box-sizing: border-box;
    overflow: visible;
}

.sidebar-more__item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    width: 100%;
    min-height: 40px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #eef4fb;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
}

.sidebar-more__group {
    position: static;
}

.sidebar-more__group::after {
    position: absolute;
    left: 100%;
    top: 12px;
    width: 14px;
    height: 40px;
    content: '';
}

.sidebar-more__group:hover > .sidebar-more__item,
.sidebar-more__group:focus-within > .sidebar-more__item,
.sidebar-more__item:hover {
    background: rgba(255, 255, 255, 0.06);
    border-radius: 8px;
    color: #fff;
}

.sidebar-more__submenu {
    position: absolute;
    left: calc(100% + 14px);
    top: auto;
    bottom: 0;
    z-index: 2147483647;
    display: flex;
    flex-direction: column;
    width: 210px;
    max-height: calc(100vh - 48px);
    padding: 12px 0;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: rgba(25, 26, 29, 0.98);
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.36);
    box-sizing: border-box;
    overflow-y: auto;
    opacity: 0;
    pointer-events: none;
    transform: translateX(-4px);
    transition:
        opacity 0.16s ease,
        transform 0.16s ease;
}

.sidebar-more__group:hover .sidebar-more__submenu,
.sidebar-more__group:focus-within .sidebar-more__submenu {
    opacity: 1;
    pointer-events: auto;
    transform: translateX(0);
}

.sidebar-more__subitem {
    display: flex;
    align-items: center;
    min-height: 40px;
    padding: 0 18px;
    color: #eef4fb;
    font-size: 14px;
    line-height: 1.4;
    text-decoration: none;
    transition: color 0.2s ease;
}

.sidebar-more__subitem:hover {
    color: #fff;
}

.sidebar-more__item-main {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.sidebar-more__item-main .el-icon {
    font-size: 16px;
}

.sidebar-more__arrow {
    color: rgba(171, 184, 194, 0.72);
    font-size: 14px;
    transition: transform 0.2s ease;
}

.sidebar-more__copyright {
    display: flex;
    flex-wrap: wrap;
    gap: 5px 0;
    margin-top: 10px;
    color: rgba(183, 194, 204, 0.86);
    font-size: 12px;
    line-height: 1.65;
}

.sidebar-more__copyright a {
    color: inherit;
    text-decoration: none;
}

.sidebar-more__copyright a:hover {
    color: #fff;
}

.sidebar-more__copyright a:not(:last-child)::after {
    content: ' | ';
    white-space: pre;
}
</style>
