<template>
    <el-form label-width="80px">
        <el-card shadow="never" class="!border-none">
            <el-form-item label="图片">
                <material-picker v-model="contentData.image" :limit="1" exclude-domain />
            </el-form-item>
            <el-form-item label="高度">
                <el-input-number v-model="contentData.height" :min="80" :max="800" />
            </el-form-item>
            <el-form-item label="图片裁剪">
                <el-select v-model="contentData.fit">
                    <el-option label="覆盖" value="cover" />
                    <el-option label="完整显示" value="contain" />
                    <el-option label="拉伸" value="fill" />
                </el-select>
            </el-form-item>
            <div class="text-base font-medium mb-3">热区设置</div>
            <div v-for="(item, index) in contentData.areas" :key="index" class="hotspot-row">
                <div class="flex items-center justify-between mb-3">
                    <span>热区 {{ index + 1 }}</span>
                    <el-button link type="danger" @click="handleDelete(index)">删除</el-button>
                </div>
                <el-form-item label="名称">
                    <el-input v-model="item.name" />
                </el-form-item>
                <div class="grid grid-cols-2 gap-x-3">
                    <el-form-item label="左">
                        <el-input-number v-model="item.left" :min="0" :max="100" :precision="0" />
                    </el-form-item>
                    <el-form-item label="上">
                        <el-input-number v-model="item.top" :min="0" :max="100" :precision="0" />
                    </el-form-item>
                    <el-form-item label="宽">
                        <el-input-number v-model="item.width" :min="1" :max="100" :precision="0" />
                    </el-form-item>
                    <el-form-item label="高">
                        <el-input-number v-model="item.height" :min="1" :max="100" :precision="0" />
                    </el-form-item>
                </div>
                <el-form-item label="链接">
                    <link-picker
                        v-model="item.link"
                        @update:modelValue="(value) => handleAreaLinkChange(index, value)"
                    />
                </el-form-item>
            </div>
            <el-button type="primary" @click="handleAdd">添加热区</el-button>
        </el-card>
    </el-form>
</template>

<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'
import type { PropType } from 'vue'
import type options from './options'

type OptionsType = ReturnType<typeof options>
const emit = defineEmits<(event: 'update:content', data: OptionsType['content']) => void>()
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    }
})
const defaultContent = {
    enabled: 1,
    image: '',
    height: 180,
    fit: 'cover',
    areas: [
        {
            name: '热区',
            left: 0,
            top: 0,
            width: 50,
            height: 50,
            link: {}
        }
    ]
}
const ensureDefaults = () => {
    Object.keys(defaultContent).forEach((key) => {
        if ((props.content as any)[key] === undefined || (key === 'areas' && !Array.isArray((props.content as any)[key]))) {
            ;(props.content as any)[key] = (defaultContent as any)[key]
        }
    })
}
const contentData = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})
const handleAdd = () => {
    const content = cloneDeep(props.content)
    content.areas.push({
        name: '热区',
        left: 0,
        top: 0,
        width: 50,
        height: 50,
        link: {}
    })
    emit('update:content', content)
}
const handleDelete = (index: number) => {
    const content = cloneDeep(props.content)
    content.areas.splice(index, 1)
    emit('update:content', content)
}
const handleAreaLinkChange = (index: number, value: any) => {
    const content = cloneDeep(props.content)
    if (!content.areas?.[index]) return
    content.areas[index].link = value || {}
    emit('update:content', content)
}
watch(
    () => props.content,
    () => ensureDefaults(),
    { immediate: true, deep: true }
)
</script>

<style scoped lang="scss">
.hotspot-row {
    margin-bottom: 12px;
    padding: 12px;
    background: var(--el-fill-color-light);
    border-radius: 8px;
}
:deep(.el-input-number) {
    width: 100%;
}
</style>
