<template>
    <div class="clip-panel clip-panel--result">
        <div class="preview-box">
            <video v-if="latestVideo" :src="latestVideo" controls playsinline />
            <div v-else class="preview-empty">选择模板和素材后开始剪辑</div>
            <div class="preview-meta">
                <strong>{{ templateName || '未选择模板' }}</strong>
                <span>{{ typeLabel }}</span>
            </div>
        </div>
        <div class="panel-head">
            <strong>任务与作品</strong>
            <button type="button" @click="$emit('refresh')">刷新</button>
        </div>
        <div class="result-list">
            <article v-for="item in results" :key="item.id || item.task_id" class="result-card">
                <video v-if="item.video_url" :src="item.video_url" controls playsinline preload="metadata" />
                <div v-else class="result-placeholder">{{ statusText(item.status) }}</div>
                <div>
                    <strong>{{ item.title || typeText(item.clip_type) }}</strong>
                    <span>{{ statusText(item.status) }} · {{ item.user_charge_points || 0 }}点</span>
                </div>
                <button type="button" @click="$emit('reuse', item)">再次剪辑</button>
                <button type="button" @click="$emit('delete', item)">删除</button>
            </article>
            <div v-if="!results.length" class="empty-state">暂无剪辑作品</div>
        </div>
    </div>
</template>

<script lang="ts" setup>
defineProps<{
    results: any[]
    latestVideo: string
    templateName: string
    typeLabel: string
    statusText: (status: string) => string
    typeText: (type: string) => string
}>()

defineEmits<{
    (event: 'refresh'): void
    (event: 'reuse', item: any): void
    (event: 'delete', item: any): void
}>()
</script>

<style scoped>
.clip-panel { background: #101012; border: 1px solid rgba(255,255,255,.1); border-radius: 8px; padding: 18px; }
.panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.panel-head button, .result-card button { background: #222; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; padding: 8px 10px; }
.preview-box { position: relative; min-height: 360px; background: #050505; border-radius: 8px; overflow: hidden; display: grid; place-items: center; }
.preview-box video { width: 100%; height: 100%; max-height: 520px; object-fit: contain; }
.preview-meta { position: absolute; left: 16px; bottom: 16px; }
.preview-meta span, .result-card span { display: block; color: rgba(255,255,255,.55); font-size: 12px; }
.preview-empty, .empty-state { color: rgba(255,255,255,.45); }
.result-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; margin-top: 14px; }
.result-card { background: #171719; border-radius: 8px; padding: 10px; }
.result-card video, .result-placeholder { width: 100%; aspect-ratio: 16/9; background: #050505; border-radius: 6px; object-fit: contain; display: grid; place-items: center; }
</style>
