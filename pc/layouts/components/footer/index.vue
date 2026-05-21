<template>
    <footer class="layout-footer text-center bg-[#222222] py-[30px]">
        <div class="text-[#bebebe]">
            <!-- <NuxtLink> 关于我们 </NuxtLink>
            ｜ -->
            <NuxtLink :to="`/policy/${PolicyAgreementEnum.SERVICE}`">
                用户协议
            </NuxtLink>
            ｜
            <NuxtLink :to="`/policy/${PolicyAgreementEnum.PRIVACY}`">
                隐私政策
            </NuxtLink>
            ｜
            <NuxtLink to="/user/info"> 会员中心 </NuxtLink>
        </div>
        <div class="mt-4 text-tx-secondary">
            <a
                class="mx-1 hover:underline"
                :href="item.value || undefined"
                target="_blank"
                v-for="item in copyrightConfig"
                :key="item.key"
            >
                {{ item.key }}
            </a>
        </div>
    </footer>
</template>
<script lang="ts" setup>
import { useAppStore } from '@/stores/app'
import { PolicyAgreementEnum } from '@/enums/appEnums'
const appStore = useAppStore()
const defaultCopyright = [{ key: '贵州猿创科技有限责任公司', value: '' }]
const copyrightConfig = computed(() =>
    appStore.getCopyrightConfig.length ? appStore.getCopyrightConfig : defaultCopyright
)
</script>

<style lang="scss" scoped></style>
