import request from '@/utils/request'

export function getMembershipPlans() {
    return request.get({ url: '/membership/plans' })
}

export function getMembershipStatus() {
    return request.get({ url: '/membership/status' }, { isAuth: true })
}

export function getMembershipAppAccess(data: any) {
    return request.get({ url: '/membership/appAccess', data }, { isAuth: true })
}

export function createMembershipOrder(data: any) {
    return request.post({ url: '/membership.order/create', data }, { isAuth: true })
}

export function getMembershipOrder(data: any) {
    return request.get({ url: '/membership.order/detail', data }, { isAuth: true })
}
