<template>
    <div>
        <ElDropdown v-if="userStore.isLogin" @command="handleCommand">
            <div class="flex items-center">
                <ElAvatar :size="25" :src="avatarSrc" />
                <div class="ml-1 text-white text-lg flex">
                    <span class="mr-2">个人中心</span>
                    <ElIcon><ArrowDown /></ElIcon>
                </div>
            </div>
            <template #dropdown>
                <ElDropdownMenu>
                    <NuxtLink to="/user/info">
                        <ElDropdownItem command="user">个人信息</ElDropdownItem>
                    </NuxtLink>
                    <NuxtLink to="/user/collection">
                        <ElDropdownItem command="collect">
                            我的收藏
                        </ElDropdownItem>
                    </NuxtLink>
                    <NuxtLink to="/account/security">
                        <ElDropdownItem command="account">
                            账号安全
                        </ElDropdownItem>
                    </NuxtLink>
                    <ElDropdownItem command="logout">退出登录</ElDropdownItem>
                </ElDropdownMenu>
            </template>
        </ElDropdown>

        <div v-else class="cursor-pointer text-lg" @click="handleToLogin">
            登录/注册
        </div>
    </div>
</template>
<script lang="ts" setup>
import {
    ElAvatar,
    ElDropdown,
    ElDropdownMenu,
    ElDropdownItem,
    ElIcon
} from 'element-plus'
import { ArrowDown } from '@element-plus/icons-vue'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import feedback from '~~/utils/feedback'
import { logout } from '~~/api/account'
import { normalizeFileUrl } from '@/utils/file-url'
const userStore = useUserStore()
const route = useRoute()
const { openPcLoginModal } = usePcLoginGate()

const avatarSrc = computed(() =>
    normalizeFileUrl(userStore.userInfo?.avatar, userStore.avatarVersion)
)

const handleToLogin = () => {
    openPcLoginModal({ redirect: route.fullPath })
}

const handleCommand = async (command: string) => {
    switch (command) {
        case 'logout':
            await feedback.confirm('确定退出登录吗？')
            await logout()
            userStore.logout()
    }
}
</script>

<style lang="scss" scoped></style>
