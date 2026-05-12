import request from '@/utils/request'

export function getAigcCanvasStat() {
    return request.get({ url: '/app.aigc_canvas.admin/stat' })
}

export function getAigcCanvasProjects(params?: any) {
    return request.get({ url: '/app.aigc_canvas.admin_project/lists', params })
}

export function deleteAigcCanvasProject(params: any) {
    return request.post({ url: '/app.aigc_canvas.admin_project/delete', params })
}

export function clearAigcCanvasProjects(params?: any) {
    return request.post({ url: '/app.aigc_canvas.admin_project/clear', params })
}

export function getAigcCanvasRuns(params?: any) {
    return request.get({ url: '/app.aigc_canvas.admin_run/lists', params })
}

export function getAigcCanvasDependencies() {
    return request.get({ url: '/app.aigc_canvas.config/dependencies' })
}
