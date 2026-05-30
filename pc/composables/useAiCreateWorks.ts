import card1 from '@/assets/images/ai-app/card-1.png'
import card2 from '@/assets/images/ai-app/card-2.png'
import card3 from '@/assets/images/ai-app/card-3.png'
import card4 from '@/assets/images/ai-app/card-4.png'
import card5 from '@/assets/images/ai-app/card-5.png'
import card6 from '@/assets/images/ai-app/card-6.png'
import card7 from '@/assets/images/ai-app/card-7.png'
import card8 from '@/assets/images/ai-app/card-8.png'
import card9 from '@/assets/images/ai-app/card-9.png'
import card10 from '@/assets/images/ai-app/card-10.png'
import card11 from '@/assets/images/ai-app/card-11.png'
import card12 from '@/assets/images/ai-app/card-12.png'

export type AiGenerationMode = 'image' | 'video'
export type AiCreateStatus = 'creating' | 'created'
export type AiCreateOptionKey = 'model' | 'count' | 'ratio' | 'resolution' | 'duration' | 'quality'
export type AiCreateOptionState = Record<AiCreateOptionKey, string>
export type AiCreateOptionValues = Record<AiCreateOptionKey, string[]>

export interface AiCreateDraft {
    task: string
    prompt: string
    mode: AiGenerationMode
    model: string
    count: string
    ratio: string
    resolution: string
    duration: string
    quality: string
    referenceFileName: string
    referenceImage: string
    referenceImages?: string[]
}

export interface AiCreateWork extends AiCreateDraft {
    id: string
    status: AiCreateStatus
    createdAt: number
    seed: number
}

const imagePool = [card1, card2, card3, card4, card5, card6, card7, card8, card9, card10, card11, card12]

const normalizeText = (value: unknown, fallback = '') => (typeof value === 'string' ? value : fallback)
const normalizeMode = (value: unknown, fallback: AiGenerationMode = 'image'): AiGenerationMode =>
    value === 'video' ? 'video' : fallback

export const createAiCreateOptionState = (): AiCreateOptionState => ({
    model: '豆包seed-2-0-pro',
    count: '1张',
    ratio: '3:4',
    resolution: '1k',
    duration: '5s',
    quality: '1k标清'
})

export const aiCreateOptionValues: AiCreateOptionValues = {
    model: ['豆包seed-2-0-pro', 'sdxl-lightning', 'midjourney-v7'],
    count: ['1张', '2张', '4张'],
    ratio: ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16'],
    resolution: ['1k', '2k', '4k'],
    duration: ['5s', '10s'],
    quality: ['1k标清', '2k高清']
}

export const normalizeAiCreateOptionState = (state?: Partial<AiCreateOptionState>) => {
    const defaults = createAiCreateOptionState()
    return {
        model: normalizeText(state?.model, defaults.model),
        count: normalizeText(state?.count, defaults.count),
        ratio: normalizeText(state?.ratio, defaults.ratio),
        resolution: normalizeText(state?.resolution, defaults.resolution),
        duration: normalizeText(state?.duration, defaults.duration),
        quality: normalizeText(state?.quality, defaults.quality)
    } satisfies AiCreateOptionState
}

export const getAiCreateRatioPreviewStyle = (ratio: string) => {
    const [widthText = '1', heightText = '1'] = ratio.split(/[:：]/)
    const width = Number.parseFloat(widthText)
    const height = Number.parseFloat(heightText)

    if (!Number.isFinite(width) || !Number.isFinite(height) || width <= 0 || height <= 0) {
        return { width: '12px', height: '12px' }
    }

    const maxSide = 14
    const minSide = 4
    const ratioValue = width / height

    if (ratioValue >= 1) {
        return {
            width: `${maxSide}px`,
            height: `${Math.max(minSide, Math.round(maxSide / ratioValue))}px`
        }
    }

    return {
        width: `${Math.max(minSide, Math.round(maxSide * ratioValue))}px`,
        height: `${maxSide}px`
    }
}

export const getAiCreateCardCount = (count: string) => {
    const parsed = Number.parseInt(count, 10)
    if (!Number.isFinite(parsed) || parsed <= 0) return 1
    return Math.min(parsed, 4)
}

export const getAiCreateCards = (work: Pick<AiCreateWork, 'id' | 'seed' | 'count'>) =>
    Array.from({ length: getAiCreateCardCount(work.count) }, (_, index) => ({
        id: `${work.id}-${index}`,
        image: imagePool[(work.seed + index) % imagePool.length],
        alt: `生成结果 ${index + 1}`
    }))

export const useAiCreateWorks = () => {
    const works = useState<AiCreateWork[]>('ai-create-works', () => [])
    const draft = useState<AiCreateDraft | null>('ai-create-draft', () => null)

    const setDraft = (payload: AiCreateDraft | null) => {
        draft.value = payload
    }

    const appendWork = (payload: AiCreateDraft) => {
        const existed = works.value.find((item) => item.task === payload.task)
        if (existed) {
            if (draft.value?.task === payload.task) draft.value = null
            return existed
        }

        const nextWork: AiCreateWork = {
            ...payload,
            id: payload.task,
            status: 'creating',
            createdAt: Date.now(),
            seed: (works.value.length * 3) % imagePool.length
        }

        works.value = [...works.value, nextWork]

        if (draft.value?.task === payload.task) draft.value = null

        return nextWork
    }

    const buildPayloadFromQuery = (query: Record<string, unknown>) => {
        const draftValue = draft.value
        const prompt = normalizeText(query.prompt, draftValue?.prompt || '').trim()
        if (!prompt) return null
        const optionState = normalizeAiCreateOptionState({
            model: normalizeText(query.model, draftValue?.model),
            count: normalizeText(query.count, draftValue?.count),
            ratio: normalizeText(query.ratio, draftValue?.ratio),
            resolution: normalizeText(query.resolution, draftValue?.resolution),
            duration: normalizeText(query.duration, draftValue?.duration),
            quality: normalizeText(query.quality, draftValue?.quality)
        })

        return {
            task: normalizeText(query.task, draftValue?.task || `${Date.now()}`),
            prompt,
            mode: normalizeMode(query.mode, draftValue?.mode || 'image'),
            model: optionState.model,
            count: optionState.count,
            ratio: optionState.ratio,
            resolution: optionState.resolution,
            duration: optionState.duration,
            quality: optionState.quality,
            referenceFileName: normalizeText(query.referenceName, draftValue?.referenceFileName || ''),
            referenceImage: normalizeText(query.referenceImage, draftValue?.referenceImage || ''),
            referenceImages: Array.isArray(draftValue?.referenceImages) ? draftValue.referenceImages.filter(Boolean) : []
        } satisfies AiCreateDraft
    }

    const ensureWorkFromQuery = (query: Record<string, unknown>) => {
        const payload = buildPayloadFromQuery(query)
        if (!payload) return null
        return appendWork(payload)
    }

    const setWorkStatus = (task: string, status: AiCreateStatus) => {
        works.value = works.value.map((item) => (item.task === task ? { ...item, status } : item))
    }

    const fillDraftFromWork = (work: AiCreateWork) => {
        const optionState = normalizeAiCreateOptionState(work)
        const payload: AiCreateDraft = {
            task: `${Date.now()}`,
            prompt: work.prompt,
            mode: work.mode,
            model: optionState.model,
            count: optionState.count,
            ratio: optionState.ratio,
            resolution: optionState.resolution,
            duration: optionState.duration,
            quality: optionState.quality,
            referenceFileName: work.referenceFileName,
            referenceImage: work.referenceImage,
            referenceImages: Array.isArray(work.referenceImages) ? work.referenceImages.filter(Boolean) : []
        }
        draft.value = payload
        return payload
    }

    return {
        works,
        draft,
        setDraft,
        appendWork,
        ensureWorkFromQuery,
        setWorkStatus,
        fillDraftFromWork
    }
}
