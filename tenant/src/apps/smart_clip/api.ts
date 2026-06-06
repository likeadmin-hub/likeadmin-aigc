import request from '@/utils/request'

export function getSmartClipConfig() {
    return request.get({ url: '/app.smart_clip.config/detail' })
}

export function setSmartClipConfig(params: any) {
    return request.post({ url: '/app.smart_clip.config/setup', params })
}

export function getSmartClipTaskLists(params?: any) {
    return request.get({ url: '/app.smart_clip.admin_task/lists', params })
}

export function getSmartClipTaskDetail(params: any) {
    return request.get({ url: '/app.smart_clip.admin_task/detail', params })
}

export function retrySmartClipTask(params: any) {
    return request.post({ url: '/app.smart_clip.admin_task/retry', params })
}

export function deleteSmartClipTask(params: any) {
    return request.post({ url: '/app.smart_clip.admin_task/delete', params })
}

export function getSmartClipSensitiveWords(params?: any) {
    return request.get({ url: '/app.smart_clip.admin/sensitiveWord', params })
}

export function setSmartClipSensitiveWord(params: any) {
    return request.post({ url: '/app.smart_clip.admin/sensitiveWord', params })
}

export function getSmartClipStat() {
    return request.get({ url: '/app.smart_clip.admin/stat' })
}

export function getSmartClipChannels() {
    return request.get({ url: '/app.smart_clip.channel/lists' })
}

export function saveSmartClipChannel(params: any) {
    return request.post({ url: '/app.smart_clip.channel/save', params })
}

export function batchSaveSmartClipChannels(params: any) {
    return request.post({ url: '/app.smart_clip.channel/batchSave', params })
}

export function setSmartClipChannelStatus(params: any) {
    return request.post({ url: '/app.smart_clip.channel/status', params })
}
