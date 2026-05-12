<template>
    <div class="decorate-pages h-[530px]">
        <div class="text-xl font-medium mb-4">装修页面</div>
        <div v-loading="loading" class="link-list flex flex-wrap">
            <div
                v-for="item in linkList"
                :key="item.id"
                class="link-item border border-br px-5 py-[5px] rounded-[3px] cursor-pointer mr-[10px] mb-[10px]"
                :class="{
                    'border-primary text-primary':
                        modelValue.path == item.path && modelValue.page_code == item.page_code
                }"
                @click="handleSelect(item)"
            >
                {{ item.name }}
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import { getDecoratePageLinkLists } from '@/api/decoration'

import { type Link, LinkTypeEnum } from '.'

defineProps({
    modelValue: {
        type: Object as PropType<Link>,
        default: () => ({})
    }
})
const emit = defineEmits<{
    (event: 'update:modelValue', value: Link): void
}>()

const loading = ref(false)
const linkList = ref<Link[]>([])
const getLists = async () => {
    loading.value = true
    try {
        const data = await getDecoratePageLinkLists({ terminal: 'mobile' })
        linkList.value = data.map((item: any) => ({
            ...item,
            type: LinkTypeEnum.DECORATE_PAGE
        }))
    } finally {
        loading.value = false
    }
}

const handleSelect = (value: Link) => {
    emit('update:modelValue', value)
}

getLists()
</script>
