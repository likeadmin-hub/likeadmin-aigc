<template>
    <div class="account">
        <ClientOnly>
            <PcAccountModalShell
                v-if="popupType === PopupTypeEnum.BIND_MOBILE"
                v-model="showPopup"
                compact
                :aria-label="bindMobileTitle"
                :content-title="bindMobileTitle"
                content-subtitle="请使用可接收验证码的手机号"
            >
                <BindMobile />
            </PcAccountModalShell>

            <ElDialog
                v-else
                v-model="showPopup"
                :width="400"
                :close-on-click-modal="false"
            >
                <div class="px-5 text-tx-primary">
                    <Login v-show="popupType == PopupTypeEnum.LOGIN" />
                    <Register v-show="popupType == PopupTypeEnum.REGISTER" />
                    <ForgotPwd v-show="popupType == PopupTypeEnum.FORGOT_PWD" />
                </div>
            </ElDialog>
        </ClientOnly>
    </div>
</template>
<script lang="ts" setup>
import { ElDialog } from 'element-plus'
import Login from './login.vue'
import { useAccount, PopupTypeEnum } from './useAccount'
import Register from './register.vue'
import ForgotPwd from './forgot-pwd.vue'
import BindMobile from './bind-mobile.vue'
import PcAccountModalShell from '@/components/pc-account-modal-shell.vue'
import { useUserStore } from '~~/stores/user'

const { popupType, showPopup } = useAccount()
const userStore = useUserStore()

const bindMobileTitle = computed(() =>
    userStore.userInfo.mobile ? '更换手机号' : '绑定手机号'
)

watch(showPopup, (value) => {
    if (!value) userStore.temToken = null
})
</script>

<style lang="scss" scoped></style>
