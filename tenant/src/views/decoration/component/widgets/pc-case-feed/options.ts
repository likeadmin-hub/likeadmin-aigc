export default () => ({
    title: '案例流',
    name: 'pc-case-feed',
    content: {
        enabled: 1,
        title: '灵感案例',
        source_key: 'image_cases',
        source_params: { limit: 20 },
        tabs: [
            { name: '图片', source_key: 'image_cases' },
            { name: '视频', source_key: 'video_cases' },
            { name: '数字人', source_key: 'digital_human_cases' }
        ]
    },
    styles: {
        layout: { mode: 'flow', x: 40, y: 980, w: 1120, h: 520, z: 1, locked: false, hidden: false, snap: 8 }
    }
})
