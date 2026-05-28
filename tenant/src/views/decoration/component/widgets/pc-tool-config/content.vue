<template>
    <div class="pc-tool-config-preview" :style="styles">
        <div class="pc-tool-config-preview__head">
            <span>工具配置</span>
            <strong>{{ enabledCount }} 个工具</strong>
        </div>
        <div class="pc-tool-config-preview__grid">
            <div
                v-for="tool in previewTools"
                :key="tool.id"
                class="pc-tool-config-preview__card"
            >
                <div class="cover"></div>
                <div class="meta">
                    <strong>{{ tool.title }}</strong>
                    <span>{{ tool.badge || 'AI 工具' }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script lang="ts" setup>
import type { PropType } from 'vue'
import type options from './options'

type OptionsType = ReturnType<typeof options>
const props = defineProps({
    content: {
        type: Object as PropType<OptionsType['content']>,
        default: () => ({})
    },
    styles: {
        type: Object as PropType<OptionsType['styles']>,
        default: () => ({})
    }
})

const enabledCount = computed(() => (props.content.data || []).filter((item: any) => item.enabled !== 0).length)
const previewTools = computed(() =>
    (props.content.data || [])
        .filter((item: any) => item.enabled !== 0)
        .slice(0, 4)
)
</script>

<style lang="scss" scoped>
.pc-tool-config-preview {
    padding: 18px;
    border-radius: 8px;
    background: #ffffff;
    color: #111827;
    border: 1px solid rgba(85, 112, 255, 0.1);
    box-shadow: 0 16px 36px rgba(74, 87, 128, 0.08);
    box-sizing: border-box;
}

.pc-tool-config-preview__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    span {
        color: #7a8498;
    }
    strong {
        font-size: 18px;
    }
}
.pc-tool-config-preview__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-top: 16px;
}
.pc-tool-config-preview__card {
    overflow: hidden;
    border: 1px solid #edf0f7;
    border-radius: 8px;
    background: #f8faff;
    .cover {
        height: 72px;
        background: linear-gradient(135deg, #dce7ff, #ffe2f0);
    }
    .meta {
        padding: 10px;
        strong,
        span {
            display: block;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }
        span {
            margin-top: 4px;
            color: #7a8498;
            font-size: 12px;
        }
    }
}
</style>
