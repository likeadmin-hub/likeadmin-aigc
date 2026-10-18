Component({
    data: { items: [], selected: 0, color: '#999999', selectedColor: '#2f80ed', backgroundColor: '#ffffff' },
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
        refresh: function () {
            var model = getApp().globalData.decorationTabbar
            if (!model) return
            var pages = getCurrentPages()
            var current = pages[pages.length - 1]
            var match = /pages\/decoration-tab\/tab-(\d)$/.exec(current && current.route || '')
            this.setData({ items: model.tabs, color: model.style.color, selectedColor: model.style.selectedColor, backgroundColor: model.style.backgroundColor, selected: match ? Number(match[1]) : this.data.selected })
        },
        select: function (event) {
            var index = Number(event.currentTarget.dataset.index)
            if (index === this.data.selected) return
            var action = getApp().globalData.switchDecorationTab
            if (action) action(index)
        }
    }
})
