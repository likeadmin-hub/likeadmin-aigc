<template>
    <div v-if="viewMode === 'list'" class="decorate-template">
        <el-card shadow="never" class="!border-none">
            <div class="flex items-center justify-between">
                <div>
                    <div class="text-lg font-medium">模板管理</div>
                    <div class="text-sm text-tx-secondary mt-1">
                        每个模板包含移动端和 PC 端页面，发布启用后才影响用户端。
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <el-button @click="triggerImportTemplate">导入模板</el-button>
                    <el-button type="primary" @click="openTemplateDialog()">新建模板</el-button>
                </div>
            </div>
        </el-card>
        <div v-loading="loading" class="template-grid mt-4">
            <el-card
                v-for="item in lists"
                :key="item.id"
                shadow="never"
                class="!border-none template-card"
            >
                <div class="cover">
                    <el-image
                        v-if="item.cover"
                        :src="item.cover"
                        fit="cover"
                        class="w-full h-full"
                    />
                    <div v-else class="cover-empty">装修模板</div>
                </div>
                <div class="flex items-start justify-between gap-3 mt-4">
                    <div class="min-w-0">
                        <div class="text-lg font-medium truncate">{{ item.name }}</div>
                        <div class="text-xs text-tx-secondary mt-1">
                            更新：{{ item.update_time || '-' }}
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <el-tag v-if="item.is_active" type="success">启用中</el-tag>
                        <el-tag :type="item.publish_status === 'published' ? 'success' : 'info'">
                            {{ item.publish_status === 'published' ? '已发布' : '草稿' }}
                        </el-tag>
                    </div>
                </div>
                <div class="template-actions">
                    <el-button type="primary" @click="goManage(item.id)">进入管理</el-button>
                    <el-button @click="handleExportTemplate(item.id)">导出</el-button>
                    <el-button @click="handleCopyTemplate(item.id)">复制</el-button>
                    <el-button
                        v-if="!item.is_active"
                        type="success"
                        @click="handleEnableTemplate(item.id)"
                    >
                        启用
                    </el-button>
                    <el-button type="warning" @click="handlePublishTemplate(item.id)"
                        >发布</el-button
                    >
                    <el-button
                        v-if="!item.is_active"
                        type="danger"
                        @click="handleDeleteTemplate(item.id)"
                    >
                        删除
                    </el-button>
                </div>
            </el-card>
        </div>
    </div>

    <div v-else-if="viewMode === 'manage'" class="template-manage">
        <el-card shadow="never" class="!border-none manage-header">
            <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <el-button link @click="backList">
                            <icon name="el-icon-ArrowLeft" :size="18" />
                        </el-button>
                        <div class="text-lg font-medium truncate">{{ detail.template?.name }}</div>
                        <el-tag v-if="detail.template?.is_active" type="success">启用中</el-tag>
                        <el-tag
                            :type="
                                detail.template?.publish_status === 'published' ? 'success' : 'info'
                            "
                        >
                            {{
                                detail.template?.publish_status === 'published' ? '已发布' : '草稿'
                            }}
                        </el-tag>
                    </div>
                    <div class="text-sm text-tx-secondary mt-1">
                        在这里管理{{ terminalText }}页面{{ terminal === 'mobile' ? '、底部导航和系统风格' : '和 PC 自定义装修' }}；单页装修从页面列表进入。
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <el-radio-group v-model="terminal" size="large" @change="handleTerminalChange">
                        <el-radio-button label="mobile">移动端</el-radio-button>
                        <el-radio-button label="pc">PC端</el-radio-button>
                    </el-radio-group>
                    <el-button type="primary" @click="handleSaveSettings">保存设置</el-button>
                    <el-button type="warning" @click="handlePublishTemplate(templateId)"
                        >发布模板</el-button
                    >
                    <el-button
                        v-if="!detail.template?.is_active"
                        type="success"
                        @click="handleEnableTemplate(templateId)"
                    >
                        启用模板
                    </el-button>
                </div>
            </div>
        </el-card>

        <el-tabs v-model="manageTab" class="manage-tabs mt-4">
            <el-tab-pane :label="`${terminalText}页面`" name="pages">
                <el-card shadow="never" class="!border-none">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <div class="text-base font-medium">页面管理</div>
                            <div class="text-sm text-tx-secondary mt-1">
                                {{ terminal === 'mobile' ? '首页、个人中心和客服页为系统页面，可装修但不能删除。' : 'PC 首页为系统页面，自定义页面发布后可通过 /page/{code} 访问。' }}
                            </div>
                        </div>
                        <el-button type="primary" @click="openPageDialog()">新增页面</el-button>
                    </div>
                    <el-table :data="pages" size="large">
                        <el-table-column label="页面名称" min-width="180">
                            <template #default="{ row }">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium">{{ row.name }}</span>
                                    <el-tag v-if="row.is_home" size="small">首页</el-tag>
                                    <el-tag v-if="row.is_system" size="small" type="info"
                                        >系统</el-tag
                                    >
                                </div>
                            </template>
                        </el-table-column>
                        <el-table-column label="页面标识" prop="page_code" min-width="150" />
                        <el-table-column label="页面类型" min-width="120">
                            <template #default="{ row }">
                                {{ pageTypeText(row) }}
                            </template>
                        </el-table-column>
                        <el-table-column label="渠道" min-width="100">
                            <template #default="{ row }">
                                {{ channelText(row.channel) }}
                            </template>
                        </el-table-column>
                        <el-table-column label="状态" width="90">
                            <template #default="{ row }">
                                <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'">
                                    {{ Number(row.status) === 1 ? '启用' : '停用' }}
                                </el-tag>
                            </template>
                        </el-table-column>
                        <el-table-column label="更新时间" prop="update_time" min-width="160" />
                        <el-table-column label="操作" fixed="right" width="260">
                            <template #default="{ row }">
                                <el-button link type="primary" @click.stop="goPageEditor(row.id)"
                                    >装修</el-button
                                >
                                <el-button link @click.stop="previewPage(row)">预览</el-button>
                                <el-button link @click.stop="handleCopyPage(row.id)"
                                    >复制</el-button
                                >
                                <el-button
                                    v-if="!row.is_system"
                                    link
                                    type="danger"
                                    @click.stop="handleDeletePage(row.id)"
                                >
                                    删除
                                </el-button>
                            </template>
                        </el-table-column>
                    </el-table>
                </el-card>
            </el-tab-pane>
            <el-tab-pane v-if="terminal === 'mobile'" label="底部导航" name="tabbar">
                <div v-if="manageTab === 'tabbar'" class="settings-panel">
                    <mobile-tabbar-attr v-model="settings.mobile_tabbar" />
                </div>
            </el-tab-pane>
            <el-tab-pane v-if="terminal === 'mobile'" label="系统风格" name="style">
                <el-card
                    v-if="manageTab === 'style'"
                    shadow="never"
                    class="!border-none settings-panel"
                >
                    <mobile-style v-model="settings.mobile_style" />
                </el-card>
            </el-tab-pane>
        </el-tabs>
    </div>

    <div v-else class="decorate-template-editor">
        <el-card shadow="never" class="!border-none editor-toolbar">
            <div class="flex items-center justify-between gap-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2">
                        <el-button link @click="backManage">
                            <icon name="el-icon-ArrowLeft" :size="18" />
                        </el-button>
                        <div class="text-lg font-medium truncate">
                            {{ currentPage?.name || detail.template?.name }} / {{ terminalText }}装修
                        </div>
                        <el-tag type="info">{{
                            channelText(currentPage?.channel || 'common')
                        }}</el-tag>
                    </div>
                    <div class="text-sm text-tx-secondary mt-1">
                        {{ terminal === 'mobile' ? '当前编辑移动端 H5 预览草稿，小程序复用 common 数据结构。' : '当前编辑 PC 端草稿，可在区块流和自由画布之间切换。' }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <el-button @click="rightTab = 'page'">页面配置</el-button>
                    <el-button @click="previewPage(currentPage)">预览</el-button>
                    <el-button type="primary" @click="handleSavePageDraft">保存草稿</el-button>
                    <el-button type="warning" @click="handlePublishTemplate(templateId)"
                        >发布模板</el-button
                    >
                </div>
            </div>
        </el-card>

        <div class="editor-layout mt-4">
            <el-card shadow="never" class="!border-none editor-side">
                <div class="side-title">组件库</div>
                <div class="text-xs text-tx-secondary mb-3">
                    组件可重复添加，只作用于当前{{ terminalText }}页面。
                </div>
                <div class="widget-scroll">
                    <draggable
                        :list="availableWidgets"
                        class="widget-grid"
                        item-key="name"
                        :group="{ name: 'decoration-widgets', pull: 'clone', put: false }"
                        :sort="false"
                        :clone="cloneWidgetFromLibrary"
                        :animation="180"
                        ghost-class="widget-library-ghost"
                        @start="handleLibraryDragStart"
                        @end="handleLibraryDragEnd"
                    >
                        <template #item="{ element: item }">
                            <div class="widget-item" @click="handleWidgetItemClick(item.name)">
                                <icon :name="item.icon" :size="18" />
                                <span>{{ item.title }}</span>
                                <small>{{ supportChannelText(item.support_channels) }}</small>
                            </div>
                        </template>
                    </draggable>
                    <div v-if="!availableWidgets.length" class="widget-empty">暂无可用组件</div>
                </div>
            </el-card>

            <div class="editor-preview">
                <preview
                    v-if="terminal === 'mobile'"
                    :model-value="selectWidgetIndex"
                    :page-data="currentPageData"
                    :page-meta="currentPageMeta"
                    @update:model-value="handleSelectWidget"
                    @updatePageData="updatePageData"
                    @copyWidget="copyWidget"
                    @deleteWidget="deleteWidget"
                />
                <pc-visual-preview
                    v-else
                    :model-value="selectWidgetIndex"
                    :page-data="currentPageData"
                    @update:model-value="handleSelectWidget"
                    @updatePageData="updatePageData"
                    @copyWidget="copyWidget"
                    @deleteWidget="deleteWidget"
                />
            </div>

            <el-card shadow="never" class="!border-none editor-attr">
                <div class="attr-tabs">
                    <button
                        class="attr-tab"
                        :class="{ active: rightTab === 'widget' }"
                        type="button"
                        @click="rightTab = 'widget'"
                    >
                        组件属性
                    </button>
                    <button
                        class="attr-tab"
                        :class="{ active: rightTab === 'page' }"
                        type="button"
                        @click="rightTab = 'page'"
                    >
                        页面配置
                    </button>
                </div>
                <div class="attr-content">
                    <div v-show="rightTab === 'widget'">
                        <attr-setting
                            :widget="selectWidget"
                            :type="terminal"
                            @update:content="updateContent"
                        />
                    </div>
                    <div v-show="rightTab === 'page'">
                        <div class="page-setting-block">
                            <div class="setting-title">页面信息</div>
                            <el-form label-width="90px">
                                <el-form-item label="页面名称">
                                    <el-input v-model="pageForm.name" />
                                </el-form-item>
                                <el-form-item label="页面标识">
                                    <el-input
                                        v-model="pageForm.page_code"
                                        :disabled="!!currentPage?.is_system"
                                    />
                                </el-form-item>
                                <el-form-item label="页面状态">
                                    <el-switch
                                        v-model="pageForm.status"
                                        :active-value="1"
                                        :inactive-value="0"
                                    />
                                </el-form-item>
                                <el-form-item label="排序">
                                    <el-input-number v-model="pageForm.sort" :min="0" />
                                </el-form-item>
                                <el-form-item>
                                    <el-button type="primary" @click="savePageBase"
                                        >保存页面</el-button
                                    >
                                    <el-button @click="handleCopyCurrentPage">复制页面</el-button>
                                    <el-button
                                        v-if="!currentPage?.is_system"
                                        type="danger"
                                        @click="handleDeleteCurrentPage"
                                    >
                                        删除页面
                                    </el-button>
                                </el-form-item>
                            </el-form>
                        </div>
                        <div v-if="terminal === 'pc'" class="page-setting-block">
                            <div class="setting-title">统一数据源</div>
                            <div class="source-tags">
                                <el-tag v-for="source in dataSources" :key="source.key" effect="plain">
                                    {{ source.name }}
                                </el-tag>
                            </div>
                        </div>
                        <div class="page-setting-block">
                            <div class="setting-title">页面配置 / 页面背景</div>
                            <page-meta-attr
                                :content="pageMetaContent"
                                :styles="pageMetaStyles"
                                :type="terminal"
                                @update:content="updatePageMetaContent"
                            />
                        </div>
                    </div>
                </div>
            </el-card>
        </div>
    </div>

    <el-dialog v-model="templateDialog.show" title="装修模板" width="420px">
        <el-form label-width="80px">
            <el-form-item label="模板名称">
                <el-input v-model="templateDialog.form.name" />
            </el-form-item>
            <el-form-item label="封面">
                <material-picker v-model="templateDialog.form.cover" exclude-domain />
            </el-form-item>
        </el-form>
        <template #footer>
            <el-button @click="templateDialog.show = false">取消</el-button>
            <el-button type="primary" @click="handleSaveTemplate">确定</el-button>
        </template>
    </el-dialog>

    <el-dialog v-model="pageDialog.show" title="新增装修页面" width="420px">
        <el-form label-width="80px">
            <el-form-item label="页面名称">
                <el-input v-model="pageDialog.form.name" />
            </el-form-item>
            <el-form-item label="页面标识">
                <el-input v-model="pageDialog.form.page_code" placeholder="例如 activity_page" />
            </el-form-item>
        </el-form>
        <template #footer>
            <el-button @click="pageDialog.show = false">取消</el-button>
            <el-button type="primary" @click="handleAddPage">确定</el-button>
        </template>
    </el-dialog>

    <input
        ref="importInputRef"
        class="hidden"
        type="file"
        accept=".zip,.ladtpl.zip,application/zip"
        @change="handleImportTemplateFile"
    />
</template>

<script lang="ts" setup name="decorationTemplate">
import { ElMessage } from 'element-plus'
import { cloneDeep } from 'lodash-es'
import Draggable from 'vuedraggable'

import {
    addDecoratePage,
    addDecorateTemplate,
    copyDecoratePage,
    copyDecorateTemplate,
    deleteDecoratePage,
    deleteDecorateTemplate,
    editDecoratePage,
    enableDecorateTemplate,
    exportDecorateTemplate,
    getDecorateDataSources,
    getDecorateTemplateDetail,
    getDecorateTemplateLists,
    importDecorateTemplate,
    publishDecorateTemplate,
    saveDecorateTemplateSettings,
    setDecoratePages
} from '@/api/decoration'
import feedback from '@/utils/feedback'
import { getNonDuplicateID } from '@/utils/util'

import AttrSetting from '../component/pages/attr-setting.vue'
import PcVisualPreview from '../component/pages/preview-pc-visual.vue'
import Preview from '../component/pages/preview.vue'
import mobileTabbarAttr from '../component/tabbar/mobile/attr.vue'
import widgets from '../component/widgets'
import PageMetaAttr from '../component/widgets/page-meta/attr.vue'
import MobileStyle from '../style/components/mobile-style.vue'
import { getPcPreviewUrl } from '../utils/pc'
import { mobileWidgetDefinitions, createDefaultMobileWidget } from '@mobile-decoration'
import { pcWidgetDefinitions, createDefaultPcWidget, getWidgetLayout } from '@decoration-core'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const lists = ref<any[]>([])
const detail = reactive<any>({
    template: {},
    settings: {},
    pages: []
})
const terminal = ref<'mobile' | 'pc'>(route.query.terminal === 'pc' ? 'pc' : 'mobile')
const templateId = computed(() => Number(route.query.id || 0))
const routeMode = computed(() => String(route.query.mode || ''))
const viewMode = computed<'list' | 'manage' | 'editor'>(() => {
    if (!templateId.value) return 'list'
    return routeMode.value === 'editor' ? 'editor' : 'manage'
})
const activePageId = ref<number>(0)
const selectWidgetIndex = ref<number>(0)
const rightTab = ref('widget')
const manageTab = ref('pages')
const isLibraryDragging = ref(false)
const dataSources = ref<any[]>([])
const importInputRef = shallowRef<HTMLInputElement>()

const templateDialog = reactive({
    show: false,
    form: {
        name: '',
        cover: ''
    }
})
const pageDialog = reactive({
    show: false,
    form: {
        name: '',
        page_code: ''
    }
})
const pageForm = reactive<any>({
    id: 0,
    name: '',
    page_code: '',
    status: 1,
    sort: 0
})
const pageMetaContent = reactive<any>({})
const pageMetaStyles = reactive<any>({})
const settings = reactive<any>({
    mobile_style: {},
    mobile_tabbar: {
        style: {},
        list: []
    },
    pc_style: {
        width: 1200,
        background: '#f5f7fa'
    }
})

const pages = computed(() => detail.pages || [])
const terminalText = computed(() => (terminal.value === 'pc' ? 'PC端' : '移动端'))
const currentPage = computed(() => pages.value.find((item: any) => item.id === activePageId.value))
const currentPageData = computed(() =>
    normalizePageData(safeParse(currentPage.value?.draft_data || currentPage.value?.data, []))
)
const currentPageMeta = computed(() =>
    normalizePageMeta(safeParse(currentPage.value?.draft_meta || currentPage.value?.meta, null))
)
const selectWidget = computed(() => {
    if (selectWidgetIndex.value === -1) {
        return currentPageMeta.value?.[0] || {}
    }
    return currentPageData.value?.[selectWidgetIndex.value] || {}
})

const availableWidgets = computed(() => {
    if (terminal.value === 'pc') {
        return pcWidgetDefinitions.map((item) => ({
            name: item.name,
            title: item.title,
            icon: item.icon,
            terminal: item.terminal,
            support_channels: ['pc']
        }))
    }
    const sharedMobileMeta = Object.fromEntries(mobileWidgetDefinitions.map((item) => [item.name, item]))
    return Object.keys(widgets)
        .filter((name) => name !== 'page-meta')
        .map((name) => {
            const option = widgets[name]?.options?.() || {}
            const shared = sharedMobileMeta[name]
            return {
                name,
                title: shared?.title || option.title || name,
                icon: shared?.icon || widgets[name]?.icon || 'el-icon-Menu',
                terminal: widgets[name]?.terminal || ['mobile', 'pc'],
                support_channels: widgets[name]?.support_channels || ['h5', 'mp_weixin']
            }
        })
        .filter((item) => item.terminal.includes(terminal.value))
})

const safeParse = (value: any, defaults: any) => {
    if (Array.isArray(value) || (value && typeof value === 'object')) return value
    try {
        const data = JSON.parse(value || '')
        return data ?? defaults
    } catch (error) {
        return defaults
    }
}

const normalizePageData = (data: any[]) => {
    if (!Array.isArray(data)) return []
    return data.map((item) => {
        if (['news', 'search'].includes(item?.name) && item?.disabled) {
            const normalized = { ...item }
            delete normalized.disabled
            return normalized
        }
        return item
    })
}
const getDefaultPageMeta = () =>
    cloneDeep(
        widgets['page-meta']?.options?.() || {
            title: '页面配置',
            name: 'page-meta',
            content: {},
            styles: {}
        }
    )
const normalizePageMeta = (value: any) => {
    const defaults = getDefaultPageMeta()
    const meta = Array.isArray(value) ? value : value ? [value] : []
    const current = meta[0] || {}
    return [
        {
            ...defaults,
            ...current,
            name: 'page-meta',
            content: {
                ...(defaults.content || {}),
                ...(current.content || {})
            },
            styles: {
                ...(defaults.styles || {}),
                ...(current.styles || {})
            }
        }
    ]
}
const assignReactive = (target: Record<string, any>, source: Record<string, any>) => {
    Object.keys(target).forEach((key) => delete target[key])
    Object.assign(target, cloneDeep(source || {}))
}
const syncPageMetaForm = () => {
    const meta = currentPageMeta.value?.[0] || getDefaultPageMeta()
    assignReactive(pageMetaContent, meta.content || {})
    assignReactive(pageMetaStyles, meta.styles || {})
}

const syncPageForm = () => {
    const page = currentPage.value
    if (!page) return
    Object.assign(pageForm, {
        id: page.id,
        name: page.name,
        page_code: page.page_code,
        status: Number(page.status),
        sort: Number(page.sort)
    })
    syncPageMetaForm()
}

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getDecorateTemplateLists()
    } finally {
        loading.value = false
    }
}

const reloadDetail = async () => {
    if (viewMode.value === 'list' || !templateId.value) return
    loading.value = true
    try {
        const data = await getDecorateTemplateDetail({
            id: templateId.value,
            terminal: terminal.value
        })
        if (terminal.value === 'pc' && !dataSources.value.length) {
            dataSources.value = await getDecorateDataSources({ terminal: 'pc' })
        }
        detail.template = data.template || {}
        detail.pages = data.pages || []
        Object.assign(settings, {
            mobile_style: data.settings?.mobile_style || {},
            mobile_tabbar: data.settings?.mobile_tabbar || { style: {}, list: [] },
            pc_style: data.settings?.pc_style || { width: 1200, background: '#f5f7fa' }
        })
        const queryPageId = Number(route.query.page_id || 0)
        activePageId.value =
            pages.value.find((item: any) => item.id === queryPageId)?.id ||
            pages.value.find((item: any) => item.id === activePageId.value)?.id ||
            pages.value[0]?.id ||
            0
        selectWidgetIndex.value = currentPageData.value.findIndex((item: any) => !item.disabled)
        if (selectWidgetIndex.value < 0) selectWidgetIndex.value = currentPageMeta.value ? -1 : 0
        syncPageForm()
    } finally {
        loading.value = false
    }
}

const goManage = (id: number) => {
    router.push({
        path: route.path,
        query: {
            id,
            mode: 'manage',
            terminal: terminal.value
        }
    })
}

const goPageEditor = (pageId: number) => {
    if (!pageId) return
    const page = pages.value.find((item: any) => item.id === pageId)
    if (terminal.value === 'pc') {
        router.push({
            path: '/decoration/pc_details',
            query: {
                template_id: templateId.value,
                page_id: pageId,
                page_code: page?.page_code || '',
                terminal: 'pc'
            }
        })
        return
    }
    manageTab.value = 'pages'
    rightTab.value = 'widget'
    router.push({
        path: route.path,
        query: {
            id: templateId.value,
            mode: 'editor',
            page_id: pageId,
            terminal: terminal.value
        }
    })
}

const backList = () => {
    router.push({ path: route.path })
}

const backManage = () => {
    router.push({
        path: route.path,
        query: {
            id: templateId.value,
            mode: 'manage',
            terminal: terminal.value
        }
    })
}

const openTemplateDialog = () => {
    templateDialog.form.name = ''
    templateDialog.form.cover = ''
    templateDialog.show = true
}

const handleSaveTemplate = async () => {
    await addDecorateTemplate(templateDialog.form)
    templateDialog.show = false
    getLists()
}

const handleCopyTemplate = async (id: number) => {
    await copyDecorateTemplate({ id })
    getLists()
}

const triggerImportTemplate = () => {
    importInputRef.value?.click()
}

const handleImportTemplateFile = async (event: Event) => {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''
    if (!file) return
    const fileBase64 = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader()
        reader.onload = () => resolve(String(reader.result || ''))
        reader.onerror = () => reject(reader.error)
        reader.readAsDataURL(file)
    })
    await importDecorateTemplate({
        filename: file.name,
        file_base64: fileBase64
    })
    ElMessage.success('导入成功')
    getLists()
}

const handleExportTemplate = async (id: number) => {
    const data = await exportDecorateTemplate({ id })
    const link = document.createElement('a')
    link.href = `data:${data.mime || 'application/zip'};base64,${data.content}`
    link.download = data.filename || 'decorate_template.ladtpl.zip'
    link.click()
}

const handleDeleteTemplate = async (id: number) => {
    await feedback.confirm('删除后该模板草稿和页面将一并删除，确认继续？')
    await deleteDecorateTemplate({ id })
    getLists()
}

const handlePublishTemplate = async (id: number) => {
    if (viewMode.value === 'editor') {
        await saveCurrentPage(false)
    }
    if (viewMode.value === 'manage') {
        await saveSettings(false)
    }
    await publishDecorateTemplate({ id })
    ElMessage.success('发布成功')
    viewMode.value === 'list' ? getLists() : reloadDetail()
}

const handleEnableTemplate = async (id: number) => {
    await enableDecorateTemplate({ id })
    ElMessage.success('启用成功')
    viewMode.value === 'list' ? getLists() : reloadDetail()
}

const handleTerminalChange = () => {
    manageTab.value = 'pages'
    router.replace({
        path: route.path,
        query: {
            ...route.query,
            terminal: terminal.value,
            page_id: undefined
        }
    })
    reloadDetail()
}

const updatePageData = (value: any[]) => {
    if (!currentPage.value) return
    currentPage.value.draft_data = JSON.stringify(value || [])
    currentPage.value.data = currentPage.value.draft_data
}

const writePageMetaDraft = () => {
    if (!currentPage.value) return
    const meta = normalizePageMeta(currentPageMeta.value)
    meta[0].content = cloneDeep(pageMetaContent)
    meta[0].styles = cloneDeep(pageMetaStyles)
    currentPage.value.draft_meta = JSON.stringify(meta)
    currentPage.value.meta = currentPage.value.draft_meta
}

const updatePageMetaContent = (content: any) => {
    assignReactive(pageMetaContent, content || {})
    writePageMetaDraft()
}

const updateContent = (content: any) => {
    if (!currentPage.value) return
    const data = currentPageData.value
    const meta = currentPageMeta.value
    if (selectWidgetIndex.value === -1 && meta?.[0]) {
        meta[0].content = content
        currentPage.value.draft_meta = JSON.stringify(meta)
        currentPage.value.meta = currentPage.value.draft_meta
    } else if (data[selectWidgetIndex.value]) {
        data[selectWidgetIndex.value].content = content
        updatePageData(data)
    }
}

const createWidgetData = (name: string) => ({
    id: getNonDuplicateID(),
    ...(
        terminal.value === 'pc'
            ? createDefaultPcWidget(name) || widgets[name]?.options?.()
            : createDefaultMobileWidget(name) || widgets[name]?.options?.()
    )
})

const ensurePcWidgetLayout = (widget: any, index = 0) => {
    if (terminal.value !== 'pc') return widget
    const next = cloneDeep(widget)
    const styles = next.styles || {}
    const layout = getWidgetLayout(next, index)
    next.styles = {
        ...styles,
        layout
    }
    return next
}

const cloneWidgetFromLibrary = (item: any) => ensurePcWidgetLayout(createWidgetData(item.name), currentPageData.value.length)

const handleLibraryDragStart = () => {
    isLibraryDragging.value = true
}

const handleLibraryDragEnd = () => {
    setTimeout(() => {
        isLibraryDragging.value = false
    }, 0)
}

const handleWidgetItemClick = (name: string) => {
    if (isLibraryDragging.value) return
    addWidget(name)
}

const handleSelectWidget = (index: number) => {
    selectWidgetIndex.value = index
    if (index !== -1) {
        rightTab.value = 'widget'
    }
}

const addWidget = (name: string) => {
    if (!currentPage.value) return
    const data = currentPageData.value
    data.push(ensurePcWidgetLayout(createWidgetData(name), data.length))
    updatePageData(data)
    selectWidgetIndex.value = data.length - 1
    rightTab.value = 'widget'
}

const copyWidget = (index: number) => {
    const data = currentPageData.value
    if (!data[index]) return
    const copy = cloneDeep(data[index])
    copy.id = getNonDuplicateID()
    data.splice(index + 1, 0, copy)
    updatePageData(data)
    selectWidgetIndex.value = index + 1
}

const deleteWidget = async (index: number) => {
    const data = currentPageData.value
    if (!data[index] || data[index].disabled) return
    data.splice(index, 1)
    updatePageData(data)
    selectWidgetIndex.value = Math.min(index, data.length - 1)
}

const openPageDialog = () => {
    pageDialog.form.name = ''
    pageDialog.form.page_code = ''
    pageDialog.show = true
}

const handleAddPage = async () => {
    const page = await addDecoratePage({
        template_id: templateId.value,
        terminal: terminal.value,
        ...pageDialog.form
    })
    pageDialog.show = false
    await reloadDetail()
    goPageEditor(page.id)
}

const savePageBase = async () => {
    await editDecoratePage({
        template_id: templateId.value,
        ...pageForm
    })
    ElMessage.success('页面已保存')
    reloadDetail()
}

const handleCopyPage = async (id: number) => {
    const page = await copyDecoratePage({ id })
    await reloadDetail()
    goPageEditor(page.id)
}

const handleCopyCurrentPage = async () => {
    if (!currentPage.value) return
    await handleCopyPage(currentPage.value.id)
}

const handleDeletePage = async (id: number) => {
    await feedback.confirm('确认删除当前装修页面？')
    await deleteDecoratePage({ id })
    await reloadDetail()
}

const handleDeleteCurrentPage = async () => {
    if (!currentPage.value) return
    await handleDeletePage(currentPage.value.id)
    backManage()
}

const saveCurrentPage = async (showMessage = true) => {
    if (!currentPage.value) return
    writePageMetaDraft()
    await setDecoratePages({
        template_id: templateId.value,
        id: currentPage.value.id,
        name: pageForm.name || currentPage.value.name,
        data: currentPage.value.draft_data || currentPage.value.data || '[]',
        meta: currentPage.value.draft_meta || currentPage.value.meta || ''
    })
    if (showMessage) {
        ElMessage.success(
            terminal.value === 'pc'
                ? '草稿已保存，发布模板后 PC 端生效'
                : '草稿已保存，发布模板后小程序/H5正式端生效'
        )
    }
}

const handleSavePageDraft = async () => {
    await saveCurrentPage()
    reloadDetail()
}

const saveSettings = async (showMessage = true) => {
    await saveDecorateTemplateSettings({
        id: templateId.value,
        settings
    })
    if (showMessage) {
        ElMessage.success('设置已保存')
    }
}

const handleSaveSettings = async () => {
    await saveSettings()
    reloadDetail()
}

const getH5PreviewUrl = (page: any) => {
    const baseUrl = String(import.meta.env.VITE_APP_BASE_URL || window.location.origin).replace(
        /\/$/,
        ''
    )
    const query = new URLSearchParams({
        code: page?.page_code || 'home',
        preview: '1',
        template_id: String(templateId.value || page?.template_id || 0),
        page_id: String(page?.id || 0)
    })
    return `${baseUrl}/mobile/pages/diy/diy?${query.toString()}`
}

const previewPage = async (page: any) => {
    if (!page) return
    if (viewMode.value === 'editor' && currentPage.value?.id === page.id) {
        await saveCurrentPage(false)
    }
    if (terminal.value === 'pc') {
        window.open(getPcPreviewUrl(page, templateId.value), '_blank')
        return
    }
    window.open(getH5PreviewUrl(page), '_blank')
}

const pageTypeText = (row: any) => {
    const map: Record<string, string> = {
        mobile_home: '移动首页',
        mobile_user: '个人中心',
        mobile_service: '客服页',
        pc_home: 'PC首页',
        custom: '自定义页'
    }
    return map[row.page_type] || row.page_type || '自定义页'
}

const channelText = (value: string) => {
    const map: Record<string, string> = {
        common: 'H5/小程序共用',
        h5: 'H5',
        mp_weixin: '微信小程序'
    }
    return map[value] || value || 'H5/小程序共用'
}

const supportChannelText = (value: string[]) => {
    const channels = value || ['h5', 'mp_weixin']
    if (channels.includes('pc')) return 'PC'
    if (channels.includes('h5') && channels.includes('mp_weixin')) return 'H5/小程序'
    if (channels.includes('h5')) return '仅H5'
    if (channels.includes('mp_weixin')) return '仅小程序'
    return '通用'
}

watch(
    () => route.query,
    () => {
        terminal.value = route.query.terminal === 'pc' ? 'pc' : 'mobile'
        viewMode.value === 'list' ? getLists() : reloadDetail()
    },
    { immediate: true }
)

watch(
    () => pageMetaContent,
    () => writePageMetaDraft(),
    { deep: true }
)

watch(selectWidgetIndex, (value) => {
    if (value === -1) {
        rightTab.value = 'page'
    }
})
</script>

<style scoped lang="scss">
.template-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.template-card {
    .cover {
        height: 160px;
        border-radius: 8px;
        overflow: hidden;
        background: linear-gradient(135deg, #eef4ff, #f7f8fa);
    }
    .cover-empty {
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #7a8294;
        font-size: 18px;
        font-weight: 600;
    }
    .template-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 16px;
        :deep(.el-button) {
            margin-left: 0;
        }
    }
}
.template-manage {
    min-width: 980px;
}
.manage-header {
    position: sticky;
    top: 0;
    z-index: 2;
}
.manage-tabs {
    :deep(.el-tabs__header) {
        margin-bottom: 12px;
    }
}
.settings-panel {
    min-height: 520px;
    max-height: calc(100vh - var(--navbar-height) - 190px);
    overflow-y: auto;
}
.decorate-template-editor {
    height: calc(100vh - var(--navbar-height) - 34px);
    width: 100%;
    max-width: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.editor-toolbar {
    flex: none;
}
.editor-layout {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: clamp(168px, 15vw, 196px) minmax(440px, 1fr) clamp(320px, 30vw, 380px);
    gap: 12px;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
}
.editor-side,
.editor-attr {
    height: 100%;
    min-height: 0;
    overflow: hidden;
    :deep(.el-card__body) {
        height: 100%;
        box-sizing: border-box;
    }
}
.editor-side {
    :deep(.el-card__body) {
        display: flex;
        flex-direction: column;
        padding: 16px;
    }
    .widget-scroll {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding-right: 2px;
        scrollbar-width: none;
        -ms-overflow-style: none;
        &::-webkit-scrollbar {
            width: 0;
            height: 0;
        }
    }
}
.editor-attr {
    :deep(.el-card__body) {
        padding: 0;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .attr-tabs {
        flex: none;
        display: flex;
        align-items: center;
        gap: 28px;
        margin: 0;
        padding: 0 18px;
        height: 52px;
        border-bottom: 1px solid var(--el-border-color-light);
        background: #fff;
    }
    .attr-tab {
        height: 52px;
        padding: 0;
        border: 0;
        border-bottom: 2px solid transparent;
        background: transparent;
        color: var(--el-text-color-regular);
        font-size: 14px;
        cursor: pointer;
        &.active {
            color: var(--el-color-primary);
            border-bottom-color: var(--el-color-primary);
            font-weight: 600;
        }
    }
    .attr-content {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 16px 14px 24px;
        scrollbar-gutter: stable;
        > div {
            min-width: 0;
        }
    }
    :deep(.pages-setting) {
        height: auto;
        min-width: 0;
    }
    :deep(.pages-setting > .el-card:first-child) {
        padding: 0;
    }
    :deep(.pages-setting > .el-scrollbar) {
        height: auto !important;
        overflow: visible;
    }
    :deep(.pages-setting > .el-scrollbar .el-scrollbar__wrap) {
        height: auto;
        overflow: visible;
    }
    :deep(.el-card) {
        max-width: 100%;
    }
    :deep(.el-card__body) {
        max-width: 100%;
    }
    :deep(.el-form-item__content) {
        min-width: 0;
    }
    :deep(.el-input),
    :deep(.el-select),
    :deep(.el-input-number),
    :deep(.el-textarea) {
        max-width: 100%;
    }
    :deep(.w-\[467px\]) {
        width: 100%;
    }
    :deep(.w-\[467px\] .bg-fill-light) {
        align-items: flex-start;
    }
    :deep(.w-\[467px\] .ml-3) {
        min-width: 0;
    }
    :deep(.w-\[467px\] .flex.items-center) {
        min-width: 0;
    }
    :deep(.max-w-\[400px\]) {
        max-width: 100%;
    }
}
.page-setting-block {
    padding: 0 4px 16px;
    & + .page-setting-block {
        padding-top: 16px;
        border-top: 1px solid var(--el-border-color-lighter);
    }
    .setting-title {
        display: flex;
        align-items: center;
        margin-bottom: 14px;
        color: var(--el-text-color-primary);
        font-size: 15px;
        font-weight: 600;
        &::before {
            content: '';
            width: 3px;
            height: 14px;
            margin-right: 8px;
            border-radius: 2px;
            background: var(--el-color-primary);
        }
    }
    :deep(.el-card) {
        border: 0;
        box-shadow: none;
    }
    :deep(.el-card__body) {
        padding: 0;
    }
    :deep(.el-form) {
        max-width: 100%;
    }
    :deep(.w-\[300px\]) {
        width: 100%;
    }
}
.source-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.side-title {
    font-weight: 600;
    margin-bottom: 10px;
}
.widget-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}
.widget-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    color: var(--el-text-color-secondary);
    border: 1px dashed var(--el-border-color);
    border-radius: 8px;
}
.widget-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    min-height: 72px;
    min-width: 0;
    padding: 8px 4px;
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
    cursor: pointer;
    font-size: 13px;
    text-align: center;
    span {
        max-width: 100%;
        line-height: 18px;
        overflow-wrap: anywhere;
    }
    small {
        color: var(--el-text-color-secondary);
        font-size: 11px;
    }
    &:hover {
        color: var(--el-color-primary);
        border-color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }
}
.widget-library-ghost {
    color: var(--el-color-primary);
    border-color: var(--el-color-primary);
    background: var(--el-color-primary-light-9);
    opacity: 0.7;
}
.editor-preview {
    min-width: 0;
    min-height: 0;
    height: 100%;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    display: flex;
    justify-content: center;
    :deep(.pages-preview-container) {
        width: 100%;
        max-width: 520px;
    }
    :deep(.pages-preview) {
        margin-left: 0;
        margin-right: 60px;
    }
}
</style>
