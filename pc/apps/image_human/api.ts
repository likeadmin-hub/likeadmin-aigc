export function estimateImageHuman(params: any) {
    return $request.post({ url: '/app.image_human.generate/estimate', params })
}

export function submitImageHuman(params: any) {
    return $request.post({ url: '/app.image_human.generate/submit', params })
}

export function getImageHumanConfig() {
    return $request.get({ url: '/app.image_human.config/detail' })
}

export function getImageHumanTasks(params?: any, requestOptions?: any) {
    return $request.get({ url: '/app.image_human.task/lists', params }, requestOptions)
}

export function getImageHumanTask(params: any) {
    return $request.get({ url: '/app.image_human.task/detail', params })
}

export function getImageHumanResults(params?: any, requestOptions?: any) {
    return $request.get({ url: '/app.image_human.result/lists', params }, requestOptions)
}

export function deleteImageHumanResult(params: any) {
    return $request.post({ url: '/app.image_human.result/delete', params })
}

export function getImageHumanAvatars(params?: any) {
    return $request.get({ url: '/app.image_human.avatar/lists', params })
}

export function saveImageHumanAvatar(params: any) {
    return $request.post({ url: '/app.image_human.avatar/save', params })
}

export function deleteImageHumanAvatar(params: any) {
    return $request.post({ url: '/app.image_human.user_avatar/delete', params })
}

export function getImageHumanVoices(params?: any) {
    return $request.get({ url: '/app.image_human.voice/lists', params })
}

export function saveImageHumanVoice(params: any) {
    return $request.post({ url: '/app.image_human.voice/save', params })
}

export function deleteImageHumanVoice(params: any) {
    return $request.post({ url: '/app.image_human.voice/delete', params })
}

export function previewImageHumanVoice(params: any) {
    return $request.post({ url: '/app.image_human.voice/preview', params })
}
