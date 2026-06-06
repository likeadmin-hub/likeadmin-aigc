import request from '@/utils/request'

export function getSmartClipConfig() {
    return request.get({ url: '/app.smart_clip.config/detail' })
}

export function getSmartClipTemplates(data?: any) {
    return request.get({ url: '/app.smart_clip.template/lists', data })
}

export function getSmartClipTemplateDetail(data: any) {
    return request.get({ url: '/app.smart_clip.template/detail', data })
}

export function generateSmartClip(data: any) {
    return request.post({ url: '/app.smart_clip.generate/index', data })
}

export function estimateSmartClip(data: any) {
    return request.post({ url: '/app.smart_clip.generate/estimate', data })
}

export function getSmartClipTasks(data?: any) {
    return request.get({ url: '/app.smart_clip.task/lists', data })
}

export function getSmartClipTask(data: any) {
    return request.get({ url: '/app.smart_clip.task/detail', data })
}

export function getSmartClipResults(data?: any) {
    return request.get({ url: '/app.smart_clip.result/lists', data })
}

export function deleteSmartClipResult(data: any) {
    return request.post({ url: '/app.smart_clip.result/delete', data })
}
