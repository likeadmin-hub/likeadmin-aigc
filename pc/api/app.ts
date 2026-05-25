//发送短信
export function smsSend(params: any) {
    return $request.post({ url: '/sms/sendCode', params }, { withToken: false })
}

// 获取配置
export function getConfig(params?: Record<string, any>) {
    return $request.get({ url: '/pc/config', params, retry: 0 })
}

// 获取协议
export function getPolicy(params: any) {
    return $request.get({ url: '/index/policy', params })
}

// 上传图片
export function uploadImage(params: any) {
    return $request.uploadFile({ url: '/upload/image' }, params)
}

// 上传文件
export function uploadFile(params: any) {
    return $request.uploadFile({ url: '/upload/file' }, params)
}

// 上传视频
export function uploadVideo(params: any) {
    return $request.uploadFile({ url: '/upload/video' }, params)
}

export function getAppFrontend(params?: any) {
    return $request.get({ url: '/app/frontend', params })
}
