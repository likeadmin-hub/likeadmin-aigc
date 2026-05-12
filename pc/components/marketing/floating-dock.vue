<template>
    <div class="site-floating-dock-layer">
        <div class="floating-dock">
            <div :class="['floating-dock__inner', { 'is-hidden': isDockHidden }]">
                <button class="floating-dock__brand" type="button" :aria-label="props.brandLabel" @click="$emit('brand-click')">
                    <img :src="dockBrandIcon" alt="" />
                </button>

                <div v-if="props.navItems.length" class="floating-dock__tabs">
                    <button
                        v-for="item in props.navItems"
                        :key="item.id"
                        :class="['floating-dock__tab', { 'is-active': props.activeNavId === item.id }]"
                        type="button"
                        @click="$emit('nav-click', item.id)"
                    >
                        {{ item.label }}
                    </button>
                </div>

                <button
                    v-if="props.secondaryToolLabel"
                    :class="[
                        'floating-dock__tool',
                        'floating-dock__tool--secondary',
                        `floating-dock__tool--${props.secondaryToolTheme}`,
                        { 'is-iconless': !props.secondaryShowToolIcon }
                    ]"
                    type="button"
                    @click="$emit('secondary-tool-click')"
                >
                    <img v-if="props.secondaryShowToolIcon" :src="dockToolIcon" alt="" />
                    <span>{{ props.secondaryToolLabel }}</span>
                </button>

                <button
                    :class="['floating-dock__tool', { 'is-iconless': !props.showToolIcon }]"
                    type="button"
                    @click="$emit('tool-click')"
                >
                    <img
                        v-if="props.showToolIcon"
                        :class="['floating-dock__tool-icon', { 'is-light': props.toolIconTone === 'light' }]"
                        :src="dockToolIcon"
                        alt=""
                    />
                    <span>{{ props.toolLabel }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import dockBrandIcon from '@/assets/images/A..svg'
import dockToolIcon from '@/assets/images/Download-three.svg'

type FloatingDockItem = {
    id: string
    label: string
}

defineEmits<{
    (event: 'brand-click'): void
    (event: 'nav-click', id: string): void
    (event: 'secondary-tool-click'): void
    (event: 'tool-click'): void
}>()

const props = withDefaults(
    defineProps<{
        activeNavId?: string
        autoHideDelay?: number
        brandLabel?: string
        dockHidden?: boolean
        enableAutoHide?: boolean
        navItems?: FloatingDockItem[]
        secondaryShowToolIcon?: boolean
        secondaryToolLabel?: string
        secondaryToolTheme?: 'light' | 'accent'
        showToolIcon?: boolean
        toolIconTone?: 'default' | 'light'
        toolLabel: string
    }>(),
    {
        activeNavId: '',
        autoHideDelay: 2000,
        brandLabel: '首页',
        dockHidden: false,
        enableAutoHide: true,
        navItems: () => [],
        secondaryShowToolIcon: false,
        secondaryToolLabel: '',
        secondaryToolTheme: 'light',
        showToolIcon: true,
        toolIconTone: 'default'
    }
)

const internalDockHidden = ref(false)
let dockTimer: ReturnType<typeof setTimeout> | null = null

const isDockHidden = computed(() => props.dockHidden || internalDockHidden.value)

const syncDockVisibility = () => {
    if (!props.enableAutoHide || typeof window === 'undefined') return

    internalDockHidden.value = false

    if (dockTimer) {
        clearTimeout(dockTimer)
    }

    dockTimer = setTimeout(() => {
        internalDockHidden.value = true
    }, props.autoHideDelay)
}

onMounted(() => {
    if (!props.enableAutoHide || typeof window === 'undefined') return

    syncDockVisibility()
    window.addEventListener('scroll', syncDockVisibility, { passive: true })
    window.addEventListener('wheel', syncDockVisibility, { passive: true })
})

onBeforeUnmount(() => {
    if (dockTimer) {
        clearTimeout(dockTimer)
    }

    if (typeof window === 'undefined') return

    window.removeEventListener('scroll', syncDockVisibility)
    window.removeEventListener('wheel', syncDockVisibility)
})
</script>

<style lang="scss" scoped>
.site-floating-dock-layer {
    position: relative;
}

.floating-dock__brand,
.floating-dock__tab,
.floating-dock__tool {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.floating-dock {
    position: fixed;
    left: 50%;
    bottom: 52px;
    z-index: 30;
    transform: translateX(-50%);

    &__inner {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 6px;
        border-radius: 12px;
        background: rgba(34, 34, 34, 0.2);
        box-shadow: 0 12px 32px rgba(34, 34, 34, 0.16);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        transition:
            transform 0.32s ease,
            opacity 0.32s ease;

        &.is-hidden {
            opacity: 0;
            transform: translateY(28px);
            pointer-events: none;
        }
    }

    &__brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 60px;
        height: 60px;
        padding: 0;
        border-radius: 10px;
        background: #111;

        img {
            width: 30px;
            height: 30px;
            object-fit: contain;
        }
    }

    &__tabs {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 4px;
        border-radius: 10px;
        background: #222;
    }

    &__tab {
        min-width: 92px;
        height: 48px;
        padding: 0 18px;
        border-radius: 8px;
        background: transparent;
        color: rgba(255, 255, 255, 0.76);
        font-size: 14px;
        line-height: 14px;
        white-space: nowrap;

        &.is-active {
            background: #f2f2f2;
            color: #222;
            font-weight: 600;
        }
    }

    &__tool {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        height: 60px;
        padding: 0 24px;
        border-radius: 10px;
        background: #ff6b00;
        color: #fff;
        font-size: 16px;
        font-weight: 600;
        white-space: nowrap;

        img {
            width: 24px;
            height: 24px;
            object-fit: contain;
        }

        &.is-iconless {
            padding: 0 28px;
        }
    }

    &__tool-icon {
        &.is-light {
            filter: brightness(0) invert(1);
        }
    }

    &__tool--secondary {
        &.floating-dock__tool--light {
            background: #fff;
            color: #222;
        }

        &.floating-dock__tool--accent {
            background: #ff6b00;
            color: #fff;
        }
    }
}
</style>
