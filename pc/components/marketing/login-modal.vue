<template>
    <Teleport to="body">
        <transition name="login-modal-fade">
            <div
                v-if="modelValue"
                class="site-login-modal"
                role="dialog"
                aria-modal="true"
                :aria-label="dialogTitle"
                @click="close"
            >
                <div class="site-login-modal__backdrop"></div>

                <div ref="panelRef" class="site-login-modal__panel" @click.stop>
                    <section class="site-login-modal__hero">
                        <div class="site-login-modal__hero-top">
                            <span class="site-login-modal__hero-greeting">{{ heroGreeting }}</span>
                        </div>

                        <div class="site-login-modal__hero-visual">
                            <span class="site-login-modal__hero-mark">A<span class="dot">.</span></span>
                            <span class="site-login-modal__hero-smiley" aria-hidden="true">
                                <svg viewBox="0 0 64 64" width="68" height="68" fill="none">
                                    <circle cx="32" cy="32" r="30" fill="#c9f04a" stroke="#1f1f1f" stroke-width="3"/>
                                    <circle cx="22" cy="26" r="3.4" fill="#1f1f1f"/>
                                    <circle cx="42" cy="26" r="3.4" fill="#1f1f1f"/>
                                    <path d="M20 38 Q32 50 44 38" stroke="#1f1f1f" stroke-width="3" stroke-linecap="round" fill="none"/>
                                </svg>
                            </span>
                        </div>

                        <div class="site-login-modal__hero-foot">
                            <div class="site-login-modal__hero-copy">
                                <span class="site-login-modal__hero-eyebrow">{{ heroEyebrow }}</span>
                                <h2>{{ heroTitle }}</h2>
                                <p>{{ heroDescription }}</p>
                            </div>

                            <div class="site-login-modal__hero-switch">
                                <span>{{ switchPrompt }}</span>
                                <button
                                    class="site-login-modal__hero-link"
                                    type="button"
                                    @click="toggleAuthView"
                                >
                                    {{ switchActionText }}
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="site-login-modal__content">
                        <header class="site-login-modal__content-head">
                            <h3 class="site-login-modal__content-title">{{ contentTitle }}</h3>
                            <span class="site-login-modal__content-sub">{{ contentSubtitle }}</span>
                        </header>

                        <div v-if="authView === 'login'" class="site-login-modal__auth">
                            <div v-if="loginTabCount > 1" class="site-login-modal__tabs">
                                <button
                                    v-if="allowMobileLogin"
                                    :class="['site-login-modal__tab', { 'is-active': loginTab === 'mobile' }]"
                                    type="button"
                                    @click="setLoginTab('mobile')"
                                >
                                    手机号
                                </button>
                                <button
                                    v-if="allowAccountLogin"
                                    :class="['site-login-modal__tab', { 'is-active': loginTab === 'account' }]"
                                    type="button"
                                    @click="setLoginTab('account')"
                                >
                                    账号密码
                                </button>
                                <button
                                    v-if="allowWechatLogin"
                                    :class="['site-login-modal__tab', { 'is-active': loginTab === 'wechat' }]"
                                    type="button"
                                    @click="setLoginTab('wechat')"
                                >
                                    微信扫码
                                </button>
                            </div>

                            <div v-if="loginTab === 'mobile'" class="site-login-modal__form">
                                <div class="site-login-modal__fields">
                                    <label class="site-login-modal__field">
                                        <span class="site-login-modal__field-label">手机号</span>
                                        <div class="site-login-modal__input site-login-modal__input--phone">
                                            <span class="site-login-modal__phone-prefix">+86</span>
                                            <input
                                                v-model.trim="mobileForm.mobile"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="11"
                                                placeholder="请输入手机号"
                                            />
                                        </div>
                                    </label>

                                    <label class="site-login-modal__field">
                                        <span class="site-login-modal__field-label">验证码</span>
                                        <div class="site-login-modal__input site-login-modal__input--code">
                                            <input
                                                v-model.trim="mobileForm.code"
                                                type="text"
                                                inputmode="numeric"
                                                maxlength="6"
                                                placeholder="请输入验证码"
                                            />
                                            <button
                                                class="site-login-modal__code-btn"
                                                type="button"
                                                :disabled="codeSending || countdown > 0"
                                                @click="sendCode"
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
                                        :disabled="submitLoading"
                                        @click="submitMobileLogin"
                                    >
                                        {{ submitLoading ? '登录中...' : '立即登录' }}
                                    </button>

                                    <p v-if="requireAgreement" class="site-login-modal__agreement">
                                        {{ agreementActionText }}
                                        <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`" target="_blank">
                                            《用户协议》
                                        </NuxtLink>
                                        和
                                        <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`" target="_blank">
                                            《隐私政策》
                                        </NuxtLink>
                                    </p>
                                </div>
                            </div>

                            <div v-else-if="loginTab === 'account'" class="site-login-modal__form">
                                <div class="site-login-modal__fields">
                                    <label class="site-login-modal__field">
                                        <span class="site-login-modal__field-label">账号</span>
                                        <div class="site-login-modal__input">
                                            <input
                                                v-model.trim="accountForm.account"
                                                type="text"
                                                maxlength="40"
                                                placeholder="请输入账号或手机号"
                                            />
                                        </div>
                                    </label>

                                    <label class="site-login-modal__field">
                                        <span class="site-login-modal__field-label">密码</span>
                                        <div class="site-login-modal__input">
                                            <input
                                                v-model.trim="accountForm.password"
                                                type="password"
                                                maxlength="20"
                                                placeholder="请输入密码"
                                            />
                                        </div>
                                        <div class="site-login-modal__field-extra">
                                            <button
                                                class="site-login-modal__forgot"
                                                type="button"
                                                @click="openForgotPassword"
                                            >
                                                忘记密码？
                                            </button>
                                        </div>
                                    </label>
                                </div>

                                <div class="site-login-modal__form-footer">
                                    <button
                                        class="site-login-modal__submit"
                                        type="button"
                                        :disabled="submitLoading"
                                        @click="submitAccountLogin"
                                    >
                                        {{ submitLoading ? '登录中...' : '立即登录' }}
                                    </button>

                                    <p v-if="requireAgreement" class="site-login-modal__agreement">
                                        {{ agreementActionText }}
                                        <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`" target="_blank">
                                            《用户协议》
                                        </NuxtLink>
                                        和
                                        <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`" target="_blank">
                                            《隐私政策》
                                        </NuxtLink>
                                    </p>
                                </div>
                            </div>

                            <div v-else class="site-login-modal__wechat-pane">
                                <div class="site-login-modal__wechat-box">
                                    <div
                                        v-if="wechatQrSvg"
                                        class="site-login-modal__wechat-qr"
                                        v-html="wechatQrSvg"
                                    ></div>
                                    <div v-else class="site-login-modal__wechat-loading">
                                        {{ wechatLoading ? '二维码加载中...' : '正在准备二维码...' }}
                                    </div>
                                </div>

                                <p class="site-login-modal__wechat-text">
                                    打开微信扫一扫，使用微信快捷登录
                                </p>

                                <p v-if="requireAgreement" class="site-login-modal__agreement">
                                    {{ agreementActionText }}
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`" target="_blank">
                                        《用户协议》
                                    </NuxtLink>
                                    和
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`" target="_blank">
                                        《隐私政策》
                                    </NuxtLink>
                                </p>
                            </div>
                        </div>

                        <div v-else-if="authView === 'register'" class="site-login-modal__register">
                            <div class="site-login-modal__fields">
                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">账号</span>
                                    <div class="site-login-modal__input">
                                        <input
                                            v-model.trim="registerForm.account"
                                            type="text"
                                            maxlength="12"
                                            placeholder="请输入要注册的账号"
                                        />
                                    </div>
                                </label>

                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">密码</span>
                                    <div class="site-login-modal__input">
                                        <input
                                            v-model.trim="registerForm.password"
                                            type="password"
                                            maxlength="20"
                                            placeholder="请输入 6-20 位密码"
                                        />
                                    </div>
                                </label>

                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">确认密码</span>
                                    <div class="site-login-modal__input">
                                        <input
                                            v-model.trim="registerForm.passwordConfirm"
                                            type="password"
                                            maxlength="20"
                                            placeholder="请再次输入密码"
                                        />
                                    </div>
                                </label>
                            </div>

                            <div class="site-login-modal__form-footer">
                                <button
                                    class="site-login-modal__submit"
                                    type="button"
                                    :disabled="registerLoading"
                                    @click="submitRegister"
                                >
                                    {{ registerLoading ? '注册中...' : '立即注册' }}
                                </button>

                                <p v-if="requireAgreement" class="site-login-modal__agreement">
                                    {{ agreementActionText }}
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`" target="_blank">
                                        《用户协议》
                                    </NuxtLink>
                                    和
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`" target="_blank">
                                        《隐私政策》
                                    </NuxtLink>
                                </p>
                            </div>
                        </div>

                        <div v-else class="site-login-modal__register">
                            <div class="site-login-modal__fields">
                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">手机号</span>
                                    <div class="site-login-modal__input site-login-modal__input--phone">
                                        <span class="site-login-modal__phone-prefix">+86</span>
                                        <input
                                            v-model.trim="forgotForm.mobile"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="11"
                                            placeholder="请输入注册手机号"
                                        />
                                    </div>
                                </label>

                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">验证码</span>
                                    <div class="site-login-modal__input site-login-modal__input--code">
                                        <input
                                            v-model.trim="forgotForm.code"
                                            type="text"
                                            inputmode="numeric"
                                            maxlength="6"
                                            placeholder="请输入验证码"
                                        />
                                        <button
                                            class="site-login-modal__code-btn"
                                            type="button"
                                            :disabled="codeSending || countdown > 0"
                                            @click="sendForgotCode"
                                        >
                                            {{ codeButtonText }}
                                        </button>
                                    </div>
                                </label>

                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">新密码</span>
                                    <div class="site-login-modal__input">
                                        <input
                                            v-model.trim="forgotForm.password"
                                            type="password"
                                            maxlength="20"
                                            placeholder="请输入 6-20 位字母数字组合"
                                        />
                                    </div>
                                </label>

                                <label class="site-login-modal__field">
                                    <span class="site-login-modal__field-label">确认密码</span>
                                    <div class="site-login-modal__input">
                                        <input
                                            v-model.trim="forgotForm.passwordConfirm"
                                            type="password"
                                            maxlength="20"
                                            placeholder="请再次输入新密码"
                                        />
                                    </div>
                                </label>
                            </div>

                            <div class="site-login-modal__form-footer">
                                <button
                                    class="site-login-modal__submit"
                                    type="button"
                                    :disabled="forgotLoading"
                                    @click="submitForgot"
                                >
                                    {{ forgotLoading ? '提交中...' : '重置密码' }}
                                </button>

                                <p v-if="requireAgreement" class="site-login-modal__agreement">
                                    {{ agreementActionText }}
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`" target="_blank">
                                        《用户协议》
                                    </NuxtLink>
                                    和
                                    <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`" target="_blank">
                                        《隐私政策》
                                    </NuxtLink>
                                </p>
                            </div>
                        </div>
                    </section>

                    <button
                        class="site-login-modal__close"
                        type="button"
                        aria-label="关闭登录弹窗"
                        @click.stop="close"
                    >
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <path d="M6 6l12 12M18 6L6 18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { renderSVG } from 'uqr'
import { getWxCodeUrl, login, register } from '@/api/account'
import { smsSend } from '@/api/app'
import { forgotPassword } from '@/api/user'
import { PolicyAgreementEnum, SMSEnum } from '@/enums/appEnums'
import { PopupTypeEnum, useAccount } from '@/layouts/components/account/useAccount'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'
import { useModalBodyScrollLock } from '@/composables/useModalBodyScrollLock'

type AuthView = 'login' | 'register' | 'forgot'
type LoginTab = 'mobile' | 'account' | 'wechat'

const props = defineProps<{
    modelValue: boolean
}>()

const emit = defineEmits<{
    (event: 'update:modelValue', value: boolean): void
    (event: 'login-success'): void
}>()

const appStore = useAppStore()
const userStore = useUserStore()
const { setPopupType, toggleShowPopup } = useAccount()

const authView = ref<AuthView>('login')
const loginTab = ref<LoginTab>('mobile')
const codeSending = ref(false)
const submitLoading = ref(false)
const wechatLoading = ref(false)
const registerLoading = ref(false)
const forgotLoading = ref(false)
const countdown = ref(0)
const panelRef = ref<HTMLElement | null>(null)
const wechatQrSvg = ref('')
const wechatUrl = ref('')
const mobileForm = reactive({
    mobile: '',
    code: ''
})
const accountForm = reactive({
    account: '',
    password: ''
})
const registerForm = reactive({
    account: '',
    password: '',
    passwordConfirm: ''
})
const forgotForm = reactive({
    mobile: '',
    code: '',
    password: '',
    passwordConfirm: ''
})
let countdownTimer: ReturnType<typeof setInterval> | null = null
const { lock: lockPageScroll, unlock: unlockPageScroll } = useModalBodyScrollLock()

const allowMobileLogin = computed(() => true)
const allowAccountLogin = computed(() => {
    const loginWay = appStore.getLoginConfig.login_way
    return Array.isArray(loginWay) && loginWay.length
        ? loginWay.some((item) => Number(item) === 1)
        : true
})

const allowWechatLogin = computed(() => {
    const wechatAuth = appStore.getLoginConfig.wechat_auth
    return typeof wechatAuth === 'undefined' ? true : Number(wechatAuth) === 1
})

const loginTabCount = computed(() => {
    return [allowMobileLogin.value, allowAccountLogin.value, allowWechatLogin.value].filter(Boolean).length
})

const requireAgreement = computed(() => Number(appStore.getLoginConfig.login_agreement) === 1)
const isForceBindMobile = computed(() => Number(appStore.getLoginConfig.coerce_mobile) === 1)
const dialogTitle = computed(() => {
    if (authView.value === 'forgot') return '找回密码'
    if (authView.value === 'register') return '注册账号'
    if (loginTab.value === 'wechat') return '微信扫码登录'
    if (loginTab.value === 'account') return '账号密码登录'
    return '手机号验证码登录'
})
const heroGreeting = computed(() => {
    if (authView.value === 'forgot') return '找回密码'
    if (authView.value === 'register') return '加入我们'
    return '欢迎回来'
})
const heroEyebrow = computed(() => {
    if (authView.value === 'forgot') return '验证手机号 · 重置密码'
    if (authView.value === 'register') return '开启创作之旅'
    return 'A. PART 会员'
})
const heroTitle = computed(() => {
    if (authView.value === 'forgot') return '换一个密码，\n继续你的旅程。'
    if (authView.value === 'register') return '一个账号，\n解锁所有权益。'
    return '继续你的\n创作与灵感。'
})
const heroDescription = computed(() => {
    if (authView.value === 'forgot') return '通过短信验证后即可重置登录密码'
    return '同步站内技能、课程与 AI 应用'
})
const switchPrompt = computed(() => {
    if (authView.value === 'forgot') return '想起密码了？'
    if (authView.value === 'register') return '已有账号？'
    return '还没有账号？'
})
const switchActionText = computed(() => {
    if (authView.value === 'forgot' || authView.value === 'register') return '立即登录'
    return '立即注册'
})
const agreementActionText = computed(() => {
    if (authView.value === 'register') return '注册即代表同意'
    if (authView.value === 'forgot') return '继续操作即代表同意'
    return '登录即代表同意'
})
const contentTitle = computed(() => {
    if (authView.value === 'forgot') return '重置密码'
    if (authView.value === 'register') return '注册'
    return '登录'
})
const contentSubtitle = computed(() => {
    if (authView.value === 'forgot') return '通过手机号验证后设置新密码'
    if (authView.value === 'register') return '创建你的 A. PART 账号'
    if (loginTab.value === 'wechat') return '使用微信扫码快速登录'
    return '欢迎回来，请完成登录'
})
const codeButtonText = computed(() => {
    if (codeSending.value) return '发送中'
    if (countdown.value > 0) return `${countdown.value}s`
    return '获取验证码'
})

const startCountdown = () => {
    countdown.value = 60

    if (countdownTimer) {
        clearInterval(countdownTimer)
    }

    countdownTimer = setInterval(() => {
        if (countdown.value <= 1) {
            countdown.value = 0
            if (countdownTimer) {
                clearInterval(countdownTimer)
                countdownTimer = null
            }
            return
        }

        countdown.value -= 1
    }, 1000)
}

const validateMobile = () => {
    if (!/^1\d{10}$/.test(mobileForm.mobile)) {
        feedback.msgError('请输入正确的手机号')
        return false
    }

    return true
}

const validateForgotMobile = () => {
    if (!/^1\d{10}$/.test(forgotForm.mobile)) {
        feedback.msgError('请输入正确的手机号')
        return false
    }

    return true
}

const validateForgot = () => {
    if (!validateForgotMobile()) return false

    if (!forgotForm.code) {
        feedback.msgError('请输入短信验证码')
        return false
    }

    if (!forgotForm.password || forgotForm.password.length < 6 || forgotForm.password.length > 20) {
        feedback.msgError('请输入 6-20 位字母数字组合新密码')
        return false
    }

    if (!/^[A-Za-z0-9]+$/.test(forgotForm.password)) {
        feedback.msgError('新密码须为字母数字组合')
        return false
    }

    if (forgotForm.password !== forgotForm.passwordConfirm) {
        feedback.msgError('两次输入的密码不一致')
        return false
    }

    return true
}

const validateAccountLogin = () => {
    if (!accountForm.account) {
        feedback.msgError('请输入账号或手机号')
        return false
    }

    if (!accountForm.password) {
        feedback.msgError('请输入密码')
        return false
    }

    return true
}

const validateRegister = () => {
    if (!registerForm.account) {
        feedback.msgError('请输入要注册的账号')
        return false
    }

    if (registerForm.account.length < 3 || registerForm.account.length > 12) {
        feedback.msgError('账号长度应为 3-12 位')
        return false
    }

    if (!registerForm.password) {
        feedback.msgError('请输入 6-20 位密码')
        return false
    }

    if (registerForm.password.length < 6 || registerForm.password.length > 20) {
        feedback.msgError('密码长度应为 6-20 位')
        return false
    }

    if (!registerForm.passwordConfirm) {
        feedback.msgError('请再次输入密码')
        return false
    }

    if (registerForm.password !== registerForm.passwordConfirm) {
        feedback.msgError('两次输入的密码不一致')
        return false
    }

    return true
}

const getDefaultLoginTab = (): LoginTab => {
    if (allowMobileLogin.value) return 'mobile'
    if (allowAccountLogin.value) return 'account'
    return 'wechat'
}

const syncLoginTab = () => {
    loginTab.value = getDefaultLoginTab()
}

const toggleAuthView = () => {
    if (authView.value === 'login') {
        authView.value = 'register'
        return
    }

    authView.value = 'login'
    loginTab.value = getDefaultLoginTab()
}

const setLoginTab = (tab: LoginTab) => {
    loginTab.value = tab
}

const close = () => {
    emit('update:modelValue', false)
}

const openForgotPassword = () => {
    authView.value = 'forgot'
    forgotForm.mobile = ''
    forgotForm.code = ''
    forgotForm.password = ''
    forgotForm.passwordConfirm = ''
}

const sendCode = async () => {
    if (!validateMobile()) return

    codeSending.value = true
    try {
        await smsSend({
            scene: SMSEnum.LOGIN,
            mobile: mobileForm.mobile
        })
        startCountdown()
        feedback.msgSuccess('验证码已发送')
    } finally {
        codeSending.value = false
    }
}

const sendForgotCode = async () => {
    if (!validateForgotMobile()) return

    codeSending.value = true
    try {
        await smsSend({
            scene: SMSEnum.FIND_PASSWORD,
            mobile: forgotForm.mobile
        })
        startCountdown()
        feedback.msgSuccess('验证码已发送')
    } finally {
        codeSending.value = false
    }
}

const submitForgot = async () => {
    if (!validateForgot()) return

    forgotLoading.value = true
    try {
        await forgotPassword({
            mobile: forgotForm.mobile,
            code: forgotForm.code,
            password: forgotForm.password,
            password_confirm: forgotForm.passwordConfirm
        })
        feedback.msgSuccess('密码已重置，请重新登录')
        forgotForm.mobile = ''
        forgotForm.code = ''
        forgotForm.password = ''
        forgotForm.passwordConfirm = ''
        authView.value = 'login'
        loginTab.value = getDefaultLoginTab()
    } finally {
        forgotLoading.value = false
    }
}

const submitMobileLogin = async () => {
    if (!validateMobile()) return
    if (!mobileForm.code) {
        feedback.msgError('请输入短信验证码')
        return
    }

    submitLoading.value = true
    try {
        const data = await login({
            account: mobileForm.mobile,
            code: mobileForm.code,
            scene: 2
        })
        if (isForceBindMobile.value && !data.mobile) {
            userStore.temToken = data.token
            close()
            await nextTick()
            setPopupType(PopupTypeEnum.BIND_MOBILE)
            toggleShowPopup(true)
            return
        }
        userStore.login(data.token)
        try {
            await userStore.getUser()
        } catch (error) {
            console.warn('[site-login-modal] load user center after mobile login failed', error)
        }
        feedback.msgSuccess('登录成功')
        emit('login-success')
        close()
    } finally {
        submitLoading.value = false
    }
}

const submitAccountLogin = async () => {
    if (!validateAccountLogin()) return

    submitLoading.value = true
    try {
        const data = await login({
            account: accountForm.account,
            password: accountForm.password,
            scene: 1
        })
        if (isForceBindMobile.value && !data.mobile) {
            userStore.temToken = data.token
            close()
            await nextTick()
            setPopupType(PopupTypeEnum.BIND_MOBILE)
            toggleShowPopup(true)
            return
        }
        userStore.login(data.token)
        try {
            await userStore.getUser()
        } catch (error) {
            console.warn('[site-login-modal] load user center after account login failed', error)
        }
        feedback.msgSuccess('登录成功')
        emit('login-success')
        close()
    } finally {
        submitLoading.value = false
    }
}

const refreshWechatQr = async () => {
    if (!allowWechatLogin.value) return
    wechatLoading.value = true
    try {
        const { url } = await getWxCodeUrl()
        wechatUrl.value = url
        wechatQrSvg.value = renderSVG(url, {
            border: 1
        })
    } finally {
        wechatLoading.value = false
    }
}

const submitRegister = async () => {
    if (!validateRegister()) return

    registerLoading.value = true
    try {
        await register({
            account: registerForm.account,
            password: registerForm.password,
            password_confirm: registerForm.passwordConfirm
        })
        registerForm.account = ''
        registerForm.password = ''
        registerForm.passwordConfirm = ''
        feedback.msgSuccess('注册成功，请登录')
        authView.value = 'login'
        if (allowMobileLogin.value) {
            loginTab.value = 'mobile'
        }
    } finally {
        registerLoading.value = false
    }
}

const handleKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && props.modelValue) {
        close()
    }
}

watch([allowMobileLogin, allowWechatLogin], syncLoginTab, {
    immediate: true
})

watch([authView, loginTab, () => props.modelValue], async ([view, tab, visible]) => {
    if (!visible || view !== 'login' || tab !== 'wechat') {
        return
    }

    if (!wechatQrSvg.value || !wechatUrl.value) {
        await refreshWechatQr()
    }
})

watch(() => props.modelValue, (visible) => {
    if (import.meta.client) {
        if (visible) {
            lockPageScroll()
        } else {
            unlockPageScroll()
        }
    }

    authView.value = 'login'
    loginTab.value = getDefaultLoginTab()

    if (!visible) {
        forgotForm.mobile = ''
        forgotForm.code = ''
        forgotForm.password = ''
        forgotForm.passwordConfirm = ''
    }
}, { immediate: true })

onMounted(() => {
    window.addEventListener('keydown', handleKeydown)
})

onBeforeUnmount(() => {
    if (countdownTimer) {
        clearInterval(countdownTimer)
    }

    if (import.meta.client) {
        window.removeEventListener('keydown', handleKeydown)
        unlockPageScroll()
    }
})
</script>

<style lang="scss">
@import '@/assets/styles/pc-site-login-modal.scss';
</style>
