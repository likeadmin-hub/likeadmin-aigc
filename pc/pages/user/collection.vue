<template>
    <UserCenterLayout
        page-title="我的收藏"
        page-desc="这里是您收藏过的文章与资讯。"
    >
        <div v-if="data?.lists?.length">
            <div
                v-for="item in data.lists"
                :key="item.id"
                class="uc-collect-item"
                @click="$router.push(`/news/${item.article_id}`)"
            >
                <img
                    v-if="item.image"
                    :src="item.image"
                    alt=""
                    class="uc-collect-img"
                />
                <div class="uc-collect-body">
                    <div class="uc-collect-title">{{ item.title }}</div>
                    <div class="uc-collect-desc">{{ item.desc }}</div>
                    <div class="uc-collect-meta">
                        <span>收藏于 {{ item.collect_time }}</span>
                        <button
                            type="button"
                            class="uc-cancel-btn"
                            @click.stop="handelCollect(item.article_id)"
                        >
                            取消收藏
                        </button>
                    </div>
                </div>
            </div>
            <div class="uc-pagination">
                <ElPagination
                    v-model:current-page="params.page_no"
                    :total="data.count"
                    :page-size="params.page_size"
                    hide-on-single-page
                    layout="total, prev, pager, next, jumper"
                    @current-change="refresh()"
                />
            </div>
        </div>

        <div v-else class="uc-empty">
            <ElEmpty
                :image="empty_news"
                description="暂无收藏"
                :image-size="220"
            />
        </div>
    </UserCenterLayout>
</template>
<script lang="ts" setup>
import { cancelCollect, getCollect } from '~~/api/news'
import empty_news from '@/assets/images/empty_news.png'
import { ElPagination, ElEmpty } from 'element-plus'
import UserCenterLayout from '@/components/user-center-layout.vue'
import { isPcLoginRequiredError } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import feedback from '~~/utils/feedback'

const params = reactive({
    page_no: 1,
    page_size: 15
})
const userStore = useUserStore()
const data = ref<any>({ lists: [], count: 0 })
const refresh = async () => {
    if (!userStore.isLogin) {
        data.value = { lists: [], count: 0 }
        return
    }
    try {
        data.value = await getCollect(params)
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}
watch(() => userStore.isLogin, refresh, { immediate: true })
const handelCollect = async (id: number | string) => {
    await cancelCollect({ id })
    feedback.msgSuccess('已取消收藏')
    refresh()
}
definePageMeta({
    layout: 'blank',
    module: 'personal',
    auth: true
})
</script>
<style lang="scss" scoped>
.uc-collect-item {
    display: flex;
    align-items: center;
    gap: 20px;
    padding: 24px 0;
    border-bottom: 1px solid rgba(34, 34, 34, 0.08);
    cursor: pointer;
    transition: background 0.15s ease;
    &:hover {
        background: rgba(34, 34, 34, 0.02);
    }
}
.uc-collect-img {
    flex: none;
    width: 200px;
    height: 140px;
    border-radius: 4px;
    object-fit: cover;
}
.uc-collect-body {
    flex: 1;
    min-width: 0;
}
.uc-collect-title {
    color: #222;
    font-size: 18px;
    font-weight: 600;
    line-height: 24px;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 1;
    overflow: hidden;
    text-overflow: ellipsis;
}
.uc-collect-desc {
    margin-top: 12px;
    color: #666;
    font-size: 13px;
    line-height: 22px;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    text-overflow: ellipsis;
}
.uc-collect-meta {
    margin-top: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: #a1a1a1;
    font-size: 12px;
}
.uc-cancel-btn {
    appearance: none;
    border: 0;
    background: transparent;
    padding: 0;
    color: #a1a1a1;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
    &:hover {
        color: #d64545;
    }
}
.uc-pagination {
    margin-top: 24px;
    display: flex;
    justify-content: flex-end;
}
.uc-empty {
    padding: 60px 0;
    display: flex;
    justify-content: center;
}
</style>
