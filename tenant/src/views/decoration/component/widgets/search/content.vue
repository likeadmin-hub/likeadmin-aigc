<template>
    <div v-if="contentData.enabled" class="search" :style="{ background: styleData.background }">
        <div
            class="search-con flex items-center px-[15px]"
            :style="{ background: styleData.input_background, color: styleData.text_color }"
        >
            <icon name="el-icon-Search" :size="17" />
            <span class="ml-[5px]">{{ contentData.placeholder }}</span>
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
const contentData = computed(() => ({
    enabled: 1,
    placeholder: '请输入关键词搜索',
    link: {},
    ...props.content
}))
const styleData = computed(() => ({
    background: '#ffffff',
    input_background: '#f4f4f4',
    text_color: '#999999',
    ...props.styles
}))
</script>

<style lang="scss" scoped>
.search {
    padding: 7px 12px;
    .search-con {
        height: 36px;
        border-radius: 36px;
    }
}
</style>
