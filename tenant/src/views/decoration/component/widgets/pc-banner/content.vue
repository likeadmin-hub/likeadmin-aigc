<template>
    <div class="pc-banner-preview">
        <div class="pc-banner-preview__visual">
            <div class="pc-banner-preview__glow pc-banner-preview__glow--one"></div>
            <div class="pc-banner-preview__glow pc-banner-preview__glow--two"></div>
        </div>
        <div v-if="firstVisible?.image && !imageBroken" class="pc-banner-preview__image">
            <decoration-img width="100%" height="100%" :src="firstVisible.image" fit="cover" />
        </div>
        <div class="pc-banner-preview__empty">
            <div class="pc-banner-preview__hero-copy">
                <span>AI</span>
                <strong>{{ firstVisible?.name || firstVisible?.title || 'AI 创作工作台' }}</strong>
                <em>{{ firstVisible?.description || '配置封面、标题、描述和跳转链接' }}</em>
            </div>
        </div>
        <div class="pc-banner-preview__bar">
            <strong>{{ firstVisible?.name || firstVisible?.title || '首页轮播图' }}</strong>
            <em>{{ firstVisible?.description || '配置封面、标题、描述和跳转链接' }}</em>
            <span>{{ visibleCount }} 张</span>
        </div>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import DecorationImg from '../../decoration-img.vue'
import type options from './options'

type OptionsType = ReturnType<typeof options>
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    },
    styles: {
        type: Object as PropType<OptionsType['styles']>,
        default: () => ({})
    }
})

const visibleRows = computed(() =>
    (props.content.data || []).filter((item: any) => item.is_show !== '0')
)
const firstVisible = computed(() => visibleRows.value[0] || props.content.data?.[0] || null)
const visibleCount = computed(() => visibleRows.value.length || props.content.data?.length || 0)
const imageBroken = computed(() => {
    const image = String(firstVisible.value?.image || '')
    return !image || image.includes('undefined') || image.includes('null')
})
</script>

<style lang="scss" scoped>
.pc-banner-preview {
    position: relative;
    overflow: hidden;
    width: 100%;
    height: 100%;
    min-height: 180px;
    border-radius: 8px;
    background:
        radial-gradient(circle at 20% 20%, rgba(69, 108, 255, 0.45), transparent 34%),
        radial-gradient(circle at 80% 18%, rgba(255, 96, 168, 0.36), transparent 32%),
        linear-gradient(135deg, #eef4ff, #ffffff 44%, #fff1f7);
    box-shadow:
        inset 0 0 0 1px rgba(88, 112, 255, 0.12),
        0 18px 46px rgba(78, 98, 160, 0.16);
}
.pc-banner-preview__image,
.pc-banner-preview__empty {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    min-height: inherit;
}
.pc-banner-preview__image {
    opacity: 0.72;
}
.pc-banner-preview__empty {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding-left: 8%;
    position: relative;
    color: #1e2a44;
}
.pc-banner-preview__glow {
    position: absolute;
    border-radius: 34px;
    filter: blur(0);
}
.pc-banner-preview__glow--one {
    right: 12%;
    top: 14%;
    width: 260px;
    height: 180px;
    background: linear-gradient(135deg, rgba(78, 112, 255, 0.4), rgba(255, 105, 180, 0.34));
    transform: rotate(-8deg);
}
.pc-banner-preview__glow--two {
    right: 26%;
    bottom: 10%;
    width: 180px;
    height: 116px;
    background: linear-gradient(135deg, rgba(29, 185, 255, 0.32), rgba(116, 83, 255, 0.26));
    transform: rotate(10deg);
}
.pc-banner-preview__hero-copy {
    position: relative;
    z-index: 2;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    width: min(520px, 68%);
    span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 54px;
        height: 54px;
        margin-bottom: 18px;
        border-radius: 18px;
        background: linear-gradient(135deg, #4f6cff, #ff68aa);
        color: #fff;
        font-size: 22px;
        font-weight: 700;
    }
    strong {
        color: #111827;
        font-size: 34px;
        line-height: 1.2;
        text-shadow: 0 1px 0 rgba(255, 255, 255, 0.5);
    }
    em {
        margin-top: 10px;
        color: #4f5d75;
        font-size: 15px;
        font-style: normal;
    }
}
.pc-banner-preview__bar {
    position: absolute;
    left: 14px;
    right: 14px;
    bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.78);
    color: #111827;
    box-shadow: 0 12px 32px rgba(74, 87, 128, 0.12);
    backdrop-filter: blur(12px);
    strong,
    em,
    span {
        position: relative;
        z-index: 1;
    }
    em {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        margin: 0 18px;
        color: #7a8498;
        font-style: normal;
    }
    span {
        color: #7a8498;
    }
}
</style>
