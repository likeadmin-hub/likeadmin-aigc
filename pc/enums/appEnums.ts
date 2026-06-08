//菜单主题类型
export enum ThemeEnum {
    LIGHT = 'light',
    DARK = 'dark'
}

// 菜单类型
export enum MenuEnum {
    CATALOGUE = 'M',
    MENU = 'C',
    BUTTON = 'A'
}

// 屏幕
export enum ScreenEnum {
    SM = 640,
    MD = 768,
    LG = 1024,
    XL = 1280,
    '2XL' = 1536
}

export enum SMSEnum {
    LOGIN = 'YZMDL',
    BIND_MOBILE = 'BDSJHM',
    CHANGE_MOBILE = 'BGSJHM',
    FIND_PASSWORD = 'ZHDLMM'
}

export enum PolicyAgreementEnum {
    SERVICE = 'service',
    PRIVACY = 'privacy',
    COMMUNITY = 'community',
    AI_USAGE = 'ai_usage',
    PAID = 'paid'
}

export const policyAgreementOptions = [
    { type: PolicyAgreementEnum.SERVICE, label: '用户服务协议' },
    { type: PolicyAgreementEnum.PRIVACY, label: '隐私政策' },
    { type: PolicyAgreementEnum.COMMUNITY, label: '社区自律公约' },
    { type: PolicyAgreementEnum.AI_USAGE, label: 'AI功能使用须知' }
] as const

export const allPolicyAgreementOptions = [
    ...policyAgreementOptions,
    { type: PolicyAgreementEnum.PAID, label: '付费用户协议' }
] as const
