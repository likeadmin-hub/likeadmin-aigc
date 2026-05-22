<template>
    <el-form ref="form" label-width="80px" size="large">
        <el-card shadow="never" class="!border-none flex mt-2">
            <div class="section-title">页面配置</div>
            <el-form-item label="页面标题">
                <el-radio-group v-model="contentData.title_type">
                    <el-radio value="1">文字</el-radio>
                    <el-radio value="2">图片</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item v-if="content.title_type == 1">
                <el-input
                    v-model="contentData.title"
                    maxlength="8"
                    show-word-limit
                    class="w-[300px]"
                    placeholder="请输入页面标题"
                ></el-input>
            </el-form-item>
            <el-form-item v-if="content.title_type == 2">
                <material-picker v-model="contentData.title_img" :limit="1" size="100px" />
                <div class="form-tips">建议图片尺寸：300px*40px</div>
            </el-form-item>
            <el-form-item label="文字颜色" v-if="content.title_type == 1">
                <el-radio-group v-model="contentData.text_color">
                    <el-radio value="1">白色</el-radio>
                    <el-radio value="2">黑色</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="返回按钮">
                <el-switch v-model="contentData.show_back" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="标题位置">
                <el-radio-group v-model="contentData.title_align">
                    <el-radio value="center">居中</el-radio>
                    <el-radio value="left">居左</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item label="顶部背景">
                <color-picker v-model="contentData.nav_bg_color" reset-color="#FFFFFF" />
                <div class="form-tips">用于微信小程序状态栏和顶部导航背景，H5 仍使用页面标题。</div>
            </el-form-item>
            <div class="section-title section-title--gap">页面背景</div>
            <el-form-item label="页面背景">
                <el-radio-group v-model="contentData.bg_type">
                    <el-radio value="0">无</el-radio>
                    <el-radio value="1">颜色</el-radio>
                    <el-radio value="2">图片</el-radio>
                    <el-radio value="3">视频</el-radio>
                    <el-radio value="4">渐变</el-radio>
                </el-radio-group>
            </el-form-item>
            <el-form-item v-if="content.bg_type == 1">
                <color-picker v-model="contentData.bg_color" reset-color="#F5F5F5" />
            </el-form-item>
            <el-form-item v-if="content.bg_type == 2">
                <material-picker v-model="contentData.bg_image" :limit="1" size="100px" />
                <div class="form-tips">建议图片尺寸：750px*高度不限</div>
            </el-form-item>
            <template v-if="content.bg_type == 2">
                <el-form-item label="平铺方式">
                    <el-select v-model="contentData.bg_image_repeat" class="w-[300px]">
                        <el-option label="不平铺" value="no-repeat" />
                        <el-option label="平铺" value="repeat" />
                        <el-option label="横向平铺" value="repeat-x" />
                        <el-option label="纵向平铺" value="repeat-y" />
                    </el-select>
                </el-form-item>
                <el-form-item label="缩放方式">
                    <el-select v-model="contentData.bg_image_size" class="w-[300px]">
                        <el-option label="覆盖" value="cover" />
                        <el-option label="完整显示" value="contain" />
                        <el-option label="拉伸铺满" value="stretch" />
                        <el-option label="原始尺寸" value="auto" />
                    </el-select>
                </el-form-item>
                <el-form-item label="背景位置">
                    <el-select v-model="contentData.bg_image_position" class="w-[300px]">
                        <el-option label="顶部居中" value="center top" />
                        <el-option label="居中" value="center center" />
                        <el-option label="底部居中" value="center bottom" />
                        <el-option label="左上角" value="left top" />
                        <el-option label="右上角" value="right top" />
                    </el-select>
                </el-form-item>
            </template>
            <el-form-item v-if="content.bg_type == 3">
                <material-picker
                    v-model="contentData.bg_video"
                    type="video"
                    :limit="1"
                    size="100px"
                />
                <div class="form-tips">视频背景在预览中全屏覆盖播放。</div>
            </el-form-item>
            <template v-if="content.bg_type == 4">
                <el-form-item label="渐变方向">
                    <el-select v-model="contentData.gradient_direction" class="w-[300px]">
                        <el-option label="从上到下" value="180deg" />
                        <el-option label="从下到上" value="0deg" />
                        <el-option label="从左到右" value="90deg" />
                        <el-option label="从右到左" value="270deg" />
                        <el-option label="左上到右下" value="135deg" />
                        <el-option label="右上到左下" value="225deg" />
                    </el-select>
                </el-form-item>
                <el-form-item label="渐变颜色">
                    <div class="gradient-colors">
                        <div
                            v-for="(color, index) in contentData.gradient_colors"
                            :key="index"
                            class="gradient-color-item"
                        >
                            <el-color-picker v-model="contentData.gradient_colors[index]" />
                            <el-button
                                v-if="contentData.gradient_colors.length > 2"
                                link
                                type="danger"
                                @click="contentData.gradient_colors.splice(index, 1)"
                            >
                                删除
                            </el-button>
                        </div>
                        <el-button
                            v-if="contentData.gradient_colors.length < 5"
                            @click="addGradientColor"
                        >
                            添加颜色
                        </el-button>
                    </div>
                </el-form-item>
            </template>
        </el-card>
    </el-form>
</template>
<script lang="ts" setup>
import type { PropType } from 'vue'

import type options from './options'

type OptionsType = ReturnType<typeof options>
const emits = defineEmits<(event: 'update:content', data: OptionsType['content']) => void>()
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    },
    styles: {
        type: Object as PropType<OptionsType['styles']>,
        default: () => ({})
    }
})

const defaultContent = {
    title_type: '1',
    title: '',
    title_img: '',
    show_back: 0,
    title_align: 'center',
    nav_bg_color: '#ffffff',
    text_color: '2',
    bg_type: '0',
    bg_color: '',
    bg_image: '',
    bg_image_repeat: 'no-repeat',
    bg_image_size: 'cover',
    bg_image_position: 'center top',
    bg_video: '',
    gradient_direction: '180deg',
    gradient_colors: ['#f8f8f8', '#ffffff']
}
const ensureDefaults = () => {
    Object.keys(defaultContent).forEach((key) => {
        if (
            (props.content as any)[key] === undefined ||
            (key === 'gradient_colors' && !Array.isArray((props.content as any)[key]))
        ) {
            ;(props.content as any)[key] = (defaultContent as any)[key]
        }
    })
}
const contentData = computed({
    get: () => props.content,
    set: (newValue) => {
        emits('update:content', newValue)
    }
})
const addGradientColor = () => {
    contentData.value.gradient_colors.push('#ffffff')
}
watch(
    () => props.content,
    () => ensureDefaults(),
    { immediate: true, deep: true }
)
</script>

<style lang="scss" scoped>
.section-title {
    margin: 0 0 16px;
    color: var(--el-text-color-primary);
    font-size: 14px;
    font-weight: 600;
}
.section-title--gap {
    margin-top: 24px;
}
.gradient-colors {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.gradient-color-item {
    display: flex;
    align-items: center;
    gap: 8px;
}
</style>
