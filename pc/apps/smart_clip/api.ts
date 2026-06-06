export function getSmartClipConfig() {
    return $request.get({ url: '/app.smart_clip.config/detail' })
}

export function getSmartClipTemplates(params?: any, requestOptions?: any) {
    return $request.get({ url: '/app.smart_clip.template/lists', params }, requestOptions)
}

export function getSmartClipTemplateDetail(params: any, requestOptions?: any) {
    return $request.get({ url: '/app.smart_clip.template/detail', params }, requestOptions)
}

export function estimateSmartClip(params: any, requestOptions?: any) {
    return $request.post({ url: '/app.smart_clip.generate/estimate', params }, requestOptions)
}

export function generateSmartClip(params: any, requestOptions?: any) {
    return $request.post({ url: '/app.smart_clip.generate/index', params }, requestOptions)
}

export function getSmartClipTasks(params?: any) {
    return $request.get({ url: '/app.smart_clip.task/lists', params })
}

export function getSmartClipTask(params: any) {
    return $request.get({ url: '/app.smart_clip.task/detail', params })
}

export function getSmartClipResults(params?: any) {
    return $request.get({ url: '/app.smart_clip.result/lists', params })
}

export function deleteSmartClipResult(params: any) {
    return $request.post({ url: '/app.smart_clip.result/delete', params })
}
