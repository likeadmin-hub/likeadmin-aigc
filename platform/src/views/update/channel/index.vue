<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">接口渠道</div>
                    <div class="text-sm text-tx-secondary mt-1">配置授权系统并完成授权文件导入</div>
                </div>
                <div class="flex items-center gap-2">
                    <el-tag :type="configured ? 'success' : 'warning'">
                        {{ configured ? '已配置接口' : '未配置接口' }}
                    </el-tag>
                    <el-tag :type="licenseStatusType(licenseInfo.status)">
                        {{ licenseStatusText(licenseInfo.status) }}
                    </el-tag>
                </div>
            </div>
        </el-card>

        <el-card v-loading="loading" class="!border-none mt-4" shadow="never">
            <el-tabs v-model="activeTab">
                <el-tab-pane label="接口配置" name="source">
                    <el-form label-width="110px">
                        <el-form-item label="名称">
                            <el-input v-model="sourceForm.name" placeholder="授权系统" />
                        </el-form-item>
                        <el-form-item label="开发模式">
                            <div class="w-full">
                                <el-switch
                                    v-model="sourceForm.dev_mode"
                                    :active-value="1"
                                    :inactive-value="0"
                                    active-text="开启"
                                    inactive-text="关闭"
                                />
                                <div class="text-xs text-tx-secondary mt-1">
                                    开启时使用当前接口地址和 API Key；关闭时使用线上接口地址和线上 API Key。
                                </div>
                            </div>
                        </el-form-item>
                        <el-form-item label="接口地址">
                            <el-input v-model="sourceForm.base_url" placeholder="https://update.example.com 或 https://update.example.com/aigc/v1" />
                        </el-form-item>
                        <el-form-item label="API Key">
                            <el-input v-model="sourceForm.license_key" placeholder="Bearer API Key，可为空" show-password />
                        </el-form-item>
                        <el-form-item label="SSL校验">
                            <div class="w-full">
                                <el-switch v-model="sourceForm.ssl_verify" :active-value="1" :inactive-value="0" />
                                <div class="text-xs text-tx-secondary mt-1">
                                    关闭后仍会保留授权文件和响应签名校验，用于兼容证书链不完整的接口渠道。
                                </div>
                            </div>
                        </el-form-item>
                        <el-form-item label="线上接口地址">
                            <el-input v-model="sourceForm.online_base_url" placeholder="https://online.example.com 或 https://online.example.com/aigc/v1" />
                        </el-form-item>
                        <el-form-item label="线上 API Key">
                            <el-input v-model="sourceForm.online_license_key" placeholder="线上 Bearer API Key，可为空" show-password />
                        </el-form-item>
                        <el-form-item label="验签公钥">
                            <div class="w-full">
                                <div class="flex justify-end mb-2">
                                    <el-upload
                                        :show-file-list="false"
                                        :auto-upload="false"
                                        accept=".pem,.key,.crt,.cer,.txt,.json"
                                        :on-change="readPublicKeyFile"
                                    >
                                        <el-button>上传公钥文件</el-button>
                                    </el-upload>
                                </div>
                                <el-input v-model="sourceForm.public_key" type="textarea" :rows="7" placeholder="可手动输入 PEM 公钥，也可以上传 .pem/.key/.crt/.txt/.json 文件" />
                            </div>
                        </el-form-item>
                        <el-form-item>
                            <el-button type="primary" @click="saveSource">保存并下一步</el-button>
                            <el-button @click="refreshAll">刷新</el-button>
                        </el-form-item>
                    </el-form>
                </el-tab-pane>
                <el-tab-pane label="授权导入" name="license" :disabled="!configured">
                    <el-alert
                        v-if="!configured"
                        class="mb-4"
                        type="warning"
                        show-icon
                        :closable="false"
                        title="请先保存接口地址和验签公钥"
                    />
                    <template v-else>
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                            <div>
                                <div class="font-medium">授权申请</div>
                                <div class="text-sm text-tx-secondary mt-1">下载申请文件后交给授权系统生成授权文件，再在这里上传</div>
                            </div>
                            <div class="flex items-center gap-2">
                                <el-button @click="downloadApplyFile">下载授权申请文件</el-button>
                                <el-upload :show-file-list="false" :http-request="uploadLicense" accept=".json,.license">
                                    <el-button type="primary">上传授权文件</el-button>
                                </el-upload>
                            </div>
                        </div>

                        <el-descriptions :column="2" border>
                            <el-descriptions-item label="授权状态">
                                <el-tag :type="licenseStatusType(licenseInfo.status)">{{ licenseStatusText(licenseInfo.status) }}</el-tag>
                            </el-descriptions-item>
                            <el-descriptions-item label="客户名称">{{ payload.customer_name || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="授权ID">{{ payload.license_id || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="产品码">{{ payload.product_code || machine.product_code || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="绑定域名">{{ (payload.domains || []).join(', ') || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="当前域名">{{ machine.domain || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="授权有效期">{{ formatTime(payload.expires_at) }}</el-descriptions-item>
                            <el-descriptions-item label="更新截止期">{{ formatTime(payload.update_until) }}</el-descriptions-item>
                            <el-descriptions-item label="最高系统版本">{{ payload.max_core_version || '-' }}</el-descriptions-item>
                            <el-descriptions-item label="机器指纹">{{ shortHash(machine.machine_fingerprint_hash) }}</el-descriptions-item>
                        </el-descriptions>
                        <el-input class="mt-4" :model-value="machine.machine_code || ''" type="textarea" :rows="4" readonly />
                    </template>
                </el-tab-pane>
            </el-tabs>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <template #header>
                <div class="font-medium">配置状态</div>
            </template>
            <el-descriptions :column="2" border>
                <el-descriptions-item label="授权系统">{{ configured ? '已填写' : '未填写' }}</el-descriptions-item>
                <el-descriptions-item label="开发模式">{{ sourceForm.dev_mode === 0 ? '关闭' : '开启' }}</el-descriptions-item>
                <el-descriptions-item label="接口地址">{{ sourceForm.base_url || '-' }}</el-descriptions-item>
                <el-descriptions-item label="API Key">{{ sourceForm.license_key ? '已填写' : '未填写' }}</el-descriptions-item>
                <el-descriptions-item label="SSL校验">{{ sourceForm.ssl_verify === 1 ? '开启' : '关闭' }}</el-descriptions-item>
                <el-descriptions-item label="线上接口地址">{{ sourceForm.online_base_url || '-' }}</el-descriptions-item>
                <el-descriptions-item label="线上 API Key">{{ sourceForm.online_license_key ? '已填写' : '未填写' }}</el-descriptions-item>
                <el-descriptions-item label="当前使用地址">{{ sourceForm.active_base_url || '-' }}</el-descriptions-item>
                <el-descriptions-item label="当前使用 Key">{{ sourceForm.active_api_key ? '已填写' : '未填写' }}</el-descriptions-item>
                <el-descriptions-item label="验签公钥">{{ sourceForm.public_key ? '已填写' : '未填写' }}</el-descriptions-item>
                <el-descriptions-item label="授权文件">{{ licenseStatusText(licenseInfo.status) }}</el-descriptions-item>
            </el-descriptions>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="update-channel">
import axios from 'axios'

import { updateLicenseInfo, updateMachineCode, updateSaveSource, updateSource } from '@/api/update_service'
import config from '@/config'
import { ContentTypeEnum, RequestCodeEnum } from '@/enums/requestEnums'
import useUserStore from '@/stores/modules/user'
import feedback from '@/utils/feedback'

const userStore = useUserStore()
const loading = ref(false)
const activeTab = ref('source')
const sourceForm = ref<any>({})
const licenseInfo = ref<any>({})
const configured = computed(() => !!sourceForm.value.base_url)
const payload = computed(() => licenseInfo.value.payload || {})
const machine = computed(() => licenseInfo.value.machine || {})

const getSource = async () => {
    sourceForm.value = normalizeSource(await updateSource())
}

const normalizeSource = (source: any = {}) => ({
    id: source.id || 0,
    name: source.name || '授权系统',
    base_url: source.base_url || '',
    license_key: source.license_key || source.api_key || '',
    online_base_url: source.online_base_url || '',
    online_license_key: source.online_license_key || '',
    dev_mode: Number(source.dev_mode ?? 1),
    ssl_verify: Number(source.ssl_verify ?? 0),
    public_key: source.public_key || '',
    status: Number(source.status ?? 1),
    active_base_url: source.active_base_url || '',
    active_api_key: source.active_api_key || ''
})

const getLicenseInfo = async () => {
    licenseInfo.value = await updateLicenseInfo()
}

const refreshAll = async () => {
    loading.value = true
    try {
        await Promise.all([getSource(), getLicenseInfo()])
    } finally {
        loading.value = false
    }
}

const saveSource = async () => {
    await updateSaveSource(sourceForm.value)
    feedback.msgSuccess('保存成功')
    await refreshAll()
    activeTab.value = 'license'
}

const uploadLicense = async (options: any) => {
    const form = new FormData()
    form.append('file', options.file)
    const { data } = await axios.post(`${config.baseUrl}${config.urlPrefix}/upgrade.upgrade/importLicense`, form, {
        headers: {
            'Content-Type': ContentTypeEnum.FORM_DATA,
            token: userStore.token,
            version: config.version
        }
    })
    if (data.code === RequestCodeEnum.SUCCESS) {
        feedback.msgSuccess(data.msg || '导入成功')
        await refreshAll()
        return
    }
    feedback.msgError(data.msg || '导入失败')
}

const readPublicKeyFile = (uploadFile: any) => {
    const file = uploadFile.raw
    if (!file) {
        return
    }
    const reader = new FileReader()
    reader.onload = () => {
        const content = String(reader.result || '').trim()
        if (!content) {
            feedback.msgError('公钥文件内容为空')
            return
        }
        sourceForm.value.public_key = parsePublicKeyContent(content)
        feedback.msgSuccess('公钥已读取')
    }
    reader.onerror = () => feedback.msgError('公钥文件读取失败')
    reader.readAsText(file)
}

const parsePublicKeyContent = (content: string) => {
    try {
        const data = JSON.parse(content)
        const key = data.public_key || data.publicKey || data.pem || data.key || data.certificate
        if (key) {
            return String(key).replace(/\\n/g, '\n').trim()
        }
    } catch (e) {
        return content
    }
    return content
}

const downloadApplyFile = async () => {
    let machineData = machine.value
    if (!machineData?.machine_code || !machineData?.machine_fingerprint_hash) {
        machineData = await updateMachineCode()
        licenseInfo.value.machine = machineData
    }
    const applyData = {
        product_code: machineData.product_code || '',
        domain: machineData.domain || '',
        machine_fingerprint_hash: machineData.machine_fingerprint_hash || '',
        machine_code: machineData.machine_code || '',
        environment: machineData.environment || {}
    }
    if (!applyData.product_code || !applyData.domain || !applyData.machine_fingerprint_hash || !applyData.machine_code) {
        feedback.msgError('机器码生成失败，请刷新后重试')
        return
    }
    const blob = new Blob(
        [
            JSON.stringify(applyData, null, 2)
        ],
        { type: 'application/json;charset=utf-8' }
    )
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `license_apply_${applyData.domain || 'server'}.json`
    link.click()
    URL.revokeObjectURL(url)
}

const licenseStatusText = (status: string) =>
    ({
        active: '授权有效',
        not_imported: '授权未导入',
        expired: '授权已过期',
        domain_mismatch: '域名不匹配',
        machine_mismatch: '机器不匹配',
        replaced: '授权已替换'
    }[status] || status || '授权未导入')

const licenseStatusType = (status: string) => (status === 'active' ? 'success' : status === 'not_imported' ? 'info' : 'danger')
const shortHash = (hash: string) => (hash ? `${hash.slice(0, 10)}...${hash.slice(-8)}` : '-')
const formatTime = (value: number | string) => {
    const time = Number(value || 0)
    return time ? new Date(time * 1000).toLocaleString() : '-'
}

refreshAll()
</script>
