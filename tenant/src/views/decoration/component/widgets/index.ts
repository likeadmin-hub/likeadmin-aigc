const widgets: Record<string, any> = import.meta.glob('./**/index.ts', { eager: true })
interface Widget {
    attr?: any
    content: any
    options: any
    category?: string
    icon?: string
    repeatable?: boolean
    terminal?: string[]
    support_channels?: string[]
}

const exportWidgets: Record<string, Widget> = {}
Object.keys(widgets).forEach((key) => {
    const widgetName = key.replace(/^\.\/([\w-]+).*/gi, '$1')
    exportWidgets[widgetName] = widgets[key]?.default
})

export default exportWidgets
