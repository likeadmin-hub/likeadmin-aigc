<template>
    <el-form label-width="70px">
        <el-card shadow="never" class="!border-none">
            <div class="flex items-end">
                <div class="text-base text-[#101010] font-medium">导航设置</div>
                <div class="text-xs text-tx-secondary ml-2">可重复添加列表项</div>
            </div>
            <div class="mt-4">
                <AddNav v-model="contentData.data" @update:modelValue="handleDataChange" />
            </div>
        </el-card>
    </el-form>
</template>

<script lang="ts" setup>
import { cloneDeep } from 'lodash-es'
import type { PropType } from 'vue'

import AddNav from '../../add-nav.vue'
import type options from './options'

type OptionsType = ReturnType<typeof options>
const emit = defineEmits<(event: 'update:content', data: OptionsType['content']) => void>()
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    }
})
const contentData = computed({
    get: () => props.content,
    set: (value) => emit('update:content', value)
})

const handleDataChange = (data: any[]) => {
    const content = cloneDeep(props.content)
    content.data = data || []
    emit('update:content', content)
}
</script>
