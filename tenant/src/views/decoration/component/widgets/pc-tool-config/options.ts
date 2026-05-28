import { pcToolConfigDefaults } from './defaults'

export default () => ({
    title: '工具配置',
    name: 'pc-tool-config',
    content: {
        enabled: 1,
        data: pcToolConfigDefaults
    },
    styles: {
        layout: { mode: 'flow', x: 40, y: 1530, w: 1120, h: 280, z: 1, locked: false, hidden: false, snap: 8 }
    }
})
