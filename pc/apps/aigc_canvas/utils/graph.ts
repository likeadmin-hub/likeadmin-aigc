import type { Connection, Edge, Node } from '@vue-flow/core'
import type { CanvasNodeVariant } from '../types'
import { createId } from '../stores/projects'

export const CANVAS_NODE_TYPE = 'aigcCanvasNode'

export function createCanvasNode(
    variant: CanvasNodeVariant,
    position = { x: 100, y: 100 },
    data: Record<string, any> = {},
    id = createId(variant)
): Node {
    return {
        id,
        type: CANVAS_NODE_TYPE,
        position,
        data: normalizeNodeData(variant, data)
    }
}

export function normalizeNodeData(variant: CanvasNodeVariant, data: Record<string, any> = {}) {
    const defaults: Record<CanvasNodeVariant, Record<string, any>> = {
        text: { variant, title: '文本输入', content: '', publicName: '' },
        imagePrompt: { variant, title: '图生图提示词', content: '' },
        imageConfig: {
            variant,
            title: '图片生成',
            prompt: '',
            model: '',
            size: '',
            quality: '',
            style: 'vivid',
            mode: 'new',
            promptCount: 0,
            referenceCount: 0,
            status: 'idle',
            outputNodeId: ''
        },
        image: {
            variant,
            title: '图片节点',
            subtitle: '',
            image: '',
            url: '',
            public: true,
            publicName: '图片',
            status: 'idle'
        },
        imageResult: {
            variant,
            title: '图片结果',
            subtitle: '',
            image: '',
            url: '',
            public: true,
            publicName: '图片',
            status: 'idle'
        },
        videoConfig: {
            variant,
            title: '视频生成',
            prompt: '',
            model: '',
            ratio: '',
            duration: '',
            resolution: '720p',
            status: 'idle',
            outputNodeId: ''
        },
        video: {
            variant,
            title: '视频节点',
            url: '',
            poster: '',
            duration: 5,
            taskId: '',
            status: 'idle'
        },
        llmConfig: {
            variant,
            title: 'LLM 文本生成',
            model: '',
            systemPrompt: '你是专业提示词助手。',
            prompt: '',
            outputContent: '',
            outputFormat: 'text',
            imageModel: '',
            imageQuality: '',
            imageSize: '',
            splitStatus: '',
            splitMessage: '',
            splitCount: 0,
            referenceCount: 0,
            status: 'idle'
        }
    }
    return { ...defaults[variant], ...data, variant, updatedAt: Date.now() }
}

export function createCanvasEdge(source: string, target: string, type = 'smoothstep', data: Record<string, any> = {}): Edge {
    return {
        id: `edge_${source}_${target}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
        source,
        target,
        sourceHandle: 'right',
        targetHandle: 'left',
        type,
        label: data.promptOrder ? String(data.promptOrder) : data.imageOrder ? String(data.imageOrder) : '',
        data,
        style: { stroke: '#c9c9c9', strokeWidth: 2 }
    }
}

export function edgeFromConnection(params: Connection, nodes: Node[], edges: Edge[]) {
    if (!params.source || !params.target) return null
    const source = nodes.find((node) => node.id === params.source)
    const target = nodes.find((node) => node.id === params.target)
    const sourceVariant = String(source?.data?.variant || '')
    const targetVariant = String(target?.data?.variant || '')
    const data: Record<string, any> = {}
    let type = 'smoothstep'

    if (['text', 'imagePrompt', 'llmConfig'].includes(sourceVariant) && ['imageConfig', 'videoConfig'].includes(targetVariant)) {
        type = 'promptOrder'
        data.promptOrder = nextOrder(edges, params.target, 'promptOrder')
    }

    if (['image', 'imageResult'].includes(sourceVariant) && ['imageConfig', 'llmConfig'].includes(targetVariant)) {
        type = 'imageOrder'
        data.imageOrder = nextOrder(edges, params.target, 'imageOrder')
    }

    if (['image', 'imageResult'].includes(sourceVariant) && targetVariant === 'videoConfig') {
        type = 'imageRole'
        data.imageRole = nextImageRole(edges, params.target)
        data.imageOrder = nextOrder(edges, params.target, 'imageOrder')
    }

    return createCanvasEdge(params.source, params.target, type, data)
}

export function collectConnectedPrompt(targetId: string, nodes: Node[], edges: Edge[]) {
    return edges
        .filter((edge) => edge.target === targetId)
        .sort((a, b) => Number(a.data?.promptOrder || 0) - Number(b.data?.promptOrder || 0))
        .map((edge) => nodes.find((node) => node.id === edge.source))
        .filter((node) => ['text', 'imagePrompt', 'llmConfig'].includes(String(node?.data?.variant)))
        .map((node) => node?.data?.outputContent || node?.data?.content || node?.data?.prompt || '')
        .filter(Boolean)
        .join('\n\n')
}

export function collectConnectedImages(targetId: string, nodes: Node[], edges: Edge[]) {
    return edges
        .filter((edge) => edge.target === targetId)
        .sort((a, b) => Number(a.data?.imageOrder || 0) - Number(b.data?.imageOrder || 0))
        .map((edge) => ({ edge, node: nodes.find((node) => node.id === edge.source) }))
        .filter(({ node }) => ['image', 'imageResult'].includes(String(node?.data?.variant)) && (node?.data?.image || node?.data?.url))
        .map(({ edge, node }) => ({
            role: edge.data?.imageRole || 'reference_image',
            url: String(node?.data?.image || node?.data?.url || '')
        }))
}

export function publicImageNodes(nodes: Node[]) {
    return nodes.filter((node) => ['image', 'imageResult'].includes(String(node.data?.variant)) && node.data?.public !== false && (node.data?.image || node.data?.url))
}

function nextOrder(edges: Edge[], target: string, key: string) {
    return edges.filter((edge) => edge.target === target && edge.data?.[key]).length + 1
}

function nextImageRole(edges: Edge[], target: string) {
    const hasFirst = edges.some((edge) => edge.target === target && edge.data?.imageRole === 'first_frame_image')
    return hasFirst ? 'last_frame_image' : 'first_frame_image'
}
