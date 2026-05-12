<template>
    <view class="page">
        <view v-for="item in lists" :key="item.id" class="item">
            <view class="prompt">{{ item.prompt }}</view>
            <view class="meta">{{ item.ratio }} / {{ item.style }} / {{ item.quantity }}条</view>
            <view class="status">{{ item.status }}</view>
        </view>
    </view>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { onShow } from '@dcloudio/uni-app'
import { getAigcVideoTasks } from '@/apps/aigc_video/api'

const lists = ref<any[]>([])
onShow(async () => {
    lists.value = await getAigcVideoTasks()
})
</script>

<style lang="scss" scoped>
.page {
    padding: 24rpx;
    min-height: 100vh;
    background: #f6f7f9;
}
.item {
    padding: 24rpx;
    margin-bottom: 18rpx;
    background: #fff;
    border-radius: 12rpx;
}
.prompt {
    font-size: 30rpx;
    font-weight: 500;
}
.meta {
    margin-top: 12rpx;
    color: #7a8499;
    font-size: 24rpx;
}
.status {
    margin-top: 12rpx;
    color: #2c6cff;
}
</style>
