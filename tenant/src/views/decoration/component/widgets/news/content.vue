<template>
    <div v-if="contentData.enabled" class="news" :style="wrapStyle">
        <div
            v-if="contentData.title"
            class="flex items-center news-title mx-[10px] my-[15px] text-[17px] font-medium"
            :style="{ color: stylesData.title_color }"
        >
            {{ contentData.title }}
        </div>
        <div
            v-for="item in showNewsList"
            :key="item.id"
            class="news-card flex bg-white px-[10px] py-[16px] text-[#333] border-[#f2f2f2] border-b"
            :style="{ color: stylesData.text_color }"
        >
            <div class="mr-[10px]" v-if="contentData.show_image && item.image">
                <img :src="item.image" class="w-[120px] h-[90px] object-contain" />
            </div>
            <div class="flex flex-col justify-between flex-1">
                <div class="text-[15px] font-medium line-clamp-2">{{ item.title }}</div>
                <div v-if="contentData.show_desc" class="line-clamp-1 text-sm mt-[8px]">
                    {{ item.desc }}
                </div>

                <div
                    v-if="contentData.show_time || contentData.show_click"
                    class="text-[#999] text-xs w-full flex justify-between mt-[8px]"
                >
                    <div>{{ contentData.show_time ? item.create_time : '' }}</div>
                    <div v-if="contentData.show_click" class="flex items-center">
                        <icon name="el-icon-View" />
                        <div class="ml-[5px]">{{ item.click }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import { getDecorateArticle } from '@/api/decoration'

import type options from './options'

type OptionsType = ReturnType<typeof options>
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
    enabled: 1,
    title: '最新资讯',
    limit: 10,
    show_image: 1,
    show_desc: 1,
    show_time: 1,
    show_click: 1
}
const defaultStyles = {
    background: '#f8f8f8',
    title_color: '#101010',
    text_color: '#333333'
}
const contentData = computed(() => ({
    ...defaultContent,
    ...props.content
}))
const stylesData = computed(() => ({
    ...defaultStyles,
    ...props.styles
}))
const wrapStyle = computed(() => ({
    background: stylesData.value.background
}))
const newsList = ref<any[]>([])
const showNewsList = computed(() => {
    return newsList.value.slice(0, Number(contentData.value.limit) || 10)
})
const getData = async () => {
    const data = await getDecorateArticle({
        limit: Number(contentData.value.limit) || 10
    })
    newsList.value = data
}
watch(
    () => contentData.value.limit,
    () => getData(),
    { immediate: true }
)
</script>

<style lang="scss" scoped>
.news {
    .news-title {
        &::before {
            content: '';
            width: 4px;
            height: 17px;
            display: block;
            margin-right: 5px;
            background: #4173ff;
        }
    }
}
</style>
