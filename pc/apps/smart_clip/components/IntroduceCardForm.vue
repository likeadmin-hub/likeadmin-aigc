<template>
    <div class="two-grid">
        <div>
            <label class="field-label">身份栏名称</label>
            <ElInput :model-value="name" placeholder="名称" @update:model-value="$emit('update:name', String($event))" />
        </div>
        <div>
            <label class="field-label">身份栏描述</label>
            <ElInput :model-value="description" placeholder="描述" @update:model-value="$emit('update:description', String($event))" />
        </div>
    </div>
</template>

<script lang="ts" setup>
import { ElInput } from 'element-plus'

defineProps<{
    name: string
    description: string
}>()

defineEmits<{
    (event: 'update:name', value: string): void
    (event: 'update:description', value: string): void
}>()
</script>

<style scoped>
.two-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.field-label {
    display: block;
    margin: 0 0 10px;
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1;
}

@media (max-width: 520px) {
    .two-grid {
        grid-template-columns: 1fr;
    }
}
</style>
