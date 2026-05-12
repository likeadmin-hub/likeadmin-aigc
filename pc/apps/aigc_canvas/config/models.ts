import type { CanvasModel } from '../types'

export const IMAGE_SIZE_OPTIONS = [
    { label: '21:9', key: '3024x1296' },
    { label: '16:9', key: '2560x1440' },
    { label: '4:3', key: '2304x1728' },
    { label: '3:2', key: '2496x1664' },
    { label: '1:1', key: '2048x2048' },
    { label: '2:3', key: '1664x2496' },
    { label: '3:4', key: '1728x2304' },
    { label: '9:16', key: '1440x2560' },
    { label: '9:21', key: '1296x3024' },
    { label: '1x1', key: '1x1' },
    { label: '16x9', key: '16x9' },
    { label: '9x16', key: '9x16' }
]

export const IMAGE_QUALITY_OPTIONS = [
    { label: '标准画质', key: 'standard' },
    { label: '高清', key: 'hd' },
    { label: '4K 高清', key: '4k' }
]

export const VIDEO_RATIO_OPTIONS = [
    { label: '16:9', key: '16:9' },
    { label: '4:3', key: '4:3' },
    { label: '1:1', key: '1:1' },
    { label: '3:4', key: '3:4' },
    { label: '9:16', key: '9:16' },
    { label: '21:9', key: '21:9' }
]

export const VIDEO_DURATION_OPTIONS = [
    { label: '5 秒', key: 5 },
    { label: '10 秒', key: 10 }
]

export const CHAT_MODELS: CanvasModel[] = [
    { label: 'GPT-4o Mini', key: 'gpt-4o-mini', provider: ['openai'] },
    { label: 'GPT-4o', key: 'gpt-4o', provider: ['openai'] },
    { label: 'GPT-5.2', key: 'gpt-5.2', provider: ['openai'] },
    { label: 'DeepSeek Chat', key: 'deepseek-chat', provider: ['openai', 'chatfire'] },
    { label: '豆包 Seed Flash', key: 'doubao-seed-1-6-flash-250615', provider: ['chatfire'] },
    { label: 'Gemini 3 Pro', key: 'gemini-3-pro', provider: ['openai'] }
]

export const IMAGE_MODELS: CanvasModel[] = [
    {
        label: 'Nano Banana 2',
        key: 'nano-banana-2',
        provider: ['chatfire'],
        sizes: ['16x9', '4x3', '3x2', '1x1', '2x3', '3x4', '9x16'],
        defaultParams: { size: '1x1', quality: 'standard', style: 'vivid' }
    },
    {
        label: 'Nano Banana Pro',
        key: 'nano-banana-pro',
        provider: ['chatfire'],
        sizes: ['16x9', '4x3', '3x2', '1x1', '2x3', '3x4', '9x16'],
        defaultParams: { size: '1x1', quality: 'standard', style: 'vivid' }
    },
    {
        label: '豆包 Seedream 4.5',
        key: 'doubao-seedream-4-5-251128',
        provider: ['chatfire'],
        sizes: ['3024x1296', '2560x1440', '2304x1728', '2048x2048', '1440x2560'],
        defaultParams: { size: '2048x2048', quality: 'standard', style: 'vivid' }
    },
    {
        label: 'Nano Banana',
        key: 'nano-banana',
        provider: ['chatfire'],
        sizes: [],
        defaultParams: { size: '1x1', quality: 'standard', style: 'vivid' }
    }
]

export const VIDEO_MODELS: CanvasModel[] = [
    {
        label: 'Seedance 1.5 Pro 图文视频',
        key: 'doubao-seedance-1-5-pro-251215',
        provider: ['chatfire'],
        ratios: ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'],
        durations: [5, 10],
        defaultParams: { ratio: '16:9', duration: 10, resolution: '1080p' }
    },
    {
        label: 'Seedance 1.0 Lite 文生视频',
        key: 'doubao-seedance-1-0-lite-t2v-250428',
        provider: ['chatfire'],
        ratios: ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'],
        durations: [5, 10],
        defaultParams: { ratio: '16:9', duration: 5, resolution: '720p' }
    },
    {
        label: 'Seedance 1.0 Lite 图生视频',
        key: 'doubao-seedance-1-0-lite-i2v-250428',
        provider: ['chatfire'],
        ratios: ['16:9'],
        durations: [5, 10],
        defaultParams: { ratio: '16:9', duration: 5, resolution: '720p' }
    },
    {
        label: 'Seedance 1.0 Pro Fast 图文视频',
        key: 'doubao-seedance-1-0-pro-fast-251015',
        provider: ['chatfire'],
        ratios: ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9'],
        durations: [5, 10],
        defaultParams: { ratio: '16:9', duration: 5, resolution: '1080p' }
    }
]

export const DEFAULT_CHAT_MODEL = 'gpt-4o-mini'
export const DEFAULT_IMAGE_MODEL = 'nano-banana-pro'
export const DEFAULT_VIDEO_MODEL = 'doubao-seedance-1-5-pro-251215'

export function getModelByKey(key: string) {
    return [...CHAT_MODELS, ...IMAGE_MODELS, ...VIDEO_MODELS].find((model) => model.key === key)
}

export function filterModelsByProvider(models: CanvasModel[], provider: string) {
    return models.filter((model) => !model.provider?.length || model.provider.includes(provider))
}
