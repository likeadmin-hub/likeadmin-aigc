<template>
    <UserCenterLayout
        page-title="账号安全"
        page-desc="管理登录密码与第三方账号绑定。"
    >
        <div class="uc-security">
            <div class="uc-security-item">
                <div class="uc-security-item__main">
                    <div class="uc-security-item__title">登录密码</div>
                    <div class="uc-security-item__desc">
                        用于账号登录，建议定期更换。
                    </div>
                </div>
                <button
                    type="button"
                    class="uc-security-item__action"
                    @click="showPwdModal = true"
                >
                    {{ userInfo?.has_password ? '点击修改' : '点击设置' }}
                </button>
            </div>

            <div class="uc-security-item">
                <div class="uc-security-item__main">
                    <div class="uc-security-item__title">绑定微信</div>
                    <div class="uc-security-item__desc">
                        微信扫码快捷登录（若已开启）。
                    </div>
                </div>
                <span class="uc-security-item__status">{{
                    userInfo?.has_auth ? '已绑定' : '未绑定'
                }}</span>
            </div>
        </div>

        <ClientOnly>
            <PcAccountModalShell
                v-model="showPwdModal"
                compact
                aria-label="登录密码"
                :content-title="
                    userInfo?.has_password ? '修改登录密码' : '设置登录密码'
                "
                content-subtitle="请使用 6–20 位字母数字组合"
            >
                <div class="site-login-modal__register">
                    <ElForm
                        ref="formRef"
                        class="pwd-security-form"
                        :model="formData"
                        :rules="formRules"
                    >
                        <div class="site-login-modal__fields pwd-security-fields">
                            <ElFormItem
                                v-if="userInfo?.has_password"
                                prop="old_password"
                                class="pwd-form-item"
                            >
                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label"
                                        >原密码</span
                                    >
                                    <div
                                        class="site-login-modal__input site-login-modal__input--el"
                                    >
                                        <ElInput
                                            v-model="formData.old_password"
                                            type="password"
                                            show-password
                                            placeholder="请输入原密码"
                                            autocomplete="current-password"
                                        />
                                    </div>
                                </label>
                            </ElFormItem>

                            <ElFormItem prop="password" class="pwd-form-item">
                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label"
                                        >新密码</span
                                    >
                                    <div
                                        class="site-login-modal__input site-login-modal__input--el"
                                    >
                                        <ElInput
                                            v-model="formData.password"
                                            type="password"
                                            show-password
                                            placeholder="请输入6-20位字母数字组合"
                                            autocomplete="new-password"
                                        />
                                    </div>
                                </label>
                            </ElFormItem>

                            <ElFormItem
                                prop="password_confirm"
                                class="pwd-form-item"
                            >
                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label"
                                        >确认新密码</span
                                    >
                                    <div
                                        class="site-login-modal__input site-login-modal__input--el"
                                    >
                                        <ElInput
                                            v-model="formData.password_confirm"
                                            type="password"
                                            show-password
                                            placeholder="请再次输入密码"
                                            autocomplete="new-password"
                                        />
                                    </div>
                                </label>
                            </ElFormItem>
                        </div>

                        <div
                            v-if="userInfo?.has_password"
                            class="site-login-modal__field-extra"
                        >
                            <button
                                type="button"
                                class="site-login-modal__forgot"
                                @click="toForgetPwd"
                            >
                                忘记原密码
                            </button>
                        </div>

                        <div class="site-login-modal__form-footer">
                            <button
                                type="button"
                                class="site-login-modal__submit"
                                :disabled="isLock"
                                @click="handleConfirmLock"
                            >
                                {{ isLock ? '提交中…' : '确认' }}
                            </button>
                        </div>
                    </ElForm>
                </div>
            </PcAccountModalShell>
        </ClientOnly>
    </UserCenterLayout>
</template>

<script lang="ts" setup>
import { getUserInfo, userChangePwd } from '@/api/user'
import PcAccountModalShell from '@/components/pc-account-modal-shell.vue'
import UserCenterLayout from '@/components/user-center-layout.vue'
import {
    PopupTypeEnum,
    useAccount
} from '~~/layouts/components/account/useAccount'
import { isPcLoginRequiredError } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import { ElForm, ElFormItem, ElInput, FormInstance, FormRules } from 'element-plus'
import { computed, nextTick } from 'vue'

const userStore = useUserStore()
const { setPopupType, toggleShowPopup } = useAccount()
const userInfo = ref<Record<string, any>>({})

const refresh = async () => {
    if (!userStore.isLogin) {
        userInfo.value = {}
        return
    }
    try {
        userInfo.value = await getUserInfo()
    } catch (error) {
        if (isPcLoginRequiredError(error)) return
        throw error
    }
}

const showPwdModal = ref(false)
const formRef = shallowRef<FormInstance>()

const formData = reactive({
    old_password: '',
    password: '',
    password_confirm: ''
})

const formRules = computed<FormRules>(() => ({
    ...(userInfo.value?.has_password
        ? {
              old_password: [
                  {
                      required: true,
                      message: '请输入原密码',
                      trigger: ['change', 'blur']
                  }
              ]
          }
        : {}),
    password: [
        {
            required: true,
            message: '请输入6-20位字母数字组合',
            trigger: ['change', 'blur']
        },
        {
            min: 6,
            max: 20,
            message: '密码长度应为6-20',
            trigger: ['change', 'blur']
        },
        {
            pattern: /^[A-Za-z0-9]+$/,
            message: '密码须为字母数字组合',
            trigger: ['change', 'blur']
        }
    ],
    password_confirm: [
        {
            validator(_rule: unknown, value: string, callback: (e?: Error) => void) {
                if (value === '') {
                    callback(new Error('请再次输入密码'))
                } else if (value !== formData.password) {
                    callback(new Error('两次输入的密码不一致'))
                } else {
                    callback()
                }
            },
            trigger: ['change', 'blur']
        }
    ]
}))

const resetPwdForm = () => {
    formData.old_password = ''
    formData.password = ''
    formData.password_confirm = ''
}

watch(showPwdModal, (open) => {
    if (open) {
        resetPwdForm()
        nextTick(() => formRef.value?.clearValidate())
    }
})

watch(() => userStore.isLogin, (loggedIn) => {
    if (!loggedIn) {
        userInfo.value = {}
        return
    }
    refresh()
}, { immediate: true })

const toForgetPwd = () => {
    showPwdModal.value = false
    setPopupType(PopupTypeEnum.FORGOT_PWD)
    toggleShowPopup(true)
}

const handleConfirm = async () => {
    await formRef.value?.validate()
    await userChangePwd(formData)
    userStore.logout()
    showPwdModal.value = false
    refresh()
}

const { lockFn: handleConfirmLock, isLock } = useLockFn(handleConfirm)

definePageMeta({
    layout: 'blank',
    module: 'personal',
    auth: true
})
</script>

<style lang="scss" scoped>
.uc-security {
    width: 100%;
}

.uc-security-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 0;
    border-bottom: 1px solid rgba(34, 34, 34, 0.08);

    &:first-child {
        padding-top: 0;
    }
}

.uc-security-item__title {
    color: #222;
    font-size: 18px;
    font-weight: 600;
    line-height: 24px;
}

.uc-security-item__desc {
    margin-top: 8px;
    color: #8b8b8b;
    font-size: 13px;
    line-height: 22px;
    max-width: 520px;
}

.uc-security-item__action {
    flex: none;
    appearance: none;
    border: 0;
    background: transparent;
    padding: 4px 0;
    color: #1f1f1f;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
    transition: color 0.15s ease;

    &:hover {
        color: #000;
    }
}

.uc-security-item__status {
    flex: none;
    color: #686868;
    font-size: 14px;
    font-weight: 500;
}

.pwd-security-fields {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.pwd-security-form {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.pwd-form-item {
    margin-bottom: 0;

    :deep(.el-form-item__content) {
        display: block;
    }

    :deep(.el-form-item__error) {
        padding-top: 6px;
    }
}
</style>
