<template>
    <div class="field-block">
        <div class="field-row">
            <span>剪辑模板</span>
            <button type="button" :disabled="loading" @click="$emit('refresh')">{{ loading ? '加载中' : '换一批' }}</button>
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
    (event: 'refresh'): void
    (event: 'select', item: any): void
}>()

function templateId(item: any) {
    return String(item.id || item.styleId || item.style_id || '')
}
</script>

<style scoped>
.field-block { margin-top: 18px; }
.field-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.field-row button { background: #222; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; padding: 8px 10px; }
.field-row button:disabled { cursor: not-allowed; opacity: .55; }
.template-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.template-card { text-align: left; color: #fff; background: #171719; border: 1px solid rgba(255,255,255,.1); border-radius: 8px; padding: 8px; min-height: 130px; }
.template-card.is-active { border-color: #fff; }
.template-card img, .template-empty { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 6px; background: #2a2b2c; display: grid; place-items: center; }
.template-card strong { display: block; margin-top: 8px; font-size: 13px; }
@media (max-width: 980px) { .template-grid { grid-template-columns: repeat(2,1fr); } }
</style>
