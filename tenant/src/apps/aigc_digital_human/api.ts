import request from '@/utils/request'

export function getAigcDigitalHumanConfig() {
    return request.get({ url: '/app.aigc_digital_human.config/detail' })
}

export function setAigcDigitalHumanConfig(params: any) {
    return request.post({ url: '/app.aigc_digital_human.config/setup', params })
}

export function getAigcDigitalHumanTaskLists(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.admin_task/lists', params })
}

export function getAigcDigitalHumanTaskDetail(params: any) {
    return request.get({ url: '/app.aigc_digital_human.admin_task/detail', params })
}

export function retryAigcDigitalHumanTask(params: any) {
    return request.post({ url: '/app.aigc_digital_human.admin_task/retry', params })
}

export function deleteAigcDigitalHumanTask(params: any) {
    return request.post({ url: '/app.aigc_digital_human.admin_task/delete', params })
}

export function getAigcDigitalHumanCases(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.case/lists', params })
}

export function getAigcDigitalHumanCaseDetail(params: any) {
    return request.get({ url: '/app.aigc_digital_human.case/detail', params })
}

export function saveAigcDigitalHumanCase(params: any) {
    return request.post({ url: '/app.aigc_digital_human.case/save', params })
}

export function createAigcDigitalHumanCaseFromTask(params: any) {
    return request.post({ url: '/app.aigc_digital_human.case/fromTask', params })
}

export function setAigcDigitalHumanCaseStatus(params: any) {
    return request.post({ url: '/app.aigc_digital_human.case/status', params })
}

export function deleteAigcDigitalHumanCase(params: any) {
    return request.post({ url: '/app.aigc_digital_human.case/delete', params })
}

export function getAigcDigitalHumanQuota() {
    return request.get({ url: '/app.aigc_digital_human.admin/quota' })
}

export function setAigcDigitalHumanQuota(params: any) {
    return request.post({ url: '/app.aigc_digital_human.admin/quota', params })
}

export function getAigcDigitalHumanSensitiveWords() {
    return request.get({ url: '/app.aigc_digital_human.admin/sensitiveWord' })
}

export function setAigcDigitalHumanSensitiveWord(params: any) {
    return request.post({ url: '/app.aigc_digital_human.admin/sensitiveWord', params })
}

export function getAigcDigitalHumanStat() {
    return request.get({ url: '/app.aigc_digital_human.admin/stat' })
}

export function getAigcDigitalHumanChannels() {
    return request.get({ url: '/app.aigc_digital_human.channel/lists' })
}

export function saveAigcDigitalHumanChannel(params: any) {
    return request.post({ url: '/app.aigc_digital_human.channel/save', params })
}

export function setAigcDigitalHumanChannelStatus(params: any) {
    return request.post({ url: '/app.aigc_digital_human.channel/status', params })
}

export function getAigcDigitalHumanPublicAvatars(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.public_avatar/lists', params })
}

export function saveAigcDigitalHumanPublicAvatar(params: any) {
    return request.post({ url: '/app.aigc_digital_human.public_avatar/save', params })
}

export function deleteAigcDigitalHumanPublicAvatar(params: any) {
    return request.post({ url: '/app.aigc_digital_human.public_avatar/delete', params })
}

export function getAigcDigitalHumanPublicVoices(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.public_voice/lists', params })
}

export function saveAigcDigitalHumanPublicVoice(params: any) {
    return request.post({ url: '/app.aigc_digital_human.public_voice/save', params })
}

export function deleteAigcDigitalHumanPublicVoice(params: any) {
    return request.post({ url: '/app.aigc_digital_human.public_voice/delete', params })
}

export function getAigcDigitalHumanUserAvatars(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.user_avatar/lists', params })
}

export function deleteAigcDigitalHumanUserAvatar(params: any) {
    return request.post({ url: '/app.aigc_digital_human.user_avatar/delete', params })
}

export function getAigcDigitalHumanUserVoices(params?: any) {
    return request.get({ url: '/app.aigc_digital_human.user_voice/lists', params })
}

export function publishAigcDigitalHumanUserVoice(params: any) {
    return request.post({ url: '/app.aigc_digital_human.user_voice/publish', params })
}

export function deleteAigcDigitalHumanUserVoice(params: any) {
    return request.post({ url: '/app.aigc_digital_human.user_voice/delete', params })
}

export function getAigcDigitalHumanPricing() {
    return request.get({ url: '/app.aigc_digital_human.pricing/detail' })
}

export function setAigcDigitalHumanPricing(params: any) {
    return request.post({ url: '/app.aigc_digital_human.pricing/setup', params })
}
