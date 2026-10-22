Component({
    data: { items: [], selected: 0, color: '#999999', selectedColor: '#2f80ed', backgroundColor: '#ffffff', iconAtlas: '', barHeight: 100, iconSize: 44 },
    lifetimes: {
        attached: function () {
            var app = getApp()
            app.globalData = app.globalData || {}
            app.globalData.decorationTabbarListeners = app.globalData.decorationTabbarListeners || new Set()
            this._refresh = this.refresh.bind(this)
            app.globalData.decorationTabbarListeners.add(this._refresh)
            this.refresh()
        },
        detached: function () {
            var app = getApp()
            if (app.globalData.decorationTabbarListeners) app.globalData.decorationTabbarListeners.delete(this._refresh)
        }
    },
    pageLifetimes: { show: function () { this.refresh() } },
    methods: {
        refresh: function (slot) {
            // Each cached native page owns a separate bar. Only its page show
            // hook can bind the slot; a broadcast must never adopt another page.
            if (typeof slot === 'number' && slot >= 0 && slot < 5 && Math.floor(slot) === slot) this._slot = slot
            var model = getApp().globalData.decorationTabbar
            if (!model) return
            var pages = getCurrentPages()
            var current = pages[pages.length - 1]
            var match = /pages\/decoration-tab\/tab-(\d)$/.exec(current && current.route || '')
            var selected = typeof this._slot === 'number' ? this._slot : match ? Number(match[1]) : this.data.selected
            var style = model.style || {}
            var active = style.iconAtlas ? model.tabs[selected] || {} : {}
            var items = model.tabs.map(function (item, index) {
                var column = item.atlasIndex == null ? index : Number(item.atlasIndex)
                var state = selected === index ? 'selected' : 'unselected'
                var source = selected === index ? item.selectedIconPath : item.iconPath
                var useAtlas = style.iconAtlas && !(item.icon_atlas_overrides && item.icon_atlas_overrides[state]) && (!source || source === style.iconAtlas)
                return Object.assign({}, item, { hasAtlas: !!useAtlas && column >= 0 && column < 4 && Math.floor(column) === column, atlasLeft: (-column * 100) + '%', atlasTop: selected === index ? '-100%' : '0%' })
            })
            this.setData({ items: items, color: style.color, selectedColor: active.selected_color || style.selectedColor, backgroundColor: active.background_color || style.backgroundColor, selected: selected,
                iconAtlas: style.iconAtlas || '', barHeight: style.iconAtlas ? style.barHeight || 122 : 100, iconSize: style.iconAtlas ? style.iconSize || 72 : 44 })
        },
        select: function (event) {
            var index = Number(event.currentTarget.dataset.index)
            if (index === this.data.selected) return
            var action = getApp().globalData.switchDecorationTab
            if (action) action(index)
        }
    }
})
