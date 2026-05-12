<template>
    <div class="link flex">
        <el-menu
            :default-active="activeMenu"
            class="flex-none w-[180px] min-h-[350px] link-menu"
            :default-openeds="[
                MenuTypeEnum.SHOP_PAGES,
                MenuTypeEnum.DECORATE_PAGE,
                MenuTypeEnum.APPTOOL,
                MenuTypeEnum.APP_CENTER,
                MenuTypeEnum.OTHER_LINK
            ]"
            @select="handleSelect"
        >
            <el-sub-menu v-for="(item, index) in menus" :index="item.type" :key="index">
                <template #title>
                    <span>{{ item.name }}</span>
                </template>
                <el-menu-item
                    v-for="(sitem, sindex) in item.children"
                    :index="sitem.type"
                    :key="sindex"
                    :disabled="sitem.disabled"
                    style="min-width: 160px"
                >
                    <span>{{ sitem.name }}</span>
                </el-menu-item>
            </el-sub-menu>
        </el-menu>
        <div class="flex-1 ml-4 link-content">
            <shop-pages v-model="activeLink" v-if="LinkTypeEnum.SHOP_PAGES == activeMenu" />
            <decorate-pages v-model="activeLink" v-if="LinkTypeEnum.DECORATE_PAGE == activeMenu" />
            <article-list v-model="activeLink" v-if="LinkTypeEnum.ARTICLE_LIST == activeMenu" />
            <app-center-pages
                v-model="activeLink"
                :entry="activeAppEntry"
                v-if="activeMenuComponent == LinkTypeEnum.APP_CENTER"
            />
            <custom-link v-model="activeLink" v-if="LinkTypeEnum.CUSTOM_LINK == activeMenu" />
            <mini-program v-model="activeLink" v-if="LinkTypeEnum.MINI_PROGRAM == activeMenu" />
        </div>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'

import { type Link, LinkTypeEnum, MenuTypeEnum } from '.'
import { appFrontend } from '@/api/app_center'
import AppCenterPages from './app-center-pages.vue'
import ArticleList from './article-list.vue'
import CustomLink from './custom-link.vue'
import DecoratePages from './decorate-pages.vue'
import MiniProgram from './mini-program.vue'
import ShopPages from './shop-pages.vue'

const props = defineProps({
    modelValue: {
        type: Object as PropType<Link>,
        required: true
    }
})
const emit = defineEmits<{
    (event: 'update:modelValue', value: any): void
}>()

const menus = ref([
    {
        name: '商城页面',
        type: MenuTypeEnum.SHOP_PAGES,
        children: [
            {
                name: '基础页面',
                type: LinkTypeEnum.SHOP_PAGES,
                link: {}
            }
        ]
    },
    {
        name: '应用中心',
        type: MenuTypeEnum.APP_CENTER,
        children: [] as any[]
    },
    {
        name: '装修页面',
        type: MenuTypeEnum.DECORATE_PAGE,
        children: [
            {
                name: '模板页面',
                type: LinkTypeEnum.DECORATE_PAGE,
                link: {}
            }
        ]
    },
    {
        name: '应用工具',
        type: MenuTypeEnum.APPTOOL,
        children: [
            {
                name: '文章资讯',
                type: LinkTypeEnum.ARTICLE_LIST,
                link: {}
            }
        ]
    },
    {
        name: '其他',
        type: MenuTypeEnum.OTHER_LINK,
        children: [
            {
                name: '自定义链接',
                type: LinkTypeEnum.CUSTOM_LINK,
                link: {}
            },
            {
                name: '跳转小程序',
                type: LinkTypeEnum.MINI_PROGRAM,
                link: {}
            }
        ]
    }
])

const findMenuItem = (type: string) => {
    for (const item of menus.value) {
        const child = item.children.find((citem: any) => citem.type == type)
        if (child) return child
    }
    return null
}

const activeMenuComponent = computed(() => {
    const item = findMenuItem(activeMenu.value)
    return item?.componentType || activeMenu.value
})

const activeAppEntry = computed(() => {
    const item = findMenuItem(activeMenu.value)
    return item?.entry || null
})

const selectedLink = ref<Partial<Link>>({})
const activeLink = computed({
    get() {
        return selectedLink.value
    },
    set(value) {
        selectedLink.value = value || {}
        emit('update:modelValue', value || {})
    }
})

const activeMenu = ref<string>(LinkTypeEnum.SHOP_PAGES)

const handleSelect = (index: string) => {
    activeMenu.value = index
}

const loadAppLinks = async () => {
    const appMenu = menus.value.find((item) => item.type == MenuTypeEnum.APP_CENTER)
    if (!appMenu) return
    const lists = await appFrontend({ terminal: 'uniapp' })
    appMenu.children = lists.map((entry: any) => ({
        name: entry.name,
        type: `${LinkTypeEnum.APP_CENTER}:${entry.app_code}`,
        componentType: LinkTypeEnum.APP_CENTER,
        entry,
        link: {}
    }))
    if (!appMenu.children.length) {
        appMenu.children = [
            {
                name: '暂无应用',
                type: `${LinkTypeEnum.APP_CENTER}:empty`,
                componentType: LinkTypeEnum.APP_CENTER,
                disabled: true,
                entry: null,
                link: {}
            }
        ]
    }
    if (props.modelValue?.type == LinkTypeEnum.APP_CENTER && props.modelValue?.app_code) {
        activeMenu.value = `${LinkTypeEnum.APP_CENTER}:${props.modelValue.app_code}`
        selectedLink.value = props.modelValue as Link
    }
}

watch(
    () => props.modelValue,
    (value) => {
        activeMenu.value =
            value?.type == LinkTypeEnum.APP_CENTER && value.app_code
                ? `${LinkTypeEnum.APP_CENTER}:${value.app_code}`
                : value?.type || LinkTypeEnum.SHOP_PAGES
        selectedLink.value = value || {}
    },
    {
        immediate: true
    }
)

loadAppLinks()

defineExpose({
    getActiveLink: () => activeLink.value
})
</script>

<style lang="scss" scoped>
.link {
    .link-menu {
        --el-menu-item-height: 40px;
        border-radius: 8px;
        border: 1px solid var(--el-border-color);

        :deep(.el-menu-item) {
            border-color: transparent;

            &.is-active {
                border-right-width: 2px;
                border-color: var(--el-color-primary);
                background-color: var(--el-color-primary-light-9);
            }
        }
    }

    .link-content {
        padding: 20px;
        box-sizing: border-box;
        border-radius: 8px;
        border: 1px solid var(--el-border-color);
    }
}
</style>
