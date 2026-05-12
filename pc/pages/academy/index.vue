<template>
    <div class="academy-center-page">
        <MarketingHeader />

        <section class="academy-center">
            <div class="academy-center__shell">
                <section class="toolbar">
                    <div class="toolbar__filters">
                        <div
                            v-for="filter in filterButtons"
                            :key="filter.key"
                            :class="[
                                'filter-control',
                                {
                                    'filter-control--wide': filter.key === 'access' || filter.key === 'downloads',
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
                        <div class="filter-counter" :aria-label="`筛选后共 ${resultCount} 门课程`">
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
                    <div class="hero__eyebrow">College Center</div>
                    <div class="hero__headline">
                        <h1>课程中心</h1>
                        <span>{{ totalCourseCount }} 门课程</span>
                    </div>
                </section>

                <section class="academy-grid" aria-label="课程列表">
                    <AcademyCourseCard
                        v-for="course in visibleCourses"
                        :key="course.id"
                        :card-id="`course-${course.id}`"
                        :course="course"
                        title-tag="h2"
                    />
                </section>

                <section v-if="!visibleCourses.length" class="academy-empty">
                    <h2>没有找到匹配的课程</h2>
                    <p>试试调整筛选条件，或者重置筛选后再看看。</p>
                    <button type="button" @click="resetFilters">重置筛选</button>
                </section>
            </div>

            <MarketingFloatingDock
                :auto-hide-delay="2000"
                tool-label="立即开通会员"
                :show-tool-icon="false"
                @brand-click="goHome"
                @tool-click="goToPricing"
            />

            <MarketingMembershipTip class="membership-tip" @click="goToPricing" />
        </section>
    </div>
</template>

<script lang="ts" setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RefreshRight } from '@element-plus/icons-vue'
import { getAcademyList } from '@/api/pc-content'
import type { AcademyCourseAccess } from '@/constants/academy-courses'
import { extractListData, mapAcademyCourseItem } from '@/utils/pc-adapters'

type FilterKey = 'category' | 'access' | 'downloads'
definePageMeta({ layout: 'blank' })
const router = useRouter()
const { data: academyResponse } = await useAsyncData('pc-academy-index', () => getAcademyList({ page_no: 1, page_size: 100 }))
const academyCourses = computed(() => extractListData(academyResponse.value).map((item) => mapAcademyCourseItem(item)))
const totalCourseCount = computed(() => academyCourses.value.length)
const openFilterKey = ref<FilterKey | null>(null)
const selectedCategory = ref<string | null>(null)
const selectedAccess = ref<AcademyCourseAccess | null>(null)
const selectedDownloadThreshold = ref<number | null>(null)
useHead({ title: 'Academy' })

const allCategoryLabel = '全部分类'
const accessLabelMap: Record<Exclude<AcademyCourseAccess, null>, string> = {
    free: '免费',
    member: '会员',
    paid: '付费'
}

const categoryOptions = computed(() => [allCategoryLabel, ...new Set(academyCourses.value.map((item) => item.category).filter(Boolean))])
const accessLabel = computed(() => selectedAccess.value ? accessLabelMap[selectedAccess.value] : '权限')
const downloadLabel = computed(() => selectedDownloadThreshold.value ? `下载量 ${selectedDownloadThreshold.value}+` : '下载量')
const filterButtons = computed(() => [
    { key: 'category' as const, label: selectedCategory.value || '分类', value: selectedCategory.value || allCategoryLabel, options: categoryOptions.value.map((item) => ({ label: item, value: item })) },
    { key: 'access' as const, label: accessLabel.value, value: selectedAccess.value || 'all', options: [{ label: '全部权限', value: 'all' }, { label: '免费', value: 'free' }, { label: '会员', value: 'member' }, { label: '付费', value: 'paid' }] },
    { key: 'downloads' as const, label: downloadLabel.value, value: selectedDownloadThreshold.value || 'downloads', options: [{ label: '全部下载量', value: 'all' }, { label: '2000+', value: 2000 }, { label: '3000+', value: 3000 }, { label: '4500+', value: 4500 }] }
])

const filteredCourses = computed(() => academyCourses.value.filter((item) => {
    if (selectedCategory.value && item.category !== selectedCategory.value) return false
    if (selectedAccess.value && item.access !== selectedAccess.value) return false
    if (selectedDownloadThreshold.value && item.downloads < selectedDownloadThreshold.value) return false
    return true
}))
const visibleCourses = computed(() => filteredCourses.value.slice(0, 12))
const resultCount = computed(() => filteredCourses.value.length)
const toggleFilter = (key: FilterKey) => { openFilterKey.value = openFilterKey.value === key ? null : key }
const selectFilter = (key: FilterKey, value: string | number) => {
    if (key === 'category') selectedCategory.value = value === allCategoryLabel ? null : String(value)
    if (key === 'access') selectedAccess.value = value === 'free' || value === 'member' || value === 'paid' ? (value as AcademyCourseAccess) : null
    if (key === 'downloads') selectedDownloadThreshold.value = typeof value === 'number' ? value : null
    openFilterKey.value = null
}
const resetFilters = () => { selectedCategory.value = null; selectedAccess.value = null; selectedDownloadThreshold.value = null; openFilterKey.value = null }
const goToPricing = () => router.push({ path: '/', hash: '#pricing-section' })
const goHome = () => router.push('/')
const handleDocumentClick = () => { openFilterKey.value = null }
onMounted(() => document.addEventListener('click', handleDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', handleDocumentClick))
</script>

<style lang="scss" scoped>
.academy-center-page {
    min-width: 0;
    background: #f8f8f8;
}

.academy-center {
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
        box-shadow: 0 16px 32px rgba(34, 34, 34, 0.08);
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
        letter-spacing: 0.05em;
        line-height: 1;
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

.academy-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 40px 20px;
}

.academy-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    margin-top: 48px;
    padding: 72px 32px;
    border-radius: 24px;
    background: #fff;

    h2,
    p {
        margin: 0;
    }

    h2 {
        color: #222;
        font-size: 28px;
        font-weight: 600;
    }

    p {
        color: #8f8f8f;
        font-size: 15px;
    }

    button {
        height: 44px;
        padding: 0 22px;
        border: 0;
        border-radius: 999px;
        background: #222;
        color: #fff;
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
