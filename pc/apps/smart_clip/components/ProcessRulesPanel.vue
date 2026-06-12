<template>
    <div>
        <div class="switch-grid">
            <label><input v-model="rules.watermarkShow" type="checkbox" />AI水印</label>
            <label><input v-model="rules.firstFrameCover" type="checkbox" />首帧封面</label>
        </div>
        <div v-if="clipType === 'realman_broadcast'" class="segmented small">
            <button :class="{ 'is-active': rules.resourcePreprocessMethod === '' }" type="button" @click="rules.resourcePreprocessMethod = ''">原片</button>
            <button :class="{ 'is-active': rules.resourcePreprocessMethod === 'roughCut' }" type="button" @click="rules.resourcePreprocessMethod = 'roughCut'">粗剪</button>
            <button :class="{ 'is-active': rules.resourcePreprocessMethod === 'sliceMerge' }" type="button" @click="rules.resourcePreprocessMethod = 'sliceMerge'">字幕切片</button>
        </div>
        <div v-if="clipType === 'news_mixcut'" class="two-grid">
            <ElInputNumber v-model="rules.videoDuration" :min="5" :max="300" />
            <div class="segmented small">
                <button :class="{ 'is-active': rules.materialComposition === 'random' }" type="button" @click="rules.materialComposition = 'random'">随机</button>
                <button :class="{ 'is-active': rules.materialComposition === 'order' }" type="button" @click="rules.materialComposition = 'order'">顺序</button>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ElInputNumber } from 'element-plus'

defineProps<{
    clipType: string
    rules: any
}>()
</script>

<style scoped>
.switch-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
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
    color: rgba(255, 255, 255, 0.76);
    font-size: 14px;
    box-sizing: border-box;
}

.switch-grid input {
    accent-color: #fff;
}

.segmented {
    display: flex;
    gap: 8px;
    margin: 14px 0 0;
}

.segmented button {
    flex: 1;
    min-height: 40px;
    border: 1px solid #222;
    border-radius: 8px;
    background: #0f0f0f;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
}

.segmented button:hover {
    color: #fff;
}

.segmented button.is-active,
.segmented button.is-active:hover,
.segmented button.is-active:focus,
.segmented button.is-active:active {
    border-color: #fff;
    background: #fff;
    color: #050505;
}

.two-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    margin-top: 12px;
}

.small {
    margin: 12px 0 0;
}
</style>
