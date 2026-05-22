<template>
    <aside class="app-sidebar">
        <button
            v-for="item in sidebarItems"
            :key="item.key"
            :class="['sidebar-item', { 'is-active': activeSidebar === item.key }]"
            type="button"
            @click="emit('navigate', item.key)"
        >
            <span class="sidebar-item__icon">
                <img :src="activeSidebar === item.key ? item.activeIcon : item.icon" :alt="item.label" />
            </span>
            <span>{{ item.label }}</span>
        </button>
    </aside>
</template>

<script lang="ts" setup>
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
}>()

const texts = {
    inspiration: '灵感',
    create: '创作',
    avatar: '数字人',
    tools: 'OPC',
    assets: '资产'
} as const

const sidebarItems = [
    { key: 'inspiration' as const, label: texts.inspiration, icon: inspirationIcon, activeIcon: inspirationIconActive },
    { key: 'create' as const, label: texts.create, icon: createIcon, activeIcon: createIconActive },
    { key: 'avatar' as const, label: texts.avatar, icon: avatarIcon, activeIcon: avatarIconActive },
    { key: 'tools' as const, label: texts.tools, icon: toolsIcon, activeIcon: toolsIconActive },
    { key: 'assets' as const, label: texts.assets, icon: assetsIcon, activeIcon: assetsIconActive }
]
</script>

<style lang="scss" scoped>
.app-sidebar {
    position: fixed;
    left: 16px;
    top: 0;
    z-index: 14;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    width: 76px;
    height: 100vh;
    padding: 0 2px;
    box-sizing: border-box;
}

.sidebar-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    width: 72px;
    height: 72px;
    padding: 10px 8px;
    border: 0;
    border-radius: 18px;
    background: transparent;
    color: rgba(255, 255, 255, 0.86);
    font-size: 12px;
    line-height: 1;
    box-sizing: border-box;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.sidebar-item__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    flex-shrink: 0;
}

.sidebar-item__icon img {
    display: block;
    width: 24px;
    height: 24px;
    object-fit: contain;
    object-position: center center;
}

.sidebar-item.is-active,
.sidebar-item:hover {
    background: rgba(255, 255, 255, 0.06);
    color: #fff;
}
</style>
