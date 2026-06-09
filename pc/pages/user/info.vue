<template>
    <UserCenterLayout
        page-title="您的个人资料"
        page-desc="请在此处填写更多关于您的信息。"
    >
        <div class="uc-form-grid">
            <div class="uc-form-main">
                <div class="uc-grid">
                    <div class="uc-field">
                        <label class="uc-label">
                            账号
                            <span class="uc-hint">?</span>
                        </label>
                        <div class="uc-readonly uc-readonly--box">
                            {{ form.account || '--' }}
                        </div>
                    </div>

                    <div class="uc-field">
                        <label class="uc-label">
                            显示名称
                            <span class="uc-hint">?</span>
                        </label>
                        <ElInput
                            v-model="form.nickname"
                            placeholder="请输入昵称"
                            :maxlength="30"
                            show-word-limit
                            class="uc-input"
                        />
                    </div>

                    <div class="uc-field">
                        <label class="uc-label">
                            性别
                            <span class="uc-hint">?</span>
                        </label>
                        <ElSelect
                            v-model="form.sex"
                            placeholder="请选择性别"
                            class="uc-input"
                        >
                            <ElOption
                                v-for="opt in sexOptions"
                                :key="opt.value"
                                :label="opt.label"
                                :value="opt.value"
                            />
                        </ElSelect>
                    </div>

                    <div class="uc-field">
                        <label class="uc-label">手机号</label>
                        <div class="uc-mobile-box">
                            <span class="uc-mobile-text">
                                {{ userInfo?.mobile || '未绑定' }}
                            </span>
                            <button
                                type="button"
                                class="uc-link-btn"
                                @click="handleChangeMobile"
                            >
                                {{
                                    userInfo?.mobile
                                        ? '更换手机号'
                                        : '绑定手机号'
                                }}
                            </button>
                        </div>
                    </div>

                    <div class="uc-field uc-field--wide">
                        <label class="uc-label">注册时间</label>
                        <div class="uc-readonly">
                            {{ userInfo?.create_time || '--' }}
                        </div>
                    </div>
                </div>

                <div class="uc-avatar-section">
                    <label class="uc-label">头像</label>
                    <div class="uc-avatar-box">
                        <div class="uc-avatar-preview">
                            <img
                                v-if="avatarSrc && !avatarBroken"
                                :src="avatarSrc"
                                alt="avatar"
                                @error="avatarBroken = true"
                                @load="avatarBroken = false"
                            />
                            <span v-else class="uc-avatar-placeholder">
                                头像
                            </span>
                        </div>
                        <div class="uc-avatar-meta">
                            <CropperUpload @change="handleAvatarChange">
                                <button
                                    type="button"
                                    class="uc-upload-btn"
                                >
                                    上传新头像
                                </button>
                            </CropperUpload>
                            <p class="uc-avatar-hint">
                                推荐尺寸：400×400 像素，最大 400KB。
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <aside class="uc-form-side">
                <button
                    type="button"
                    class="uc-save-btn"
                    :disabled="!isDirty || isLock"
                    @click="handleSaveLock"
                >
                    <span v-if="isLock">保存中…</span>
                    <span v-else>保存更改</span>
                </button>
                <p class="uc-save-hint">
                    如果您进行了任何编辑，请记得在离开此页面前保存。
                </p>
                <div class="uc-divider" />
                <button
                    type="button"
                    class="uc-logout-btn"
                    @click="handleLogout"
                >
                    退出登录
                </button>
            </aside>
        </div>
    </UserCenterLayout>
</template>
<script lang="ts" setup>
import { ElInput, ElOption, ElSelect } from 'element-plus'
import { getUserInfo, userEdit } from '@/api/user'
import CropperUpload from '@/components/cropper-upload/index.vue'
import UserCenterLayout from '@/components/user-center-layout.vue'
import {
    PopupTypeEnum,
    useAccount
} from '@/layouts/components/account/useAccount'
import { logout } from '~~/api/account'
import { isPcLoginRequiredError } from '@/composables/usePcLoginGate'
import feedback from '~~/utils/feedback'
import { useUserStore } from '~~/stores/user'
import { normalizeFileUrl } from '@/utils/file-url'

enum UserFieldEnum {
    AVATAR = 'avatar',
    NICKNAME = 'nickname',
    SEX = 'sex'
}

const sexOptions = [
    { label: '未知', value: 0 },
    { label: '男', value: 1 },
    { label: '女', value: 2 }
]
const sexLabelToValue = new Map(sexOptions.map((o) => [o.label, o.value]))

const toSexValue = (raw: unknown): number => {
    if (typeof raw === 'number') return raw
    if (typeof raw === 'string') {
        const num = Number(raw)
        if (!Number.isNaN(num) && raw.trim() !== '') return num
        return sexLabelToValue.get(raw) ?? 0
    }
    return 0
}

const { setPopupType, toggleShowPopup, showPopup } = useAccount()
const userStore = useUserStore()

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

const syncForm = () => {
    form.account = userInfo.value?.account ?? ''
    form.nickname = userInfo.value?.nickname ?? ''
    form.sex = toSexValue(userInfo.value?.sex_code ?? userInfo.value?.sex)
    form.avatar = userInfo.value?.avatar ?? ''
    snapshot.account = form.account
    snapshot.nickname = form.nickname
    snapshot.sex = form.sex
    snapshot.avatar = form.avatar
}

syncForm()
watch(userInfo, syncForm)
watch(() => userStore.isLogin, (loggedIn) => {
    if (!loggedIn) {
        userInfo.value = {}
        syncForm()
        return
    }
    refresh()
}, { immediate: true })

const isDirty = computed(
    () =>
        form.nickname !== snapshot.nickname ||
        form.sex !== snapshot.sex ||
        form.avatar !== snapshot.avatar
)

const handleSave = async () => {
    if (!isDirty.value) return
    const tasks: Promise<any>[] = []
    if (form.nickname !== snapshot.nickname) {
        tasks.push(
            userEdit({ field: UserFieldEnum.NICKNAME, value: form.nickname })
        )
    }
    if (form.sex !== snapshot.sex) {
        tasks.push(userEdit({ field: UserFieldEnum.SEX, value: form.sex }))
    }
    if (form.avatar !== snapshot.avatar) {
        tasks.push(
            userEdit({ field: UserFieldEnum.AVATAR, value: form.avatar })
        )
    }
    await Promise.all(tasks)
    feedback.msgSuccess('保存成功')
    await Promise.all([refresh(), userStore.getUser()])
}
const { lockFn: handleSaveLock, isLock } = useLockFn(handleSave)

const avatarBroken = ref(false)

const avatarSrc = computed(() =>
    normalizeFileUrl(
        form.avatar || userInfo.value?.avatar || '',
        userStore.avatarVersion
    )
)

watch(avatarSrc, () => {
    avatarBroken.value = false
})

const handleAvatarChange = (uri: string) => {
    form.avatar = uri
    avatarBroken.value = false
    feedback.msgSuccess('头像已选择，请点击「保存更改」提交')
}

const handleChangeMobile = () => {
    setPopupType(PopupTypeEnum.BIND_MOBILE)
    toggleShowPopup(true)
}

watch(showPopup, (value) => {
    if (!value) refresh()
})

const handleLogout = async () => {
    await feedback.confirm('确定退出登录吗？')
    await logout()
    userStore.logout()
}

definePageMeta({
    layout: 'blank',
    module: 'personal',
    auth: true
})
</script>
<style lang="scss" scoped>
.uc-form-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 240px;
    gap: 56px;
    align-items: start;
}
.uc-form-main {
    min-width: 0;
}
.uc-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    column-gap: 24px;
    row-gap: 24px;
}
.uc-field {
    display: flex;
    flex-direction: column;
    gap: 10px;
    &--wide {
        grid-column: span 2;
    }
}
.uc-label {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #222;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.01em;
}
.uc-hint {
    display: inline-flex;
    width: 14px;
    height: 14px;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #ececec;
    color: #8b8b8b;
    font-size: 10px;
    line-height: 1;
    font-weight: 600;
}
.uc-input {
    width: 100%;
    :deep(.el-input__wrapper),
    :deep(.el-select .el-input__wrapper) {
        height: 40px;
        padding: 0 12px;
        background: #fff;
        border-radius: 2px;
        box-shadow: inset 0 0 0 1px rgba(34, 34, 34, 0.12);
        transition: box-shadow 0.2s ease;
    }
    :deep(.el-input__wrapper:hover) {
        box-shadow: inset 0 0 0 1px rgba(34, 34, 34, 0.24);
    }
    :deep(.el-input.is-focus .el-input__wrapper),
    :deep(.el-select .el-input.is-focus .el-input__wrapper) {
        box-shadow: inset 0 0 0 1px #222;
    }
    :deep(.el-input__inner) {
        font-size: 14px;
        color: #222;
    }
}
.uc-mobile-box {
    display: flex;
    align-items: center;
    justify-content: space-between;
    height: 40px;
    padding: 0 12px;
    background: #fff;
    border-radius: 2px;
    box-shadow: inset 0 0 0 1px rgba(34, 34, 34, 0.12);
}
.uc-mobile-text {
    font-size: 14px;
    color: #222;
}
.uc-link-btn {
    appearance: none;
    border: 0;
    padding: 0;
    background: transparent;
    color: #222;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
    &:hover {
        color: #ff6b00;
    }
}
.uc-readonly {
    font-size: 14px;
    color: #666;
    padding: 10px 0;
}
.uc-readonly--box {
    height: 40px;
    padding: 0 12px;
    display: flex;
    align-items: center;
    background: #f7f7f7;
    border-radius: 2px;
    box-shadow: inset 0 0 0 1px rgba(34, 34, 34, 0.08);
    color: #8b8b8b;
}

.uc-avatar-section {
    margin-top: 36px;
}
.uc-avatar-box {
    display: flex;
    align-items: center;
    gap: 20px;
    margin-top: 12px;
    padding: 20px;
    background: #fafafa;
    border: 1px dashed rgba(34, 34, 34, 0.18);
    border-radius: 4px;
    max-width: 520px;
}
.uc-avatar-preview {
    flex: none;
    width: 120px;
    height: 120px;
    border-radius: 4px;
    overflow: hidden;
    background: #fff;
    box-shadow: inset 0 0 0 1px rgba(34, 34, 34, 0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
}
.uc-avatar-placeholder {
    color: #b7b7b7;
    font-size: 13px;
}
.uc-avatar-meta {
    display: flex;
    flex-direction: column;
    gap: 12px;
    flex: 1;
    min-width: 0;
}
.uc-upload-btn {
    align-self: flex-start;
    appearance: none;
    cursor: pointer;
    height: 36px;
    padding: 0 20px;
    border: 1px solid rgba(34, 34, 34, 0.18);
    border-radius: 2px;
    background: #fff;
    color: #222;
    font-size: 13px;
    font-weight: 500;
    transition: all 0.2s ease;
    &:hover {
        background: #222;
        color: #fff;
        border-color: #222;
    }
}
.uc-avatar-hint {
    margin: 0;
    color: #8b8b8b;
    font-size: 12px;
    line-height: 20px;
}

.uc-form-side {
    position: -webkit-sticky;
    position: sticky;
    top: 24px;
    align-self: start;
    display: flex;
    width: 240px;
    flex-direction: column;
    z-index: 2;
}
.uc-save-btn {
    width: 100%;
    height: 48px;
    appearance: none;
    cursor: pointer;
    border: 0;
    border-radius: 2px;
    background: #222;
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    transition: background 0.2s ease;
    &:hover:not(:disabled) {
        background: #000;
    }
    &:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
}
.uc-save-hint {
    margin: 12px 0 0;
    color: #8b8b8b;
    font-size: 12px;
    line-height: 20px;
}
.uc-divider {
    margin: 24px 0;
    border-top: 1px solid rgba(34, 34, 34, 0.1);
}
.uc-logout-btn {
    appearance: none;
    cursor: pointer;
    background: transparent;
    border: 0;
    padding: 0;
    color: #666;
    font-size: 13px;
    font-weight: 500;
    text-align: left;
    &:hover {
        color: #d64545;
    }
}
</style>
