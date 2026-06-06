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
.switch-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 12px; }
</style>
