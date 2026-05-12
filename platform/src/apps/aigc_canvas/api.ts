import request from '@/utils/request'

export function getAigcCanvasTenantStat(params?: any) {
    return request.get({ url: '/app.aigc_canvas.tenant/stat', params })
}

export function getAigcCanvasTenantLists(params?: any) {
    return request.get({ url: '/app.aigc_canvas.tenant/lists', params })
}

export function getAigcCanvasDependencies(params?: any) {
    return request.get({ url: '/app.aigc_canvas.config/dependencies', params })
}
