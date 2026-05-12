/**
 * 与 server 库表 la_tenant 一致；PC 请求 /api 时后端按 Host 匹配租户（domain_alias 或子域名 sn）。
 * 开发默认租户：编号 sn = PUBLIC_DEFAULT_TENANT_SN → Host = sn + '.' + PUBLIC_API_HOST
 */
export const PUBLIC_API_HOST = 'uana.cn'
export const PUBLIC_DEFAULT_TENANT_SN = '62ogq9u1'
/** 开发环境未配置 NUXT_PUBLIC_* 时，Nitro 代理使用该 Host（子域名租户） */
export const DEFAULT_PC_DEV_API_HOST = `${PUBLIC_DEFAULT_TENANT_SN}.${PUBLIC_API_HOST}`
export const PUBLIC_API_ORIGIN = `https://${PUBLIC_API_HOST}`
