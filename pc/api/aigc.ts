export function getAigcApps(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.ai/apps', params })
}

export function getAigcAppDetail(app_id: string | number) {
    return $request.get({ url: '/aigc.ai/appDetail', params: { app_id } })
}

export function aigcQuote(params: Record<string, any>) {
    return $request.post({ url: '/aigc.ai/quote', params })
}

export function aigcCreate(params: Record<string, any>) {
    return $request.post({ url: '/aigc.ai/create', params })
}

export function getAigcJobDetail(job_id: string | number) {
    return $request.get({ url: '/aigc.ai/jobDetail', params: { job_id } })
}

export function getAigcJobLists(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.ai/jobLists', params })
}

export function syncAigcJob(job_id: string | number) {
    return $request.post({ url: '/aigc.ai/syncJob', params: { job_id } })
}

export function getAigcAssets(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.asset/lists', params })
}

export function favoriteAigcAsset(id: string | number, favorite = 1) {
    return $request.post({ url: '/aigc.asset/favorite', params: { id, favorite } })
}

export function batchFavoriteAigcAssets(ids: Array<string | number>, favorite = 1) {
    return $request.post({ url: '/aigc.asset/batchFavorite', params: { ids, favorite } })
}

export function deleteAigcAsset(id: string | number) {
    return $request.post({ url: '/aigc.asset/delete', params: { id } })
}

export function batchDeleteAigcAssets(ids: Array<string | number>) {
    return $request.post({ url: '/aigc.asset/batchDelete', params: { ids } })
}

export function downloadAigcAsset(id: string | number) {
    return $request.get({ url: '/aigc.asset/download', params: { id } })
}

export function getOfficialAvatars(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.avatar/officialLists', params })
}

export function getMyAvatars(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.avatar/myLists', params })
}

export function createMyAvatar(params: Record<string, any>) {
    return $request.post({ url: '/aigc.avatar/createMine', params })
}

export function deleteMyAvatar(id: string | number) {
    return $request.post({ url: '/aigc.avatar/deleteMine', params: { id } })
}

export function getOfficialVoices(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.voice/officialLists', params })
}

export function getMyVoices(params: Record<string, any> = {}) {
    return $request.get({ url: '/aigc.voice/myLists', params })
}

export function createMyVoice(params: Record<string, any>) {
    return $request.post({ url: '/aigc.voice/createMine', params })
}

export function deleteMyVoice(id: string | number) {
    return $request.post({ url: '/aigc.voice/deleteMine', params: { id } })
}
