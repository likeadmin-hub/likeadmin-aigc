<template>
    <div class="workspace-page">
        <div class="workspace-page__background" :style="backgroundStyle"></div>
        <div class="workspace-page__noise"></div>
        <div class="workspace-page__stars workspace-page__stars--near"></div>
        <div class="workspace-page__stars workspace-page__stars--far"></div>

        <AiWorkspaceChrome
            :active-sidebar="activeSidebar"
            :remaining-credits="remainingCredits"
            :membership-enabled="membershipEnabled"
            :active-popover="activePopover"
            :popover-content="chromePopoverContent"
            @toggle-popover="togglePopover"
            @increment-credits="refreshCredits"
            @toggle-membership="refreshCredits"
            @go-home="goHome"
            @navigate="handleSidebar"
        />

        <main class="workspace-main">
            <SmartClipCreator />
        </main>
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { usePcCredits } from '~/composables/usePcCredits'
import { buildSidebarRouteLocation } from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import SmartClipCreator from '@/apps/smart_clip/components/SmartClipCreator.vue'

definePageMeta({ layout: 'blank' })

type PopoverKey = '' | 'share' | 'api' | 'notice'

const router = useRouter()
const route = useRoute()
const { ensurePcLogin } = usePcLoginGate()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()
const activeSidebar = ref<SidebarKey>('tools')
const activePopover = ref<PopoverKey>('')
const backgroundStyle = computed(() => ({
    backgroundImage: 'linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%)'
}))

const chromePopoverContent = computed(() => ({
    share: {
        title: '邀请好友',
        text: '分享 AI 视频剪辑给团队成员，双方各得 10 条创作额度。'
    },
    api: {
        title: 'API 配额',
        text: `当前可用额度 ${remainingCredits.value} 次，可用于工具调用、视频剪辑与批量创作。`
    },
    notice: {
        title: '消息中心',
        text: 'AI视频剪辑已在工作台内打开，可以从工具页继续切换其他能力。',
        compact: true
    }
}))
const togglePopover = (key: Exclude<PopoverKey, ''>) => {
    activePopover.value = activePopover.value === key ? '' : key
}

const goHome = () => router.push('/')

const handleSidebar = (key: SidebarKey) => {
    if (key === activeSidebar.value) {
        activePopover.value = 'notice'
        return
    }

    if ((key === 'create' || key === 'assets') && !ensurePcLogin({ redirect: buildSidebarRouteLocation(key).path || route.fullPath })) return
    router.push(buildSidebarRouteLocation(key))
}

onBeforeUnmount(() => {
    if (typeof document === 'undefined') return
    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
})
</script>

<style lang="scss" scoped>
:global(html) {
    overflow: hidden;
}

:global(body) {
    overflow: hidden;
}

.workspace-page {
    --workspace-header-height: 56px;
    --workspace-rail-left: 16px;
    --workspace-rail-width: 76px;
    --workspace-rail-gap: clamp(12px, 1.25vw, 24px);
    --workspace-content-left: calc(var(--workspace-rail-left) + var(--workspace-rail-width) + var(--workspace-rail-gap));
    --workspace-content-right: clamp(16px, 2.2vw, 40px);
    position: relative;
    height: 100vh;
    min-width: 810px;
    padding: 0;
    background: #050505;
    color: #fff;
    overflow: hidden;
    box-sizing: border-box;
}

.workspace-page__background,
.workspace-page__noise,
.workspace-page__stars {
    position: fixed;
    inset: 0;
    pointer-events: none;
    will-change: opacity;
}

.workspace-page__background {
    background-position: center top;
    background-repeat: no-repeat;
    background-size: cover;
}

.workspace-page__noise {
    background-image:
        radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.65) 0 1px, transparent 1.8px),
        radial-gradient(circle at 12% 54%, rgba(255, 255, 255, 0.4) 0 1px, transparent 1.8px),
        radial-gradient(circle at 18% 32%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.6px),
        radial-gradient(circle at 26% 12%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.6px),
        radial-gradient(circle at 34% 58%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.8px),
        radial-gradient(circle at 42% 18%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.5px),
        radial-gradient(circle at 52% 10%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.5px),
        radial-gradient(circle at 61% 44%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.5px),
        radial-gradient(circle at 72% 20%, rgba(255, 255, 255, 0.48) 0 1px, transparent 1.7px),
        radial-gradient(circle at 84% 38%, rgba(255, 255, 255, 0.42) 0 1px, transparent 1.7px),
        radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.52) 0 1px, transparent 1.7px),
        radial-gradient(circle at 96% 52%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.8px);
    opacity: 0.24;
}

.workspace-page__stars {
    opacity: 0.95;
    mix-blend-mode: screen;
}

.workspace-page__stars--near {
    background-image:
        radial-gradient(circle at 10% 22%, rgba(255, 255, 255, 0.95) 0 1.4px, transparent 2.2px),
        radial-gradient(circle at 21% 68%, rgba(255, 255, 255, 0.92) 0 1.2px, transparent 2px),
        radial-gradient(circle at 36% 36%, rgba(255, 255, 255, 0.98) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 48% 14%, rgba(255, 255, 255, 0.9) 0 1.3px, transparent 2px),
        radial-gradient(circle at 57% 60%, rgba(255, 255, 255, 0.9) 0 1.1px, transparent 1.8px),
        radial-gradient(circle at 69% 28%, rgba(255, 255, 255, 0.96) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 82% 58%, rgba(255, 255, 255, 0.94) 0 1.25px, transparent 2px),
        radial-gradient(circle at 92% 20%, rgba(255, 255, 255, 0.9) 0 1.35px, transparent 2px);
    animation: starTwinkle 4.8s ease-in-out infinite alternate;
}

.workspace-page__stars--far {
    background-image:
        radial-gradient(circle at 14% 10%, rgba(160, 203, 255, 0.8) 0 1px, transparent 1.8px),
        radial-gradient(circle at 30% 48%, rgba(255, 255, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 44% 72%, rgba(178, 193, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 60% 8%, rgba(255, 255, 255, 0.68) 0 1px, transparent 1.6px),
        radial-gradient(circle at 78% 42%, rgba(181, 220, 255, 0.75) 0 0.95px, transparent 1.6px),
        radial-gradient(circle at 88% 74%, rgba(255, 255, 255, 0.7) 0 1px, transparent 1.6px);
    animation: starTwinkle 6.2s ease-in-out infinite alternate-reverse;
}

.workspace-main {
    position: relative;
    z-index: 1;
    height: 100%;
    min-width: 0;
    padding: var(--workspace-header-height) var(--workspace-content-right) 24px var(--workspace-content-left);
    overflow-y: auto;
    overflow-x: hidden;
    box-sizing: border-box;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.workspace-main::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.workspace-main::-webkit-scrollbar-track {
    background: transparent;
}

.workspace-main::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.workspace-main::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

@keyframes starTwinkle {
    0% {
        opacity: 0.52;
    }

    100% {
        opacity: 1;
    }
}

@media (max-width: 1200px) {
    :global(html),
    :global(body) {
        overflow: auto;
    }

    .workspace-page {
        height: auto;
        min-width: 0;
        padding-bottom: 32px;
    }

    .workspace-main {
        height: auto;
        min-width: 0;
        padding: 48px 16px 32px 96px;
        overflow: visible;
    }
}

@media (max-width: 820px) {
    .workspace-page {
        padding-inline: 16px;
    }
}
</style>
