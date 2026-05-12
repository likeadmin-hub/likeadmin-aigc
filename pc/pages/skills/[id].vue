<template>
    <section class="skill-detail-page">
        <MarketingHeader />

        <div class="skill-detail-shell">
            <template v-if="skill">
                <main class="skill-main">
                    <section class="hero-panel">
                        <div class="crumbs-pill">
                            <span>目录：</span>
                            <NuxtLink class="crumbs-pill__link" to="/">首页</NuxtLink>
                            <span>/</span>
                            <NuxtLink class="crumbs-pill__link" to="/skills">技能广场</NuxtLink>
                            <span>/</span>
                            <NuxtLink
                                class="crumbs-pill__link"
                                :to="{ path: '/skills', query: breadcrumbCategory ? { category: breadcrumbCategory } : {} }"
                            >
                                {{ breadcrumbCategory }}
                            </NuxtLink>
                            <span>/</span>
                            <span class="crumbs-pill__current">{{ detailTitle }}</span>
                        </div>

                        <h1 class="hero-title">{{ detailTitle }}</h1>
                        <p class="hero-summary">{{ detailSummary }}</p>

                        <div class="meta-terminal">
                            <div class="meta-terminal__header">技能概况</div>
                            <div class="meta-terminal__stats">
                                <span class="meta-terminal__stat is-hot">星级：{{ formatCompact(repoStarCount) }}</span>
                                <span class="meta-terminal__stat is-install">分叉：{{ formatCompact(repoForkCount) }}</span>
                                <span class="meta-terminal__stat is-update">更新：{{ formattedUpdatedAt }}</span>
                            </div>
                        </div>
                    </section>

                    <section class="view-switch">
                        <button
                            :class="['view-switch__item', { 'is-active': activeTab === 'detail' }]"
                            type="button"
                            @click="activeTab = 'detail'"
                        >
                            详情
                        </button>
                        <button
                            :class="['view-switch__item', { 'is-active': activeTab === 'example' }]"
                            type="button"
                            @click="activeTab = 'example'"
                        >
                            示例
                        </button>
                    </section>

                    <section class="terminal-card">
                        <header class="terminal-card__header">
                            <div class="terminal-card__dots">
                                <span class="dot dot--red"></span>
                                <span class="dot dot--yellow"></span>
                                <span class="dot dot--green"></span>
                            </div>
                            <span class="terminal-card__title">{{ activeTab === 'detail' ? 'SKILL.md' : '示例.md' }}</span>
                            <span class="terminal-card__readonly">只读</span>
                        </header>

                        <div v-if="activeTab === 'detail'" class="terminal-card__body">
                            <div class="info-table">
                                <div class="info-table__label">名称</div>
                                <div class="info-table__value">{{ detailTitle }}</div>
                                <div class="info-table__label">描述</div>
                                <div class="info-table__value">{{ detailSummary }}</div>
                            </div>

                            <article class="detail-html" v-html="detailHtml"></article>
                        </div>

                        <div v-else class="terminal-card__body example-panel">
                            <div v-if="skill.coverImage" class="example-cover">
                                <img :src="skill.coverImage" :alt="detailTitle" />
                            </div>

                            <div class="example-copy">
                                <h2>技能示例</h2>
                                <p>{{ exampleDescription }}</p>
                                <div class="example-copy__facts">
                                    <span>技能名称：{{ detailTitle }}</span>
                                    <span>技能收费：{{ accessBadgeText }}</span>
                                    <span>安装命令：{{ installCommandDisplay }}</span>
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <aside class="skill-side">
                    <section
                        :class="['side-card', 'summary-access-card', { 'is-member-card': accessType === 'member' }]"
                    >
                        <header class="side-card__header">
                            <span>技能权限</span>
                        </header>

                        <div class="side-card__body summary-access-card__body">
                            <h1>{{ detailTitle }}</h1>
                            <div class="summary-card__rating" @mouseleave="ratingHover = 0">
                                <span class="summary-card__rating-label">评分：</span>
                                <div class="summary-card__stars">
                                    <button
                                        v-for="star in 5"
                                        :key="star"
                                        type="button"
                                        :class="['summary-card__star', { 'is-active': star <= displayRatingStars }]"
                                        @mouseenter="ratingHover = star"
                                        @click="submitSkillRating(star)"
                                    >
                                        ★
                                    </button>
                                </div>
                            </div>
                            <div v-if="accessType === 'paid'" class="summary-card__price">¥{{ paidPrice }}</div>
                            <template v-else-if="accessType === 'member'">
                                <div class="summary-card__member-note">
                                    <p class="summary-card__member-note-line">
                                        <span class="summary-card__member-note-highlight">只需每月 {{ basicMembershipPrice }} 元，</span>
                                        <span>即可访问本技能以及平台内更多会员限免内容。</span>
                                    </p>
                                    <button class="summary-card__member-link" type="button" @click="handleAccessAction">
                                        点击了解更多
                                    </button>
                                </div>
                            </template>
                            <button
                                v-if="accessType !== 'free'"
                                class="summary-card__action"
                                type="button"
                                @click="handleAccessAction"
                            >
                                {{ accessActionLabel }}
                            </button>
                        </div>
                    </section>

                    <section class="side-card">
                        <header class="side-card__header">
                            <span>全局安装</span>
                        </header>

                        <div class="side-card__body">
                            <div class="tag-row">
                                <button
                                    v-for="item in installRuntimeOptions"
                                    :key="item.key"
                                    :class="['tag-chip', { 'is-active': selectedInstallRuntime === item.key }]"
                                    type="button"
                                    @click="selectedInstallRuntime = item.key"
                                >
                                    {{ item.label }}
                                </button>
                            </div>

                            <div :class="['command-block', { 'is-masked': !canUseSkill }]">
                                <span>{{ installCommandDisplay }}</span>
                                <button
                                    :class="['copy-button', { 'is-disabled': !canCopyInstallCommand }]"
                                    type="button"
                                    :disabled="!canCopyInstallCommand"
                                    @click="copyInstallCommand"
                                >
                                    复制
                                </button>
                            </div>
                            <p class="side-tip">{{ installTipText }}</p>
                        </div>
                    </section>

                    <section class="side-card">
                        <header class="side-card__header">
                            <span>下载技能</span>
                        </header>

                        <div class="side-card__body">
                            <a
                                v-if="canDownloadSkill && skill.downloadUrl"
                                class="side-action side-action--download"
                                :href="skill.downloadUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ resolvedDownloadLabel }}
                            </a>
                            <button
                                v-else
                                class="side-action side-action--download is-disabled"
                                type="button"
                                :disabled="true"
                            >
                                {{ resolvedDownloadLabel }}
                            </button>
                            <p class="side-tip">{{ downloadTipText }}</p>
                        </div>
                    </section>

                    <section class="side-card">
                        <header class="side-card__header">
                            <span>推荐技能</span>
                        </header>

                        <div class="side-card__body">
                            <div v-if="displayRelatedSkills.length" class="related-list">
                                <article
                                    v-for="item in displayRelatedSkills"
                                    :key="item.id || `${item.name}-${item.from}`"
                                    class="related-item"
                                    @click="goToRelatedSkill(item.id)"
                                >
                                    <div class="related-item__avatar">{{ item.avatar || packageInitial }}</div>
                                    <div class="related-item__content">
                                        <div class="related-item__title">导入 {{ item.name }}</div>
                                        <div class="related-item__meta">来自 “{{ item.from || '官方' }}”</div>
                                    </div>
                                    <div class="related-item__stars">★ {{ formatCompact(item.ratingTotal || 0) }}</div>
                                </article>
                            </div>

                            <div v-else class="empty-related">暂无相关技能</div>
                        </div>
                    </section>
                </aside>
            </template>

            <main v-else class="skill-main">
                <section class="hero-panel hero-panel--empty">
                    <h1 class="hero-title">技能不存在</h1>
                    <p class="hero-summary">当前技能不存在、未启用，或详情数据尚未发布。</p>
                </section>
            </main>
        </div>
    </section>
</template>

<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import { getSkillDetail, getSkillList } from '@/api/pc-content'
import { MEMBERSHIP_PLANS } from '@/constants/membership-plans'
import type { SkillItem } from '@/constants/skills'
import { useUserStore } from '@/stores/user'
import { extractListData, mapSkillItem } from '@/utils/pc-adapters'

definePageMeta({ layout: 'blank' })

type SkillAccessType = 'free' | 'member' | 'paid'
type InstallRuntime = 'npx' | 'bun' | 'pnpm'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const skillId = computed(() => String(route.params.id ?? ''))
const activeTab = ref<'detail' | 'example'>('detail')
const ratingHover = ref(0)
const selectedInstallRuntime = ref<InstallRuntime>('npx')
const localRatingTotal = useState<Record<string, number>>('pc-skill-rating-total', () => ({}))
const localRatingSelected = useState<Record<string, number>>('pc-skill-rating-selected', () => ({}))

if (userStore.isLogin && !Object.keys(userStore.userInfo || {}).length) {
    try {
        await userStore.getUser()
    } catch (error) {
        console.warn('[pc-skill-detail] load user center failed, continue rendering page', error)
        userStore.$reset()
    }
}

const { data: pageData } = await useAsyncData(`pc-skill-detail-${skillId.value}`, async () => {
    const [rawDetail, rawList] = await Promise.all([
        getSkillDetail(skillId.value),
        getSkillList({ page_no: 1, page_size: 100 })
    ])

    const detail = rawDetail && rawDetail.id ? (mapSkillItem(rawDetail) as SkillItem) : null
    const list = extractListData(rawList).map((item) => mapSkillItem(item))

    return { detail, list }
})

const skill = computed(() => pageData.value?.detail ?? null)
const allSkills = computed(() => pageData.value?.list ?? [])

const detailTitle = computed(() => skill.value?.detailTitle || skill.value?.title || '技能详情')
const detailSummary = computed(
    () => skill.value?.detailSummary || skill.value?.summary || skill.value?.description || '暂无简介'
)
const detailHtml = computed(() => {
    const source = String(skill.value?.detailContent || skill.value?.description || detailSummary.value || '').trim()
    if (!source) return '<p>暂无详情内容。</p>'
    if (/<\/?[a-z][\s\S]*>/i.test(source)) return source
    return source
        .split(/\n{2,}/)
        .map((item) => `<p>${item.replace(/\n/g, '<br>')}</p>`)
        .join('')
})

const breadcrumbCategory = computed(() => skill.value?.category || '技能')
const packageInitial = computed(() => detailTitle.value.slice(0, 1).toUpperCase() || 'S')

const accessType = computed<SkillAccessType>(() => {
    if (skill.value?.accessType === 'member' || skill.value?.accessType === 'paid' || skill.value?.accessType === 'free') {
        return skill.value.accessType
    }
    if (skill.value?.memberFree) return 'member'
    if (typeof skill.value?.price === 'number' && skill.value.price > 0) return 'paid'
    return 'free'
})

const isMemberActive = computed(() => Boolean(userStore.userInfo?.member_plan_id))
const isPurchased = computed(() => Boolean(skill.value?.isPurchased))
const canUseSkill = computed(() => {
    if (accessType.value === 'free') return true
    if (accessType.value === 'member') return isMemberActive.value
    return isPurchased.value
})
const accessBadgeText = computed(() => {
    if (accessType.value === 'member') return '会员限免'
    if (accessType.value === 'paid') return `付费购买 ¥${paidPrice.value}`
    return '免费'
})
const accessCardStatus = computed(() => (accessType.value === 'paid' ? '付费购买' : '会员限免'))
const accessCardTip = computed(() => (accessType.value === 'member' ? '开通会员后即可复制命令并下载安装包' : '购买后即可复制命令并下载安装包'))
const accessActionLabel = computed(() => (accessType.value === 'member' ? '开通会员' : '立即购买'))
const paidPrice = computed(() => Number(skill.value?.price || 0).toFixed(2))
const basicMembershipPrice = computed(() => MEMBERSHIP_PLANS.find((item) => item.id === 'basic')?.monthlyPrice || '19.00')
const selectedRating = computed(() => localRatingSelected.value[skillId.value] || 0)
const displayRatingStars = computed(() => ratingHover.value || selectedRating.value)

const formattedUpdatedAt = computed(() => {
    if (!skill.value?.updatedAt) return '--'
    const date = new Date(skill.value.updatedAt)
    if (Number.isNaN(date.getTime())) return skill.value.updatedAt
    const hh = `${date.getHours()}`.padStart(2, '0')
    const mm = `${date.getMinutes()}`.padStart(2, '0')
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日 ${hh}:${mm}`
})

const repoStarCount = computed(() => (skill.value?.repoStars || 0) + (localRatingTotal.value[skillId.value] || 0))
const repoForkCount = computed(() => skill.value?.repoForks || skill.value?.installs || 0)
const installRuntimeOptions = [
    { key: 'npx' as const, label: 'npx' },
    { key: 'bun' as const, label: 'Bun' },
    { key: 'pnpm' as const, label: 'pnpm' }
]
const installCommandsMap = computed<Record<InstallRuntime, string>>(() => {
    const commands = skill.value?.installCommands
    if (commands?.npx || commands?.bun || commands?.pnpm) {
        return {
            npx: String(commands?.npx || ''),
            bun: String(commands?.bun || ''),
            pnpm: String(commands?.pnpm || '')
        }
    }

    const target = skill.value?.repoName || skill.value?.slug || skill.value?.id || ''
    const body = skill.value?.installCommand
        ? String(skill.value.installCommand).replace(/^\s*(npx|bunx|pnpm(?:\s+dlx)?)\s+/i, '').trim()
        : `skills add ${target}`.trim()

    return {
        npx: `npx ${body}`.trim(),
        bun: `bunx ${body}`.trim(),
        pnpm: `pnpm dlx ${body}`.trim()
    }
})
const resolvedInstallLabel = computed(
    () => installRuntimeOptions.find((item) => item.key === selectedInstallRuntime.value)?.label || 'npx'
)
const rawInstallCommand = computed(() => installCommandsMap.value[selectedInstallRuntime.value] || '')
const installCommandDisplay = computed(() => {
    if (canUseSkill.value) return rawInstallCommand.value
    return rawInstallCommand.value.replace(/[^\s]/g, '*')
})
const canCopyInstallCommand = computed(() => canUseSkill.value && Boolean(rawInstallCommand.value))
const resolvedDownloadLabel = computed(() => skill.value?.downloadLabel || '下载技能')
const canDownloadSkill = computed(() => canUseSkill.value && Boolean(skill.value?.downloadUrl))
const installTipText = computed(() => {
    if (accessType.value === 'free') return '当前技能可直接复制安装命令。'
    if (canUseSkill.value) return '当前账号已解锁，可直接复制安装命令。'
    return accessType.value === 'member' ? '非会员用户暂不可复制安装命令。' : '未购买前安装命令已隐藏。'
})
const downloadTipText = computed(() => {
    if (!skill.value?.downloadUrl) return '当前技能暂未提供下载包。'
    if (accessType.value === 'free') return skill.value?.downloadTip || '下载包包含技能所需完整文件。'
    if (canUseSkill.value) return skill.value?.downloadTip || '当前账号已解锁，可直接下载安装包。'
    return accessType.value === 'member' ? '开通会员后即可下载安装包。' : '购买后即可下载安装包。'
})
const exampleDescription = computed(() => {
    if (skill.value?.coverImage) {
        return '示例区域优先展示后台上传的技能封面图，方便和详情页布局保持一致。'
    }
    return '当前后台没有单独的示例图片字段，已保留示例页签和技能说明信息。'
})

watch(
    () => skill.value?.installLabel,
    (value) => {
        const normalized = String(value || 'npx').trim().toLowerCase()
        if (normalized === 'bun' || normalized === 'bunx') {
            selectedInstallRuntime.value = 'bun'
            return
        }
        if (normalized === 'pnpm') {
            selectedInstallRuntime.value = 'pnpm'
            return
        }
        selectedInstallRuntime.value = 'npx'
    },
    { immediate: true }
)

const displayRelatedSkills = computed(() => {
    if (skill.value?.relatedSkills?.length) {
        return skill.value.relatedSkills.slice(0, 5).map((item) => {
            const itemId = String(item.id || '')
            return {
                ...item,
                id: itemId,
                ratingTotal: Number(item.stars || 0) + Number(localRatingTotal.value[itemId] || 0)
            }
        })
    }

    return allSkills.value
        .filter((item) => item.id !== skill.value?.id)
        .filter((item) => !skill.value?.category || item.category === skill.value.category)
        .slice(0, 5)
        .map((item) => ({
            id: item.id,
            avatar: item.title.slice(0, 1).toUpperCase(),
            name: item.title,
            from: item.category || '官方',
            ratingTotal: Number(item.repoStars || 0) + Number(localRatingTotal.value[String(item.id || '')] || 0)
        }))
})

const formatCompact = (value: number) => {
    if (value >= 10000) {
        return `${(value / 10000).toFixed(1).replace(/\.0$/, '')}w`
    }
    if (value >= 1000) {
        return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`
    }
    return String(value)
}

const persistSkillRatingState = () => {
    if (!import.meta.client) return
    localStorage.setItem('pc-skill-rating-total', JSON.stringify(localRatingTotal.value))
    localStorage.setItem('pc-skill-rating-selected', JSON.stringify(localRatingSelected.value))
}

if (import.meta.client) {
    try {
        const savedTotal = JSON.parse(localStorage.getItem('pc-skill-rating-total') || '{}')
        const savedSelected = JSON.parse(localStorage.getItem('pc-skill-rating-selected') || '{}')
        if (savedTotal && typeof savedTotal === 'object') {
            localRatingTotal.value = savedTotal
        }
        if (savedSelected && typeof savedSelected === 'object') {
            localRatingSelected.value = savedSelected
        }
    } catch (error) {
        console.warn('load skill rating cache failed', error)
    }
}

const handleAccessAction = () => {
    if (accessType.value === 'member') {
        router.push({ path: '/', hash: '#pricing-section' })
        return
    }
    ElMessage.warning('当前站点暂未接入技能单独购买流程，已先按未购买状态限制复制和下载。')
}

const goToRelatedSkill = (id?: string | number) => {
    if (!id) return
    router.push(`/skills/${id}`)
}

const submitSkillRating = (stars: number) => {
    if (!skill.value?.id) return
    if (selectedRating.value > 0) {
        ElMessage.warning('你已经评价过这个技能了')
        return
    }
    localRatingTotal.value = {
        ...localRatingTotal.value,
        [skillId.value]: (localRatingTotal.value[skillId.value] || 0) + stars
    }
    localRatingSelected.value = {
        ...localRatingSelected.value,
        [skillId.value]: stars
    }
    persistSkillRatingState()
    ElMessage.success(`已评分 ${stars} 星，技能概况星级已累计 +${stars}`)
}

const copyInstallCommand = async () => {
    if (!canCopyInstallCommand.value) {
        if (accessType.value === 'free') {
            ElMessage.warning('当前技能可直接使用安装命令')
        } else {
            ElMessage.warning(accessType.value === 'member' ? '开通会员后才能复制安装命令' : '购买后才能复制安装命令')
        }
        return
    }

    if (!import.meta.client || !navigator?.clipboard) {
        ElMessage.warning('当前浏览器不支持一键复制，请手动复制安装命令')
        return
    }

    try {
        await navigator.clipboard.writeText(rawInstallCommand.value)
        ElMessage.success('安装命令已复制')
    } catch (error) {
        console.warn('copy install command failed', error)
        ElMessage.error('复制失败，请稍后重试')
    }
}

useHead(() => ({
    title: detailTitle.value
}))
</script>

<style lang="scss" scoped>
.skill-detail-page {
    min-width: var(--pc-min-width, 768px);
    min-height: 100vh;
    background: #fff;
    color: #24334a;
}

.skill-detail-shell {
    width: min(1288px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto;
    padding: 22px 0 60px;
    display: grid;
    grid-template-columns: 1fr 420px;
    gap: 32px;
    align-items: start;
}

.skill-main {
    min-width: 0;
    max-width: none;
}

.skill-side {
    width: 420px;
    position: sticky;
    top: 20px;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.hero-panel {
    padding: 2px 2px 0;
}

.hero-panel--empty {
    padding-top: 64px;
    text-align: center;
}

.crumbs-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 38px;
    padding: 0 16px;
    border: 1px solid #b7c0d2;
    border-radius: 12px;
    background: #fff;
    color: #5c6c82;
    font-size: 14px;
    line-height: 1;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.crumbs-pill__link {
    color: #4b74ff;
    text-decoration: none;
}

.crumbs-pill__link:hover {
    color: #315ce8;
}

.crumbs-pill__current {
    color: #5c6c82;
}

.hero-title {
    margin: 22px 0 10px;
    color: #222;
    font-size: 44px;
    line-height: 1.08;
    font-weight: 700;
    letter-spacing: 0.02em;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.hero-summary {
    max-width: 860px;
    margin: 0;
    color: #5e6d83;
    font-size: 15px;
    line-height: 1.85;
}

.meta-terminal {
    margin-top: 18px;
    overflow: hidden;
    border: 1px solid #b8c1d3;
    border-radius: 12px;
    background: #fff;
}

.meta-terminal__header {
    min-height: 30px;
    padding: 0 14px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #cfd6e4;
    color: #66758d;
    font-size: 13px;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.meta-terminal__stats {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    padding: 14px 16px;
}

.meta-terminal__stat {
    font-size: 14px;
    font-weight: 600;
}

.meta-terminal__stat.is-hot {
    color: #efa208;
}

.meta-terminal__stat.is-install {
    color: #4a73ff;
}

.meta-terminal__stat.is-update {
    color: #22b86b;
}

.view-switch {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 28px;
    padding: 4px;
    border: 1px solid #b8c1d3;
    border-radius: 14px;
    background: #fff;
}

.view-switch__item {
    min-width: 84px;
    height: 36px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #66758d;
    font-size: 15px;
    cursor: pointer;
}

.view-switch__item.is-active {
    background: #fff;
    color: #1c2737;
    box-shadow: inset 0 0 0 1px #d5dbe7;
}

.terminal-card,
.side-card {
    overflow: hidden;
    border: 1px solid #b8c1d3;
    border-radius: 14px;
    background: #fff;
}

.terminal-card {
    margin-top: 28px;
}

.terminal-card__header,
.side-card__header {
    min-height: 30px;
    padding: 0 14px;
    display: flex;
    align-items: center;
    border-bottom: 1px solid #cfd6e4;
    color: #66758d;
    font-size: 13px;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.terminal-card__dots {
    display: flex;
    align-items: center;
    gap: 6px;
}

.dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
}

.dot--red {
    background: #ff8d7a;
}

.dot--yellow {
    background: #ffd27a;
}

.dot--green {
    background: #83de9b;
}

.terminal-card__title {
    margin-left: 10px;
}

.terminal-card__readonly {
    margin-left: auto;
    color: #8b97aa;
}

.terminal-card__body {
    padding: 24px 26px 30px;
}

.info-table {
    display: grid;
    grid-template-columns: 154px minmax(0, 1fr);
    border: 1px solid #f0d4c7;
    border-bottom: 0;
    border-radius: 20px;
    overflow: hidden;
}

.info-table__label,
.info-table__value {
    min-height: 76px;
    padding: 16px 18px;
    border-bottom: 1px solid #f0d4c7;
    font-size: 16px;
    line-height: 1.7;
}

.info-table__label {
    background: #f9efea;
    color: #cd7b5e;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.info-table__value {
    background: rgba(255, 255, 255, 0.9);
    color: #46566e;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    text-align: left;
}

.detail-html {
    margin-top: 30px;
    color: #1f2d40;
    font-size: 18px;
    line-height: 1.95;
    word-break: break-word;
}

.detail-html :deep(h1),
.detail-html :deep(h2) {
    margin: 1.3em 0 0.6em;
    color: #132540;
    font-size: 28px;
    line-height: 1.3;
    font-weight: 700;
}

.detail-html :deep(h3) {
    margin: 1.2em 0 0.6em;
    color: #132540;
    font-size: 22px;
    line-height: 1.35;
    font-weight: 700;
}

.detail-html :deep(p),
.detail-html :deep(li) {
    color: #2d3a50;
    font-size: 18px;
    line-height: 1.95;
}

.detail-html :deep(ul),
.detail-html :deep(ol) {
    padding-left: 1.4em;
}

.detail-html :deep(code) {
    padding: 1px 6px;
    border-radius: 6px;
    background: rgba(205, 123, 94, 0.08);
    color: #24334a;
    font-size: 0.92em;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.detail-html :deep(pre) {
    overflow-x: auto;
    margin: 18px 0;
    padding: 18px 20px;
    border-radius: 12px;
    background: #fff8f4;
    border: 1px solid #f0d4c7;
}

.detail-html :deep(pre code) {
    padding: 0;
    background: transparent;
    font-size: 15px;
    line-height: 1.8;
}

.detail-html :deep(img) {
    max-width: 100%;
    border-radius: 12px;
}

.example-panel {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.example-cover {
    overflow: hidden;
    border-radius: 12px;
    border: 1px solid #f0d4c7;
    background: #fff8f4;
}

.example-cover img {
    display: block;
    width: 100%;
    object-fit: cover;
}

.example-copy h2 {
    margin: 0 0 12px;
    color: #132540;
    font-size: 28px;
    line-height: 1.3;
}

.example-copy p {
    margin: 0;
    color: #5e6d83;
    font-size: 16px;
    line-height: 1.8;
}

.example-copy__facts {
    margin-top: 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 18px;
    border: 1px solid #f0d4c7;
    border-radius: 12px;
    background: #fff8f4;
    color: #46566e;
    font-size: 14px;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.side-card__body {
    padding: 12px 14px 14px;
}

.summary-access-card__body h1 {
    margin: 0;
    color: #222;
    font-size: 30px;
    line-height: 1.35;
    font-weight: 700;
    word-break: break-word;
}

.summary-card__rating {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 18px;
}

.summary-card__rating-label,
.summary-card__rating-value {
    color: #a1a1a1;
    font-size: 14px;
    line-height: 1;
}

.summary-card__stars {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #ffbf58;
    font-size: 18px;
}

.summary-card__star {
    padding: 0;
    border: 0;
    background: transparent;
    color: #e2e2e2;
    font-size: inherit;
    line-height: 1;
    cursor: pointer;
}

.summary-card__star.is-active {
    color: #ffbf58;
}

.summary-card__price {
    margin-top: 18px;
    color: #f23434;
    font-size: 32px;
    line-height: 1;
    font-weight: 600;
}

.summary-card__member-tip {
    margin-top: 18px;
    color: #ff6600;
    font-size: 20px;
    line-height: 1.5;
    font-weight: 600;
}

.summary-card__member-note {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 18px;
    padding: 18px 20px;
    border-radius: 18px;
    background: #f8f8f8;
    color: #222;
    font-size: 14px;
    line-height: 1.9;
}

.summary-card__member-note-line {
    margin: 0;
}

.summary-card__member-note-highlight {
    color: #ff6600;
    font-size: 16px;
    font-weight: 600;
}

.summary-card__member-link {
    width: fit-content;
    padding: 0;
    border: 0;
    background: transparent;
    color: #222;
    font-size: 16px;
    line-height: 1.5;
    font-weight: 600;
    text-decoration: underline;
    cursor: pointer;
}

.summary-card__action {
    width: 100%;
    height: 54px;
    margin-top: 40px;
    border: 0;
    border-radius: 16px;
    background: #ff6600;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
}

.summary-access-card {
    border-radius: 14px;
    background: #fff;
}

.summary-access-card__body {
    padding: 18px 16px 18px;
}

.summary-access-card.is-member-card h1 {
    margin-top: 18px;
    font-size: 30px;
    line-height: 1.45;
    font-weight: 700;
}

.summary-access-card.is-member-card .summary-card__rating {
    margin-top: 20px;
    gap: 10px;
}

.summary-access-card.is-member-card .summary-card__rating-label {
    color: #a1a1a1;
}

.summary-access-card.is-member-card .summary-card__rating-value {
    display: none;
}

.summary-access-card.is-member-card .summary-card__stars {
    font-size: 22px;
    gap: 6px;
}

.tag-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
}

.tag-chip {
    height: 28px;
    padding: 0 10px;
    display: inline-flex;
    align-items: center;
    border: 0;
    border-radius: 8px;
    background: #f3f5f8;
    color: #74839a;
    font-size: 12px;
    cursor: pointer;
}

.tag-chip.is-active {
    background: #fff7ef;
    color: #cd7b5e;
}

.command-block {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border: 1px solid #cfd6e4;
    border-radius: 10px;
    background: #fff;
}

.command-block span {
    min-width: 0;
    flex: 1;
    color: #203249;
    font-size: 13px;
    line-height: 1.6;
    word-break: break-all;
    font-family: 'JetBrains Mono', 'Consolas', monospace;
}

.command-block.is-masked span {
    color: #9aa3b2;
    letter-spacing: 0.04em;
}

.copy-button {
    min-width: 52px;
    height: 28px;
    border: 0;
    border-radius: 8px;
    background: #111827;
    color: #fff;
    font-size: 12px;
    cursor: pointer;
}

.copy-button.is-disabled,
.copy-button:disabled {
    background: #d8dce5;
    color: #7b8799;
    cursor: not-allowed;
}

.side-action {
    width: 100%;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 0;
    border-radius: 10px;
    background: #111827;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
}

.side-action--download {
    background: #111827;
}

.side-action.is-disabled,
.side-action:disabled {
    background: #d8dce5;
    color: #7b8799;
    cursor: not-allowed;
}

.side-tip {
    margin: 10px 0 0;
    color: #d08b35;
    font-size: 12px;
    line-height: 1.7;
}

.related-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.related-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px;
    border: 1px solid #cfd6e4;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        transform 0.2s ease;
}

.related-item:hover {
    border-color: rgba(255, 102, 0, 0.32);
    transform: translateY(-1px);
}

.related-item__avatar {
    width: 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: linear-gradient(135deg, #ff5630, #d81919);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    flex-shrink: 0;
}

.related-item__content {
    min-width: 0;
    flex: 1;
}

.related-item__title {
    color: #cd7b5e;
    font-size: 13px;
    line-height: 1.5;
}

.related-item__meta {
    margin-top: 4px;
    color: #4b74ff;
    font-size: 12px;
    line-height: 1.5;
}

.related-item__stars {
    color: #d08b35;
    font-size: 12px;
    white-space: nowrap;
}

.empty-related {
    padding: 14px 0;
    color: #8b97aa;
    font-size: 13px;
}

@media (max-width: 1100px) {
    .skill-detail-shell {
        grid-template-columns: 1fr;
    }

    .skill-side {
        position: static;
        width: 100%;
    }
}

@media (max-width: 820px) {
    .hero-title {
        font-size: 34px;
    }

    .terminal-card__body {
        padding: 18px;
    }

    .info-table {
        grid-template-columns: 120px minmax(0, 1fr);
    }
}
</style>
