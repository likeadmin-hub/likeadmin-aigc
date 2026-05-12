<template>
    <div v-if="contentData.enabled" class="image-hotspot" :style="{ height: `${contentData.height || 180}px` }">
        <el-image v-if="contentData.image" class="image" :src="contentData.image" :fit="contentData.fit || 'cover'" />
        <div v-else class="placeholder">请选择热区图片</div>
        <div
            v-for="(item, index) in contentData.image ? contentData.areas || [] : []"
            :key="index"
            class="area"
            :style="{
                left: `${item.left}%`,
                top: `${item.top}%`,
                width: `${item.width}%`,
                height: `${item.height}%`
            }"
        >
            {{ item.name }}
        </div>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps({
    content: {
        type: Object,
        default: () => ({})
    }
})
const contentData = computed(() => ({
    enabled: 1,
    image: '',
    height: 180,
    fit: 'cover',
    areas: [],
    ...props.content
}))
</script>

<style scoped lang="scss">
.image-hotspot {
    position: relative;
    margin: 10px 12px;
    overflow: hidden;
    border-radius: 8px;
    background: #f5f7fa;
}
.image {
    width: 100%;
    height: 100%;
}
.placeholder {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
}
.area {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px dashed var(--el-color-primary);
    background: rgba(64, 115, 255, 0.12);
    color: var(--el-color-primary);
    font-size: 12px;
}
</style>
