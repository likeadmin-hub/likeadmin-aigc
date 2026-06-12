<template>
    <div class="field-block">
        <div class="field-row">
            <span>剪辑模板</span>
            <button type="button" :disabled="loading" @click="$emit('open-more')">{{ loading ? '加载中' : '查看更多' }}</button>
        </div>
        <div class="template-grid">
            <button
                v-for="item in templates"
                :key="templateId(item)"
                type="button"
                class="template-card"
                :class="{ 'is-active': selectedId === templateId(item) }"
                @click="$emit('select', item)"
            >
                <img v-if="item.coverUrl || item.cover_url" :src="item.coverUrl || item.cover_url" alt="" />
                <div v-else class="template-empty">模板</div>
                <strong>{{ item.name }}</strong>
            </button>
            <div v-if="!templates.length" class="template-empty-state">
                <strong>暂无模板</strong>
                <span>模板加载后会显示在这里</span>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
defineProps<{
    templates: any[]
    selectedId: string
    loading?: boolean
}>()

defineEmits<{
    (event: 'open-more'): void
    (event: 'select', item: any): void
}>()

function templateId(item: any) {
    return String(item.id || item.styleId || item.style_id || '')
}
</script>

<style scoped>
.field-block {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.field-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.field-row span {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
}

.field-row button {
    height: 34px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: #343434;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.field-row button:hover {
    background: #3c3c3c;
    color: #fff;
    transform: translateY(-1px);
}

.field-row button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
    transform: none;
}

.template-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
}

.template-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
    min-height: 0;
    padding: 0;
    border: 1px solid #222;
    border-radius: 10px;
    background: #0f0f0f;
    color: #fff;
    text-align: left;
    cursor: pointer;
    overflow: hidden;
    box-sizing: border-box;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease;
}

.template-card:hover,
.template-card.is-active {
    border-color: #fff;
    background: #0f0f0f;
    transform: translateY(-1px);
}

.template-card.is-active {
    box-shadow: none;
}

.template-card img,
.template-empty {
    display: grid;
    place-items: center;
    width: 100%;
    aspect-ratio: 3 / 4;
    border-radius: 9px 9px 0 0;
    background: #262626;
    color: rgba(255, 255, 255, 0.46);
    object-fit: cover;
    overflow: hidden;
}

.template-card strong {
    display: block;
    min-width: 0;
    padding: 0 8px 8px;
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.template-empty-state {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 132px;
    border: 0;
    border-radius: 10px;
    background: #262626;
    text-align: center;
}

.template-empty-state strong {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
}

.template-empty-state span {
    color: rgba(255, 255, 255, 0.46);
    font-size: 12px;
}

@media (max-width: 980px) {
    .template-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
