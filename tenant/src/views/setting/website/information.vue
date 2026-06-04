<!-- 网站信息 -->
<template>
    <div class="website-information">
        <el-form
            ref="formRef"
            :rules="rules"
            class="ls-form"
            :model="formData"
            label-width="120px"
            scroll-to-error
        >
            <el-card shadow="never" class="!border-none">
                <div class="text-xl font-medium mb-[20px]">后台设置</div>
                <el-form-item label="网站名称" prop="name">
                    <div class="w-80">
                        <el-input
                            v-model.trim="formData.name"
                            placeholder="请输入网站名称"
                            maxlength="30"
                            show-word-limit
                        />
                    </div>
                </el-form-item>
                <el-form-item label="网站图标" prop="web_favicon" required>
                    <div>
                        <material-picker v-model="formData.web_favicon" :limit="1" />
                        <div class="form-tips">建议尺寸：100*100像素，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
                <el-form-item label="网站LOGO" prop="web_logo" required>
                    <div>
                        <material-picker v-model.trim="formData.web_logo" :limit="1" />
                        <div class="form-tips">建议尺寸：100*100像素，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
                <el-form-item label="后台登录图" prop="login_image">
                    <div>
                        <material-picker v-model.trim="formData.login_image" :limit="1" />
                        <div class="form-tips">用于后台登录页兜底展示，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
            </el-card>
            <el-card shadow="never" class="!border-none mt-4">
                <div class="text-xl font-medium mb-[20px]">前台设置</div>
                <el-form-item label="前台名称" prop="shop_name">
                    <div class="w-80">
                        <el-input
                            v-model.trim="formData.shop_name"
                            placeholder="请输入前台名称"
                            maxlength="30"
                            show-word-limit
                        ></el-input>
                    </div>
                </el-form-item>
                <el-form-item label="网站图标" prop="web_favicon" required>
                    <div>
                        <material-picker v-model="formData.h5_favicon" :limit="1" />
                        <div class="form-tips">建议尺寸：100*100像素，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
                <el-form-item label="前台LOGO" prop="shop_logo">
                    <div>
                        <material-picker v-model="formData.shop_logo" :limit="1" />
                        <div class="form-tips">建议尺寸：100*100px，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
            </el-card>
            <el-card shadow="never" class="!border-none mt-4">
                <div class="text-xl font-medium mb-[20px]">PC端设置</div>
                <el-form-item label="PC端LOGO" prop="pc_logo">
                    <div>
                        <material-picker v-model="formData.pc_logo" :limit="1" />
                        <div class="form-tips">建议尺寸：120*28px，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
                <el-form-item label="网站标题" prop="pc_title">
                    <div class="w-80">
                        <el-input
                            v-model.trim="formData.pc_title"
                            placeholder="请输入PC端网站标题"
                            maxlength="30"
                            show-word-limit
                        />
                    </div>
                </el-form-item>
                <el-form-item label="网站图标" prop="pc_ico">
                    <div>
                        <material-picker v-model="formData.pc_ico" :limit="1" />
                        <div class="form-tips">建议尺寸：100*100像素，支持jpg，jpeg，png格式</div>
                    </div>
                </el-form-item>
                <el-form-item label="网站描述" prop="pc_desc">
                    <div class="w-80">
                        <el-input
                            v-model.trim="formData.pc_desc"
                            placeholder="请输入PC端网站描述"
                        />
                    </div>
                </el-form-item>
                <el-form-item label="网站关键词" prop="pc_keywords">
                    <div class="w-80">
                        <el-input
                            v-model.trim="formData.pc_keywords"
                            placeholder="请输入PC端网站关键词"
                        />
                    </div>
                </el-form-item>
                <el-form-item label="登录背景类型" prop="pc_login_bg_type">
                    <el-radio-group v-model="formData.pc_login_bg_type">
                        <el-radio value="image">图片</el-radio>
                        <el-radio value="video">视频</el-radio>
                        <el-radio value="none">不设置</el-radio>
                    </el-radio-group>
                </el-form-item>
                <el-form-item v-if="formData.pc_login_bg_type !== 'none'" label="登录背景" prop="pc_login_bg">
                    <div>
                        <material-picker
                            v-model="formData.pc_login_bg"
                            :type="formData.pc_login_bg_type"
                            :limit="1"
                            width="180px"
                            height="108px"
                        />
                        <div class="form-tips">
                            图片建议 1920*1080，视频建议 mp4/webm，资源上传后不会进入系统更新包
                        </div>
                    </div>
                </el-form-item>
                <el-form-item v-if="formData.pc_login_bg_type === 'video'" label="视频封面" prop="pc_login_bg_poster">
                    <div>
                        <material-picker
                            v-model="formData.pc_login_bg_poster"
                            :limit="1"
                            width="180px"
                            height="108px"
                        />
                        <div class="form-tips">视频加载前展示的封面图，可不填</div>
                    </div>
                </el-form-item>
                <el-form-item label="PC首页风格" prop="pc_home_style">
                    <el-radio-group v-model="formData.pc_home_style">
                        <el-radio value="default">默认风格</el-radio>
                        <el-radio value="immersive">沉浸式风格</el-radio>
                    </el-radio-group>
                </el-form-item>
                <template v-if="formData.pc_home_style === 'immersive'">
                    <el-form-item label="首页大标题" prop="pc_home_immersive_title">
                        <div class="w-80">
                            <el-input
                                v-model.trim="formData.pc_home_immersive_title"
                                placeholder="请输入沉浸式首页大标题"
                                maxlength="80"
                                show-word-limit
                            />
                        </div>
                    </el-form-item>
                    <el-form-item label="首页小标题" prop="pc_home_immersive_subtitle">
                        <div class="w-80">
                            <el-input
                                v-model.trim="formData.pc_home_immersive_subtitle"
                                placeholder="请输入沉浸式首页小标题"
                                maxlength="120"
                                show-word-limit
                            />
                        </div>
                    </el-form-item>
                    <el-form-item label="首页背景类型" prop="pc_home_bg_type">
                        <el-radio-group v-model="formData.pc_home_bg_type">
                            <el-radio value="video">视频</el-radio>
                            <el-radio value="image">图片</el-radio>
                            <el-radio value="none">无背景</el-radio>
                        </el-radio-group>
                    </el-form-item>
                    <el-form-item v-if="formData.pc_home_bg_type !== 'none'" label="首页背景" prop="pc_home_bg">
                        <div>
                            <material-picker
                                v-model="formData.pc_home_bg"
                                :type="formData.pc_home_bg_type"
                                :limit="8"
                                width="180px"
                                height="108px"
                            />
                            <div class="form-tips">
                                最多选择 8 个资源，PC 首页会按顺序轮换；图片建议 1920*1080，视频建议 mp4/webm
                            </div>
                        </div>
                    </el-form-item>
                    <el-form-item v-if="formData.pc_home_bg_type === 'video'" label="视频封面" prop="pc_home_bg_poster">
                        <div>
                            <material-picker
                                v-model="formData.pc_home_bg_poster"
                                :limit="8"
                                width="180px"
                                height="108px"
                            />
                            <div class="form-tips">视频加载前或播放失败时展示的封面图，可按视频顺序选择，可不填</div>
                        </div>
                    </el-form-item>
                </template>
            </el-card>
        </el-form>
        <footer-btns v-perms="['setting.web.web_setting/setWebsite']">
            <el-button type="primary" @click="handleSubmit">保存</el-button>
        </footer-btns>
    </div>
</template>

<script lang="ts" setup name="webInformation">
import type { FormInstance } from 'element-plus'

import { getWebsite, setWebsite } from '@/api/setting/website'
import useAppStore from '@/stores/modules/app'

const formRef = ref<FormInstance>()

const appStore = useAppStore()
// 表单数据
const formData = reactive({
    name: '', // 网站名称
    web_favicon: '', // 网站图标
    web_logo: '', // 网站logo
    login_image: '', // 登录页广告图
    h5_favicon: '',
    shop_name: '',
    shop_logo: '',
    pc_logo: '',
    pc_title: '',
    pc_desc: '',
    pc_ico: '',
    pc_keywords: '',
    pc_login_bg_type: 'image',
    pc_login_bg: '',
    pc_login_bg_poster: '',
    pc_home_style: 'default',
    pc_home_immersive_title: 'OPC社区专属，AI创业平台',
    pc_home_immersive_subtitle: '一个人就是一支团队',
    pc_home_bg_type: 'none',
    pc_home_bg: [] as string[],
    pc_home_bg_poster: [] as string[]
})

const normalizeFileList = (value: string | string[] | undefined) => {
    if (Array.isArray(value)) return value.filter(Boolean)
    return value ? [value] : []
}

// 表单验证
const rules = {
    name: [
        {
            required: true,
            message: '请输入网站名称',
            trigger: ['blur']
        }
    ],
    web_favicon: [
        {
            required: true,
            message: '请选择网站图标',
            trigger: ['change']
        }
    ],
    web_logo: [
        {
            required: true,
            message: '请选择网站logo',
            trigger: ['change']
        }
    ],
    shop_name: [
        {
            required: true,
            message: '请输入店铺/商城名称',
            trigger: ['blur']
        }
    ],
    shop_logo: [
        {
            required: true,
            message: '请选择商城LOGO',
            trigger: ['change']
        }
    ],
    pc_logo: [
        {
            required: true,
            message: '请选择PC端LOGO',
            trigger: ['change']
        }
    ],
    pc_title: [
        {
            required: true,
            message: '请输入PC端网站标题',
            trigger: ['blur']
        }
    ],
    pc_ico: [
        {
            required: true,
            message: '请选择PC端网站图标',
            trigger: ['change']
        }
    ]
}

// 获取备案信息
const getData = async () => {
    const data = await getWebsite()
    for (const key in formData) {
        //@ts-ignore
        formData[key] = data[key]
    }
    formData.pc_home_bg = normalizeFileList(data.pc_home_bg || data.pc_home_bg_url)
    formData.pc_home_bg_poster = normalizeFileList(data.pc_home_bg_poster || data.pc_home_bg_poster_url)
}

// 设置备案信息
const handleSubmit = async () => {
    await formRef.value?.validate()
    if (formData.pc_home_style !== 'immersive') {
        formData.pc_home_bg_type = 'none'
        formData.pc_home_bg = []
        formData.pc_home_bg_poster = []
    } else if (formData.pc_home_bg_type === 'none') {
        formData.pc_home_bg = []
        formData.pc_home_bg_poster = []
    } else if (formData.pc_home_bg_type !== 'video') {
        formData.pc_home_bg_poster = []
    }
    await setWebsite(formData)
    appStore.getConfig()
    getData()
}

getData()
</script>

<style lang="scss" scoped></style>
