import request from '@/utils/request'

export function getImageHumanPlatformConfig() {
    return request.get({ url: '/app.image_human.config/detail' })
}

export function setImageHumanPlatformConfig(params: any) {
    return request.post({ url: '/app.image_human.config/setup', params })
}

export function getImageHumanTenantStat(params?: any) {
    return request.get({ url: '/app.image_human.tenant/stat', params })
}

export function getImageHumanTaskLogs(params?: any) {
    return request.get({ url: '/app.image_human.task_log/lists', params })
}

export function getImageHumanTaskLogDetail(params: any) {
    return request.get({ url: '/app.image_human.task_log/detail', params })
}
