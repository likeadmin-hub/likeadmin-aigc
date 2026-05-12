import request from '@/utils/request'

// 点数明细
export function accountLog(params?: any) {
    return request.get({ url: '/finance.account_log/lists', params })
}

// 充值记录
export function rechargeLists(params?: any) {
    return request.get({ url: '/recharge.recharge/lists', params }, { ignoreCancelToken: true })
}

// 点数变动类型
export function getUmChangeType(params?: any) {
    return request.get({ url: '/finance.account_log/getUmChangeType', params })
}

//退款
export function refund(params?: any) {
    return request.post({ url: '/recharge.recharge/refund', params })
}

//重新退款
export function refundAgain(params?: any) {
    return request.post({ url: '/recharge.recharge/refundAgain', params })
}

//退款记录
export function refundRecord(params?: any) {
    return request.get({ url: '/finance.refund/record', params })
}

//退款日志
export function refundLog(params?: any) {
    return request.get({ url: '/finance.refund/log', params })
}

//退款统计
export function refundStat(params?: any) {
    return request.get({ url: '/finance.refund/stat', params })
}

// 会员套餐
export function membershipPlanLists(params?: any) {
    return request.get({ url: '/finance.membership_plan/lists', params })
}

export function membershipPlanDetail(params?: any) {
    return request.get({ url: '/finance.membership_plan/detail', params })
}

export function membershipPlanAdd(params?: any) {
    return request.post({ url: '/finance.membership_plan/add', params })
}

export function membershipPlanEdit(params?: any) {
    return request.post({ url: '/finance.membership_plan/edit', params })
}

export function membershipPlanDelete(params?: any) {
    return request.post({ url: '/finance.membership_plan/delete', params })
}

export function membershipPlanApps(params?: any) {
    return request.get({ url: '/finance.membership_plan/apps', params })
}

// 会员订单
export function membershipOrderLists(params?: any) {
    return request.get({ url: '/finance.membership_order/lists', params })
}

export function membershipOrderDetail(params?: any) {
    return request.get({ url: '/finance.membership_order/detail', params })
}

// 算力套餐
export function rechargePackageLists(params?: any) {
    return request.get({ url: '/finance.recharge_package/lists', params })
}

export function rechargePackageDetail(params?: any) {
    return request.get({ url: '/finance.recharge_package/detail', params })
}

export function rechargePackageAdd(params?: any) {
    return request.post({ url: '/finance.recharge_package/add', params })
}

export function rechargePackageEdit(params?: any) {
    return request.post({ url: '/finance.recharge_package/edit', params })
}

export function rechargePackageDelete(params?: any) {
    return request.post({ url: '/finance.recharge_package/delete', params })
}
