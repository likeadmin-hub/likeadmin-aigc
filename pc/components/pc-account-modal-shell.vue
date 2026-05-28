<template>
    <Teleport to="body">
        <transition name="login-modal-fade">
            <div
                v-if="modelValue"
                :class="[
                    'site-login-modal',
                    { 'site-login-modal--compact': compact }
                ]"
                role="dialog"
                aria-modal="true"
                :aria-label="ariaLabel"
                @click="close"
            >
                <div class="site-login-modal__backdrop"></div>

                <div
                    :class="[
                        'site-login-modal__panel',
                        { 'site-login-modal__panel--compact': compact }
                    ]"
                    @click.stop
                >
                    <section v-if="!compact" class="site-login-modal__hero">
                        <span v-if="heroMediaUrl" class="site-login-modal__hero-bg" aria-hidden="true">
                            <video
                                v-if="heroMediaType === 'video'"
                                :src="heroMediaUrl"
                                :poster="heroPosterUrl || undefined"
                                autoplay
                                muted
                                loop
                                playsinline
                            ></video>
                            <img v-else :src="heroMediaUrl" alt="" />
                        </span>
                        <div class="site-login-modal__hero-top">
                            <span class="site-login-modal__hero-greeting">{{
                                heroGreeting
                            }}</span>
                        </div>

                        <div class="site-login-modal__hero-visual">
                            <span class="site-login-modal__hero-mark"
                                >A<span class="dot">.</span></span
                            >
                            <span
                                class="site-login-modal__hero-smiley"
                                aria-hidden="true"
                            >
                                <svg
                                    viewBox="0 0 64 64"
                                    width="68"
                                    height="68"
                                    fill="none"
                                >
                                    <circle
                                        cx="32"
                                        cy="32"
                                        r="30"
                                        fill="#c9f04a"
                                        stroke="#1f1f1f"
                                        stroke-width="3"
                                    />
                                    <circle
                                        cx="22"
                                        cy="26"
                                        r="3.4"
                                        fill="#1f1f1f"
                                    />
                                    <circle
                                        cx="42"
                                        cy="26"
                                        r="3.4"
                                        fill="#1f1f1f"
                                    />
                                    <path
                                        d="M20 38 Q32 50 44 38"
                                        stroke="#1f1f1f"
                                        stroke-width="3"
                                        stroke-linecap="round"
                                        fill="none"
                                    />
                                </svg>
                            </span>
                        </div>

                        <div class="site-login-modal__hero-foot">
                            <div class="site-login-modal__hero-copy">
                                <span class="site-login-modal__hero-eyebrow">{{
                                    heroEyebrow
                                }}</span>
                                <h2>{{ heroTitle }}</h2>
                                <p>{{ heroDescription }}</p>
                            </div>

                            <div
                                v-if="showHeroSwitch"
                                class="site-login-modal__hero-switch"
                            >
                                <span>{{ switchPrompt }}</span>
                                <button
                                    class="site-login-modal__hero-link"
                                    type="button"
                                    @click="emit('heroSwitch')"
                                >
                                    {{ switchActionText }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="site-login-modal__content">
                        <button
                            v-if="compact"
                            class="site-login-modal__compact-close"
                            type="button"
                            :aria-label="closeLabel"
                            @click.stop="close"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                width="18"
                                height="18"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                            >
                                <path d="M6 6l12 12M18 6L6 18" />
                            </svg>
                        </button>

                        <header class="site-login-modal__content-head">
                            <h3 class="site-login-modal__content-title">
                                {{ contentTitle }}
                            </h3>
                            <span
                                v-if="contentSubtitle"
                                class="site-login-modal__content-sub"
                                >{{ contentSubtitle }}</span
                            >
                        </header>

                        <slot />
                    </section>
                </div>

                <button
                    v-if="!compact"
                    class="site-login-modal__close"
                    type="button"
                    :aria-label="closeLabel"
                    @click.stop="close"
                >
                    <svg
                        viewBox="0 0 24 24"
                        width="18"
                        height="18"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                    >
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>
        </transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue'
import { useModalBodyScrollLock } from '@/composables/useModalBodyScrollLock'
import { useAppStore } from '@/stores/app'

const props = withDefaults(
    defineProps<{
        modelValue: boolean
        ariaLabel: string
        contentTitle: string
        contentSubtitle?: string
        heroGreeting?: string
        heroEyebrow?: string
        heroTitle?: string
        heroDescription?: string
        compact?: boolean
        showHeroSwitch?: boolean
        switchPrompt?: string
        switchActionText?: string
        closeLabel?: string
    }>(),
    {
        contentSubtitle: '',
        heroGreeting: '',
        heroEyebrow: '',
        heroTitle: '',
        heroDescription: '',
        compact: false,
        showHeroSwitch: false,
        switchPrompt: '',
        switchActionText: '',
        closeLabel: '关闭'
    }
)

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'heroSwitch'): void
}>()

const { lock, unlock } = useModalBodyScrollLock()
const appStore = useAppStore()
const heroMediaType = computed(() => appStore.getWebsiteConfig.pc_login_bg_type === 'video' ? 'video' : 'image')
const heroMediaUrl = computed(() => {
    if (appStore.getWebsiteConfig.pc_login_bg_type === 'none') return ''
    return appStore.getWebsiteConfig.pc_login_bg_url || appStore.getWebsiteConfig.pc_login_bg || ''
})
const heroPosterUrl = computed(() => appStore.getWebsiteConfig.pc_login_bg_poster_url || appStore.getWebsiteConfig.pc_login_bg_poster || '')

const close = () => {
    emit('update:modelValue', false)
}

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.modelValue) {
        close()
    }
}

watch(
    () => props.modelValue,
    (visible) => {
        if (import.meta.client) {
            if (visible) lock()
            else unlock()
        }
    },
    { immediate: true }
)

onMounted(() => {
    if (import.meta.client) {
        window.addEventListener('keydown', onKeydown)
    }
})

onBeforeUnmount(() => {
    if (import.meta.client) {
        window.removeEventListener('keydown', onKeydown)
        unlock()
    }
})
</script>

<style lang="scss">
@import '@/assets/styles/pc-site-login-modal.scss';
</style>
