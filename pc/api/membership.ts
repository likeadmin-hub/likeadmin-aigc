export function getMembershipPlans() {
    return $request.get({ url: '/membership/plans' })
}

export function getMembershipStatus() {
    return $request.get({ url: '/membership/status' })
}

export function getMembershipAppAccess(params: Record<string, any>) {
    return $request.get({ url: '/membership/appAccess', params })
}

export function createMembershipOrder(params: Record<string, any>) {
    return $request.post({ url: '/membership.order/create', params })
}

export function getMembershipOrder(params: Record<string, any>) {
    return $request.get({ url: '/membership.order/detail', params })
}
