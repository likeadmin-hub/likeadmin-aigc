import request from '@/utils/request'

export function getAigcImageConfig() {
    return request.get({ url: '/app.aigc_image.config/detail' })
}

export function generateAigcImage(data: any) {
    return request.post({ url: '/app.aigc_image.generate/index', data })
}

export function estimateAigcImage(data: any) {
    return request.post({ url: '/app.aigc_image.generate/estimate', data })
}

export function getAigcImageTasks(data?: any) {
    return request.get({ url: '/app.aigc_image.task/lists', data })
}

export function getAigcImageTask(data: any) {
    return request.get({ url: '/app.aigc_image.task/detail', data })
}

export function getAigcImageResults(data?: any) {
    return request.get({ url: '/app.aigc_image.result/lists', data })
}

export function deleteAigcImageResult(data: any) {
    return request.post({ url: '/app.aigc_image.result/delete', data })
}

export function deleteAigcImageTask(data: any) {
    return request.post({ url: '/app.aigc_image.result/delete', data })
}
