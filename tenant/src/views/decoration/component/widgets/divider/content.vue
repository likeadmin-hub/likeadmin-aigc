<template>
    <div
        v-if="content.enabled"
        class="divider"
        :style="{
            margin: `${styles.margin_top || 0}px 14px ${styles.margin_bottom || 0}px`,
            borderTop: `1px ${content.style || 'solid'} ${styles.color || '#eee'}`
        }"
    />
</template>

<script lang="ts" setup>
defineProps({
    content: {
        type: Object,
        default: () => ({})
    },
    styles: {
        type: Object,
        default: () => ({})
    }
})
</script>
