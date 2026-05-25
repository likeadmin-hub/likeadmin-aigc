import request from '@/utils/request'

export function getAigcDigitalHumanPlatformConfig() {
    return request.get({ url: '/app.aigc_digital_human.config/detail' })
}

export function setAigcDigitalHumanPlatformConfig(params: any) {
    return request.post({ url: '/app.aigc_digital_human.config/setup', params })
}

export function getAigcDigitalHumanTenantStat(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.tenant/stat', params })
}

export function getAigcDigitalHumanChannels() {
    return request.get({ url: '/app.aigc_digital_human.channel/lists' })
}

export function saveAigcDigitalHumanChannel(params: any) {
    return request.post({ url: '/app.aigc_digital_human.channel/save', params })
}

export function deleteAigcDigitalHumanChannel(params: any) {
    return request.post({ url: '/app.aigc_digital_human.channel/delete', params })
}

export function setAigcDigitalHumanChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_digital_human.channel/status', params })
}

export function getAigcDigitalHumanSpecs() {
    return request.get({ url: '/app.aigc_digital_human.spec/lists' })
}

export function saveAigcDigitalHumanSpec(params: any) {
    return request.post({ url: '/app.aigc_digital_human.spec/save', params })
}

export function deleteAigcDigitalHumanSpec(params: any) {
    return request.post({ url: '/app.aigc_digital_human.spec/delete', params })
}

export function setAigcDigitalHumanSpecStatus(params: any) {
    return request.post({ url: '/app.aigc_digital_human.spec/status', params })
}

export function getAigcDigitalHumanPricing() {
    return request.get({ url: '/app.aigc_digital_human.pricing/detail' })
}

export function setAigcDigitalHumanPricing(params: any) {
    return request.post({ url: '/app.aigc_digital_human.pricing/setup', params })
}

export function getAigcDigitalHumanUpstreamPricing(params: any) {
    return request.get({ url: '/app.aigc_digital_human.pricing_query/model', params })
}

export function getAigcDigitalHumanUpstreamClonePricing() {
    return request.get({ url: '/app.aigc_digital_human.pricing_query/clone' })
}

export function getAigcDigitalHumanTaskLogs(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.task_log/lists', params })
}

export function getAigcDigitalHumanTaskLogDetail(params: any) {
    return request.get({ url: '/app.aigc_digital_human.task_log/detail', params })
}
