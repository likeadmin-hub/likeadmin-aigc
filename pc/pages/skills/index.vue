<template>
    <div class="skills-square-page">
        <MarketingHeader />

        <section class="skills-square">
            <div class="skills-square__shell">
                <section class="toolbar">
                    <div class="toolbar__filters">
                        <div
                            v-for="filter in filterButtons"
                            :key="filter.key"
                            :class="[
                                'filter-control',
                                {
                                    'filter-control--wide': filter.key === 'access' || filter.key === 'sort',
                                    'is-open': openFilterKey === filter.key
                                }
                            ]"
                        >
                            <button class="filter-control__trigger" type="button" @click.stop="toggleFilter(filter.key)">
                                <span>{{ filter.label }}</span>
                                <span class="filter-control__arrow"></span>
                            </button>

                            <div v-if="openFilterKey === filter.key" class="filter-control__menu" @click.stop>
                                <button
                                    v-for="option in filter.options"
                                    :key="String(option.value)"
                                    :class="['filter-control__option', { 'is-active': filter.value === option.value }]"
                                    type="button"
                                    @click="selectFilter(filter.key, option.value)"
                                >
                                    <span>{{ option.label }}</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="toolbar__actions">
                        <div class="filter-counter" :aria-label="`筛选后共 ${resultCount} 个技能`">
                            <span class="filter-counter__dot"></span>
                            <span>{{ resultCount }}</span>
                        </div>
                        <button class="reset-button" type="button" @click="resetFilters">
                            <el-icon><RefreshRight /></el-icon>
                            <span>重置筛选</span>
                        </button>
                    </div>
                </section>

                <section class="hero">
                    <div class="hero__eyebrow">Skills Square</div>
                    <div class="hero__headline">
                        <h1>技能广场</h1>
                        <span>({{ totalSkillCount }})</span>
                    </div>
                </section>

                <section class="skills-grid" aria-label="技能列表">
                    <SkillSquareCard
                        v-for="skill in visibleSkills"
                        :key="skill.id"
                        :skill="skill"
                        @click="openSkill(skill.id)"
                    />
                </section>

                <section v-if="!visibleSkills.length" class="skills-empty">
                    <h2>没有找到匹配的技能</h2>
                    <p>试试调整筛选条件，或者先到租户后台确认技能已启用并填写完整字段。</p>
                    <button type="button" @click="resetFilters">重置筛选</button>
                </section>
            </div>

            <MarketingMembershipTip class="membership-tip" @click="goToPricing" />

            <MarketingFloatingDock
                :auto-hide-delay="2000"
                secondary-tool-label="查看会员方案"
                secondary-tool-theme="light"
                :show-tool-icon="false"
                tool-label="立即开通会员"
                @brand-click="goHome"
                @secondary-tool-click="goToPricing"
                @tool-click="goToPricing"
            />
        </section>
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RefreshRight } from '@element-plus/icons-vue'
import { getSkillList } from '@/api/pc-content'
import { extractListData, mapSkillItem } from '@/utils/pc-adapters'

type SortOption = 'latest' | 'updated' | 'downloads' | 'rating'
type FilterKey = 'category' | 'access' | 'sort'
type AccessFilter = 'free' | 'member' | 'paid' | null

definePageMeta({ layout: 'blank' })

const router = useRouter()
const route = useRoute()

useHead({ title: '技能广场' })

const { data: skillsResponse } = await useAsyncData('pc-skills-index', () =>
    getSkillList({ page_no: 1, page_size: 100 })
)

const skillItems = computed(() => extractListData(skillsResponse.value).map((item) => mapSkillItem(item)))
const totalSkillCount = computed(() => skillItems.value.length)
const localRatingTotal = useState<Record<string, number>>('pc-skill-rating-total', () => ({}))

const sortOptions: SortOption[] = ['latest', 'updated', 'downloads', 'rating']
const activeSort = ref<SortOption>('latest')
const openFilterKey = ref<FilterKey | null>(null)
const selectedCategory = ref<string | null>(String(route.query.category || '').trim() || null)
const selectedAccess = ref<AccessFilter>(null)

const allCategoryLabel = '全部分类'
const accessLabelMap: Record<Exclude<AccessFilter, null>, string> = {
    free: '免费',
    member: '会员限免',
    paid: '付费'
}
const sortLabelMap: Record<SortOption, string> = {
    latest: '最新发布',
    updated: '最近更新',
    downloads: '下载',
    rating: '评分'
}

const categoryOptions = computed(() => [
    allCategoryLabel,
    ...new Set(skillItems.value.map((item) => item.category).filter(Boolean))
])

const filterButtons = computed(() => [
    {
        key: 'category' as const,
        label: selectedCategory.value || '类别',
        value: selectedCategory.value || allCategoryLabel,
        options: categoryOptions.value.map((item) => ({ label: item, value: item }))
    },
    {
        key: 'access' as const,
        label: selectedAccess.value ? accessLabelMap[selectedAccess.value] : '全部权限',
        value: selectedAccess.value || 'all',
        options: [
            { label: '全部权限', value: 'all' },
            { label: '免费', value: 'free' },
            { label: '会员限免', value: 'member' },
            { label: '付费', value: 'paid' }
        ]
    },
    {
        key: 'sort' as const,
        label: sortLabelMap[activeSort.value],
        value: activeSort.value,
        options: sortOptions.map((item) => ({ label: sortLabelMap[item], value: item }))
    }
])

const filteredSkills = computed(() => {
    const items = skillItems.value.filter((item) => {
        if (selectedCategory.value && item.category !== selectedCategory.value) return false
        if (selectedAccess.value === 'free' && (item.memberFree || (item.price ?? 0) > 0)) return false
        if (selectedAccess.value === 'member' && !item.memberFree) return false
        if (selectedAccess.value === 'paid' && (item.memberFree || (item.price ?? 0) <= 0)) return false
        return true
    })

    return items.sort((left, right) => {
        if (activeSort.value === 'downloads') return right.downloads - left.downloads
        if (activeSort.value === 'rating') {
            const leftRating = Number(left.repoStars || 0) + Number(localRatingTotal.value[String(left.id || '')] || 0)
            const rightRating = Number(right.repoStars || 0) + Number(localRatingTotal.value[String(right.id || '')] || 0)
            return rightRating - leftRating
        }
        return Date.parse(right.updatedAt || '1970-01-01') - Date.parse(left.updatedAt || '1970-01-01')
    })
})

const visibleSkills = computed(() => filteredSkills.value)
const resultCount = computed(() => filteredSkills.value.length)

const toggleFilter = (key: FilterKey) => {
    openFilterKey.value = openFilterKey.value === key ? null : key
}

const selectFilter = (key: FilterKey, value: string | number) => {
    if (key === 'category') selectedCategory.value = value === allCategoryLabel ? null : String(value)
    if (key === 'access') {
        selectedAccess.value = value === 'free' || value === 'member' || value === 'paid' ? (value as AccessFilter) : null
    }
    if (key === 'sort') activeSort.value = value as SortOption
    openFilterKey.value = null
}

const resetFilters = () => {
    selectedCategory.value = null
    selectedAccess.value = null
    activeSort.value = 'latest'
    openFilterKey.value = null
}

const openSkill = (id: string) => router.push(`/skills/${id}`)
const goToPricing = () => router.push({ path: '/', hash: '#pricing-section' })
const goHome = () => router.push('/')
const handleDocumentClick = () => {
    openFilterKey.value = null
}

onMounted(() => document.addEventListener('click', handleDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', handleDocumentClick))
</script>

<style lang="scss" scoped>
.skills-square-page {
    min-width: 0;
    background: #f8f8f8;
}

.skills-square {
    position: relative;
    padding: 24px 0 220px;
    overflow-x: hidden;

    &__shell {
        width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
        margin: 0 auto;
        position: relative;
    }
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    min-height: 62px;
    padding: 8px;
    border-radius: 12px;
    background: #ededed;

    &__filters,
    &__actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
}

.filter-control {
    position: relative;
    width: 105px;
    flex-shrink: 0;

    &--wide {
        width: 120px;
    }

    &__trigger {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        height: 46px;
        border: 0;
        border-radius: 8px;
        background: #fff;
        color: #222;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
    }

    &__arrow {
        width: 8px;
        height: 8px;
        border-right: 1.5px solid #222;
        border-bottom: 1.5px solid #222;
        transform: rotate(45deg) translateY(-2px);
        transform-origin: center;
        transition: transform 0.2s ease;
    }

    &.is-open &__arrow {
        transform: rotate(-135deg) translateY(-1px);
    }

    &__menu {
        position: absolute;
        top: calc(100% + 8px);
        left: 0;
        z-index: 10;
        width: 100%;
        min-width: 100%;
        padding: 8px;
        border-radius: 14px;
        background: #fff;
    }

    &__option {
        display: flex;
        align-items: center;
        width: 100%;
        min-height: 38px;
        padding: 0 12px;
        border: 0;
        border-radius: 10px;
        background: transparent;
        color: #666;
        font-size: 14px;
        cursor: pointer;
        text-align: left;
        white-space: nowrap;
    }

    &__option.is-active,
    &__option:hover {
        background: #fff2e8;
        color: #ff6600;
    }
}

.filter-counter,
.reset-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 46px;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font-size: 14px;
    font-weight: 500;
}

.filter-counter {
    width: 46px;
    position: relative;

    &__dot {
        position: absolute;
        inset: 7px;
        border-radius: 24px;
        background: #ff6600;
    }

    span:last-child {
        position: relative;
        z-index: 1;
        color: #fff;
    }
}

.reset-button {
    gap: 8px;
    width: 120px;
    border: 0;
    cursor: pointer;
}

.hero {
    width: 352px;
    padding: 80px 0;
    margin: 0 auto;
    text-align: center;

    &__eyebrow {
        color: #a1a1a1;
        font-size: 16px;
        line-height: 1;
        letter-spacing: 0.05em;
    }

    &__headline {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 40px;
        margin-top: 40px;

        h1 {
            margin: 0;
            color: #222;
            font-size: 88px;
            line-height: 1;
            font-weight: 600;
        }

        span {
            display: block;
            width: 100%;
            color: #a1a1a1;
            font-size: 44px;
            line-height: 1;
            font-weight: 500;
        }
    }
}

.skills-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px;
}

.skills-empty {
    margin-top: 30px;
    padding: 68px 20px;
    border-radius: 16px;
    background: #fff;
    text-align: center;

    h2 {
        margin: 0;
        color: #222;
        font-size: 28px;
        line-height: 1.3;
    }

    p {
        margin: 16px 0 0;
        color: #8b8b8b;
        font-size: 16px;
        line-height: 1.8;
    }

    button {
        margin-top: 24px;
        min-width: 132px;
        height: 44px;
        border: 0;
        border-radius: 10px;
        background: #222;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
    }
}

.membership-tip {
    position: absolute;
    right: calc((100% - var(--pc-shell-max, 1596px)) / 2);
    top: 114px;
}

@media (max-width: 1760px) {
    .membership-tip {
        display: none;
    }
}

@media (max-width: 900px) {
    .hero__headline {
        gap: 24px;
    }

    .hero__headline h1 {
        font-size: 56px;
    }

    .hero__headline span {
        font-size: 30px;
    }
}
</style>
