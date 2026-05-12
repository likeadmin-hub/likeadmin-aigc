export type AcademyCourseAccess = 'paid' | 'free' | 'member'

export type AcademyCourseDetailState = 'free' | 'locked-member' | 'locked-paid'

export type AcademyCoursePlaylistItem = {
    id: string
    title: string
    duration: string
    thumbnail: string
    isPreview?: boolean
}

export type AcademyCourseFact = {
    label: string
    value: string
}

export type AcademyCourseComment = {
    id: string
    author: string
    avatar: string
    publishedAt: string
    content: string
    likes: number
    dislikes: number
    replies: number
    replyTo?: string
    featured?: boolean
}

export type AcademyRelatedCourse = {
    id: string
    title: string
    image: string
    duration: string
    rating: string
    priceLabel: string
}

export type AcademyCourseItem = {
    id: string
    title: string
    category: string
    access: AcademyCourseAccess
    duration: string
    rating: string
    price?: number
    downloads: number
    image: string
}

export type AcademyCourseDetail = AcademyCourseItem & {
    detailState: AcademyCourseDetailState
    canWatch: boolean
    canComment: boolean
    heroMedia: {
        cover: string
    }
    reviewSummary: string
    tags: string[]
    playlistTitle: string
    playlistProgress: string
    playlist: AcademyCoursePlaylistItem[]
    description: string
    facts: AcademyCourseFact[]
    commentCount: number
    comments: AcademyCourseComment[]
    relatedTitle: string
    relatedCourses: AcademyRelatedCourse[]
    unlockTitle?: string
    unlockDescription?: string
    unlockActionLabel?: string
}

const sharedFacts: AcademyCourseFact[] = [
    { label: '类别', value: 'UI设计' },
    { label: '集数', value: '15集' },
    { label: '总时长', value: '4小时39分钟' },
    { label: '课程形式', value: '录播' },
    { label: '等级', value: '初学者' }
]

const sharedPlaylistSeed: AcademyCoursePlaylistItem[] = [
    {
        id: 'lesson-1',
        title: 'AIGC商业视频广告实战进阶课AIGC商业视频广告实战进阶课',
        duration: '16分06秒',
        thumbnail: '/figma-home/12_469.png',
        isPreview: true
    },
    {
        id: 'lesson-2',
        title: 'AIGC商业视频广告实战进阶课AIGC商业视频广告实战进阶课',
        duration: '16分06秒',
        thumbnail: '/figma-home/12_478.png'
    },
    {
        id: 'lesson-3',
        title: 'AIGC商业视频广告实战进阶课AIGC商业视频广告实战进阶课',
        duration: '16分06秒',
        thumbnail: '/figma-home/12_504.png'
    },
    {
        id: 'lesson-4',
        title: 'AIGC商业视频广告实战进阶课AIGC商业视频广告实战进阶课',
        duration: '16分06秒',
        thumbnail: '/figma-home/12_513.png'
    },
    {
        id: 'lesson-5',
        title: 'AIGC商业视频广告实战进阶课AIGC商业视频广告实战进阶课',
        duration: '16分06秒',
        thumbnail: '/figma-home/12_522.png'
    }
]

const sharedPlaylist: AcademyCoursePlaylistItem[] = Array.from({ length: 15 }, (_, index) => {
    const source = sharedPlaylistSeed[index % sharedPlaylistSeed.length]

    return {
        ...source,
        id: `lesson-${index + 1}`,
        isPreview: index === 0
    }
})

const sharedComments: AcademyCourseComment[] = [
    {
        id: 'comment-featured',
        author: '汤层层',
        avatar: '/figma-home/I123_1035__83_1454.png',
        replyTo: 'Uana',
        publishedAt: '2023-05-11 09:00',
        content: '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」',
        likes: 12,
        dislikes: 1,
        replies: 1,
        featured: true
    },
    {
        id: 'comment-1',
        author: 'Uana',
        avatar: '/figma-home/I123_1035__83_1454.png',
        publishedAt: '2023-05-11 09:00',
        content:
            '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」，活动周期2026.02.01-2026.03.31，首期商单报名截止日期为2月3日前。新作、存量作品均可参与，三重奖励+长期收益，零门槛解锁创作红利！',
        likes: 12,
        dislikes: 1,
        replies: 0
    },
    {
        id: 'comment-2',
        author: 'Uana',
        avatar: '/figma-home/I123_1035__83_1454.png',
        publishedAt: '2023-05-11 09:00',
        content:
            '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」，活动周期2026.02.01-2026.03.31，首期商单报名截止日期为2月3日前。新作、存量作品均可参与，三重奖励+长期收益，零门槛解锁创作红利！',
        likes: 12,
        dislikes: 1,
        replies: 0
    },
    {
        id: 'comment-3',
        author: 'Uana',
        avatar: '/figma-home/I123_1035__83_1454.png',
        publishedAt: '2023-05-11 09:00',
        content:
            '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」，活动周期2026.02.01-2026.03.31，首期商单报名截止日期为2月3日前。新作、存量作品均可参与，三重奖励+长期收益，零门槛解锁创作红利！',
        likes: 12,
        dislikes: 1,
        replies: 0
    },
    {
        id: 'comment-4',
        author: 'Uana',
        avatar: '/figma-home/I123_1035__83_1454.png',
        publishedAt: '2023-05-11 09:00',
        content:
            '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」，活动周期2026.02.01-2026.03.31，首期商单报名截止日期为2月3日前。新作、存量作品均可参与，三重奖励+长期收益，零门槛解锁创作红利！',
        likes: 12,
        dislikes: 1,
        replies: 0
    },
    {
        id: 'comment-5',
        author: 'Uana',
        avatar: '/figma-home/I123_1035__83_1454.png',
        publishedAt: '2023-05-11 09:00',
        content:
            '奇艺知识官方联合有戏AI发起「少儿科普剧AI创作大赛」，活动周期2026.02.01-2026.03.31，首期商单报名截止日期为2月3日前。新作、存量作品均可参与，三重奖励+长期收益，零门槛解锁创作红利！',
        likes: 12,
        dislikes: 1,
        replies: 0
    }
]

const sharedRelatedCourses: AcademyRelatedCourse[] = [
    {
        id: 'aigc-commercial-video-advanced-1',
        title: 'AIGC商业视频广告实战进阶课',
        image: '/figma-home/12_461.png',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        priceLabel: '￥9.99'
    },
    {
        id: 'aigc-commercial-video-member-1',
        title: 'AIGC商业视频广告实战进阶课',
        image: '/figma-home/12_478.png',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        priceLabel: '￥9.99'
    },
    {
        id: 'figma-ui-design-course-1',
        title: '从零开始学习使用 Figma 进行 UI 设计',
        image: '/figma-home/12_496.png',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        priceLabel: '￥9.99'
    }
]

const sharedTags = ['视频广告设计师', '拍摄剪辑', '短视频', 'vlog', 'MG动画', '特效师']

const sharedDescription =
    '学习 UI 设计的基础知识，并使用 Figma 创建能够与受众建立联系的、具有视觉吸引力的界面。 “通过互动和动态效果让项目栩栩如生是我的热情所在，但无论使用什么工具或技术，用户始终是第一位的。”用户界面设计的核心在于将用户置于每一个创意决策的中心。屡获殊荣的设计师 Daniele Buffa 以打造极具影响力的用户界面体验而闻名，她曾与 Headspace 和 Insider 等品牌合作，并在戛纳国际创意节和 D&AD 铅笔奖上斩获奖项。'

export const ACADEMY_COURSES: AcademyCourseItem[] = [
    {
        id: 'aigc-commercial-video-advanced-1',
        title: 'AIGC商业视频广告实战进阶课',
        category: '视频实战',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 5200,
        image: '/figma-home/12_461.png'
    },
    {
        id: 'aigc-commercial-video-free-1',
        title: 'AIGC商业视频广告实战进阶课',
        category: '视频实战',
        access: 'free',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 4600,
        image: '/figma-home/12_469.png'
    },
    {
        id: 'aigc-commercial-video-member-1',
        title: 'AIGC商业视频广告实战进阶课',
        category: '视频实战',
        access: 'member',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 4300,
        image: '/figma-home/12_478.png'
    },
    {
        id: 'aigc-commercial-video-advanced-2',
        title: 'AIGC商业视频广告实战进阶课',
        category: '视频实战',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 4100,
        image: '/figma-home/12_487.png'
    },
    {
        id: 'figma-ui-design-course-1',
        title: '从零开始学习使用 Figma 进行 UI 设计',
        category: 'Figma进阶',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 3600,
        image: '/figma-home/12_496.png'
    },
    {
        id: 'brand-design-thinking-1',
        title: '北欧品牌设计强化课：完整品牌塑造',
        category: '品牌设计',
        access: 'member',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 3400,
        image: '/figma-home/12_504.png'
    },
    {
        id: 'figma-web-innovation-1',
        title: 'Figma 创新网页设计：分步实战指南',
        category: '网页设计',
        access: 'member',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 3150,
        image: '/figma-home/12_513.png'
    },
    {
        id: 'figma-bootcamp-1',
        title: '从零到一，学会 Figma（10 节课程）',
        category: 'Figma进阶',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 2950,
        image: '/figma-home/12_522.png'
    },
    {
        id: 'figma-ui-design-course-2',
        title: '从零开始学习使用 Figma 进行 UI 设计',
        category: 'Figma进阶',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 2780,
        image: '/figma-home/12_496.png'
    },
    {
        id: 'brand-design-thinking-2',
        title: '北欧品牌设计强化课：完整品牌塑造',
        category: '品牌设计',
        access: 'member',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 2640,
        image: '/figma-home/12_504.png'
    },
    {
        id: 'figma-web-innovation-2',
        title: 'Figma 创新网页设计：分步实战指南',
        category: '网页设计',
        access: 'member',
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 2480,
        image: '/figma-home/12_513.png'
    },
    {
        id: 'figma-bootcamp-2',
        title: '从零到一，学会 Figma（10 节课程）',
        category: 'Figma进阶',
        access: 'paid',
        price: 9.99,
        duration: '时长：1小时32分钟',
        rating: '评分：4.8/5',
        downloads: 2320,
        image: '/figma-home/12_522.png'
    }
]

const buildCourseDetail = (course: AcademyCourseItem): AcademyCourseDetail => {
    const isFree = course.access === 'free'
    const isMemberLocked = course.access === 'member'
    const detailState: AcademyCourseDetailState = isFree ? 'free' : isMemberLocked ? 'locked-member' : 'locked-paid'

    return {
        ...course,
        detailState,
        canWatch: isFree,
        canComment: isFree,
        heroMedia: {
            cover: course.image
        },
        reviewSummary: course.rating,
        tags: sharedTags,
        playlistTitle: '合集',
        playlistProgress: '（1/15）',
        playlist: sharedPlaylist,
        description: sharedDescription,
        facts: sharedFacts,
        commentCount: 440,
        comments: sharedComments,
        relatedTitle: '相似课程',
        relatedCourses: sharedRelatedCourses,
        unlockTitle: isMemberLocked ? '开通会员后即可观看完整课程' : '购买课程后即可解锁完整内容',
        unlockDescription: isMemberLocked
            ? '当前课程属于会员限免内容，未开通会员前仅可查看详情信息。'
            : '当前课程属于单独付费课程，完成购买后才能观看视频并参与评论。',
        unlockActionLabel: isMemberLocked ? '开通会员' : '立即购买'
    }
}

export const ACADEMY_COURSE_DETAILS: AcademyCourseDetail[] = ACADEMY_COURSES.map((course) =>
    buildCourseDetail(course)
)

export const getAcademyCourseDetailById = (id: string) =>
    ACADEMY_COURSE_DETAILS.find((course) => course.id === id)
