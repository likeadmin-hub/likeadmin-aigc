<template>
    <aside v-if="shouldShow" class="tutorial-popup">
        <div class="tutorial-popup__label">新教程</div>
        <button class="tutorial-popup__close" type="button" aria-label="关闭" @click="visible = false">
            ×
        </button>
        <img
            class="tutorial-popup__image"
            :src="tutorialPopup.image"
            :alt="tutorialPopup.title"
            @click="openLatestVideo"
        />
        <button class="tutorial-popup__title" type="button" @click="openLatestVideo">
            {{ tutorialPopup.title }}
        </button>
    </aside>
</template>

<script lang="ts" setup>
const router = useRouter()
const route = useRoute()
const visible = ref(true)
const shouldShow = computed(() => visible.value && !route.path.startsWith('/ai'))

const tutorialPopup = {
    title: 'AIGC商业视频广告实战进阶课',
    image: '/figma-home/9_253.png'
}

const openLatestVideo = () => {
    if (route.path === '/academy' && typeof window !== 'undefined') {
        window.scrollTo({ top: 0, behavior: 'smooth' })
        return
    }

    router.push('/academy')
}
</script>

<style lang="scss" scoped>
.tutorial-popup__close,
.tutorial-popup__title {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.tutorial-popup {
    position: fixed;
    right: 88px;
    bottom: 52px;
    z-index: 30;
    width: 260px;
    height: 210px;
    padding: 12px;
    border-radius: 12px;
    background: #222;
    box-shadow: 0 12px 28px rgba(34, 34, 34, 0.18);

    &__label {
        color: #a1a1a1;
        font-size: 12px;
        line-height: 12px;
    }

    &__close {
        position: absolute;
        top: 8px;
        right: 8px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: transparent;
        color: #fff;
        font-size: 20px;
        line-height: 20px;
    }

    &__image {
        display: block;
        width: 236px;
        height: 123px;
        margin-top: 10px;
        border-radius: 4px;
        object-fit: cover;
        cursor: pointer;
    }

    &__title {
        width: 100%;
        margin-top: 10px;
        padding: 0;
        background: transparent;
        text-align: left;
        color: #fff;
        font-size: 14px;
        line-height: 20px;
        font-weight: 500;
    }
}
</style>
