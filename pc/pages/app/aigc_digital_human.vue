<template>
    <div class="dh-page">
        <header class="dh-header">
            <div>
                <div class="dh-kicker">DIGITAL HUMAN STUDIO</div>
                <h1>数字人视频</h1>
                <p>选择视频形象与可合成音色，输入口播文案后生成数字人视频。</p>
            </div>
            <div class="dh-header__actions">
                <button type="button" @click="getData">刷新</button>
                <button type="button" @click="activeLibrary = 'tasks'">任务</button>
                <button type="button" @click="activeLibrary = 'works'">作品</button>
            </div>
        </header>

        <main class="dh-workspace">
            <section class="creator-panel">
                <div class="step-card">
                    <div class="step-card__head">
                        <span>01</span>
                        <strong>形象</strong>
                        <button type="button" @click="activeLibrary = 'avatar'">形象库</button>
                    </div>
                    <div class="asset-summary" :class="{ 'is-empty': !selectedAvatar }" @click="activeLibrary = 'avatar'">
                        <video v-if="selectedAvatarVideo" :src="selectedAvatarVideo" muted playsinline />
                        <img v-else-if="selectedAvatarCover" :src="selectedAvatarCover" alt="" />
                        <div v-else class="asset-summary__placeholder">形象</div>
                        <div>
                            <strong>{{ selectedAvatar?.name || '选择数字人形象' }}</strong>
                            <span>{{ avatarSourceText(selectedAvatar) }}</span>
                        </div>
                    </div>
                </div>

                <div class="step-card">
                    <div class="step-card__head">
                        <span>02</span>
                        <strong>声音</strong>
                        <button type="button" @click="activeLibrary = 'voice'">声音库</button>
                    </div>
                    <div class="voice-summary" :class="{ 'is-empty': !selectedVoice }" @click="activeLibrary = 'voice'">
                        <div class="voice-summary__icon">◎</div>
                        <div>
                            <strong>{{ selectedVoice?.name || '选择声音' }}</strong>
                            <span>{{ voiceSourceText(selectedVoice) }}</span>
                        </div>
                    </div>
                </div>

                <div class="step-card step-card--script">
                    <div class="step-card__head">
                        <span>03</span>
                        <strong>口播文案</strong>
                        <button type="button" @click="openEmotionPanelFromButton">情绪</button>
                        <button type="button" @click="fillExample">示例</button>
                    </div>
                    <div class="script-editor">
                        <textarea
                            ref="scriptTextareaRef"
                            v-model="scriptText"
                            maxlength="500"
                            placeholder="输入数字人口播文案，例如产品介绍、知识讲解、短视频开场..."
                            @input="handleScriptInput"
                            @keydown="handleScriptKeydown"
                            @click="rememberScriptCaret"
                            @keyup="rememberScriptCaret"
                        ></textarea>
                        <div v-if="emotionPanelOpen" class="script-emotion-panel" @mousedown.prevent>
                            <div class="script-emotion-panel__head">
                                <strong>S2-Pro 情绪控制</strong>
                                <span>输入 /笑 /happy 可筛选，选择后插入到文案中</span>
                            </div>
                            <div class="script-emotion-tabs">
                                <button
                                    v-for="category in emotionCategories"
                                    :key="category"
                                    type="button"
                                    :class="{ 'is-active': activeEmotionCategory === category }"
                                    @mousedown.prevent="setEmotionCategory(category)"
                                >
                                    {{ category }}
                                </button>
                            </div>
                            <div class="script-emotion-list">
                                <button
                                    v-for="(item, index) in filteredEmotionOptions"
                                    :key="item.tag"
                                    type="button"
                                    class="script-emotion-item"
                                    :class="{ 'is-active': index === activeEmotionIndex }"
                                    @mousedown.prevent="insertEmotionMarker(item)"
                                >
                                    <span class="script-emotion-item__top">
                                        <strong>{{ item.label }}</strong>
                                        <code>{{ item.tag }}</code>
                                    </span>
                                    <span>{{ item.description }}</span>
                                    <small>适合：{{ item.scene }}</small>
                                    <em>{{ item.example }}</em>
                                </button>
                                <div v-if="!filteredEmotionOptions.length" class="script-emotion-empty">未找到匹配的情绪控制</div>
                            </div>
                        </div>
                        <div class="script-count">{{ scriptText.length }}/500</div>
                    </div>
                </div>

                <div class="step-card">
                    <div class="step-card__head">
                        <span>04</span>
                        <strong>模型</strong>
                    </div>
                    <div class="model-list">
                        <button
                            v-for="item in channels"
                            :key="item.value"
                            :class="{ 'is-active': form.channel === item.value }"
                            type="button"
                            @click="selectChannel(item.value)"
                        >
                            <strong>{{ item.description || item.label }}</strong>
                            <small>{{ modelPriceText(item) }}</small>
                        </button>
                    </div>
                </div>

                <div class="step-card">
                    <div class="step-card__head">
                        <span>05</span>
                        <strong>合成规格</strong>
                    </div>
                    <div class="segmented segmented--muted">
                        <button
                            v-for="item in qualities"
                            :key="item.value"
                            :class="{ 'is-active': form.quality === item.value }"
                            type="button"
                            @click="selectQuality(item.value)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                    <div class="ratio-row">
                        <button
                            v-for="item in ratios"
                            :key="item.value"
                            :class="{ 'is-active': form.ratio === item.value }"
                            type="button"
                            @click="form.ratio = item.value"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                </div>

                <button class="generate-button" type="button" :disabled="submitting || isGenerateLocked" @click="handleGenerateLock">
                    <span>{{ submitting || isGenerateLocked ? '提交中...' : '合成视频' }}</span>
                    <small>约{{ estimatedDuration }}秒 · {{ estimatedCost }} 点</small>
                </button>
            </section>

            <section class="preview-panel">
                <div class="preview-stage">
                    <video v-if="latestPreview?.video_url" :src="latestPreview.video_url" controls autoplay muted />
                    <template v-else>
                        <video v-if="selectedAvatarVideo" :src="selectedAvatarVideo" muted playsinline />
                        <img v-else-if="selectedAvatarCover" :src="selectedAvatarCover" alt="" />
                        <div v-else class="preview-placeholder">选择形象后预览</div>
                    </template>
                    <div class="preview-stage__shade"></div>
                    <div class="preview-stage__meta">
                        <strong>{{ selectedAvatar?.name || latestPreview?.title || '未选择形象' }}</strong>
                        <span>{{ selectedVoice?.name || '未选择声音' }} · {{ form.ratio }} · {{ form.quality }}</span>
                    </div>
                </div>
                <div class="preview-info">
                    <div>
                        <span>当前作品</span>
                        <strong>{{ latestPreview?.title || currentTask?.title || form.title }}</strong>
                    </div>
                    <div>
                        <span>状态</span>
                        <strong>{{ currentStatusText }}</strong>
                    </div>
                </div>
                <div v-if="currentTask?.error" class="error-panel">{{ currentTask.error }}</div>
            </section>

            <aside class="library-panel">
                <div class="library-tabs">
                    <button :class="{ 'is-active': activeLibrary === 'avatar' }" type="button" @click="activeLibrary = 'avatar'">形象库</button>
                    <button :class="{ 'is-active': activeLibrary === 'voice' }" type="button" @click="activeLibrary = 'voice'">声音库</button>
                    <button :class="{ 'is-active': activeLibrary === 'tasks' }" type="button" @click="activeLibrary = 'tasks'">任务</button>
                    <button :class="{ 'is-active': activeLibrary === 'works' }" type="button" @click="activeLibrary = 'works'">作品</button>
                </div>

                <div v-if="activeLibrary === 'avatar'" class="library-body">
                    <div class="library-toolbar">
                        <span>视频形象</span>
                        <button type="button" :disabled="uploadingAvatar" @click="triggerAvatarUpload">
                            {{ uploadingAvatar ? '上传中...' : '上传视频' }}
                        </button>
                    </div>
                    <div class="source-filter">
                        <button
                            v-for="item in sourceFilters"
                            :key="item.value"
                            :class="{ 'is-active': avatarSource === item.value }"
                            type="button"
                            @click="avatarSource = item.value"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                    <div class="asset-grid">
                        <article
                            v-for="item in filteredAvatars"
                            :key="item.id"
                            class="avatar-tile"
                            :class="{ 'is-active': form.avatar_id === item.id }"
                            @click="selectAvatar(item)"
                        >
                            <video v-if="item.media_type === 'video' && item.media_url" :src="item.media_url" muted playsinline />
                            <img v-else :src="item.cover_url || item.media_url" alt="" />
                            <span>{{ item.source === 'official' ? '官方' : '我的' }}</span>
                            <strong>{{ item.name }}</strong>
                            <small>{{ item.media_type === 'video' ? '可合成视频形象' : '不可用于真实合成' }}</small>
                        </article>
                        <div v-if="!filteredAvatars.length" class="empty-state">暂无形象</div>
                    </div>
                </div>

                <div v-else-if="activeLibrary === 'voice'" class="library-body">
                    <div class="library-toolbar">
                        <span>合成音色</span>
                        <button type="button" :disabled="uploadingVoice || recording" @click="triggerVoiceUpload">
                            {{ uploadingVoice ? '创建中...' : '上传音频' }}
                        </button>
                    </div>
                    <div class="voice-actions">
                        <button type="button" :class="{ 'is-recording': recording }" :disabled="uploadingVoice" @click="toggleRecord">
                            {{ recording ? `停止录音 ${recordSeconds}s` : '录制声音' }}
                        </button>
                    </div>
                    <div class="source-filter">
                        <button
                            v-for="item in sourceFilters"
                            :key="item.value"
                            :class="{ 'is-active': voiceSource === item.value }"
                            type="button"
                            @click="voiceSource = item.value"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                    <div class="voice-list">
                        <article
                            v-for="item in filteredVoices"
                            :key="item.id"
                            class="voice-tile"
                            :class="{ 'is-active': form.voice_id === item.id }"
                            @click="selectVoice(item)"
                        >
                            <div class="voice-tile__icon">◎</div>
                            <div>
                                <strong>{{ item.name }}</strong>
                                <span>{{ voiceSourceText(item) }}</span>
                            </div>
                        </article>
                        <div v-if="!filteredVoices.length" class="empty-state">暂无音色</div>
                    </div>
                </div>

                <div v-else-if="activeLibrary === 'tasks'" class="library-body works-body">
                    <div class="library-toolbar">
                        <span>合成任务</span>
                        <button type="button" @click="getData">刷新</button>
                    </div>
                    <article
                        v-for="item in tasks"
                        :key="item.id"
                        class="task-row"
                        :class="{ 'is-active': currentTask?.id === item.id }"
                        @click="selectTask(item)"
                    >
                        <div>
                            <strong>{{ item.title || '数字人合成' }}</strong>
                            <span>{{ statusText(item.status) }} · {{ stageText(item.provider_stage) }} · {{ item.progress || 0 }}%</span>
                        </div>
                        <button v-if="item.status === 'success'" type="button" @click.stop="activeLibrary = 'works'">结果</button>
                    </article>
                    <div v-if="!tasks.length" class="empty-state">暂无任务</div>
                </div>

                <div v-else class="library-body works-body">
                    <div class="library-toolbar">
                        <span>最近作品</span>
                        <button type="button" @click="getData">刷新</button>
                    </div>
                    <div class="source-filter">
                        <button
                            v-for="item in statusFilters"
                            :key="item.value"
                            :class="{ 'is-active': resultStatus === item.value }"
                            type="button"
                            @click="changeResultStatus(item.value)"
                        >
                            {{ item.label }}
                        </button>
                    </div>
                    <article v-for="item in results" :key="item.task_id || item.id" class="work-row">
                        <video v-if="item.video_url" :src="item.video_url" muted />
                        <div v-else class="work-row__empty">{{ statusText(item.status) }}</div>
                        <div>
                            <strong>{{ item.title || '数字人作品' }}</strong>
                            <span>{{ item.ratio }} · {{ statusText(item.status) }} · {{ stageText(item.provider_stage) }}</span>
                        </div>
                        <button type="button" :disabled="!item.video_url" @click="copyVideoLink(item)">链接</button>
                        <button type="button" :disabled="!item.video_url" @click="clipDigitalHumanResult(item)">剪辑</button>
                        <button type="button" @click="reuseResult(item)">复用</button>
                        <button type="button" @click="handleDelete(item)">删除</button>
                    </article>
                    <div v-if="!results.length" class="empty-state">暂无作品</div>
                </div>
            </aside>
        </main>

        <section class="task-strip">
            <div class="task-strip__head">
                <strong>最近任务</strong>
                <span>{{ tasks.length }} 条</span>
            </div>
            <div class="task-list">
                <article v-for="item in tasks.slice(0, 6)" :key="item.id" class="task-card" @click="selectTask(item)">
                    <strong>{{ item.title || '数字人合成' }}</strong>
                    <span>{{ statusText(item.status) }} · {{ item.progress || 0 }}% · {{ item.user_charge_points || 0 }} 点</span>
                </article>
            </div>
        </section>

        <input ref="avatarInputRef" class="hidden-input" type="file" accept="video/*" @change="handleAvatarUpload" />
        <input ref="voiceInputRef" class="hidden-input" type="file" accept="audio/*,.mp3,.wav,.m4a,.aac,.ogg,.flac,.opus" @change="handleVoiceUpload" />
    </div>
</template>

<script lang="ts" setup>
import { uploadFile, uploadVideo } from '@/api/app'
import { isPcLoginRequiredError, usePcLoginGate } from '@/composables/usePcLoginGate'
import {
    deleteAigcDigitalHumanResult,
    estimateAigcDigitalHuman,
    generateAigcDigitalHuman,
    getAigcDigitalHumanAvatars,
    getAigcDigitalHumanConfig,
    getAigcDigitalHumanResults,
    getAigcDigitalHumanTask,
    getAigcDigitalHumanTasks,
    getAigcDigitalHumanVoices,
    saveAigcDigitalHumanAvatar,
    saveAigcDigitalHumanVoice
} from '@/apps/aigc_digital_human/api'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'

type LibraryTab = 'avatar' | 'voice' | 'tasks' | 'works'
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
const uploadingAvatar = ref(false)
const uploadingVoice = ref(false)
const recording = ref(false)
const recordSeconds = ref(0)
const activeLibrary = ref<LibraryTab>('avatar')
const tasks = ref<any[]>([])
const results = ref<any[]>([])
const avatars = ref<any[]>([])
const voices = ref<any[]>([])
const estimateInfo = ref<any>({})
const currentTask = ref<any>(null)
const avatarSource = ref('')
const voiceSource = ref('')
const resultStatus = ref('')
const avatarInputRef = ref<HTMLInputElement | null>(null)
const voiceInputRef = ref<HTMLInputElement | null>(null)
const scriptTextareaRef = ref<HTMLTextAreaElement | null>(null)
const optionConfig = ref<any>({ channels: [], defaults: { channel: 'master', quality: '1k', ratio: '9:16' } })
const scriptText = ref('')
const emotionPanelOpen = ref(false)
const activeEmotionCategory = ref<EmotionCategory>('情绪')
const activeEmotionIndex = ref(0)
const emotionSearchKeyword = ref('')
const scriptCaretPosition = ref(0)
const emotionTriggerRange = ref({ start: 0, end: 0 })
const blobUrls = ref<string[]>([])
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const router = useRouter()
let pollingTimer: ReturnType<typeof window.setInterval> | null = null
let recordTimer: ReturnType<typeof window.setInterval> | null = null
let audioContext: AudioContext | null = null
let recorderNode: ScriptProcessorNode | null = null
let audioSourceNode: MediaStreamAudioSourceNode | null = null
let recordStream: MediaStream | null = null
let audioBuffers: Float32Array[] = []
let audioSampleRate = 44100

const form = reactive({
    avatar_id: 0,
    voice_id: 0,
    title: '数字人口播',
    prompt: '',
    ratio: '9:16',
    quality: '1k',
    channel: 'master'
})
const sourceFilters = [
    { label: '全部', value: '' },
    { label: '官方', value: 'official' },
    { label: '我的', value: 'mine' }
]
const statusFilters = [
    { label: '全部', value: '' },
    { label: '合成中', value: 'running' },
    { label: '已完成', value: 'success' },
    { label: '失败', value: 'failed' }
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

const channels = computed(() =>
    (optionConfig.value.channels || []).map((channel: any) => ({
        label: channel.name || channel.label || channel.description || '数字人视频模型',
        value: channel.value || channel.code,
        description: channel.description || channel.label || '数字人视频模型',
        tenant_unit_price: channel.tenant_unit_price,
        qualities: (channel.qualities || []).map((quality: any) => ({
            label: quality.label || quality.quality_label || String(quality.value || '').toUpperCase(),
            value: quality.value || quality.quality,
            ratios: (quality.ratios || []).map((ratio: any) => ({
                ...ratio,
                label: ratio.label || ratio.ratio || ratio.value,
                value: ratio.value || ratio.ratio
            }))
        }))
    }))
)
const currentChannel = computed(() => channels.value.find((item: any) => item.value === form.channel) || channels.value[0])
const qualities = computed(() => currentChannel.value?.qualities || [])
const currentQuality = computed(() => qualities.value.find((item: any) => item.value === form.quality) || qualities.value[0])
const ratios = computed(() => currentQuality.value?.ratios || [])
const estimatedDuration = computed(() => Math.max(1, Math.ceil((scriptText.value.trim().length || 1) / 4)))
const estimatedCost = computed(() => Number(estimateInfo.value.user_charge_points ?? 0).toFixed(2))
const selectedAvatar = computed(() => avatars.value.find((item) => item.id === form.avatar_id))
const selectedVoice = computed(() => voices.value.find((item) => item.id === form.voice_id))
const selectedAvatarCover = computed(() => selectedAvatar.value?.cover_url || selectedAvatar.value?.media_url || '')
const selectedAvatarVideo = computed(() => selectedAvatar.value?.media_type === 'video' ? selectedAvatar.value?.media_url || '' : '')
const latestPreview = computed(() => results.value.find((item) => item.video_url) || results.value[0])
const filteredAvatars = computed(() => avatars.value.filter((item) => !avatarSource.value || item.source === avatarSource.value))
const filteredVoices = computed(() => voices.value.filter((item) => !voiceSource.value || item.source === voiceSource.value))
const hasRunningTask = computed(() => tasks.value.some((item) => ['pending', 'running'].includes(item.status)))
const filteredEmotionOptions = computed(() => {
    const keyword = emotionSearchKeyword.value.trim().toLowerCase()
    return emotionOptions.filter((item) => {
        const categoryMatched = item.category === activeEmotionCategory.value
        if (!keyword) return categoryMatched
        const haystack = [item.label, item.tag, item.description, item.scene, item.example, ...item.keywords].join(' ').toLowerCase()
        return categoryMatched && haystack.includes(keyword)
    })
})
const currentStatusText = computed(() => {
    const source = currentTask.value || latestPreview.value
    if (!source) return '待创作'
    const progress = source.progress !== undefined ? ` · ${source.progress}%` : ''
    return `${statusText(source.status)} · ${stageText(source.provider_stage)}${progress}`
})

const avatarSourceText = (item?: any) => {
    if (!item) return '当前用户可用资产'
    const source = item.source === 'official' ? '官方形象' : '我的形象'
    return item.media_type === 'video' ? `${source} · 视频形象` : `${source} · 不可用于真实合成`
}

const voiceSourceText = (item?: any) => {
    if (!item) return '当前用户可用资产'
    const source = item.source === 'official' ? '官方声音' : '我的声音'
    return item.provider_asset_id ? `${source} · 可合成` : `${source} · 未完成克隆`
}

const modelPriceText = (item: any) => {
    const price = item?.tenant_unit_price
    return price !== undefined && price !== null && price !== '' ? `${price}点/秒` : '按秒计费'
}

const statusText = (status?: string) => {
    const map: Record<string, string> = {
        pending: '排队中',
        running: '合成中',
        success: '已完成',
        failed: '失败',
        canceled: '已取消'
    }
    return map[status || ''] || status || '待提交'
}

const stageText = (stage?: string) => {
    const map: Record<string, string> = {
        created: '准备音频',
        tts_submitted: '音频已提交',
        tts_running: '音频合成中',
        tts_failed: '音频失败',
        lipsync_submitted: '视频已提交',
        lipsync_running: '视频合成中',
        lipsync_failed: '视频失败',
        storing: '保存作品中',
        success: '合成完成',
        failed: '合成失败'
    }
    return map[stage || ''] || stage || '待处理'
}

const pickUploadUri = (res: any) => res?.uri || res?.url || res?.path || ''

const rememberBlobUrl = (url: string) => {
    blobUrls.value.push(url)
    return url
}

const syncDefaults = () => {
    if (channels.value.length && !channels.value.some((item: any) => item.value === form.channel)) form.channel = channels.value[0].value
    if (qualities.value.length && !qualities.value.some((item: any) => item.value === form.quality)) form.quality = qualities.value[0].value
    if (ratios.value.length && !ratios.value.some((item: any) => item.value === form.ratio)) form.ratio = ratios.value[0].value
    if (!form.avatar_id && avatars.value.length) {
        form.avatar_id = (avatars.value.find((item) => item.media_type === 'video') || avatars.value[0]).id
    }
    if (!form.voice_id && voices.value.length) {
        form.voice_id = (voices.value.find((item) => item.provider_asset_id) || voices.value[0]).id
    }
}

const getData = async () => {
    if (!userStore.isLogin) {
        tasks.value = []
        results.value = []
        avatars.value = []
        voices.value = []
        estimateInfo.value = {}
        currentTask.value = null
        stopPolling()
        return
    }
    try {
        const [config, taskRows, resultRows, avatarRows, voiceRows] = await Promise.all([
            getAigcDigitalHumanConfig(),
            getAigcDigitalHumanTasks(),
            getAigcDigitalHumanResults(resultStatus.value ? { status: resultStatus.value } : undefined),
            getAigcDigitalHumanAvatars(),
            getAigcDigitalHumanVoices()
        ])
        optionConfig.value = config?.option_config || optionConfig.value
        const defaults = optionConfig.value.defaults || {}
        form.channel = defaults.channel || form.channel
        form.quality = defaults.quality || form.quality
        form.ratio = defaults.ratio || form.ratio
        tasks.value = taskRows || []
        results.value = resultRows || []
        avatars.value = avatarRows || []
        voices.value = voiceRows || []
        syncDefaults()
        currentTask.value = tasks.value.find((item) => item.id === currentTask.value?.id) || tasks.value[0] || null
        syncPolling()
        await refreshEstimate()
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}

const changeResultStatus = async (status: string) => {
    if (!ensurePcLogin()) return
    resultStatus.value = status
    results.value = await getAigcDigitalHumanResults(status ? { status } : undefined)
}

const selectChannel = (value: string) => {
    form.channel = value
    form.quality = currentChannel.value?.qualities?.[0]?.value || form.quality
    form.ratio = currentQuality.value?.ratios?.[0]?.value || form.ratio
    refreshEstimate()
}

const selectQuality = (value: string) => {
    form.quality = value
    form.ratio = currentQuality.value?.ratios?.[0]?.value || form.ratio
    refreshEstimate()
}

const refreshEstimate = async () => {
    if (!userStore.isLogin) {
        estimateInfo.value = {}
        return
    }
    try {
        estimateInfo.value = await estimateAigcDigitalHuman({
            channel: form.channel,
            quality: form.quality,
            ratio: form.ratio,
            duration: estimatedDuration.value
        })
    } catch (e) {
        estimateInfo.value = {}
    }
}

const selectAvatar = (item: any) => {
    form.avatar_id = item.id
    activeLibrary.value = 'voice'
}

const selectVoice = (item: any) => {
    form.voice_id = item.id
}

const fillExample = () => {
    scriptText.value = '大家好，欢迎来到我们的数字人直播间。今天用一分钟带你了解这款产品的核心亮点，以及它适合移动端传播的短视频表达。'
}

const rememberScriptCaret = () => {
    const textarea = scriptTextareaRef.value
    if (!textarea) return
    scriptCaretPosition.value = textarea.selectionStart ?? scriptText.value.length
}

const syncEmotionTrigger = () => {
    const textarea = scriptTextareaRef.value
    const caret = textarea?.selectionStart ?? scriptCaretPosition.value
    scriptCaretPosition.value = caret
    const beforeCaret = scriptText.value.slice(0, caret)
    const match = beforeCaret.match(/\/([^\s/\[\]]*)$/)
    if (!match) {
        emotionPanelOpen.value = false
        emotionSearchKeyword.value = ''
        return
    }
    emotionSearchKeyword.value = match[1] || ''
    emotionTriggerRange.value = { start: caret - match[0].length, end: caret }
    emotionPanelOpen.value = true
    activeEmotionIndex.value = 0
}

const handleScriptInput = () => {
    syncEmotionTrigger()
}

const setEmotionCategory = (category: EmotionCategory) => {
    activeEmotionCategory.value = category
    activeEmotionIndex.value = 0
}

const closeEmotionPanel = () => {
    emotionPanelOpen.value = false
    emotionSearchKeyword.value = ''
}

const openEmotionPanelFromButton = async () => {
    const textarea = scriptTextareaRef.value
    if (textarea) {
        textarea.focus()
        scriptCaretPosition.value = textarea.selectionStart ?? scriptText.value.length
    }
    const caret = scriptCaretPosition.value
    emotionTriggerRange.value = { start: caret, end: caret }
    emotionSearchKeyword.value = ''
    activeEmotionIndex.value = 0
    emotionPanelOpen.value = true
    await nextTick()
    scriptTextareaRef.value?.focus()
}

const insertEmotionMarker = async (item: EmotionOption) => {
    const marker = `${item.tag} `
    const range = emotionPanelOpen.value ? emotionTriggerRange.value : { start: scriptCaretPosition.value, end: scriptCaretPosition.value }
    const nextValue = `${scriptText.value.slice(0, range.start)}${marker}${scriptText.value.slice(range.end)}`
    if (Array.from(nextValue).length > 500) {
        feedback.msgError('文案长度已达上限')
        return
    }
    scriptText.value = nextValue
    closeEmotionPanel()
    await nextTick()
    const caret = range.start + marker.length
    const textarea = scriptTextareaRef.value
    if (textarea) {
        textarea.focus()
        textarea.setSelectionRange(caret, caret)
    }
    scriptCaretPosition.value = caret
}

const handleScriptKeydown = (event: KeyboardEvent) => {
    if (!emotionPanelOpen.value) {
        if (event.key === '/') {
            requestAnimationFrame(syncEmotionTrigger)
        }
        return
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        activeEmotionIndex.value = filteredEmotionOptions.value.length
            ? (activeEmotionIndex.value + 1) % filteredEmotionOptions.value.length
            : 0
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        activeEmotionIndex.value = filteredEmotionOptions.value.length
            ? (activeEmotionIndex.value - 1 + filteredEmotionOptions.value.length) % filteredEmotionOptions.value.length
            : 0
    } else if (event.key === 'Enter') {
        const item = filteredEmotionOptions.value[activeEmotionIndex.value]
        if (!item) return
        event.preventDefault()
        void insertEmotionMarker(item)
    } else if (event.key === 'Escape') {
        event.preventDefault()
        closeEmotionPanel()
    }
}

const triggerAvatarUpload = () => {
    if (!ensurePcLogin()) return
    avatarInputRef.value?.click()
}

const triggerVoiceUpload = () => {
    if (!ensurePcLogin()) return
    voiceInputRef.value?.click()
}

const handleAvatarUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    const file = target.files?.[0]
    if (!file || uploadingAvatar.value) return
    uploadingAvatar.value = true
    const objectUrl = rememberBlobUrl(URL.createObjectURL(file))
    try {
        const res: any = await uploadVideo({ file })
        const uri = pickUploadUri(res)
        if (!uri) throw new Error('视频上传失败')
        const row = await saveAigcDigitalHumanAvatar({
            name: `我的数字人形象 ${avatars.value.filter((item) => item.source === 'mine').length + 1}`,
            cover_uri: uri,
            media_uri: uri,
            media_type: 'video'
        })
        row.cover_url = objectUrl
        row.media_url = objectUrl
        avatars.value.unshift(row)
        form.avatar_id = row.id
        activeLibrary.value = 'voice'
        feedback.msgSuccess('形象已创建')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || error || '形象上传失败')
    } finally {
        uploadingAvatar.value = false
        target.value = ''
    }
}

const createVoiceByFile = async (file: File) => {
    if (uploadingVoice.value) return
    uploadingVoice.value = true
    try {
        const res: any = await uploadFile({ file })
        const audioUri = pickUploadUri(res)
        if (!audioUri) throw new Error('音频上传失败')
        const row = await saveAigcDigitalHumanVoice({
            name: `我的克隆音色 ${voices.value.filter((item) => item.source === 'mine').length + 1}`,
            audio_uri: audioUri,
            duration: recordSeconds.value,
            gender: 'female',
            age_group: 'young'
        })
        voices.value.unshift(row)
        form.voice_id = row.id
        activeLibrary.value = 'voice'
        feedback.msgSuccess('音色已创建')
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || error || '音色创建失败')
    } finally {
        uploadingVoice.value = false
        recordSeconds.value = 0
    }
}

const handleVoiceUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    const file = target.files?.[0]
    if (!file || uploadingVoice.value) return
    await createVoiceByFile(file)
    target.value = ''
}

const stopRecordTimer = () => {
    if (!recordTimer) return
    clearInterval(recordTimer)
    recordTimer = null
}

const cleanupRecorder = async () => {
    recorderNode?.disconnect()
    audioSourceNode?.disconnect()
    recorderNode = null
    audioSourceNode = null
    recordStream?.getTracks().forEach((track) => track.stop())
    recordStream = null
    if (audioContext) {
        await audioContext.close()
        audioContext = null
    }
}

const mergeAudioBuffers = (buffers: Float32Array[]) => {
    const length = buffers.reduce((total, buffer) => total + buffer.length, 0)
    const result = new Float32Array(length)
    let offset = 0
    buffers.forEach((buffer) => {
        result.set(buffer, offset)
        offset += buffer.length
    })
    return result
}

const encodeWav = (samples: Float32Array, sampleRate: number) => {
    const buffer = new ArrayBuffer(44 + samples.length * 2)
    const view = new DataView(buffer)
    const writeString = (offset: number, value: string) => {
        for (let i = 0; i < value.length; i += 1) view.setUint8(offset + i, value.charCodeAt(i))
    }
    writeString(0, 'RIFF')
    view.setUint32(4, 36 + samples.length * 2, true)
    writeString(8, 'WAVE')
    writeString(12, 'fmt ')
    view.setUint32(16, 16, true)
    view.setUint16(20, 1, true)
    view.setUint16(22, 1, true)
    view.setUint32(24, sampleRate, true)
    view.setUint32(28, sampleRate * 2, true)
    view.setUint16(32, 2, true)
    view.setUint16(34, 16, true)
    writeString(36, 'data')
    view.setUint32(40, samples.length * 2, true)
    let offset = 44
    for (let i = 0; i < samples.length; i += 1) {
        const sample = Math.max(-1, Math.min(1, samples[i]))
        view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true)
        offset += 2
    }
    return new Blob([view], { type: 'audio/wav' })
}

const toggleRecord = async () => {
    if (!process.client) return
    if (!ensurePcLogin()) return
    if (recording.value) {
        recording.value = false
        stopRecordTimer()
        const wavBlob = encodeWav(mergeAudioBuffers(audioBuffers), audioSampleRate)
        await cleanupRecorder()
        await createVoiceByFile(new File([wavBlob], `record-${Date.now()}.wav`, { type: 'audio/wav' }))
        return
    }
    const AudioContextCtor = window.AudioContext || (window as any).webkitAudioContext
    if (!navigator.mediaDevices?.getUserMedia || !AudioContextCtor) {
        feedback.msgError('当前浏览器不支持录音')
        return
    }
    try {
        audioBuffers = []
        recordSeconds.value = 0
        recordStream = await navigator.mediaDevices.getUserMedia({ audio: true })
        audioContext = new AudioContextCtor()
        audioSampleRate = audioContext.sampleRate
        audioSourceNode = audioContext.createMediaStreamSource(recordStream)
        recorderNode = audioContext.createScriptProcessor(4096, 1, 1)
        recorderNode.onaudioprocess = (event) => {
            if (!recording.value) return
            audioBuffers.push(new Float32Array(event.inputBuffer.getChannelData(0)))
        }
        audioSourceNode.connect(recorderNode)
        recorderNode.connect(audioContext.destination)
        recording.value = true
        recordTimer = window.setInterval(() => {
            recordSeconds.value += 1
        }, 1000)
    } catch (error: any) {
        recording.value = false
        stopRecordTimer()
        await cleanupRecorder()
        feedback.msgError(error?.message || '录音失败')
    }
}

const handleGenerate = async () => {
    if (submitting.value) return
    if (!ensurePcLogin()) return
    if (!form.avatar_id) return feedback.msgError('请选择形象')
    if (!form.voice_id) return feedback.msgError('请选择声音')
    if (selectedAvatar.value?.media_type && selectedAvatar.value.media_type !== 'video') return feedback.msgError('请选择可合成的视频形象')
    if (!selectedVoice.value?.provider_asset_id) return feedback.msgError('当前音色未完成克隆，无法合成')
    if (!scriptText.value.trim()) return feedback.msgError('请输入口播文案')
    submitting.value = true
    try {
        const task = await generateAigcDigitalHuman({ ...form, script_text: scriptText.value.trim(), duration: estimatedDuration.value })
        currentTask.value = task?.task_id ? { ...task, id: task.task_id, title: form.title, progress: 5 } : task
        scriptText.value = ''
        activeLibrary.value = 'tasks'
        await getData()
        startPolling()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '提交合成任务失败')
    } finally {
        submitting.value = false
    }
}
const { lockFn: handleGenerateLock, isLock: isGenerateLocked } = useLockFn(handleGenerate)

const selectTask = async (item: any) => {
    if (!ensurePcLogin()) return
    currentTask.value = item
    if (!item?.id) return
    try {
        currentTask.value = await getAigcDigitalHumanTask({ id: item.id })
    } catch {
        currentTask.value = item
    }
}

const reuseResult = (item: any) => {
    scriptText.value = item.script_text || ''
    form.avatar_id = item.avatar_id || form.avatar_id
    form.voice_id = item.voice_id || form.voice_id
    form.channel = item.channel || form.channel
    form.quality = item.quality || form.quality
    form.ratio = item.ratio || form.ratio
    activeLibrary.value = 'avatar'
}

const handleDelete = async (item: any) => {
    if (!ensurePcLogin()) return
    try {
        await deleteAigcDigitalHumanResult({ task_id: item.task_id || item.id })
        feedback.msgSuccess('删除成功')
        await getData()
    } catch (error: any) {
        if (isPcLoginRequiredError(error)) return
        feedback.msgError(error?.msg || error?.message || '删除失败')
    }
}

const copyVideoLink = async (item: any) => {
    if (!ensurePcLogin()) return
    if (!item.video_url) return feedback.msgError('暂无视频链接')
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(item.video_url)
    } else {
        const textarea = document.createElement('textarea')
        textarea.value = item.video_url
        document.body.appendChild(textarea)
        textarea.select()
        document.execCommand('copy')
        document.body.removeChild(textarea)
    }
    feedback.msgSuccess('视频链接已复制')
}

const clipDigitalHumanResult = (item: any) => {
    if (!item.video_url) return feedback.msgError('暂无可剪辑视频')
    router.push({
        path: '/ai/smart_clip',
        query: {
            source_app: 'aigc_digital_human',
            source_result_id: item.result_id || item.id || item.task_id || '',
            video_url: item.video_url,
            cover_url: item.cover_url || '',
            duration: item.duration || '',
            type: 'realman_broadcast',
        },
    })
}

const refreshRunningTasks = async () => {
    const rows = await getAigcDigitalHumanTasks()
    tasks.value = rows || []
    currentTask.value = tasks.value.find((item) => item.id === currentTask.value?.id) || currentTask.value
    if (!hasRunningTask.value) {
        stopPolling()
        results.value = await getAigcDigitalHumanResults(resultStatus.value ? { status: resultStatus.value } : undefined)
        if (tasks.value.some((item) => item.status === 'success')) activeLibrary.value = 'works'
    }
}

const startPolling = () => {
    if (!process.client || pollingTimer) return
    pollingTimer = window.setInterval(refreshRunningTasks, 3000)
}

const stopPolling = () => {
    if (!pollingTimer) return
    clearInterval(pollingTimer)
    pollingTimer = null
}

const syncPolling = () => {
    if (hasRunningTask.value) startPolling()
    else stopPolling()
}

if (process.client) getData()
watch(() => userStore.isLogin, getData)
watch(() => [form.channel, form.quality, form.ratio, scriptText.value], refreshEstimate)
watch(filteredEmotionOptions, (options) => {
    if (!options.length) {
        activeEmotionIndex.value = 0
        return
    }
    if (activeEmotionIndex.value >= options.length) activeEmotionIndex.value = options.length - 1
})
onBeforeUnmount(() => {
    stopPolling()
    stopRecordTimer()
    cleanupRecorder()
    blobUrls.value.forEach((url) => URL.revokeObjectURL(url))
})
</script>

<style scoped>
.dh-page {
    min-height: calc(100vh - 80px);
    padding: 28px;
    background: #050505;
    color: #fff;
}

.dh-header,
.dh-workspace,
.task-strip {
    max-width: 1480px;
    margin: 0 auto;
}

.dh-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    margin-bottom: 22px;
}

.dh-kicker {
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
    font-weight: 700;
}

.dh-header h1 {
    margin: 8px 0 6px;
    font-size: 34px;
    line-height: 1;
}

.dh-header p {
    margin: 0;
    color: rgba(255, 255, 255, 0.58);
}

.dh-header__actions,
.library-tabs,
.segmented,
.ratio-row,
.source-filter,
.voice-actions {
    display: flex;
    gap: 8px;
}

button {
    border: 0;
    color: inherit;
    cursor: pointer;
}

button:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}

.dh-header__actions button,
.library-toolbar button,
.step-card__head button,
.work-row button,
.task-row button,
.voice-actions button {
    height: 34px;
    padding: 0 14px;
    border-radius: 6px;
    background: #222;
    color: rgba(255, 255, 255, 0.82);
}

.dh-workspace {
    display: grid;
    grid-template-columns: minmax(300px, 380px) minmax(420px, 1fr) minmax(320px, 440px);
    gap: 18px;
    align-items: stretch;
}

.creator-panel,
.preview-panel,
.library-panel,
.task-strip {
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    background: #101012;
}

.creator-panel {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 14px;
}

.step-card {
    padding: 14px;
    border-radius: 8px;
    background: #171719;
}

.step-card__head,
.library-toolbar,
.preview-info,
.task-strip__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.step-card__head span {
    color: rgba(255, 255, 255, 0.36);
    font-size: 12px;
    font-weight: 700;
}

.asset-summary,
.voice-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 12px;
    padding: 10px;
    border-radius: 8px;
    background: #222;
}

.asset-summary img,
.asset-summary video,
.asset-summary__placeholder {
    width: 64px;
    height: 82px;
    border-radius: 6px;
    object-fit: cover;
    background: #2a2b2c;
}

.asset-summary__placeholder,
.preview-placeholder,
.empty-state {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.42);
}

.empty-state {
    min-height: 88px;
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: 8px;
}

.asset-summary strong,
.voice-summary strong,
.voice-tile strong,
.work-row strong,
.task-card strong,
.task-row strong {
    display: block;
    font-size: 14px;
}

.asset-summary span,
.voice-summary span,
.voice-tile span,
.work-row span,
.task-card span,
.task-row span,
.preview-info span {
    display: block;
    margin-top: 4px;
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
}

.voice-summary__icon,
.voice-tile__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex: none;
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #313233;
}

.script-editor {
    position: relative;
}

.step-card textarea {
    width: 100%;
    min-height: 132px;
    margin-top: 12px;
    padding: 12px;
    resize: none;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    outline: none;
    background: #0b0b0c;
    color: #fff;
    line-height: 1.5;
}

.script-count {
    margin-top: 8px;
    color: rgba(255, 255, 255, 0.42);
    text-align: right;
    font-size: 12px;
}

.script-emotion-panel {
    position: absolute;
    left: 0;
    right: 0;
    top: calc(100% + 8px);
    z-index: 20;
    display: flex;
    flex-direction: column;
    max-height: 390px;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    background: rgba(18, 18, 18, 0.98);
    box-shadow: 0 18px 48px rgba(0, 0, 0, 0.42);
    backdrop-filter: blur(14px);
}

.script-emotion-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.script-emotion-panel__head strong {
    flex: none;
    color: #fff;
    font-size: 14px;
}

.script-emotion-panel__head span {
    color: rgba(255, 255, 255, 0.52);
    font-size: 12px;
    line-height: 1.5;
    text-align: right;
}

.script-emotion-tabs {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 6px;
    margin-top: 10px;
}

.script-emotion-tabs button {
    height: 30px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 6px;
    background: rgba(255, 255, 255, 0.05);
    color: rgba(255, 255, 255, 0.68);
    font-size: 12px;
}

.script-emotion-tabs button.is-active {
    border-color: rgba(255, 255, 255, 0.8);
    background: #fff;
    color: #050505;
}

.script-emotion-list {
    display: grid;
    gap: 8px;
    margin-top: 10px;
    overflow-y: auto;
    padding-right: 4px;
}

.script-emotion-item {
    display: grid;
    gap: 5px;
    width: 100%;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, 0.07);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.04);
    color: rgba(255, 255, 255, 0.82);
    text-align: left;
}

.script-emotion-item.is-active,
.script-emotion-item:hover {
    border-color: rgba(255, 255, 255, 0.2);
    background: rgba(255, 255, 255, 0.1);
}

.script-emotion-item__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}

.script-emotion-item strong {
    color: #fff;
    font-size: 13px;
}

.script-emotion-item code {
    flex: none;
    max-width: 180px;
    overflow: hidden;
    color: #fff;
    font-size: 12px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.script-emotion-item span,
.script-emotion-item small,
.script-emotion-item em {
    color: rgba(255, 255, 255, 0.56);
    font-size: 12px;
    font-style: normal;
    line-height: 1.45;
}

.script-emotion-item em {
    color: rgba(255, 255, 255, 0.78);
}

.script-emotion-empty {
    padding: 18px 0;
    color: rgba(255, 255, 255, 0.48);
    text-align: center;
    font-size: 13px;
}

.segmented,
.ratio-row,
.model-list,
.source-filter,
.voice-actions {
    margin-top: 12px;
}

.source-filter button,
.voice-actions button {
    flex: 1;
    height: 34px;
    border-radius: 6px;
    background: #1d1d1f;
    color: rgba(255, 255, 255, 0.64);
}

.source-filter button.is-active,
.voice-actions button.is-recording {
    background: #fff;
    color: #050505;
}

.model-list {
    display: grid;
    gap: 8px;
}

.model-list button {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-height: 58px;
    padding: 10px 12px;
    border-radius: 6px;
    background: #222;
    color: rgba(255, 255, 255, 0.76);
    text-align: left;
}

.model-list button small {
    color: rgba(255, 255, 255, 0.44);
}

.segmented button,
.ratio-row button {
    flex: 1;
    height: 36px;
    border-radius: 6px;
    background: #222;
    color: rgba(255, 255, 255, 0.64);
}

.segmented button.is-active,
.ratio-row button.is-active,
.model-list button.is-active,
.library-tabs button.is-active {
    background: #fff;
    color: #050505;
}

.model-list button.is-active small {
    color: rgba(0, 0, 0, 0.52);
}

.segmented--muted button {
    background: #1d1d1f;
}

.generate-button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 56px;
    margin-top: auto;
    padding: 0 18px;
    border-radius: 8px;
    background: #fff;
    color: #050505;
    font-weight: 700;
}

.generate-button small {
    color: rgba(0, 0, 0, 0.52);
}

.preview-panel {
    padding: 14px;
}

.preview-stage {
    position: relative;
    min-height: 620px;
    overflow: hidden;
    border-radius: 8px;
    background: radial-gradient(circle at top, #313233, #06070a 62%);
}

.preview-stage img,
.preview-stage video {
    width: 100%;
    height: 100%;
    min-height: 620px;
    object-fit: cover;
}

.preview-stage__shade {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, transparent 50%, rgba(0, 0, 0, 0.82));
    pointer-events: none;
}

.preview-stage__meta {
    position: absolute;
    left: 18px;
    right: 18px;
    bottom: 18px;
    pointer-events: none;
}

.preview-stage__meta strong,
.preview-info strong {
    display: block;
    font-size: 18px;
}

.preview-stage__meta span {
    display: block;
    margin-top: 6px;
    color: rgba(255, 255, 255, 0.64);
}

.preview-info,
.error-panel {
    margin-top: 12px;
    padding: 14px;
    border-radius: 8px;
    background: #171719;
}

.error-panel {
    color: #ff8b96;
    line-height: 1.5;
}

.library-panel {
    display: flex;
    flex-direction: column;
    min-height: 720px;
    overflow: hidden;
}

.library-tabs {
    padding: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.library-tabs button {
    flex: 1;
    height: 38px;
    border-radius: 6px;
    background: #222;
    color: rgba(255, 255, 255, 0.68);
}

.library-body {
    flex: 1;
    overflow: auto;
    padding: 14px;
}

.asset-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
}

.avatar-tile,
.voice-tile,
.work-row,
.task-card,
.task-row {
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 8px;
    background: #171719;
}

.avatar-tile {
    position: relative;
    overflow: hidden;
    padding: 8px;
}

.avatar-tile.is-active,
.voice-tile.is-active,
.task-row.is-active {
    border-color: rgba(255, 255, 255, 0.82);
}

.avatar-tile img,
.avatar-tile video {
    width: 100%;
    aspect-ratio: 3 / 4;
    border-radius: 6px;
    object-fit: cover;
    background: #222;
}

.avatar-tile span {
    position: absolute;
    top: 14px;
    left: 14px;
    padding: 3px 8px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.58);
    font-size: 12px;
}

.avatar-tile strong,
.avatar-tile small {
    display: block;
    margin-top: 8px;
    font-size: 13px;
}

.avatar-tile small {
    color: rgba(255, 255, 255, 0.44);
    font-size: 12px;
}

.voice-list,
.works-body {
    display: grid;
    gap: 10px;
    align-content: start;
    margin-top: 12px;
}

.voice-tile,
.task-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
}

.task-row {
    justify-content: space-between;
}

.work-row {
    display: grid;
    grid-template-columns: 72px minmax(0, 1fr) auto auto auto;
    gap: 10px;
    align-items: center;
    padding: 10px;
}

.work-row video,
.work-row__empty {
    width: 72px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    background: #222;
}

.work-row__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(255, 255, 255, 0.5);
    font-size: 12px;
}

.task-strip {
    margin-top: 18px;
    padding: 14px;
}

.task-list {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 10px;
    margin-top: 12px;
}

.task-card {
    padding: 12px;
}

.hidden-input {
    display: none;
}

@media (max-width: 1280px) {
    .dh-workspace {
        grid-template-columns: 360px 1fr;
    }

    .library-panel {
        grid-column: 1 / -1;
        min-height: 0;
    }

    .task-list {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 980px) {
    .dh-page {
        padding: 18px;
    }

    .dh-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .dh-header__actions,
    .library-tabs,
    .segmented,
    .ratio-row,
    .source-filter,
    .voice-actions {
        flex-wrap: wrap;
    }

    .dh-workspace {
        grid-template-columns: 1fr;
    }

    .creator-panel,
    .preview-panel,
    .library-panel {
        min-width: 0;
    }

    .preview-stage {
        min-height: clamp(360px, 62vh, 620px);
    }

    .preview-stage img,
    .preview-stage video {
        min-height: clamp(360px, 62vh, 620px);
    }

    .library-panel {
        min-height: 0;
    }

    .asset-grid {
        grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    }

    .work-row {
        grid-template-columns: 72px minmax(0, 1fr);
    }

    .work-row button,
    .work-row > span,
    .work-row > small {
        grid-column: 2;
        justify-self: start;
    }

    .task-list {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    }
}
</style>
