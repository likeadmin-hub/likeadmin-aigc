export function getSkillList(params: Record<string, any> = {}) {
    return $request.get({ url: '/pc.skills/lists', params })
}

export function getSkillDetail(id: string | number) {
    return $request.get({ url: '/pc.skills/detail', params: { id } })
}

export function getAcademyList(params: Record<string, any> = {}) {
    return $request.get({ url: '/pc.academy/lists', params })
}

export function getAcademyDetail(id: string | number) {
    return $request.get({ url: '/pc.academy/detail', params: { id } })
}

export function getAcademyComments(params: Record<string, any> = {}) {
    return $request.get({ url: '/pc.academy/comments', params })
}

export function postAcademyComment(params: Record<string, any>) {
    return $request.post({ url: '/pc.academy/comment', params })
}
