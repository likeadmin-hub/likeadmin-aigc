export interface SkillItem {
    id: string
    slug?: string
    accessType?: 'free' | 'member' | 'paid'
    isPurchased?: boolean
    badge: string
    title: string
    summary: string
    description: string
    tags: string[]
    category: string
    type: string
    memberFree: boolean
    downloads: number
    installs: number
    updatedAt: string
    updatedDate?: string
    price?: number
    detailTitle?: string
    detailSummary?: string
    detailContent?: string
    coverImage?: string
    repoName?: string
    repoStars?: number
    repoForks?: number
    installLabel?: string
    installCommand?: string
    installCommands?: {
        npx?: string
        bun?: string
        pnpm?: string
    }
    downloadLabel?: string
    downloadUrl?: string
    downloadTip?: string
    relatedSkills?: Array<{
        avatar?: string
        name: string
        from?: string
        stars?: number
    }>
}

const SKILL_LIBRARY: SkillItem[] = [
    {
        id: 'xiaohongshu-creator-helper',
        badge: 'NEW',
        title: '小红书创作助手',
        summary: '从选题、脚本到发布建议，帮助内容团队快速完成小红书账号日更。',
        description:
            '面向内容团队和个人创作者的创作型技能。支持热点选题发现、标题优化、封面提示词、评论区互动建议和复盘分析，适合做稳定更新的小红书账号矩阵。',
        tags: ['内容创作', '小红书', 'AIGC', '运营'],
        category: '内容创作',
        type: '创作助手',
        memberFree: false,
        price: 9.99,
        downloads: 698,
        installs: 2300,
        updatedAt: '2026-03-28'
    },
    {
        id: 'hr-recruit-assistant',
        badge: 'HOT',
        title: 'HR招聘助手',
        summary: '支持 JD 拆解、候选人筛选与面试追问建议，帮助招聘流程更标准。',
        description:
            '适合招聘负责人和用人经理的工作流技能。可以围绕岗位画像自动生成 JD、匹配简历重点、输出面试题库，并给出候选人评估摘要。',
        tags: ['招聘人事', '面试', '简历', '流程'],
        category: '招聘人事',
        type: '工作流',
        memberFree: true,
        downloads: 1240,
        installs: 4100,
        updatedAt: '2026-04-01'
    },
    {
        id: 'private-domain-growth-coach',
        badge: 'PRO',
        title: '私域增长教练',
        summary: '帮助品牌梳理私域路径，生成拉新、促活、成交和复购的动作方案。',
        description:
            '聚焦私域运营的策略技能。支持社群分层、朋友圈内容编排、转化节奏拆解和数据复盘，适合门店、教育和咨询类业务持续使用。',
        tags: ['增长', '私域', '社群', '转化'],
        category: '运营增长',
        type: '自动化',
        memberFree: false,
        price: 19.99,
        downloads: 856,
        installs: 3270,
        updatedAt: '2026-03-22'
    },
    {
        id: 'short-video-script-lab',
        badge: 'HOT',
        title: '短视频脚本实验室',
        summary: '面向抖音和视频号的脚本生成与镜头拆解助手，提升短视频开拍效率。',
        description:
            '适用于品牌营销和个人 IP 团队。可根据行业、受众和卖点生成多版本脚本，输出镜头表、口播节奏和封面标题建议。',
        tags: ['短视频', '脚本', '抖音', '营销'],
        category: '内容创作',
        type: '创作助手',
        memberFree: true,
        downloads: 980,
        installs: 3520,
        updatedAt: '2026-03-30'
    },
    {
        id: 'ecommerce-listing-polisher',
        badge: 'NEW',
        title: '电商商品页润色器',
        summary: '围绕卖点提炼、详情页文案和 FAQ 生成，帮助商品页转化更完整。',
        description:
            '电商运营可直接使用的详情页助手。可以根据品类特点生成主副标题、卖点模块、评价回复和售后 FAQ，适合淘宝、抖音和独立站商品运营。',
        tags: ['电商', '详情页', '商品文案', '转化'],
        category: '运营增长',
        type: '行业模板',
        memberFree: false,
        price: 12.99,
        downloads: 732,
        installs: 2810,
        updatedAt: '2026-03-25'
    },
    {
        id: 'meeting-minutes-orchestrator',
        badge: 'HOT',
        title: '会议纪要编排官',
        summary: '自动提炼待办、责任人和关键风险，让周会与项目复盘更加可执行。',
        description:
            '办公协作团队常用的效率技能。支持把纪要整理成行动清单、项目同步邮件和跨部门跟进表，减少遗漏和重复沟通。',
        tags: ['办公协作', '纪要', '项目管理', '效率'],
        category: '办公协作',
        type: '工作流',
        memberFree: true,
        downloads: 1460,
        installs: 4890,
        updatedAt: '2026-04-03'
    },
    {
        id: 'data-review-analyst',
        badge: 'PRO',
        title: '数据复盘分析师',
        summary: '把周报月报数据转成业务洞察，帮助团队找到问题和下一步动作。',
        description:
            '适合运营、产品和管理层的分析型技能。支持多维度对比、异常波动提示、结论摘要和会议复盘输出。',
        tags: ['数据分析', '复盘', '报表', '洞察'],
        category: '数据分析',
        type: '自动化',
        memberFree: false,
        price: 16.99,
        downloads: 564,
        installs: 1980,
        updatedAt: '2026-03-18'
    },
    {
        id: 'knowledge-base-builder',
        badge: 'HOT',
        title: '知识库搭建助手',
        summary: '帮助企业整理 SOP、FAQ 和内部知识，快速搭建标准化知识库。',
        description:
            '面向团队知识沉淀的结构化技能。适合把散落在文档、群聊和历史项目中的知识整理为可搜索的知识块和问答卡片。',
        tags: ['知识库', 'SOP', 'FAQ', '内部协作'],
        category: '办公协作',
        type: '工作流',
        memberFree: true,
        downloads: 1320,
        installs: 3760,
        updatedAt: '2026-04-02'
    },
    {
        id: 'product-requirement-copilot',
        badge: 'PRO',
        title: '产品需求共创官',
        summary: '聚合需求背景、用户问题和业务目标，输出结构清晰的 PRD 初稿。',
        description:
            '适合产品经理和项目负责人。可以围绕问题背景、目标人群、核心流程、边界条件和验收标准生成需求文档框架。',
        tags: ['产品', 'PRD', '需求分析', '协作'],
        category: '产品设计',
        type: '工作流',
        memberFree: false,
        price: 18.99,
        downloads: 625,
        installs: 2480,
        updatedAt: '2026-03-20'
    },
    {
        id: 'course-outline-designer',
        badge: 'NEW',
        title: '课程大纲生成器',
        summary: '帮助讲师和内容团队从主题目标出发，快速搭建课程结构和作业设计。',
        description:
            '面向教育培训和知识付费场景。支持课程分层、单元拆解、知识点排序和互动练习生成，适合线上录播课和训练营。',
        tags: ['教育培训', '课程设计', '知识付费', '内容策划'],
        category: '内容创作',
        type: '行业模板',
        memberFree: true,
        downloads: 910,
        installs: 2940,
        updatedAt: '2026-03-27'
    },
    {
        id: 'campaign-calendar-planner',
        badge: 'HOT',
        title: '活动策划排期器',
        summary: '按活动目标和时间节点拆解方案、物料清单和推广节奏。',
        description:
            '适合市场和品牌团队的活动排期技能。支持大型活动、直播促销和新品发布的阶段任务拆解及风险提醒。',
        tags: ['活动策划', '市场', '排期', '执行'],
        category: '运营增长',
        type: '工作流',
        memberFree: false,
        price: 11.99,
        downloads: 770,
        installs: 2660,
        updatedAt: '2026-03-24'
    },
    {
        id: 'sales-followup-agent',
        badge: 'HOT',
        title: '销售跟进代理人',
        summary: '根据客户阶段生成跟进节奏、异议回复和转化话术，提高销售推进效率。',
        description:
            '适合教育、SaaS 和咨询行业的销售团队。支持线索分层、跟进提醒、异议处理和成单前信号识别。',
        tags: ['销售', '线索转化', 'CRM', '话术'],
        category: '运营增长',
        type: '自动化',
        memberFree: true,
        downloads: 1180,
        installs: 4380,
        updatedAt: '2026-04-04'
    },
    {
        id: 'ai-customer-service-brain',
        badge: 'PRO',
        title: '客服话术引擎',
        summary: '快速生成标准问答和情绪安抚话术，让客服团队响应更加稳定。',
        description:
            '适用于售前、售后和社群客服。可根据问题类型生成多轮对话建议，帮助团队统一服务口径并提升满意度。',
        tags: ['客服', '问答', '售后', '服务体验'],
        category: '办公协作',
        type: '自动化',
        memberFree: false,
        price: 15.99,
        downloads: 688,
        installs: 2570,
        updatedAt: '2026-03-23'
    },
    {
        id: 'resume-optimizer-pro',
        badge: 'HOT',
        title: '简历优化专家',
        summary: '根据岗位方向调整简历结构、亮点表述和面试自我介绍。',
        description:
            '求职和招聘双场景都适用的辅助技能。既能帮候选人优化简历，也能帮助招聘方快速提炼候选人的关键优势与风险点。',
        tags: ['简历', '求职', '面试', '招聘'],
        category: '招聘人事',
        type: '创作助手',
        memberFree: true,
        downloads: 1520,
        installs: 5210,
        updatedAt: '2026-04-05'
    },
    {
        id: 'seo-content-architect',
        badge: 'PRO',
        title: 'SEO内容架构师',
        summary: '从关键词分组到专题页结构，帮助内容站点建立稳定的搜索流量策略。',
        description:
            '适合品牌官网、博客和出海内容团队。支持内容群组规划、内链建议、SERP 意图分析和文章框架生成。',
        tags: ['SEO', '内容策略', '搜索增长', '站点运营'],
        category: '内容创作',
        type: '自动化',
        memberFree: false,
        price: 21.99,
        downloads: 538,
        installs: 1840,
        updatedAt: '2026-03-19'
    },
    {
        id: 'community-ops-playbook',
        badge: 'HOT',
        title: '社群运营打法库',
        summary: '围绕拉新、促活、转化和留存，提供可执行的社群运营动作清单。',
        description:
            '适合品牌私域和课程社群团队。支持活动节奏安排、内容栏目策划、用户分层互动和转化节点提醒。',
        tags: ['社群运营', '私域', '用户增长', '活动'],
        category: '运营增长',
        type: '行业模板',
        memberFree: true,
        downloads: 1348,
        installs: 4630,
        updatedAt: '2026-04-01'
    },
    {
        id: 'legal-risk-checker',
        badge: 'PRO',
        title: '法务风险检查器',
        summary: '对宣传文案、活动规则和合作条款进行基础风险提示与审阅建议。',
        description:
            '适用于市场、运营和商务团队的风险检查型技能。可以在上线前快速识别常见表述风险，减少沟通成本。',
        tags: ['法务', '风险控制', '合同', '审阅'],
        category: '办公协作',
        type: '工作流',
        memberFree: false,
        price: 24.99,
        downloads: 416,
        installs: 1390,
        updatedAt: '2026-03-15'
    },
    {
        id: 'brand-visual-prompt-master',
        badge: 'NEW',
        title: '品牌视觉提示词师',
        summary: '帮助设计和营销团队生成稳定的视觉风格描述与图像提示词。',
        description:
            '适合品牌视觉、海报和社媒创意场景。支持风格板提炼、视觉关键词生成和跨平台视觉方向统一。',
        tags: ['品牌设计', '提示词', '视觉创意', '海报'],
        category: '产品设计',
        type: '创作助手',
        memberFree: true,
        downloads: 845,
        installs: 3010,
        updatedAt: '2026-03-29'
    },
    {
        id: 'creator-business-modeler',
        badge: 'PRO',
        title: '创作者商业模型师',
        summary: '梳理创作者的内容、产品和服务组合，形成更清晰的变现路径。',
        description:
            '适合个人 IP、咨询顾问和知识创作者。可以输出产品梯度、内容矩阵、用户旅程和商业化试验方案。',
        tags: ['个人IP', '商业化', '内容创业', '策略'],
        category: '运营增长',
        type: '行业模板',
        memberFree: false,
        price: 14.99,
        downloads: 590,
        installs: 2140,
        updatedAt: '2026-03-17'
    },
    {
        id: 'multichannel-distribution-orchestrator',
        badge: 'HOT',
        title: '多渠道分发协同官',
        summary: '把同一份内容拆解为公众号、视频号、社群和邮件等多渠道版本。',
        description:
            '适合内容运营和品牌传播团队。支持主内容拆条、渠道差异化改写和统一排期，让分发工作更高效。',
        tags: ['内容分发', '多渠道', '运营效率', '品牌传播'],
        category: '内容创作',
        type: '工作流',
        memberFree: true,
        downloads: 1110,
        installs: 3960,
        updatedAt: '2026-04-06'
    }
]

export const SKILL_SQUARE_ITEMS: SkillItem[] = SKILL_LIBRARY
export const HERO_SKILLS: SkillItem[] = SKILL_LIBRARY.slice(0, 1)
export const HOT_SKILLS: SkillItem[] = SKILL_LIBRARY.slice(0, 8)

export function getSkillById(id: string) {
    return SKILL_LIBRARY.find((item) => item.id === id)
}
