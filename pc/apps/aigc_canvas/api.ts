import { TOKEN_KEY } from '@/enums/cacheEnums'
import { useUserStore } from '@/stores/user'
import { getApiPrefix, getApiUrl, getVersion } from '@/utils/env'
import { parseTenantIdFromRoute } from '@/utils/tenant'

export function getAigcCanvasConfig() {
    return $request.get({ url: '/app.aigc_canvas.config/detail' })
}

export function getCanvasRunLists(params?: Record<string, any>) {
    return $request.get({ url: '/app.aigc_canvas.run/lists', params })
}

export async function proxyChat(params: Record<string, any>) {
    return $request.post({
        url: '/app.aigc_canvas.generate/text',
        params
    })
}

export type CanvasTextStreamEvent = {
    event: string
    data: any
}

const getCookieValue = (name: string) => {
    if (!process.client) return ''
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
    return match?.[1] ? decodeURIComponent(match[1]) : ''
}

const getAuthToken = () => {
    const userStore = useUserStore()
    return userStore.token || useCookie<string | null>(TOKEN_KEY).value || getCookieValue(TOKEN_KEY) || ''
}

export async function streamCanvasText(
    params: Record<string, any>,
    handlers: {
        onEvent?: (event: CanvasTextStreamEvent) => void
        onError?: (error: Error) => void
        onClose?: () => void
    } = {}
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

    const response = await fetch(`${getApiUrl()}${getApiPrefix()}/app.aigc_canvas.generate/textStream`, {
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
            if (error instanceof Error && error.message !== '流式接口返回异常') throw error
            throw new Error(text || '流式接口返回异常')
        }
    }

    const reader = response.body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''
    const parseChunk = (chunk: string) => {
        buffer += chunk
        buffer = buffer.replace(/\r\n/g, '\n').replace(/\r/g, '\n')
        const blocks = buffer.split(/\n\n+/)
        buffer = blocks.pop() || ''
        for (const block of blocks) {
            const lines = block.split(/\n/)
            const event = lines.find((line) => line.startsWith('event:'))?.replace(/^event:\s*/, '').trim() || 'message'
            const dataText = lines
                .filter((line) => line.startsWith('data:'))
                .map((line) => line.replace(/^data:\s*/, '').trimEnd())
                .join('\n')
            if (!dataText) continue
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
        if (buffer.trim()) {
            parseChunk('\n\n')
        }
    } catch (error) {
        handlers.onError?.(error as Error)
    } finally {
        handlers.onClose?.()
    }
}

export async function proxyImage(params: Record<string, any>) {
    return $request.post({
        url: '/app.aigc_canvas.generate/image',
        params
    })
}

export async function proxyImageQuery(taskId: string | number) {
    return $request.get({
        url: '/app.aigc_canvas.generate/imageQuery',
        params: { task_id: taskId }
    })
}

export async function proxyVideo(params: Record<string, any>) {
    return $request.post({
        url: '/app.aigc_canvas.generate/video',
        params
    })
}

export async function proxyVideoQuery(taskId: string | number) {
    return $request.get({
        url: '/app.aigc_canvas.generate/videoQuery',
        params: { task_id: taskId }
    })
}
