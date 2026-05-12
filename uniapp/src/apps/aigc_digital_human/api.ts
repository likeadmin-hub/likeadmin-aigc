import request from '@/utils/request'

export function getAigcDigitalHumanConfig() {
    return request.get({ url: '/app.aigc_digital_human.config/detail' })
}

export function generateAigcDigitalHuman(data: any) {
    return request.post({ url: '/app.aigc_digital_human.generate/index', data })
}

export function estimateAigcDigitalHuman(data: any) {
    return request.post({ url: '/app.aigc_digital_human.generate/estimate', data })
}

export function getAigcDigitalHumanAvatars(data?: any) {
    return request.get({ url: '/app.aigc_digital_human.avatar/lists', data })
}

export function saveAigcDigitalHumanAvatar(data: any) {
    return request.post({ url: '/app.aigc_digital_human.avatar/save', data })
}

export function getAigcDigitalHumanVoices(data?: any) {
    return request.get({ url: '/app.aigc_digital_human.voice/lists', data })
}

export function saveAigcDigitalHumanVoice(data: any) {
    return request.post({ url: '/app.aigc_digital_human.voice/save', data })
}

export function getAigcDigitalHumanTasks(data?: any) {
    return request.get({ url: '/app.aigc_digital_human.task/lists', data })
}

export function getAigcDigitalHumanTask(data: any) {
    return request.get({ url: '/app.aigc_digital_human.task/detail', data })
}

export function getAigcDigitalHumanResults(data?: any) {
    return request.get({ url: '/app.aigc_digital_human.result/lists', data })
}

export function deleteAigcDigitalHumanResult(data: any) {
    return request.post({ url: '/app.aigc_digital_human.result/delete', data })
}
