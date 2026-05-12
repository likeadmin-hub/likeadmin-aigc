<template>
    <div class="cropper-upload">
        <div class="cropper-upload__trigger" @click="openFilePicker">
            <slot />
        </div>
        <input
            ref="fileInputRef"
            class="cropper-upload__file"
            type="file"
            accept="image/*"
            @change="handleFileChange"
        />
        <ClientOnly>
            <ElDialog
                v-model="state.cropperVisible"
                :append-to-body="true"
                :close-on-click-modal="false"
                :width="600"
                @close="state.cropperVisible = false"
            >
                <div class="h-[400px]">
                    <VueCropper
                        ref="vueCropperRef"
                        :img="state.imagePath"
                        :autoCrop="true"
                        :auto-crop-height="200"
                        :auto-crop-width="200"
                        output-type="png"
                    />
                </div>
                <template #footer>
                    <span class="dialog-footer">
                        <ElButton
                            type="primary"
                            :loading="state.uploading"
                            @click="handleConfirmCropper"
                        >
                            {{ state.uploading ? '上传中…' : '确认裁剪' }}
                        </ElButton>
                    </span>
                </template>
            </ElDialog>
        </ClientOnly>
    </div>
</template>

<script lang="ts" setup>
import { ElDialog, ElButton } from 'element-plus'
import 'vue-cropper/dist/index.css'
import { VueCropper } from 'vue-cropper'
import { uploadImage } from '~~/api/app'
import feedback from '@/utils/feedback'

const emit = defineEmits(['change'])
const vueCropperRef = shallowRef()
const fileInputRef = shallowRef<HTMLInputElement>()

const state = reactive({
    cropperVisible: false,
    uploading: false,
    imagePath: ''
})

const openFilePicker = () => {
    fileInputRef.value?.click()
}

const handleFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return

    const URL = window.URL || window.webkitURL
    state.imagePath = URL.createObjectURL(file)
    state.cropperVisible = true
    // 允许相同文件再次被选中
    input.value = ''
}

const handleConfirmCropper = () => {
    if (state.uploading) return
    const cropper = vueCropperRef.value
    if (!cropper) return

    state.uploading = true
    cropper.getCropBlob(async (file: Blob) => {
        try {
            const ext = (file.type.split('/')[1] || 'png').toLowerCase()
            const fileName = `avatar.${ext}`
            const imgFile = new window.File([file], fileName, {
                type: file.type
            })
            const data = await uploadImage({ file: imgFile })
            const uri = data?.uri
            if (!uri) {
                feedback.msgError('上传失败：服务器未返回图片地址')
                return
            }
            state.cropperVisible = false
            emit('change', uri)
        } catch (err: any) {
            const msg =
                typeof err === 'string'
                    ? err
                    : err?.message || err?.msg || '上传失败，请重试'
            feedback.msgError(msg)
        } finally {
            state.uploading = false
        }
    })
}
</script>

<style lang="scss" scoped>
.cropper-upload {
    display: inline-block;
}

.cropper-upload__trigger {
    display: inline-flex;
    cursor: pointer;
}

.cropper-upload__file {
    display: none;
}
</style>
