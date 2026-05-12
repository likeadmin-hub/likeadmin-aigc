<template>
    <div class="site-login-modal__register">
        <div class="site-login-modal__fields">
            <label class="site-login-modal__field">
                <span class="site-login-modal__field-label">手机号</span>
                <div
                    class="site-login-modal__input site-login-modal__input--phone"
                >
                    <span class="site-login-modal__phone-prefix">+86</span>
                    <input
                        v-model.trim="formData.mobile"
                        type="text"
                        inputmode="numeric"
                        maxlength="11"
                        :placeholder="
                            hasMobile ? '请输入新手机号' : '请输入手机号码'
                        "
                    />
                </div>
            </label>

            <label class="site-login-modal__field">
                <span class="site-login-modal__field-label">验证码</span>
                <div
                    class="site-login-modal__input site-login-modal__input--code"
                >
                    <input
                        v-model.trim="formData.code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="请输入验证码"
                    />
                    <button
                        class="site-login-modal__code-btn"
                        type="button"
                        :disabled="codeSending || countdown > 0"
                        @click="sendSms"
                    >
                        {{ codeButtonText }}
                    </button>
                </div>
            </label>
        </div>

        <div class="site-login-modal__form-footer">
            <button
                class="site-login-modal__submit"
                type="button"
                :disabled="isLock"
                @click="handleConfirmLock"
            >
                {{ isLock ? '提交中...' : '确认' }}
            </button>
        </div>
    </div>
</template>

<script lang="ts" setup>
import { smsSend } from '~~/api/app'
import { userBindMobile } from '~~/api/user'
import { SMSEnum } from '~~/enums/appEnums'
import { useAccount } from './useAccount'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'

const { toggleShowPopup, popupType, showPopup } = useAccount()
const userStore = useUserStore()

const hasMobile = computed(() => !!userStore.userInfo.mobile)
const formData = reactive({
    type: hasMobile.value ? 'change' : 'bind',
    mobile: '',
    code: ''
})

const codeSending = ref(false)
const countdown = ref(0)
let countdownTimer: ReturnType<typeof setInterval> | null = null

const codeButtonText = computed(() => {
    if (codeSending.value) return '发送中'
    if (countdown.value > 0) return `${countdown.value}s`
    return '获取验证码'
})

const clearCountdown = () => {
    if (countdownTimer) {
        clearInterval(countdownTimer)
        countdownTimer = null
    }
}

const startCountdown = () => {
    countdown.value = 60
    clearCountdown()
    countdownTimer = setInterval(() => {
        if (countdown.value <= 1) {
            countdown.value = 0
            clearCountdown()
            return
        }
        countdown.value -= 1
    }, 1000)
}

const validateMobile = () => {
    if (!/^1\d{10}$/.test(formData.mobile)) {
        feedback.msgError('请输入正确的手机号')
        return false
    }
    return true
}

const sendSms = async () => {
    if (codeSending.value || countdown.value > 0) return
    if (!validateMobile()) return
    codeSending.value = true
    try {
        await smsSend({
            scene: hasMobile.value
                ? SMSEnum.CHANGE_MOBILE
                : SMSEnum.BIND_MOBILE,
            mobile: formData.mobile
        })
        feedback.msgSuccess('验证码已发送')
        startCountdown()
    } finally {
        codeSending.value = false
    }
}

const handleConfirm = async () => {
    if (!validateMobile()) return
    if (!formData.code) {
        feedback.msgError('请输入短信验证码')
        return
    }

    formData.type = hasMobile.value ? 'change' : 'bind'

    if (userStore.isLogin) {
        await userBindMobile(formData)
    } else {
        await userBindMobile(formData, { token: userStore.temToken })
        userStore.login(userStore.temToken)
        await userStore.getUser()
    }
    toggleShowPopup(false)
}

const { lockFn: handleConfirmLock, isLock } = useLockFn(handleConfirm)

watch(
    () => showPopup.value && popupType.value,
    () => {
        const active = showPopup.value && popupType.value
        if (!active) {
            formData.mobile = ''
            formData.code = ''
            clearCountdown()
            countdown.value = 0
            codeSending.value = false
        }
    }
)

onBeforeUnmount(() => {
    clearCountdown()
})
</script>

<style lang="scss" scoped></style>
