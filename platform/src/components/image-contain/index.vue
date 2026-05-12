<template>
    <el-image :style="styles" v-bind="props" :src="currentSrc" @error="handleError"> </el-image>
</template>

<script lang="ts" setup>
import { imageProps } from 'element-plus'
import type { CSSProperties } from 'vue'
import { computed, ref, watch } from 'vue'

import { addUnit } from '@/utils/util'

const props = defineProps({
    width: {
        type: [String, Number],
        default: 'auto'
    },
    height: {
        type: [String, Number],
        default: 'auto'
    },
    radius: {
        type: [String, Number],
        default: 0
    },
    fallback: {
        type: String,
        default: '/resource/image/common/default_avatar.png'
    },
    ...imageProps
})

const normalizeSrc = (value?: string) => {
    const src = String(value || '').trim()
    if (!src) {
        return props.fallback
    }
    if (/^(https?:)?\/\//.test(src) || src.startsWith('data:') || src.startsWith('blob:')) {
        return src
    }
    return `/${src.replace(/^\/+/, '')}`
}

const currentSrc = ref(normalizeSrc(props.src as string))

watch(
    () => props.src,
    (value) => {
        currentSrc.value = normalizeSrc(value as string)
    }
)

const handleError = () => {
    const fallback = normalizeSrc(props.fallback)
    if (currentSrc.value !== fallback) {
        currentSrc.value = fallback
    }
}

const styles = computed<CSSProperties>(() => {
    return {
        width: addUnit(props.width),
        height: addUnit(props.height),
        borderRadius: addUnit(props.radius)
    }
})
</script>

<style lang="scss" scoped>
.el-image {
    display: block;
    .el-image__error {
        @apply text-xs;
    }
}
</style>
