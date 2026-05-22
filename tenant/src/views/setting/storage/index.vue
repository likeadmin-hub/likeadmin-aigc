<template>
    <div class="storage">
        <el-card class="!border-none" shadow="never">
            <el-alert type="warning" title="温馨提示：" :closable="false" show-icon>
                <template #default>
                    <div>
                        1.使用对象存储，需要将public目录下的资源文件保留原来目录路径传输到对象存储空间。
                    </div>
                    <div>2.请勿随意切换存储方式，可能导致图片无法查看。</div>
                    <div>
                        3.需要在对象存储后台设置域名跨域，否则图片生成场景无法使用，例海报合成等。
                    </div>
                    <div>
                        4.需将对象存储的图片域名添加到微信小程序官方后台request合法域名和downloadFile合法域名。
                    </div>
                </template>
            </el-alert>
        </el-card>
        <el-card class="!border-none mt-4" shadow="never" v-loading="state.loading">
            <el-alert
                v-if="!state.allowCustomStorage"
                class="mb-4"
                type="info"
                title="平台未允许该租户自定义存储，请联系平台管理员。当前上传将继续使用平台端存储配置。"
                :closable="false"
                show-icon
            />
            <el-alert
                v-else-if="!state.allowLocalStorage"
                class="mb-4"
                type="info"
                title="平台已关闭本地存储，当前仅可配置对象存储。"
                :closable="false"
                show-icon
            />
            <el-table size="large" :data="state.lists">
                <el-table-column label="储存方式" prop="name" min-width="120" />
                <el-table-column label="储存位置" prop="path" min-width="160" />
                <el-table-column label="状态" min-width="80">
                    <template #default="{ row }">
                        <el-tag :type="row.status == 1 ? 'primary' : 'danger'">
                            {{ row.status == 1 ? '开启' : '关闭' }}
                        </el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="操作" min-width="80" fixed="right">
                    <template #default="{ row }">
                        <el-button
                            v-perms="['setting.storage/setup']"
                            type="primary"
                            link
                            :disabled="!state.allowCustomStorage"
                            @click="handleSet(row.engine)"
                        >
                            设置
                        </el-button>
                    </template>
                </el-table-column>
            </el-table>
        </el-card>
        <edit-popup ref="editRef" @success="getLists" />
    </div>
</template>
<script lang="ts" setup name="storage">
import { storageLists } from '@/api/setting/storage'

import EditPopup from './edit.vue'

const editRef = shallowRef<InstanceType<typeof EditPopup>>()

const isEnabled = (value: unknown, defaultValue = true) => {
    return Number(value ?? (defaultValue ? 1 : 0)) === 1
}

// 列表数据
const state = reactive({
    loading: false,
    allowCustomStorage: false,
    allowLocalStorage: true,
    lists: []
})

// 获取存储引擎列表数据
const getLists = async () => {
    try {
        state.loading = true
        const data = await storageLists()
        if (Array.isArray(data)) {
            state.allowCustomStorage = true
            state.allowLocalStorage = true
            state.lists = data
        } else {
            state.allowCustomStorage = isEnabled(data.allow_custom_storage, false)
            state.allowLocalStorage = isEnabled(data.allow_local_storage)
            state.lists = data.lists || []
        }
        state.loading = false
    } catch (error) {
        state.loading = false
    }
}

const handleSet = (engine: string) => {
    if (engine === 'local' && !state.allowLocalStorage) {
        return
    }
    editRef.value?.open(engine, state.allowLocalStorage)
}

getLists()
</script>
