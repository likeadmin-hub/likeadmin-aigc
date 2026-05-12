import request from '@/utils/request'

export function getAigcImageConfig() {
    return request.get({ url: '/app.aigc_image.config/detail' })
}

export function setAigcImageConfig(params: any) {
    return request.post({ url: '/app.aigc_image.config/setup', params })
}

export function getAigcImageTaskLists(params?: any) {
    return request.get({ url: '/app.aigc_image.admin_task/lists', params })
}

export function getAigcImageTaskDetail(params: any) {
    return request.get({ url: '/app.aigc_image.admin_task/detail', params })
}

export function retryAigcImageTask(params: any) {
    return request.post({ url: '/app.aigc_image.admin_task/retry', params })
}

export function deleteAigcImageTask(params: any) {
    return request.post({ url: '/app.aigc_image.admin_task/delete', params })
}

export function getAigcImageCases(params?: any) {
    return request.get({ url: '/app.aigc_image.case/lists', params })
}

export function getAigcImageCaseDetail(params: any) {
    return request.get({ url: '/app.aigc_image.case/detail', params })
}

export function saveAigcImageCase(params: any) {
    return request.post({ url: '/app.aigc_image.case/save', params })
}

export function createAigcImageCaseFromTask(params: any) {
    return request.post({ url: '/app.aigc_image.case/fromTask', params })
}

export function setAigcImageCaseStatus(params: any) {
    return request.post({ url: '/app.aigc_image.case/status', params })
}

export function deleteAigcImageCase(params: any) {
    return request.post({ url: '/app.aigc_image.case/delete', params })
}

export function getAigcImageQuota() {
    return request.get({ url: '/app.aigc_image.admin/quota' })
}

export function setAigcImageQuota(params: any) {
    return request.post({ url: '/app.aigc_image.admin/quota', params })
}

export function getAigcImageSensitiveWords() {
    return request.get({ url: '/app.aigc_image.admin/sensitiveWord' })
}

export function setAigcImageSensitiveWord(params: any) {
    return request.post({ url: '/app.aigc_image.admin/sensitiveWord', params })
}

export function getAigcImageStat() {
    return request.get({ url: '/app.aigc_image.admin/stat' })
}

export function getAigcImageChannels() {
    return request.get({ url: '/app.aigc_image.channel/lists' })
}

export function saveAigcImageChannel(params: any) {
    return request.post({ url: '/app.aigc_image.channel/save', params })
}

export function setAigcImageChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_image.channel/status', params })
}
