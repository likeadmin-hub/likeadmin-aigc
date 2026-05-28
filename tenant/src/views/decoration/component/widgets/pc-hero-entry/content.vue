<template>
    <div v-if="content.enabled" class="pc-hero-entry">
        <div class="eyebrow">PC 首页入口</div>
        <div class="title">{{ content.title }}</div>
        <div class="desc">{{ content.description }}</div>
        <div class="actions">
            <span>{{ content.primary_text }}</span>
            <span>{{ content.secondary_text }}</span>
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
.pc-hero-entry {
    min-height: 190px;
    padding: 26px;
    border-radius: 8px;
    background:
        radial-gradient(circle at 84% 18%, rgba(255, 116, 177, 0.18), transparent 28%),
        linear-gradient(135deg, #ffffff, #f2f6ff);
    color: #111827;
    border: 1px solid rgba(85, 112, 255, 0.12);
    box-sizing: border-box;
}
.eyebrow {
    color: #6b7a99;
    font-size: 13px;
}
.title {
    margin-top: 10px;
    font-size: 28px;
    font-weight: 700;
}
.desc {
    margin-top: 8px;
    color: #697386;
}
.actions {
    display: flex;
    gap: 10px;
    margin-top: 22px;
    span {
        padding: 8px 14px;
        border-radius: 8px;
        background: linear-gradient(135deg, #4f6cff, #ff68aa);
        color: #fff;
    }
}
</style>
