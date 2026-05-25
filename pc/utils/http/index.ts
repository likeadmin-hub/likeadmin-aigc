import { FetchOptions } from 'ofetch'
import { RequestCodeEnum, RequestMethodsEnum } from '@/enums/requestEnums'
import feedback from '@/utils/feedback'
import { merge } from 'lodash-es'
import { Request } from './request'
import { getApiPrefix, getApiUrl, getVersion } from '../env'
import { useUserStore } from '@/stores/user'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { parseTenantIdFromRoute } from '../tenant'

const SYSTEM_ERROR_PATTERNS = [
    /unserialize\(\)/i,
    /Error at offset/i,
    /stack trace/i,
    /SQLSTATE/i
]

const normalizeResponseMessage = (msg: any) => {
    const text = typeof msg === 'string' ? msg : msg?.message
    if (!text) return msg
    if (SYSTEM_ERROR_PATTERNS.some((pattern) => pattern.test(text))) {
        return '登录状态异常，请刷新页面后重新登录'
    }
    return text
}

export function createRequest(opt?: Partial<FetchOptions>) {
    const defaultOptions: FetchOptions = {
        // 基础接口地址
        baseURL: getApiUrl(),
        //请求头
        headers: {
            version: getVersion()
        },
        retry: 2,
        requestOptions: {
            apiPrefix: getApiPrefix(),
            isTransformResponse: true,
            isReturnDefaultResponse: false,
            withToken: true,
            isParamsToData: true,
            requestInterceptorsHook(options) {
                const userStore = useUserStore()
                const { apiPrefix, isParamsToData, withToken } = options.requestOptions
                // 拼接请求前缀
                if (apiPrefix) {
                    options.url = `${apiPrefix}${options.url}`
                }
                const params = options.params || {}
                // POST请求下如果无data，则将params视为data
                if (
                    isParamsToData &&
                    !Reflect.has(options, 'body') &&
                    options.method?.toUpperCase() === RequestMethodsEnum.POST
                ) {
                    options.body = params
                    options.params = {}
                }
                const headers = options.headers || {}
                if (withToken) {
                    const token = userStore.token
                    headers['token'] = token
                }
                headers['terminal'] = '4'
                const tenantId = parseTenantIdFromRoute()
                if (tenantId) {
                    headers['tenant-id'] = tenantId
                }
                options.headers = headers
                return options
            },
            async responseInterceptorsHook(response, options) {
                const userStore = useUserStore()
                const { handlePcLoginFailure } = usePcLoginGate()
                const { isTransformResponse, isReturnDefaultResponse, suppressErrorMessage } = options.requestOptions
                //返回默认响应，当需要获取响应头及其他数据时可使用
                if (isReturnDefaultResponse) {
                    return response
                }
                // 是否需要对数据进行处理
                if (!isTransformResponse) {
                    return response._data
                }
                const { code, data, show, msg } = response._data
                const normalizedMsg = normalizeResponseMessage(msg)
                switch (code) {
                    case RequestCodeEnum.SUCCESS:
                        if (show) {
                            normalizedMsg && feedback.msgSuccess(normalizedMsg)
                        }
                        return data
                    case RequestCodeEnum.FAIL:
                        if (show && !suppressErrorMessage) {
                            normalizedMsg && feedback.msgError(normalizedMsg)
                        }
                        return Promise.reject(normalizedMsg)
                    case RequestCodeEnum.LOGIN_FAILURE:
                        return Promise.reject(handlePcLoginFailure())
                    case RequestCodeEnum.NOT_INSTALL:
                        window.location.replace('/install/install.php')
                        break
                    default:
                        return data
                }
            },
            responseInterceptorsCatchHook(err) {
                if (typeof err === 'string') {
                    return normalizeResponseMessage(err)
                }
                if (err?.data?.msg) {
                    err.data.msg = normalizeResponseMessage(err.data.msg)
                }
                return err
            }
        }
    }
    return new Request(
        // 深度合并
        merge(defaultOptions, opt || {})
    )
}
