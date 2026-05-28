<template>
    <div v-if="content.enabled" class="pc-rich-media">
        <img v-if="content.image" :src="content.image" alt="" />
        <div v-else class="placeholder">富媒体图片</div>
        <div class="info">
            <strong>{{ content.title }}</strong>
            <span>{{ content.description }}</span>
        </div>
    </div>
</template>

<script lang="ts" setup>
defineProps({
    content: { type: Object, default: () => ({}) },
    styles: { type: Object, default: () => ({}) }
})
</script>

<style scoped lang="scss">
.pc-rich-media {
    overflow: hidden;
    border-radius: 8px;
    background: #11151f;
    color: #fff;
}
img,
.placeholder {
    display: flex;
    width: 100%;
    height: 180px;
    align-items: center;
    justify-content: center;
    object-fit: cover;
    background: linear-gradient(135deg, #394152, #813f8c);
    color: rgba(255, 255, 255, 0.72);
}
.info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px;
    span {
        color: rgba(255, 255, 255, 0.62);
    }
}
</style>
