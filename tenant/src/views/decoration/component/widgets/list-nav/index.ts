import attr from './attr.vue'
import content from './content.vue'
import options from './options'

export default {
    attr,
    content,
    options,
    category: 'navigation',
    icon: 'el-icon-List',
    repeatable: true,
    terminal: ['mobile', 'pc'],
    support_channels: ['h5', 'mp_weixin']
}
