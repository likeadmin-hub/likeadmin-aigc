<template>
    <el-card class="!border-none" shadow="never">
        <el-form inline class="mb-4">
            <el-form-item>
                <el-input
                    v-model="query.keyword"
                    placeholder="搜索会话标题"
                    clearable
                    @keyup.enter="getLists"
                />
            </el-form-item>
            <el-form-item>
                <el-button type="primary" @click="getLists">查询</el-button>
            </el-form-item>
        </el-form>
        <el-table v-loading="loading" :data="lists" size="large">
            <el-table-column label="ID" prop="id" width="90" />
            <el-table-column label="用户ID" prop="user_id" width="110" />
            <el-table-column label="标题" prop="title" min-width="180" />
            <el-table-column label="模型" prop="model_code" min-width="140" />
            <el-table-column label="消息数" prop="message_count" width="100" />
            <el-table-column
                label="最后消息"
                prop="last_message"
                min-width="240"
                show-overflow-tooltip
            />
            <el-table-column label="操作" width="100">
                <template #default="{ row }">
                    <el-button type="primary" link @click="openDetail(row)">详情</el-button>
                </template>
            </el-table-column>
        </el-table>

        <el-drawer v-model="detailVisible" title="会话详情" size="620px">
            <div
                v-for="item in detail.messages || []"
                :key="item.id"
                class="message-item"
                :class="item.role"
            >
                <div class="message-role">{{ item.role }}</div>
                <div class="message-content">{{ item.content || '-' }}</div>
            </div>
        </el-drawer>
    </el-card>
</template>

<script lang="ts" setup name="tenant-aigc-llm-session">
import { getAigcLlmAdminSessionDetail, getAigcLlmAdminSessions } from '@/apps/aigc_llm/api'

const loading = ref(false)
const detailVisible = ref(false)
const lists = ref<any[]>([])
const detail = ref<any>({})
const query = reactive({ keyword: '' })

const getLists = async () => {
    loading.value = true
    try {
        lists.value = await getAigcLlmAdminSessions(query)
    } finally {
        loading.value = false
    }
}

const openDetail = async (row: any) => {
    detail.value = await getAigcLlmAdminSessionDetail({ session_id: row.id })
    detailVisible.value = true
}

getLists()
</script>

<style scoped>
.message-item {
    margin-bottom: 14px;
    border-radius: 8px;
    background: #f7f8fa;
    padding: 12px;
}
.message-item.assistant {
    background: #eef5ff;
}
.message-role {
    margin-bottom: 6px;
    color: #64748b;
    font-size: 12px;
}
.message-content {
    white-space: pre-wrap;
    line-height: 1.6;
}
</style>
