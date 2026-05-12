<template>
    <div class="news-detail-page">
        <MarketingHeader />

        <main v-if="newsDetail" class="news-detail">
            <!-- 与 MarketingHeader 内 .shell 左侧对齐 -->
            <div class="nd-crumb-bar">
                <nav class="nd-crumb" aria-label="面包屑">
                    <NuxtLink to="/news">AI资讯</NuxtLink>
                    <span class="nd-crumb__sep" aria-hidden="true">&gt;</span>
                    <NuxtLink
                        v-if="newsDetail.cate_name"
                        :to="{
                            path: '/news',
                            query: {
                                cid: newsDetail.cid,
                                name: newsDetail.cate_name
                            }
                        }"
                    >
                        {{ newsDetail.cate_name }}
                    </NuxtLink>
                    <NuxtLink v-else to="/news">资讯</NuxtLink>
                    <span class="nd-crumb__sep" aria-hidden="true">&gt;</span>
                    <span class="nd-crumb__current">正文</span>
                </nav>
            </div>
            <div class="news-detail__shell">
                <!-- 版式对齐 Cloud Blog：居中单栏、灰标签、粗标题、日期、宽图、居中作者、窄正文；相关在文末 -->
                <div class="nd-article-gcp">
                    <header class="nd-blog-head">
                        <NuxtLink
                            v-if="newsDetail.cate_name"
                            :to="{
                                path: '/news',
                                query: {
                                    cid: newsDetail.cid,
                                    name: newsDetail.cate_name
                                }
                            }"
                            class="nd-cat-pill"
                        >
                            {{ newsDetail.cate_name }}
                        </NuxtLink>
                        <p v-else class="nd-cat-pill nd-cat-pill--text">
                            资讯
                        </p>
                        <h1 class="nd-blog-title">{{ newsDetail.title }}</h1>
                        <time
                            class="nd-blog-date"
                            :datetime="isoDate(newsDetail.create_time) ?? undefined"
                        >
                            {{ formatDateLong(newsDetail.create_time) }}
                        </time>
                        <div class="nd-blog-meta-sub">
                            <span class="nd-blog-meta-sub__item">
                                <el-icon><Clock /></el-icon>
                                {{ readingTime }}
                            </span>
                            <span class="nd-blog-meta-sub__dot" aria-hidden="true">·</span>
                            <span class="nd-blog-meta-sub__item">
                                <el-icon><View /></el-icon>
                                {{ formatCompact(newsDetail.click) }}
                            </span>
                        </div>
                    </header>

                    <figure v-if="coverImage" class="nd-cover nd-cover--gcp">
                        <img
                            :src="coverImage"
                            :alt="newsDetail.title"
                            loading="eager"
                            @error="onHeroCoverError"
                        />
                    </figure>

                    <div class="nd-byline-gcp">
                        <div class="nd-byline-gcp__name">{{ authorName }}</div>
                        <div class="nd-byline-gcp__role">{{ authorRole }}</div>
                    </div>

                    <p v-if="newsDetail.abstract" class="nd-deck">
                        {{ newsDetail.abstract }}
                    </p>

                    <article class="nd-main-inner">
                        <div
                            class="nd-content render-html nd-content--gcp"
                            v-html="newsDetail.content"
                        />

                        <!-- Tag/分类 -->
                        <div class="nd-taxonomy">
                            <span class="nd-taxonomy__label">发布于</span>
                            <NuxtLink
                                v-if="newsDetail.cate_name"
                                :to="{
                                    path: '/news',
                                    query: {
                                        cid: newsDetail.cid,
                                        name: newsDetail.cate_name
                                    }
                                }"
                                class="nd-tag"
                            >
                                #{{ newsDetail.cate_name }}
                            </NuxtLink>
                        </div>

                        <!-- 收藏 -->
                        <div class="nd-actions">
                            <button
                                type="button"
                                :class="[
                                    'nd-collect',
                                    { 'is-active': newsDetail.collect }
                                ]"
                                @click="handelCollectLock"
                            >
                                <el-icon v-if="newsDetail.collect">
                                    <StarFilled />
                                </el-icon>
                                <el-icon v-else>
                                    <Star />
                                </el-icon>
                                {{ newsDetail.collect ? '已收藏' : '收藏文章' }}
                            </button>
                            <NuxtLink to="/news" class="nd-back">
                                ← 返回资讯列表
                            </NuxtLink>
                        </div>

                        <!-- 原「上一篇/下一篇」位置：相关推荐（最多 4 条） -->
                        <section
                            v-if="relatedArticlesRow.length"
                            class="nd-related-articles nd-related-articles--in-article"
                            aria-label="相关文章"
                        >
                            <h2 class="nd-related-articles__title">相关文章</h2>
                            <!-- 卡片结构与首页「热门资讯」.article-card 一致 -->
                            <div class="grid grid--four">
                                <NuxtLink
                                    v-for="item in relatedArticlesRow"
                                    :key="item.id"
                                    :to="`/news/${item.id}`"
                                    class="article-card"
                                >
                                    <img
                                        class="article-card__image"
                                        :src="relatedThumbSrc(item)"
                                        :alt="
                                            typeof item.title === 'string'
                                                ? item.title
                                                : ''
                                        "
                                        loading="lazy"
                                        @error="(e) => onRelatedCoverError(e, item)"
                                    />
                                    <div class="article-card__body">
                                        <h3>{{ item.title }}</h3>
                                        <p>{{ relatedExcerpt(item) }}</p>
                                    </div>
                                </NuxtLink>
                            </div>
                        </section>
                    </article>
                </div>
            </div>
        </main>
    </div>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import { ElIcon } from 'element-plus'
import { View, Clock, Star, StarFilled } from '@element-plus/icons-vue'
import { addCollect, cancelCollect, getArticleDetail } from '~~/api/news'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import feedback from '~~/utils/feedback'
import { homeCoverFallbackById } from '@/constants/article-cover-fallbacks'
import { normalizeFileUrl } from '@/utils/file-url'

definePageMeta({
    layout: 'blank'
})

const route = useRoute()
const { ensurePcLogin } = usePcLoginGate()

const { data: newsDetail, refresh } = await useAsyncData(
    () =>
        getArticleDetail({
            id: route.params.id,
            source: 'default'
        }),
    {
        initialCache: false,
        watch: [() => route.params.id]
    }
)

useHead({
    title: () => newsDetail.value?.title || '资讯详情'
})

const authorName = computed(() => {
    const a = newsDetail.value?.author
    if (typeof a === 'string' && a.trim()) return a.trim()
    return newsDetail.value?.cate_name || 'A. PART Editorial'
})

/** 副标题 / 职位（对齐 Cloud Blog 作者行下方说明） */
const authorRole = computed(() => {
    const d = newsDetail.value as Record<string, unknown> | undefined
    if (!d) return 'A. PART Editorial'
    const job = d.author_title ?? d.author_job ?? d.job
    if (typeof job === 'string' && job.trim()) return job.trim()
    return 'A. PART Editorial'
})

const formatDateLong = (raw: unknown) => {
    if (raw == null || raw === '') return '—'
    const s = String(raw).trim()
    const datePart = s.includes(' ') ? s.slice(0, 10) : s.slice(0, 10)
    const t = Date.parse(datePart.replace(/-/g, '/'))
    if (Number.isNaN(t)) return s
    return new Intl.DateTimeFormat('zh-CN', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    }).format(new Date(t))
}

const isoDate = (raw: unknown) => {
    if (raw == null || raw === '') return undefined
    const s = String(raw).trim()
    return s.includes(' ') ? s.slice(0, 10) : s.slice(0, 10)
}

const coverImage = computed(() => {
    const raw = newsDetail.value?.image
    return normalizeFileUrl(
        typeof raw === 'string' ? raw : raw != null ? String(raw) : ''
    )
})

const onHeroCoverError = (e: Event) => {
    const el = e.target as HTMLImageElement | null
    if (!el || el.dataset.fallback === '1') return
    el.dataset.fallback = '1'
    el.src = homeCoverFallbackById(newsDetail.value?.id)
}

const articleImage = (article: Record<string, unknown>) => {
    const raw = article?.image
    return normalizeFileUrl(
        typeof raw === 'string' ? raw : raw != null ? String(raw) : ''
    )
}

const relatedThumbSrc = (article: Record<string, unknown>) =>
    articleImage(article) || homeCoverFallbackById(article.id)

/** 底部「相关文章」最多 4 条（与 Cloud Blog 横向卡片一致） */
const relatedArticlesRow = computed(() => {
    const list = newsDetail.value?.new
    if (!Array.isArray(list)) return []
    return list.slice(0, 4) as Record<string, unknown>[]
})

/** 摘要展示：与首页资讯卡片正文行一致（去 HTML、截断） */
const relatedExcerpt = (item: Record<string, unknown>) => {
    const raw = item.desc ?? item.abstract
    let s = ''
    if (typeof raw === 'string') {
        s = raw.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim()
    }
    if (!s) return '点击阅读全文'
    return s.length > 140 ? `${s.slice(0, 140)}…` : s
}

const onRelatedCoverError = (e: Event, article: Record<string, unknown>) => {
    const el = e.target as HTMLImageElement | null
    if (!el || el.dataset.fallback === '1') return
    el.dataset.fallback = '1'
    el.src = homeCoverFallbackById(article.id)
}

const formatCompact = (n: unknown) => {
    const v = Number(n ?? 0)
    if (!Number.isFinite(v) || v <= 0) return '0'
    if (v >= 10000) return `${(v / 10000).toFixed(1).replace(/\.0$/, '')}w`
    if (v >= 1000) return `${(v / 1000).toFixed(1).replace(/\.0$/, '')}k`
    return String(v)
}

const readingTime = computed(() => {
    const raw = newsDetail.value?.content
    if (typeof raw !== 'string') return '约 1 分钟'
    const text = raw.replace(/<[^>]+>/g, '').replace(/\s+/g, '')
    const charCount = text.length
    const minutes = Math.max(1, Math.round(charCount / 500))
    return `约 ${minutes} 分钟`
})

const handelCollect = async () => {
    if (!ensurePcLogin()) return
    const id = route.params.id
    if (!newsDetail.value) return
    try {
        if (newsDetail.value.collect) {
            await cancelCollect({ id })
            feedback.msgSuccess('已取消收藏')
        } else {
            await addCollect({ id })
            feedback.msgSuccess('收藏成功')
        }
        refresh()
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}
const { lockFn: handelCollectLock } = useLockFn(handelCollect)
</script>

<style lang="scss" scoped>
/* 版式参考 Google Cloud Blog 文章（白底、分类蓝链、标题、日期、大图、作者、正文蓝链） */
.news-detail-page {
    position: relative;
    min-width: var(--pc-min-width, 768px);
    min-height: 100vh;
    background: #fff;
    color: #202124;
}

.news-detail {
    padding: 48px 0 100px;
}

.news-detail__shell {
    max-width: 1040px;
    margin: 0 auto;
    padding: 0 24px;
}

/* 与顶部导航 .shell 同宽居中，左侧与 logo 对齐 */
.nd-crumb-bar {
    width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto 28px;
    box-sizing: border-box;
}

.nd-crumb {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    text-align: left;
    font-size: 14px;
    line-height: 1.5;

    a {
        color: #666;
        text-decoration: none;

        &:hover {
            color: #222;
        }
    }
}

.nd-crumb__sep {
    color: #b7b7b7;
    user-select: none;
}

.nd-crumb__current {
    color: #222;
    font-weight: 600;
}

/* 正文主列：居中、单栏阅读感 */
.nd-article-gcp {
    margin: 0 auto;
}

/* 文首：灰标签 → 粗标题 → 日期（参考 Cloud Blog 顶区居中） */
.nd-blog-head {
    text-align: center;
    margin-bottom: 40px;
}

.nd-cat-pill {
    display: inline-flex;
    align-items: center;
    margin: 0 0 20px;
    padding: 6px 16px;
    border-radius: 999px;
    background: #f1f3f4;
    color: #5f6368;
    font-size: 13px;
    font-weight: 500;
    line-height: 1.4;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;

    &:hover {
        background: #e8eaed;
        color: #202124;
    }

    &--text {
        cursor: default;
    }
}

.nd-blog-title {
    margin: 0 0 16px;
    color: #202124;
    font-size: clamp(28px, 3.6vw, 42px);
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: -0.02em;
}

.nd-blog-date {
    display: block;
    margin: 0 0 10px;
    font-size: 15px;
    font-weight: 400;
    line-height: 1.5;
    color: #5f6368;
}

.nd-blog-meta-sub {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 4px 8px;
    font-size: 12px;
    line-height: 1.5;
    color: #80868b;
}

.nd-blog-meta-sub__item {
    display: inline-flex;
    align-items: center;
    gap: 4px;

    :deep(.el-icon) {
        font-size: 14px;
    }
}

.nd-blog-meta-sub__dot {
    color: #dadce0;
    user-select: none;
}

/* 封面大图 */
.nd-cover--gcp {
    margin: 0 0 40px;
    overflow: hidden;
    border-radius: 28px;
    background: #f1f3f4;
    max-height: 520px;

    img {
        width: 100%;
        height: 100%;
        max-height: 520px;
        object-fit: cover;
        display: block;
    }
}

/* —— 作者：居中 —— */
.nd-byline-gcp {
    max-width: 720px;
    margin: 0 auto 32px;
    text-align: center;
}

.nd-byline-gcp__name {
    font-size: 16px;
    font-weight: 700;
    line-height: 1.4;
    color: #202124;
}

.nd-byline-gcp__role {
    margin-top: 4px;
    font-size: 14px;
    line-height: 1.5;
    color: #5f6368;
}

/* —— 摘要导语 —— */
.nd-deck {
    max-width: 720px;
    margin: 0 auto 36px;
    font-size: 18px;
    line-height: 1.65;
    color: #3c4043;
    text-align: left;
}

.nd-main-inner {
    min-width: 0;
}

/* —— 正文（阅读宽度与 Cloud Blog 相近，外链为 Google 蓝） —— */
.nd-content.nd-content--gcp {
    max-width: 720px;
    margin: 0 auto;
    font-size: 16px;
    line-height: 1.75;
    color: #3c4043;

    :deep(p) {
        margin: 0 0 20px;
    }

    :deep(img) {
        display: block;
        max-width: 100%;
        height: auto;
        margin: 24px 0;
        border-radius: 8px;
    }

    :deep(h1),
    :deep(h2),
    :deep(h3),
    :deep(h4) {
        margin: 36px 0 16px;
        color: #202124;
        letter-spacing: -0.01em;
    }

    :deep(h2) {
        font-size: 24px;
        font-weight: 600;
        line-height: 1.35;
    }

    :deep(h3) {
        font-size: 20px;
        font-weight: 600;
        line-height: 1.4;
    }

    :deep(h4) {
        font-size: 18px;
        font-weight: 600;
    }

    :deep(blockquote) {
        margin: 28px 0;
        padding: 4px 0 4px 20px;
        border-left: 3px solid #dadce0;
        color: #5f6368;
        font-size: 15px;
        line-height: 1.75;
    }

    :deep(a) {
        color: #1a73e8;
        text-decoration: none;
        border-bottom: 1px solid transparent;

        &:hover {
            color: #174ea6;
            border-bottom-color: #174ea6;
        }
    }

    :deep(ul),
    :deep(ol) {
        margin: 16px 0 20px;
        padding-left: 22px;

        li {
            margin-bottom: 8px;
        }
    }

    :deep(code) {
        padding: 2px 6px;
        border-radius: 3px;
        background: rgba(31, 31, 31, 0.06);
        font-size: 0.92em;
    }

    :deep(pre) {
        margin: 20px 0;
        padding: 16px;
        border-radius: 4px;
        background: #111;
        color: #f0f0f0;
        overflow-x: auto;
        font-size: 13px;
        line-height: 1.65;

        code {
            background: transparent;
            padding: 0;
        }
    }
}

/* —— Taxonomy —— */
.nd-taxonomy {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 40px;
    padding-top: 28px;
    border-top: 1px solid rgba(31, 31, 31, 0.1);
}

.nd-taxonomy__label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.2em;
    text-transform: uppercase;
    color: #8b8b8b;
    margin-right: 4px;
}

.nd-tag {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(31, 31, 31, 0.06);
    color: #1f1f1f;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;

    &:hover {
        background: #1f1f1f;
        color: #fff;
    }
}

/* —— Actions —— */
.nd-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    margin: 32px 0 48px;
    flex-wrap: wrap;
}

.nd-collect {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    height: 44px;
    padding: 0 22px;
    border: 1px solid rgba(31, 31, 31, 0.18);
    border-radius: 999px;
    background: #fff;
    color: #1f1f1f;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.04em;
    cursor: pointer;
    transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;

    :deep(.el-icon) {
        font-size: 16px;
    }

    &:hover {
        background: #1f1f1f;
        color: #fff;
        border-color: #1f1f1f;
    }

    &.is-active {
        background: #ff6b00;
        color: #fff;
        border-color: #ff6b00;

        &:hover {
            background: #e85e00;
            border-color: #e85e00;
        }
    }
}

.nd-back {
    font-size: 14px;
    font-weight: 500;
    letter-spacing: normal;
    text-transform: none;
    color: #1a73e8;
    text-decoration: none;

    &:hover {
        text-decoration: underline;
        text-underline-offset: 3px;
    }
}

/* —— 文末：相关文章（四列卡片，参考稿居中排版） —— */
.nd-related-articles {
    margin-top: 56px;
    padding-top: 48px;
    border-top: 1px solid #dadce0;
}

/* 紧接在收藏/返回下方（原上下篇位置），不再额外下移一大块 */
.nd-related-articles--in-article {
    margin-top: 0;
    padding-top: 40px;
}

.nd-related-articles__title {
    margin: 0 0 28px;
    font-size: 22px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -0.02em;
    color: #202124;
}

/* 与首页 `pages/index.vue` 中 `.grid` / `.grid--four` / `.article-card` 视觉一致 */
.nd-related-articles .grid {
    display: grid;
    gap: 20px;

    &.grid--four {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

.nd-related-articles .article-card {
    position: relative;
    display: block;
    background: #fff;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(34, 34, 34, 0.05);
    text-decoration: none;
    color: inherit;

    &::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 270px;
        background: linear-gradient(
            180deg,
            rgba(0, 0, 0, 0) 0%,
            rgba(0, 0, 0, 0.2) 100%
        );
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.22s ease;
    }

    .article-card__image {
        display: block;
        width: 100%;
        height: 270px;
        object-fit: cover;
    }

    .article-card__body {
        padding: 20px;
    }

    h3 {
        min-height: 56px;
        margin: 0 0 20px;
        font-size: 20px;
        line-height: 1.4;
        font-weight: 600;
        color: #222;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    p {
        margin: 0;
        color: #666;
        font-size: 14px;
        line-height: 1.8;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    &:hover::after {
        opacity: 1;
    }
}

@media (max-width: 1200px) {
    .nd-related-articles .grid.grid--four {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .nd-related-articles .grid.grid--four {
        grid-template-columns: 1fr;
    }
}
</style>
