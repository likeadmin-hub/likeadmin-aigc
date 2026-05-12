import { getApiPrefix, getApiUrl, getVersion } from '@/utils/env'
import { parseTenantIdFromRoute } from '@/utils/tenant'
import { useUserStore } from '@/stores/user'
import { TOKEN_KEY } from '@/enums/cacheEnums'

export function getAigcLlmConfig() {
    return $request.get({ url: '/app.aigc_llm.config/detail' })
}

export function getAigcLlmSessions() {
    return $request.get({ url: '/app.aigc_llm.session/lists' })
}

export function createAigcLlmSession(params?: any) {
    return $request.post({ url: '/app.aigc_llm.session/create', params })
}

export function getAigcLlmSessionDetail(params: any) {
    return $request.get({ url: '/app.aigc_llm.session/detail', params })
}

export function renameAigcLlmSession(params: any) {
    return $request.post({ url: '/app.aigc_llm.session/rename', params })
}

export function deleteAigcLlmSession(params: any) {
    return $request.post({ url: '/app.aigc_llm.session/delete', params })
}

export function getAigcLlmMessages(params: any) {
    return $request.get({ url: '/app.aigc_llm.message/lists', params })
}

export function stopAigcLlmChat(params: any) {
    return $request.post({ url: '/app.aigc_llm.chat/stop', params })
}

export type LlmSseEvent = {
    event: string
    data: any
}

const getCookieValue = (name: string) => {
    if (!process.client) {
        return ''
    }
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
    return match?.[1] ? decodeURIComponent(match[1]) : ''
}

const getAuthToken = () => {
    const userStore = useUserStore()
    return userStore.token || useCookie<string | null>(TOKEN_KEY).value || getCookieValue(TOKEN_KEY) || ''
}

export async function streamAigcLlmChat(
    params: any,
    handlers: {
        onEvent?: (event: LlmSseEvent) => void
        onError?: (error: Error) => void
        onClose?: () => void
    }
) {
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
        version: String(getVersion() || '')
    }
    const token = getAuthToken()
    if (token) {
        headers.token = token
        headers.Authorization = `Bearer ${token}`
    }
    const tenantId = parseTenantIdFromRoute()
    if (tenantId) {
        headers['tenant-id'] = tenantId
    }

    const response = await fetch(`${getApiUrl()}${getApiPrefix()}/app.aigc_llm.chat/stream`, {
        method: 'POST',
        headers,
        body: JSON.stringify(params)
    })
    if (!response.ok || !response.body) {
        throw new Error(`SSE连接失败：${response.status}`)
    }
    const contentType = response.headers.get('content-type') || ''
    if (!contentType.includes('text/event-stream')) {
        const text = await response.text()
        try {
            const json = JSON.parse(text)
            throw new Error(json.msg || json.message || '流式接口返回异常')
        } catch (error) {
            if (error instanceof Error && error.message !== '流式接口返回异常') {
                throw error
            }
            throw new Error(text || '流式接口返回异常')
        }
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''

    const parseChunk = (chunk: string) => {
        buffer += chunk
        const blocks = buffer.split(/\n\n/)
        buffer = blocks.pop() || ''
        for (const block of blocks) {
            const lines = block.split(/\n/)
            const event = lines.find((line) => line.startsWith('event:'))?.replace(/^event:\s*/, '') || 'message'
            const dataText = lines
                .filter((line) => line.startsWith('data:'))
                .map((line) => line.replace(/^data:\s*/, ''))
                .join('\n')
            if (!dataText) {
                continue
            }
            try {
                handlers.onEvent?.({ event, data: JSON.parse(dataText) })
            } catch (error) {
                handlers.onError?.(error as Error)
            }
        }
    }

    try {
        let reading = true
        while (reading) {
            const { value, done } = await reader.read()
            if (done) {
                reading = false
            } else {
                parseChunk(decoder.decode(value, { stream: true }))
            }
        }
        parseChunk(decoder.decode())
    } catch (error) {
        handlers.onError?.(error as Error)
    } finally {
        handlers.onClose?.()
    }
}
