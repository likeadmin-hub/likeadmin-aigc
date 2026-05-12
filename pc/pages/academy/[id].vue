<template>
    <div class="academy-detail-page">
        <MarketingHeader />

        <section class="academy-detail">
            <div class="academy-detail__shell">
                <button class="academy-detail__back" type="button" @click="goBack">
                    <el-icon><ArrowLeft /></el-icon>
                    <span>返回课程列表</span>
                </button>

                <div class="academy-detail__grid">
                    <div class="academy-detail__main">
                        <section :class="['video-panel', { 'is-locked': isLocked, 'is-cinema': isCinemaMode }]">
                            <img v-if="isLocked" class="video-panel__cover" :src="activeHeroCover" :alt="courseDetail.title" />
                            <div v-if="isLocked" class="video-panel__scrim"></div>
                            <video
                                v-else
                                :key="videoPlayerKey"
                                class="video-panel__video"
                                :poster="activeHeroCover"
                                :src="demoVideoSrc"
                                controls
                                preload="metadata"
                                playsinline
                                controlslist="nodownload"
                            ></video>

                            <div v-if="isLocked" class="video-panel__lock">
                                <div class="video-panel__lock-icon">
                                    <el-icon><Lock /></el-icon>
                                </div>
                                <span class="video-panel__lock-kicker">
                                    {{ isLockedMember ? '会员专享内容' : '付费解锁内容' }}
                                </span>
                                <h2>{{ courseDetail.unlockTitle }}</h2>
                                <p>{{ courseDetail.unlockDescription }}</p>
                                <button class="video-panel__cta" type="button" @click="goToPricing">
                                    {{ courseDetail.unlockActionLabel }}
                                </button>
                            </div>
                        </section>

                        <section class="detail-copy">
                            <h2>{{ courseDetail.title }}</h2>

                            <div class="detail-copy__box">
                                <p class="detail-copy__paragraph">
                                    <span>{{ displayedDescription }}</span>
                                    <button
                                        v-if="hasLongDescription"
                                        class="detail-copy__toggle"
                                        type="button"
                                        @click="toggleDescription"
                                    >
                                        {{ expandedDescription ? '收起' : '展开' }}
                                    </button>
                                </p>
                            </div>

                            <div class="detail-copy__divider"></div>

                            <div class="detail-copy__tags">
                                <span v-for="tag in courseDetail.tags" :key="tag">{{ tag }}</span>
                            </div>
                        </section>

                        <section class="facts-card" aria-label="课程信息">
                            <article v-for="fact in courseDetail.facts" :key="fact.label" class="facts-card__item">
                                <span>{{ fact.label }}</span>
                                <strong>{{ fact.value }}</strong>
                            </article>
                        </section>

                        <section class="comments-section">
                            <header class="comments-section__header">
                                <h2>{{ displayedCommentCount }} 条评论</h2>
                            </header>

                            <div v-if="courseDetail.canComment" class="comment-entry">
                                <div class="comment-entry__avatar">
                                    <img v-if="currentUserAvatar" :src="currentUserAvatar" :alt="currentUserName" />
                                    <span v-else>{{ currentUserInitial }}</span>
                                </div>
                                <div class="comment-entry__composer">
                                    <textarea
                                        v-model="commentDraft"
                                        class="comment-entry__input"
                                        rows="1"
                                        placeholder="写下你对这门课程的看法..."
                                        @focus="isCommentEditorActive = true"
                                    ></textarea>

                                    <div v-if="isCommentEditorActive" class="comment-entry__actions">
                                        <button class="comment-entry__action comment-entry__action--ghost" type="button" @click="cancelComment">
                                            取消
                                        </button>
                                        <button class="comment-entry__action" type="button" :disabled="!canSubmitComment" @click="submitComment">
                                            发布评论
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="comments-list">
                                <article
                                    v-for="comment in displayedComments"
                                    :key="comment.id"
                                    :class="['comment-card', { 'comment-card--featured': comment.featured }]"
                                >
                                    <img class="comment-card__avatar" :src="comment.avatar" :alt="comment.author" />

                                    <div class="comment-card__body">
                                        <div class="comment-card__meta">
                                            <strong>{{ comment.author }}</strong>
                                            <span>{{ comment.publishedAt }}</span>
                                        </div>
                                        <p>{{ comment.content }}</p>

                                        <div v-if="comment.featured && comment.replyTo" class="comment-card__reply">
                                            <div class="comment-card__reply-head">
                                                <span>{{ comment.author }}</span>
                                                <span>回复 {{ comment.replyTo }}</span>
                                            </div>
                                            <p>{{ comment.content }}</p>
                                        </div>

                                        <div class="comment-card__actions">
                                            <span>赞 {{ comment.likes }}</span>
                                            <span>踩 {{ comment.dislikes }}</span>
                                            <span>回复 {{ comment.replies }}</span>
                                        </div>
                                    </div>
                                </article>
                            </div>
                        </section>
                    </div>

                    <aside class="academy-detail__sidebar">
                        <section :class="['summary-card', { 'is-locked': isLocked }]">
                            <template v-if="!isLocked">
                                <h1>{{ courseDetail.title }}</h1>
                                <div class="summary-card__rating">
                                    <span class="summary-card__rating-label">课程评分</span>
                                    <div class="summary-card__stars">
                                        <el-icon v-for="star in 5" :key="star"><StarFilled /></el-icon>
                                    </div>
                                    <span class="summary-card__rating-value">{{ reviewValue }}</span>
                                </div>
                            </template>

                            <template v-else-if="isLockedPaid">
                                <h1>{{ courseDetail.title }}</h1>
                                <div class="summary-card__rating">
                                    <span class="summary-card__rating-label">课程评分</span>
                                    <div class="summary-card__stars">
                                        <el-icon v-for="star in 5" :key="star"><StarFilled /></el-icon>
                                    </div>
                                </div>
                                <div class="summary-card__price">{{ paidCoursePriceLabel }}</div>
                                <button class="summary-card__action" type="button" @click="goToPricing">
                                    {{ courseDetail.unlockActionLabel }}
                                </button>
                            </template>

                            <template v-else>
                                <h1>{{ courseDetail.title }}</h1>
                                <div class="summary-card__rating">
                                    <span class="summary-card__rating-label">课程评分</span>
                                    <div class="summary-card__stars">
                                        <el-icon v-for="star in 5" :key="star"><StarFilled /></el-icon>
                                    </div>
                                    <span class="summary-card__rating-value">{{ reviewValue }}</span>
                                </div>
                                <div class="summary-card__member-tip">会员可免费看</div>
                                <button class="summary-card__action" type="button" @click="goToPricing">
                                    {{ courseDetail.unlockActionLabel }}
                                </button>
                            </template>
                        </section>

                        <section class="playlist-card">
                            <header class="playlist-card__header">
                                <h2>{{ courseDetail.playlistTitle }}</h2>
                                <span>{{ courseDetail.playlistProgress }}</span>
                            </header>

                            <div class="playlist-card__list">
                                <button
                                    v-for="lesson in courseDetail.playlist"
                                    :key="lesson.id"
                                    :class="['playlist-card__item', { 'is-active': lesson.id === activeLessonId }]"
                                    type="button"
                                    @click="selectLesson(lesson.id)"
                                >
                                    <img class="playlist-card__thumb" :src="lesson.thumbnail" :alt="lesson.title" />
                                    <div class="playlist-card__copy">
                                        <p>{{ lesson.title }}</p>
                                        <div class="playlist-card__meta">
                                            <span>{{ lesson.duration }}</span>
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </section>

                        <section class="related-section">
                            <h2>{{ courseDetail.relatedTitle }}</h2>

                            <article
                                v-for="related in courseDetail.relatedCourses"
                                :key="related.id"
                                class="related-card is-clickable"
                                @click="openRelatedCourse(related.id)"
                            >
                                <img class="related-card__image" :src="related.image" :alt="related.title" />
                                <div class="related-card__body">
                                    <h3>{{ related.title }}</h3>
                                    <span v-if="hasNumericPrice(related.priceLabel)" class="related-card__price">
                                        {{ related.priceLabel }}
                                    </span>
                                    <span
                                        v-else
                                        :class="[
                                            'related-card__badge',
                                            related.priceLabel.includes('免费')
                                                ? 'related-card__badge--free'
                                                : 'related-card__badge--member'
                                        ]"
                                    >
                                        {{ related.priceLabel }}
                                    </span>
                                    <div class="related-card__meta">
                                        <span>{{ related.duration }}</span>
                                        <span>{{ related.rating }}</span>
                                    </div>
                                </div>
                            </article>
                        </section>
                    </aside>
                </div>
            </div>

            <MarketingFloatingDock
                :auto-hide-delay="2000"
                tool-label="立即开通会员"
                :show-tool-icon="false"
                @brand-click="goHome"
                @tool-click="goToPricing"
            />
        </section>
    </div>
</template>

<script lang="ts" setup>
import { computed, ref, watch } from 'vue'
import { ArrowLeft, Lock, StarFilled } from '@element-plus/icons-vue'
import { getAcademyComments, getAcademyDetail, getAcademyList, postAcademyComment } from '@/api/pc-content'
import { getAcademyCourseDetailById, type AcademyCourseComment } from '@/constants/academy-courses'
import { useUserStore } from '@/stores/user'
import { extractDetailData, extractListData, mapAcademyComment, mapAcademyCourseItem, mapAcademyDetail } from '@/utils/pc-adapters'

definePageMeta({ layout: 'blank' })
const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const courseId = computed(() => String(route.params.id || ''))
const [{ data: detailResponse }, { data: commentsResponse }, { data: relatedResponse }] = await Promise.all([
  useAsyncData(`pc-academy-detail-${courseId.value}`, () => getAcademyDetail(courseId.value)),
  useAsyncData(`pc-academy-comments-${courseId.value}`, () => getAcademyComments({ course_id: courseId.value, page_no: 1, page_size: 50 })),
  useAsyncData('pc-academy-related', () => getAcademyList({ page_no: 1, page_size: 12 }))
])
const rawDetail = computed(() => extractDetailData(detailResponse.value))
const fallbackCourseDetail = computed(() => getAcademyCourseDetailById(courseId.value) || null)
const mappedComments = computed(() => extractListData(commentsResponse.value).map((item) => mapAcademyComment(item)))
const relatedCourses = computed(() => extractListData(relatedResponse.value).map((item) => mapAcademyCourseItem(item)))
const courseDetail = computed(() => {
  if (rawDetail.value?.id) {
    return mapAcademyDetail(rawDetail.value, mappedComments.value, relatedCourses.value)
  }

  return fallbackCourseDetail.value || mapAcademyDetail({}, mappedComments.value, relatedCourses.value)
})
if (!courseDetail.value.id) throw createError({ statusCode: 404, statusMessage: 'Course not found' })

const activeLessonId = ref('')
const expandedDescription = ref(false)
const isCinemaMode = ref(false)
const isPlaying = ref(false)
const playbackRate = ref('1x')
const playbackRates = ['0.5x', '1x', '1.5x', '2x']
const commentDraft = ref('')
const isCommentEditorActive = ref(false)
const localComments = ref<AcademyCourseComment[]>([])
const currentUserAvatar = computed(() => String(userStore.userInfo?.avatar || '').trim())
const currentUserName = computed(() => String(userStore.userInfo?.nickname || userStore.userInfo?.sn || 'User'))
const currentUserInitial = computed(() => currentUserName.value.trim().slice(0, 1) || 'U')
const isLocked = computed(() => courseDetail.value.detailState !== 'free')
const isLockedMember = computed(() => courseDetail.value.detailState === 'locked-member')
const isLockedPaid = computed(() => courseDetail.value.detailState === 'locked-paid')
const activeLesson = computed(() => courseDetail.value.playlist.find((item) => item.id === activeLessonId.value) || courseDetail.value.playlist[0])
const demoVideoSrc = '/academy/course-preview.mp4'
const activeHeroCover = computed(() => activeLesson.value?.thumbnail || courseDetail.value.heroMedia.cover)
const videoPlayerKey = computed(() => `${courseDetail.value.id}-${activeLessonId.value}`)
const displayedComments = computed(() => localComments.value)
const displayedCommentCount = computed(() => localComments.value.length)
const canSubmitComment = computed(() => commentDraft.value.trim().length > 0)
const activeLessonIndex = computed(() => Math.max(courseDetail.value.playlist.findIndex((item) => item.id === activeLessonId.value), 0))
const progressPercent = computed(() => { if (isLocked.value) return 0; const base = 12 + activeLessonIndex.value * 12; return isPlaying.value ? Math.min(base + 20, 92) : Math.min(base, 70) })
const currentTimeLabel = computed(() => (isPlaying.value ? '01:28' : '00:00'))
const playbackHint = computed(() => isPlaying.value ? `Lesson ${activeLessonIndex.value + 1} 路 speed ${playbackRate.value}` : 'Use the play button to preview this lesson')
const reviewValue = computed(() => courseDetail.value.reviewSummary.match(/[0-9.]+\/5/)?.[0] ?? courseDetail.value.reviewSummary)
const hasLongDescription = computed(() => courseDetail.value.description.length > 120)
const displayedDescription = computed(() => expandedDescription.value || !hasLongDescription.value ? courseDetail.value.description : `${courseDetail.value.description.slice(0, 128).trimEnd()}...`)
const paidPriceLabel = computed(() => (courseDetail.value.price ? `楼${courseDetail.value.price.toFixed(2)}` : ''))
const paidCoursePriceLabel = computed(() =>
  typeof courseDetail.value.price === 'number' && courseDetail.value.price > 0
    ? `\uFFE5${courseDetail.value.price.toFixed(2)}`
    : ''
)
useHead(() => ({ title: courseDetail.value.title }))
watch(() => courseDetail.value.id, () => {
  activeLessonId.value = courseDetail.value.playlist[0]?.id || ''
  expandedDescription.value = false
  isCinemaMode.value = false
  isPlaying.value = false
  playbackRate.value = '1x'
  commentDraft.value = ''
  isCommentEditorActive.value = false
  localComments.value = courseDetail.value.comments.map((comment) => ({ ...comment }))
}, { immediate: true })
const goBack = () => router.push('/academy')
const goHome = () => router.push('/')
const goToPricing = () => router.push({ path: '/', hash: '#pricing-section' })
const togglePlayback = () => { if (isLocked.value) return goToPricing(); isPlaying.value = !isPlaying.value }
const cyclePlaybackRate = () => { if (isLocked.value) return; const currentIndex = playbackRates.indexOf(playbackRate.value); playbackRate.value = playbackRates[(currentIndex + 1) % playbackRates.length] }
const toggleCinemaMode = () => { if (isLocked.value) return; isCinemaMode.value = !isCinemaMode.value }
const selectLesson = (lessonId: string) => { activeLessonId.value = lessonId; isPlaying.value = false }
const cancelComment = () => { commentDraft.value = ''; isCommentEditorActive.value = false }
const hasNumericPrice = (label: string) => /\d/.test(String(label || ''))
const submitComment = async () => {
  const content = commentDraft.value.trim()
  if (!content) return
  try {
    await postAcademyComment({ course_id: courseId.value, content })
    const now = new Date()
    const month = `${now.getMonth() + 1}`.padStart(2, '0')
    const day = `${now.getDate()}`.padStart(2, '0')
    const hours = `${now.getHours()}`.padStart(2, '0')
    const minutes = `${now.getMinutes()}`.padStart(2, '0')
    localComments.value.unshift({ id: `comment-local-${Date.now()}`, author: currentUserName.value, avatar: currentUserAvatar.value || '/figma-home/I123_1035__83_1454.png', publishedAt: `${now.getFullYear()}-${month}-${day} ${hours}:${minutes}`, content, likes: 0, dislikes: 0, replies: 0 })
    commentDraft.value = ''
    isCommentEditorActive.value = false
  } catch (error) {
    console.error('submit academy comment failed', error)
  }
}
const toggleDescription = () => { expandedDescription.value = !expandedDescription.value }
const openRelatedCourse = (id: string) => router.push(`/academy/${id}`)
</script>

<style lang="scss" scoped>
.academy-detail-page {
    min-width: 0;
    background: #f8f8f8;
}

.academy-detail {
    position: relative;
    padding: 28px 0 220px;
    overflow-x: hidden;

    &__shell {
        width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
        margin: 0 auto;
    }

    &__back {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #a1a1a1;
        font-size: 14px;
        line-height: 1;
        cursor: pointer;
        margin-bottom: 20px;
    }

    &__grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(320px, 445px);
        gap: 20px;
        align-items: start;
    }

    &__main {
        display: flex;
        flex-direction: column;
        gap: 30px;
    }

    &__sidebar {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
}

.video-panel {
    position: relative;
    min-height: 360px;
    aspect-ratio: 16 / 9;
    border-radius: 20px;
    overflow: hidden;
    background: #121212;
    box-shadow: 0 16px 40px rgba(34, 34, 34, 0.08);

    &.is-cinema {
        box-shadow: 0 24px 56px rgba(34, 34, 34, 0.16);

        .video-panel__cover {
            transform: scale(1.04);
            filter: saturate(1.02);
        }
    }

    &__cover {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition:
            transform 0.32s ease,
            filter 0.32s ease;
    }

    &__video {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        background: #000;
    }

    &__scrim {
        position: absolute;
        inset: 0;
        background:
            linear-gradient(180deg, rgba(0, 0, 0, 0) 38%, rgba(0, 0, 0, 0.48) 100%),
            linear-gradient(90deg, rgba(0, 0, 0, 0.16) 0%, rgba(0, 0, 0, 0) 40%);
        pointer-events: none;
    }

    &__status,
    &__lock {
        position: absolute;
        inset: 0;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        text-align: center;
        padding: 40px 120px;
        color: #fff;
        z-index: 1;
    }

    &__status {
        background: rgba(0, 0, 0, 0.22);

        strong {
            margin-top: 18px;
            font-size: 32px;
            line-height: 1.35;
            font-weight: 600;
        }

        p {
            margin: 12px 0 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 16px;
        }
    }

    &__status-kicker,
    &__lock-kicker {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 108px;
        height: 40px;
        padding: 0 20px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        font-size: 14px;
        font-weight: 500;
    }

    &__lock {
        background: rgba(0, 0, 0, 0.46);

        h2 {
            margin: 20px 0 12px;
            font-size: 36px;
            line-height: 1.35;
            font-weight: 600;
        }

        p {
            max-width: 620px;
            margin: 0;
            color: rgba(255, 255, 255, 0.84);
            font-size: 16px;
            line-height: 1.75;
        }
    }

    &__lock-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 72px;
        height: 72px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.14);
        font-size: 32px;
    }

    &__cta {
        margin-top: 24px;
        height: 52px;
        padding: 0 28px;
        border: 0;
        border-radius: 14px;
        background: #ff6600;
        color: #fff;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
    }

    &__controls {
        position: absolute;
        left: 12px;
        right: 20px;
        bottom: 18px;
        z-index: 2;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }

    &__controls-left,
    &__controls-right {
        display: flex;
        align-items: center;
        gap: 20px;
    }
}

.control-play,
.control-chip,
.control-icon,
.summary-card__action,
.comments-lock__button,
.detail-copy__toggle,
.playlist-card__item {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.control-play {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    font-size: 24px;
}

.control-progress {
    display: inline-flex;
    align-items: center;
    gap: 12px;
    height: 40px;
    padding: 0 20px;
    border-radius: 20px;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    font-size: 14px;

    &__track {
        position: relative;
        width: 180px;
        height: 4px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.18);
        overflow: hidden;

        span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: #fff;
            transition: width 0.24s ease;
        }
    }
}

.control-chip,
.control-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 40px;
    border-radius: 20px;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    font-size: 14px;
}

.control-chip {
    padding: 0 20px;
    min-width: 110px;
}

.control-icon {
    width: 40px;
    font-size: 18px;
}

.detail-copy {
    h2 {
        margin: 0 0 20px;
        color: #222;
        font-size: 24px;
        line-height: 1;
        font-weight: 600;
    }

    &__box {
        padding: 20px;
        border: 1px solid #ededed;
        border-radius: 20px;
        background: #ededed;
    }

    &__paragraph {
        margin: 0;
        color: #222;
        font-size: 14px;
        line-height: 2;
    }

    &__toggle {
        display: inline;
        margin-left: 4px;
        padding: 0;
        background: transparent;
        color: #4882ff;
        font-size: 14px;
        line-height: 2;
        font-weight: 500;
        vertical-align: baseline;
    }

    &__divider {
        height: 1px;
        margin: 16px 0 20px;
        background: #ededed;
    }

    &__tags {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;

        span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 4px;
            background: rgba(244, 164, 36, 0.1);
            color: #f4a424;
            font-size: 14px;
            line-height: 1;
            font-weight: 500;
        }
    }
}

.facts-card {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    min-height: 124px;
    border: 1px solid #222;
    border-radius: 20px;
    overflow: hidden;

    &__item {
        display: flex;
        flex-direction: column;
        min-height: 124px;
        border-right: 1px solid #222;

        &:last-child {
            border-right: 0;
        }

        span {
            display: flex;
            align-items: center;
            height: 54px;
            padding: 0 14px;
            border-bottom: 1px solid #222;
            color: #a1a1a1;
            font-size: 12px;
            line-height: 1.66;
        }

        strong {
            display: flex;
            align-items: center;
            flex: 1;
            padding: 0 14px;
            color: #222;
            font-size: 20px;
            line-height: 1;
            font-weight: 500;
        }
    }
}

.comments-section {
    padding-top: 10px;

    &__header {
        margin-bottom: 28px;

        h2 {
            margin: 0;
            color: #000;
            font-size: 24px;
            line-height: 1;
            font-weight: 600;
        }
    }
}

.comments-list {
    display: flex;
    flex-direction: column;
    gap: 0;
    margin-top: 28px;
}

.comment-entry {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    margin-top: 40px;
    padding: 0 0 18px;
    border-bottom: 1px solid #ededed;

    &__avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background: #5c7080;
        color: #fff;
        font-size: 12px;
        line-height: 1;
        flex-shrink: 0;

        img {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: inherit;
            object-fit: cover;
        }

        span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 100%;
        }
    }

    &__composer {
        flex: 1;
        min-width: 0;
    }

    &__input {
        display: block;
        width: 100%;
        min-height: 28px;
        padding: 0;
        border: 0;
        background: transparent;
        color: #222;
        font-size: 14px;
        line-height: 28px;
        text-align: left;
        resize: none;
        overflow: hidden;

        &::placeholder {
            color: #a1a1a1;
        }

        &:focus {
            outline: none;
        }
    }

    &__actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        margin-top: 16px;
    }

    &__action {
        height: 36px;
        padding: 0 18px;
        border: 0;
        border-radius: 999px;
        background: #ff6600;
        color: #fff;
        font-size: 14px;
        line-height: 1;
        font-weight: 500;
        cursor: pointer;

        &:disabled {
            background: #d6d6d6;
            cursor: not-allowed;
        }
    }

    &__action--ghost {
        background: #ededed;
        color: #666;
    }
}

.comment-card {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 0 0 28px;
    border-bottom: 1px solid #ededed;
    margin-bottom: 28px;

    &--featured {
        align-items: stretch;
        min-height: 208px;
    }

    &:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: 0;
    }

    &__avatar {
        width: 62px;
        height: 62px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
    }

    &__body {
        flex: 1;
    }

    &__meta {
        display: flex;
        align-items: center;
        gap: 12px;

        strong {
            color: #000;
            font-size: 16px;
            line-height: 1;
            font-weight: 600;
        }

        span {
            color: #a1a1a1;
            font-size: 12px;
            line-height: 1;
        }
    }

    p {
        margin: 12px 0 0;
        color: #222;
        font-size: 14px;
        line-height: 1.72;
    }

    &__reply {
        margin-top: 16px;
        padding: 16px 18px;
        border-radius: 16px;
        background: rgba(237, 237, 237, 0.6);
    }

    &__reply-head {
        display: flex;
        align-items: center;
        gap: 12px;
        color: #666;
        font-size: 14px;
        line-height: 1;

        span:first-child {
            color: #222;
            font-weight: 600;
        }
    }

    &__actions {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 18px;
        color: #666;
        font-size: 12px;
        line-height: 1;
    }
}

.comments-lock {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 40px;
    padding: 24px 28px;
    border: 1px solid rgba(255, 102, 0, 0.18);
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(255, 102, 0, 0.06) 0%, rgba(255, 255, 255, 0.8) 100%);

    &__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 52px;
        height: 52px;
        border-radius: 16px;
        background: #fff;
        color: #ff6600;
        font-size: 24px;
        flex-shrink: 0;
    }

    &__body {
        flex: 1;

        h3 {
            margin: 0 0 8px;
            color: #222;
            font-size: 20px;
            line-height: 1;
            font-weight: 600;
        }

        p {
            margin: 0;
            color: #666;
            font-size: 14px;
            line-height: 1.72;
        }
    }

    &__button {
        height: 48px;
        padding: 0 22px;
        border-radius: 12px;
        background: #ff6600;
        color: #fff;
        font-size: 15px;
        font-weight: 600;
        white-space: nowrap;
    }
}

.summary-card,
.playlist-card {
    border: 1px solid #ededed;
    border-radius: 20px;
    background: #f8f8f8;
}

.summary-card {
    min-height: 184px;
    padding: 22px 20px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;

    h1 {
        margin: 0;
        color: #222;
        font-size: 32px;
        line-height: 1.5;
        font-weight: 600;
        word-break: break-word;
    }

    &__rating {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-top: 18px;
    }

    &__rating-label,
    &__rating-value {
        color: #a1a1a1;
        font-size: 14px;
        line-height: 1;
    }

    &__stars {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: #ffbf58;
        font-size: 18px;
    }

    &.is-locked {
        p {
            margin: 16px 0 22px;
            color: #666;
            font-size: 14px;
            line-height: 1.72;
        }
    }

    &__price {
        margin-top: 18px;
        color: #f23434;
        font-size: 32px;
        line-height: 1;
        font-weight: 600;
    }

    &__member-tip {
        margin-top: 18px;
        color: #ff6600;
        font-size: 20px;
        line-height: 1;
        font-weight: 600;
    }

    &__action {
        width: 100%;
        height: 54px;
        margin-top: 40px;
        border-radius: 16px;
        background: #ff6600;
        color: #fff;
        font-size: 16px;
        font-weight: 600;
    }
}

.related-section {
    h2 {
        margin: 20px 0;
        color: #222;
        font-size: 32px;
        line-height: 1.625;
        font-weight: 600;
    }
}

.related-card {
    position: relative;
    border-radius: 20px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 10px 30px rgba(34, 34, 34, 0.04);

    & + & {
        margin-top: 20px;
    }

    &::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 255px;
        border-radius: 20px 20px 0 0;
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
        height: 255px;
        object-fit: cover;
    }

    &__body {
        padding: 20px 20px 14px;
    }

    h3 {
        margin: 0;
        color: #222;
        font-size: 20px;
        line-height: 1;
        font-weight: 600;
    }

    &__price {
        display: block;
        margin-top: 16px;
        color: #ff6b00;
        font-size: 32px;
        line-height: 1;
        font-weight: 700;
        white-space: nowrap;
    }

    &__badge {
        margin-top: 16px;
        width: fit-content;
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
        background: #f5f7fa;
        color: #8a94a6;
    }

    &__meta {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        margin-top: 16px;
        color: #a1a1a1;
        font-size: 14px;
        line-height: 1;
    }
}

.playlist-card {
    height: 647px;
    padding: 20px 20px 16px;
    display: flex;
    flex-direction: column;

    &__header {
        display: flex;
        align-items: flex-end;
        gap: 0;
        margin-bottom: 20px;

        h2 {
            margin: 0;
            color: #222;
            font-size: 32px;
            line-height: 1.625;
            font-weight: 600;
        }

        span {
            color: #a1a1a1;
            font-size: 14px;
            line-height: 1;
            margin-bottom: 12px;
        }
    }

    &__list {
        flex: none;
        height: 535px;
        min-height: 535px;
        overflow-y: auto;
        overflow-x: hidden;
        padding-right: 2px;

        &::-webkit-scrollbar {
            width: 0;
            height: 0;
        }
    }

    &__item {
        display: flex;
        align-items: flex-start;
        gap: 16px;
        width: 100%;
        height: 91px;
        padding: 0;
        background: transparent;
        text-align: left;

        & + & {
            margin-top: 20px;
        }

        &.is-active {
            .playlist-card__copy p {
                color: #ff6600;
            }

            .playlist-card__meta {
                margin-top: 18px;
            }

            .playlist-card__meta span {
                min-height: 24px;
                background: #ededed;
                color: #222;
                padding: 6px 9px;
            }
        }
    }

    &__thumb {
        width: 153px;
        height: 91px;
        border-radius: 8px;
        object-fit: cover;
        flex-shrink: 0;
    }

    &__copy {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        padding-top: 6px;

        p {
            margin: 0;
            width: 237px;
            height: 40px;
            color: #222;
            font-size: 14px;
            line-height: 1.43;
            font-weight: 500;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    }

    &__meta {
        display: flex;
        align-items: center;
        margin-top: 23px;
        min-height: 24px;

        span {
            display: inline-flex;
            align-items: center;
            min-height: 12px;
            padding: 0;
            border-radius: 4px;
            color: #a1a1a1;
            font-size: 12px;
            line-height: 1;
        }
    }
}

@media (max-width: 1100px) {
    .academy-detail {
        &__grid {
            grid-template-columns: 1fr;
        }
    }

    .playlist-card__list {
        height: auto;
        min-height: 0;
        max-height: 535px;
    }
}
</style>
