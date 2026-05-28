export default () => ({
    title: '首页轮播图',
    name: 'pc-banner',
    content: {
        enabled: 1,
        data: [
            {
                image: '',
                name: '',
                description: '',
                link: {}
            }
        ]
    },
    styles: {
        layout: { mode: 'flow', x: 40, y: 40, w: 1120, h: 360, z: 1, locked: false, hidden: false, snap: 8 }
    }
})
