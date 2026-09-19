import request from '@/utils/request'

export function getMusicCoverConfig() {
    return request.get({ url: '/app.aigc_music_cover.config/detail' })
}

export function searchMusic(keyword: string, page = 1, pageSize = 10) {
    return request.post({
        url: '/v1/apps/music_search/search',
        data: { keyword, page, page_size: pageSize }
    })
}

export function generateMusicCover(data: Record<string, any>) {
    return request.post({ url: '/app.aigc_music_cover.generate/index', data })
}

export function estimateMusicCover(data: Record<string, any>) {
    return request.post({ url: '/app.aigc_music_cover.generate/estimate', data })
}

export function getMusicCoverTasks(data?: Record<string, any>) {
    return request.get({ url: '/app.aigc_music_cover.task/lists', data })
}

export function retryMusicCoverTask(data: Record<string, any>) {
    return request.post({ url: '/app.aigc_music_cover.task/retry', data })
}

export function deleteMusicCoverTask(data: Record<string, any>) {
    return request.post({ url: '/app.aigc_music_cover.task/delete', data })
}

export function uploadMusicCoverAudio(
    filePath: string,
    assetType: 'cover_source' | 'cover_reference'
) {
    return request.uploadFile({
        url: '/app.aigc_music_cover.asset/upload_audio',
        filePath,
        name: 'file',
        formData: { asset_type: assetType }
    })
}
