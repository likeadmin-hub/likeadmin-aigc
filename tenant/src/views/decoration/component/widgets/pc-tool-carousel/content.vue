<template>
    <div v-if="content.enabled" class="pc-tool-carousel">
        <div class="head">
            <strong>{{ content.title || '工具轮播' }}</strong>
            <span>{{ content.source_key || '自定义数据' }}</span>
        </div>
        <div class="cards">
            <div v-for="index in 4" :key="index" class="card">
                <div class="cover"></div>
                <span>工具 {{ index }}</span>
            </div>
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
.pc-tool-carousel {
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
.cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 16px;
}
.card {
    min-height: 128px;
    padding: 10px;
    border: 1px solid #edf0f7;
    border-radius: 8px;
    background: #f8faff;
    color: #293247;
}
.cover {
    height: 82px;
    border-radius: 6px;
    background: linear-gradient(135deg, #dce7ff, #ffe2f0);
}
</style>
