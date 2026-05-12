<template>
    <article
        :class="['skills-square-card', { 'is-clickable': clickable }]"
        @click="handleClick"
    >
        <header class="skills-square-card__topbar">
            <div class="skills-square-card__traffic-lights" aria-hidden="true">
                <span class="is-red"></span>
                <span class="is-yellow"></span>
                <span class="is-green"></span>
            </div>

            <div class="skills-square-card__filename" :title="fileName">
                {{ fileName }}
            </div>

            <div class="skills-square-card__hotness">
                <span class="skills-square-card__hotness-star">★</span>
                <span>{{ formatCompact(totalRating) }}</span>
            </div>
        </header>

        <div class="skills-square-card__body">
            <div class="skills-square-card__identity">
                <img class="skills-square-card__avatar" :src="defaultAvatar" alt="avatar" />

                <div class="skills-square-card__meta">
                    <div class="skills-square-card__meta-line">
                        <span class="skills-square-card__from">来自</span>
                        <span class="skills-square-card__author">官方</span>
                    </div>
                    <div class="skills-square-card__category">
                        {{ skill.category || '未分类' }}
                    </div>
                </div>
            </div>

            <div class="skills-square-card__subtitle">
                {{ skill.title }}
            </div>

            <p class="skills-square-card__summary">
                {{ skill.summary || skill.description || '暂无简介' }}
            </p>

            <div
                :class="[
                    'skills-square-card__price-row',
                    { 'skills-square-card__price-row--badge-only': accessState !== 'paid' }
                ]"
            >
                <span v-if="accessState === 'paid'" class="skills-square-card__price">
                    {{ accessLabel }}
                </span>

                <span
                    v-else
                    :class="[
                        'skills-square-card__badge',
                        {
                            'skills-square-card__badge--free': accessState === 'free',
                            'skills-square-card__badge--member': accessState === 'member'
                        }
                    ]"
                >
                    {{ accessLabel }}
                </span>
            </div>
        </div>

        <footer class="skills-square-card__footer">
            <span>{{ skill.updatedDate || skill.updatedAt || '--' }}</span>
            <span
                :class="[
                    'skills-square-card__favorite',
                    { 'is-rated': hasRated, 'is-unrated': !hasRated }
                ]"
                aria-hidden="true"
            >
                {{ hasRated ? '★' : '☆' }}
            </span>
        </footer>
    </article>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import defaultAvatar from '@/assets/images/default-avatar.svg'
import type { SkillItem } from '@/constants/skills'

const props = withDefaults(
    defineProps<{
        skill: SkillItem
        clickable?: boolean
    }>(),
    {
        clickable: true
    }
)

const emit = defineEmits<{
    (event: 'click'): void
}>()

const localRatingTotal = useState<Record<string, number>>('pc-skill-rating-total', () => ({}))
const localRatingSelected = useState<Record<string, number>>('pc-skill-rating-selected', () => ({}))

const formatCompact = (value: number) => {
    if (value >= 10000) {
        return `${(value / 10000).toFixed(1).replace(/\.0$/, '')}w`
    }

    if (value >= 1000) {
        return `${(value / 1000).toFixed(1).replace(/\.0$/, '')}k`
    }

    return String(value)
}

const fileName = computed(() => {
    const raw = props.skill.slug || props.skill.id || props.skill.title || 'skill-card'
    return `${String(raw).replace(/\s+/g, '-').toLowerCase()}.md`
})

const totalRating = computed(() => {
    const skillId = String(props.skill.id || '')
    return Number(props.skill.repoStars || 0) + Number(localRatingTotal.value[skillId] || 0)
})

const hasRated = computed(() => {
    const skillId = String(props.skill.id || '')
    return Number(localRatingSelected.value[skillId] || 0) > 0
})

const accessState = computed<'paid' | 'free' | 'member'>(() => {
    if (props.skill.memberFree) return 'member'
    if (typeof props.skill.price === 'number' && props.skill.price > 0) return 'paid'
    return 'free'
})

const accessLabel = computed(() => {
    if (accessState.value === 'member') return '会员限免'
    if (accessState.value === 'paid') return `¥${(props.skill.price || 0).toFixed(2)}`
    return '免费'
})

const handleClick = () => {
    if (!props.clickable) return
    emit('click')
}
</script>

<style lang="scss" scoped>
.skills-square-card {
    display: flex;
    flex-direction: column;
    min-height: 244px;
    border: 1px solid #aeb7c4;
    border-radius: 14px;
    background: #fff;
    overflow: hidden;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease;

    &.is-clickable {
        cursor: pointer;

        &:hover {
            transform: translateY(-4px);
            border-color: rgba(255, 107, 0, 0.52);
            box-shadow:
                0 6px 14px rgba(255, 107, 0, 0.05),
                0 0 0 1px rgba(255, 107, 0, 0.035);

            .skills-square-card__topbar {
                border-bottom-color: rgba(255, 107, 0, 0.26);
            }

            .skills-square-card__footer {
                border-top-color: rgba(255, 107, 0, 0.2);
            }
        }
    }

    &__topbar {
        display: grid;
        grid-template-columns: auto minmax(0, 1fr) auto;
        align-items: center;
        gap: 12px;
        min-height: 34px;
        padding: 0 14px;
        border-bottom: 1px solid #aeb7c4;
        background: linear-gradient(180deg, #fbfcfe 0%, #f1f4f8 100%);
    }

    &__traffic-lights {
        display: inline-flex;
        align-items: center;
        gap: 6px;

        span {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .is-red {
            background: #ff7a7a;
        }

        .is-yellow {
            background: #ffd76a;
        }

        .is-green {
            background: #76d89c;
        }
    }

    &__filename,
    &__hotness {
        color: #7d8795;
        font-family: 'JetBrains Mono', 'Consolas', monospace;
        font-size: 12px;
        line-height: 1;
        font-weight: 700;
    }

    &__filename {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    &__hotness {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }

    &__hotness-star {
        color: #ffb300;
        font-size: 11px;
    }

    &__body {
        display: flex;
        flex: 1;
        flex-direction: column;
        gap: 14px;
        padding: 16px 16px 14px;
    }

    &__identity {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    &__avatar {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
    }

    &__meta {
        display: flex;
        min-width: 0;
        flex: 1;
        flex-direction: column;
        gap: 4px;
    }

    &__meta-line {
        display: flex;
        align-items: center;
        gap: 6px;
        min-width: 0;
        font-size: 12px;
        line-height: 1.3;
        font-weight: 700;
    }

    &__from {
        color: #7d8795;
        white-space: nowrap;
    }

    &__author {
        color: #2f80ff;
        white-space: nowrap;
    }

    &__category {
        color: #9aa3b2;
        font-size: 12px;
        line-height: 1.3;
    }

    &__subtitle {
        color: #1f2430;
        font-size: 16px;
        line-height: 1.3;
        font-weight: 600;
    }

    &__price-row {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 12px;
        min-height: 46px;
    }

    &__price-row--badge-only {
        .skills-square-card__badge {
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

    &__summary {
        margin: 0;
        color: #707b8a;
        font-size: 13px;
        line-height: 1.7;
        word-break: break-word;
        display: -webkit-box;
        overflow: hidden;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 3;
    }

    &__footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: auto;
        padding: 12px 16px;
        border-top: 1px solid #d7dde7;
        color: #8d98a8;
        font-family: 'JetBrains Mono', 'Consolas', monospace;
        font-size: 12px;
        line-height: 1;
        font-weight: 700;
    }

    &__favorite {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 16px;
        height: 16px;
        font-size: 15px;

        &.is-rated {
            color: #ffb300;
        }

        &.is-unrated {
            color: #c7cfdb;
        }
    }
}
</style>
