<template>
    <div class="title-bar" :style="wrapStyle">
        <div class="title" :class="`text-${content.align || 'left'}`">{{ content.title }}</div>
        <div v-if="content.sub_title" class="sub-title" :class="`text-${content.align || 'left'}`">
            {{ content.sub_title }}
        </div>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps({
    content: {
        type: Object,
        default: () => ({})
    },
    styles: {
        type: Object,
        default: () => ({})
    }
})

const wrapStyle = computed(() => ({
    background: props.styles.background,
    color: props.styles.color,
    padding: `${props.styles.padding_top || 0}px 14px ${props.styles.padding_bottom || 0}px`
}))
</script>

<style scoped lang="scss">
.title {
    font-size: 17px;
    font-weight: 600;
}
.sub-title {
    margin-top: 4px;
    font-size: 12px;
    color: #888;
}
</style>
