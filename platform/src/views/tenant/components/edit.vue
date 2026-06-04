<template>
    <el-drawer
        v-model="drawer"
        destroy-on-close
        title="租户信息"
        direction="rtl"
        size="50%"
        @close="afterClose"
        :before-close="beforeClose"
    >
        <div
            class="h-full flex flex-col"
            v-loading="loading"
            element-loading-text="加载中..."
            element-loading-background="var(--el-bg-color)"
        >
            <div class="flex flex-col pb-1">
                <div class="bg-page p-4 rounded flex justify-between items-center">
                    <div class="flex items-center gap-4">
                        <el-avatar :src="formData.avatar" :size="58" />
                        <div class="flex flex-col justify-center gap-1">
                            <span class="font-bold text-lg">{{ formData.name }}</span>
                            <span class="text-info text-xs">编号：{{ formData.sn }}</span>
                        </div>
                    </div>
                    <div>
                        <el-button type="info" size="small" @click="handleEnterTenant">
                            登录
                        </el-button>
                        <el-button
                            v-if="editStatus"
                            type="default"
                            size="small"
                            @click="handleEdit()"
                        >
                            取消
                        </el-button>
                        <el-button
                            type="primary"
                            size="small"
                            :loading="isLock"
                            @click="handleEdit(true)"
                        >
                            {{ editStatus ? '保存' : '编辑' }}
                        </el-button>
                    </div>
                </div>
            </div>

            <el-tabs class="flex-1" v-model="activeName" :before-leave="beforeLeave">
                <el-tab-pane label="基础信息" name="profile">
                    <el-form
                        ref="formRef"
                        class="profile grid grid-cols-2 gap-x-4 pt-2"
                        :class="{
                            '!grid-cols-1': editStatus
                        }"
                        label-position="right"
                        :model="formData"
                        label-width="100px"
                        :rules="formRules"
                    >
                        <el-form-item v-if="editStatus" label="头像：" prop="avatar">
                            <material-picker v-model="formData.avatar" :limit="1" />
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="ID后台："
                            class="col-span-2"
                        >
                            <a
                                :href="formData.links?.id_query?.admin || formData.tenant_id_domain"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ formData.links?.id_query?.admin || formData.tenant_id_domain || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="formData.links?.id_query?.admin || formData.tenant_id_domain"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="ID路径后台："
                            class="col-span-2"
                        >
                            <a
                                :href="formData.links?.id_path?.admin"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ formData.links?.id_path?.admin || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="formData.links?.id_path?.admin"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                        <el-form-item label="租户名称：" prop="name" :required="editStatus">
                            <el-input
                                v-if="editStatus"
                                v-model="formData.name"
                                placeholder="请输入租户名称"
                                style="max-width: 250px"
                            />
                            <span v-else class="break-all">
                                {{ formData.name || '--' }}
                            </span>
                        </el-form-item>
                        <el-form-item label="联系方式：" prop="tel">
                            <el-input
                                v-if="editStatus"
                                v-model="formData.tel"
                                placeholder="请输入联系方式"
                                style="max-width: 250px"
                            />
                            <span v-else>
                                {{ formData.tel || '--' }}
                            </span>
                        </el-form-item>
                        <el-form-item label="访问方式：" prop="access_mode">
                            <div v-if="editStatus">
                                <el-radio-group v-model="formData.access_mode">
                                    <el-radio value="subdomain">默认子域名</el-radio>
                                    <el-radio value="id">ID访问</el-radio>
                                    <el-radio value="alias">别名访问</el-radio>
                                </el-radio-group>
                                <p class="text-info text-sm">
                                    默认子域名使用系统自动生成域名；别名访问需配置域名解析后生效。
                                </p>
                            </div>

                            <el-tag
                                v-else
                                disable-transitions
                                :type="formData.access_mode === 'alias' ? 'primary' : 'info'"
                            >
                                {{ accessModeText(formData.access_mode) }}
                            </el-tag>
                        </el-form-item>
                        <el-form-item
                            v-if="formData.access_mode === 'alias' || !editStatus"
                            label="域名别名："
                            prop="domain_alias"
                        >
                            <el-input
                                v-if="editStatus"
                                v-model="formData.domain_alias"
                                placeholder="请输入域名别名"
                                style="max-width: 250px"
                            />
                            <span v-else class="break-all">
                                {{ formData.domain_alias || '--' }}
                            </span>
                        </el-form-item>
                        <el-form-item label="租户状态：" prop="disable">
                            <el-radio-group v-if="editStatus" v-model="formData.disable">
                                <el-radio :value="0">开启</el-radio>
                                <el-radio :value="1">关闭</el-radio>
                            </el-radio-group>
                            <el-tag
                                v-else
                                disable-transitions
                                :type="formData.disable === 0 ? 'primary' : 'info'"
                            >
                                {{ formData.disable === 0 ? '开启' : '关闭' }}
                            </el-tag>
                        </el-form-item>
                        <el-form-item label="自定义存储：" prop="allow_custom_storage">
                            <div v-if="editStatus">
                                <el-radio-group v-model="formData.allow_custom_storage">
                                    <el-radio :value="1">允许</el-radio>
                                    <el-radio :value="0">不允许</el-radio>
                                </el-radio-group>
                                <p class="text-info text-sm">
                                    开启后，租户可在后台系统设置中配置自己的文件存储；关闭时统一使用平台存储。
                                </p>
                            </div>
                            <el-tag
                                v-else
                                disable-transitions
                                :type="formData.allow_custom_storage === 1 ? 'primary' : 'info'"
                            >
                                {{ formData.allow_custom_storage === 1 ? '允许' : '不允许' }}
                            </el-tag>
                        </el-form-item>
                        <el-form-item
                            v-if="editStatus ? formData.allow_custom_storage === 1 : true"
                            label="本地存储："
                            prop="allow_local_storage"
                        >
                            <div v-if="editStatus">
                                <el-radio-group v-model="formData.allow_local_storage">
                                    <el-radio :value="1">允许</el-radio>
                                    <el-radio :value="0">不允许</el-radio>
                                </el-radio-group>
                                <p class="text-info text-sm">
                                    关闭后，租户只能配置对象存储，后台不显示本地存储入口。
                                </p>
                            </div>
                            <el-tag
                                v-else
                                disable-transitions
                                :type="formData.allow_local_storage === 1 ? 'primary' : 'info'"
                            >
                                {{ formData.allow_local_storage === 1 ? '允许' : '不允许' }}
                            </el-tag>
                        </el-form-item>
                        <el-form-item v-if="!editStatus" label="租户点数：">
                            {{ formData.point_balance || '0.00' }}
                        </el-form-item>
                        <el-form-item v-if="!editStatus" label="创建时间：">
                            {{ formData.create_time }}
                        </el-form-item>
                        <el-form-item label="租户备注：" prop="notes">
                            <el-input
                                v-if="editStatus"
                                v-model="formData.notes"
                                placeholder="请输入租户备注"
                                style="max-width: 250px"
                                type="textarea"
                                :maxlength="100"
                            />
                            <span class="break-all" v-else>
                                {{ formData.notes || '--' }}
                            </span>
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="主访问地址："
                            class="col-span-2"
                        >
                            <a
                                :href="formData.links?.current?.admin || formData.domain"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ formData.links?.current?.admin || formData.domain || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="formData.links?.current?.admin || formData.domain"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="默认域名："
                            class="col-span-2"
                            prop="default_domain"
                        >
                            <a
                                :href="formData.default_domain"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ formData.default_domain || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="formData.default_domain"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="前台PC："
                            class="col-span-2"
                            prop="default_domain"
                        >
                            <a
                                :href="tenantPcUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ tenantPcUrl || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="tenantPcUrl"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                        <el-form-item
                            v-if="!editStatus"
                            label="移动端："
                            class="col-span-2"
                            prop="default_domain"
                        >
                            <a
                                :href="tenantMobileUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ tenantMobileUrl || '--' }}
                            </a>
                            <span
                                class="flex items-center ml-2 cursor-pointer"
                                v-copy="tenantMobileUrl"
                            >
                                <icon name="el-icon-DocumentCopy" />
                                复制
                            </span>
                        </el-form-item>
                    </el-form>
                </el-tab-pane>
                <el-tab-pane lazy label="账号列表" name="accounts">
                    <Accounts :tenant_id="formData.id" />
                </el-tab-pane>
                <el-tab-pane lazy label="用户列表" name="users">
                    <Users :tenant_id="formData.id" />
                </el-tab-pane>
            </el-tabs>
        </div>
    </el-drawer>
</template>

<script lang="ts" setup>
import type { FormInstance, FormRules } from 'element-plus'
import { cloneDeep } from 'lodash-es'

import { getUserDetail, tenantSso, userEdit } from '@/api/consumer'
import { useLockFn } from '@/hooks/useLockFn'

import Accounts from './account/index.vue'
import Users from './user/index.vue'

interface DetailType {
    avatar: string
    create_time: string
    name: string
    sn: string
    domain: string
    default_domain: string
    id: number
    disable: number
    tel: string
    domain_alias: string
    domain_alias_enable: number
    access_mode: 'subdomain' | 'id' | 'alias'
    allow_custom_storage: number
    allow_local_storage: number
    notes: string
    point_balance: string
    tenant_id_domain?: string
    links?: Record<string, any>
}

const drawer = ref(false)
const formRef = shallowRef<FormInstance>()
const tenantId = ref<number>(0)
const activeName = ref<'profile' | 'accounts' | 'users'>('profile')
const editStatus = ref<boolean>(false)
const tempFormData = ref<DetailType>()
const loading = ref<boolean>(true)
const formData = ref<DetailType>({
    avatar: '',
    create_time: '',
    name: '',
    sn: '',
    domain: '',
    default_domain: '',
    id: 0,
    disable: 0,
    tel: '',
    domain_alias: '',
    domain_alias_enable: 1,
    access_mode: 'subdomain',
    allow_custom_storage: 0,
    allow_local_storage: 1,
    notes: '',
    point_balance: '0.00'
})

const formRules: FormRules = {
    name: [
        {
            required: true,
            message: '请输入租户名称',
            trigger: ['blur']
        }
    ],
    access_mode: [
        {
            required: true,
            message: '请选择访问方式',
            trigger: ['change']
        }
    ],
    domain_alias: [
        {
            validator: (rule, value, callback) => {
                if (formData.value.access_mode === 'alias' && !String(value || '').trim()) {
                    callback(new Error('请设置域名别名'))
                    return
                }
                callback()
            },
            trigger: ['blur', 'change']
        }
    ]
}

const emits = defineEmits(['refresh'])

const openHandle = (id: number, status?: boolean, tabIndex?: 'profile' | 'accounts' | 'users') => {
    loading.value = true
    activeName.value = tabIndex || 'profile'
    editStatus.value = status || false
    getDetails(id)
    tenantId.value = id
    drawer.value = true
}

const beforeLeave = async () => {
    if (editStatus.value) {
        try {
            await ElMessageBox.confirm('修改还未保存，确认退出编辑吗？')
            handleEdit()
        } catch (error) {
            return false
        }
    }
}
const getDetails = async (id: number) => {
    const data: DetailType = await getUserDetail({
        id: id
    })
    loading.value = false
    data.access_mode = data.access_mode || (data.domain_alias_enable === 0 ? 'alias' : 'subdomain')
    formData.value = data
    tempFormData.value = cloneDeep(formData.value)
}

const beforeClose = (done: () => void) => {
    if (editStatus.value) {
        ElMessageBox.confirm('修改还未保存，确认退出编辑吗？')
            .then(() => {
                done()
            })
            .catch(() => {
                console.log('取消')
            })
    } else {
        done()
    }
}

const afterClose = () => {
    formRef.value?.resetFields()
}

const handleEdit = async (save?: boolean) => {
    if (editStatus.value) {
        if (save) {
            await formRef.value?.validate()
            await lockSubmit()
        } else {
            formData.value = tempFormData.value as DetailType
            formRef.value?.clearValidate()
        }
    } else {
        activeName.value = 'profile'
        await nextTick()
    }
    editStatus.value = !editStatus.value
}

const submitEdit = async () => {
    loading.value = true
    try {
        formData.value.domain_alias_enable = formData.value.access_mode === 'alias' ? 0 : 1
        if (formData.value.allow_custom_storage !== 1) {
            formData.value.allow_local_storage = 1
        }
        await userEdit(formData.value)
        await getDetails(tenantId.value)
        emits('refresh')
    } catch (error) {
        loading.value = false
    }
}

const accessModeText = (mode: string) => {
    return (
        {
            subdomain: '默认子域名',
            id: 'ID访问',
            alias: '别名访问'
        }[mode] || '默认子域名'
    )
}

const tenantPcUrl = computed(() => {
    return (
        formData.value.links?.current?.pc ||
        formData.value.links?.subdomain?.pc ||
        formData.value.default_domain?.replace('/admin/', '/')
    )
})

const tenantMobileUrl = computed(() => {
    return (
        formData.value.links?.current?.mobile ||
        formData.value.links?.subdomain?.mobile ||
        formData.value.default_domain?.replace('/admin/', '/mobile/')
    )
})

const handleEnterTenant = async () => {
    if (!formData.value.id) {
        return
    }
    const data = await tenantSso({
        tenant_id: formData.value.id,
        target: 'admin'
    })
    window.open(data.url, '_blank')
}

const { isLock, lockFn: lockSubmit } = useLockFn(submitEdit)

defineExpose({
    openHandle
})
</script>

<style lang="scss" scoped>
:deep(.el-tabs__content) {
    flex: 1;

    .el-tab-pane {
        height: 100%;
    }
}

.profile {
    :deep(.el-form-item__content) {
        align-items: center;
    }
}
</style>
