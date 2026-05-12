import type { Edge, Node } from '@vue-flow/core'

export type CanvasNodeVariant =
    | 'text'
    | 'imagePrompt'
    | 'imageConfig'
    | 'image'
    | 'imageResult'
    | 'videoConfig'
    | 'video'
    | 'llmConfig'

export type CanvasEdgeKind = 'promptOrder' | 'imageOrder' | 'imageRole' | 'smoothstep'

export type CanvasProviderKey = 'chatfire' | 'openai' | string

export interface CanvasApiSettings {
    provider: CanvasProviderKey
    baseUrl: string
    apiKey: string
    chatModel: string
    imageModel: string
    videoModel: string
    customChatModels: CanvasModel[]
    customImageModels: CanvasModel[]
    customVideoModels: CanvasModel[]
}

export interface CanvasModel {
    label: string
    key: string
    provider?: string[]
    sizes?: string[]
    ratios?: string[]
    durations?: number[]
    defaultParams?: Record<string, any>
    isCustom?: boolean
}

export interface CanvasProject {
    id: string
    name: string
    thumbnail: string
    createdAt: number
    updatedAt: number
    nodes: Node[]
    edges: Edge[]
    viewport: { x: number; y: number; zoom: number }
}

export interface WorkflowTemplate {
    id: string
    name: string
    description: string
    category: string
    cover?: string
    create: (start: { x: number; y: number }) => {
        nodes: Array<Partial<Node> & { id: string; data: Record<string, any> }>
        edges: Array<Partial<Edge> & { source: string; target: string; data?: Record<string, any> }>
    }
}

export interface DownloadAsset {
    id: string
    title: string
    url: string
    type: 'image' | 'video'
}
