<template>
    <div class="ripple-grid-bg" aria-hidden="true">
        <div class="ripple-grid-bg__grid"></div>
        <div class="ripple-grid-bg__fade"></div>
        <span class="ripple-grid-bg__ripple ripple-grid-bg__ripple--one"></span>
        <span class="ripple-grid-bg__ripple ripple-grid-bg__ripple--two"></span>
        <span class="ripple-grid-bg__ripple ripple-grid-bg__ripple--three"></span>
    </div>
</template>

<style lang="scss" scoped>
.ripple-grid-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 0;
    background: #f8f8f8;

    &__grid,
    &__fade,
    &__ripple {
        position: absolute;
        inset: 0;
    }

    &__grid {
        background-image:
            linear-gradient(rgba(194, 194, 194, 0.26) 1px, transparent 1px),
            linear-gradient(90deg, rgba(194, 194, 194, 0.26) 1px, transparent 1px);
        background-size: 42px 42px;
        background-position: center center;
        mask-image: radial-gradient(circle at center, rgba(0, 0, 0, 0.96) 0%, rgba(0, 0, 0, 0.88) 42%, rgba(0, 0, 0, 0.32) 72%, transparent 100%);
        -webkit-mask-image: radial-gradient(circle at center, rgba(0, 0, 0, 0.96) 0%, rgba(0, 0, 0, 0.88) 42%, rgba(0, 0, 0, 0.32) 72%, transparent 100%);
        opacity: 0.72;
    }

    &__fade {
        background:
            radial-gradient(circle at center, rgba(248, 248, 248, 0) 0%, rgba(248, 248, 248, 0.14) 44%, rgba(248, 248, 248, 0.88) 76%, #f8f8f8 100%);
    }

    &__ripple {
        inset: 50% auto auto 50%;
        width: 1200px;
        height: 1200px;
        border-radius: 50%;
        border: 1px solid rgba(194, 194, 194, 0.34);
        transform: translate(-50%, -50%) scale(0.22);
        transform-origin: center;
        opacity: 0;
        animation: ripple-grid-wave 8.6s ease-out infinite;
    }

    &__ripple--two {
        animation-delay: 2.85s;
    }

    &__ripple--three {
        animation-delay: 5.7s;
    }
}

@keyframes ripple-grid-wave {
    0% {
        transform: translate(-50%, -50%) scale(0.2);
        opacity: 0;
    }

    12% {
        opacity: 0.72;
    }

    72% {
        opacity: 0.2;
    }

    100% {
        transform: translate(-50%, -50%) scale(1);
        opacity: 0;
    }
}
</style>
