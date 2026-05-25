import type { Edge, Node } from '@vue-flow/core'
import type { CanvasProject } from '../types'

export const PROJECT_STORAGE_KEY = 'likeadmin-aigc-canvas-projects'

const defaultViewport = { x: 100, y: 50, zoom: 0.82 }
const canvasNodeType = 'aigcCanvasNode'

function hasLocalStorage() {
    return typeof window !== 'undefined' && typeof window.localStorage !== 'undefined'
}

export function createId(prefix = 'project') {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`
}

export function cloneDeep<T>(value: T): T {
    return JSON.parse(JSON.stringify(value))
}

export function loadCanvasProjects(): CanvasProject[] {
    if (!hasLocalStorage()) return []
    try {
        const raw = localStorage.getItem(PROJECT_STORAGE_KEY)
        if (!raw) return []
        const parsed = JSON.parse(raw)
        return Array.isArray(parsed) ? parsed.map(normalizeProject).sort((a, b) => b.updatedAt - a.updatedAt) : []
    } catch {
        return []
    }
}

export function saveCanvasProjects(projects: CanvasProject[]) {
    if (!hasLocalStorage()) return
    const cleaned = projects.map(cleanProjectForStorage)
    try {
        localStorage.setItem(PROJECT_STORAGE_KEY, JSON.stringify(cleaned))
    } catch (error: any) {
        if (error?.name !== 'QuotaExceededError') throw error
        const minimal = cleaned.map((project, index) => ({
            ...project,
            thumbnail: '',
            nodes: index > 10 ? [] : project.nodes,
            edges: index > 10 ? [] : project.edges
        }))
        try {
            localStorage.setItem(PROJECT_STORAGE_KEY, JSON.stringify(minimal.slice(0, 20)))
        } catch {
            localStorage.setItem(PROJECT_STORAGE_KEY, JSON.stringify(minimal.slice(0, 5)))
        }
    }
}

export function createCanvasProject(name = '未命名项目', data: Partial<CanvasProject> = {}, prompt = ''): CanvasProject {
    const now = Date.now()
    return {
        id: createId('project'),
        name,
        thumbnail: '',
        nodes: data.nodes || [],
        edges: data.edges || [],
        viewport: data.viewport || defaultViewport,
        createdAt: now,
        updatedAt: now,
        ...data
    }
}

export function ensureProjectCanvas(project: CanvasProject): CanvasProject {
    return normalizeProject({
        ...project,
        viewport: project.viewport || defaultViewport
    })
}

export function duplicateCanvasProject(project: CanvasProject): CanvasProject {
    const copy = cloneDeep(project)
    const now = Date.now()
    return {
        ...copy,
        id: createId('project'),
        name: `${project.name} (副本)`,
        createdAt: now,
        updatedAt: now
    }
}

export function updateProjectThumbnail(project: CanvasProject, nodes: Node[]) {
    const media = [...nodes]
        .filter((node) => ['image', 'imageResult', 'video'].includes(String(node.data?.variant)) && (node.data?.image || node.data?.url || node.data?.poster))
        .sort((a, b) => Number(b.data?.updatedAt || 0) - Number(a.data?.updatedAt || 0))[0]
    if (!media) return
    project.thumbnail = String(media.data?.image || media.data?.poster || media.data?.url || '')
}

function normalizeProject(project: any): CanvasProject {
    const rawNodes = Array.isArray(project.nodes) ? project.nodes : project.canvasData?.nodes || []
    const rawEdges = Array.isArray(project.edges) ? project.edges : project.canvasData?.edges || []
    const isLegacyStarter = isLegacyStarterProject(rawNodes, rawEdges)
    const name = String(project.name || '未命名项目')
    return {
        id: String(project.id || createId('project')),
        name: isLegacyStarter || name.includes('示例') ? '未命名项目' : name,
        thumbnail: isLegacyStarter ? '' : String(project.thumbnail || ''),
        nodes: isLegacyStarter ? [] : rawNodes.map(normalizeStoredNode),
        edges: isLegacyStarter ? [] : rawEdges.map(normalizeStoredEdge),
        viewport: project.viewport || project.canvasData?.viewport || defaultViewport,
        createdAt: Number(project.createdAt || Date.now()),
        updatedAt: Number(project.updatedAt || Date.now())
    }
}

function isLegacyStarterProject(nodes: any[], edges: any[]) {
    if (!Array.isArray(nodes) || nodes.length !== 3 || !Array.isArray(edges) || edges.length !== 2) return false
    const variants = nodes.map((node) => String(node?.data?.variant || node?.type)).sort().join(',')
    if (variants !== 'image,imageConfig,text') return false
    const text = nodes.find((node) => String(node?.data?.variant || node?.type) === 'text')
    const image = nodes.find((node) => String(node?.data?.variant || node?.type) === 'image')
    return String(text?.data?.title || '').includes('示例') && !String(image?.data?.image || image?.data?.url || '')
}

function normalizeStoredNode(node: any): Node {
    const variant = normalizeNodeVariant(node?.data?.variant || node?.type)
    const data = normalizeStoredNodeData(variant, node?.data || {})
    return {
        ...node,
        type: canvasNodeType,
        position: normalizePosition(node?.position),
        data
    }
}

function normalizeNodeVariant(value: any) {
    const variant = String(value || 'text')
    const allowed = ['text', 'imagePrompt', 'imageConfig', 'image', 'imageResult', 'videoConfig', 'video', 'llmConfig']
    return allowed.includes(variant) ? variant : 'text'
}

function normalizePosition(position: any) {
    return {
        x: Number(position?.x ?? 160),
        y: Number(position?.y ?? 150)
    }
}

function normalizeStoredNodeData(variant: string, data: Record<string, any>) {
    const title = data.title || data.label
    const publicName = data.publicName || data.publicProps?.name || data.label || '图片'
    const common = {
        ...data,
        variant,
        title: title || fallbackNodeTitle(variant),
        updatedAt: Number(data.updatedAt || Date.now())
    }

    if (variant === 'text' || variant === 'imagePrompt') {
        return {
            ...common,
            content: data.content || data.prompt || '',
            publicName: data.publicName || ''
        }
    }

    if (variant === 'imageConfig') {
        return {
            ...common,
            prompt: data.prompt || '',
            model: data.model || 'doubao-seedream-4-5-251128',
            size: data.size || data.ratio || '1x1',
            quality: data.quality || 'standard',
            style: data.style || 'vivid',
            mode: data.mode || 'new',
            promptCount: Number(data.promptCount || 0),
            referenceCount: Number(data.referenceCount || 0),
            status: data.status || 'idle',
            outputNodeId: data.outputNodeId || ''
        }
    }

    if (variant === 'image' || variant === 'imageResult') {
        const url = data.image || data.url || data.thumbnail || ''
        return {
            ...common,
            subtitle: data.subtitle || '',
            image: url,
            url,
            public: data.public ?? true,
            publicName,
            status: data.status || 'idle'
        }
    }

    if (variant === 'videoConfig') {
        return {
            ...common,
            prompt: data.prompt || '',
            model: data.model || 'runway-gen3',
            ratio: data.ratio || '16:9',
            quality: data.quality || '',
            duration: Number(data.duration || data.seconds || 5),
            resolution: data.resolution || '720p',
            status: data.status || 'idle',
            outputNodeId: data.outputNodeId || ''
        }
    }

    if (variant === 'video') {
        return {
            ...common,
            url: data.url || '',
            poster: data.poster || data.thumbnail || '',
            duration: Number(data.duration || 5),
            taskId: data.taskId || '',
            status: data.status || 'idle'
        }
    }

    if (variant === 'llmConfig') {
        return {
            ...common,
            model: data.model || '',
            systemPrompt: data.systemPrompt || '',
            prompt: data.prompt || '',
            outputContent: data.outputContent || '',
            outputFormat: data.outputFormat || 'text',
            imageModel: data.imageModel || '',
            imageQuality: data.imageQuality || '',
            imageSize: data.imageSize || '',
            splitStatus: data.splitStatus || '',
            splitMessage: data.splitMessage || '',
            splitCount: Number(data.splitCount || 0),
            referenceCount: Number(data.referenceCount || 0),
            status: data.status || 'idle'
        }
    }

    return common
}

function fallbackNodeTitle(variant: string) {
    return (
        {
            text: '文本输入',
            imagePrompt: '图生图提示词',
            imageConfig: '图片生成',
            image: '图片节点',
            imageResult: '图片结果',
            videoConfig: '视频生成',
            video: '视频节点',
            llmConfig: 'LLM 文本生成'
        } as Record<string, string>
    )[variant] || '节点'
}

function normalizeStoredEdge(edge: any): Edge {
    const data = edge?.data || {}
    return {
        ...edge,
        id: String(edge?.id || createId(`edge_${edge?.source || 'source'}_${edge?.target || 'target'}`)),
        source: String(edge?.source || ''),
        target: String(edge?.target || ''),
        sourceHandle: edge?.sourceHandle || 'right',
        targetHandle: edge?.targetHandle || 'left',
        type: edge?.type || edgeTypeFromData(data),
        label: edge?.label || (data.promptOrder ? String(data.promptOrder) : data.imageOrder ? String(data.imageOrder) : ''),
        data,
        style: edge?.style || { stroke: '#c9c9c9', strokeWidth: 2 }
    }
}

function edgeTypeFromData(data: Record<string, any>) {
    if (data.imageRole) return 'imageRole'
    if (data.imageOrder) return 'imageOrder'
    if (data.promptOrder) return 'promptOrder'
    return 'smoothstep'
}

function cleanProjectForStorage(project: CanvasProject): CanvasProject {
    return {
        ...project,
        thumbnail: project.thumbnail?.startsWith('data:') ? '' : project.thumbnail,
        nodes: project.nodes.map(cleanNodeForStorage),
        edges: project.edges.map(cleanEdgeForStorage)
    }
}

function cleanNodeForStorage(node: Node): Node {
    const data = { ...(node.data || {}) }
    if (typeof data.image === 'string' && data.image.startsWith('data:')) delete data.image
    if (typeof data.url === 'string' && data.url.startsWith('data:')) delete data.url
    delete data.base64
    delete data.maskData
    return { ...node, data }
}

function cleanEdgeForStorage(edge: Edge): Edge {
    return { ...edge }
}

export function formatProjectTime(value: number) {
    return new Date(value).toLocaleString('zh-CN', {
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    })
}
