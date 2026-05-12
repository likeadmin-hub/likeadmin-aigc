<template>
    <div>
        <el-card class="!border-none" shadow="never">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-lg font-medium">版本日志</div>
                    <div class="text-sm text-tx-secondary mt-1">从云端获取系统版本发布记录和更新内容</div>
                </div>
                <el-button type="primary" :loading="pager.loading" @click="resetPage">刷新</el-button>
            </div>
        </el-card>

        <el-card class="!border-none mt-4" shadow="never">
            <el-form class="ls-form" :model="formData" inline>
                <el-form-item label="关键词">
                    <el-input
                        v-model="formData.keyword"
                        class="w-[240px]"
                        clearable
                        placeholder="版本 / 标题 / 摘要"
                        @keyup.enter="resetPage"
                    />
                </el-form-item>
                <el-form-item label="版本">
                    <el-input v-model="formData.version" class="w-[180px]" clearable @keyup.enter="resetPage" />
                </el-form-item>
                <el-form-item label="类型">
                    <el-select v-model="formData.update_type" style="width: 160px" clearable placeholder="全部类型">
                        <el-option label="补丁" value="patch" />
                        <el-option label="小版本" value="minor" />
                        <el-option label="大版本" value="major" />
                    </el-select>
                </el-form-item>
                <el-form-item>
                    <el-button type="primary" @click="resetPage">查询</el-button>
                    <el-button @click="resetParams">重置</el-button>
                </el-form-item>
            </el-form>
        </el-card>

        <el-card v-loading="pager.loading" class="!border-none mt-4" shadow="never">
            <el-table :data="pager.lists" size="large">
                <el-table-column label="版本" min-width="120">
                    <template #default="{ row }">
                        <div class="font-medium">{{ versionText(row) }}</div>
                        <div v-if="row.require_core" class="text-xs text-tx-secondary mt-1">{{ row.require_core }}</div>
                    </template>
                </el-table-column>
                <el-table-column label="标题" min-width="180">
                    <template #default="{ row }">
                        {{ row.title || `系统版本 ${versionText(row)}` }}
                    </template>
                </el-table-column>
                <el-table-column label="摘要" prop="summary" min-width="240" show-overflow-tooltip />
                <el-table-column label="类型" width="110">
                    <template #default="{ row }">
                        <el-tag>{{ updateTypeText(row.update_type) }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="强制" width="90">
                    <template #default="{ row }">
                        <el-tag :type="row.force ? 'danger' : 'info'">{{ row.force ? '是' : '否' }}</el-tag>
                    </template>
                </el-table-column>
                <el-table-column label="包大小" width="120">
                    <template #default="{ row }">{{ formatSize(row.package_size) }}</template>
                </el-table-column>
                <el-table-column label="发布时间" min-width="180">
                    <template #default="{ row }">{{ formatTime(row.release_time || row.publish_time) }}</template>
                </el-table-column>
                <el-table-column label="操作" width="100" fixed="right">
                    <template #default="{ row }">
                        <el-button type="primary" link @click="openDetail(row)">详情</el-button>
                    </template>
                </el-table-column>
            </el-table>
            <el-empty v-if="!pager.loading && pager.lists.length === 0" description="暂无版本日志" />
            <div class="flex mt-4 justify-end">
                <pagination v-model="pager" @change="getLists" />
            </div>
        </el-card>

        <el-dialog v-model="detailVisible" title="版本日志详情" width="760px">
            <el-descriptions :column="2" border>
                <el-descriptions-item label="版本">{{ versionText(detail) }}</el-descriptions-item>
                <el-descriptions-item label="标题">{{ detail.title || '-' }}</el-descriptions-item>
                <el-descriptions-item label="类型">{{ updateTypeText(detail.update_type) }}</el-descriptions-item>
                <el-descriptions-item label="发布时间">{{ formatTime(detail.release_time || detail.publish_time) }}</el-descriptions-item>
                <el-descriptions-item label="强制更新">{{ detail.force ? '是' : '否' }}</el-descriptions-item>
                <el-descriptions-item label="包大小">{{ formatSize(detail.package_size) }}</el-descriptions-item>
            </el-descriptions>
            <el-alert
                v-if="detail.summary"
                class="mt-4"
                type="info"
                :closable="false"
                :title="detail.summary"
            />
            <div class="mt-4 version-content">
                <div v-for="(item, index) in changelog(detail)" :key="index" class="version-content__item">
                    {{ item }}
                </div>
                <el-empty v-if="changelog(detail).length === 0" description="暂无更新内容" />
            </div>
        </el-dialog>
    </div>
</template>

<script lang="ts" setup name="update-log">
import { updateLogs } from '@/api/update_service'
import { usePaging } from '@/hooks/usePaging'

const detailVisible = ref(false)
const detail = ref<any>({})
const formData = reactive({
    keyword: '',
    version: '',
    update_type: ''
})

const { pager, getLists, resetParams, resetPage } = usePaging({
    fetchFun: updateLogs,
    params: formData
})

const openDetail = (row: any) => {
    detail.value = row
    detailVisible.value = true
}
const versionText = (row: any) => row?.version || row?.version_no || '-'
const updateTypeText = (type: string) =>
    ({ patch: '补丁', minor: '小版本', major: '大版本', full: '完整包' }[type] || type || '-')
const formatTime = (value: number | string) => {
    const time = Number(value || 0)
    return time ? new Date(time * 1000).toLocaleString() : '-'
}
const formatSize = (value: number | string) => {
    const size = Number(value || 0)
    if (!size) return '-'
    if (size >= 1024 * 1024) return `${(size / 1024 / 1024).toFixed(2)} MB`
    return `${Math.ceil(size / 1024)} KB`
}
const changelog = (row: any) => {
    const content = row?.changelog || row?.update_content || row?.content || []
    if (Array.isArray(content)) {
        return content.map((item: any) => item?.update_function || item?.content || item?.title || item).filter(Boolean)
    }
    return String(content || '')
        .split('\n')
        .map((item) => item.trim())
        .filter(Boolean)
}

getLists()
</script>

<style lang="scss" scoped>
.version-content {
    padding: 14px 16px;
    background: var(--el-fill-color-lighter);
    border-radius: 8px;
}

.version-content__item {
    position: relative;
    padding-left: 14px;
    font-size: 14px;
    line-height: 28px;
    color: var(--el-text-color-regular);

    &::before {
        position: absolute;
        top: 12px;
        left: 0;
        width: 5px;
        height: 5px;
        content: '';
        background: var(--el-color-primary);
        border-radius: 50%;
    }
}
</style>
