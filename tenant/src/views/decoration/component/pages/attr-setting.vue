<template>
    <div class="pages-setting">
        <el-card shadow="never" class="!border-none flex">
            <div
                class="title flex items-center before:w-[3px] before:h-[14px] before:block before:bg-primary before:mr-2 text-xl font-medium"
            >
                {{ widget?.title }}
            </div>
        </el-card>
        <el-scrollbar class="w-full" style="height: calc(100% - 60px)">
            <keep-alive>
                <component
                    v-if="widgets[widget?.name]?.attr"
                    :is="widgets[widget?.name]?.attr"
                    :content="widget?.content"
                    :styles="widget?.styles"
                    :type="type"
                    @update:content="handleUpdateContent"
                />
                <el-form
                    v-else-if="type === 'pc' && widget?.content"
                    class="px-4 py-3"
                    label-width="86px"
                >
                    <template v-if="widget?.name === 'pc-home-hero-grid'">
                        <div class="setting-section-title with-action">
                            <span>首页轮播</span>
                            <el-button link type="primary" @click="addHeroBanner">添加</el-button>
                        </div>
                        <div
                            v-for="(banner, index) in draftContent.banners"
                            :key="banner.id || index"
                            class="pc-entry-config"
                        >
                            <div class="config-row-title">
                                <span>轮播{{ index + 1 }}</span>
                                <span>
                                    <el-button link :disabled="index === 0" @click="moveHeroBanner(index, -1)">
                                        上移
                                    </el-button>
                                    <el-button
                                        link
                                        :disabled="index === draftContent.banners.length - 1"
                                        @click="moveHeroBanner(index, 1)"
                                    >
                                        下移
                                    </el-button>
                                    <el-button
                                        link
                                        type="danger"
                                        :disabled="draftContent.banners.length <= 1"
                                        @click="removeHeroBanner(index)"
                                    >
                                        删除
                                    </el-button>
                                </span>
                            </div>
                            <el-form-item label="标题">
                                <el-input v-model="banner.title" @input="emitDraft" />
                            </el-form-item>
                            <el-form-item label="描述">
                                <el-input
                                    v-model="banner.description"
                                    type="textarea"
                                    :rows="2"
                                    @input="emitDraft"
                                />
                            </el-form-item>
                            <el-form-item label="封面">
                                <material-picker
                                    v-model="banner.image"
                                    :limit="1"
                                    size="84px"
                                    @update:model-value="emitDraft"
                                />
                            </el-form-item>
                            <el-form-item label="跳转">
                                <el-input
                                    v-model="banner.link.path"
                                    placeholder="/ai/tools"
                                    @input="emitDraft"
                                />
                            </el-form-item>
                        </div>
                        <div class="setting-section-title">快捷入口</div>
                        <div
                            v-for="(entry, index) in draftContent.features"
                            :key="index"
                            class="pc-entry-config"
                        >
                            <el-form-item :label="`入口${index + 1}`">
                                <el-input v-model="entry.title" @input="emitDraft" />
                            </el-form-item>
                            <el-form-item label="描述">
                                <el-input v-model="entry.description" @input="emitDraft" />
                            </el-form-item>
                            <el-form-item label="跳转">
                                <el-input v-model="entry.link.path" @input="emitDraft" />
                            </el-form-item>
                        </div>
                    </template>
                    <template v-else-if="widget?.name === 'pc-tool-carousel'">
                        <el-form-item label="标题">
                            <el-input v-model="draftContent.title" placeholder="热门工具" @input="emitDraft" />
                        </el-form-item>
                        <el-form-item label="数量">
                            <el-input-number
                                v-model="draftContent.source_params.limit"
                                :min="1"
                                :max="30"
                                @change="emitDraft"
                            />
                        </el-form-item>
                        <el-form-item label="数据源">
                            <el-input
                                v-model="draftContent.source_key"
                                placeholder="ai_tools"
                                @input="emitDraft"
                            />
                        </el-form-item>
                    </template>
                    <template v-else-if="widget?.name === 'pc-case-feed'">
                        <el-form-item label="标题">
                            <el-input v-model="draftContent.title" placeholder="案例展示" @input="emitDraft" />
                        </el-form-item>
                        <el-form-item label="数量">
                            <el-input-number
                                v-model="draftContent.source_params.limit"
                                :min="1"
                                :max="60"
                                @change="emitDraft"
                            />
                        </el-form-item>
                        <el-form-item label="分类">
                            <el-input
                                v-model="caseTabText"
                                placeholder="图片,视频,数字人"
                                @input="emitCaseTabs"
                            />
                        </el-form-item>
                    </template>
                    <template v-else>
                        <el-form-item label="标题">
                            <el-input v-model="draftContent.title" placeholder="组件标题" @input="emitDraft" />
                        </el-form-item>
                        <el-form-item label="描述">
                            <el-input
                                v-model="draftContent.description"
                                type="textarea"
                                placeholder="组件描述"
                                @input="emitDraft"
                            />
                        </el-form-item>
                        <el-form-item v-if="'text' in draftContent" label="文本">
                            <el-input v-model="draftContent.text" type="textarea" @input="emitDraft" />
                        </el-form-item>
                        <el-form-item label="跳转">
                            <el-input
                                v-model="draftLinkPath"
                                placeholder="/ai/tools"
                                @input="emitDraft"
                            />
                        </el-form-item>
                    </template>
                    <el-alert
                        title="PC 装修组件会直接使用前台共享渲染器，更多展示项可在样式、布局和数据面板继续配置。"
                        type="info"
                        :closable="false"
                    />
                </el-form>
                <el-form v-else-if="widget?.content" class="px-4 py-3" label-width="86px">
                    <el-form-item label="标题">
                        <el-input v-model="draftContent.title" placeholder="组件标题" @input="emitDraft" />
                    </el-form-item>
                    <el-form-item label="描述">
                        <el-input
                            v-model="draftContent.description"
                            type="textarea"
                            placeholder="组件描述"
                            @input="emitDraft"
                        />
                    </el-form-item>
                    <el-form-item v-if="'text' in draftContent" label="文本">
                        <el-input v-model="draftContent.text" type="textarea" @input="emitDraft" />
                    </el-form-item>
                    <el-form-item label="跳转">
                        <el-input
                            v-model="draftLinkPath"
                            placeholder="/ai/tools"
                            @input="emitDraft"
                        />
                    </el-form-item>
                    <el-alert
                        title="此组件使用共享装修协议，更多专属字段可在样式、布局、数据面板配置。"
                        type="info"
                        :closable="false"
                    />
                </el-form>
                <el-empty v-else description="当前组件暂无可配置属性" />
            </keep-alive>
        </el-scrollbar>
    </div>
</template>
<script lang="ts" setup>
import type { PropType } from 'vue'

import widgets from '../widgets'

const emits = defineEmits(['update:content'])
const handleUpdateContent = (data: any) => {
    emits('update:content', data)
}

const props = defineProps({
    widget: {
        type: Object as PropType<Record<string, any>>,
        default: () => ({})
    },
    type: {
        type: String as PropType<'mobile' | 'pc'>,
        default: 'mobile'
    }
})

const draftContent = reactive<any>({})
const draftLinkPath = ref('')
const caseTabText = ref('')
const createHeroBanner = (index = 0) => ({
    id: `hero_banner_${Date.now()}_${Math.random().toString(16).slice(2, 7)}`,
    title: ['AI 创作介绍', 'AI 工具合集', '灵感案例广场'][index] || '新轮播',
    description: ['模型、应用、资产与灵感统一入口', '热门 AI 能力一站式直达', '发现案例，一键做同款'][index] || '',
    image: '',
    link: { path: ['/ai/tools', '/ai/create', '/ai'][index] || '/ai/tools' }
})
const normalizePcDraftContent = () => {
    if (props.type !== 'pc') return
    if (props.widget?.name === 'pc-home-hero-grid') {
        if (!Array.isArray(draftContent.banners) || !draftContent.banners.length) {
            draftContent.banners = [createHeroBanner(0)]
        }
        draftContent.banners.forEach((item: any) => {
            if (!item.link) item.link = { path: '' }
        })
        if (!Array.isArray(draftContent.features) || !draftContent.features.length) {
            draftContent.features = [
                { title: 'AI TV', description: '', link: { path: '/ai/create' } },
                { title: '视频生成', description: '', link: { path: '/ai/create?type=video' } },
                { title: '图片生成', description: '', link: { path: '/ai/create?type=image' } }
            ]
        }
        draftContent.features.forEach((item: any) => {
            if (!item.link) item.link = { path: '' }
        })
    }
    if (['pc-tool-carousel', 'pc-case-feed'].includes(props.widget?.name || '')) {
        if (!draftContent.source_params) draftContent.source_params = {}
    }
    if (props.widget?.name === 'pc-case-feed') {
        if (!Array.isArray(draftContent.tabs)) draftContent.tabs = []
        caseTabText.value = draftContent.tabs.map((item: any) => item.name || item.title || item.key).filter(Boolean).join(',')
    }
}
const addHeroBanner = () => {
    draftContent.banners.push(createHeroBanner(draftContent.banners.length))
    emitDraft()
}
const removeHeroBanner = (index: number) => {
    if (draftContent.banners.length <= 1) return
    draftContent.banners.splice(index, 1)
    emitDraft()
}
const moveHeroBanner = (index: number, offset: number) => {
    const nextIndex = index + offset
    if (nextIndex < 0 || nextIndex >= draftContent.banners.length) return
    const next = draftContent.banners[index]
    draftContent.banners[index] = draftContent.banners[nextIndex]
    draftContent.banners[nextIndex] = next
    emitDraft()
}
const syncDraft = () => {
    Object.keys(draftContent).forEach((key) => delete draftContent[key])
    Object.assign(draftContent, JSON.parse(JSON.stringify(props.widget?.content || {})))
    normalizePcDraftContent()
    draftLinkPath.value = draftContent.link?.path || draftContent.primary_link?.path || ''
}
const emitDraft = () => {
    const next = JSON.parse(JSON.stringify(draftContent))
    if (draftLinkPath.value) {
        next.link = {
            ...(next.link || {}),
            path: draftLinkPath.value
        }
    }
    emits('update:content', next)
}
const emitCaseTabs = () => {
    draftContent.tabs = String(caseTabText.value || '')
        .split(/[,，]/)
        .map((item: string) => item.trim())
        .filter(Boolean)
        .map((name: string, index: number) => ({ key: `tab_${index}`, name }))
    emitDraft()
}
watch(
    () => props.widget,
    () => syncDraft(),
    { immediate: true, deep: true }
)
</script>
<style scoped lang="scss">
.setting-section-title {
    margin: 8px 0 14px;
    color: var(--el-text-color-primary);
    font-size: 14px;
    font-weight: 600;
    &.with-action {
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
}
.config-row-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
    color: var(--el-text-color-primary);
    font-size: 13px;
    font-weight: 600;
}
.pc-entry-config {
    padding: 10px 0 2px;
    border-top: 1px solid var(--el-border-color-lighter);
}
</style>
