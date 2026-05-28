import {
    mobileWidgetDefinitions,
    mobileWidgetRegistry,
    cloneJson,
    createWidgetId
} from '../decoration-core/index'

export { mobileWidgetDefinitions, mobileWidgetRegistry, cloneJson, createWidgetId }

export const createDefaultMobileWidget = (name: string) => {
    const definition = mobileWidgetRegistry[name]
    return definition ? cloneJson(definition.options()) : null
}

export const normalizeMobileDecorationWidgets = (value: any[]) =>
    (Array.isArray(value) ? value : []).map((item, index) => {
        const defaults = createDefaultMobileWidget(item?.name) || {}
        return {
            ...defaults,
            ...item,
            id: item?.id || createWidgetId(item?.name || 'mobile'),
            title: item?.title || defaults.title || item?.name,
            content: {
                enabled: 1,
                ...(defaults.content || {}),
                ...(item?.content || {})
            },
            styles: {
                ...(defaults.styles || {}),
                ...(item?.styles || {})
            },
            sort: item?.sort ?? index
        }
    })
