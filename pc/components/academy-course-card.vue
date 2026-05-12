<template>
    <NuxtLink :id="cardId" :to="resolvedTo" class="academy-course-card">
        <img class="academy-course-card__image" :src="course.image" :alt="course.title" />

        <div class="academy-course-card__body">
            <component :is="titleTag" class="academy-course-card__title">
                {{ course.title }}
            </component>

            <div
                :class="[
                    'academy-course-card__price-row',
                    { 'academy-course-card__price-row--badge-only': course.access !== 'paid' }
                ]"
            >
                <span v-if="course.access === 'paid'" class="academy-course-card__price">
                    {{ priceLabel }}
                </span>

                <span
                    v-else
                    :class="[
                        'academy-course-card__badge',
                        {
                            'academy-course-card__badge--free': course.access === 'free',
                            'academy-course-card__badge--member': course.access === 'member'
                        }
                    ]"
                >
                    {{ badgeLabel }}
                </span>
            </div>

            <div class="academy-course-card__meta">
                <span>{{ course.duration }}</span>
                <span>{{ course.rating }}</span>
            </div>
        </div>
    </NuxtLink>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import type { AcademyCourseItem } from '@/constants/academy-courses'

const props = withDefaults(
    defineProps<{
        course: AcademyCourseItem
        titleTag?: 'h2' | 'h3'
        cardId?: string
        to?: string
    }>(),
    {
        titleTag: 'h3',
        cardId: '',
        to: ''
    }
)

const resolvedTo = computed(() => props.to || `/academy/${props.course.id}`)

const priceLabel = computed(() =>
    typeof props.course.price === 'number' ? '\uFFE5' + props.course.price.toFixed(2) : ''
)

const badgeLabel = computed(() => {
    if (props.course.access === 'free') return '免费'
    if (props.course.access === 'member') return '会员限免'
    return ''
})
</script>

<style lang="scss" scoped>
.academy-course-card {
    position: relative;
    display: block;
    background: #fff;
    border-radius: 24px;
    overflow: hidden;
    box-shadow: none;
    color: #222;
    text-decoration: none;

    &::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 220px;
        border-radius: 24px 24px 0 0;
        background: linear-gradient(180deg, rgba(0, 0, 0, 0) 0%, rgba(0, 0, 0, 0.2) 100%);
        opacity: 0;
        pointer-events: none;
        transition: opacity 0.22s ease;
    }

    &:hover::after {
        opacity: 1;
    }

    &__image {
        display: block;
        width: 100%;
        height: 220px;
        object-fit: cover;
    }

    &__body {
        display: flex;
        flex-direction: column;
        padding: 16px 20px 14px 16px;
    }

    &__title {
        min-height: 28px;
        margin: 0 0 10px;
        color: #222;
        font-size: 20px;
        line-height: 28px;
        font-weight: 600;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    &__price-row {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        min-height: 46px;
    }

    &__price-row--badge-only {
        align-items: center;

        .academy-course-card__badge {
            margin: 0;
        }
    }

    &__price {
        color: #ff6b00;
        font-size: 32px;
        line-height: 1;
        font-weight: 700;
        white-space: nowrap;
    }

    &__badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        height: 32px;
        padding: 0 12px;
        border-radius: 8px;
        font-size: 14px;
        line-height: 16px;
        font-weight: 500;
        white-space: nowrap;
    }

    &__badge--member {
        background: #fff4e8;
        color: #ff6b00;
    }

    &__badge--free {
        background: #f8f8f8;
        color: #a1a1a1;
    }

    &__meta {
        display: flex;
        justify-content: space-between;
        margin-top: 10px;
        color: #666;
        font-size: 14px;
        line-height: 1;
    }
}
</style>
