<template>
    <view class="page">
        <view class="page-bg"></view>
        <view class="page-lines"></view>

        <view class="topbar" :style="topbarStyle">
            <view class="nav-row" :style="navRowStyle">
                <view class="nav-btn" @click="goBack">
                    <u-icon name="arrow-left" color="#ffffff" size="38"></u-icon>
                </view>
                <view class="page-title" :style="pageTitleStyle">数字人创作</view>
            </view>
        </view>

        <scroll-view class="content" scroll-y>
            <view class="subtitle">选择形象与音色，输入文案，快速生成你的数字人视频</view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">1. 选择形象</view>
                    <view class="step-link" @click="goAvatarAssets(avatarMode)"
                        >查看全部 <u-icon name="arrow-right" color="#8a93a3" size="24"></u-icon
                    ></view>
                </view>
                <view class="source-tabs">
                    <view
                        class="source-tab"
                        :class="{ 'is-active': avatarMode === 'official' }"
                        @click="avatarMode = 'official'"
                    >
                        <view class="source-tab__mark">官</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">官方形象</view>
                            <view class="source-tab__sub">{{ officialAvatars.length }}个可用</view>
                        </view>
                    </view>
                    <view
                        class="source-tab"
                        :class="{ 'is-active': avatarMode === 'mine' }"
                        @click="avatarMode = 'mine'"
                    >
                        <view class="source-tab__mark">我</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">我的克隆</view>
                            <view class="source-tab__sub">{{
                                mineAvatars.length ? mineAvatars.length + '个形象' : '去克隆形象'
                            }}</view>
                        </view>
                    </view>
                </view>
                <scroll-view class="asset-scroll" scroll-x>
                    <view class="avatar-list">
                        <view
                            v-for="item in displayedAvatars"
                            :key="item.id"
                            class="avatar-item"
                            :class="{ 'is-active': form.avatar_id === item.id }"
                            @click="selectAvatar(item)"
                        >
                            <view class="check-badge" v-if="form.avatar_id === item.id">
                                <u-icon name="checkmark" color="#ffffff" size="26"></u-icon>
                            </view>
                            <image
                                v-if="item.cover_url || item.media_url"
                                class="avatar-img"
                                :src="item.cover_url || item.media_url"
                                mode="aspectFill"
                            />
                            <view v-else class="avatar-empty">{{
                                item.source === 'mine' ? '我' : 'A'
                            }}</view>
                            <view class="asset-name">{{ item.name }}</view>
                        </view>
                        <view
                            v-if="!displayedAvatars.length"
                            class="create-inline"
                            @click="goAvatarAssets(avatarMode)"
                        >
                            <view class="create-plus">+</view>
                            <view>{{ avatarMode === 'mine' ? '创建形象' : '添加形象' }}</view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">2. 选择音色</view>
                    <view class="step-link" @click="goVoiceAssets(voiceSource)"
                        >全部音色
                        <u-icon name="arrow-right" color="rgba(255,255,255,0.72)" size="24"></u-icon
                    ></view>
                </view>
                <view class="source-tabs">
                    <view
                        class="source-tab"
                        :class="{ 'is-active': voiceSource === 'official' }"
                        @click="voiceSource = 'official'"
                    >
                        <view class="source-tab__mark">声</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">官方音色</view>
                            <view class="source-tab__sub">{{ officialVoices.length }}个可用</view>
                        </view>
                    </view>
                    <view
                        class="source-tab"
                        :class="{ 'is-active': voiceSource === 'mine' }"
                        @click="voiceSource = 'mine'"
                    >
                        <view class="source-tab__mark">录</view>
                        <view class="source-tab__body">
                            <view class="source-tab__main">我的克隆</view>
                            <view class="source-tab__sub">{{
                                mineVoices.length ? mineVoices.length + '个音色' : '微信/录音克隆'
                            }}</view>
                        </view>
                    </view>
                </view>
                <view class="voice-tabs">
                    <view
                        v-for="item in voiceTabs"
                        :key="item.value"
                        class="voice-tab"
                        :class="{ 'is-active': voiceMode === item.value }"
                        @click="voiceMode = item.value"
                    >
                        {{ item.label }}
                    </view>
                </view>
                <scroll-view class="asset-scroll" scroll-x>
                    <view class="voice-list">
                        <view
                            v-for="(item, index) in displayedVoices"
                            :key="item.id"
                            class="voice-item"
                            :class="{ 'is-active': form.voice_id === item.id }"
                            @click="selectVoice(item)"
                        >
                            <view class="check-badge" v-if="form.voice_id === item.id">
                                <u-icon name="checkmark" color="#ffffff" size="26"></u-icon>
                            </view>
                            <view class="play-circle" :class="`is-${index % 4}`">
                                <u-icon name="play-right-fill" color="#ffffff" size="34"></u-icon>
                            </view>
                            <view class="voice-name">{{ item.name }}</view>
                            <view class="voice-desc">{{ voiceDesc(item, index) }}</view>
                        </view>
                        <view
                            v-if="!displayedVoices.length"
                            class="create-inline create-inline--voice"
                            @click="goVoiceAssets(voiceSource)"
                        >
                            <view class="create-plus">+</view>
                            <view>{{ voiceSource === 'mine' ? '创建音色' : '添加音色' }}</view>
                        </view>
                    </view>
                </scroll-view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">3. 选择模型</view>
                </view>
                <view class="model-list">
                    <view
                        v-for="item in modelOptions"
                        :key="item.value"
                        class="model-item"
                        :class="{ 'is-active': form.channel === item.value }"
                        @click="selectModel(item)"
                    >
                        <view>
                            <view class="model-name">{{ item.description || item.label }}</view>
                            <view class="model-desc">按音频时长计费</view>
                        </view>
                        <view class="model-price">{{ modelPriceText(item) }}</view>
                    </view>
                </view>
            </view>

            <view class="step-card">
                <view class="step-head">
                    <view class="step-title">4. 输入文案</view>
                    <view class="assistant-pill" @click="openEmotionPanel">
                        <text>/ 情绪</text>
                    </view>
                    <view class="assistant-pill" @click="fillScriptExample">
                        <u-icon name="edit-pen" color="#ffffff" size="28"></u-icon>
                        <text>文案助手</text>
                    </view>
                </view>
                <view class="script-box">
                    <textarea
                        v-model="form.script_text"
                        maxlength="500"
                        placeholder="请输入或粘贴您想要数字人说的内容..."
                        placeholder-class="input-placeholder"
                        @input="handleScriptInput"
                    />
                    <view class="counter">{{ form.script_text.length }}/500</view>
                </view>
                <view class="tool-row">
                    <button type="button" @click="form.script_text = ''">
                        <u-icon name="trash" color="#87909f" size="26"></u-icon>
                        <text>清空文案</text>
                    </button>
                    <button type="button" @click="pasteScript">
                        <u-icon name="file-text" color="#87909f" size="26"></u-icon>
                        <text>粘贴文案</text>
                    </button>
                </view>
            </view>

            <view class="page-bottom-space"></view>
        </scroll-view>

        <view class="bottom-bar">
            <button class="history-btn" @click="goResults">
                <u-icon name="clock" color="#ffffff" size="38"></u-icon>
                <view>创作记录</view>
            </button>
            <button
                class="generate-btn"
                :class="{ 'is-disabled': submitting }"
                :loading="submitting"
                :disabled="submitting"
                @click="handleGenerate"
            >
                <view>{{ submitting ? '提交中...' : '立即合成' }}</view>
                <text>{{ estimateText }}</text>
            </button>
        </view>

        <view v-if="submitting" class="submit-mask" @click.stop @touchmove.stop.prevent>
            <view class="submit-loading">
                <u-loading mode="circle" color="#ffffff" size="52"></u-loading>
                <view class="submit-loading__title">正在提交合成任务</view>
                <view class="submit-loading__desc">任务创建成功后将进入合成进度</view>
            </view>
        </view>

        <view v-if="emotionPanelOpen" class="emotion-mask" @click="closeEmotionPanel" @touchmove.stop.prevent>
            <view class="emotion-sheet" @click.stop>
                <view class="emotion-sheet__handle"></view>
                <view class="emotion-sheet__head">
                    <view>
                        <view class="emotion-sheet__title">S2-Pro 情绪控制</view>
                        <view class="emotion-sheet__sub">选择后插入方括号标记，输入 /笑 /happy 可筛选</view>
                    </view>
                    <button type="button" @click="closeEmotionPanel">关闭</button>
                </view>
                <scroll-view class="emotion-tabs" scroll-x>
                    <view class="emotion-tabs__inner">
                        <view
                            v-for="category in emotionCategories"
                            :key="category"
                            class="emotion-tab"
                            :class="{ 'is-active': activeEmotionCategory === category }"
                            @click="setEmotionCategory(category)"
                        >
                            {{ category }}
                        </view>
                    </view>
                </scroll-view>
                <scroll-view class="emotion-list" scroll-y>
                    <view
                        v-for="item in filteredEmotionOptions"
                        :key="item.tag"
                        class="emotion-item"
                        @click="insertEmotionMarker(item)"
                    >
                        <view class="emotion-item__top">
                            <view class="emotion-item__label">{{ item.label }}</view>
                            <view class="emotion-item__tag">{{ item.tag }}</view>
                        </view>
                        <view class="emotion-item__desc">{{ item.description }}</view>
                        <view class="emotion-item__scene">适合：{{ item.scene }}</view>
                        <view class="emotion-item__example">{{ item.example }}</view>
                    </view>
                    <view v-if="!filteredEmotionOptions.length" class="emotion-empty">
                        未找到匹配的情绪控制
                    </view>
                </scroll-view>
            </view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getMembershipAppAccess } from '@/api/membership'
import {
    generateAigcDigitalHuman,
    estimateAigcDigitalHuman,
    getAigcDigitalHumanAvatars,
    getAigcDigitalHumanConfig,
    getAigcDigitalHumanVoices
} from '@/apps/aigc_digital_human/api'

type EmotionCategory = '情绪' | '语气' | '音效' | '场景'

interface EmotionOption {
    category: EmotionCategory
    label: string
    tag: string
    description: string
    scene: string
    example: string
    keywords: string[]
}

const submitting = ref(false)
const estimating = ref(false)
const membershipAccess = ref<any>({
    need_membership: 0,
    allowed: 1,
    member_status: 'none'
})
const estimateInfo = ref<any>({})
const avatars = ref<any[]>([])
const voices = ref<any[]>([])
const avatarMode = ref('official')
const voiceSource = ref('official')
const voiceMode = ref('hot')
const emotionPanelOpen = ref(false)
const activeEmotionCategory = ref<EmotionCategory>('情绪')
const emotionSearchKeyword = ref('')
const emotionTriggerRange = ref({ start: 0, end: 0 })
const optionConfig = ref<any>({
    defaults: { channel: 'master', quality: '1k', ratio: '9:16' }
})
const navMetrics = reactive({
    statusBarHeight: 24,
    menuTop: 44,
    menuHeight: 32,
    menuWidth: 88,
    navHeight: 88
})
const form = reactive({
    avatar_id: 0,
    voice_id: 0,
    title: '数字人创作',
    script_text: '',
    prompt: '',
    channel: 'master',
    quality: '1k',
    ratio: '9:16'
})
const voiceTabs = [
    { label: '热门', value: 'hot' },
    { label: '女声', value: 'female' },
    { label: '男声', value: 'male' },
    { label: '童声', value: 'child' },
    { label: '方言', value: 'dialect' },
    { label: '外语', value: 'foreign' }
]
const voiceDescs = [
    '温柔知性，适合口播',
    '温柔亲切，适合讲解',
    '沉稳磁性，适合介绍',
    '活力阳光，适合短视频'
]
const emotionCategories: EmotionCategory[] = ['情绪', '语气', '音效', '场景']
const emotionOptions: EmotionOption[] = [
    { category: '情绪', label: '开心', tag: '[happy]', description: '声音更明亮、积极，适合开场和好消息。', scene: '欢迎语、福利发布、轻松介绍', example: '[happy] 大家好，欢迎来到直播间。', keywords: ['开心', '高兴', 'happy', '快乐'] },
    { category: '情绪', label: '兴奋', tag: '[excited]', description: '增强能量和感染力，适合突出亮点。', scene: '新品发布、活动通知、抽奖', example: '[excited] 今天给大家介绍一个非常实用的功能。', keywords: ['兴奋', '激动', 'excited'] },
    { category: '情绪', label: '平静', tag: '[calm]', description: '语速更稳，听感克制清晰。', scene: '知识讲解、教程、说明', example: '[calm] 我们先来看第一步操作。', keywords: ['平静', '冷静', 'calm'] },
    { category: '情绪', label: '自信', tag: '[confident]', description: '表达更坚定，适合建立信任。', scene: '销售转化、品牌介绍、观点输出', example: '[confident] 这套方案可以明显提升制作效率。', keywords: ['自信', '坚定', 'confident'] },
    { category: '情绪', label: '温柔', tag: '[gentle]', description: '声音更柔和，亲近感更强。', scene: '陪伴、安抚、女性口播', example: '[gentle] 别着急，我们一步一步来。', keywords: ['温柔', '柔和', 'gentle'] },
    { category: '情绪', label: '紧张', tag: '[nervous]', description: '带一点犹豫和不确定，适合剧情转折。', scene: '悬念、风险提示、故事对白', example: '[nervous] 我不确定这样做是否安全。', keywords: ['紧张', '担心', 'nervous'] },
    { category: '情绪', label: '难过', tag: '[sad]', description: '降低情绪亮度，适合遗憾或共情表达。', scene: '道歉、故事、情绪短片', example: '[sad] 很抱歉，这次没有达到你的期待。', keywords: ['难过', '悲伤', 'sad'] },
    { category: '情绪', label: '惊讶', tag: '[surprised]', description: '语气更有反应感，适合反转内容。', scene: '发现、对比、反转开头', example: '[surprised] 没想到这个方法真的有效。', keywords: ['惊讶', '意外', 'surprised'] },
    { category: '情绪', label: '感谢', tag: '[grateful]', description: '语气真诚，适合表达认可。', scene: '致谢、结尾、用户回访', example: '[grateful] 感谢大家一直以来的支持。', keywords: ['感谢', '感恩', 'grateful'] },
    { category: '情绪', label: '同理', tag: '[empathetic]', description: '更有理解和安抚感，适合服务话术。', scene: '客服、咨询、售后安抚', example: '[empathetic] 我理解你现在的困扰。', keywords: ['同理', '共情', '安抚', 'empathetic'] },
    { category: '语气', label: '低语', tag: '[whispers softly]', description: '降低音量，制造私密和靠近感。', scene: '秘密、悬念、睡前内容', example: '[whispers softly] 接下来这个细节很重要。', keywords: ['低语', '悄悄', 'whisper', 'whispers'] },
    { category: '语气', label: '柔和', tag: '[speaks softly]', description: '语气更轻，适合舒缓内容。', scene: '陪伴、引导、冥想', example: '[speaks softly] 慢慢呼吸，放松下来。', keywords: ['柔和', '轻声', 'softly'] },
    { category: '语气', label: '正式', tag: '[speaks formally]', description: '表达更稳重，适合商务和公告。', scene: '企业介绍、通知、课程', example: '[speaks formally] 欢迎参加本次产品说明会。', keywords: ['正式', '商务', 'formally'] },
    { category: '语气', label: '亲切', tag: '[speaks warmly]', description: '更自然亲近，适合建立好感。', scene: '欢迎语、口播开头、客服', example: '[speaks warmly] 大家好，很高兴又见面了。', keywords: ['亲切', '温暖', 'warmly'] },
    { category: '语气', label: '急促', tag: '[speaks quickly]', description: '节奏更快，适合紧迫信息。', scene: '限时活动、倒计时、提醒', example: '[speaks quickly] 活动今晚十二点就结束。', keywords: ['急促', '快速', 'quickly'] },
    { category: '语气', label: '强调', tag: '[emphasizes]', description: '增强重点感，适合突出关键词。', scene: '卖点、价格、注意事项', example: '[emphasizes] 重点是，它不需要复杂设置。', keywords: ['强调', '重点', 'emphasize', 'emphasizes'] },
    { category: '音效', label: '笑', tag: '[laughing]', description: '加入明显笑意，让内容更轻松。', scene: '轻松开场、互动、幽默段落', example: '[laughing] 这个结果真的太有意思了。', keywords: ['笑', '大笑', 'laugh', 'laughing'] },
    { category: '音效', label: '轻笑', tag: '[chuckles]', description: '轻微笑声，适合自然口播。', scene: '调侃、轻松解释、日常感', example: '[chuckles] 这个小技巧很多人都忽略了。', keywords: ['轻笑', '笑一下', 'chuckle', 'chuckles'] },
    { category: '音效', label: '叹气', tag: '[sighs]', description: '加入叹息感，表达无奈或释然。', scene: '故事、情绪、问题说明', example: '[sighs] 事情一开始并不顺利。', keywords: ['叹气', '叹息', 'sigh', 'sighs'] },
    { category: '音效', label: '停顿', tag: '[pause]', description: '短暂停顿，帮助信息分层。', scene: '转折、强调、分句', example: '先完成账号设置。[pause] 然后上传素材。', keywords: ['停顿', '暂停', 'pause'] },
    { category: '音效', label: '长停顿', tag: '[long pause]', description: '更长的留白，适合情绪转场。', scene: '故事转折、重要结论、悬念', example: '答案其实很简单。[long pause] 先从需求开始。', keywords: ['长停顿', '长暂停', 'long pause'] },
    { category: '场景', label: '直播开场', tag: '[speaks warmly and energetically]', description: '亲切又有活力，适合开头抓注意力。', scene: '直播、短视频开场、欢迎语', example: '[speaks warmly and energetically] 大家好，欢迎来到今天的直播间。', keywords: ['直播', '开场', '欢迎'] },
    { category: '场景', label: '产品介绍', tag: '[confident and clear]', description: '清晰且有说服力，突出产品价值。', scene: '卖点讲解、品牌介绍、种草', example: '[confident and clear] 这款产品最大的优势是效率高。', keywords: ['产品', '介绍', '卖点'] },
    { category: '场景', label: '客服安抚', tag: '[speaks empathetically]', description: '更有理解感，降低用户焦虑。', scene: '售后、客服、咨询', example: '[speaks empathetically] 我理解你的情况，我们马上帮你处理。', keywords: ['客服', '安抚', '售后'] },
    { category: '场景', label: '故事旁白', tag: '[narrates calmly]', description: '稳定叙述感，适合讲故事。', scene: '剧情、科普、案例复盘', example: '[narrates calmly] 故事要从一个普通的下午说起。', keywords: ['故事', '旁白', '叙述'] },
    { category: '场景', label: '限时促销', tag: '[urgent and excited]', description: '紧迫且有行动号召。', scene: '促销、倒计时、活动提醒', example: '[urgent and excited] 限时优惠马上结束，记得及时领取。', keywords: ['促销', '限时', '活动', 'urgent'] }
]

const selectedAvatar = computed(() => avatars.value.find((item) => item.id === form.avatar_id))
const selectedVoice = computed(() => voices.value.find((item) => item.id === form.voice_id))
const filteredEmotionOptions = computed(() => {
    const keyword = emotionSearchKeyword.value.trim().toLowerCase()
    return emotionOptions.filter((item) => {
        const categoryMatched = item.category === activeEmotionCategory.value
        if (!keyword) return categoryMatched
        const haystack = [item.label, item.tag, item.description, item.scene, item.example, ...item.keywords].join(' ').toLowerCase()
        return categoryMatched && haystack.includes(keyword)
    })
})
const modelOptions = computed(() =>
    (optionConfig.value.channels || []).map((item: any) => ({
        label: item.label || item.description || '数字人视频模型',
        value: item.value || item.code,
        description: item.description || item.label || '数字人视频模型',
        tenant_unit_price: item.tenant_unit_price,
        qualities: item.qualities || []
    }))
)
const estimatedDuration = computed(() =>
    Math.max(1, Math.ceil((form.script_text.trim().length || 1) / 4))
)
const estimateText = computed(() => {
    const points = estimateInfo.value.user_charge_points
    if (points !== undefined && points !== null && points !== '') {
        return `约${estimatedDuration.value}秒 · ${points}积分`
    }
    return `约${estimatedDuration.value}秒，按音频时长计费`
})
const officialAvatars = computed(() => avatars.value.filter((item) => item.source === 'official'))
const mineAvatars = computed(() => avatars.value.filter((item) => item.source === 'mine'))
const displayedAvatars = computed(() =>
    (avatarMode.value === 'mine' ? mineAvatars.value : officialAvatars.value).slice(0, 8)
)
const officialVoices = computed(() => voices.value.filter((item) => item.source === 'official'))
const mineVoices = computed(() => voices.value.filter((item) => item.source === 'mine'))
const displayedVoices = computed(() =>
    (voiceSource.value === 'mine' ? mineVoices.value : officialVoices.value).slice(0, 8)
)
const topbarStyle = computed(() => ({ height: `${navMetrics.navHeight}px` }))
const navRowStyle = computed(() => ({
    top: `${navMetrics.menuTop}px`,
    height: `${navMetrics.menuHeight}px`
}))
const pageTitleStyle = computed(() => ({
    height: `${navMetrics.menuHeight}px`,
    lineHeight: `${navMetrics.menuHeight}px`
}))

const initNavMetrics = () => {
    const systemInfo = uni.getSystemInfoSync()
    navMetrics.statusBarHeight = systemInfo.statusBarHeight || navMetrics.statusBarHeight
    // #ifdef MP-WEIXIN
    const menuButton = uni.getMenuButtonBoundingClientRect()
    navMetrics.menuTop = menuButton.top
    navMetrics.menuHeight = menuButton.height
    navMetrics.menuWidth = systemInfo.windowWidth - menuButton.left
    navMetrics.navHeight = menuButton.top + menuButton.height + 20
    // #endif
    // #ifndef MP-WEIXIN
    navMetrics.menuTop = navMetrics.statusBarHeight + 10
    navMetrics.menuHeight = 36
    navMetrics.menuWidth = 0
    navMetrics.navHeight = navMetrics.menuTop + navMetrics.menuHeight + 20
    // #endif
}

const syncSelection = () => {
    const storedAvatar = uni.getStorageSync('aigc_digital_human_selected_avatar')
    const storedVoice = uni.getStorageSync('aigc_digital_human_selected_voice')
    if (storedAvatar?.id && avatars.value.some((item) => item.id === storedAvatar.id))
        form.avatar_id = storedAvatar.id
    if (storedVoice?.id && voices.value.some((item) => item.id === storedVoice.id))
        form.voice_id = storedVoice.id
    if (!form.avatar_id && officialAvatars.value.length)
        form.avatar_id = officialAvatars.value[0].id
    if (!form.avatar_id && avatars.value.length) form.avatar_id = avatars.value[0].id
    if (!form.voice_id && officialVoices.value.length) form.voice_id = officialVoices.value[0].id
    if (!form.voice_id && voices.value.length) form.voice_id = voices.value[0].id
    const currentAvatar = avatars.value.find((item) => item.id === form.avatar_id)
    const currentVoice = voices.value.find((item) => item.id === form.voice_id)
    avatarMode.value = currentAvatar?.source === 'mine' ? 'mine' : 'official'
    voiceSource.value = currentVoice?.source === 'mine' ? 'mine' : 'official'
}

const getData = async () => {
    const [config, avatarRows, voiceRows] = await Promise.all([
        getAigcDigitalHumanConfig(),
        getAigcDigitalHumanAvatars(),
        getAigcDigitalHumanVoices()
    ])
    optionConfig.value = config?.option_config || optionConfig.value
    const defaults = optionConfig.value.defaults || {}
    form.channel = defaults.channel || form.channel
    form.quality = defaults.quality || form.quality
    form.ratio = defaults.ratio || form.ratio
    avatars.value = avatarRows || []
    voices.value = voiceRows || []
    const reuse = uni.getStorageSync('aigc_digital_human_reuse')
    if (reuse) {
        form.script_text = reuse.script_text || form.script_text
        form.avatar_id = reuse.avatar_id || form.avatar_id
        form.voice_id = reuse.voice_id || form.voice_id
        form.channel = reuse.channel || form.channel
        form.quality = reuse.quality || form.quality
        form.ratio = reuse.ratio || form.ratio
        uni.removeStorageSync('aigc_digital_human_reuse')
    }
    syncSelection()
    refreshEstimate()
}

const selectAvatar = (item: any) => {
    form.avatar_id = item.id
    uni.setStorageSync('aigc_digital_human_selected_avatar', item)
}

const selectVoice = (item: any) => {
    form.voice_id = item.id
    uni.setStorageSync('aigc_digital_human_selected_voice', item)
}

const selectModel = (item: any) => {
    form.channel = item.value
    const quality = item.qualities?.[0]
    const ratio = quality?.ratios?.[0]
    if (quality?.value) form.quality = quality.value
    if (ratio?.value || ratio?.ratio) form.ratio = ratio.value || ratio.ratio
    refreshEstimate()
}

const modelPriceText = (item: any) => {
    const price = item?.tenant_unit_price
    return price !== undefined && price !== null && price !== '' ? `${price}积分/秒` : '按秒计费'
}

const refreshEstimate = async () => {
    if (estimating.value) return
    estimating.value = true
    try {
        estimateInfo.value = await estimateAigcDigitalHuman({
            channel: form.channel,
            quality: form.quality,
            ratio: form.ratio,
            duration: estimatedDuration.value
        })
    } catch (error) {
        estimateInfo.value = {}
    } finally {
        estimating.value = false
    }
}

const voiceDesc = (item: any, index: number) =>
    item.description || voiceDescs[index % voiceDescs.length]

const fillScriptExample = () => {
    form.script_text =
        '大家好，欢迎了解我们的产品。它能帮助你快速完成内容创作，用数字人讲清楚重点，让短视频表达更自然、更高效。'
}

const handleScriptInput = (event: any) => {
    const value = String(event?.detail?.value ?? form.script_text ?? '')
    const match = value.match(/\/([^\s/\[\]]*)$/)
    if (!match) {
        emotionSearchKeyword.value = ''
        return
    }
    emotionSearchKeyword.value = match[1] || ''
    emotionTriggerRange.value = { start: value.length - match[0].length, end: value.length }
    emotionPanelOpen.value = true
}

const openEmotionPanel = () => {
    emotionSearchKeyword.value = ''
    emotionTriggerRange.value = { start: form.script_text.length, end: form.script_text.length }
    emotionPanelOpen.value = true
}

const closeEmotionPanel = () => {
    emotionPanelOpen.value = false
    emotionSearchKeyword.value = ''
}

const setEmotionCategory = (category: EmotionCategory) => {
    activeEmotionCategory.value = category
}

const insertEmotionMarker = (item: EmotionOption) => {
    const marker = `${item.tag} `
    const range = emotionPanelOpen.value ? emotionTriggerRange.value : { start: form.script_text.length, end: form.script_text.length }
    const nextValue = `${form.script_text.slice(0, range.start)}${marker}${form.script_text.slice(range.end)}`
    if (Array.from(nextValue).length > 500) {
        uni.$u.toast('文案长度已达上限')
        return
    }
    form.script_text = nextValue
    closeEmotionPanel()
    uni.$u.toast('已插入情绪标记')
}

const pasteScript = () => {
    uni.getClipboardData({
        success: (res) => {
            form.script_text = String(res.data || '').slice(0, 500)
        }
    })
}

const handleGenerate = async () => {
    if (!(await ensureMembershipAccess())) return
    if (!form.avatar_id) return uni.$u.toast('请选择形象')
    if (!form.voice_id) return uni.$u.toast('请选择音色')
    if (selectedAvatar.value?.media_type && selectedAvatar.value.media_type !== 'video')
        return uni.$u.toast('请选择可合成的视频形象')
    if (!selectedVoice.value?.provider_asset_id) return uni.$u.toast('当前音色未完成克隆，无法合成')
    if (!form.script_text.trim()) return uni.$u.toast('请输入文案')
    submitting.value = true
    try {
        const payload = {
            avatar_id: form.avatar_id,
            voice_id: form.voice_id,
            title: selectedAvatar.value?.name || form.title,
            script_text: form.script_text.trim(),
            prompt: form.prompt,
            channel: form.channel,
            quality: form.quality,
            ratio: form.ratio,
            duration: estimatedDuration.value
        }
        const task = await generateAigcDigitalHuman(payload)
        uni.setStorageSync('aigc_digital_human_latest_generate', {
            ...payload,
            ...task,
            avatar: selectedAvatar.value,
            voice: selectedVoice.value
        })
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/tasks/tasks' })
    } finally {
        submitting.value = false
    }
}

const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) return uni.navigateBack()
    uni.switchTab({ url: '/pages/index/index' })
}
const goAvatarAssets = (source: string) => {
    if (source === 'mine') {
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/avatar/avatar' })
        return
    }
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/assets/avatar/avatar?source=official' })
}
const goVoiceAssets = (source: string) => {
    if (source === 'mine') {
        uni.navigateTo({ url: '/apps/aigc_digital_human/pages/clone/voice/voice' })
        return
    }
    uni.navigateTo({ url: '/apps/aigc_digital_human/pages/assets/voice/voice?source=official' })
}
const goResults = () => uni.navigateTo({ url: '/apps/aigc_digital_human/pages/results/results' })

const ensureMembershipAccess = async () => {
    try {
        const data = await getMembershipAppAccess({ app_code: 'aigc_digital_human' })
        membershipAccess.value = data || membershipAccess.value
        if (Number(data?.need_membership || 0) === 1 && Number(data?.allowed || 0) !== 1) {
            uni.showModal({
                title: '会员专享应用',
                content: '该应用需开通会员后使用',
                confirmText: '去开通',
                success: (res) => {
                    if (res.confirm) {
                        uni.navigateTo({ url: '/packages/pages/membership/membership' })
                    }
                }
            })
            return false
        }
    } catch (error) {
        return false
    }
    return true
}

initNavMetrics()
onShow(() => {
    ensureMembershipAccess()
    getData()
})
watch(() => [form.channel, form.quality, form.ratio, form.script_text], refreshEstimate)
</script>

<style lang="scss" scoped>
.page {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #050505;
    color: #ffffff;
}

.page-bg,
.page-lines {
    position: fixed;
    inset: 0;
    pointer-events: none;
}

.page-bg {
    background: radial-gradient(circle at 78% -6%, rgba(255, 255, 255, 0.055), transparent 32%),
        radial-gradient(circle at 10% 2%, rgba(255, 255, 255, 0.035), transparent 34%),
        linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%);
}

.page-lines {
    opacity: 0.34;
    background: repeating-radial-gradient(
        circle at 52% -8%,
        transparent 0 42rpx,
        rgba(88, 112, 180, 0.18) 44rpx 46rpx,
        transparent 48rpx 78rpx
    );
}

.topbar {
    position: relative;
    z-index: 2;
}

.nav-row {
    position: absolute;
    left: 34rpx;
    right: 34rpx;
    display: flex;
    align-items: center;
    justify-content: center;
}

.nav-btn {
    position: absolute;
    left: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 52rpx;
    height: 100%;
}

.page-title {
    max-width: 310rpx;
    overflow: hidden;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 36rpx;
    font-weight: 900;
}

.content {
    position: relative;
    z-index: 1;
    box-sizing: border-box;
    height: calc(100vh - 112rpx - var(--status-bar-height));
    padding: 18rpx 32rpx 226rpx;
}

.subtitle {
    margin: 8rpx 0 28rpx;
    color: rgba(255, 255, 255, 0.58);
    text-align: center;
    font-size: 28rpx;
    line-height: 1.35;
}

.step-card {
    margin-bottom: 42rpx;
    padding: 30rpx 28rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.06);
    border-radius: 24rpx;
    background: rgba(34, 34, 34, 0.96);
}

.step-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24rpx;
}

.step-title {
    color: #ffffff;
    font-size: 31rpx;
    font-weight: 900;
}

.step-link,
.assistant-pill {
    display: flex;
    align-items: center;
    gap: 6rpx;
    color: #8a93a3;
    font-size: 25rpx;
    font-weight: 700;
}

.assistant-pill {
    padding: 8rpx 16rpx;
    border-radius: 999rpx;
    background: #eff6ff;
    color: #ffffff;
    background: rgba(255, 255, 255, 0.08);
}

.source-tabs {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12rpx;
    margin-bottom: 24rpx;
    padding: 8rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 22rpx;
    background: rgba(255, 255, 255, 0.035);
}

.source-tab {
    position: relative;
    display: flex;
    align-items: center;
    gap: 14rpx;
    min-height: 88rpx;
    padding: 0 16rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 18rpx;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.58);
    transition: all 0.18s ease;
}

.source-tab__mark {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 46rpx;
    height: 46rpx;
    border-radius: 14rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.76);
    font-size: 22rpx;
    font-weight: 900;
}

.source-tab__body {
    flex: 1;
    min-width: 0;
}

.source-tab__main {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(255, 255, 255, 0.72);
    font-size: 27rpx;
    font-weight: 900;
}

.source-tab__sub {
    overflow: hidden;
    margin-top: 4rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(255, 255, 255, 0.38);
    font-size: 21rpx;
    font-weight: 600;
}

.source-tab.is-active {
    border-color: rgba(255, 255, 255, 0.3);
    background: linear-gradient(180deg, #3b3c3d 0%, #2e2f30 100%);
    box-shadow: inset 0 1rpx 0 rgba(255, 255, 255, 0.15), 0 14rpx 30rpx rgba(0, 0, 0, 0.26);
}

.source-tab.is-active .source-tab__mark {
    background: #ffffff;
    color: #171719;
}

.source-tab.is-active .source-tab__main,
.source-tab.is-active .source-tab__sub {
    color: #ffffff;
}

.asset-scroll {
    width: 100%;
    white-space: nowrap;
}

.avatar-list,
.voice-list {
    display: inline-flex;
    gap: 18rpx;
    min-width: 100%;
}

.avatar-item {
    position: relative;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    overflow: hidden;
    width: 148rpx;
    height: 198rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.avatar-item.is-active,
.voice-item.is-active {
    border-color: #3b82ff;
    box-shadow: 0 0 0 2rpx rgba(59, 130, 255, 0.16);
}

.check-badge {
    position: absolute;
    z-index: 2;
    top: -1rpx;
    right: -1rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44rpx;
    height: 44rpx;
    border-radius: 0 14rpx 0 22rpx;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
}

.avatar-img,
.avatar-empty {
    width: 100%;
    height: 142rpx;
    background: linear-gradient(180deg, #313233 0%, #2a2b2c 100%);
}

.avatar-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
    font-size: 42rpx;
    font-weight: 900;
}

.asset-name {
    box-sizing: border-box;
    width: 100%;
    overflow: hidden;
    padding: 12rpx 8rpx 0;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 24rpx;
    font-weight: 800;
}

.create-inline {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 148rpx;
    height: 198rpx;
    border: 2rpx dashed rgba(255, 255, 255, 0.42);
    border-radius: 16rpx;
    color: rgba(255, 255, 255, 0.68);
    font-size: 24rpx;
}

.create-plus {
    margin-bottom: 12rpx;
    color: #ffffff;
    font-size: 44rpx;
    font-weight: 500;
}

.voice-tabs {
    display: flex;
    gap: 12rpx;
    margin-bottom: 24rpx;
    overflow: hidden;
}

.voice-tab {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    min-width: 88rpx;
    height: 52rpx;
    padding: 0 18rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 14rpx;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.68);
    font-size: 25rpx;
    font-weight: 700;
}

.voice-tab.is-active {
    border-color: rgba(255, 255, 255, 0.3);
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    color: #ffffff;
}

.voice-item {
    position: relative;
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    width: 156rpx;
    height: 174rpx;
    padding: 22rpx 12rpx 14rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.play-circle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72rpx;
    height: 72rpx;
    border-radius: 50%;
    background: linear-gradient(135deg, #8fc0ff, #4f8df7);
}

.play-circle.is-1 {
    background: linear-gradient(135deg, #ff9ac8, #f15ca4);
}

.play-circle.is-2 {
    background: linear-gradient(135deg, #8fc0ff, #4f8df7);
}

.play-circle.is-3 {
    background: linear-gradient(135deg, #b69aff, #7d5df0);
}

.voice-name {
    overflow: hidden;
    width: 100%;
    margin-top: 16rpx;
    color: #ffffff;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 25rpx;
    font-weight: 900;
}

.voice-desc {
    overflow: hidden;
    width: 100%;
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.48);
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 20rpx;
}

.create-inline--voice {
    width: 156rpx;
    height: 174rpx;
}

.model-list {
    display: grid;
    gap: 16rpx;
}

.model-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20rpx;
    padding: 22rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.08);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.055);
}

.model-item.is-active {
    border-color: #3b82ff;
    background: rgba(59, 130, 255, 0.14);
    box-shadow: 0 0 0 2rpx rgba(59, 130, 255, 0.16);
}

.model-name {
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 900;
}

.model-desc {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 22rpx;
}

.model-price {
    flex: none;
    color: #ffffff;
    font-size: 24rpx;
    font-weight: 800;
}

.script-box {
    position: relative;
    min-height: 210rpx;
    padding: 20rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.04);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.02);
}

.script-box textarea {
    width: 100%;
    height: 170rpx;
    color: #ffffff;
    font-size: 27rpx;
    line-height: 1.55;
}

.input-placeholder {
    color: rgba(255, 255, 255, 0.42);
}

.counter {
    position: absolute;
    right: 20rpx;
    bottom: 18rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
}

.tool-row {
    display: flex;
    gap: 20rpx;
    margin-top: 18rpx;
}

.tool-row button {
    display: flex;
    align-items: center;
    gap: 8rpx;
    height: 52rpx;
    margin: 0;
    padding: 0 18rpx;
    border: 0;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.68);
    font-size: 24rpx;
}

.emotion-mask {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    align-items: flex-end;
    background: rgba(0, 0, 0, 0.58);
}

.emotion-sheet {
    width: 100%;
    max-height: 78vh;
    padding: 18rpx 24rpx calc(24rpx + env(safe-area-inset-bottom));
    border-radius: 28rpx 28rpx 0 0;
    background: #101012;
    box-shadow: 0 -20rpx 80rpx rgba(0, 0, 0, 0.42);
}

.emotion-sheet__handle {
    width: 72rpx;
    height: 8rpx;
    margin: 0 auto 22rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.22);
}

.emotion-sheet__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20rpx;
}

.emotion-sheet__title {
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 900;
}

.emotion-sheet__sub {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.52);
    font-size: 22rpx;
    line-height: 1.5;
}

.emotion-sheet__head button {
    flex: none;
    height: 52rpx;
    margin: 0;
    padding: 0 22rpx;
    border: 0;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.08);
    color: rgba(255, 255, 255, 0.72);
    font-size: 24rpx;
}

.emotion-tabs {
    margin-top: 22rpx;
    white-space: nowrap;
}

.emotion-tabs__inner {
    display: flex;
    gap: 14rpx;
}

.emotion-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 56rpx;
    padding: 0 26rpx;
    border-radius: 999rpx;
    background: rgba(255, 255, 255, 0.07);
    color: rgba(255, 255, 255, 0.66);
    font-size: 24rpx;
}

.emotion-tab.is-active {
    background: #ffffff;
    color: #050505;
    font-weight: 800;
}

.emotion-list {
    max-height: 52vh;
    margin-top: 20rpx;
}

.emotion-item {
    display: flex;
    flex-direction: column;
    gap: 8rpx;
    margin-bottom: 14rpx;
    padding: 20rpx;
    border: 1rpx solid rgba(255, 255, 255, 0.07);
    border-radius: 16rpx;
    background: rgba(255, 255, 255, 0.045);
}

.emotion-item__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16rpx;
}

.emotion-item__label {
    color: #ffffff;
    font-size: 28rpx;
    font-weight: 900;
}

.emotion-item__tag {
    max-width: 360rpx;
    overflow: hidden;
    color: #ffffff;
    font-size: 22rpx;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.emotion-item__desc,
.emotion-item__scene {
    color: rgba(255, 255, 255, 0.58);
    font-size: 23rpx;
    line-height: 1.5;
}

.emotion-item__example {
    color: rgba(255, 255, 255, 0.8);
    font-size: 23rpx;
    line-height: 1.5;
}

.emotion-empty {
    padding: 40rpx 0;
    color: rgba(255, 255, 255, 0.48);
    text-align: center;
    font-size: 24rpx;
}

.page-bottom-space {
    height: 1rpx;
}

.bottom-bar {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 5;
    display: grid;
    grid-template-columns: 1fr 1.8fr;
    gap: 22rpx;
    padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom));
    border-top: 1rpx solid rgba(255, 255, 255, 0.08);
    background: rgba(9, 10, 15, 0.98);
}

.history-btn,
.generate-btn {
    height: 104rpx;
    margin: 0;
    border-radius: 16rpx;
    color: #ffffff;
    background: linear-gradient(180deg, #3a3b3c 0%, #2f3031 100%);
    font-size: 27rpx;
    line-height: 1.15;
    font-weight: 700;
}

.history-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
    background: rgba(255, 255, 255, 0.08);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
}

.generate-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8rpx;
}

.generate-btn text {
    color: rgba(255, 255, 255, 0.72);
    font-size: 22rpx;
}

.generate-btn.is-disabled,
.generate-btn[disabled] {
    color: #ffffff;
    background: linear-gradient(180deg, rgba(58, 59, 60, 0.72) 0%, rgba(47, 48, 49, 0.72) 100%);
    opacity: 0.86;
}

.submit-mask {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 48rpx;
    background: rgba(0, 0, 0, 0.52);
    backdrop-filter: blur(6rpx);
    box-sizing: border-box;
}

.submit-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 420rpx;
    padding: 46rpx 34rpx;
    border-radius: 24rpx;
    background: rgba(20, 21, 24, 0.96);
    border: 1rpx solid rgba(255, 255, 255, 0.12);
    box-shadow: 0 28rpx 72rpx rgba(0, 0, 0, 0.38);
}

.submit-loading__title {
    margin-top: 24rpx;
    color: #ffffff;
    font-size: 30rpx;
    font-weight: 700;
}

.submit-loading__desc {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.58);
    font-size: 24rpx;
}
</style>
