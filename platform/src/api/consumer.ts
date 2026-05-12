import request from '@/utils/request'

/** 租户列表 */
export function getUserList(params: any) {
    return request.get({ url: '/tenant.tenant/lists', params }, { ignoreCancelToken: true })
}

/** 租户详情 */
export function getUserDetail(params: any) {
    return request.get({ url: '/tenant.tenant/detail', params })
}

/** 租户编辑 */
export function userEdit(params: any) {
    return request.post({ url: '/tenant.tenant/edit', params })
}

/** 租户新增 */
export function userAdd(params: any) {
    return request.post({ url: '/tenant.tenant/add', params })
}

/** 租户删除 */
export function userDelete(params: any) {
    return request.post({ url: '/tenant.tenant/delete', params })
}

/** 租户点数充值 */
export function tenantPointRecharge(params: any) {
    return request.post({ url: '/tenant.tenant/rechargePoints', params })
}

/** 租户点数流水 */
export function tenantPointLogs(params?: any) {
    return request.get({ url: '/tenant.tenant/pointLogs', params })
}

/** 租户后台免密登录链接 */
export function tenantSso(params: any) {
    return request.post({ url: '/tenant.tenant/sso', params })
}

/** AI任务记录 */
export function getAiTaskLists(params?: any) {
    return request.get({ url: '/ai_task/lists', params }, { ignoreCancelToken: true })
}

/** AI任务详情 */
export function getAiTaskDetail(params: any) {
    return request.get({ url: '/ai_task/detail', params })
}

/** 租户账号列表 */
export function getTenantAccountList(params: any) {
    return request.get({ url: '/tenant.tenantAdmin/lists', params }, { ignoreCancelToken: true })
}

/** 租户账号详情 */
export function getTenantAccountDetail(params: any) {
    return request.get({ url: '/tenant.tenantAdmin/detail', params })
}

/** 租户账号编辑 */
export function userAccountEdit(params: any) {
    return request.post({ url: '/tenant.tenantAdmin/edit', params })
}

/** 租户账号新增 */
export function userAccountAdd(params: any) {
    return request.post({ url: '/tenant.tenantAdmin/add', params })
}

/** 租户账号删除 */
export function userAccountDelete(params: any) {
    return request.post({ url: '/tenant.tenantAdmin/delete', params })
}

/** 租户用户列表 */
export function getTenantUserList(params: any) {
    return request.get({ url: '/tenant.tenantUser/lists', params }, { ignoreCancelToken: true })
}

/** 租户用户详情 */
export function getTenantUserDetail(params: any) {
    return request.get({ url: '/tenant.tenantUser/detail', params })
}
