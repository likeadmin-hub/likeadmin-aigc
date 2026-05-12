<template>
    <button class="site-membership-tip" type="button">
        <span class="site-membership-tip__title">{{ title }}</span>
        <span class="site-membership-tip__price">{{ price }}</span>
        <span class="site-membership-tip__link">{{ linkText }}</span>
    </button>
</template>

<script lang="ts" setup>
withDefaults(
    defineProps<{
        title?: string
        price?: string
        linkText?: string
    }>(),
    {
        title: '开通会员，解锁更多精品课程',
        price: '会员低至 ¥9.00/月',
        linkText: '查看会员权益'
    }
)
</script>

<style lang="scss" scoped>
.site-membership-tip {
    z-index: 18;
    display: flex;
    flex-direction: column;
    gap: 4px;
    width: 263px;
    padding: 20px 24px;
    border: 0;
    border-radius: 12px;
    background: #ededed;
    color: #222;
    cursor: pointer;
    text-align: left;

    &__title,
    &__price,
    &__link {
        display: block;
        font-size: 14px;
        line-height: 1.4285714286;
    }

    &__price {
        color: #ff6600;
        font-weight: 700;
    }

    &__link {
        margin-top: 12px;
        font-weight: 500;
        text-decoration: underline;
    }
}
</style>
