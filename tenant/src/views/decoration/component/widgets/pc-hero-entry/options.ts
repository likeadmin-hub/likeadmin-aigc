export default () => ({
    title: 'AI首页入口',
    name: 'pc-hero-entry',
    content: {
        enabled: 1,
        title: 'AI 创作工作台',
        description: '图片、视频、数字人和工具入口统一聚合',
        primary_text: '开始创作',
        primary_link: { path: '/ai/create' },
        secondary_text: '查看工具',
        secondary_link: { path: '/ai/tools' },
        source_key: '',
        source_params: {}
    },
    styles: {
        layout: { mode: 'flow', x: 40, y: 420, w: 1120, h: 220, z: 1, locked: false, hidden: false, snap: 8 }
    }
})
