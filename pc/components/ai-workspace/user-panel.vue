<template>
    <Teleport to="body">
        <Transition name="ai-user-panel-fade">
            <div v-if="modelValue" class="ai-user-panel-mask" @click.self="close">
                <section class="ai-user-panel" aria-modal="true" role="dialog">
                    <header class="ai-user-panel__header">
                        <div class="ai-user-panel__identity">
                            <div class="ai-user-panel__avatar">
                                <img v-if="avatarSrc && !avatarBroken" :src="avatarSrc" alt="" @error="avatarBroken = true" />
                                <span v-else>{{ avatarInitial }}</span>
                            </div>
                            <div>
                                <strong>{{ displayName }}</strong>
                                <p>{{ userInfo?.mobile || '未绑定手机号' }}</p>
                            </div>
                        </div>
                        <button class="ai-user-panel__close" type="button" aria-label="关闭个人中心" @click="close">
                            <span></span>
                            <span></span>
                        </button>
                    </header>

                    <nav class="ai-user-panel__tabs" aria-label="个人中心">
                        <button
                            v-for="item in tabs"
                            :key="item.key"
                            :class="{ 'is-active': activeTab === item.key }"
                            type="button"
                            @click="activeTab = item.key"
                        >
                            {{ item.label }}
                        </button>
                    </nav>

                    <div class="ai-user-panel__body">
                        <section v-if="activeTab === 'profile'" class="ai-user-profile">
                            <div class="ai-user-profile__grid">
                                <label class="ai-user-field">
                                    <span>账号</span>
                                    <div class="ai-user-field__readonly">
                                        {{ form.account || '--' }}
                                    </div>
                                </label>
                                <label class="ai-user-field">
                                    <span>显示名称</span>
                                    <input v-model="form.nickname" maxlength="30" placeholder="请输入昵称" />
                                </label>
                                <label class="ai-user-field">
                                    <span>性别</span>
                                    <select v-model.number="form.sex">
                                        <option v-for="item in sexOptions" :key="item.value" :value="item.value">
                                            {{ item.label }}
                                        </option>
                                    </select>
                                </label>
                                <div class="ai-user-field">
                                    <span>手机号</span>
                                    <div class="ai-user-field__readonly">
                                        {{ userInfo?.mobile || '未绑定' }}
                                    </div>
                                </div>
                                <div class="ai-user-field ai-user-field--wide">
                                    <span>注册时间</span>
                                    <div class="ai-user-field__readonly">
                                        {{ userInfo?.create_time || '--' }}
                                    </div>
                                </div>
                            </div>

                            <div class="ai-user-avatar-editor">
                                <div class="ai-user-avatar-editor__preview">
                                    <img v-if="avatarSrc && !avatarBroken" :src="avatarSrc" alt="" @error="avatarBroken = true" />
                                    <span v-else>{{ avatarInitial }}</span>
                                </div>
                                <div class="ai-user-avatar-editor__meta">
                                    <CropperUpload @change="handleAvatarChange">
                                        <button type="button">上传头像</button>
                                    </CropperUpload>
                                    <p>推荐使用 400 x 400 像素图片。</p>
                                </div>
                            </div>

                            <footer class="ai-user-panel__footer">
                                <button class="ai-user-panel__secondary" type="button" @click="handleLogout">退出登录</button>
                                <button class="ai-user-panel__primary" type="button" :disabled="!isDirty || saving" @click="saveProfile">
                                    {{ saving ? '保存中...' : '保存更改' }}
                                </button>
                            </footer>
                        </section>

                        <section v-else class="ai-user-security">
                            <article class="ai-user-security__item">
                                <div>
                                    <strong>登录密码</strong>
                                    <p>用于账号登录，建议定期更换。</p>
                                </div>
                                <button type="button" @click="showPwdForm = !showPwdForm">
                                    {{ userInfo?.has_password ? '修改密码' : '设置密码' }}
                                </button>
                            </article>
                            <div v-if="showPwdForm" class="ai-user-password-form">
                                <label v-if="userInfo?.has_password" class="ai-user-field">
                                    <span>原密码</span>
                                    <input v-model="pwdForm.old_password" type="password" autocomplete="current-password" placeholder="请输入原密码" />
                                </label>
                                <label class="ai-user-field">
                                    <span>新密码</span>
                                    <input v-model="pwdForm.password" type="password" autocomplete="new-password" placeholder="请输入6-20位字母数字组合" />
                                </label>
                                <label class="ai-user-field">
                                    <span>确认新密码</span>
                                    <input v-model="pwdForm.password_confirm" type="password" autocomplete="new-password" placeholder="请再次输入密码" />
                                </label>
                                <button type="button" :disabled="changingPassword" @click="changePassword">
                                    {{ changingPassword ? '提交中...' : '确认修改' }}
                                </button>
                            </div>
                            <article class="ai-user-security__item">
                                <div>
                                    <strong>绑定微信</strong>
                                    <p>微信扫码快捷登录（若已开启）。</p>
                                </div>
                                <span>{{ userInfo?.has_auth ? '已绑定' : '未绑定' }}</span>
                            </article>
                        </section>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<script lang="ts" setup>
import { computed, reactive, ref, watch } from 'vue'
import { logout } from '@/api/account'
import { getUserInfo, userChangePwd, userEdit } from '@/api/user'
import CropperUpload from '@/components/cropper-upload/index.vue'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'
import { normalizeFileUrl } from '@/utils/file-url'

type PanelTab = 'profile' | 'security'

const props = defineProps<{
    modelValue: boolean
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
}>()

const userStore = useUserStore()
const activeTab = ref<PanelTab>('profile')
const userInfo = ref<Record<string, any>>({})
const saving = ref(false)
const changingPassword = ref(false)
const avatarBroken = ref(false)
const showPwdForm = ref(false)

const tabs: Array<{ key: PanelTab; label: string }> = [
    { key: 'profile', label: '个人资料' },
    { key: 'security', label: '账号安全' }
]

const sexOptions = [
    { label: '未知', value: 0 },
    { label: '男', value: 1 },
    { label: '女', value: 2 }
]
const sexLabelToValue = new Map(sexOptions.map((item) => [item.label, item.value]))

const form = reactive({
    account: '',
    nickname: '',
    sex: 0,
    avatar: ''
})

const snapshot = reactive({
    account: '',
    nickname: '',
    sex: 0,
    avatar: ''
})

const pwdForm = reactive({
    old_password: '',
    password: '',
    password_confirm: ''
})

const displayName = computed(() => form.nickname || form.account || userInfo.value?.nickname || userInfo.value?.account || '个人中心')
const avatarInitial = computed(() => displayName.value.slice(0, 1).toUpperCase())
const avatarSrc = computed(() => normalizeFileUrl(form.avatar || userInfo.value?.avatar || '', userStore.avatarVersion))

const isDirty = computed(() => (
    form.nickname !== snapshot.nickname ||
    form.sex !== snapshot.sex ||
    form.avatar !== snapshot.avatar
))

const toSexValue = (raw: unknown) => {
    if (typeof raw === 'string') {
        const value = raw.trim()
        if (value === '') {
            return 0
        }
        const num = Number(value)
        if (Number.isFinite(num)) {
            return num
        }
        return sexLabelToValue.get(value) ?? 0
    }
    const num = Number(raw)
    return Number.isFinite(num) ? num : 0
}

const syncForm = (info: Record<string, any>) => {
    form.account = info?.account || ''
    form.nickname = info?.nickname || ''
    form.sex = toSexValue(info?.sex_code ?? info?.sex)
    form.avatar = info?.avatar || ''
    snapshot.account = form.account
    snapshot.nickname = form.nickname
    snapshot.sex = form.sex
    snapshot.avatar = form.avatar
    avatarBroken.value = false
}

const loadUserInfo = async () => {
    const data = await getUserInfo()
    userInfo.value = data || {}
    syncForm(userInfo.value)
}

const resetPasswordForm = () => {
    pwdForm.old_password = ''
    pwdForm.password = ''
    pwdForm.password_confirm = ''
}

const close = () => emit('update:modelValue', false)

const handleAvatarChange = (uri: string) => {
    form.avatar = uri
    avatarBroken.value = false
    feedback.msgSuccess('头像已选择，请点击保存提交')
}

const saveProfile = async () => {
    if (!isDirty.value || saving.value) return
    saving.value = true
    try {
        const tasks: Promise<any>[] = []
        if (form.nickname !== snapshot.nickname) tasks.push(userEdit({ field: 'nickname', value: form.nickname }))
        if (form.sex !== snapshot.sex) tasks.push(userEdit({ field: 'sex', value: form.sex }))
        if (form.avatar !== snapshot.avatar) tasks.push(userEdit({ field: 'avatar', value: form.avatar }))
        await Promise.all(tasks)
        feedback.msgSuccess('保存成功')
        await Promise.all([loadUserInfo(), userStore.getUser()])
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || '保存失败')
    } finally {
        saving.value = false
    }
}

const changePassword = async () => {
    if (changingPassword.value) return
    if (userInfo.value?.has_password && !pwdForm.old_password) return feedback.msgError('请输入原密码')
    if (!/^[A-Za-z0-9]{6,20}$/.test(pwdForm.password)) return feedback.msgError('请输入6-20位字母数字组合')
    if (pwdForm.password !== pwdForm.password_confirm) return feedback.msgError('两次输入的密码不一致')

    changingPassword.value = true
    try {
        await userChangePwd({ ...pwdForm })
        feedback.msgSuccess('密码已修改，请重新登录')
        await logout()
        userStore.logout()
        close()
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || '修改失败')
    } finally {
        changingPassword.value = false
    }
}

const handleLogout = async () => {
    await feedback.confirm('确定退出登录吗？')
    await logout()
    userStore.logout()
    close()
}

watch(() => props.modelValue, async (visible) => {
    if (!visible) return
    activeTab.value = 'profile'
    showPwdForm.value = false
    resetPasswordForm()
    await loadUserInfo()
})
</script>

<style lang="scss" scoped>
.ai-user-panel-mask {
    position: fixed;
    inset: 0;
    z-index: 97;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
}

.ai-user-panel {
    width: 760px;
    max-width: calc(100vw - 64px);
    max-height: calc(100vh - 64px);
    display: flex;
    flex-direction: column;
    padding: 20px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    color: #fff;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    box-sizing: border-box;
}

.ai-user-panel__header,
.ai-user-panel__identity,
.ai-user-panel__tabs,
.ai-user-panel__footer,
.ai-user-security__item,
.ai-user-avatar-editor {
    display: flex;
    align-items: center;
}

.ai-user-panel__header {
    justify-content: space-between;
    gap: 18px;
}

.ai-user-panel__identity {
    gap: 14px;
    min-width: 0;
}

.ai-user-panel__avatar,
.ai-user-avatar-editor__preview {
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #222;
    color: rgba(255, 255, 255, 0.72);
}

.ai-user-panel__avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    font-size: 18px;
    font-weight: 700;
}

.ai-user-panel__avatar img,
.ai-user-avatar-editor__preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.ai-user-panel__identity strong {
    display: block;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.2;
}

.ai-user-panel__identity p,
.ai-user-avatar-editor__meta p,
.ai-user-security__item p {
    margin: 6px 0 0;
    color: rgba(255, 255, 255, 0.48);
    font-size: 13px;
    line-height: 1.5;
}

.ai-user-panel__close {
    position: relative;
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 50%;
    background: #222;
    cursor: pointer;
}

.ai-user-panel__close span {
    position: absolute;
    top: 17px;
    left: 10px;
    width: 16px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
}

.ai-user-panel__close span:first-child {
    transform: rotate(45deg);
}

.ai-user-panel__close span:last-child {
    transform: rotate(-45deg);
}

.ai-user-panel__tabs {
    gap: 10px;
    margin-top: 18px;
    padding: 6px;
    border-radius: 14px;
    background: #19191b;
}

.ai-user-panel__tabs button {
    height: 36px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.58);
    font-size: 14px;
    cursor: pointer;
}

.ai-user-panel__tabs button.is-active {
    background: #2c2c2c;
    color: #fff;
}

.ai-user-panel__body {
    min-height: 0;
    margin-top: 18px;
    overflow-y: auto;
    padding-right: 4px;
}

.ai-user-profile__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.ai-user-field {
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-width: 0;
}

.ai-user-field--wide {
    grid-column: span 2;
}

.ai-user-field span {
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    font-weight: 600;
}

.ai-user-field input,
.ai-user-field select,
.ai-user-field__readonly {
    width: 100%;
    height: 42px;
    padding: 0 12px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    background: #050505;
    color: #fff;
    font-size: 14px;
    box-sizing: border-box;
}

.ai-user-field__readonly {
    display: flex;
    align-items: center;
    color: rgba(255, 255, 255, 0.62);
}

.ai-user-avatar-editor {
    gap: 16px;
    margin-top: 18px;
    padding: 16px;
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: #0b0b0c;
}

.ai-user-avatar-editor__preview {
    width: 88px;
    height: 88px;
    border-radius: 14px;
    flex: none;
}

.ai-user-avatar-editor__meta button,
.ai-user-panel__primary,
.ai-user-panel__secondary,
.ai-user-security__item button,
.ai-user-password-form button {
    height: 38px;
    padding: 0 18px;
    border: 0;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}

.ai-user-avatar-editor__meta button,
.ai-user-panel__secondary,
.ai-user-security__item button {
    background: #222;
    color: #fff;
}

.ai-user-panel__footer {
    justify-content: flex-end;
    gap: 12px;
    margin-top: 18px;
}

.ai-user-panel__primary {
    background: #fff;
    color: #111;
}

.ai-user-panel__primary:disabled,
.ai-user-avatar-editor__meta button:disabled,
.ai-user-password-form button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.ai-user-security {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.ai-user-security__item {
    justify-content: space-between;
    gap: 18px;
    padding: 18px;
    border-radius: 14px;
    background: #19191b;
}

.ai-user-security__item strong {
    font-size: 16px;
    font-weight: 700;
}

.ai-user-security__item > span {
    color: rgba(255, 255, 255, 0.56);
    font-size: 14px;
}

.ai-user-password-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 14px;
    padding: 18px;
    border-radius: 14px;
    background: #0b0b0c;
}

.ai-user-password-form button {
    justify-self: end;
    background: #fff;
    color: #111;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.ai-user-panel-fade-enter-active,
.ai-user-panel-fade-leave-active {
    transition: opacity 0.18s ease;
}

.ai-user-panel-fade-enter-from,
.ai-user-panel-fade-leave-to {
    opacity: 0;
}
</style>
