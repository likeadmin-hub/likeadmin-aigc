export function getPayWay(params: Record<string, any>) {
    return $request.get({ url: '/pay/payWay', params })
}

export function prepay(params: Record<string, any>) {
    return $request.post({ url: '/pay/prepay', params })
}

export function getPayStatus(params: Record<string, any>) {
    return $request.get({ url: '/pay/payStatus', params })
}
