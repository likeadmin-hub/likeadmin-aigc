//首页数据
export function getIndex() {
    return $request.get({ url: '/pc/index' })
}

// 装修页面数据
export function getDecorate(params: any) {
    return $request.get({ url: '/index/decorate', params }, { withToken: false })
}
