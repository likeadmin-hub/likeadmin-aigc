export function getAigcDigitalHumanConfig() {
    return $request.get({ url: '/app.aigc_digital_human.config/detail' })
}

export function getAigcDigitalHumanCases(params?: any, requestOptions?: any) {
    return $request.get({ url: '/app.aigc_digital_human.case/lists', params }, requestOptions)
}

export function generateAigcDigitalHuman(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.generate/index', params })
}

export function estimateAigcDigitalHuman(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.generate/estimate', params })
}

export function assistAigcDigitalHumanScript(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.generate/assistScript', params })
}

export function getAigcDigitalHumanAvatars(params?: any) {
    return $request.get({ url: '/app.aigc_digital_human.avatar/lists', params })
}

export function saveAigcDigitalHumanAvatar(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.avatar/save', params })
}

export function deleteAigcDigitalHumanAvatar(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.user_avatar/delete', params })
}

export function getAigcDigitalHumanVoices(params?: any) {
    return $request.get({ url: '/app.aigc_digital_human.voice/lists', params })
}

export function saveAigcDigitalHumanVoice(params: any) {
    return $request.post({
        url: '/app.aigc_digital_human.voice/save',
        params,
        timeout: 60 * 1000,
        retry: 0
    })
}

export function deleteAigcDigitalHumanVoice(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.user_voice/delete', params })
}

export function previewAigcDigitalHumanVoice(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.voice/preview', params })
}

export function trimAigcDigitalHumanVoice(params: any) {
    return $request.uploadFile(
        {
            url: '/app.aigc_digital_human.voice/trim',
            timeout: 60 * 1000,
            retry: 0
        },
        params
    )
}

export function getAigcDigitalHumanTasks(params?: any) {
    return $request.get({ url: '/app.aigc_digital_human.task/lists', params })
}

export function getAigcDigitalHumanTask(params: any) {
    return $request.get({ url: '/app.aigc_digital_human.task/detail', params })
}

export function getAigcDigitalHumanResults(params?: any) {
    return $request.get({ url: '/app.aigc_digital_human.result/lists', params })
}

export function deleteAigcDigitalHumanResult(params: any) {
    return $request.post({ url: '/app.aigc_digital_human.result/delete', params })
}
