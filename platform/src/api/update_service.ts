import request from '@/utils/request'

export function updateOverview() {
    return request.get({ url: '/upgrade.upgrade/overview' })
}

export function updateCloudVersions(params?: any) {
    return request.get({ url: '/upgrade.upgrade/cloudVersions', params })
}

export function updateDownloadPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/downloadCloudPackage', params, timeout: 120 * 1000 })
}

export function updatePreflightPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/preflightPackage', params, timeout: 120 * 1000 })
}

export function updateApplyPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/applyPackage', params, timeout: 120 * 1000 })
}

export function updateIgnoreVersion(params: any) {
    return request.post({ url: '/upgrade.upgrade/ignoreVersion', params })
}

export function updateLicenseInfo() {
    return request.get({ url: '/upgrade.upgrade/licenseInfo' })
}

export function updateMachineCode() {
    return request.get({ url: '/upgrade.upgrade/machineCode' })
}

export function updateSource() {
    return request.get({ url: '/upgrade.upgrade/source' })
}

export function updateSaveSource(params: any) {
    return request.post({ url: '/upgrade.upgrade/saveSource', params })
}

export function updateLogs(params: any) {
    return request.get({ url: '/upgrade.upgrade/logs', params }, { ignoreCancelToken: true })
}
