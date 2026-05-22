import request from '@/utils/request'

export function getImageHumanConfig() {
    return request.get({ url: '/app.image_human.config/detail' })
}

export function setImageHumanConfig(params: any) {
    return request.post({ url: '/app.image_human.config/setup', params })
}

export function getImageHumanTaskLists(params?: any) {
    return request.get({ url: '/app.image_human.admin_task/lists', params })
}

export function getImageHumanTaskDetail(params: any) {
    return request.get({ url: '/app.image_human.admin_task/detail', params })
}

export function deleteImageHumanTask(params: any) {
    return request.post({ url: '/app.image_human.admin_task/delete', params })
}

export function getImageHumanStat() {
    return request.get({ url: '/app.image_human.admin/stat' })
}

export function getImageHumanPublicAvatars(params?: any) {
    return request.get({ url: '/app.image_human.public_avatar/lists', params })
}

export function saveImageHumanPublicAvatar(params: any) {
    return request.post({ url: '/app.image_human.public_avatar/save', params })
}

export function deleteImageHumanPublicAvatar(params: any) {
    return request.post({ url: '/app.image_human.public_avatar/delete', params })
}

export function getImageHumanUserAvatars(params?: any) {
    return request.get({ url: '/app.image_human.user_avatar/lists', params })
}

export function deleteImageHumanUserAvatar(params: any) {
    return request.post({ url: '/app.image_human.user_avatar/delete', params })
}

export function getImageHumanPublicVoices(params?: any) {
    return request.get({ url: '/app.image_human.public_voice/lists', params })
}

export function saveImageHumanPublicVoice(params: any) {
    return request.post({ url: '/app.image_human.public_voice/save', params })
}

export function deleteImageHumanPublicVoice(params: any) {
    return request.post({ url: '/app.image_human.public_voice/delete', params })
}

export function getImageHumanUserVoices(params?: any) {
    return request.get({ url: '/app.image_human.user_voice/lists', params })
}

export function publishImageHumanUserVoice(params: any) {
    return request.post({ url: '/app.image_human.user_voice/publish', params })
}

export function deleteImageHumanUserVoice(params: any) {
    return request.post({ url: '/app.image_human.user_voice/delete', params })
}
