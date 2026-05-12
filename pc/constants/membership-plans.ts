export type MembershipPlanId = string | number

export interface MembershipPlanDefinition {
    id: MembershipPlanId
    name: string
    title: string
    description: string
    monthlyPrice: string
    yearlyPrice: string
    button: string
    outline: boolean
    features: string[]
    free?: boolean
    isRecommended?: boolean
    monthlyMarketPrice?: string
    yearlyMarketPrice?: string
    monthlyBonus?: string
    monthlyBonusTip?: string
    yearlyBonus?: string
    yearlyBonusTip?: string
}

export const MEMBERSHIP_PLANS: MembershipPlanDefinition[] = [
    {
        id: 'free',
        name: '\u514d\u8d39\n\u4f1a\u5458',
        title: '\u514d\u8d39\u4f1a\u5458',
        description: '\u7cfb\u7edf\u9ed8\u8ba4\u514d\u8d39\u4f1a\u5458\uff0c\u57fa\u7840\u5e94\u7528\u53ef\u76f4\u63a5\u4f7f\u7528',
        monthlyPrice: '0.00',
        yearlyPrice: '0.00',
        button: '\u5f53\u524d\u5957\u9910',
        outline: false,
        free: true,
        features: [
            '\u57fa\u7840\u5e94\u7528\u6c38\u4e45\u514d\u8d39\u4f7f\u7528',
            '\u53ef\u8d2d\u4e70\u79ef\u5206\u7ee7\u7eed\u521b\u4f5c',
            '\u4f1a\u5458\u6743\u76ca\u53ef\u7531\u79df\u6237\u7ee7\u7eed\u8c03\u6574'
        ]
    },
    {
        id: 'basic',
        name: '\u57fa\u7840\n\u4f1a\u5458',
        title: '\u57fa\u7840\u4f1a\u5458',
        description: '\u9002\u5408\u8f7b\u91cf\u521b\u4f5c\u7528\u6237\uff0c\u8d60\u9001\u57fa\u7840\u79ef\u5206',
        monthlyPrice: '19.90',
        yearlyPrice: '199.00',
        button: '\u8ba2\u9605\u57fa\u7840\u4f1a\u5458',
        outline: false,
        monthlyMarketPrice: '29.90',
        yearlyMarketPrice: '299.00',
        monthlyBonus: '100',
        monthlyBonusTip: '\u4f1a\u5458\u5957\u9910\u8d60\u9001\u79ef\u5206',
        yearlyBonus: '1500',
        yearlyBonusTip: '\u4f1a\u5458\u5957\u9910\u8d60\u9001\u79ef\u5206',
        features: [
            '\u6bcf\u6708\u8d60\u9001100\u79ef\u5206',
            '\u6309\u5e74\u5f00\u901a\u8d60\u90011500\u79ef\u5206',
            '\u9002\u5408\u4e2a\u4eba\u8f7b\u91cf\u521b\u4f5c'
        ]
    },
    {
        id: 'advanced',
        name: '\u9ad8\u7ea7\n\u4f1a\u5458',
        title: '\u9ad8\u7ea7\u4f1a\u5458',
        description: '\u9002\u5408\u9ad8\u9891\u521b\u4f5c\u7528\u6237\uff0c\u8d60\u9001\u66f4\u591a\u79ef\u5206',
        monthlyPrice: '39.90',
        yearlyPrice: '399.00',
        button: '\u8ba2\u9605\u9ad8\u7ea7\u4f1a\u5458',
        outline: false,
        isRecommended: true,
        monthlyMarketPrice: '69.90',
        yearlyMarketPrice: '699.00',
        monthlyBonus: '300',
        monthlyBonusTip: '\u4f1a\u5458\u5957\u9910\u8d60\u9001\u79ef\u5206',
        yearlyBonus: '4200',
        yearlyBonusTip: '\u4f1a\u5458\u5957\u9910\u8d60\u9001\u79ef\u5206',
        features: [
            '\u6bcf\u6708\u8d60\u9001300\u79ef\u5206',
            '\u6309\u5e74\u5f00\u901a\u8d60\u90014200\u79ef\u5206',
            '\u9002\u5408\u9ad8\u9891\u56fe\u6587\u4e0e\u89c6\u9891\u521b\u4f5c'
        ]
    }
]
