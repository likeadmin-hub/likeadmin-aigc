import request from '@/utils/request'

export function appMarket(params?: any) {
    return request.get({ url: '/app/market', params })
}

export function myApps() {
    return request.get({ url: '/app/my' })
}

export function buyApp(params: any) {
    return request.post({ url: '/app/buy', params })
}

export function shelfApp(params: any) {
    return request.post({ url: '/app/shelf', params })
}

export function appFrontend(params?: any) {
    return request.get({ url: '/app/frontend', params })
}
