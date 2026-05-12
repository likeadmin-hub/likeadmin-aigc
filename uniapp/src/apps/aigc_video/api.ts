import request from '@/utils/request'

export function getAigcVideoConfig() {
    return request.get({ url: '/app.aigc_video.config/detail' })
}

export function generateAigcVideo(data: any) {
    return request.post({ url: '/app.aigc_video.generate/index', data })
}

export function estimateAigcVideo(data: any) {
    return request.post({ url: '/app.aigc_video.generate/estimate', data })
}

export function getAigcVideoTasks(data?: any) {
    return request.get({ url: '/app.aigc_video.task/lists', data })
}

export function getAigcVideoTask(data: any) {
    return request.get({ url: '/app.aigc_video.task/detail', data })
}

export function getAigcVideoResults(data?: any) {
    return request.get({ url: '/app.aigc_video.result/lists', data })
}

export function deleteAigcVideoResult(data: any) {
    return request.post({ url: '/app.aigc_video.result/delete', data })
}

export function deleteAigcVideoTask(data: any) {
    return request.post({ url: '/app.aigc_video.result/delete', data })
}
