<template>
    <div class="layer-grid">
        <label v-for="item in layerOptions" :key="item.key">
            <input :checked="enabled(item.key)" type="checkbox" @change="toggle(item.key)" />
            {{ item.label }}
        </label>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps<{ layers: any[] }>()

const layerOptions = [
    { key: 'title', label: '标题图层' },
    { key: 'subtitle', label: '字幕图层' },
    { key: 'introduceCard', label: '身份栏图层' },
    { key: 'background', label: '背景图层' },
    { key: 'digitalHuman', label: '数字人图层' }
]

function enabled(key: string) {
    return props.layers.some((item) => item.key === key && item.visible !== false)
}

function toggle(key: string) {
    const exists = props.layers.find((item) => item.key === key)
    if (exists) {
        exists.visible = exists.visible === false
        return
    }
    props.layers.push({ key, visible: true })
}
</script>

<style scoped>
.layer-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 10px;
}

.layer-grid label {
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

.layer-grid input {
    accent-color: #fff;
}
</style>
