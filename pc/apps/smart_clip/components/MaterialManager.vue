<template>
    <div class="field-block">
        <div class="field-row">
            <span>{{ realman ? '口播视频' : '主素材' }}</span>
            <small>{{ videoUrl ? '已选择' : realman ? '必填' : '可选' }}</small>
        </div>
        <div class="upload-drop" :class="{ 'is-uploading': videoUploading }" @click="!videoUploading && $emit('upload-video')">
            <video v-if="activeVideoUrl" :src="activeVideoUrl" muted playsinline preload="metadata" />
            <div v-else>
                <strong>上传或从作品带入视频</strong>
                <span>mp4 / mov，最长5分钟</span>
            </div>
            <div v-if="videoUploading" class="upload-mask">
                <span class="upload-spinner"></span>
                <strong>上传中</strong>
            </div>
        </div>

        <div class="field-row material-head">
            <span>素材</span>
            <div class="material-actions">
                <button type="button" :disabled="materialUploading" @click="$emit('upload-material')">{{ materialUploading ? '上传中' : '添加素材' }}</button>
                <button type="button" :disabled="materialUploading" @click="$emit('upload-audio')">添加音频</button>
            </div>
        </div>
        <div class="material-list">
            <article v-for="(item, index) in materials" :key="`${item.fileUrl}-${index}`" class="material-item" :class="{ 'is-uploading': item.uploading }">
                <div class="material-thumb">
                    <img v-if="item.type === 'image'" :src="item.fileUrl" alt="" />
                    <audio v-else-if="item.type === 'audio'" :src="item.fileUrl" controls preload="metadata" />
                    <video v-else :src="item.fileUrl" muted playsinline preload="metadata" />
                    <div v-if="item.uploading" class="material-upload-mask">
                        <span class="upload-spinner"></span>
                    </div>
                </div>
                <div>
                    <strong>{{ item.name || materialTypeText(item.type) }}</strong>
                    <span>{{ item.uploading ? '上传中...' : item.duration ? `${item.duration}秒` : item.type === 'image' ? '按2秒计' : '待识别' }}</span>
                </div>
                <label v-if="item.type === 'video'" class="sound-switch"><input v-model="item.soundSwitch" :disabled="item.uploading" type="checkbox" />原声</label>
                <button type="button" @click="$emit('remove', index)">删除</button>
            </article>
            <div v-if="!materials.length" class="empty-inline">暂无素材</div>
        </div>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps<{
    realman: boolean
    videoUrl: string
    videoUploadPreview?: string
    videoUploading?: boolean
    materialUploading?: boolean
    materials: any[]
}>()

const activeVideoUrl = computed(() => props.videoUploadPreview || props.videoUrl)

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

.field-row small,
.empty-inline {
    color: rgba(255, 255, 255, 0.45);
    font-size: 12px;
}

.material-head {
    margin-top: 14px;
}

.material-actions {
    display: flex;
    gap: 8px;
}

.field-row button,
.material-item button {
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
        color 0.2s ease;
}

.field-row button:disabled,
.material-item button:disabled {
    cursor: not-allowed;
    opacity: 0.55;
}

.field-row button:hover,
.material-item button:hover {
    background: #3c3c3c;
    color: #fff;
}

.upload-drop {
    position: relative;
    display: grid;
    place-items: center;
    height: 176px;
    min-height: 176px;
    border: 0;
    border-radius: 10px;
    background: #262626;
    color: #b8b8b8;
    cursor: pointer;
    overflow: hidden;
    transition:
        border-color 0.2s ease,
        background 0.2s ease;
}

.upload-drop.is-uploading {
    cursor: wait;
}

.upload-drop:hover {
    background: #2c2c2c;
}

.upload-drop video {
    width: 100%;
    height: 100%;
    max-height: 176px;
    object-fit: contain;
    background: #050505;
}

.upload-drop strong,
.upload-drop span {
    display: block;
    text-align: center;
}

.upload-drop strong {
    color: #b8b8b8;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.3;
}

.upload-drop span {
    margin-top: 8px;
    color: rgba(255, 255, 255, 0.46);
    font-size: 12px;
}

.upload-mask,
.material-upload-mask {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(5, 5, 5, 0.62);
    backdrop-filter: blur(2px);
}

.upload-mask {
    flex-direction: column;
    gap: 8px;
}

.upload-mask strong {
    color: #fff;
    font-size: 13px;
    font-weight: 500;
}

.upload-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.24);
    border-top-color: #fff;
    border-radius: 50%;
    animation: upload-spin 0.72s linear infinite;
}

.material-list {
    display: grid;
    gap: 10px;
}

.material-item {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 10px;
    padding: 8px;
    border: 1px solid #222;
    border-radius: 10px;
    background: #0f0f0f;
    box-sizing: border-box;
}

.material-item.is-uploading {
    border-color: rgba(255, 255, 255, 0.08);
}

.material-thumb {
    position: relative;
    width: 76px;
    height: 50px;
    border-radius: 8px;
    background: #050505;
    overflow: hidden;
}

.material-thumb img,
.material-thumb video {
    width: 76px;
    height: 50px;
    background: #050505;
    object-fit: cover;
}

.material-thumb audio {
    width: 76px;
    max-width: 76px;
}

.material-upload-mask .upload-spinner {
    width: 16px;
    height: 16px;
}

.material-item strong {
    display: block;
    min-width: 0;
    color: #fff;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.35;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.material-item span {
    display: block;
    margin-top: 4px;
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
}

.sound-switch {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 12px;
    white-space: nowrap;
}

.empty-inline {
    display: grid;
    place-items: center;
    min-height: 62px;
    border: 0;
    border-radius: 10px;
    background: #262626;
}

@keyframes upload-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
