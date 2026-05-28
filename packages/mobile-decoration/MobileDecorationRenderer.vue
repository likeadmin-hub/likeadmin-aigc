<template>
    <div class="mobile-decoration-renderer" :class="{ 'is-edit': mode === 'edit' }">
        <template v-for="(widget, index) in renderWidgets" :key="widget.id || `${widget.name}-${index}`">
            <section v-if="widget.name === 'search'" class="mobile-widget mobile-search" :style="blockStyle(widget)">
                <span>{{ widget.content.placeholder || '搜索' }}</span>
            </section>

            <section v-else-if="widget.name === 'banner'" class="mobile-widget mobile-banner" :style="blockStyle(widget)">
                <div
                    v-for="(item, bannerIndex) in visibleRows(widget).slice(0, 1)"
                    :key="bannerIndex"
                    class="mobile-banner__item"
                    :style="mediaStyle(item.image || item.cover)"
                >
                    <strong>{{ item.name || item.title || '轮播图' }}</strong>
                </div>
            </section>

            <section v-else-if="widget.name === 'nav'" class="mobile-widget mobile-nav" :style="blockStyle(widget)">
                <div v-for="(item, navIndex) in visibleRows(widget).slice(0, 10)" :key="navIndex">
                    <span class="mobile-nav__icon" :style="mediaStyle(item.image)"></span>
                    <em>{{ item.name || item.title || '导航' }}</em>
                </div>
            </section>

            <section v-else-if="widget.name === 'middle-banner'" class="mobile-widget mobile-middle-banner" :style="blockStyle(widget)">
                <span :style="mediaStyle(widget.content.image)"></span>
            </section>

            <section v-else-if="widget.name === 'title-bar'" class="mobile-widget mobile-title" :style="blockStyle(widget)">
                <strong :class="`is-${widget.content.align || 'left'}`">{{ widget.content.title || '标题' }}</strong>
                <small v-if="widget.content.sub_title" :class="`is-${widget.content.align || 'left'}`">{{ widget.content.sub_title }}</small>
            </section>

            <section v-else-if="widget.name === 'notice'" class="mobile-widget mobile-notice" :style="blockStyle(widget)">
                <span></span>
                <em>{{ widget.content.text || '公告内容' }}</em>
            </section>

            <section v-else-if="widget.name === 'list-nav'" class="mobile-widget mobile-list-nav" :style="blockStyle(widget)">
                <div v-for="(item, rowIndex) in visibleRows(widget)" :key="rowIndex">
                    <span :style="mediaStyle(item.image)"></span>
                    <strong>{{ item.name || item.title || '列表导航' }}</strong>
                    <i></i>
                </div>
            </section>

            <section
                v-else-if="widget.name === 'image-hotspot'"
                class="mobile-widget mobile-hotspot"
                :style="{ ...blockStyle(widget), height: `${Number(widget.content.height || 180) / 2}px` }"
            >
                <img v-if="widget.content.image" :src="resolveImage(widget.content.image)" alt="" />
            </section>

            <section v-else-if="widget.name === 'divider'" class="mobile-widget mobile-divider" :style="blockStyle(widget)">
                <span :style="{ borderTopStyle: widget.content.style || 'solid' }"></span>
            </section>

            <section v-else-if="widget.name === 'user-info'" class="mobile-widget mobile-user" :style="blockStyle(widget)">
                <span></span>
                <div>
                    <strong>用户昵称</strong>
                    <small>个人中心</small>
                </div>
            </section>

            <section v-else-if="widget.name === 'customer-service'" class="mobile-widget mobile-service" :style="blockStyle(widget)">
                {{ widget.content.title || '客服设置' }}
            </section>

            <section v-else class="mobile-widget mobile-unknown" :style="blockStyle(widget)">
                {{ widget.title || widget.name }}
            </section>
        </template>
    </div>
</template>

<script setup lang="ts">
import { computed, PropType } from 'vue'
import { normalizeMobileDecorationWidgets } from './index'

const props = defineProps({
    widgets: {
        type: Array as PropType<any[]>,
        default: () => []
    },
    mode: {
        type: String as PropType<'view' | 'edit'>,
        default: 'view'
    },
    adapters: {
        type: Object as PropType<{
            resolveImage?: (value: string) => string
        }>,
        default: () => ({})
    }
})

const normalizedWidgets = computed(() => normalizeMobileDecorationWidgets(props.widgets))
const renderWidgets = computed(() =>
    normalizedWidgets.value.filter((item: any) => props.mode === 'edit' || item?.content?.enabled !== 0)
)
const resolveImage = (value: any) => {
    const url = String(value || '')
    if (!url) return ''
    if (/^(https?:\/\/|data:|blob:)/i.test(url)) return url
    return props.adapters.resolveImage?.(url) || url
}
const mediaStyle = (value: any) => {
    const url = resolveImage(value)
    return url ? { backgroundImage: `url(${url})` } : {}
}
const visibleRows = (widget: any) =>
    (Array.isArray(widget.content?.data) ? widget.content.data : []).filter((item: any) => item?.is_show !== '0')
const blockStyle = (widget: any) => ({
    background: widget.styles?.background || undefined,
    color: widget.styles?.color || undefined,
    marginTop: widget.styles?.margin_top ? `${Number(widget.styles.margin_top) / 2}px` : undefined,
    marginBottom: widget.styles?.margin_bottom ? `${Number(widget.styles.margin_bottom) / 2}px` : undefined,
    borderRadius: widget.styles?.border_radius ? `${Number(widget.styles.border_radius) / 2}px` : undefined,
    opacity: widget.content?.enabled === 0 ? 0.48 : undefined
})
</script>

<style scoped lang="scss">
.mobile-decoration-renderer {
    width: 100%;
}
.mobile-widget {
    box-sizing: border-box;
}
.mobile-search {
    height: 38px;
    margin: 10px 12px;
    display: flex;
    align-items: center;
    padding: 0 14px;
    border-radius: 999px;
    background: #f6f7f9;
    color: #999;
}
.mobile-banner {
    margin: 10px 12px;
}
.mobile-banner__item,
.mobile-middle-banner span,
.mobile-hotspot {
    min-height: 132px;
    display: flex;
    align-items: flex-end;
    overflow: hidden;
    border-radius: 10px;
    background: linear-gradient(135deg, #dfe8ff, #ffe8f3);
    background-size: cover;
    background-position: center;
    strong {
        padding: 14px;
        color: #fff;
        text-shadow: 0 1px 8px rgba(0, 0, 0, 0.28);
    }
}
.mobile-nav {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 12px 6px;
    padding: 12px;
    background: #fff;
    div {
        min-width: 0;
        display: grid;
        justify-items: center;
        gap: 6px;
    }
    em {
        max-width: 100%;
        overflow: hidden;
        color: #333;
        font-size: 12px;
        font-style: normal;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
}
.mobile-nav__icon {
    width: 36px;
    height: 36px;
    border-radius: 12px;
    background: linear-gradient(135deg, #4f6cff, #ff68aa);
    background-size: cover;
    background-position: center;
}
.mobile-middle-banner {
    margin: 10px 12px;
    span {
        display: block;
        min-height: 96px;
    }
}
.mobile-title {
    padding: 12px 14px;
    background: #fff;
    strong,
    small {
        display: block;
    }
    strong {
        color: #111;
        font-size: 17px;
        font-weight: 700;
    }
    small {
        margin-top: 4px;
        color: #888;
    }
    .is-center {
        text-align: center;
    }
    .is-right {
        text-align: right;
    }
}
.mobile-notice {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 10px 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: #fff7e6;
    color: #8a5a00;
    span {
        width: 16px;
        height: 16px;
        border-radius: 99px;
        background: currentColor;
    }
    em {
        min-width: 0;
        overflow: hidden;
        font-style: normal;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
}
.mobile-list-nav {
    margin: 10px 12px;
    overflow: hidden;
    border-radius: 10px;
    background: #fff;
    div {
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 48px;
        padding: 0 12px;
        & + div {
            border-top: 1px solid #f2f2f2;
        }
    }
    span {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: #eef2ff;
        background-size: cover;
        background-position: center;
    }
    strong {
        flex: 1;
        min-width: 0;
        overflow: hidden;
        font-size: 14px;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    i {
        width: 8px;
        height: 8px;
        border-right: 1px solid #aaa;
        border-bottom: 1px solid #aaa;
        transform: rotate(-45deg);
    }
}
.mobile-hotspot {
    margin: 10px 12px;
    min-height: 90px;
    img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
}
.mobile-divider {
    padding: 8px 14px;
    span {
        display: block;
        border-top: 1px solid #eee;
    }
}
.mobile-user {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 14px;
    background: #fff;
    span {
        width: 48px;
        height: 48px;
        border-radius: 999px;
        background: linear-gradient(135deg, #4f6cff, #ff68aa);
    }
    strong,
    small {
        display: block;
    }
    small {
        margin-top: 4px;
        color: #999;
    }
}
.mobile-service,
.mobile-unknown {
    margin: 10px 12px;
    padding: 16px;
    border-radius: 10px;
    background: #fff;
    color: #666;
}
</style>
