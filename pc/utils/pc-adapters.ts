import type {
    AcademyCourseAccess,
    AcademyCourseComment,
    AcademyCourseDetail,
    AcademyCourseFact,
    AcademyCourseItem,
    AcademyCoursePlaylistItem,
    AcademyRelatedCourse
} from '@/constants/academy-courses'
import type { MembershipPlanDefinition, MembershipPlanId } from '@/constants/membership-plans'
import type { SkillItem } from '@/constants/skills'
import { normalizeFileUrl } from '@/utils/file-url'

type PlainObject = Record<string, any>

export function extractListData<T = PlainObject>(payload: any): T[] {
    if (Array.isArray(payload)) return payload
    if (Array.isArray(payload?.lists)) return payload.lists
    if (Array.isArray(payload?.list)) return payload.list
    if (Array.isArray(payload?.rows)) return payload.rows
    if (Array.isArray(payload?.data)) return payload.data
    return []
}

export function extractDetailData<T = PlainObject>(payload: any): T {
    if (payload && typeof payload === 'object' && payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) {
        return payload.data as T
    }
    return (payload ?? {}) as T
}

const formatDate = (value: unknown, fallback = '') => {
    if (typeof value === 'number' && value > 0) {
        return new Date(value * 1000).toISOString().slice(0, 10)
    }
    if (typeof value === 'string' && value.trim()) {
        return value.trim()
    }
    return fallback
}

const formatDateTime = (value: unknown) => {
    if (typeof value === 'number' && value > 0) {
        return new Date(value * 1000).toISOString().slice(0, 16).replace('T', ' ')
    }
    if (typeof value === 'string' && value.trim()) {
        return value.trim()
    }
    return ''
}

const normalizePrice = (value: unknown) => {
    const num = Number(value)
    return Number.isFinite(num) ? num : undefined
}

const toAccess = (value: unknown, memberFree?: unknown, price?: unknown): AcademyCourseAccess => {
    const raw = String(value ?? '').trim().toLowerCase()
    if (raw === 'free') return 'free'
    if (raw === 'member' || raw === 'member_free') return 'member'
    if (raw === 'paid') return 'paid'
    if (Number(memberFree) === 1) return 'member'
    if (Number(price) > 0) return 'paid'
    return 'free'
}

const parseFacts = (facts: unknown, fallback: AcademyCourseFact[] = []): AcademyCourseFact[] => {
    if (!Array.isArray(facts)) return fallback
    return facts
        .map((item) => {
            if (!item || typeof item !== 'object') return null
            const row = item as PlainObject
            const label = String(row.label ?? row.name ?? '').trim()
            const value = String(row.value ?? row.content ?? '').trim()
            if (!label || !value) return null
            return { label, value }
        })
        .filter(Boolean) as AcademyCourseFact[]
}

const parsePlaylist = (playlist: unknown, coverImage: string): AcademyCoursePlaylistItem[] => {
    if (!Array.isArray(playlist)) return []
    return playlist
        .map((item, index) => {
            if (!item || typeof item !== 'object') return null
            const row = item as PlainObject
            return {
                id: String(row.id ?? `lesson-${index + 1}`),
                title: String(row.title ?? row.name ?? `章节 ${index + 1}`),
                duration: String(row.duration ?? row.length ?? ''),
                thumbnail: normalizeFileUrl(String(row.thumbnail ?? row.cover_image ?? coverImage ?? '')),
                isPreview: Boolean(row.is_preview ?? row.preview ?? index === 0)
            }
        })
        .filter(Boolean) as AcademyCoursePlaylistItem[]
}

export function mapSkillItem(raw: PlainObject): SkillItem {
    return {
        id: String(raw.id ?? ''),
        slug: String(raw.slug ?? ''),
        accessType:
            raw.access_type === 'member' || raw.access_type === 'paid' || raw.access_type === 'free'
                ? raw.access_type
                : undefined,
        isPurchased: Number(raw.is_purchased ?? raw.purchased ?? raw.has_buy ?? raw.has_bought ?? 0) === 1,
        badge: String(raw.badge ?? ''),
        title: String(raw.title ?? ''),
        summary: String(raw.summary ?? raw.description ?? ''),
        description: String(raw.description ?? raw.summary ?? ''),
        tags: Array.isArray(raw.tags) ? raw.tags.map((item: any) => String(item)) : [],
        category: String(raw.category ?? ''),
        type: String(raw.type ?? ''),
        memberFree: Number(raw.member_free ?? 0) === 1,
        downloads: Number(raw.downloads ?? 0),
        installs: Number(raw.installs ?? 0),
        updatedAt: formatDateTime(raw.updated_at_text ?? raw.updated_time ?? raw.updated_at),
        updatedDate: formatDate(raw.updated_at_text ?? raw.updated_time ?? raw.updated_at, ''),
        price: normalizePrice(raw.price),
        detailTitle: String(raw.detail_title ?? raw.title ?? ''),
        detailSummary: String(raw.detail_summary ?? raw.summary ?? ''),
        detailContent: String(raw.detail_content ?? ''),
        coverImage: normalizeFileUrl(String(raw.cover_image ?? '')),
        repoName: String(raw.repo_name ?? ''),
        repoStars: Number(raw.repo_stars ?? 0),
        repoForks: Number(raw.repo_forks ?? 0),
        installLabel: String(raw.install_label ?? 'npx'),
        installCommand: String(raw.install_command ?? ''),
        installCommands:
            raw.install_commands && typeof raw.install_commands === 'object'
                ? {
                      npx: String(raw.install_commands.npx ?? ''),
                      bun: String(raw.install_commands.bun ?? ''),
                      pnpm: String(raw.install_commands.pnpm ?? '')
                  }
                : undefined,
        downloadLabel: String(raw.download_label ?? ''),
        downloadUrl: String(raw.download_url ?? ''),
        downloadTip: String(raw.download_tip ?? ''),
        relatedSkills: Array.isArray(raw.related_skills)
            ? raw.related_skills.map((item: any) => ({
                  avatar: String(item?.avatar ?? ''),
                  name: String(item?.name ?? ''),
                  from: String(item?.from ?? ''),
                  stars: Number(item?.stars ?? 0)
              }))
            : []
    }
}

export function mapAcademyCourseItem(raw: PlainObject): AcademyCourseItem {
    const access = toAccess(raw.access_type, raw.member_free, raw.price)
    const ratingValue = Number(raw.rating ?? 0)
    return {
        id: String(raw.id ?? ''),
        title: String(raw.title ?? ''),
        category: String(raw.category ?? ''),
        access,
        duration: String(raw.duration ?? ''),
        rating: ratingValue > 0 ? `评分：${ratingValue}/5` : '评分：4.8/5',
        price: normalizePrice(raw.price),
        downloads: Number(raw.downloads ?? 0),
        image: normalizeFileUrl(String(raw.cover_image ?? ''))
    }
}

export function mapAcademyComment(raw: PlainObject): AcademyCourseComment {
    return {
        id: String(raw.id ?? ''),
        author: String(raw.author ?? ''),
        avatar: normalizeFileUrl(String(raw.avatar ?? '')),
        publishedAt: formatDateTime(raw.published_at ?? raw.create_time),
        content: String(raw.content ?? ''),
        likes: Number(raw.likes ?? 0),
        dislikes: Number(raw.dislikes ?? 0),
        replies: Number(raw.replies ?? 0),
        replyTo: raw.reply_to ? String(raw.reply_to) : undefined,
        featured: Number(raw.featured ?? 0) === 1
    }
}

export function mapAcademyDetail(
    raw: PlainObject,
    comments: AcademyCourseComment[] = [],
    relatedCourses: AcademyCourseItem[] = []
): AcademyCourseDetail {
    const base = mapAcademyCourseItem(raw)
    const detailState = base.access === 'free' ? 'free' : base.access === 'member' ? 'locked-member' : 'locked-paid'
    const playlist = parsePlaylist(raw.playlist, base.image)
    const facts = parseFacts(raw.facts, [
        { label: '类别', value: base.category || '-' },
        { label: '总时长', value: base.duration || '-' },
        { label: '课程形式', value: '录播' }
    ])
    const related: AcademyRelatedCourse[] = relatedCourses
        .filter((item) => item.id !== base.id)
        .slice(0, 3)
        .map((item) => ({
            id: item.id,
            title: item.title,
            image: item.image,
            duration: item.duration,
            rating: item.rating,
            priceLabel:
                item.access === 'paid' && typeof item.price === 'number'
                    ? `¥${item.price.toFixed(2)}`
                    : item.access === 'member'
                      ? '会员限免'
                      : '免费'
        }))

    return {
        ...base,
        detailState,
        canWatch: detailState === 'free',
        canComment: true,
        heroMedia: {
            cover: base.image
        },
        reviewSummary: base.rating,
        tags: Array.isArray(raw.tags) ? raw.tags.map((item: any) => String(item)) : [],
        playlistTitle: '合集',
        playlistProgress: `（${playlist.length || 0}/${playlist.length || 0}）`,
        playlist,
        description: String(raw.intro ?? raw.subtitle ?? ''),
        facts,
        commentCount: Number(raw.comment_count ?? comments.length),
        comments,
        relatedTitle: '相似课程',
        relatedCourses: related,
        unlockTitle: detailState === 'locked-member' ? '开通会员后即可观看完整课程' : '购买课程后即可解锁完整内容',
        unlockDescription:
            detailState === 'locked-member' ? '当前课程属于会员限免内容。' : '当前课程属于单独付费课程。',
        unlockActionLabel: detailState === 'locked-member' ? '开通会员' : '立即购买'
    }
}

const normalizePlanId = (raw: unknown): MembershipPlanId => {
    const value = String(raw ?? '').trim().toLowerCase()
    if (value === 'free' || value === '免费会员' || value === '0' || value === '1') return 'free'
    if (value === 'basic' || value === '基础会员' || value === '2') return 'basic'
    if (value === 'advanced' || value === 'pro' || value === '高级会员' || value === '3') return 'advanced'
    return 'free'
}

export function mapMembershipPlan(raw: PlainObject): MembershipPlanDefinition {
    const id = normalizePlanId(raw.plan_id ?? raw.name ?? raw.id)
    return {
        id,
        name: String(raw.name ?? raw.title ?? ''),
        title: String(raw.title ?? raw.name ?? ''),
        description: String(raw.description ?? ''),
        monthlyPrice: String(raw.monthly_price ?? '0'),
        yearlyPrice: String(raw.yearly_price ?? '0'),
        button: `订阅${String(raw.title ?? raw.name ?? '会员')}`,
        outline: !raw.is_recommend,
        features: Array.isArray(raw.features) ? raw.features.map((item: any) => String(item)) : [],
        free: Number(raw.is_free ?? 0) === 1,
        monthlyMarketPrice: String(raw.monthly_market_price ?? ''),
        yearlyMarketPrice: String(raw.yearly_market_price ?? ''),
        monthlyBonus: String(raw.monthly_bonus ?? ''),
        monthlyBonusTip: String(raw.monthly_bonus_tip ?? ''),
        yearlyBonus: String(raw.yearly_bonus ?? ''),
        yearlyBonusTip: String(raw.yearly_bonus_tip ?? '')
    }
}

export function mapCreditPack(raw: PlainObject) {
    return {
        credits: Number(raw.credits ?? 0),
        price: Number(raw.price ?? 0).toFixed(2),
        id: Number(raw.id ?? 0),
        originalPrice: Number(raw.original_price ?? 0).toFixed(2)
    }
}

export function mapAssetCategory(assetType: unknown): 'image' | 'video' | 'avatar' | 'tool' {
    const value = String(assetType ?? '').trim().toLowerCase()
    if (value === 'video') return 'video'
    if (value === 'avatar' || value === 'digital_human') return 'avatar'
    if (value === 'tool') return 'tool'
    return 'image'
}

export function mapAssetItem(raw: PlainObject) {
    return {
        id: String(raw.id ?? ''),
        title: String(raw.title ?? ''),
        image: normalizeFileUrl(String(raw.cover_url ?? raw.thumb_url ?? raw.file_url ?? '')),
        category: mapAssetCategory(raw.asset_type),
        date: formatDate(raw.create_time, '今天'),
        favorite: Number(raw.favorite ?? 0) === 1,
        badge: String(raw.source_type ?? ''),
        duration: String(raw.duration ?? '')
    }
}
