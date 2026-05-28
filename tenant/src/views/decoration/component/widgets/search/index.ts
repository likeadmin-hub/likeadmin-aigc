import attr from './attr.vue'
import content from './content.vue'
import options from './options'

export default {
    attr,
    content,
    options,
    category: 'basic',
    icon: 'el-icon-Search',
    repeatable: true,
    terminal: ['mobile', 'pc'],
    support_channels: ['h5', 'mp_weixin', 'pc']
}
