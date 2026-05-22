import request from '@/utils/request'
import { ContentTypeEnum } from '@/enums/requestEnums'

const UPDATE_REQUEST_TIMEOUT = 10 * 60 * 1000

export function updateOverview() {
    return request.get({ url: '/upgrade.upgrade/overview' })
}

export function updateCloudVersions(params?: any) {
    return request.get({ url: '/upgrade.upgrade/cloudVersions', params })
}

export function updateDownloadPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/downloadCloudPackage', params, timeout: UPDATE_REQUEST_TIMEOUT })
}

export function updatePreflightPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/preflightPackage', params, timeout: UPDATE_REQUEST_TIMEOUT })
}

export function updateApplyPackage(params: any) {
    return request.post({ url: '/upgrade.upgrade/applyPackage', params, timeout: UPDATE_REQUEST_TIMEOUT })
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

export function updateLicenseImport(params: FormData) {
    return request.post(
        { url: '/upgrade.upgrade/license_import', params, headers: { 'Content-Type': ContentTypeEnum.FORM_DATA } },
        { isReturnDefaultResponse: true }
    )
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
