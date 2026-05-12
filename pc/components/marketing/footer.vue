<template>
    <footer class="site-marketing-footer">
        <div class="shell site-marketing-footer__top">
            <div class="site-marketing-footer__brand">
                <div class="site-marketing-footer__logo">
                    <span class="site-marketing-footer__mark">A.</span>
                    <span class="site-marketing-footer__text">PART</span>
                </div>
            </div>
            <div class="site-marketing-footer__columns">
                <div
                    v-for="column in footerColumns"
                    :key="column.title"
                    class="site-marketing-footer__column"
                >
                    <h4>{{ column.title }}</h4>
                    <a v-for="item in column.items" :key="item" href="#">{{ item }}</a>
                </div>
            </div>
        </div>

        <div class="shell site-marketing-footer__divider"></div>

        <div class="shell site-marketing-footer__bottom">
            <div class="site-marketing-footer__meta">
                <a href="#">Cookie政策</a>
                <a href="#">法律条款</a>
                <a href="#">隐私政策</a>
                <span>© 2026 Prestige. All rights reserved.</span>
            </div>
            <div class="site-marketing-footer__socials">
                <a v-for="item in socials" :key="item" href="#">{{ item }}</a>
            </div>
        </div>
    </footer>
</template>

<script lang="ts" setup>
const footerColumns = [
    {
        title: '技能',
        items: ['小红书助手', 'HR招聘助手', 'HR招聘助手', '全部技能']
    },
    {
        title: '应用',
        items: ['sora2', 'FastMovie.ai', '文章', '全部应用']
    },
    {
        title: '教程',
        items: ['文章', '知识库', '系列教程', '全部教程']
    },
    {
        title: 'AIpark',
        items: ['成为讲师', '加入我们', '商务合作', '关于我们']
    }
]

const socials = ['小红书', '微信视频号', 'YouTube', '哔哩哔哩动画', 'TikTok', 'Pinterest']
</script>

<style lang="scss" scoped>
.shell {
    width: min(1596px, calc(100vw - var(--pc-page-gutter-total, 48px)));
    margin: 0 auto;
}

.site-marketing-footer {
    padding: 89px 0 84px;
    background: #222;
    color: #fff;

    &__top {
        display: flex;
        justify-content: space-between;
        gap: 80px;
    }

    &__brand {
        padding-top: 8px;
    }

    &__logo {
        display: inline-flex;
        align-items: baseline;
        gap: 2px;
        color: #fff;
        letter-spacing: 0.04em;
    }

    &__mark {
        font-size: 48px;
        line-height: 1;
        font-weight: 700;
    }

    &__text {
        font-size: 34px;
        line-height: 1;
        font-weight: 600;
    }

    &__columns {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 120px;
    }

    &__column {
        display: flex;
        flex-direction: column;
        gap: 20px;

        h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 600;
        }

        a {
            color: rgba(255, 255, 255, 0.78);
            font-size: 14px;
            text-decoration: none;
        }
    }

    &__divider {
        height: 1px;
        margin-top: 106px;
        margin-bottom: 40px;
        background: repeating-linear-gradient(
            to right,
            rgba(255, 255, 255, 0.2),
            rgba(255, 255, 255, 0.2) 6px,
            transparent 6px,
            transparent 12px
        );
    }

    &__bottom {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 40px;
    }

    &__meta,
    &__socials {
        display: flex;
        align-items: center;
        gap: 28px;
        color: rgba(255, 255, 255, 0.78);
        font-size: 14px;
    }

    a {
        color: inherit;
        text-decoration: none;
    }
}
</style>
