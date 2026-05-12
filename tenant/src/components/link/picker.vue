<template>
    <div class="link-picker flex-1" @click="!disabled && popupRef?.open()">
        <el-input :model-value="getLink" placeholder="请选择链接" readonly :disabled="disabled">
            <template #suffix>
                <icon v-if="!selectedLink?.path" name="el-icon-ArrowRight" />
                <icon v-else name="el-icon-Close" @click.stop="!disabled && updateModelValue({})" />
            </template>
        </el-input>
        <popup
            ref="popupRef"
            width="1050px"
            title="链接选择"
            @open="handleOpen"
            @confirm="handleConfirm"
        >
            <link-content ref="linkContentRef" v-model="activeLink" />
        </popup>
    </div>
</template>

<script lang="ts" setup>
import Popup from '@/components/popup/index.vue'

import { type Link, LinkTypeEnum } from '.'
import LinkContent from './index.vue'

const props = defineProps({
    modelValue: {
        type: Object
    },
    disabled: {
        type: Boolean,
        default: false
    }
})
const emit = defineEmits<{
    (event: 'update:modelValue', value: any): void
}>()

const popupRef = shallowRef<InstanceType<typeof Popup>>()
const linkContentRef = shallowRef<InstanceType<typeof LinkContent>>()
const activeLink = ref<Link>({ path: '', type: LinkTypeEnum.SHOP_PAGES })
const selectedLink = ref<any>({})
const handleOpen = () => {
    activeLink.value = selectedLink.value?.type
        ? ({ ...selectedLink.value } as Link)
        : { path: '', type: LinkTypeEnum.SHOP_PAGES }
}
const handleConfirm = () => {
    updateModelValue(linkContentRef.value?.getActiveLink() || activeLink.value)
}

const updateModelValue = (value: any) => {
    if (props.modelValue && typeof props.modelValue == 'object') {
        Object.keys(props.modelValue).forEach((key) => {
            delete props.modelValue[key]
        })
        Object.assign(props.modelValue, value || {})
    }
    selectedLink.value = value || {}
    activeLink.value = value?.type ? (value as Link) : { path: '', type: LinkTypeEnum.SHOP_PAGES }
    emit('update:modelValue', value || {})
}

const getLink = computed(() => {
    switch (selectedLink.value?.type) {
        case LinkTypeEnum.SHOP_PAGES:
            return selectedLink.value.name
        case LinkTypeEnum.ARTICLE_LIST:
            return selectedLink.value.name
        case LinkTypeEnum.APP_CENTER:
            return selectedLink.value.name
        case LinkTypeEnum.DECORATE_PAGE:
            return selectedLink.value.name
        case LinkTypeEnum.CUSTOM_LINK:
            return selectedLink.value.query?.url
        default:
            return selectedLink.value?.name
    }
})
watch(
    () => props.modelValue,
    (value) => {
        selectedLink.value = value || {}
        if (value?.type) {
            activeLink.value = value as Link
        }
    },
    {
        immediate: true
    }
)
</script>

<style scoped lang="scss">
.link-picker {
    :deep(.el-input) {
        &.is-disabled {
            .el-input__inner {
                cursor: not-allowed;
            }
            .el-input__suffix {
                cursor: not-allowed;
            }
        }
        .el-input__inner {
            cursor: pointer;
        }
        .el-input__suffix {
            cursor: pointer;
        }
    }
}
</style>
