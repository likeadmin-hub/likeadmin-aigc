/**
 * 权限控制
 */

import 'nprogress/nprogress.css'

import NProgress from 'nprogress'

import config from './config'
import { PageEnum } from './enums/pageEnum'
import router, { findFirstValidRoute } from './router'
import { INDEX_ROUTE, INDEX_ROUTE_NAME } from './router/routes'
import useTabsStore from './stores/modules/multipleTabs'
import useUserStore from './stores/modules/user'
import { clearAuthInfo } from './utils/auth'
import {
    clearSsoTicketFromUrl,
    getSsoTicket,
    normalizeTenantPath,
    parseTenantIdFromLocation,
    withTenantQuery
} from './utils/tenant'
import { isExternal } from './utils/validate'

// 动态添加路由-使用递归进行调整-（fix: 修复之前超过3级菜单导致使用keep-alive功能无效问题
const addRoutesRecursively = (routes: any, parentPath = '') => {
    try {
        routes.forEach((route: any) => {
            // 如果路由是外部链接，则不添加
            if (isExternal(route.path)) {
                return
            }

            // 拼接父路由路径和当前路由路径
            const fullPath = parentPath + route.path

            // 创建路由对象，确保每个路由都有唯一的名称
            const routerEntry = {
                ...route,
                path: fullPath,
                name: route.name || fullPath.replace(/\//g, '_').replace('_', '') // 替换斜杠为下划线，生成唯一名称
            }

            // 添加路由
            if (!route.children) {
                router.addRoute(INDEX_ROUTE_NAME, routerEntry)
            } else {
                router.addRoute(routerEntry)
            }

            // 递归处理子路由
            if (route.children && route.children.length > 0) {
                addRoutesRecursively(route.children, fullPath + '/')
            }
        })
    } catch (e) {
        console.error('Error adding routes:', e)
    }
}

// NProgress配置
NProgress.configure({ showSpinner: false })

const loginPath = PageEnum.LOGIN
const defaultPath = PageEnum.INDEX
// 免登录白名单
const whiteList: string[] = [PageEnum.LOGIN, PageEnum.ERROR_403]
router.beforeEach(async (to, from, next) => {
    // 开始 Progress Bar
    NProgress.start()
    const tenantId = parseTenantIdFromLocation()
    const normalizedPath = normalizeTenantPath(to.path)
    if (normalizedPath !== to.path) {
        next(
            withTenantQuery(
                {
                    path: normalizedPath,
                    query: to.query,
                    hash: to.hash,
                    replace: true
                },
                tenantId
            )
        )
        return
    }
    if (tenantId && !to.query.tenant_id && !to.query.tenantId) {
        next(
            withTenantQuery(
                {
                    path: to.path,
                    query: to.query,
                    hash: to.hash,
                    replace: true
                },
                tenantId
            )
        )
        return
    }
    document.title = to.meta.title ?? config.title
    const userStore = useUserStore()
    const tabsStore = useTabsStore()
    const ssoTicket = getSsoTicket()
    if (ssoTicket && tenantId) {
        try {
            clearAuthInfo()
            const data: any = await userStore.ssoLogin({
                tenant_id: tenantId,
                sso_ticket: ssoTicket
            })
            clearSsoTicketFromUrl()
            next(withTenantQuery({ path: data.redirect || defaultPath, replace: true }, tenantId))
        } catch (error) {
            clearAuthInfo()
            clearSsoTicketFromUrl()
            next(withTenantQuery({ path: loginPath, query: { redirect: to.fullPath } }, tenantId))
        }
        return
    }
    if (whiteList.includes(to.path)) {
        // 在免登录白名单，直接进入
        next()
    } else if (userStore.token) {
        // 获取用户信息
        const hasGetUserInfo = Object.keys(userStore.userInfo).length !== 0
        if (hasGetUserInfo) {
            if (to.path === loginPath) {
                next(withTenantQuery({ path: defaultPath }, tenantId))
            } else {
                next()
            }
        } else {
            try {
                await userStore.getUserInfo()
                const routes = userStore.routes
                // 找到第一个有效路由
                const routeName = findFirstValidRoute(routes)
                // 没有有效路由跳转到403页面
                if (!routeName) {
                    clearAuthInfo()
                    next(PageEnum.ERROR_403)
                    return
                }
                tabsStore.setRouteName(routeName!)
                INDEX_ROUTE.redirect = { name: routeName }

                // 动态添加index路由
                router.addRoute(INDEX_ROUTE)
                // 动态添加其余路由
                addRoutesRecursively(routes)
                next(withTenantQuery({ ...to, replace: true }, tenantId))
            } catch (err) {
                clearAuthInfo()
                next(withTenantQuery({ path: loginPath, query: { redirect: to.fullPath } }, tenantId))
            }
        }
    } else {
        next(withTenantQuery({ path: loginPath, query: { redirect: to.fullPath } }, tenantId))
    }
})

router.afterEach(() => {
    NProgress.done()
})
