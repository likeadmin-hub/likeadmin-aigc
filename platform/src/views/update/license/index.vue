<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">授权信息</div>
                    <div class="text-sm text-tx-secondary mt-1">生成机器码、导入授权文件并查看系统与应用权益</div>
                </div>
                <div class="flex items-center gap-2">
                    <el-button @click="downloadApplyFile">下载授权申请文件</el-button>
                    <el-upload :show-file-list="false" :http-request="uploadLicense" accept=".json,.license">
                        <el-button type="primary">上传授权文件</el-button>
                    </el-upload>
                </div>
            </div>
        </el-card>

        <el-card v-loading="loading" class="!border-none mt-4" shadow="never">
            <el-descriptions :column="2" border>
                <el-descriptions-item label="授权状态">
                    <el-tag :type="statusType(info.status)">{{ statusText(info.status) }}</el-tag>
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
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <template #header>
                <div class="font-medium">应用权益</div>
            </template>
            <el-table :data="payload.apps || []" size="large">
                <el-table-column label="应用标识" prop="app_code" min-width="160" />
                <el-table-column label="应用名称" min-width="160">
                    <template #default="{ row }">{{ row.name || '-' }}</template>
                </el-table-column>
                <el-table-column label="状态" min-width="100">
                    <template #default="{ row }">
                        <el-tag :type="appEnabled(row) ? 'success' : 'info'">{{ appEnabled(row) ? '可用' : '不可用' }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="最高版本" min-width="120">
                    <template #default="{ row }">{{ appMaxVersion(row) }}</template>
                </el-table-column>
                <el-table-column label="到期时间" min-width="180">
                    <template #default="{ row }">{{ formatTime(appExpireTime(row)) }}</template>
                </el-table-column>
            </el-table>
        </el-card>
    </div>
</template>

<script lang="ts" setup name="update-license">
import { RequestCodeEnum } from '@/enums/requestEnums'
import feedback from '@/utils/feedback'
import { updateLicenseImport, updateLicenseInfo, updateMachineCode } from '@/api/update_service'

const loading = ref(false)
const info = ref<any>({})

const payload = computed(() => info.value.payload || {})
const machine = computed(() => info.value.machine || {})

const getInfo = async () => {
    loading.value = true
    try {
        const data = await updateLicenseInfo()
        info.value = data
    } finally {
        loading.value = false
    }
}

const uploadLicense = async (options: any) => {
    const form = new FormData()
    form.append('file', options.file)
    const { data } = await updateLicenseImport(form)
    if (data.code === RequestCodeEnum.SUCCESS) {
        feedback.msgSuccess(data.msg || '导入成功')
        getInfo()
        return
    }
    feedback.msgError(data.msg || '导入失败')
}

const downloadApplyFile = async () => {
    let machineData = machine.value
    if (!machineData?.machine_code || !machineData?.machine_fingerprint_hash) {
        machineData = await updateMachineCode()
        info.value.machine = machineData
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

const statusText = (status: string) =>
    ({
        active: '有效',
        not_imported: '未导入',
        expired: '已过期',
        domain_mismatch: '域名不匹配',
        machine_mismatch: '机器不匹配',
        replaced: '已替换'
    }[status] || status || '-')
const statusType = (status: string) => (status === 'active' ? 'success' : status === 'not_imported' ? 'info' : 'danger')
const shortHash = (hash: string) => (hash ? `${hash.slice(0, 10)}...${hash.slice(-8)}` : '-')
const appEnabled = (row: any) => Boolean(row.is_opened ?? row.enabled ?? true)
const appMaxVersion = (row: any) => row.max_version || row.latest_version || row.version || '-'
const appExpireTime = (row: any) =>
    Math.max(Number(row.expires_at || 0), Number(row.user_expire_time || 0), Number(row.tenant_expire_time || 0))
const formatTime = (value: number | string) => {
    const time = Number(value || 0)
    return time ? new Date(time * 1000).toLocaleString() : '-'
}

getInfo()
</script>
