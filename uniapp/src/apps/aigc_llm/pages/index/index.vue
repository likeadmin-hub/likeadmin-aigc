<template>
    <view class="page">
        <view class="topbar">
            <view class="nav-btn" @click="goBack">‹</view>
            <view class="title">AIGC对话</view>
            <view class="nav-btn" @click="drawerVisible = true">☰</view>
        </view>

        <scroll-view class="messages" scroll-y :scroll-top="scrollTop" :scroll-into-view="scrollIntoView" scroll-with-animation>
            <view v-if="!messages.length" class="empty">
                <view class="empty-title">开始对话</view>
                <view class="empty-desc">支持多会话、多轮上下文和流式回复</view>
            </view>
            <view
                v-for="item in messages"
                :key="item.local_id || item.id"
                class="message"
                :class="item.role"
            >
                <view class="avatar">{{ item.role === 'user' ? '我' : 'AI' }}</view>
                <view>
                    <view class="bubble">{{ item.content || '...' }}</view>
                    <view v-if="item.role === 'assistant' && item.billing_tip" class="billing-tip">{{ item.billing_tip }}</view>
                </view>
            </view>
            <view id="message-bottom" class="message-bottom"></view>
        </scroll-view>

        <view class="composer">
            <picker v-if="models.length" :range="models" range-key="name" @change="selectModel">
                <view class="model-picker">{{ selectedModelName }} · {{ selectedModelPrice }}</view>
            </picker>
            <textarea
                v-model="input"
                class="input"
                auto-height
                maxlength="2000"
                :disabled="streaming"
                placeholder="输入你的问题"
                placeholder-class="placeholder"
            />
            <view class="action-row">
                <button
                    class="ghost"
                    :disabled="streaming || !canRegenerate"
                    @click="regenerateLast"
                >
                    重答
                </button>
                <button v-if="streaming" class="danger" @click="stopGenerating">停止</button>
                <button v-else class="primary" :disabled="!input.trim()" @click="sendMessage()">
                    发送
                </button>
            </view>
        </view>

        <view v-if="drawerVisible" class="drawer-mask" @click="drawerVisible = false">
            <view class="drawer" @click.stop>
                <view class="drawer-head">
                    <text>会话</text>
                    <button @click="startNewSession">新建</button>
                </view>
                <scroll-view class="session-list" scroll-y>
                    <view
                        v-for="item in sessions"
                        :key="item.id"
                        class="session-item"
                        :class="{ active: currentSessionId === item.id }"
                        @click="selectSession(item.id)"
                    >
                        <view class="session-title">{{ item.title || '新对话' }}</view>
                        <view class="session-last">{{ item.last_message || '暂无消息' }}</view>
                    </view>
                </scroll-view>
                <view class="drawer-actions">
                    <button :disabled="!currentSessionId" @click="renameCurrentSession">
                        重命名
                    </button>
                    <button :disabled="!currentSessionId" @click="deleteCurrentSession">
                        删除
                    </button>
                </view>
            </view>
        </view>
    </view>
</template>

<script setup lang="ts">
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

type ChatMessage = {
    id?: number
    local_id?: string
    role: 'user' | 'assistant'
    content: string
    parent_user_message_id?: number
    billing_tip?: string
}

const sessions = ref<any[]>([])
const messages = ref<ChatMessage[]>([])
const models = ref<any[]>([])
const selectedModelCode = ref('')
const currentSessionId = ref(0)
const input = ref('')
const streaming = ref(false)
const drawerVisible = ref(false)
const scrollTop = ref(0)
const scrollIntoView = ref('')

const selectedModel = computed(() => models.value.find((item) => item.code === selectedModelCode.value) || {})
const selectedModelName = computed(() => selectedModel.value?.name || '默认模型')
const selectedModelPrice = computed(() => {
    const inputPrice = Number(selectedModel.value?.tenant_input_unit_price || selectedModel.value?.tenant_unit_price || 0)
    const outputPrice = Number(selectedModel.value?.tenant_output_unit_price || selectedModel.value?.tenant_unit_price || 0)
    return `输入${formatNumber(inputPrice)}点/百万Token 输出${formatNumber(outputPrice)}点/百万Token`
})
const canRegenerate = computed(() => messages.value.some((item) => item.role === 'user' && item.id))

const goBack = () => {
    const pages = getCurrentPages()
    if (pages.length > 1) {
        uni.navigateBack()
    } else {
        uni.switchTab({ url: '/pages/index/index' })
    }
}

const bumpScroll = () => {
    nextTick(() => {
        scrollTop.value += 99999
        scrollIntoView.value = ''
        nextTick(() => {
            scrollIntoView.value = 'message-bottom'
        })
    })
}

const loadConfig = async () => {
    const config: any = await getAigcLlmConfig()
    models.value = config.option_config?.models || []
    selectedModelCode.value =
        config.option_config?.defaults?.model || models.value[0]?.code || config.model || ''
}

const loadSessions = async () => {
    sessions.value = await getAigcLlmSessions()
    if (!currentSessionId.value && sessions.value[0]?.id) {
        await selectSession(Number(sessions.value[0].id))
    }
}

const selectSession = async (id: number) => {
    currentSessionId.value = Number(id)
    const rows: any[] = await getAigcLlmMessages({ session_id: id })
    messages.value = rows.map((item) => ({ ...item, billing_tip: buildBillingTip(item.token_usage_json) }))
    const session = sessions.value.find((item) => Number(item.id) === Number(id))
    if (session?.model_code) selectedModelCode.value = session.model_code
    drawerVisible.value = false
    bumpScroll()
}

const startNewSession = () => {
    currentSessionId.value = 0
    messages.value = []
    input.value = ''
    drawerVisible.value = false
}

const selectModel = (event: any) => {
    selectedModelCode.value =
        models.value[Number(event.detail.value)]?.code || selectedModelCode.value
}

const sendMessage = async (regenerateMessageId = 0) => {
    const source = regenerateMessageId
        ? messages.value.find((item) => Number(item.id) === regenerateMessageId)
        : null
    const content = regenerateMessageId ? (source?.content || '').trim() : input.value.trim()
    if (!content && !regenerateMessageId) return

    streaming.value = true
    const assistantLocalId = `assistant-${Date.now()}`
    if (!regenerateMessageId) {
        messages.value.push({ local_id: `user-${Date.now()}`, role: 'user', content })
        input.value = ''
    }
    messages.value.push({ local_id: assistantLocalId, role: 'assistant', content: '' })
    bumpScroll()

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
                    const assistant = messages.value.find(
                        (item) => item.local_id === assistantLocalId
                    )
                    if (assistant) {
                        assistant.id = Number(data.assistant_message_id)
                        assistant.parent_user_message_id = Number(data.parent_user_message_id)
                    }
                    const pendingUser = [...messages.value]
                        .reverse()
                        .find((item) => item.role === 'user' && item.local_id && !item.id)
                    if (pendingUser && data.user_message_id) {
                        pendingUser.id = Number(data.user_message_id)
                    }
                }
                if (event === 'delta') {
                    const assistant = messages.value.find(
                        (item) =>
                            item.local_id === assistantLocalId ||
                            item.id === Number(data.message_id)
                    )
                    if (assistant) {
                        assistant.content += data.delta || ''
                        bumpScroll()
                    }
                }
                if (event === 'done') {
                    const assistant = messages.value.find(
                        (item) =>
                            item.local_id === assistantLocalId ||
                            item.id === Number(data.message_id)
                    )
                    if (assistant) {
                        assistant.content = data.content || assistant.content
                        assistant.billing_tip = buildBillingTip(data.usage, data.billing)
                    }
                }
                if (event === 'error') {
                    uni.$u.toast(data.message || '生成失败')
                }
            },
            onError: () => uni.$u.toast('流式连接异常'),
            onClose: async () => {
                streaming.value = false
                await loadSessions()
                if (currentSessionId.value) {
                    const rows: any[] = await getAigcLlmMessages({
                        session_id: currentSessionId.value
                    })
                    messages.value = rows.map((item) => ({ ...item, billing_tip: buildBillingTip(item.token_usage_json) }))
                    bumpScroll()
                }
            }
        }
    )
}

const stopGenerating = async () => {
    if (currentSessionId.value) await stopAigcLlmChat({ session_id: currentSessionId.value })
}

const regenerateLast = () => {
    const lastUser = [...messages.value].reverse().find((item) => item.role === 'user' && item.id)
    if (lastUser?.id) sendMessage(Number(lastUser.id))
}

const renameCurrentSession = () => {
    if (!currentSessionId.value) return
    uni.showModal({
        title: '重命名会话',
        editable: true,
        placeholderText: '请输入标题',
        success: async (res: any) => {
            const title = res.content || ''
            if (res.confirm && title.trim()) {
                await renameAigcLlmSession({ session_id: currentSessionId.value, title })
                await loadSessions()
            }
        }
    } as any)
}

const deleteCurrentSession = () => {
    if (!currentSessionId.value) return
    uni.showModal({
        title: '删除会话',
        content: '确定删除当前会话？',
        success: async (res) => {
            if (!res.confirm) return
            await deleteAigcLlmSession({ session_id: currentSessionId.value })
            startNewSession()
            await loadSessions()
        }
    })
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

<style lang="scss" scoped>
.page {
    min-height: 100vh;
    background: #050505;
    color: #fff;
    display: flex;
    flex-direction: column;
}
.topbar {
    height: 96rpx;
    padding: 0 28rpx;
    padding-top: env(safe-area-inset-top);
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    background: #101012;
}
.nav-btn {
    width: 72rpx;
    height: 72rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 12rpx;
    background: #171719;
    font-size: 44rpx;
}
.title {
    font-size: 34rpx;
    font-weight: 700;
}
.messages {
    flex: 1;
    height: 0;
    padding: 28rpx;
    box-sizing: border-box;
}
.message-bottom {
    height: 1rpx;
}
.empty {
    padding-top: 180rpx;
    text-align: center;
    color: rgba(255, 255, 255, 0.52);
}
.empty-title {
    margin-bottom: 16rpx;
    color: #fff;
    font-size: 40rpx;
    font-weight: 700;
}
.message {
    display: flex;
    gap: 16rpx;
    margin-bottom: 24rpx;
}
.message.user {
    flex-direction: row-reverse;
}
.avatar {
    width: 64rpx;
    height: 64rpx;
    flex: 0 0 64rpx;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #313233;
    font-size: 24rpx;
}
.bubble {
    max-width: 560rpx;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12rpx;
    background: #171719;
    padding: 20rpx 24rpx;
    line-height: 1.65;
    white-space: pre-wrap;
    word-break: break-word;
}
.message.user .bubble {
    background: #2a2b2c;
}
.billing-tip {
    margin-top: 10rpx;
    color: rgba(255, 255, 255, 0.44);
    font-size: 22rpx;
}
.composer {
    padding: 18rpx 24rpx calc(22rpx + env(safe-area-inset-bottom));
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    background: #101012;
}
.model-picker {
    display: inline-flex;
    margin-bottom: 12rpx;
    padding: 10rpx 18rpx;
    border-radius: 999rpx;
    background: #222;
    color: rgba(255, 255, 255, 0.72);
    font-size: 24rpx;
}
.input {
    width: 100%;
    min-height: 92rpx;
    box-sizing: border-box;
    border-radius: 12rpx;
    background: #171719;
    color: #fff;
    padding: 18rpx;
    line-height: 1.6;
}
.placeholder {
    color: rgba(255, 255, 255, 0.38);
}
.action-row {
    display: flex;
    justify-content: flex-end;
    gap: 14rpx;
    margin-top: 14rpx;
}
button {
    height: 72rpx;
    margin: 0;
    padding: 0 28rpx;
    border-radius: 12rpx;
    border: 0;
    background: #222;
    color: #fff;
    font-size: 26rpx;
}
button::after {
    display: none;
}
button[disabled] {
    opacity: 0.45;
}
.primary {
    background: #fff;
    color: #050505;
}
.danger {
    background: #ef4444;
}
.drawer-mask {
    position: fixed;
    inset: 0;
    z-index: 50;
    background: rgba(0, 0, 0, 0.56);
}
.drawer {
    width: 620rpx;
    height: 100%;
    background: #101012;
    display: flex;
    flex-direction: column;
}
.drawer-head,
.drawer-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 28rpx;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}
.drawer-head text {
    font-size: 34rpx;
    font-weight: 700;
}
.session-list {
    flex: 1;
    height: 0;
    padding: 18rpx;
    box-sizing: border-box;
}
.session-item {
    margin-bottom: 14rpx;
    padding: 20rpx;
    border-radius: 12rpx;
    background: #171719;
}
.session-item.active {
    background: #2a2b2c;
}
.session-title,
.session-last {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.session-last {
    margin-top: 8rpx;
    color: rgba(255, 255, 255, 0.48);
    font-size: 24rpx;
}
</style>
