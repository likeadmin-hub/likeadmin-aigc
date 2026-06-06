<template>
    <div class="field-block">
        <div class="field-row">
            <span>{{ realman ? '口播视频' : '主素材' }}</span>
            <small>{{ videoUrl ? '已选择' : realman ? '必填' : '可选' }}</small>
        </div>
        <div class="upload-drop" @click="$emit('upload-video')">
            <video v-if="videoUrl" :src="videoUrl" muted playsinline preload="metadata" />
            <div v-else>
                <strong>上传或从作品带入视频</strong>
                <span>mp4 / mov，最长5分钟</span>
            </div>
        </div>

        <div class="field-row material-head">
            <span>素材</span>
            <div class="material-actions">
                <button type="button" @click="$emit('upload-material')">添加素材</button>
                <button type="button" @click="$emit('upload-audio')">添加音频</button>
            </div>
        </div>
        <div class="material-list">
            <article v-for="(item, index) in materials" :key="`${item.fileUrl}-${index}`" class="material-item">
                <img v-if="item.type === 'image'" :src="item.fileUrl" alt="" />
                <audio v-else-if="item.type === 'audio'" :src="item.fileUrl" controls preload="metadata" />
                <video v-else :src="item.fileUrl" muted playsinline preload="metadata" />
                <div>
                    <strong>{{ item.name || materialTypeText(item.type) }}</strong>
                    <span>{{ item.duration ? `${item.duration}秒` : item.type === 'image' ? '按2秒计' : '待识别' }}</span>
                </div>
                <label v-if="item.type === 'video'"><input v-model="item.soundSwitch" type="checkbox" />原声</label>
                <button type="button" @click="$emit('remove', index)">删除</button>
            </article>
            <div v-if="!materials.length" class="empty-inline">暂无素材</div>
        </div>
    </div>
</template>

<script lang="ts" setup>
defineProps<{
    realman: boolean
    videoUrl: string
    materials: any[]
}>()

defineEmits<{
    (event: 'upload-video'): void
    (event: 'upload-material'): void
    (event: 'upload-audio'): void
    (event: 'remove', index: number): void
}>()

function materialTypeText(type: string) {
    if (type === 'audio') return '音频素材'
    return type === 'video' ? '视频素材' : '图片素材'
}
</script>

<style scoped>
.field-block { margin-top: 18px; }
.field-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.field-row button, .material-item button { background: #222; color: #fff; border: 1px solid rgba(255,255,255,.12); border-radius: 6px; padding: 8px 10px; }
.field-row small, .empty-inline { color: rgba(255,255,255,.45); }
.material-head { margin-top: 18px; }
.material-actions { display: flex; gap: 8px; }
.upload-drop { border: 1px dashed rgba(255,255,255,.22); border-radius: 8px; min-height: 150px; display: grid; place-items: center; color: rgba(255,255,255,.6); cursor: pointer; overflow: hidden; }
.upload-drop video { width: 100%; max-height: 220px; object-fit: contain; }
.upload-drop strong, .upload-drop span { display: block; text-align: center; }
.material-list { display: grid; gap: 8px; }
.material-item { display: grid; grid-template-columns: 76px 1fr auto auto; gap: 10px; align-items: center; background: #171719; border-radius: 8px; padding: 8px; }
.material-item img, .material-item video { width: 76px; height: 50px; object-fit: cover; border-radius: 6px; }
.material-item audio { width: 76px; max-width: 76px; }
.material-item span { display: block; color: rgba(255,255,255,.55); font-size: 12px; }
</style>
