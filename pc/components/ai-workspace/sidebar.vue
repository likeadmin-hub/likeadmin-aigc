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
            <button class="sidebar-credit" type="button" @click="emit('action', 'membership')">
                <ElIcon><WalletFilled /></ElIcon>
                <strong>开通会员</strong>
            </button>

            <div class="sidebar-policy" @click.stop>
                <button
                    class="sidebar-policy__trigger"
                    type="button"
                    :aria-expanded="showPolicyMenu"
                    aria-label="平台协议"
                    title="平台协议"
                    @click="showPolicyMenu = !showPolicyMenu"
                >
                    <ElIcon><Document /></ElIcon>
                    <strong>协议</strong>
                </button>
                <div v-if="showPolicyMenu" class="sidebar-policy__menu">
                    <strong>平台协议</strong>
                    <NuxtLink
                        v-for="item in policyAgreementOptions"
                        :key="item.type"
                        :to="`/policy/${item.type}`"
                        target="_blank"
                        @click="showPolicyMenu = false"
                    >
                        <span>{{ item.label }}</span>
                        <ElIcon><ArrowRight /></ElIcon>
                    </NuxtLink>
                </div>
            </div>

            <div class="sidebar-utility" aria-label="快捷入口">
                <button type="button" aria-label="个人中心" title="个人中心" @click="emit('action', 'user')">
                    <ElIcon><UserFilled /></ElIcon>
                </button>
                <button type="button" aria-label="积分明细" title="积分明细" @click="emit('action', 'credits')">
                    <ElIcon><Coin /></ElIcon>
                </button>
                <button type="button" aria-label="API" title="API" @click="emit('action', 'api')">
                    <ElIcon><Connection /></ElIcon>
                </button>
                <button type="button" aria-label="消息" title="消息" @click="emit('action', 'notice')">
                    <ElIcon><Bell /></ElIcon>
                </button>
                <button type="button" aria-label="小程序码" title="小程序码" @click="emit('action', 'mobile')">
                    <ElIcon><Iphone /></ElIcon>
                </button>
                <button type="button" aria-label="语言" title="语言" @click="emit('action', 'language')">
                    <ElIcon><ChromeFilled /></ElIcon>
                </button>
            </div>
        </div>
    </aside>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowRight, Bell, ChromeFilled, Coin, Connection, Document, Film, Iphone, UserFilled, WalletFilled } from '@element-plus/icons-vue'
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

interface Props {
    activeSidebar: SidebarKey
}

defineProps<Props>()

const emit = defineEmits<{
    (e: 'navigate', key: SidebarKey): void
    (e: 'action', key: 'membership' | 'user' | 'credits' | 'api' | 'notice' | 'mobile' | 'language' | 'short_drama'): void
}>()
const appStore = useAppStore()
const showPolicyMenu = ref(false)
const siteLogo = computed(() => appStore.getWebsiteConfig.pc_logo || '')
const siteName = computed(() => appStore.getWebsiteConfig.pc_title || appStore.getWebsiteConfig.shop_name || 'AI')
const siteNameInitial = computed(() => siteName.value.trim().slice(0, 1).toUpperCase() || 'A')

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

const closePolicyMenu = () => {
    showPolicyMenu.value = false
}

onMounted(() => window.addEventListener('click', closePolicyMenu))

onBeforeUnmount(() => {
    window.removeEventListener('click', closePolicyMenu)
})
</script>

<style lang="scss" scoped>
.app-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    z-index: 14;
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
    gap: 10px;
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

.sidebar-credit {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    width: 58px;
    min-height: 54px;
    padding: 6px 4px;
    border: 1px solid rgba(255, 255, 255, 0.16);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.02);
    color: #fff;
    cursor: pointer;
}

.sidebar-credit .el-icon {
    color: #fff;
    font-size: 17px;
    line-height: 1;
}

.sidebar-credit strong {
    color: rgba(255, 255, 255, 0.9);
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.sidebar-credit:hover {
    border-color: rgba(77, 235, 255, 0.62);
    background: rgba(77, 235, 255, 0.08);
}

.sidebar-policy {
    position: relative;
}

.sidebar-policy__trigger {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 5px;
    width: 58px;
    min-height: 46px;
    padding: 6px 4px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.72);
    cursor: pointer;
}

.sidebar-policy__trigger .el-icon {
    font-size: 17px;
}

.sidebar-policy__trigger strong {
    font-size: 11px;
    font-weight: 500;
    line-height: 1.2;
}

.sidebar-policy__trigger:hover,
.sidebar-policy__trigger[aria-expanded='true'] {
    color: #fff;
    background: rgba(255, 255, 255, 0.08);
}

.sidebar-policy__menu {
    position: absolute;
    left: calc(100% + 12px);
    bottom: 0;
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 184px;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: #191a1d;
    box-shadow: 0 18px 50px rgba(0, 0, 0, 0.36);
}

.sidebar-policy__menu > strong {
    display: block;
    padding: 4px 8px 6px;
    color: rgba(255, 255, 255, 0.92);
    font-size: 13px;
    line-height: 1.4;
}

.sidebar-policy__menu a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    min-height: 36px;
    padding: 0 8px;
    border-radius: 8px;
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    text-decoration: none;
}

.sidebar-policy__menu a:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.07);
}

.sidebar-policy__menu a .el-icon {
    color: rgba(255, 255, 255, 0.38);
    font-size: 13px;
}

.sidebar-utility {
    gap: 6px;
}

.sidebar-utility button {
    width: 48px;
    height: 22px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: rgba(255, 255, 255, 0.5);
    font-size: 15px;
    line-height: 1;
    cursor: pointer;
}

.sidebar-utility button:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.06);
}
</style>
