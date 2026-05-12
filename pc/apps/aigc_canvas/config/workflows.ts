import type { WorkflowTemplate } from '../types'
import { createCanvasEdge, createCanvasNode } from '../utils/graph'
import cover1 from '@/assets/images/ai-app/card-9.png'
import cover2 from '@/assets/images/ai-app/card-10.png'
import cover3 from '@/assets/images/ai-app/card-11.png'
import cover4 from '@/assets/images/ai-app/card-12.png'
import placeholder from '@/assets/images/ai-app/card-8.png'

const templateId = (prefix: string) => `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`

export const WORKFLOW_TEMPLATES: WorkflowTemplate[] = [
    {
        id: 'multi-angle-storyboard',
        name: '多角度分镜',
        description: '先生成统一角色参考，再扩展正视、侧视、后视、俯视分镜。',
        category: 'storyboard',
        cover: cover1,
        create(start) {
            const characterText = templateId('text')
            const characterConfig = templateId('image_config')
            const characterImage = templateId('image')
            const nodes = [
                createCanvasNode('text', { x: start.x, y: start.y + 340 }, { title: '角色设定', content: '输入角色外貌、年龄、服装、气质和世界观，用于生成统一角色参考图。' }, characterText),
                createCanvasNode('imageConfig', { x: start.x + 380, y: start.y + 340 }, { title: '生成角色参考', model: 'doubao-seedream-4-5-251128', size: '2048x2048' }, characterConfig),
                createCanvasNode('image', { x: start.x + 760, y: start.y + 340 }, { title: '角色参考图', image: placeholder, public: true, publicName: '角色参考' }, characterImage)
            ]
            const edges = [
                createCanvasEdge(characterText, characterConfig, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(characterConfig, characterImage)
            ]
            ;[
                ['正视分镜提示词', '正视四宫格', '人物正面对着镜头，远景、中景、近景、局部特写，保持角色一致。'],
                ['侧视分镜提示词', '侧视四宫格', '人物侧面角度，远景、中景、近景、局部特写，保持角色一致。'],
                ['后视分镜提示词', '后视四宫格', '人物背影角度，远景、中景、近景、局部特写，保持角色一致。'],
                ['俯视分镜提示词', '俯视四宫格', '俯视角度，远景、中景、近景、局部特写，保持角色一致。']
            ].forEach(([textTitle, configTitle, content], index) => {
                const textId = templateId('text')
                const configId = templateId('image_config')
                nodes.push(createCanvasNode('text', { x: start.x + 1160, y: start.y + index * 250 }, { title: textTitle, content }, textId))
                nodes.push(createCanvasNode('imageConfig', { x: start.x + 1540, y: start.y + index * 250 }, { title: configTitle, model: 'doubao-seedream-4-5-251128', size: '2048x2048', promptCount: 1, referenceCount: 1 }, configId))
                edges.push(createCanvasEdge(textId, configId, 'promptOrder', { promptOrder: 1 }))
                edges.push(createCanvasEdge(characterImage, configId, 'imageOrder', { imageOrder: 1 }))
            })
            return { nodes, edges }
        }
    },
    {
        id: 'product-ecommerce-full-set',
        name: '产品电商图',
        description: '基于产品信息和产品参考图生成模特图、侧面图、俯视图、拆解图。',
        category: 'ecommerce',
        cover: cover2,
        create(start) {
            const info = templateId('text')
            const product = templateId('image')
            const nodes = [
                createCanvasNode('text', { x: start.x, y: start.y }, { title: '产品信息', content: '填写产品名称、核心卖点、材质、颜色、目标人群、使用场景。' }, info),
                createCanvasNode('image', { x: start.x, y: start.y + 290 }, { title: '产品参考图', image: placeholder, public: true, publicName: '产品图' }, product)
            ]
            const edges: any[] = []
            ;[
                ['模特图提示词', '生成模特图', '适合展示该产品的模特图，白底棚拍，人物自然持有产品，高级商业摄影。'],
                ['侧面展示提示词', '生成侧面图', '产品左侧 45 度展示，保留产品结构与材质细节，干净背景。'],
                ['俯视展示提示词', '生成俯视图', '从上往下俯拍产品，高清细节展示，构图适合电商主图。'],
                ['结构拆解提示词', '生成拆解图', '生成产品核心结构拆解展示，突出卖点，画面清晰有层次。']
            ].forEach(([textTitle, configTitle, content], index) => {
                const textId = templateId('text')
                const configId = templateId('image_config')
                nodes.push(createCanvasNode('text', { x: start.x + 420, y: start.y + index * 260 }, { title: textTitle, content }, textId))
                nodes.push(createCanvasNode('imageConfig', { x: start.x + 800, y: start.y + index * 260 }, { title: configTitle, model: 'doubao-seedream-4-5-251128', size: '2048x2048', promptCount: 2, referenceCount: 1 }, configId))
                edges.push(createCanvasEdge(info, configId, 'promptOrder', { promptOrder: 1 }))
                edges.push(createCanvasEdge(product, configId, 'imageOrder', { imageOrder: 1 }))
                edges.push(createCanvasEdge(textId, configId, 'promptOrder', { promptOrder: 2 }))
            })
            return { nodes, edges }
        }
    },
    {
        id: 'drama-character-design',
        name: '短剧角色设计',
        description: '生成角色正面图，再扩展侧面、背面和生活场景。',
        category: 'drama',
        cover: cover3,
        create(start) {
            const desc = templateId('text')
            const frontPrompt = templateId('text')
            const frontConfig = templateId('image_config')
            const frontImage = templateId('image')
            const nodes = [
                createCanvasNode('text', { x: start.x, y: start.y }, { title: '角色描述', content: '角色名称：林小雨\n性别：女\n年龄：22岁\n外貌特征：长发及腰，现代都市风。' }, desc),
                createCanvasNode('text', { x: start.x + 380, y: start.y }, { title: '正面全身提示词', content: '根据角色描述，生成角色正面全身照，白色简洁背景，高清写实风格，电影级画质。' }, frontPrompt),
                createCanvasNode('imageConfig', { x: start.x + 760, y: start.y }, { title: '生成正面全身图', model: 'doubao-seedream-4-5-251128', size: '1440x2560', promptCount: 2 }, frontConfig),
                createCanvasNode('image', { x: start.x + 1140, y: start.y }, { title: '正面角色参考', image: placeholder, public: true, publicName: '角色参考' }, frontImage)
            ]
            const edges = [
                createCanvasEdge(desc, frontConfig, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(frontPrompt, frontConfig, 'promptOrder', { promptOrder: 2 }),
                createCanvasEdge(frontConfig, frontImage)
            ]
            ;['侧面角色图', '背面角色图', '生活场景图'].forEach((title, index) => {
                const textId = templateId('text')
                const configId = templateId('image_config')
                nodes.push(createCanvasNode('text', { x: start.x + 380, y: start.y + 280 + index * 240 }, { title: `${title}提示词`, content: `基于正面角色参考，生成${title}，保持五官、服装和气质一致。` }, textId))
                nodes.push(createCanvasNode('imageConfig', { x: start.x + 760, y: start.y + 280 + index * 240 }, { title, model: 'doubao-seedream-4-5-251128', size: '1440x2560', promptCount: 1, referenceCount: 1 }, configId))
                edges.push(createCanvasEdge(textId, configId, 'promptOrder', { promptOrder: 1 }))
                edges.push(createCanvasEdge(frontImage, configId, 'imageOrder', { imageOrder: 1 }))
            })
            return { nodes, edges }
        }
    },
    {
        id: 'drama-scene-background',
        name: '多时段场景背景',
        description: '生成基础场景后，扩展傍晚、夜晚、雨天等同构场景。',
        category: 'drama',
        cover: cover4,
        create(start) {
            const scene = templateId('text')
            const basePrompt = templateId('text')
            const baseConfig = templateId('image_config')
            const baseImage = templateId('image')
            const nodes = [
                createCanvasNode('text', { x: start.x, y: start.y }, { title: '场景描述', content: '现代都市街角，便利店门口，玻璃橱窗，街边路灯，适合短剧拍摄。' }, scene),
                createCanvasNode('text', { x: start.x + 380, y: start.y }, { title: '基础场景提示词', content: '生成白天版本场景背景，真实摄影质感，无人物，构图适合人物站位。' }, basePrompt),
                createCanvasNode('imageConfig', { x: start.x + 760, y: start.y }, { title: '基础场景图', model: 'doubao-seedream-4-5-251128', size: '2560x1440', promptCount: 2 }, baseConfig),
                createCanvasNode('image', { x: start.x + 1140, y: start.y }, { title: '基础场景参考', image: placeholder, public: true, publicName: '基础场景' }, baseImage)
            ]
            const edges = [
                createCanvasEdge(scene, baseConfig, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(basePrompt, baseConfig, 'promptOrder', { promptOrder: 2 }),
                createCanvasEdge(baseConfig, baseImage)
            ]
            ;[
                ['傍晚场景提示词', '傍晚场景图', '将基础场景改为傍晚暖色夕阳光线，保持建筑和布局一致。'],
                ['夜晚场景提示词', '夜晚场景图', '将基础场景改为夜晚霓虹灯光，保持建筑和布局一致。'],
                ['雨天场景提示词', '雨天场景图', '将基础场景改为雨天湿润路面，保持建筑和布局一致。']
            ].forEach(([textTitle, configTitle, content], index) => {
                const textId = templateId('text')
                const configId = templateId('image_config')
                nodes.push(createCanvasNode('text', { x: start.x + 380, y: start.y + 280 + index * 240 }, { title: textTitle, content }, textId))
                nodes.push(createCanvasNode('imageConfig', { x: start.x + 760, y: start.y + 280 + index * 240 }, { title: configTitle, model: 'doubao-seedream-4-5-251128', size: '2560x1440', promptCount: 1, referenceCount: 1 }, configId))
                edges.push(createCanvasEdge(textId, configId, 'promptOrder', { promptOrder: 1 }))
                edges.push(createCanvasEdge(baseImage, configId, 'imageOrder', { imageOrder: 1 }))
            })
            return { nodes, edges }
        }
    },
    {
        id: 'picture-book-generator',
        name: '儿童绘本生成',
        description: '用 LLM 拆解故事、角色和分镜，再串联绘本页生成节点。',
        category: 'creative',
        cover: cover1,
        create(start) {
            const story = templateId('text')
            const characterLlm = templateId('llm')
            const characterConfig = templateId('image_config')
            const characterImage = templateId('image')
            const pageLlm = templateId('llm')
            const pageConfig = templateId('image_config')
            const nodes = [
                createCanvasNode('text', { x: start.x, y: start.y }, { title: '绘本故事', content: '输入绘本主题、主角、年龄段、画风和故事梗概。' }, story),
                createCanvasNode('llmConfig', { x: start.x + 380, y: start.y }, { title: '拆解角色设定', systemPrompt: '把故事拆解成适合绘本角色设计的提示词，直接输出。' }, characterLlm),
                createCanvasNode('imageConfig', { x: start.x + 760, y: start.y }, { title: '生成角色图', model: 'doubao-seedream-4-5-251128', size: '2048x2048', promptCount: 1 }, characterConfig),
                createCanvasNode('image', { x: start.x + 1140, y: start.y }, { title: '绘本角色参考', image: placeholder, public: true, publicName: '绘本角色' }, characterImage),
                createCanvasNode('llmConfig', { x: start.x + 380, y: start.y + 300 }, { title: '拆解绘本页', systemPrompt: '将故事拆成 4 页绘本画面提示词，每页一段，适合儿童绘本。' }, pageLlm),
                createCanvasNode('imageConfig', { x: start.x + 760, y: start.y + 300 }, { title: '生成绘本页', model: 'doubao-seedream-4-5-251128', size: '2048x2048', promptCount: 1, referenceCount: 1 }, pageConfig)
            ]
            const edges = [
                createCanvasEdge(story, characterLlm, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(characterLlm, characterConfig, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(characterConfig, characterImage),
                createCanvasEdge(story, pageLlm, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(pageLlm, pageConfig, 'promptOrder', { promptOrder: 1 }),
                createCanvasEdge(characterImage, pageConfig, 'imageOrder', { imageOrder: 1 })
            ]
            return { nodes, edges }
        }
    }
]

export function workflowCategories() {
    return Array.from(new Set(WORKFLOW_TEMPLATES.map((item) => item.category)))
}
