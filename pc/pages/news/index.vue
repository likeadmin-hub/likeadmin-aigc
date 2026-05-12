<template>
    <div class="news-list-page">
        <MarketingHeader />

        <main class="news-list">
            <div class="news-list__shell">
                <header class="news-list__hero">
                    <div class="news-list__eyebrow">A.PART Editorial</div>
                    <h1 class="news-list__title">
                        <span v-if="keywordText">
                            查找 &laquo;{{ keywordText }}&raquo;
                        </span>
                        <span v-else>{{ pageTitle }}</span>
                        <span
                            v-if="!pending && totalCount != null"
                            class="news-list__title-count"
                        >
                            ({{ totalCount }})
                        </span>
                    </h1>
                    <p class="news-list__subtitle">{{ pageSubtitle }}</p>
                </header>

                <!-- 分类 Tab：底部长细线与选中项粗下划线平行 -->
                <div class="news-list__toolbar">
                    <div class="news-list__sort-row">
                        <nav
                            class="news-list__sort news-list__sort--merged"
                            role="tablist"
                            aria-label="排序与分类"
                        >
                            <NuxtLink
                                :to="sortHref('hot')"
                                class="news-list__sort-btn"
                                :class="{ 'is-active': navActiveKey === 'hot' }"
                                role="tab"
                                :aria-selected="navActiveKey === 'hot'"
                            >
                                热门
                            </NuxtLink>
                            <NuxtLink
                                :to="sortHref('new')"
                                class="news-list__sort-btn"
                                :class="{ 'is-active': navActiveKey === 'new' }"
                                role="tab"
                                :aria-selected="navActiveKey === 'new'"
                            >
                                最新
                            </NuxtLink>
                            <NuxtLink
                                :to="allCategoriesHref"
                                class="news-list__sort-btn"
                                :class="{ 'is-active': navActiveKey === 'all' }"
                                role="tab"
                                :aria-selected="navActiveKey === 'all'"
                            >
                                全部
                            </NuxtLink>
                            <NuxtLink
                                v-for="c in articleCates"
                                :key="`c-${c.id}`"
                                :to="cateHref(c)"
                                class="news-list__sort-btn"
                                :class="{
                                    'is-active': navActiveKey === navCateKey(c.id)
                                }"
                                role="tab"
                                :aria-selected="navActiveKey === navCateKey(c.id)"
                            >
                                {{ c.name }}
                            </NuxtLink>
                        </nav>
                    </div>
                </div>

                <div
                    v-loading="pending"
                    element-loading-text="加载中"
                    class="news-list__body"
                >
                    <section
                        v-if="!pending && data?.lists?.length"
                        class="news-list__grid"
                        aria-label="文章列表"
                    >
                        <NuxtLink
                            v-for="item in data.lists"
                            :key="item.id"
                            class="nl-card"
                            :to="`/news/${item.id}`"
                        >
                            <div class="nl-card__media">
                                <img
                                    class="nl-card__image"
                                    :src="coverSrc(item)"
                                    alt=""
                                    loading="lazy"
                                    @error="(e) => onCoverError(e, item)"
                                />
                                <span
                                    v-if="navActiveKey === 'hot'"
                                    class="nl-card__tag"
                                >
                                    HOT
                                </span>
                            </div>
                            <div class="nl-card__body">
                                <div class="nl-card__meta">
                                    <time class="nl-card__date">{{
                                        formatDate(item.create_time)
                                    }}</time>
                                    <span
                                        v-if="cateLabel(item)"
                                        class="nl-card__meta-sep"
                                        aria-hidden="true"
                                    >—</span>
                                    <span v-if="cateLabel(item)" class="nl-card__cate">
                                        {{ cateLabel(item) }}
                                    </span>
                                </div>
                                <h2 class="nl-card__title">{{ item.title }}</h2>
                                <p v-if="item.desc" class="nl-card__excerpt">
                                    {{ excerpt(String(item.desc)) }}
                                </p>
                                <div class="nl-card__footer">
                                    <span class="nl-read">阅读全文</span>
                                    <span class="nl-card__views">
                                        <el-icon><View /></el-icon>
                                        {{ formatCompact(item.click) }}
                                    </span>
                                </div>
                            </div>
                        </NuxtLink>
                    </section>

                    <div
                        v-if="!pending && data?.lists?.length && showListPagination"
                        class="news-list__pagination"
                    >
                        <el-pagination
                            v-model:current-page="params.page_no"
                            :total="data.count"
                            :page-size="params.page_size"
                            :background="false"
                            layout="prev, pager, next, jumper"
                            hide-on-single-page
                            @current-change="refresh()"
                        />
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<script lang="ts" setup>
import { computed, watch } from 'vue'
import { ElPagination, ElIcon } from 'element-plus'
import { View } from '@element-plus/icons-vue'
import { homeCoverFallbackById } from '@/constants/article-cover-fallbacks'
import { getArticleCate, getArticleList } from '~~/api/news'
import { normalizeFileUrl } from '@/utils/file-url'

definePageMeta({
    layout: 'blank'
})

type SortKey = 'hot' | 'new' | 'default'

const route = useRoute()

const currentSort = computed<SortKey>(() => {
    const s = typeof route.query.sort === 'string' ? route.query.sort : 'hot'
    if (s === 'new') return 'new'
    if (s === 'default') return 'default'
    return 'hot'
})

/** 顶栏单选：无分类时为 hot / new / all；有分类时仅该分类一项为高亮 */
type NavKey = 'hot' | 'new' | 'all' | `cate:${string}`

const navActiveKey = computed<NavKey>(() => {
    if (currentCid.value) return `cate:${currentCid.value}`
    if (currentSort.value === 'default') return 'all'
    return currentSort.value === 'new' ? 'new' : 'hot'
})

const navCateKey = (id: number | string) => `cate:${String(id)}` as const

const keywordText = computed(() =>
    typeof route.query.keywords === 'string' ? route.query.keywords : ''
)

const currentCid = computed(() =>
    route.query.cid != null && route.query.cid !== '' ? String(route.query.cid) : ''
)

const currentCateName = computed(() =>
    typeof route.query.name === 'string' ? route.query.name : ''
)

const pageTitle = computed(() => {
    if (currentCateName.value) return currentCateName.value
    if (currentSort.value === 'hot') return '热门资讯'
    if (currentSort.value === 'new') return '最新资讯'
    return '全部资讯'
})

const pageSubtitle = computed(() => {
    if (keywordText.value) return '基于关键词匹配的相关资讯'
    if (currentCateName.value) return '此频道下的最新文章与动态'
    if (currentSort.value === 'hot') return '近期阅读量最高的资讯精选'
    if (currentSort.value === 'new') return '刚刚更新的最新资讯'
    return '设计、产品、AI 与前端趋势精选'
})

/** 卡片上展示的分类名：列表接口带 cate_name，筛选频道时用 query.name */
const cateLabel = (item: Record<string, unknown>) => {
    const fromItem = item.cate_name
    if (typeof fromItem === 'string' && fromItem.trim()) return fromItem.trim()
    if (currentCateName.value) return currentCateName.value
    return ''
}

const { data: cateListData } = await useAsyncData(
    'news-list-article-cates',
    () => getArticleCate(),
    { default: () => [] }
)

type ArticleCateRow = { id: number | string; name: string }

const articleCates = computed<ArticleCateRow[]>(() => {
    const raw = cateListData.value as unknown
    if (!Array.isArray(raw)) return []
    return raw.filter(
        (x): x is ArticleCateRow =>
            x != null &&
            typeof x === 'object' &&
            'id' in x &&
            'name' in x &&
            String((x as ArticleCateRow).name).trim() !== ''
    ) as ArticleCateRow[]
})

const params = reactive({
    page_no: 1,
    page_size: 36,
    keyword: keywordText,
    cid: currentCid,
    sort: currentSort
})

const { data, refresh, pending } = await useAsyncData(
    () => getArticleList(params),
    {
        initialCache: false
    }
)

const totalCount = computed(() => data.value?.count ?? 0)

const showListPagination = computed(() => {
    const count = data.value?.count
    if (count == null) return false
    const n = Number(count)
    if (!Number.isFinite(n) || n <= 0) return false
    return Math.ceil(n / params.page_size) > 1
})

useHead({
    title: () => {
        if (keywordText.value) return `${keywordText.value} - 资讯搜索`
        return `${pageTitle.value} - 资讯`
    }
})

watch(
    [
        () => route.query.keywords,
        () => route.query.cid,
        () => route.query.name,
        () => route.query.sort
    ],
    () => {
        params.page_no = 1
        refresh()
    }
)

const buildSortQuery = (sort: SortKey) => {
    const q: Record<string, string> = {}
    if (keywordText.value) q.keywords = keywordText.value
    if (sort !== 'hot') q.sort = sort
    return q
}

/** 切换热门/最新/全部时去掉分类，仅保留搜索词（顶栏单选） */
const sortHref = (sort: SortKey) => {
    return {
        path: '/news',
        query: buildSortQuery(sort)
    }
}

/** 「全部」：后台 default 排序 + 不按分类；与热门/最新互斥 */
const allCategoriesHref = computed(() => {
    return {
        path: '/news',
        query: buildSortQuery('default')
    }
})

const cateHref = (c: ArticleCateRow) => ({
    path: '/news',
    query: {
        ...buildSortQuery(currentSort.value),
        cid: String(c.id),
        name: c.name
    }
})

const articleImage = (article: Record<string, unknown>) => {
    const raw = article?.image
    return normalizeFileUrl(
        typeof raw === 'string' ? raw : raw != null ? String(raw) : ''
    )
}

const coverSrc = (article: Record<string, unknown>) =>
    articleImage(article) || homeCoverFallbackById(article.id)

const onCoverError = (e: Event, article: Record<string, unknown>) => {
    const el = e.target as HTMLImageElement | null
    if (!el || el.dataset.fallback === '1') return
    el.dataset.fallback = '1'
    el.src = homeCoverFallbackById(article.id)
}

const formatDate = (raw: unknown) => {
    if (raw == null || raw === '') return '—'
    const s = String(raw)
    if (s.includes(' ')) return s.length > 10 ? s.slice(0, 10) : s
    return s
}

const excerpt = (text: string) =>
    text.length > 140 ? `${text.slice(0, 140)}…` : text

const formatCompact = (n: unknown) => {
    const v = Number(n ?? 0)
    if (!Number.isFinite(v) || v <= 0) return '0'
    if (v >= 10000) return `${(v / 10000).toFixed(1).replace(/\.0$/, '')}w`
    if (v >= 1000) return `${(v / 1000).toFixed(1).replace(/\.0$/, '')}k`
    return String(v)
}
</script>

<style lang="scss" scoped>
.news-list-page {
    position: relative;
    min-width: 0;
    min-height: 100vh;
    background: #f8f9fa;
    color: #202124;
    overflow-x: hidden;
}

.news-list {
    padding: 40px 0 72px;
}

.news-list__shell {
    width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto;
}

/* —— Hero —— */
.news-list__hero {
    margin-bottom: 28px;
    text-align: left;
    max-width: 920px;
}

.news-list__eyebrow {
    display: block;
    margin-bottom: 12px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.24em;
    text-transform: uppercase;
    color: #8b8b8b;
}

/* 与首页首屏主标题 .hero__title 一致：超粗字重 + 负字距（字号略缩放以适配列表页） */
.news-list__title {
    margin: 0;
    color: #202124;
    font-size: clamp(40px, 5vw, 72px);
    font-weight: 800;
    line-height: 1;
    letter-spacing: -0.04em;
}

.news-list__title-count {
    margin-left: 0.35em;
    font-size: 0.5em;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: #8b8b8b;
    vertical-align: 0.08em;
}

.news-list__subtitle {
    margin: 12px 0 0;
    max-width: 720px;
    font-size: 16px;
    line-height: 1.55;
    color: #5f6368;
}

/* —— 分类 Tab：全宽细底边线，与选中项粗下划线同一基线 —— */
.news-list__toolbar {
    margin-bottom: 28px;
}

.news-list__sort-row {
    width: 100%;
    border-bottom: 1px solid rgba(32, 33, 36, 0.14);
}

.news-list__sort {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0;
    min-width: 0;

    &--merged .news-list__sort-btn {
        letter-spacing: 0.04em;
        text-transform: none;
    }
}

.news-list__sort-btn {
    position: relative;
    padding: 14px 0 12px;
    margin-right: 32px;
    border: 0;
    background: transparent;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #8b8b8b;
    cursor: pointer;
    text-decoration: none;
    transition: color 0.18s ease;

    &:hover {
        color: #1f1f1f;
    }

    &.is-active {
        color: #1f1f1f;

        &::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: -1px;
            height: 2px;
            background: #1f1f1f;
        }
    }
}

/* 列表体 */
.news-list__body {
    min-height: 320px;
    margin-top: 8px;
}

.news-list__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 24px;
}

/* 卡片：与首页「热门资讯」.article-card 一致 */
.nl-card {
    position: relative;
    display: flex;
    flex-direction: column;
    text-decoration: none;
    color: inherit;
    background: #fff;
    border-radius: 16px;
    overflow: hidden;

    &::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 270px;
        background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.2) 100%);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.22s ease;
    }

    &:hover::after {
        opacity: 1;
    }
}

.nl-card__media {
    position: relative;
    overflow: hidden;
    background: #f2f2f2;
}

.nl-card__image {
    display: block;
    width: 100%;
    height: 270px;
    object-fit: cover;
}

.nl-card__tag {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: 4px 10px;
    border-radius: 8px;
    background: #fff4e8;
    color: #ff6b00;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
}

.nl-card__body {
    padding: 20px;
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.nl-card__meta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}

.nl-card__date {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: #8b8b8b;
}

.nl-card__meta-sep {
    color: #cfcfcf;
}

.nl-card__cate {
    font-size: 12px;
    font-weight: 500;
    letter-spacing: normal;
    text-transform: none;
    color: #666;
}

.nl-card__title {
    min-height: 56px;
    margin: 0 0 20px;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.4;
    color: #222;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;

    /* 无摘要时与页脚间距略收紧 */
    &:has(+ .nl-card__footer) {
        margin-bottom: 12px;
    }
}

.nl-card__excerpt {
    margin: 0;
    color: #666;
    font-size: 14px;
    line-height: 1.8;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
    min-height: 0;
}

.nl-card__footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: auto;
    padding-top: 16px;
}

.nl-read {
    font-size: 13px;
    font-weight: 600;
    letter-spacing: normal;
    text-transform: none;
    color: #ff6b00;
}

.nl-card:hover .nl-read {
    color: #e65f00;
}

.nl-card__views {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 12px;
    color: #8b8b8b;

    :deep(.el-icon) {
        font-size: 13px;
    }
}

/* 分页 */
.news-list__pagination {
    display: flex;
    justify-content: center;
    margin-top: 32px;

    :deep(.el-pagination) {
        --el-pagination-button-color: #4a4a4a;
        --el-pagination-hover-color: #1f1f1f;
    }

    :deep(.el-pager li) {
        background: transparent;
        color: #4a4a4a;

        &.is-active {
            color: #1f1f1f;
            font-weight: 700;
            position: relative;

            &::after {
                content: '';
                position: absolute;
                left: 6px;
                right: 6px;
                bottom: 4px;
                height: 2px;
                background: #1f1f1f;
            }
        }
    }

    :deep(.btn-prev),
    :deep(.btn-next) {
        background: transparent;
    }
}

@media (max-width: 1400px) {
    .news-list__grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 1200px) {
    .news-list__grid {
        grid-template-columns: 1fr;
    }
}
</style>
