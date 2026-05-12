import request from '@/utils/request'

// 页面装修详情
export function getDecoratePages(params: any) {
    return request.get({ url: '/decorate.page/detail', params }, { ignoreCancelToken: true })
}

// 页面装修保存
export function setDecoratePages(params: any) {
    return request.post({ url: '/decorate.page/save', params })
}

// 装修模板列表
export function getDecorateTemplateLists(params?: any) {
    return request.get({ url: '/decorate.template/lists', params }, { ignoreCancelToken: true })
}

// 装修模板详情
export function getDecorateTemplateDetail(params: any) {
    return request.get({ url: '/decorate.template/detail', params }, { ignoreCancelToken: true })
}

// 新增装修模板
export function addDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/add', params })
}

// 编辑装修模板
export function editDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/edit', params })
}

// 复制装修模板
export function copyDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/copy', params })
}

// 删除装修模板
export function deleteDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/delete', params })
}

// 启用装修模板
export function enableDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/enable', params })
}

// 发布装修模板
export function publishDecorateTemplate(params: any) {
    return request.post({ url: '/decorate.template/publish', params })
}

// 保存模板设置
export function saveDecorateTemplateSettings(params: any) {
    return request.post({ url: '/decorate.template/saveSettings', params })
}

// 装修页面列表
export function getDecoratePageLists(params: any) {
    return request.get({ url: '/decorate.page/lists', params }, { ignoreCancelToken: true })
}

// 新增装修页面
export function addDecoratePage(params: any) {
    return request.post({ url: '/decorate.page/add', params })
}

// 编辑装修页面基础信息
export function editDecoratePage(params: any) {
    return request.post({ url: '/decorate.page/edit', params })
}

// 复制装修页面
export function copyDecoratePage(params: any) {
    return request.post({ url: '/decorate.page/copy', params })
}

// 删除装修页面
export function deleteDecoratePage(params: any) {
    return request.post({ url: '/decorate.page/delete', params })
}

// 装修页面链接
export function getDecoratePageLinkLists(params?: any) {
    return request.get({ url: '/decorate.page/linkLists', params }, { ignoreCancelToken: true })
}

// 获取首页文章数据
export function getDecorateArticle(params?: any) {
    return request.get({ url: '/decorate.data/article', params })
}

// 底部导航详情
export function getDecorateTabbar(params?: any) {
    return request.get({ url: '/decorate.tabbar/detail', params })
}

// 底部导航保存
export function setDecorateTabbar(params: any) {
    return request.post({ url: '/decorate.tabbar/save', params })
}

// pc装修数据
export function getDecoratePc() {
    return request.get({ url: '/decorate.data/pc' })
}
