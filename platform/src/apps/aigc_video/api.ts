import request from '@/utils/request'

export function getAigcVideoPlatformConfig() {
    return request.get({ url: '/app.aigc_video.config/detail' })
}

export function setAigcVideoPlatformConfig(params: any) {
    return request.post({ url: '/app.aigc_video.config/setup', params })
}

export function getAigcVideoTenantStat(params?: any) {
    return request.get({ url: '/app.aigc_video.tenant/stat', params })
}

export function getAigcVideoChannels() {
    return request.get({ url: '/app.aigc_video.channel/lists' })
}

export function saveAigcVideoChannel(params: any) {
    return request.post({ url: '/app.aigc_video.channel/save', params })
}

export function deleteAigcVideoChannel(params: any) {
    return request.post({ url: '/app.aigc_video.channel/delete', params })
}

export function setAigcVideoChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_video.channel/status', params })
}

export function getAigcVideoSpecs() {
    return request.get({ url: '/app.aigc_video.spec/lists' })
}

export function saveAigcVideoSpec(params: any) {
    return request.post({ url: '/app.aigc_video.spec/save', params })
}

export function batchSaveAigcVideoSpecs(params: any) {
    return request.post({ url: '/app.aigc_video.spec/batchSave', params })
}

export function deleteAigcVideoSpec(params: any) {
    return request.post({ url: '/app.aigc_video.spec/delete', params })
}

export function setAigcVideoSpecStatus(params: any) {
    return request.post({ url: '/app.aigc_video.spec/status', params })
}
