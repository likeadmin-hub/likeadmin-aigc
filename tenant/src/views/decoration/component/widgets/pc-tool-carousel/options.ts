export default () => ({
    title: '工具轮播',
    name: 'pc-tool-carousel',
    content: {
        enabled: 1,
        title: '热门工具',
        source_key: 'ai_tools',
        source_params: { limit: 12 },
        data: []
    },
    styles: {
        layout: { mode: 'flow', x: 40, y: 670, w: 1120, h: 280, z: 1, locked: false, hidden: false, snap: 8 }
    }
})
