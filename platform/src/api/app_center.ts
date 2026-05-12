import request from '@/utils/request'

export function appLists() {
    return request.get({ url: '/app/lists' })
}

export function appDetail(params: any) {
    return request.get({ url: '/app/detail', params })
}

export function appInstall(params: any) {
    return request.post({ url: '/app/install', params })
}

export function appEnable(params: any) {
    return request.post({ url: '/app/enable', params })
}

export function appDisable(params: any) {
    return request.post({ url: '/app/disable', params })
}

export function appUninstall(params: any) {
    return request.post({ url: '/app/uninstall', params })
}

export function appPlans(params: any) {
    return request.get({ url: '/app/plans', params })
}

export function appSavePlan(params: any) {
    return request.post({ url: '/app/savePlan', params })
}

export function appDeletePlan(params: any) {
    return request.post({ url: '/app/deletePlan', params })
}

export function appSaveExpirePolicy(params: any) {
    return request.post({ url: '/app/saveExpirePolicy', params })
}

export function appCloudLists(params?: any) {
    return request.get({ url: '/app/cloudLists', params })
}

export function appCloudDetail(params: any) {
    return request.get({ url: '/app/cloudDetail', params })
}

export function appDownloadPackage(params: any) {
    return request.post({ url: '/app/downloadPackage', params, timeout: 120 * 1000 })
}

export function appPreflightPackage(params: any) {
    return request.post({ url: '/app/preflightPackage', params, timeout: 120 * 1000 })
}

export function appApplyPackage(params: any) {
    return request.post({ url: '/app/applyPackage', params, timeout: 120 * 1000 })
}

export function appUpdateSource() {
    return request.get({ url: '/app/updateSource' })
}

export function appSaveUpdateSource(params: any) {
    return request.post({ url: '/app/saveUpdateSource', params })
}
