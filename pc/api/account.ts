import { getClient } from '~~/utils/env'

// 登录
export function login(params: any) {
    return $request.post({
        url: '/login/account',
        body: { ...params, terminal: getClient() }
    })
}
// 登录
export function logout() {
    return $request.post({ url: '/login/logout' })
}

//注册
export function register(params: any) {
    return $request.post({
        url: '/login/register',
        params: { ...params, channel: getClient() }
    })
}
