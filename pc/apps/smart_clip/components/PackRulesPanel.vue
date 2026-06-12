<template>
    <div class="switch-grid">
        <label><input v-model="rules.headerSwitch" type="checkbox" />标题包装</label>
        <label><input v-model="rules.materialSwitch" type="checkbox" />素材包装</label>
        <label><input v-model="rules.subtitleSwitch" type="checkbox" />字幕包装</label>
        <label><input v-model="rules.keywordSwitch" type="checkbox" />关键词包装</label>
        <label><input v-model="rules.backgroundMusic.audioSwitch" type="checkbox" />背景音乐</label>
    </div>
</template>

<script lang="ts" setup>
defineProps<{ rules: any }>()
</script>

<style scoped>
.switch-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 14px;
}

.switch-grid label {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;
    padding: 0 12px;
    border: 1px solid #222;
    border-radius: 8px;
    background: #0f0f0f;
    color: rgba(255, 255, 255, 0.78);
    font-size: 14px;
    box-sizing: border-box;
}

.switch-grid input {
    accent-color: #fff;
}
</style>
