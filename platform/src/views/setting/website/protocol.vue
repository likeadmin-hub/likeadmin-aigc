<template>
    <div class="grid xl:grid-cols-2 gap-4">
        <el-card
            v-for="item in protocolItems"
            :key="item.type"
            class="!border-none mb-4"
            shadow="never"
        >
            <template #header>
                <span class="font-medium">{{ item.label }}</span>
            </template>
            <el-form :model="formData" label-width="80px">
                <el-form-item label="协议名称">
                    <el-input v-model="formData[item.titleKey]" />
                </el-form-item>
            </el-form>

            <editor class="mb-10" v-model="formData[item.contentKey]" height="420"></editor>
        </el-card>
    </div>
    <footer-btns v-perms="['setting.web.web_setting/setAgreement']">
        <el-button type="primary" @click="handleProtocolEdit">保存</el-button>
    </footer-btns>
</template>

<script setup lang="ts" naem="webProtocol">
import { getProtocol, setProtocol } from '@/api/setting/website'

const protocolItems = [
    { type: 'service', label: '用户服务协议', titleKey: 'service_title', contentKey: 'service_content' },
    { type: 'privacy', label: '隐私政策', titleKey: 'privacy_title', contentKey: 'privacy_content' },
    { type: 'community', label: '社区自律公约', titleKey: 'community_title', contentKey: 'community_content' },
    { type: 'ai_usage', label: 'AI功能使用须知', titleKey: 'ai_usage_title', contentKey: 'ai_usage_content' },
    { type: 'paid', label: '付费用户协议', titleKey: 'paid_title', contentKey: 'paid_content' },
    { type: 'points_rule', label: '积分规则', titleKey: 'points_rule_title', contentKey: 'points_rule_content' }
] as const

const formData = ref<Record<string, string>>({})
const protocolGet = async () => {
    formData.value = await getProtocol()
}

const handleProtocolEdit = async (): Promise<void> => {
    await setProtocol({ ...formData.value })
    protocolGet()
}
protocolGet()
</script>
