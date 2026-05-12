<template>
    <div class="canvas-home" :class="{ 'is-dark': canvasDarkMode }">
        <header class="home-topbar">
            <NuxtLink class="home-back" to="/ai/tools" title="返回 OPC">
                <el-icon><ArrowLeft /></el-icon>
            </NuxtLink>
        </header>

        <main class="home-main">
            <section class="home-hero">
                <div class="home-title">
                    <span>∞</span>
                    <h1>无限画布</h1>
                </div>
                <div class="home-input-card">
                    <textarea
                        v-model="prompt"
                        rows="5"
                        placeholder="输入创意需求，创建项目后自动进入画布"
                        @keydown.enter.exact.prevent="createFromPrompt"
                    />
                    <button type="button" title="开始创作" @click="createFromPrompt">
                        <el-icon><Promotion /></el-icon>
                    </button>
                </div>
                <div class="home-suggestions">
                    <span>推荐：</span>
                    <button v-for="item in suggestions" :key="item" type="button" @click="prompt = item">{{ item }}</button>
                    <button type="button" title="换一批" @click="rotateSuggestions">
                        <el-icon><Refresh /></el-icon>
                    </button>
                </div>
            </section>

            <section class="projects-section" v-loading="loading">
                <div class="projects-header">
                    <h2>项目管理</h2>
                    <button type="button" @click="createBlank">
                        <el-icon><Plus /></el-icon>
                        新建项目
                    </button>
                </div>
                <div v-if="!projects.length" class="empty-projects">
                    <el-icon><Folder /></el-icon>
                    <p>暂无项目，创建一个开始编排创作流程</p>
                    <button type="button" @click="createBlank">创建项目</button>
                </div>
                <div v-else class="project-grid">
                    <article v-for="project in projects" :key="project.id" class="project-card">
                        <button class="project-thumb" type="button" @click="openProject(project.id)">
                            <img v-if="project.thumbnail" :src="project.thumbnail" alt="" />
                            <div v-else class="project-placeholder">
                                <el-icon><Document /></el-icon>
                            </div>
                            <span>打开项目</span>
                        </button>
                        <div class="project-meta">
                            <button type="button" @click="openProject(project.id)">
                                <strong>{{ project.name }}</strong>
                                <small>{{ formatProjectTime(project.updatedAt) }}</small>
                            </button>
                            <div class="project-actions">
                                <button type="button" title="重命名" @click="renameProject(project.id)">
                                    <el-icon><Edit /></el-icon>
                                </button>
                                <button type="button" title="复制" @click="copyProject(project.id)">
                                    <el-icon><CopyDocument /></el-icon>
                                </button>
                                <button type="button" title="删除" @click="removeProject(project.id)">
                                    <el-icon><Delete /></el-icon>
                                </button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        </main>

    </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ArrowLeft, CopyDocument, Delete, Document, Edit, Folder, Plus, Promotion, Refresh } from '@element-plus/icons-vue'
import { canvasDarkMode, initCanvasSettings } from '@/apps/aigc_canvas/stores/settings'
import { createCanvasProject, duplicateCanvasProject, formatProjectTime, loadCanvasProjects, saveCanvasProjects } from '@/apps/aigc_canvas/stores/projects'
import type { CanvasProject } from '@/apps/aigc_canvas/types'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import { useUserStore } from '@/stores/user'
import feedback from '@/utils/feedback'

definePageMeta({ layout: 'blank', auth: true })

const router = useRouter()
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const prompt = ref('')
const projects = ref<CanvasProject[]>([])
const loading = ref(false)
const suggestionOffset = ref(0)
const allSuggestions = ['短剧角色设计', '产品电商主图', '多角度分镜', '儿童绘本故事', '雨中魔法森林', '图生视频镜头推进']
const suggestions = computed(() => allSuggestions.slice(suggestionOffset.value, suggestionOffset.value + 4).concat(allSuggestions.slice(0, Math.max(0, suggestionOffset.value + 4 - allSuggestions.length))))

function persist() {
    saveCanvasProjects(projects.value)
}

async function loadProjects() {
    if (!userStore.isLogin) {
        projects.value = []
        return
    }
    loading.value = true
    try {
        projects.value = loadCanvasProjects()
    } finally {
        loading.value = false
    }
}

async function createFromPrompt() {
    if (!ensurePcLogin()) return
    const content = prompt.value.trim()
    const project = createCanvasProject(content ? content.slice(0, 18) : '未命名项目')
    projects.value = [project, ...projects.value.filter((item) => item.id !== project.id)]
    persist()
    if (content) sessionStorage.setItem('ai-canvas-initial-prompt', content)
    router.push(`/app/aigc_canvas/project/${project.id}`)
}

async function createBlank() {
    if (!ensurePcLogin()) return
    const name = await inputProjectName('新建项目', '未命名项目')
    if (!name) return
    const project = createCanvasProject(name)
    projects.value = [project, ...projects.value.filter((item) => item.id !== project.id)]
    persist()
    router.push(`/app/aigc_canvas/project/${project.id}`)
}

function openProject(id: string) {
    router.push(`/app/aigc_canvas/project/${id}`)
}

async function renameProject(id: string) {
    if (!ensurePcLogin()) return
    const project = projects.value.find((item) => item.id === id)
    if (!project) return
    const name = await inputProjectName('重命名项目', project.name)
    if (!name) return
    project.name = name
    project.updatedAt = Date.now()
    projects.value = [project, ...projects.value.filter((item) => item.id !== id)]
    persist()
}

async function copyProject(id: string) {
    if (!ensurePcLogin()) return
    const project = projects.value.find((item) => item.id === id)
    if (!project) return
    const copy = duplicateCanvasProject(project)
    projects.value = [copy, ...projects.value]
    persist()
}

async function removeProject(id: string) {
    if (!ensurePcLogin()) return
    const project = projects.value.find((item) => item.id === id)
    if (!project) return
    try {
        await feedback.confirm(`确定删除「${project.name}」吗？`)
    } catch {
        return
    }
    projects.value = projects.value.filter((item) => item.id !== id)
    persist()
}

function rotateSuggestions() {
    suggestionOffset.value = (suggestionOffset.value + 1) % allSuggestions.length
}

async function inputProjectName(title: string, value: string) {
    try {
        const result: any = await feedback.prompt('请输入项目名称', title, {
            inputValue: value,
            inputPlaceholder: '请输入项目名称',
            inputValidator(input: string) {
                return input.trim() ? true : '项目名称不能为空'
            },
            customClass: 'aigc-canvas-message-box'
        })
        return String(result?.value || '').trim()
    } catch {
        return ''
    }
}

onMounted(() => {
    initCanvasSettings()
    loadProjects()
})

watch(() => userStore.isLogin, loadProjects)
</script>

<style scoped lang="scss">
.canvas-home {
    --page-bg: #060708;
    --panel-bg: rgba(18, 20, 23, 0.94);
    --panel-soft: rgba(25, 28, 32, 0.96);
    --panel-raised: rgba(29, 33, 38, 0.98);
    --border: rgba(255, 255, 255, 0.08);
    --border-strong: rgba(255, 255, 255, 0.14);
    --text: #f5f7fa;
    --muted: #9097a1;
    --accent: #6ee7c8;
    --accent-strong: #4fd1b4;
    --accent-soft: rgba(110, 231, 200, 0.14);
    --shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
    position: relative;
    min-height: 100vh;
    overflow-x: hidden;
    background:
        radial-gradient(circle at 18% 8%, rgba(65, 86, 109, 0.24), transparent 34%),
        radial-gradient(circle at 66% 18%, rgba(71, 124, 110, 0.16), transparent 28%),
        linear-gradient(180deg, #050607 0%, #07090b 42%, #050607 100%);
    color: var(--text);
}

.canvas-home.is-dark {
    --page-bg: #060708;
    --panel-bg: rgba(18, 20, 23, 0.94);
    --panel-soft: rgba(25, 28, 32, 0.96);
    --panel-raised: rgba(29, 33, 38, 0.98);
    --border: rgba(255, 255, 255, 0.08);
    --border-strong: rgba(255, 255, 255, 0.14);
    --text: #f5f7fa;
    --muted: #9097a1;
    --accent: #6ee7c8;
    --accent-strong: #4fd1b4;
    --accent-soft: rgba(110, 231, 200, 0.14);
    --shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
}

.canvas-home::before {
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    content: '';
    background-image:
        radial-gradient(circle, rgba(255, 255, 255, 0.12) 0 1px, transparent 1px),
        radial-gradient(circle, rgba(110, 231, 200, 0.08) 0 1px, transparent 1px);
    background-position: 0 0, 36px 22px;
    background-size: 92px 92px, 138px 138px;
    opacity: 0.22;
}

.home-topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    justify-content: space-between;
    align-items: center;
    height: 64px;
    padding: 0 28px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    background: rgba(7, 8, 10, 0.82);
    backdrop-filter: blur(12px);
}

.home-back,
.project-actions button,
.projects-header button,
.empty-projects button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    background: rgba(23, 23, 25, 0.86);
    color: var(--text);
    cursor: pointer;
    transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.home-back:hover,
.project-actions button:hover,
.projects-header button:hover,
.empty-projects button:hover {
    border-color: rgba(110, 231, 200, 0.3);
    background: rgba(31, 35, 40, 0.98);
    color: var(--text);
}

.home-back {
    width: 38px;
    height: 38px;
    border-radius: 10px;
}

.home-main {
    position: relative;
    z-index: 1;
    max-width: 1180px;
    margin: 0 auto;
    padding: 54px 24px 80px;
}

.home-hero {
    display: grid;
    gap: 22px;
    max-width: 780px;
    margin: 0 auto 64px;
}

.home-title {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
}

.home-title span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 46px;
    height: 46px;
    border-radius: 14px;
    border: 1px solid rgba(110, 231, 200, 0.22);
    background: linear-gradient(180deg, rgba(34, 38, 42, 0.94), rgba(20, 23, 27, 0.98));
    color: var(--accent);
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.03) inset, 0 10px 28px rgba(0, 0, 0, 0.26);
    font-size: 24px;
    font-weight: 800;
}

.home-title h1 {
    margin: 0;
    font-size: 36px;
    font-weight: 800;
    letter-spacing: 0;
    color: #fff;
}

.home-input-card {
    display: grid;
    grid-template-columns: 1fr 54px;
    gap: 12px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 22px;
    background: var(--panel-bg);
    box-shadow:
        0 18px 46px rgba(0, 0, 0, 0.38),
        0 0 0 1px rgba(255, 255, 255, 0.02) inset;
    backdrop-filter: blur(16px);
}

.home-input-card textarea {
    min-height: 128px;
    border: 0;
    resize: vertical;
    outline: none;
    background: transparent;
    color: var(--text);
    font-size: 16px;
    line-height: 1.7;
}

.home-input-card textarea::placeholder {
    color: rgba(245, 247, 250, 0.38);
}

.home-input-card button {
    align-self: end;
    height: 54px;
    border: 1px solid rgba(110, 231, 200, 0.26);
    border-radius: 16px;
    background:
        linear-gradient(180deg, rgba(31, 37, 42, 0.98), rgba(20, 24, 28, 0.98)),
        linear-gradient(135deg, rgba(110, 231, 200, 0.14), rgba(79, 209, 180, 0.08));
    color: var(--accent);
    cursor: pointer;
    box-shadow:
        0 10px 28px rgba(0, 0, 0, 0.28),
        0 0 0 1px rgba(255, 255, 255, 0.03) inset;
    transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.home-input-card button:hover {
    transform: translateY(-1px);
    border-color: rgba(110, 231, 200, 0.42);
    background:
        linear-gradient(180deg, rgba(36, 42, 47, 0.98), rgba(23, 28, 32, 0.98)),
        linear-gradient(135deg, rgba(110, 231, 200, 0.18), rgba(79, 209, 180, 0.12));
    box-shadow:
        0 14px 32px rgba(0, 0, 0, 0.34),
        0 0 20px rgba(110, 231, 200, 0.08);
}

.home-input-card button :deep(svg) {
    font-size: 18px;
}

.home-suggestions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    color: var(--muted);
}

.home-suggestions button {
    height: 34px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.035);
    color: #d6dbe1;
    cursor: pointer;
    transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.home-suggestions button:hover {
    transform: translateY(-1px);
    border-color: rgba(110, 231, 200, 0.22);
    background: rgba(110, 231, 200, 0.08);
    color: #eefbf6;
}

.projects-section {
    display: grid;
    gap: 18px;
}

.projects-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.projects-header h2 {
    margin: 0;
    font-size: 22px;
    font-weight: 800;
    color: #fff;
}

.projects-header button,
.empty-projects button {
    gap: 6px;
    height: 38px;
    padding: 0 14px;
    border-radius: 10px;
}

.project-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 18px;
}

.project-card {
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    background: var(--panel-bg);
    box-shadow:
        0 16px 42px rgba(0, 0, 0, 0.34),
        0 0 0 1px rgba(255, 255, 255, 0.02) inset;
    backdrop-filter: blur(14px);
    transition: border-color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
}

.project-card:hover {
    border-color: rgba(110, 231, 200, 0.16);
    box-shadow: 0 24px 56px rgba(0, 0, 0, 0.42);
    transform: translateY(-2px);
}

.project-thumb {
    position: relative;
    display: block;
    width: 100%;
    aspect-ratio: 1.35 / 1;
    border: 0;
    background:
        radial-gradient(circle at 30% 20%, rgba(86, 108, 130, 0.2), transparent 30%),
        linear-gradient(180deg, #16181b 0%, #101214 100%);
    cursor: pointer;
    overflow: hidden;
}

.project-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.project-thumb span {
    position: absolute;
    right: 12px;
    bottom: 12px;
    padding: 5px 9px;
    border-radius: 999px;
    background: rgba(8, 10, 12, 0.72);
    color: #fff;
    font-size: 12px;
    backdrop-filter: blur(8px);
}

.project-placeholder,
.empty-projects {
    display: grid;
    place-items: center;
    color: var(--muted);
}

.project-placeholder {
    height: 100%;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    font-size: 36px;
}

.project-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px;
}

.project-meta > button {
    min-width: 0;
    border: 0;
    background: transparent;
    color: var(--text);
    text-align: left;
    cursor: pointer;
}

.project-meta strong,
.project-meta small {
    display: block;
}

.project-meta strong {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.project-meta small {
    margin-top: 4px;
    color: var(--muted);
    font-size: 12px;
}

.project-actions {
    display: flex;
    gap: 6px;
}

.project-actions button {
    width: 30px;
    height: 30px;
    border-radius: 8px;
}

.empty-projects {
    min-height: 260px;
    gap: 12px;
    border: 1px dashed var(--border);
    border-radius: 16px;
    background: rgba(18, 20, 23, 0.76);
    backdrop-filter: blur(14px);
}

</style>

<style lang="scss">
.aigc-canvas-message-box {
    --el-bg-color: #171719;
    --el-bg-color-overlay: #171719;
    --el-text-color-primary: #f4f4f5;
    --el-text-color-regular: #d4d4d8;
    --el-border-color-light: #313233;
    --el-fill-color-blank: #111113;
    width: min(420px, calc(100vw - 40px));
    border: 1px solid #313233;
    border-radius: 14px;
    background: #171719;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.48);
}

.aigc-canvas-message-box .el-message-box__title,
.aigc-canvas-message-box .el-message-box__message {
    color: #f4f4f5;
}

.aigc-canvas-message-box .el-input__wrapper {
    background: #111113;
    box-shadow: 0 0 0 1px #313233 inset;
}

.aigc-canvas-message-box .el-input__inner {
    color: #f4f4f5;
}
</style>
