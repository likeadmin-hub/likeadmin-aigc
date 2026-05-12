export enum MenuTypeEnum {
    'SHOP_PAGES' = 'shop',
    'DECORATE_PAGE' = 'decorate_page',
    'APPTOOL' = 'application_tool',
    'APP_CENTER' = 'app_center',
    'OTHER_LINK' = 'other_link'
}

export enum LinkTypeEnum {
    'SHOP_PAGES' = 'shop',
    'DECORATE_PAGE' = 'decorate_page',
    'ARTICLE_LIST' = 'article',
    'APP_CENTER' = 'app_center',
    'CUSTOM_LINK' = 'custom',
    'MINI_PROGRAM' = 'mini_program'
}

export interface Link {
    path: string
    name?: string
    type: string
    query?: Record<string, any>
    app_code?: string
    entry_key?: string
    terminal?: string
    page_code?: string
    canTab?: boolean
}
