import { createRequest } from '~~/utils/http'

/**
 * 业务请求请用 $request。不要替换全局 $fetch：Nuxt 内部用 ofetch 多种签名，
 * 错误包装会导致路由/预取卡住、页面一直「加载中」。
 */
export default defineNuxtPlugin(() => {
    const request = createRequest()
    //@ts-ignore
    globalThis.$request = request
})
