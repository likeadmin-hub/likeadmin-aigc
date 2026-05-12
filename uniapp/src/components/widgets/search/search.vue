<template>
    <navigator
        v-if="!isLargeScreen"
        url="/pages/search/search"
        class="px-[24rpx] mt-[24rpx] mb-[30rpx]"
        :style="{ opacity: 1 - percent }"
        hover-class="none"
    >
        <u-search
            placeholder="请输入关键词搜索"
            :height="72"
            :disabled="true"
            :show-action="false"
            bgColor="#ffffff"
        ></u-search>
    </navigator>
</template>

<script setup lang="ts">
defineProps({
    pageMeta: {
        type: Object,
        default: () => []
    },
    content: {
        type: Object,
        default: () => ({})
    },
    styles: {
        type: Object,
        default: () => ({})
    },
    isLargeScreen: {
        type: Boolean
    },
    percent: {
        type: Number
    }
})
</script>

<style scoped></style>
