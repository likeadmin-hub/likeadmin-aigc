//首页数据
export function getIndex(params?: any) {
    return $request.get({ url: '/pc/index', params })
}

// 装修页面数据
export function getDecorate(params: any) {
    return $request.get({ url: '/index/decorate', params }, { withToken: false })
}
