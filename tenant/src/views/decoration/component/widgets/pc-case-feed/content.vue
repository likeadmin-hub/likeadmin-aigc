<template>
    <div v-if="content.enabled" class="pc-case-feed">
        <div class="head">
            <strong>{{ content.title || '案例流' }}</strong>
            <span>{{ content.source_key || 'image_cases' }}</span>
        </div>
        <div class="tabs">
            <span v-for="tab in content.tabs || []" :key="tab.name">{{ tab.name }}</span>
        </div>
        <div class="grid">
            <div v-for="index in 8" :key="index" class="case-card"></div>
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
.pc-case-feed {
    padding: 18px;
    border-radius: 8px;
    background: #ffffff;
    color: #111827;
    border: 1px solid rgba(85, 112, 255, 0.1);
    box-shadow: 0 16px 36px rgba(74, 87, 128, 0.08);
}
.head {
    display: flex;
    justify-content: space-between;
    color: #7a8498;
    strong {
        color: #111827;
        font-size: 18px;
    }
}
.tabs {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    span {
        padding: 6px 12px;
        border-radius: 8px;
        background: #f2f5ff;
        color: #53627c;
    }
}
.grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 16px;
}
.case-card {
    aspect-ratio: 4 / 3;
    border-radius: 8px;
    background: linear-gradient(135deg, #dce7ff, #ffe2f0);
}
</style>
