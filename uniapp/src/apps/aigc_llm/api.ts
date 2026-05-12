import request from '@/utils/request'
import appConfig from '@/config'
import { getToken } from '@/utils/auth'
import { getTenantId } from '@/utils/tenant'

export function getAigcLlmConfig() {
    return request.get({ url: '/app.aigc_llm.config/detail' })
}

export function getAigcLlmSessions() {
    return request.get({ url: '/app.aigc_llm.session/lists' })
}

export function createAigcLlmSession(data?: any) {
    return request.post({ url: '/app.aigc_llm.session/create', data })
}

export function getAigcLlmMessages(data: any) {
    return request.get({ url: '/app.aigc_llm.message/lists', data })
}

export function renameAigcLlmSession(data: any) {
    return request.post({ url: '/app.aigc_llm.session/rename', data })
}

export function deleteAigcLlmSession(data: any) {
    return request.post({ url: '/app.aigc_llm.session/delete', data })
}

export function stopAigcLlmChat(data: any) {
    return request.post({ url: '/app.aigc_llm.chat/stop', data })
}

type StreamHandlers = {
    onEvent?: (event: { event: string; data: any }) => void
    onError?: (error: any) => void
    onClose?: () => void
}

const buildHeaders = () => {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        version: appConfig.version
    }
    const token = getToken()
    const tenantId = getTenantId()
    if (token) headers.token = token
    if (tenantId) headers['tenant-id'] = tenantId
    return headers
}

const emitSseBlocks = (state: { buffer: string }, chunk: string, handlers: StreamHandlers) => {
    state.buffer += chunk
    const blocks = state.buffer.split(/\n\n/)
    state.buffer = blocks.pop() || ''
    blocks.forEach((block) => {
        const lines = block.split(/\n/)
        const event =
            lines.find((line) => line.startsWith('event:'))?.replace(/^event:\s*/, '') || 'message'
        const dataText = lines
            .filter((line) => line.startsWith('data:'))
            .map((line) => line.replace(/^data:\s*/, ''))
            .join('\n')
        if (!dataText) return
        try {
            handlers.onEvent?.({ event, data: JSON.parse(dataText) })
        } catch (error) {
            handlers.onError?.(error)
        }
    })
}

export function streamAigcLlmChat(data: any, handlers: StreamHandlers = {}) {
    const url = `${appConfig.baseUrl}${appConfig.urlPrefix}/app.aigc_llm.chat/stream`
    const state = { buffer: '' }

    // #ifdef H5
    return fetch(url, {
        method: 'POST',
        headers: buildHeaders(),
        body: JSON.stringify(data)
    })
        .then(async (response) => {
            if (!response.ok || !response.body) throw new Error(`SSE连接失败：${response.status}`)
            const reader = response.body.getReader()
            const decoder = new TextDecoder('utf-8')
            let reading = true
            while (reading) {
                const { value, done } = await reader.read()
                if (done) {
                    reading = false
                } else {
                    emitSseBlocks(state, decoder.decode(value, { stream: true }), handlers)
                }
            }
            emitSseBlocks(state, decoder.decode(), handlers)
        })
        .catch((error) => handlers.onError?.(error))
        .finally(() => handlers.onClose?.())
    // #endif

    // #ifdef MP-WEIXIN
    return new Promise((resolve) => {
        const decoder = new TextDecoder('utf-8')
        const task: any = uni.request({
            url,
            method: 'POST',
            header: buildHeaders(),
            data,
            enableChunked: true,
            success: () => resolve(true),
            fail: (error) => handlers.onError?.(error),
            complete: () => handlers.onClose?.()
        } as any)
        task.onChunkReceived?.((res: any) => {
            emitSseBlocks(
                state,
                decoder.decode(new Uint8Array(res.data), { stream: true }),
                handlers
            )
        })
    })
    // #endif
}
