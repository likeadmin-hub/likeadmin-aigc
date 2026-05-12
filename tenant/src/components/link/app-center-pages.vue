<template>
    <div class="app-center-pages h-[530px]">
        <div v-if="!entry" class="text-tx-secondary">暂无可选应用</div>
        <template v-else>
            <div class="mb-4">
                <div class="text-xl font-medium">{{ entry.name }}</div>
                <div class="text-sm text-tx-secondary mt-1">{{ entry.app_code }}</div>
            </div>
            <div class="link-list flex flex-wrap">
                <div
                    class="link-item border border-br px-5 py-[5px] rounded-[3px] cursor-pointer mr-[10px] mb-[10px]"
                    v-for="(item, index) in pageLinks"
                    :class="{ 'border-primary text-primary': isSelected(item) }"
                    :key="index"
                    @click="handleSelect(item)"
                >
                    {{ item.name }}
                </div>
            </div>
        </template>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import { type Link, LinkTypeEnum } from '.'

const props = defineProps({
    modelValue: {
        type: Object as PropType<Link>,
        default: () => ({})
    },
    entry: {
        type: Object as PropType<any>,
        default: null
    }
})
const emit = defineEmits<{
    (event: 'update:modelValue', value: Link): void
}>()

const pageLinks = computed<Link[]>(() => {
    if (!props.entry) {
        return []
    }
    const pages =
        Array.isArray(props.entry.meta?.pages) && props.entry.meta.pages.length
            ? props.entry.meta.pages
            : [
                  {
                      name: props.entry.name,
                      path: props.entry.path
                  }
              ]
    return pages.map((item: any) => ({
        path: item.path,
        name: item.name,
        query: item.query || undefined,
        canTab: item.canTab || false,
        type: LinkTypeEnum.APP_CENTER,
        app_code: props.entry.app_code,
        entry_key: props.entry.entry_key,
        terminal: props.entry.terminal
    }))
})

const isSameQuery = (query?: Record<string, any>, target?: Record<string, any>) => {
    return JSON.stringify(query || {}) === JSON.stringify(target || {})
}

const isSelected = (item: Link) => {
    return (
        props.modelValue?.type == LinkTypeEnum.APP_CENTER &&
        props.modelValue?.app_code == item.app_code &&
        props.modelValue?.path == item.path &&
        isSameQuery(props.modelValue?.query, item.query)
    )
}

const handleSelect = (value: Link) => {
    emit('update:modelValue', value)
}
</script>
