<template>
    <div class="llm-page">
        <aside class="session-panel">
            <div class="session-head">
                <div>
                    <p>AIGC CHAT</p>
                    <h1>AIGC对话</h1>
                </div>
                <button type="button" @click="startNewSession">新建</button>
            </div>
            <div class="session-list">
                <button
                    v-for="item in sessions"
                    :key="item.id"
                    :class="['session-item', { active: currentSessionId === item.id }]"
                    type="button"
                    @click="selectSession(item.id)"
                >
                    <strong>{{ item.title || '新对话' }}</strong>
                    <span>{{ item.last_message || '暂无消息' }}</span>
                </button>
            </div>
        </aside>

        <main class="chat-panel">
            <header class="chat-head">
                <div>
                    <h2>{{ currentSession?.title || '新对话' }}</h2>
                    <p>{{ selectedModelName }} · {{ selectedModelPrice }}</p>
                </div>
                <div class="chat-actions">
                    <select v-model="selectedModelCode" :disabled="streaming">
                        <option v-for="model in models" :key="model.code" :value="model.code">{{ model.name }}</option>
                    </select>
                    <button type="button" :disabled="!currentSessionId" @click="renameCurrentSession">重命名</button>
                    <button type="button" :disabled="!currentSessionId" @click="deleteCurrentSession">删除</button>
                </div>
            </header>

            <section ref="messageWrapRef" class="message-wrap">
                <div v-if="!messages.length" class="empty-chat">
                    <strong>开始一轮大模型对话</strong>
                    <span>支持多会话保存、多轮上下文和 SSE 流式回复。</span>
                </div>
                <article v-for="message in messages" :key="message.local_id || message.id" :class="['message', message.role]">
                    <div class="avatar">{{ message.role === 'user' ? '我' : 'AI' }}</div>
                    <div class="message-body">
                        <div class="bubble" :class="{ empty: !message.content }" v-html="renderContent(message.content || '正在生成...')"></div>
                        <div v-if="message.role === 'assistant' && message.billing_tip" class="billing-tip">{{ message.billing_tip }}</div>
                    </div>
                </article>
                <div ref="messageBottomRef" class="message-bottom" aria-hidden="true"></div>
            </section>

            <footer class="composer">
                <textarea
                    v-model="input"
                    :disabled="streaming"
                    placeholder="输入你的问题，Enter 发送，Shift+Enter 换行"
                    @keydown.enter.exact.prevent="sendMessage()"
                />
                <div class="composer-bar">
                    <button type="button" :disabled="streaming || !canRegenerate" @click="regenerateLast">重答上一轮</button>
                    <button v-if="streaming" type="button" class="danger" @click="stopGenerating">停止生成</button>
                    <button v-else type="button" class="primary" :disabled="!input.trim()" @click="sendMessage()">发送</button>
                </div>
            </footer>
        </main>
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import {
    deleteAigcLlmSession,
    getAigcLlmConfig,
    getAigcLlmMessages,
    getAigcLlmSessions,
    renameAigcLlmSession,
    stopAigcLlmChat,
    streamAigcLlmChat
} from '@/apps/aigc_llm/api'
import feedback from '@/utils/feedback'

definePageMeta({ layout: 'blank' })

type ChatMessage = {
    id?: number
    local_id?: string
    role: 'user' | 'assistant'
    content: string
    status?: string
    parent_user_message_id?: number
    billing_tip?: string
}

const sessions = ref<any[]>([])
const messages = ref<ChatMessage[]>([])
const models = ref<any[]>([])
const currentSessionId = ref(0)
const selectedModelCode = ref('')
const input = ref('')
const streaming = ref(false)
const messageWrapRef = ref<HTMLElement | null>(null)
const messageBottomRef = ref<HTMLElement | null>(null)
let scrollFrame = 0

const currentSession = computed(() => sessions.value.find((item) => Number(item.id) === currentSessionId.value))
const selectedModel = computed(() => models.value.find((item) => item.code === selectedModelCode.value) || {})
const selectedModelName = computed(() => selectedModel.value?.name || selectedModelCode.value || '默认模型')
const selectedModelPrice = computed(() => {
    const input = Number(selectedModel.value?.tenant_input_unit_price || selectedModel.value?.tenant_unit_price || 0)
    const output = Number(selectedModel.value?.tenant_output_unit_price || selectedModel.value?.tenant_unit_price || 0)
    return `输入 ${formatNumber(input)} 点/百万Token · 输出 ${formatNumber(output)} 点/百万Token`
})
const canRegenerate = computed(() => messages.value.some((item) => item.role === 'user') && !streaming.value)

const loadConfig = async () => {
    const config = await getAigcLlmConfig()
    models.value = config.option_config?.models || []
    selectedModelCode.value = config.option_config?.defaults?.model || models.value[0]?.code || config.model || ''
}

const loadSessions = async () => {
    sessions.value = await getAigcLlmSessions()
    if (!currentSessionId.value && sessions.value[0]?.id) {
        await selectSession(Number(sessions.value[0].id))
    }
}

const selectSession = async (id: number) => {
    currentSessionId.value = Number(id)
    const rows = await getAigcLlmMessages({ session_id: id })
    messages.value = rows.map((item: any) => ({ ...item, role: item.role, billing_tip: buildBillingTip(item.token_usage_json) }))
    const session = sessions.value.find((item) => Number(item.id) === Number(id))
    if (session?.model_code) {
        selectedModelCode.value = session.model_code
    }
    scrollToBottom()
}

const startNewSession = () => {
    currentSessionId.value = 0
    messages.value = []
    input.value = ''
}

const appendMessage = (message: ChatMessage) => {
    messages.value.push(message)
    scrollToBottom()
}

const scrollToBottom = () => {
    nextTick(() => {
        if (!process.client) {
            return
        }
        if (scrollFrame) {
            window.cancelAnimationFrame(scrollFrame)
        }
        scrollFrame = window.requestAnimationFrame(() => {
            const wrap = messageWrapRef.value
            const bottom = messageBottomRef.value
            if (!wrap || !bottom) {
                scrollFrame = 0
                return
            }
            bottom.scrollIntoView({ block: 'end', behavior: 'auto' })
            wrap.scrollTo({ top: wrap.scrollHeight, behavior: 'auto' })
            scrollFrame = 0
        })
    })
}

const sendMessage = async (regenerateMessageId = 0) => {
    const regenerateSource = regenerateMessageId ? messages.value.find((item) => Number(item.id) === regenerateMessageId) : null
    const content = regenerateMessageId ? (regenerateSource?.content || '').trim() : input.value.trim()
    if (!content && !regenerateMessageId) return
    streaming.value = true
    const assistantLocalId = `assistant-${Date.now()}`
    if (!regenerateMessageId) {
        appendMessage({ local_id: `user-${Date.now()}`, role: 'user', content })
        appendMessage({ local_id: assistantLocalId, role: 'assistant', content: '' })
        input.value = ''
    } else {
        appendMessage({ local_id: assistantLocalId, role: 'assistant', content: '' })
    }
    try {
        await streamAigcLlmChat(
            {
                session_id: currentSessionId.value || undefined,
                content,
                model_code: selectedModelCode.value,
                regenerate_message_id: regenerateMessageId || undefined
            },
            {
                onEvent: ({ event, data }) => {
                    if (event === 'session') {
                        currentSessionId.value = Number(data.session_id)
                    }
                    if (event === 'message') {
                        const assistant = messages.value.find((item) => item.local_id === assistantLocalId)
                        if (assistant) {
                            assistant.id = Number(data.assistant_message_id)
                            assistant.parent_user_message_id = Number(data.parent_user_message_id)
                        }
                        const pendingUser = [...messages.value].reverse().find((item) => item.role === 'user' && item.local_id && !item.id)
                        if (pendingUser && data.user_message_id) {
                            pendingUser.id = Number(data.user_message_id)
                        }
                    }
                    if (event === 'delta') {
                        const assistant = messages.value.find((item) => item.local_id === assistantLocalId || item.id === Number(data.message_id))
                        if (assistant) {
                            assistant.content += data.delta || ''
                            scrollToBottom()
                        }
                    }
                    if (event === 'done') {
                        const assistant = messages.value.find((item) => item.local_id === assistantLocalId || item.id === Number(data.message_id))
                        if (assistant) {
                            assistant.content = data.content || assistant.content
                            assistant.status = 'done'
                            assistant.billing_tip = buildBillingTip(data.usage, data.billing)
                            scrollToBottom()
                        }
                    }
                    if (event === 'error') {
                        feedback.msgError(data.message || '生成失败')
                    }
                },
                onError: (error) => feedback.msgError(error.message || '流式连接异常')
            }
        )
    } catch (error: any) {
        const assistant = messages.value.find((item) => item.local_id === assistantLocalId)
        if (assistant) {
            assistant.status = 'error'
            assistant.content = error?.message || '流式连接异常'
        }
        feedback.msgError(error?.message || '流式连接异常')
    } finally {
        streaming.value = false
        await loadSessions()
        if (currentSessionId.value) {
            await selectSession(currentSessionId.value)
        }
    }
}

const stopGenerating = async () => {
    if (!currentSessionId.value) return
    await stopAigcLlmChat({ session_id: currentSessionId.value })
}

const regenerateLast = () => {
    const lastUser = [...messages.value].reverse().find((item) => item.role === 'user' && item.id)
    if (lastUser?.id) {
        sendMessage(Number(lastUser.id))
    }
}

const renameCurrentSession = async () => {
    if (!currentSessionId.value) return
    const title = window.prompt('请输入会话标题', currentSession.value?.title || '')
    if (!title) return
    await renameAigcLlmSession({ session_id: currentSessionId.value, title })
    await loadSessions()
}

const deleteCurrentSession = async () => {
    if (!currentSessionId.value) return
    await feedback.confirm('确定删除当前会话？')
    await deleteAigcLlmSession({ session_id: currentSessionId.value })
    startNewSession()
    await loadSessions()
}

const renderContent = (content = '') => {
    return content
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
        .replace(/`([^`]+)`/g, '<code>$1</code>')
        .replace(/\n/g, '<br>')
}

const formatNumber = (value: number) => {
    if (!Number.isFinite(value)) return '0'
    return value.toFixed(4).replace(/\.?0+$/, '')
}

const buildBillingTip = (usage: any = {}, billing: any = usage?.billing || {}) => {
    const prompt = Number(usage?.prompt_tokens || 0)
    const completion = Number(usage?.completion_tokens || 0)
    const charge = Number(billing?.user_charge_points || 0)
    if (!prompt && !completion && !charge) return ''
    return `输入 ${prompt} tokens · 输出 ${completion} tokens · 消耗 ${formatNumber(charge)} 点`
}

onMounted(async () => {
    await loadConfig()
    await loadSessions()
})
</script>

<style scoped>
.llm-page {
    display: grid;
    grid-template-columns: 280px minmax(0, 1fr);
    height: 100vh;
    min-height: 0;
    background: #050505;
    color: #fff;
}
.session-panel {
    display: flex;
    flex-direction: column;
    border-right: 1px solid rgb(255 255 255 / 10%);
    background: #101012;
}
.session-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 22px;
}
.session-head p,
.chat-head p {
    margin: 0 0 6px;
    color: rgb(255 255 255 / 48%);
    font-size: 12px;
}
.session-head h1,
.chat-head h2 {
    margin: 0;
    font-size: 22px;
}
button,
select {
    border: 1px solid rgb(255 255 255 / 14%);
    border-radius: 8px;
    background: #171719;
    color: #fff;
    cursor: pointer;
}
button:disabled,
select:disabled {
    cursor: not-allowed;
    opacity: 0.45;
}
.session-head button,
.chat-actions button,
.composer-bar button {
    height: 36px;
    padding: 0 14px;
}
.session-list {
    flex: 1;
    overflow: auto;
    padding: 0 12px 18px;
}
.session-item {
    display: block;
    width: 100%;
    margin-bottom: 8px;
    padding: 12px;
    text-align: left;
}
.session-item.active {
    border-color: rgb(255 255 255 / 28%);
    background: #222;
}
.session-item strong,
.session-item span {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.session-item span {
    margin-top: 6px;
    color: rgb(255 255 255 / 48%);
    font-size: 12px;
}
.chat-panel {
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    height: 100vh;
    min-height: 0;
    min-width: 0;
    overflow: hidden;
}
.chat-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    border-bottom: 1px solid rgb(255 255 255 / 10%);
    padding: 18px 24px;
}
.chat-actions {
    display: flex;
    gap: 10px;
}
.chat-actions select {
    min-width: 150px;
    padding: 0 10px;
}
.message-wrap {
    min-height: 0;
    overflow: auto;
    padding: 32px 28px 40px;
    scroll-padding-bottom: 40px;
}
.message-bottom {
    height: 1px;
}
.empty-chat {
    display: grid;
    place-items: center;
    height: 100%;
    color: rgb(255 255 255 / 54%);
}
.empty-chat strong {
    color: #fff;
    font-size: 24px;
}
.message {
    display: flex;
    gap: 12px;
    margin: 0 auto 18px;
    max-width: 920px;
    align-items: flex-start;
}
.message.user {
    flex-direction: row-reverse;
}
.message.assistant {
    justify-content: flex-start;
}
.message.user {
    justify-content: flex-start;
}
.avatar {
    display: grid;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 50%;
    background: #313233;
    font-size: 13px;
}
.message-body {
    max-width: min(760px, calc(100% - 50px));
    min-width: 0;
}
.message.user .message-body {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}
.bubble {
    width: fit-content;
    max-width: 100%;
    border: 1px solid rgb(255 255 255 / 10%);
    border-radius: 8px;
    background: #171719;
    padding: 14px 16px;
    line-height: 1.75;
    word-break: break-word;
    white-space: pre-wrap;
    box-sizing: border-box;
}
.message.user .bubble {
    background: #2a2b2c;
}
.bubble.empty {
    min-width: 52px;
    color: rgb(255 255 255 / 42%);
}
.billing-tip {
    margin-top: 8px;
    color: rgb(255 255 255 / 44%);
    font-size: 12px;
}
.composer {
    border-top: 1px solid rgb(255 255 255 / 10%);
    padding: 16px 24px 22px;
}
.composer textarea {
    width: 100%;
    height: 92px;
    box-sizing: border-box;
    resize: none;
    border: 1px solid rgb(255 255 255 / 12%);
    border-radius: 8px;
    outline: none;
    background: #101012;
    color: #fff;
    padding: 14px;
    line-height: 1.6;
}
.composer-bar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 12px;
}
.composer-bar .primary {
    border-color: #fff;
    background: #fff;
    color: #050505;
}
.composer-bar .danger {
    border-color: #ef4444;
    background: #ef4444;
}

@media (max-width: 900px) {
    .llm-page {
        grid-template-columns: 1fr;
        grid-template-rows: auto minmax(0, 1fr);
    }

    .session-panel {
        max-height: 220px;
        border-right: 0;
        border-bottom: 1px solid rgb(255 255 255 / 10%);
    }

    .session-head {
        padding: 16px 18px 10px;
    }

    .session-list {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 0 18px 16px;
    }

    .session-item {
        flex: 0 0 220px;
        margin-bottom: 0;
    }

    .chat-panel {
        height: auto;
        min-height: 0;
    }

    .chat-head {
        align-items: flex-start;
        flex-direction: column;
        padding: 16px 18px;
    }

    .chat-actions {
        flex-wrap: wrap;
        width: 100%;
    }

    .chat-actions select {
        flex: 1 1 180px;
        min-width: 0;
        height: 36px;
    }

    .message-wrap {
        padding: 24px 18px 32px;
    }

    .composer {
        padding: 14px 18px 18px;
    }

    .composer-bar {
        flex-wrap: wrap;
    }
}
</style>
