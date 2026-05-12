import request from '@/utils/request'

export function getAigcLlmConfig() {
    return request.get({ url: '/app.aigc_llm.config/detail' })
}

export function setAigcLlmConfig(params: any) {
    return request.post({ url: '/app.aigc_llm.config/setup', params })
}

export function getAigcLlmChannels() {
    return request.get({ url: '/app.aigc_llm.channel/lists' })
}

export function saveAigcLlmChannel(params: any) {
    return request.post({ url: '/app.aigc_llm.channel/save', params })
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

export function setAigcLlmModelStatus(params: any) {
    return request.post({ url: '/app.aigc_llm.model/status', params })
}

export function getAigcLlmAdminSessions(params?: any) {
    return request.get({ url: '/app.aigc_llm.admin_session/lists', params })
}

export function getAigcLlmAdminSessionDetail(params: any) {
    return request.get({ url: '/app.aigc_llm.admin_session/detail', params })
}

export function getAigcLlmSensitiveWords() {
    return request.get({ url: '/app.aigc_llm.admin/sensitiveWord' })
}

export function setAigcLlmSensitiveWord(params: any) {
    return request.post({ url: '/app.aigc_llm.admin/sensitiveWord', params })
}

export function getAigcLlmStat() {
    return request.get({ url: '/app.aigc_llm.admin/stat' })
}
