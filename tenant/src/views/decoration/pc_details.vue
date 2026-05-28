<template>
    <div class="pc-diy-editor" v-loading="loading">
        <header class="pc-diy-header">
            <div class="pc-diy-title">
                <el-button link @click="handleBack">
                    <icon name="el-icon-ArrowLeft" :size="18" />
                </el-button>
                <div class="min-w-0">
                    <div class="name">{{ pageForm.name || 'PC端装修' }}</div>
                    <div class="sub">
                        {{ pageForm.page_code || route.query.page_code || '-' }} · 草稿保存后发布模板生效
                    </div>
                </div>
            </div>
            <div class="pc-diy-tools">
                <el-select v-model="viewportWidth" class="w-[116px]" size="default">
                    <el-option label="1366 屏" :value="1366" />
                    <el-option label="1440 屏" :value="1440" />
                    <el-option label="1600 屏" :value="1600" />
                    <el-option label="1920 屏" :value="1920" />
                </el-select>
                <el-select v-model="zoom" class="w-[96px]" size="default">
                    <el-option label="50%" :value="0.5" />
                    <el-option label="60%" :value="0.6" />
                    <el-option label="70%" :value="0.7" />
                    <el-option label="80%" :value="0.8" />
                    <el-option label="90%" :value="0.9" />
                    <el-option label="100%" :value="1" />
                </el-select>
                <el-button @click="previewPage">预览</el-button>
                <el-button type="primary" @click="saveCurrentPage">保存草稿</el-button>
                <el-button type="warning" @click="publishTemplate">发布模板</el-button>
            </div>
        </header>

        <main class="pc-diy-main">
            <aside class="pc-diy-left">
                <el-tabs v-model="leftTab" stretch>
                    <el-tab-pane label="组件" name="widgets">
                        <div class="library-scroll">
                            <section
                                v-for="group in widgetGroups"
                                :key="group.key"
                                class="library-group"
                            >
                                <div class="group-title">{{ group.title }}</div>
                                <draggable
                                    :list="group.items"
                                    class="widget-grid"
                                    item-key="name"
                                    :group="{ name: 'decoration-widgets', pull: 'clone', put: false }"
                                    :sort="false"
                                    :clone="cloneWidgetFromLibrary"
                                    :animation="180"
                                    @start="isLibraryDragging = true"
                                    @end="handleLibraryDragEnd"
                                >
                                    <template #item="{ element: item }">
                                        <button
                                            class="widget-card"
                                            type="button"
                                            @click="handleWidgetClick(item.name)"
                                        >
                                            <icon :name="item.icon" :size="18" />
                                            <span>{{ item.title }}</span>
                                        </button>
                                    </template>
                                </draggable>
                            </section>
                        </div>
                    </el-tab-pane>
                    <el-tab-pane label="图层" name="layers">
                        <div class="layer-list">
                            <button
                                v-for="(item, index) in pageData"
                                :key="item.id || index"
                                class="layer-item"
                                :class="{ active: selectWidgetIndex === index }"
                                type="button"
                                @click="selectWidgetIndex = index"
                            >
                                <span class="layer-name">{{ item.title || item.name }}</span>
                                <span class="layer-actions">
                                    <el-button link @click.stop="toggleLock(index)">
                                        {{ layoutOf(item).locked ? '解锁' : '锁定' }}
                                    </el-button>
                                    <el-button link @click.stop="toggleHidden(index)">
                                        {{ isHidden(item) ? '显示' : '隐藏' }}
                                    </el-button>
                                </span>
                            </button>
                            <el-empty
                                v-if="!pageData.length"
                                description="暂无图层，先添加组件"
                                :image-size="82"
                            />
                        </div>
                    </el-tab-pane>
                </el-tabs>
            </aside>

            <section class="pc-diy-canvas-wrap">
                <div class="canvas-meta">
                    <span>PC端 DIY 画布 · 点击模块即可配置</span>
                    <span>视口 {{ viewportWidth }}px · 缩放 {{ Math.round(zoom * 100) }}%</span>
                </div>
                <pc-wysiwyg-preview
                    v-model="selectWidgetIndex"
                    :page-data="pageData"
                    :page-meta="pageMeta"
                    :resolved-sources="resolvedSources"
                    :canvas-width="viewportWidth"
                    :zoom="zoom"
                    :page-title="pageForm.name || 'PC端装修'"
                    @updatePageData="updatePageData"
                    @copyWidget="copyWidget"
                    @deleteWidget="deleteWidget"
                />
            </section>

            <aside class="pc-diy-right">
                <el-tabs v-model="rightTab" stretch>
                    <el-tab-pane label="内容" name="content">
                        <attr-setting
                            :widget="selectWidget"
                            type="pc"
                            @update:content="updateContent"
                        />
                    </el-tab-pane>
                    <el-tab-pane label="样式" name="style">
                        <div class="panel-scroll">
                            <el-form v-if="selectWidget" label-width="86px">
                                <el-form-item label="背景色">
                                    <color-picker
                                        v-model="selectWidget.styles.background"
                                        reset-color=""
                                    />
                                </el-form-item>
                                <el-form-item label="文字色">
                                    <color-picker v-model="selectWidget.styles.color" reset-color="" />
                                </el-form-item>
                                <el-form-item label="内边距">
                                    <el-input-number
                                        v-model="selectWidget.styles.padding"
                                        :min="0"
                                        :max="120"
                                    />
                                </el-form-item>
                                <el-form-item label="上外距">
                                    <el-input-number
                                        v-model="selectWidget.styles.margin_top"
                                        :min="0"
                                        :max="160"
                                    />
                                </el-form-item>
                                <el-form-item label="下外距">
                                    <el-input-number
                                        v-model="selectWidget.styles.margin_bottom"
                                        :min="0"
                                        :max="160"
                                    />
                                </el-form-item>
                                <el-form-item label="圆角">
                                    <el-input-number
                                        v-model="selectWidget.styles.border_radius"
                                        :min="0"
                                        :max="80"
                                    />
                                </el-form-item>
                                <el-form-item label="边框">
                                    <div class="inline-fields">
                                        <el-input-number
                                            v-model="selectWidget.styles.border_width"
                                            :min="0"
                                            :max="12"
                                        />
                                        <color-picker
                                            v-model="selectWidget.styles.border_color"
                                            reset-color="#ffffff"
                                        />
                                    </div>
                                </el-form-item>
                                <el-form-item label="阴影">
                                    <el-select v-model="selectWidget.styles.shadow" class="w-full">
                                        <el-option label="无" value="" />
                                        <el-option
                                            label="轻"
                                            value="0 8px 24px rgba(0,0,0,.12)"
                                        />
                                        <el-option
                                            label="中"
                                            value="0 18px 44px rgba(0,0,0,.22)"
                                        />
                                        <el-option
                                            label="重"
                                            value="0 28px 70px rgba(0,0,0,.32)"
                                        />
                                    </el-select>
                                </el-form-item>
                                <el-form-item label="透明度">
                                    <el-slider
                                        v-model="selectWidget.styles.opacity"
                                        :min="10"
                                        :max="100"
                                    />
                                </el-form-item>
                            </el-form>
                            <el-empty v-else description="请选择组件" />
                        </div>
                    </el-tab-pane>
                    <el-tab-pane label="布局" name="layout">
                        <div class="panel-scroll">
                            <el-form v-if="selectWidget" label-width="72px">
                                <el-form-item label="模式">
                                    <el-radio-group v-model="selectLayout.mode">
                                        <el-radio-button label="flow">区块流</el-radio-button>
                                        <el-radio-button label="free">自由</el-radio-button>
                                    </el-radio-group>
                                </el-form-item>
                                <el-form-item label="位置">
                                    <div class="inline-fields">
                                        <el-input-number v-model="selectLayout.x" :min="0" />
                                        <el-input-number v-model="selectLayout.y" :min="0" />
                                    </div>
                                </el-form-item>
                                <el-form-item label="尺寸">
                                    <div class="inline-fields">
                                        <el-input-number v-model="selectLayout.w" :min="80" />
                                        <el-input-number v-model="selectLayout.h" :min="40" />
                                    </div>
                                </el-form-item>
                                <el-form-item label="层级">
                                    <el-input-number v-model="selectLayout.z" :min="1" :max="999" />
                                </el-form-item>
                                <el-form-item label="网格">
                                    <el-input-number
                                        v-model="selectLayout.snap"
                                        :min="1"
                                        :max="64"
                                    />
                                </el-form-item>
                                <el-form-item label="状态">
                                    <div class="state-row">
                                        <el-checkbox v-model="selectLayout.locked">锁定</el-checkbox>
                                        <el-checkbox v-model="selectLayout.hidden">隐藏</el-checkbox>
                                    </div>
                                </el-form-item>
                                <el-form-item>
                                    <el-button @click="moveLayer('up')">上移</el-button>
                                    <el-button @click="moveLayer('down')">下移</el-button>
                                    <el-button @click="moveLayer('top')">置顶</el-button>
                                    <el-button @click="moveLayer('bottom')">置底</el-button>
                                </el-form-item>
                            </el-form>
                            <el-empty v-else description="请选择组件" />
                        </div>
                    </el-tab-pane>
                    <el-tab-pane label="数据" name="source">
                        <div class="panel-scroll">
                            <el-form v-if="selectWidget" label-width="88px">
                                <el-form-item label="数据源">
                                    <el-select
                                        v-model="selectWidget.content.source_key"
                                        clearable
                                        class="w-full"
                                    >
                                        <el-option
                                            v-for="source in dataSources"
                                            :key="source.key"
                                            :label="source.name"
                                            :value="source.key"
                                        />
                                    </el-select>
                                </el-form-item>
                                <el-form-item label="数量">
                                    <el-input-number
                                        v-model="sourceParams.limit"
                                        :min="1"
                                        :max="100"
                                    />
                                </el-form-item>
                                <el-form-item label="分类">
                                    <el-input
                                        v-model="sourceParams.category"
                                        placeholder="可选分类标识"
                                    />
                                </el-form-item>
                                <el-form-item label="排序">
                                    <el-select v-model="sourceParams.sort" class="w-full">
                                        <el-option label="默认" value="" />
                                        <el-option label="最新" value="latest" />
                                        <el-option label="热门" value="hot" />
                                        <el-option label="推荐" value="recommend" />
                                    </el-select>
                                </el-form-item>
                            </el-form>
                            <el-empty v-else description="请选择组件" />
                        </div>
                    </el-tab-pane>
                    <el-tab-pane label="规则" name="visibility">
                        <div class="panel-scroll">
                            <el-form v-if="selectWidget" label-width="90px">
                                <el-form-item label="显示方式">
                                    <el-select v-model="visibilityForm.mode" class="w-full">
                                        <el-option label="全部规则满足" value="all" />
                                        <el-option label="任一规则满足" value="any" />
                                    </el-select>
                                </el-form-item>
                                <el-form-item label="登录状态">
                                    <el-select
                                        v-model="visibilityForm.login_status"
                                        clearable
                                        class="w-full"
                                    >
                                        <el-option label="不限" value="" />
                                        <el-option label="已登录" value="logged_in" />
                                        <el-option label="未登录" value="guest" />
                                    </el-select>
                                </el-form-item>
                                <el-form-item label="会员状态">
                                    <el-select
                                        v-model="visibilityForm.membership_status"
                                        clearable
                                        class="w-full"
                                    >
                                        <el-option label="不限" value="" />
                                        <el-option label="会员" value="member" />
                                        <el-option label="非会员" value="non_member" />
                                    </el-select>
                                </el-form-item>
                                <el-form-item label="路径包含">
                                    <el-input
                                        v-model="visibilityForm.path"
                                        placeholder="例如 /ai"
                                    />
                                </el-form-item>
                                <el-form-item label="分桶">
                                    <div class="inline-fields">
                                        <el-input-number
                                            v-model="visibilityForm.bucket_start"
                                            :min="0"
                                            :max="99"
                                        />
                                        <el-input-number
                                            v-model="visibilityForm.bucket_end"
                                            :min="0"
                                            :max="99"
                                        />
                                    </div>
                                </el-form-item>
                            </el-form>
                            <el-empty v-else description="请选择组件" />
                        </div>
                    </el-tab-pane>
                    <el-tab-pane label="页面" name="page">
                        <div class="panel-scroll">
                            <el-form label-width="88px">
                                <el-form-item label="页面名称">
                                    <el-input v-model="pageForm.name" />
                                </el-form-item>
                                <el-form-item label="页面标识">
                                    <el-input v-model="pageForm.page_code" disabled />
                                </el-form-item>
                                <el-form-item label="页面状态">
                                    <el-switch
                                        v-model="pageForm.status"
                                        :active-value="1"
                                        :inactive-value="0"
                                    />
                                </el-form-item>
                                <el-form-item label="页面背景">
                                    <color-picker v-model="pageMetaContent.bg_color" reset-color="" />
                                </el-form-item>
                                <el-form-item label="背景图">
                                    <material-picker
                                        v-model="pageMetaContent.bg_image"
                                        :limit="1"
                                        size="80px"
                                    />
                                </el-form-item>
                                <el-form-item label="内容宽度">
                                    <el-input-number
                                        v-model="pageMetaContent.pc_width"
                                        :min="810"
                                        :max="1920"
                                    />
                                </el-form-item>
                                <el-form-item label="最小高度">
                                    <el-input-number
                                        v-model="pageMetaContent.pc_min_height"
                                        :min="480"
                                        :max="5000"
                                    />
                                </el-form-item>
                                <el-form-item>
                                    <el-button type="primary" @click="savePageBase">
                                        保存页面信息
                                    </el-button>
                                </el-form-item>
                            </el-form>
                        </div>
                    </el-tab-pane>
                </el-tabs>
            </aside>
        </main>
    </div>
</template>

<script lang="ts" setup name="decorationPcDetails">
import { ElMessage } from 'element-plus'
import { cloneDeep } from 'lodash-es'
import Draggable from 'vuedraggable'

import {
    editDecoratePage,
    getDecorateDataSources,
    getDecoratePages,
    publishDecorateTemplate,
    setDecoratePages
} from '@/api/decoration'
import feedback from '@/utils/feedback'
import { getNonDuplicateID } from '@/utils/util'

import AttrSetting from './component/pages/attr-setting.vue'
import PcWysiwygPreview from './component/pages/preview-pc-wysiwyg.vue'
import widgets from './component/widgets'
import { createDefaultPcWidget, pcWidgetDefinitions } from '@decoration-core'
import {
    getPcPreviewUrl,
    getPcWidgetLayout,
    normalizePcHomePageData,
    normalizePcPageData,
    normalizePcPageMeta,
    normalizePcWidget,
    safeParseJson
} from './utils/pc'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const templateId = computed(() => Number(route.query.template_id || route.query.id || 0))
const pageId = computed(() => Number(route.query.page_id || 0))
const pageInfo = ref<any>({})
const pageData = ref<any[]>([])
const pageMeta = ref<any[]>([])
const selectWidgetIndex = ref(0)
const viewportWidth = ref(1366)
const zoom = ref(0.75)
const leftTab = ref('widgets')
const rightTab = ref('content')
const isLibraryDragging = ref(false)
const dataSources = ref<any[]>([])
const resolvedSources = ref<Record<string, any>>({})
const pageForm = reactive<any>({
    id: 0,
    name: '',
    page_code: '',
    status: 1,
    sort: 0
})
const pageMetaContent = reactive<any>({})
const visibilityForm = reactive<any>({
    mode: 'all',
    login_status: '',
    membership_status: '',
    path: '',
    bucket_start: 0,
    bucket_end: 99
})

const categoryText: Record<string, string> = {
    pc: 'AI业务组件',
    basic: '基础元素',
    media: '媒体组件',
    nav: '导航组件',
    navigation: '导航组件',
    marketing: '营销展示',
    container: '容器布局'
}

const availableWidgets = computed(() =>
    pcWidgetDefinitions.map((item) => ({
        name: item.name,
        title: item.title,
        icon: item.icon,
        category: item.category,
        terminal: item.terminal
    }))
)

const widgetGroups = computed(() => {
    const groups: Record<string, any[]> = {}
    availableWidgets.value.forEach((item) => {
        const key = item.category || 'basic'
        if (!groups[key]) groups[key] = []
        groups[key].push(item)
    })
    return Object.keys(groups).map((key) => ({
        key,
        title: categoryText[key] || '其它组件',
        items: groups[key]
    }))
})

const selectWidget = computed(() => pageData.value[selectWidgetIndex.value])
const selectLayout = computed({
    get: () => {
        if (!selectWidget.value) return {}
        const layout = getPcWidgetLayout(selectWidget.value, selectWidgetIndex.value)
        selectWidget.value.styles = {
            ...(selectWidget.value.styles || {}),
            layout
        }
        return selectWidget.value.styles.layout
    },
    set: (value) => patchSelectedLayout(value)
})
const sourceParams = computed({
    get: () => {
        if (!selectWidget.value) return {}
        if (!selectWidget.value.content) selectWidget.value.content = {}
        if (!selectWidget.value.content.source_params) {
            selectWidget.value.content.source_params = {}
        }
        return selectWidget.value.content.source_params
    },
    set: (value) => {
        if (!selectWidget.value) return
        selectWidget.value.content.source_params = value || {}
    }
})
const syncVisibilityForm = () => {
    const visibility = selectWidget.value?.visibility || { mode: 'all', rules: [] }
    const rules = visibility.rules || []
    const byField = (field: string) => rules.find((item: any) => item.field === field)?.value
    const bucket = byField('user_bucket') || {}
    Object.assign(visibilityForm, {
        mode: visibility.mode || 'all',
        login_status: byField('login_status') || '',
        membership_status: byField('membership_status') || '',
        path: byField('path') || '',
        bucket_start: bucket.start ?? 0,
        bucket_end: bucket.end ?? 99
    })
}

const writeVisibilityRules = () => {
    if (!selectWidget.value) return
    const rules = []
    if (visibilityForm.login_status) {
        rules.push({ field: 'login_status', op: 'eq', value: visibilityForm.login_status })
    }
    if (visibilityForm.membership_status) {
        rules.push({
            field: 'membership_status',
            op: 'eq',
            value: visibilityForm.membership_status
        })
    }
    if (visibilityForm.path) {
        rules.push({ field: 'path', op: 'contains', value: visibilityForm.path })
    }
    if (visibilityForm.bucket_start !== 0 || visibilityForm.bucket_end !== 99) {
        rules.push({
            field: 'user_bucket',
            op: 'between',
            value: { start: visibilityForm.bucket_start, end: visibilityForm.bucket_end }
        })
    }
    selectWidget.value.visibility = { mode: visibilityForm.mode || 'all', rules }
}

const assignReactive = (target: Record<string, any>, source: Record<string, any>) => {
    Object.keys(target).forEach((key) => delete target[key])
    Object.assign(target, cloneDeep(source || {}))
}

const syncPageForm = () => {
    Object.assign(pageForm, {
        id: pageInfo.value?.id || pageId.value,
        name: pageInfo.value?.name || '',
        page_code: pageInfo.value?.page_code || String(route.query.page_code || ''),
        status: Number(pageInfo.value?.status ?? 1),
        sort: Number(pageInfo.value?.sort || 0)
    })
}

const syncPageMeta = () => {
    pageMeta.value = normalizePcPageMeta(safeParseJson(pageInfo.value?.draft_meta || pageInfo.value?.meta, null))
    const content = pageMeta.value?.[0]?.content || {}
    const legacyLightBg = ['#f6f8ff', '#F6F8FF', '#eef1f6', '#EEF1F6'].includes(String(content.bg_color || ''))
    const normalizedPcWidth = Math.max(1366, Math.min(1920, Number(content.pc_width || 1440)))
    assignReactive(pageMetaContent, {
        ...content,
        bg_type: content.bg_type || (content.bg_image ? '2' : content.bg_color ? '1' : '1'),
        bg_color: legacyLightBg ? '#050505' : content.bg_color || '#050505',
        bg_image: content.bg_image || '',
        pc_width: normalizedPcWidth,
        pc_min_height: Number(content.pc_min_height || 1080)
    })
    viewportWidth.value = normalizedPcWidth
}

const writePageMeta = () => {
    const next = normalizePcPageMeta(pageMeta.value)
    next[0].content = {
        ...(next[0].content || {}),
        ...cloneDeep(pageMetaContent),
        render_mode: 'pc_diy_v2',
        layout_scope: 'full_page',
        layout_mode: pageForm.page_code === 'pc_home' ? 'workspace' : 'plain',
        bg_type: pageMetaContent.bg_image ? '2' : pageMetaContent.bg_color ? '1' : '0'
    }
    pageMeta.value = next
}

const getData = async () => {
    if (!templateId.value || !pageId.value) {
        ElMessage.error('缺少 PC 装修页面参数')
        return
    }
    loading.value = true
    try {
        const data = await getDecoratePages({
            template_id: templateId.value,
            id: pageId.value,
            terminal: 'pc'
        })
        pageInfo.value = data || {}
        resolvedSources.value = safeParseJson(data?.resolved_sources, {})
        syncPageForm()
        const rawPageData = safeParseJson(data?.draft_data || data?.data, [])
        pageData.value = pageForm.page_code === 'pc_home'
            ? normalizePcHomePageData(rawPageData)
            : normalizePcPageData(rawPageData)
        syncPageMeta()
        const first = pageData.value.findIndex((item) => !item.disabled)
        selectWidgetIndex.value = first >= 0 ? first : 0
        syncVisibilityForm()
        if (!dataSources.value.length) {
            dataSources.value = await getDecorateDataSources({ terminal: 'pc' })
        }
    } finally {
        loading.value = false
    }
}

const updatePageData = (value: any[]) => {
    pageData.value = normalizePcPageData(value || [])
}

const createWidgetData = (name: string, index = pageData.value.length) =>
    normalizePcWidget(
        {
            id: getNonDuplicateID(),
            ...(createDefaultPcWidget(name) || widgets[name]?.options?.() || {})
        },
        index
    )

const cloneWidgetFromLibrary = (item: any) => createWidgetData(item.name)

const handleLibraryDragEnd = () => {
    setTimeout(() => {
        isLibraryDragging.value = false
    }, 0)
}

const handleWidgetClick = (name: string) => {
    if (isLibraryDragging.value) return
    const widget = createWidgetData(name)
    pageData.value.push(widget)
    selectWidgetIndex.value = pageData.value.length - 1
    rightTab.value = 'content'
}

const updateContent = (content: any) => {
    if (!selectWidget.value) return
    selectWidget.value.content = content || {}
}

const patchSelectedLayout = (value: any) => {
    if (!selectWidget.value) return
    selectWidget.value.styles = {
        ...(selectWidget.value.styles || {}),
        layout: {
            ...getPcWidgetLayout(selectWidget.value, selectWidgetIndex.value),
            ...(value || {})
        }
    }
}

const layoutOf = (item: any) => getPcWidgetLayout(item)
const isHidden = (item: any) => item?.content?.enabled === 0 || layoutOf(item).hidden

const toggleHidden = (index: number) => {
    const item = pageData.value[index]
    if (!item) return
    const layout = getPcWidgetLayout(item, index)
    item.styles = { ...(item.styles || {}), layout: { ...layout, hidden: !layout.hidden } }
}

const toggleLock = (index: number) => {
    const item = pageData.value[index]
    if (!item) return
    const layout = getPcWidgetLayout(item, index)
    item.styles = { ...(item.styles || {}), layout: { ...layout, locked: !layout.locked } }
}

const copyWidget = (index: number) => {
    const item = pageData.value[index]
    if (!item) return
    const copy = normalizePcWidget(cloneDeep(item), index + 1)
    copy.id = getNonDuplicateID()
    copy.styles.layout.x += 24
    copy.styles.layout.y += 24
    pageData.value.splice(index + 1, 0, copy)
    selectWidgetIndex.value = index + 1
}

const deleteWidget = async (index: number) => {
    const item = pageData.value[index]
    if (!item || item.disabled) return
    await feedback.confirm('确认删除当前组件？')
    pageData.value.splice(index, 1)
    selectWidgetIndex.value = Math.min(index, pageData.value.length - 1)
}

const moveLayer = (type: 'up' | 'down' | 'top' | 'bottom') => {
    if (!selectWidget.value) return
    const layout = getPcWidgetLayout(selectWidget.value, selectWidgetIndex.value)
    const zValues = pageData.value.map((item) => getPcWidgetLayout(item).z)
    const minZ = Math.min(1, ...zValues)
    const maxZ = Math.max(1, ...zValues)
    const map: Record<string, number> = {
        up: layout.z + 1,
        down: Math.max(1, layout.z - 1),
        top: maxZ + 1,
        bottom: minZ
    }
    patchSelectedLayout({ z: map[type] })
}

const savePageBase = async () => {
    await editDecoratePage({
        template_id: templateId.value,
        ...pageForm
    })
    ElMessage.success('页面信息已保存')
}

const saveCurrentPage = async (showMessage = true) => {
    writePageMeta()
    await setDecoratePages({
        template_id: templateId.value,
        id: pageId.value,
        name: pageForm.name || pageInfo.value?.name,
        data: JSON.stringify(normalizePcPageData(pageData.value)),
        meta: JSON.stringify(pageMeta.value)
    })
    if (showMessage) ElMessage.success('PC装修草稿已保存')
}

const publishTemplate = async () => {
    await saveCurrentPage(false)
    await publishDecorateTemplate({ id: templateId.value })
    ElMessage.success('发布成功，PC端已生效')
}

const previewPage = async () => {
    await saveCurrentPage(false)
    const page = {
        ...pageInfo.value,
        id: pageId.value,
        page_code: pageForm.page_code || route.query.page_code
    }
    window.open(getPcPreviewUrl(page, templateId.value), '_blank')
}

const handleBack = async () => {
    await feedback.confirm('确定离开此页面？系统可能不会保存您所做的更改。')
    router.back()
}

watch(
    () => pageMetaContent,
    () => {
        pageMetaContent.pc_width = Number(pageMetaContent.pc_width || viewportWidth.value || 2048)
        writePageMeta()
    },
    { deep: true }
)

watch(viewportWidth, (value) => {
    pageMetaContent.pc_width = value
})

watch(selectWidgetIndex, () => {
    if (rightTab.value === 'page') rightTab.value = 'content'
    syncVisibilityForm()
})

watch(
    () => visibilityForm,
    () => {
        writeVisibilityRules()
    },
    { deep: true }
)

onMounted(getData)
</script>

<style scoped lang="scss">
.pc-diy-editor {
    height: 100vh;
    min-width: 1180px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    background: #eef1f6;
    color: #273142;
}
.pc-diy-header {
    height: 64px;
    flex: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 0 18px;
    border-bottom: 1px solid #dfe4ee;
    background: #fff;
}
.pc-diy-title {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    .name {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }
    .sub {
        margin-top: 2px;
        font-size: 12px;
        color: #7b8497;
    }
}
.pc-diy-tools {
    display: flex;
    align-items: center;
    gap: 10px;
}
.pc-diy-main {
    flex: 1;
    min-height: 0;
    display: grid;
    grid-template-columns: 220px minmax(620px, 1fr) 320px;
    gap: 10px;
    padding: 10px;
}
.pc-diy-left,
.pc-diy-right {
    min-height: 0;
    border: 1px solid #dfe4ee;
    border-radius: 8px;
    background: #fff;
    overflow: hidden;
    :deep(.el-tabs) {
        height: 100%;
        display: flex;
        flex-direction: column;
    }
    :deep(.el-tabs__content) {
        flex: 1;
        min-height: 0;
        overflow: hidden;
    }
    :deep(.el-tab-pane) {
        height: 100%;
    }
}
.library-scroll,
.panel-scroll,
.layer-list {
    height: 100%;
    overflow-y: auto;
    padding: 12px;
}
.library-group + .library-group {
    margin-top: 16px;
}
.group-title {
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #303747;
}
.widget-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 7px;
}
.widget-card {
    min-height: 66px;
    border: 1px solid #e4e9f2;
    border-radius: 8px;
    background: #f8fafc;
    color: #313949;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 12px;
    &:hover {
        border-color: var(--el-color-primary);
        color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }
}
.layer-item {
    width: 100%;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 10px;
    border: 1px solid #e4e9f2;
    border-radius: 8px;
    background: #fff;
    cursor: pointer;
    & + .layer-item {
        margin-top: 8px;
    }
    &.active {
        border-color: var(--el-color-primary);
        background: var(--el-color-primary-light-9);
    }
}
.layer-name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #273142;
}
.layer-actions {
    display: flex;
    flex: none;
}
.pc-diy-canvas-wrap {
    min-width: 0;
    min-height: 0;
    display: flex;
    flex-direction: column;
    border: 1px solid #dfe4ee;
    border-radius: 8px;
    background: #f4f6fa;
    overflow: hidden;
}
.canvas-meta {
    flex: none;
    height: 42px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 14px;
    border-bottom: 1px solid #dfe4ee;
    background: #fff;
    color: #778196;
    font-size: 12px;
}
.inline-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
    width: 100%;
}
.state-row {
    display: flex;
    align-items: center;
    gap: 14px;
}
:deep(.pages-setting) {
    height: 100%;
}
:deep(.pages-setting > .el-card:first-child) {
    display: none;
}
:deep(.pages-setting > .el-scrollbar) {
    height: 100% !important;
}
:deep(.el-form-item__content) {
    min-width: 0;
}
</style>
