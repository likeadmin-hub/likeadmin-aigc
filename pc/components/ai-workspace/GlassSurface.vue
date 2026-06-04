<template>
    <component
        :is="props.tag"
        ref="containerRef"
        :class="['glass-surface', svgSupported ? 'glass-surface--svg' : 'glass-surface--fallback', props.className, attrs.class]"
        :style="containerStyle"
        v-bind="passthroughAttrs"
    >
        <svg class="glass-surface__filter" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <defs>
                <filter :id="filterId" color-interpolation-filters="sRGB" x="0%" y="0%" width="100%" height="100%">
                    <feImage ref="feImageRef" x="0" y="0" width="100%" height="100%" preserveAspectRatio="none" result="map" />

                    <feDisplacementMap ref="redChannelRef" in="SourceGraphic" in2="map" id="redchannel" result="dispRed" />
                    <feColorMatrix
                        in="dispRed"
                        type="matrix"
                        values="1 0 0 0 0
                                0 0 0 0 0
                                0 0 0 0 0
                                0 0 0 1 0"
                        result="red"
                    />

                    <feDisplacementMap ref="greenChannelRef" in="SourceGraphic" in2="map" id="greenchannel" result="dispGreen" />
                    <feColorMatrix
                        in="dispGreen"
                        type="matrix"
                        values="0 0 0 0 0
                                0 1 0 0 0
                                0 0 0 0 0
                                0 0 0 1 0"
                        result="green"
                    />

                    <feDisplacementMap ref="blueChannelRef" in="SourceGraphic" in2="map" id="bluechannel" result="dispBlue" />
                    <feColorMatrix
                        in="dispBlue"
                        type="matrix"
                        values="0 0 0 0 0
                                0 0 0 0 0
                                0 0 1 0 0
                                0 0 0 1 0"
                        result="blue"
                    />

                    <feBlend in="red" in2="green" mode="screen" result="rg" />
                    <feBlend in="rg" in2="blue" mode="screen" result="output" />
                    <feGaussianBlur ref="gaussianBlurRef" in="output" stdDeviation="0.7" />
                </filter>
            </defs>
        </svg>

        <div class="glass-surface__content">
            <slot />
        </div>
    </component>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useAttrs, watch } from 'vue'

type GlassChannel = 'R' | 'G' | 'B'
type GlassBlendMode =
    | 'normal'
    | 'multiply'
    | 'screen'
    | 'overlay'
    | 'darken'
    | 'lighten'
    | 'color-dodge'
    | 'color-burn'
    | 'hard-light'
    | 'soft-light'
    | 'difference'
    | 'exclusion'
    | 'hue'
    | 'saturation'
    | 'color'
    | 'luminosity'
    | 'plus-darker'
    | 'plus-lighter'

interface GlassSurfaceProps {
    tag?: string
    width?: number | string
    height?: number | string
    borderRadius?: number
    borderWidth?: number
    brightness?: number
    opacity?: number
    blur?: number
    displace?: number
    backgroundOpacity?: number
    saturation?: number
    distortionScale?: number
    redOffset?: number
    greenOffset?: number
    blueOffset?: number
    xChannel?: GlassChannel
    yChannel?: GlassChannel
    mixBlendMode?: GlassBlendMode
    className?: string
    style?: Record<string, string | number>
}

defineOptions({
    inheritAttrs: false
})

const props = withDefaults(defineProps<GlassSurfaceProps>(), {
    tag: 'div',
    width: 200,
    height: 80,
    borderRadius: 20,
    borderWidth: 0.07,
    brightness: 50,
    opacity: 0.93,
    blur: 11,
    displace: 0,
    backgroundOpacity: 0,
    saturation: 1,
    distortionScale: -180,
    redOffset: 0,
    greenOffset: 10,
    blueOffset: 20,
    xChannel: 'R',
    yChannel: 'G',
    mixBlendMode: 'difference',
    className: '',
    style: () => ({})
})

const attrs = useAttrs()
const uid = useId()
const filterId = `glass-filter-${uid}`
const redGradId = `red-grad-${uid}`
const blueGradId = `blue-grad-${uid}`
const svgSupported = ref(false)
const containerRef = ref<HTMLElement | null>(null)
const feImageRef = ref<SVGFEImageElement | null>(null)
const redChannelRef = ref<SVGFEDisplacementMapElement | null>(null)
const greenChannelRef = ref<SVGFEDisplacementMapElement | null>(null)
const blueChannelRef = ref<SVGFEDisplacementMapElement | null>(null)
const gaussianBlurRef = ref<SVGFEGaussianBlurElement | null>(null)
let resizeObserver: ResizeObserver | null = null

function useId() {
    return `rb-${Math.random().toString(36).slice(2, 9)}`
}

const normalizeSize = (value: number | string) => typeof value === 'number' ? `${value}px` : value

const containerStyle = computed(() => ({
    ...props.style,
    width: normalizeSize(props.width),
    height: normalizeSize(props.height),
    borderRadius: `${props.borderRadius}px`,
    '--glass-frost': props.backgroundOpacity,
    '--glass-saturation': props.saturation,
    '--filter-id': `url(#${filterId})`
}))
const passthroughAttrs = computed(() => {
    const { class: _class, style: _style, ...rest } = attrs
    return rest
})

const generateDisplacementMap = () => {
    const rect = containerRef.value?.getBoundingClientRect()
    const actualWidth = rect?.width || 400
    const actualHeight = rect?.height || 200
    const edgeSize = Math.min(actualWidth, actualHeight) * (props.borderWidth * 0.5)
    const svgContent = `
      <svg viewBox="0 0 ${actualWidth} ${actualHeight}" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <linearGradient id="${redGradId}" x1="100%" y1="0%" x2="0%" y2="0%">
            <stop offset="0%" stop-color="#0000"/>
            <stop offset="100%" stop-color="red"/>
          </linearGradient>
          <linearGradient id="${blueGradId}" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" stop-color="#0000"/>
            <stop offset="100%" stop-color="blue"/>
          </linearGradient>
        </defs>
        <rect x="0" y="0" width="${actualWidth}" height="${actualHeight}" fill="black"></rect>
        <rect x="0" y="0" width="${actualWidth}" height="${actualHeight}" rx="${props.borderRadius}" fill="url(#${redGradId})" />
        <rect x="0" y="0" width="${actualWidth}" height="${actualHeight}" rx="${props.borderRadius}" fill="url(#${blueGradId})" style="mix-blend-mode: ${props.mixBlendMode}" />
        <rect x="${edgeSize}" y="${edgeSize}" width="${actualWidth - edgeSize * 2}" height="${actualHeight - edgeSize * 2}" rx="${props.borderRadius}" fill="hsl(0 0% ${props.brightness}% / ${props.opacity})" style="filter:blur(${props.blur}px)" />
      </svg>
    `
    return `data:image/svg+xml,${encodeURIComponent(svgContent)}`
}

const updateDisplacementMap = () => {
    feImageRef.value?.setAttribute('href', generateDisplacementMap())
}

const updateFilterChannels = () => {
    updateDisplacementMap()
    ;[
        { ref: redChannelRef, offset: props.redOffset },
        { ref: greenChannelRef, offset: props.greenOffset },
        { ref: blueChannelRef, offset: props.blueOffset }
    ].forEach(({ ref, offset }) => {
        if (!ref.value) return
        ref.value.setAttribute('scale', (props.distortionScale + offset).toString())
        ref.value.setAttribute('xChannelSelector', props.xChannel)
        ref.value.setAttribute('yChannelSelector', props.yChannel)
    })
    gaussianBlurRef.value?.setAttribute('stdDeviation', props.displace.toString())
}

const supportsSVGFilters = () => {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false
    const isWebkit = /Safari/.test(navigator.userAgent) && !/Chrome/.test(navigator.userAgent)
    const isFirefox = /Firefox/.test(navigator.userAgent)
    if (isWebkit || isFirefox) return false
    const div = document.createElement('div')
    div.style.backdropFilter = `url(#${filterId})`
    return div.style.backdropFilter !== ''
}

watch(
    () => [
        props.width,
        props.height,
        props.borderRadius,
        props.borderWidth,
        props.brightness,
        props.opacity,
        props.blur,
        props.displace,
        props.distortionScale,
        props.redOffset,
        props.greenOffset,
        props.blueOffset,
        props.xChannel,
        props.yChannel,
        props.mixBlendMode
    ],
    () => nextTick(updateFilterChannels)
)

onMounted(() => {
    svgSupported.value = supportsSVGFilters()
    nextTick(updateFilterChannels)
    if (!containerRef.value || typeof ResizeObserver === 'undefined') return
    resizeObserver = new ResizeObserver(() => {
        window.setTimeout(updateDisplacementMap, 0)
    })
    resizeObserver.observe(containerRef.value)
})

onBeforeUnmount(() => {
    resizeObserver?.disconnect()
    resizeObserver = null
})
</script>

<style>
.glass-surface {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    transition: opacity 0.26s ease-out;
}

.glass-surface__filter {
    width: 100%;
    height: 100%;
    pointer-events: none;
    position: absolute;
    inset: 0;
    opacity: 0;
    z-index: -1;
}

.glass-surface__content {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem;
    border-radius: inherit;
    position: relative;
    z-index: 1;
}

.glass-surface--svg {
    background: hsl(0 0% 0% / var(--glass-frost, 0));
    -webkit-backdrop-filter: var(--filter-id, url(#glass-filter)) saturate(var(--glass-saturation, 1));
    backdrop-filter: var(--filter-id, url(#glass-filter)) saturate(var(--glass-saturation, 1));
    box-shadow:
        0 0 2px 1px rgba(255, 255, 255, 0.35) inset,
        0 0 10px 4px rgba(255, 255, 255, 0.15) inset,
        0 4px 16px rgba(17, 17, 26, 0.05),
        0 8px 24px rgba(17, 17, 26, 0.05),
        0 16px 56px rgba(17, 17, 26, 0.05),
        0 4px 16px rgba(17, 17, 26, 0.05) inset,
        0 8px 24px rgba(17, 17, 26, 0.05) inset,
        0 16px 56px rgba(17, 17, 26, 0.05) inset;
}

.glass-surface--fallback {
    background: rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(12px) saturate(1.8) brightness(1.2);
    -webkit-backdrop-filter: blur(12px) saturate(1.8) brightness(1.2);
    border: 1px solid rgba(255, 255, 255, 0.2);
    box-shadow:
        inset 0 1px 0 0 rgba(255, 255, 255, 0.2),
        inset 0 -1px 0 0 rgba(255, 255, 255, 0.1);
}

@supports not (backdrop-filter: blur(10px)) {
    .glass-surface--fallback {
        background: rgba(255, 255, 255, 0.4);
        box-shadow:
            inset 0 1px 0 0 rgba(255, 255, 255, 0.5),
            inset 0 -1px 0 0 rgba(255, 255, 255, 0.3);
    }

    .glass-surface--fallback::before {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(255, 255, 255, 0.15);
        border-radius: inherit;
        z-index: -1;
    }
}

@supports not (backdrop-filter: blur(10px)) {
    @media (prefers-color-scheme: dark) {
        .glass-surface--fallback {
            background: rgba(0, 0, 0, 0.4);
        }

        .glass-surface--fallback::before {
            background: rgba(255, 255, 255, 0.05);
        }
    }
}

.glass-surface:focus-visible {
    outline: 2px solid #0a84ff;
    outline-offset: 2px;
}
</style>
