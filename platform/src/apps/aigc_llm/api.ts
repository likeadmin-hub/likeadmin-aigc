import request from '@/utils/request'

export function getAigcLlmPlatformConfig() {
    return request.get({ url: '/app.aigc_llm.config/detail' })
}

export function setAigcLlmPlatformConfig(params: any) {
    return request.post({ url: '/app.aigc_llm.config/setup', params })
}

export function getAigcLlmTenantStat(params?: any) {
    return request.get({ url: '/app.aigc_llm.tenant/stat', params })
}

export function getAigcLlmChannels() {
    return request.get({ url: '/app.aigc_llm.channel/lists' })
}

export function saveAigcLlmChannel(params: any) {
    return request.post({ url: '/app.aigc_llm.channel/save', params })
}

export function deleteAigcLlmChannel(params: any) {
    return request.post({ url: '/app.aigc_llm.channel/delete', params })
}

export function setAigcLlmChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_llm.channel/status', params })
}

export function getAigcLlmModels() {
    return request.get({ url: '/app.aigc_llm.model/lists' })
}

export function saveAigcLlmModel(params: any) {
    return request.post({ url: '/app.aigc_llm.model/save', params })
}

export function deleteAigcLlmModel(params: any) {
    return request.post({ url: '/app.aigc_llm.model/delete', params })
}

export function setAigcLlmModelStatus(params: any) {
    return request.post({ url: '/app.aigc_llm.model/status', params })
}

export function getAigcLlmUpstreamModelPricing(params: any) {
    return request.get({ url: '/app.aigc_llm.pricing/model', params })
}
