import request from '@/utils/request'

export function getAigcVideoConfig() {
    return request.get({ url: '/app.aigc_video.config/detail' })
}

export function setAigcVideoConfig(params: any) {
    return request.post({ url: '/app.aigc_video.config/setup', params })
}

export function getAigcVideoTaskLists(params?: any) {
    return request.get({ url: '/app.aigc_video.admin_task/lists', params })
}

export function getAigcVideoTaskDetail(params: any) {
    return request.get({ url: '/app.aigc_video.admin_task/detail', params })
}

export function retryAigcVideoTask(params: any) {
    return request.post({ url: '/app.aigc_video.admin_task/retry', params })
}

export function deleteAigcVideoTask(params: any) {
    return request.post({ url: '/app.aigc_video.admin_task/delete', params })
}

export function getAigcVideoCases(params?: any) {
    return request.get({ url: '/app.aigc_video.case/lists', params })
}

export function getAigcVideoCaseDetail(params: any) {
    return request.get({ url: '/app.aigc_video.case/detail', params })
}

export function saveAigcVideoCase(params: any) {
    return request.post({ url: '/app.aigc_video.case/save', params })
}

export function createAigcVideoCaseFromTask(params: any) {
    return request.post({ url: '/app.aigc_video.case/fromTask', params })
}

export function setAigcVideoCaseStatus(params: any) {
    return request.post({ url: '/app.aigc_video.case/status', params })
}

export function deleteAigcVideoCase(params: any) {
    return request.post({ url: '/app.aigc_video.case/delete', params })
}

export function getAigcVideoQuota() {
    return request.get({ url: '/app.aigc_video.admin/quota' })
}

export function setAigcVideoQuota(params: any) {
    return request.post({ url: '/app.aigc_video.admin/quota', params })
}

export function getAigcVideoSensitiveWords() {
    return request.get({ url: '/app.aigc_video.admin/sensitiveWord' })
}

export function setAigcVideoSensitiveWord(params: any) {
    return request.post({ url: '/app.aigc_video.admin/sensitiveWord', params })
}

export function getAigcVideoStat() {
    return request.get({ url: '/app.aigc_video.admin/stat' })
}

export function getAigcVideoChannels() {
    return request.get({ url: '/app.aigc_video.channel/lists' })
}

export function saveAigcVideoChannel(params: any) {
    return request.post({ url: '/app.aigc_video.channel/save', params })
}

export function setAigcVideoChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_video.channel/status', params })
}
