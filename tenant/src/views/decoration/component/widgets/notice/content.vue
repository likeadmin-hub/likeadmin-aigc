<template>
    <div v-if="content.enabled" class="notice" :style="wrapStyle">
        <icon :name="content.icon || 'el-icon-Bell'" :size="16" />
        <span class="notice-text">{{ content.text }}</span>
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
    color: props.styles.color
}))
</script>

<style scoped lang="scss">
.notice {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 10px 12px;
    padding: 10px 12px;
    border-radius: 8px;
}
.notice-text {
    flex: 1;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
</style>
