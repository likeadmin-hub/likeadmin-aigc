import request from '@/utils/request'

export function getSmartClipPlatformConfig() {
    return request.get({ url: '/app.smart_clip.config/detail' })
}

export function setSmartClipPlatformConfig(params: any) {
    return request.post({ url: '/app.smart_clip.config/setup', params })
}

export function getSmartClipTenantStat(params?: any) {
    return request.get({ url: '/app.smart_clip.tenant/stat', params })
}

export function getSmartClipChannels() {
    return request.get({ url: '/app.smart_clip.channel/lists' })
}

export function saveSmartClipChannel(params: any) {
    return request.post({ url: '/app.smart_clip.channel/save', params })
}

export function deleteSmartClipChannel(params: any) {
    return request.post({ url: '/app.smart_clip.channel/delete', params })
}

export function setSmartClipChannelStatus(params: any) {
    return request.post({ url: '/app.smart_clip.channel/status', params })
}

export function getSmartClipSpecs() {
    return request.get({ url: '/app.smart_clip.spec/lists' })
}

export function saveSmartClipSpec(params: any) {
    return request.post({ url: '/app.smart_clip.spec/save', params })
}

export function batchSaveSmartClipSpecs(params: any) {
    return request.post({ url: '/app.smart_clip.spec/batchSave', params })
}

export function deleteSmartClipSpec(params: any) {
    return request.post({ url: '/app.smart_clip.spec/delete', params })
}

export function setSmartClipSpecStatus(params: any) {
    return request.post({ url: '/app.smart_clip.spec/status', params })
}

export function getSmartClipTaskLogs(params?: any) {
    return request.get({ url: '/app.smart_clip.task/lists', params })
}

export function getSmartClipTaskLogDetail(params: any) {
    return request.get({ url: '/app.smart_clip.task/detail', params })
}
