<template>
    <div v-if="content.enabled" class="list-nav">
        <div v-for="(item, index) in showList" :key="index" class="list-nav-item">
            <el-image v-if="item.image" class="icon" :src="item.image" fit="cover" />
            <div class="min-w-0 flex-1">
                <div class="name">{{ item.name }}</div>
                <div v-if="item.desc" class="desc">{{ item.desc }}</div>
            </div>
            <icon name="el-icon-ArrowRight" />
        </div>
    </div>
</template>

<script lang="ts" setup>
const props = defineProps({
    content: {
        type: Object,
        default: () => ({})
    }
})
const showList = computed(() =>
    (props.content.data || []).filter((item: any) => item.is_show !== '0')
)
</script>

<style scoped lang="scss">
.list-nav {
    margin: 10px 12px;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
}
.list-nav-item {
    min-height: 50px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-bottom: 1px solid #f2f2f2;
}
.list-nav-item:last-child {
    border-bottom: 0;
}
.icon {
    width: 28px;
    height: 28px;
    border-radius: 4px;
}
.name {
    font-size: 14px;
    color: #222;
}
.desc {
    margin-top: 2px;
    font-size: 12px;
    color: #999;
}
</style>
