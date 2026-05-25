import request from '@/utils/request'

export function getAigcImagePlatformConfig() {
    return request.get({ url: '/app.aigc_image.config/detail' })
}

export function setAigcImagePlatformConfig(params: any) {
    return request.post({ url: '/app.aigc_image.config/setup', params })
}

export function getAigcImageTenantStat(params?: any) {
    return request.get({ url: '/app.aigc_image.tenant/stat', params })
}

export function getAigcImageChannels() {
    return request.get({ url: '/app.aigc_image.channel/lists' })
}

export function saveAigcImageChannel(params: any) {
    return request.post({ url: '/app.aigc_image.channel/save', params })
}

export function deleteAigcImageChannel(params: any) {
    return request.post({ url: '/app.aigc_image.channel/delete', params })
}

export function setAigcImageChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_image.channel/status', params })
}

export function getAigcImageSpecs() {
    return request.get({ url: '/app.aigc_image.spec/lists' })
}

export function saveAigcImageSpec(params: any) {
    return request.post({ url: '/app.aigc_image.spec/save', params })
}

export function batchSaveAigcImageSpecs(params: any) {
    return request.post({ url: '/app.aigc_image.spec/batchSave', params })
}

export function deleteAigcImageSpec(params: any) {
    return request.post({ url: '/app.aigc_image.spec/delete', params })
}

export function setAigcImageSpecStatus(params: any) {
    return request.post({ url: '/app.aigc_image.spec/status', params })
}

export function getAigcImageUpstreamPricingBatch(params: any) {
    return request.post({ url: '/app.aigc_image.pricing/batch', params })
}
