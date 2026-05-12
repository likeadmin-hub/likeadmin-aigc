import type { CanvasProviderKey } from '../types'

export interface ProviderConfig {
    key: CanvasProviderKey
    label: string
    defaultBaseUrl: string
    endpoints: {
        chat: string
        image: string
        video: string
        videoQuery: string
    }
}

export const PROVIDERS: Record<string, ProviderConfig> = {
    chatfire: {
        key: 'chatfire',
        label: '聚合渠道',
        defaultBaseUrl: 'https://api.chatfire.site',
        endpoints: {
            chat: '/v1/chat/completions',
            image: '/v1/images/generations',
            video: '/v1/video/generations',
            videoQuery: '/v1/video/task/{taskId}'
        }
    },
    openai: {
        key: 'openai',
        label: 'OpenAI 兼容',
        defaultBaseUrl: 'https://api.openai.com',
        endpoints: {
            chat: '/v1/chat/completions',
            image: '/v1/images/generations',
            video: '/v1/videos',
            videoQuery: '/v1/videos/{taskId}'
        }
    }
}

export const DEFAULT_PROVIDER = 'chatfire'

export function getProviderConfig(provider: string): ProviderConfig {
    return PROVIDERS[provider] || PROVIDERS[DEFAULT_PROVIDER]
}

export function getProviderList() {
    return Object.values(PROVIDERS)
}

export function adaptProviderRequest(provider: string, type: 'chat' | 'image' | 'video', params: Record<string, any>) {
    if (type === 'chat') {
        const adapted: Record<string, any> = {
            model: params.model,
            messages: params.messages
        }
        if (params.temperature !== undefined) adapted.temperature = params.temperature
        if (params.max_tokens !== undefined) adapted.max_tokens = params.max_tokens
        if (params.stream !== undefined) adapted.stream = params.stream
        return adapted
    }

    if (type === 'image') {
        const adapted: Record<string, any> = {
            model: params.model,
            prompt: params.prompt
        }
        if (params.size) adapted.size = params.size
        if (params.n) adapted.n = params.n
        if (params.quality) adapted.quality = params.quality
        if (params.style) adapted.style = params.style
        if (params.image) adapted.image = params.image
        if (params.reference_images?.length) adapted.reference_images = params.reference_images
        return adapted
    }

    const model = String(params.model || '')
    if (provider === 'chatfire' && model.includes('seedance')) {
        const content: any[] = []
        let textPrompt = params.prompt || ''
        if (params.resolution) textPrompt += ` --resolution ${params.resolution}`
        if (params.size || params.ratio) textPrompt += ` --ratio ${params.size || params.ratio}`
        if (params.seconds || params.duration) textPrompt += ` --dur ${params.seconds || params.duration}`
        textPrompt += ' --fps 24'
        textPrompt += ` --wm ${params.wm !== false ? 'true' : 'false'}`
        if (params.seed !== undefined) textPrompt += ` --seed ${params.seed}`
        textPrompt += ` --cf ${params.cf === true ? 'true' : 'false'}`
        content.push({ type: 'text', text: textPrompt })
        if (params.first_frame_image) {
            content.push({ type: 'image_url', image_url: { url: params.first_frame_image } })
        }
        return {
            model,
            content,
            generate_audio: params.generateAudio !== false
        }
    }

    const adapted: Record<string, any> = {
        model: params.model,
        prompt: params.prompt || ''
    }
    if (params.first_frame_image) adapted.first_frame_image = params.first_frame_image
    if (params.last_frame_image) adapted.last_frame_image = params.last_frame_image
    if (params.size || params.ratio) adapted.size = params.size || params.ratio
    if (params.seconds || params.duration) adapted.seconds = params.seconds || params.duration
    return adapted
}

export function adaptProviderResponse(type: 'chat' | 'image' | 'video', response: any) {
    if (type === 'chat') {
        return response?.choices?.[0]?.message?.content || response?.choices?.[0]?.delta?.content || ''
    }
    if (type === 'image') {
        const data = response?.data || response
        return (Array.isArray(data) ? data : [data]).map((item) => ({
            url: item?.url || item?.b64_json || '',
            revisedPrompt: item?.revised_prompt || ''
        }))
    }
    return {
        taskId:
            response?.task_id ||
            response?.id ||
            response?.data?.task_id ||
            response?.data?.id ||
            response?.data?.task?.id ||
            '',
        url: response?.data?.url || response?.url || response?.data?.[0]?.url || response?.content?.video_url || '',
        raw: response
    }
}
