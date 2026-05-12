<template>
    <div class="avatar-page">
        <div class="avatar-page__background" :style="backgroundStyle"></div>
        <div class="avatar-page__noise"></div>
        <div class="avatar-page__stars avatar-page__stars--near"></div>
        <div class="avatar-page__stars avatar-page__stars--far"></div>

        <AiWorkspaceChrome
            :active-sidebar="activeSidebar"
            :remaining-credits="remainingCredits"
            :membership-enabled="membershipEnabled"
            :active-popover="activePopover"
            :popover-content="chromePopoverContent"
            @toggle-popover="togglePopover"
            @increment-credits="refreshCredits"
            @toggle-membership="refreshCredits"
            @go-home="goHome"
            @navigate="handleSidebar"
        />

        <main
            ref="avatarMainRef"
            class="avatar-main"
            :class="{
                'avatar-main--tools': activeSidebar === 'tools',
                'avatar-main--assets': activeSidebar === 'assets'
            }"
        >
            <section v-if="activeSidebar === 'tools'" class="tools-shell">
                <section class="tools-section">
                    <div class="tools-section__heading">
                        <h2>{{ texts.featuredTools }}</h2>
                    </div>

                    <div class="tools-featured-grid">
                        <article
                            v-for="item in filteredFeaturedTools"
                            :key="item.id"
                            :class="['tools-featured-card', { 'is-active': selectedFeaturedToolId === item.id }]"
                            :style="{ '--tools-accent': item.accent }"
                            @click="selectFeaturedTool(item)"
                        >
                            <div class="tools-featured-card__copy">
                                <h3>{{ item.title }}</h3>
                                <p>{{ item.description }}</p>
                            </div>
                            <div class="tools-featured-card__visual">
                                <img :src="item.image" :alt="item.title" />
                            </div>
                        </article>
                    </div>
                </section>

                <section class="tools-section tools-section--all">
                    <div class="tools-section__heading tools-section__heading--row">
                        <div class="tools-section__heading-main">
                            <h2>{{ texts.allTools }}</h2>
                            <div class="tools-categories">
                                <button
                                    v-for="item in toolCategoryOptions"
                                    :key="item"
                                    :class="{ 'is-active': activeToolCategory === item }"
                                    type="button"
                                    @click="setActiveToolCategory(item)"
                                >
                                    {{ item }}
                                </button>
                            </div>
                        </div>

                        <div class="tools-search tools-search--inline">
                            <span class="tools-search__icon" aria-hidden="true"></span>
                            <input
                                v-model="toolKeyword"
                                type="text"
                                :placeholder="texts.toolSearchPlaceholder"
                            />
                        </div>
                    </div>

                    <div class="tools-grid">
                        <article
                            v-for="item in filteredToolCards"
                            :key="item.id"
                            :class="['tools-card', { 'is-active': selectedToolCardId === item.id }]"
                            @click="selectToolCard(item)"
                        >
                            <img class="tools-card__image" :src="item.image" :alt="item.title" />
                            <div class="tools-card__overlay"></div>
                            <div class="tools-card__content">
                                <h3>{{ item.title }}</h3>
                                <span class="tools-card__tag">{{ item.badge }}</span>
                            </div>
                        </article>
                    </div>

                    <div v-if="!filteredToolCards.length" class="tools-empty">
                        <strong>{{ texts.noToolResult }}</strong>
                        <span>{{ texts.noToolResultHint }}</span>
                    </div>
                </section>
            </section>

            <AiWorkspaceAssetsPanel v-else-if="activeSidebar === 'assets'" />

            <section v-else class="avatar-layout">
                <div class="avatar-editor">
                    <section class="editor-section editor-section--preview">
                        <div class="editor-section__head">
                            <span class="editor-section__label">{{ texts.uploadAvatar }}</span>
                            <button class="mini-action" type="button" @click="openAvatarLibrary('mine')">
                                <img :src="avatarLibraryIcon" alt="" />
                                <span>{{ texts.avatarLibrary }}</span>
                            </button>
                        </div>
                        <div class="editor-stage" :class="{ 'editor-stage--avatar-filled': !!appliedAvatarItem }">
                            <template v-if="appliedAvatarItem">
                                <div class="avatar-editor-card">
                                    <div class="avatar-editor-card__preview">
                                        <img :src="appliedAvatarItem.image" :alt="appliedAvatarItem.name" />
                                    </div>
                                    <div class="avatar-editor-card__actions">
                                        <button type="button" :aria-label="texts.historyAvatar" @click="openAvatarLibrary('official')">
                                            <img :src="voiceHistoryIcon" alt="" />
                                        </button>
                                        <button type="button" :aria-label="texts.uploadAvatar" @click="triggerUpload">
                                            <img :src="voiceUploadIcon" alt="" />
                                        </button>
                                        <button type="button" :aria-label="texts.clear" @click="clearAppliedAvatar">
                                            <img :src="voiceDeleteIcon" alt="" />
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <button class="editor-stage__upload" type="button" @click="triggerUpload">
                                    <img :src="addIcon" alt="" />
                                    <span>{{ texts.uploadAvatar }}</span>
                                </button>
                                <button class="editor-stage__history" type="button" @click="togglePopover('notice')">
                                    <img :src="historyIcon" alt="" />
                                    <span>{{ texts.historyAvatar }}</span>
                                </button>
                            </template>
                        </div>
                    </section>

                    <section class="editor-section editor-section--voice">
                        <div class="editor-section__head">
                            <span class="editor-section__label">{{ texts.selectVoice }}</span>
                            <button class="mini-action" type="button" @click="openVoiceLibrary('mine')">
                                <img :src="voiceLibraryIcon" alt="" />
                                <span>{{ texts.voiceLibrary }}</span>
                            </button>
                        </div>
                        <div class="editor-stage" :class="{ 'editor-stage--voice-filled': !!appliedVoiceItem || !!driverAudio }">
                            <template v-if="appliedVoiceItem">
                                <div class="voice-editor-card">
                                    <div class="voice-editor-card__body">
                                        <button
                                            class="voice-editor-card__cover"
                                            type="button"
                                            :aria-label="playingVoiceId === appliedVoiceItem.id ? texts.pauseVoice : texts.playVoice"
                                            @click="toggleVoicePreview(appliedVoiceItem)"
                                        >
                                            <img
                                                v-if="appliedVoiceItem.cover"
                                                class="voice-editor-card__image"
                                                :src="appliedVoiceItem.cover"
                                                :alt="appliedVoiceItem.name"
                                            />
                                            <span v-else class="voice-editor-card__disc"></span>
                                            <span class="voice-editor-card__play">
                                                <span
                                                    v-if="voicePreviewLoadingId === appliedVoiceItem.id"
                                                    class="voice-card__loading-icon"
                                                ></span>
                                                <span
                                                    v-else-if="playingVoiceId === appliedVoiceItem.id"
                                                    class="voice-card__pause-icon"
                                                ></span>
                                                <span v-else class="voice-card__play-icon"></span>
                                            </span>
                                        </button>
                                        <div class="voice-editor-card__name" :title="appliedVoiceItem.fileName || appliedVoiceItem.name">
                                            {{ getAppliedVoiceDisplayName(appliedVoiceItem) }}
                                        </div>
                                        <audio
                                            :data-voice-audio="appliedVoiceItem.id"
                                            :src="appliedVoiceItem.previewUrl || undefined"
                                            preload="metadata"
                                            class="voice-preview-audio"
                                        ></audio>
                                    </div>
                                    <div class="voice-editor-card__actions">
                                        <button type="button" :aria-label="texts.historyVoice" @click="openVoiceLibrary('mine')">
                                            <img :src="voiceHistoryIcon" alt="" />
                                        </button>
                                        <button type="button" :aria-label="texts.uploadVoice" @click="triggerDriverAudioUpload">
                                            <img :src="voiceUploadIcon" alt="" />
                                        </button>
                                        <button type="button" :aria-label="texts.clear" @click="clearAppliedVoice">
                                            <img :src="voiceDeleteIcon" alt="" />
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <template v-else-if="driverAudio">
                                <div class="voice-editor-card">
                                    <div class="voice-editor-card__body">
                                        <button class="voice-editor-card__cover" type="button" :aria-label="texts.audioDriver">
                                            <span class="voice-editor-card__disc"></span>
                                            <span class="voice-editor-card__play">{{ formatVoiceCreateDuration(driverAudio.duration || 0) }}</span>
                                        </button>
                                        <div class="voice-editor-card__name" :title="driverAudio.fileName">
                                            {{ driverAudio.fileName }}
                                        </div>
                                    </div>
                                    <div class="voice-editor-card__actions">
                                        <button type="button" :aria-label="texts.uploadVoice" @click="triggerDriverAudioUpload">
                                            <img :src="voiceUploadIcon" alt="" />
                                        </button>
                                        <button type="button" :aria-label="texts.clear" @click="clearDriverAudio">
                                            <img :src="voiceDeleteIcon" alt="" />
                                        </button>
                                    </div>
                                </div>
                            </template>
                            <template v-else>
                                <button class="editor-stage__upload" type="button" @click="triggerDriverAudioUpload">
                                    <img :src="addIcon" alt="" />
                                    <span>{{ texts.uploadVoice }}</span>
                                </button>
                                <button class="editor-stage__history" type="button" @click="openVoiceLibrary('mine')">
                                    <img :src="historyIcon" alt="" />
                                    <span>{{ texts.historyVoice }}</span>
                                </button>
                            </template>
                        </div>
                    </section>

                    <section v-if="!driverAudio" class="editor-section editor-section--script">
                        <div class="editor-section__head editor-section__head--stacked">
                            <span class="editor-section__label">{{ texts.scriptTitle }}</span>
                        </div>
                        <div class="script-box">
                            <textarea
                                v-model="scriptText"
                                :maxlength="scriptMaxLength || undefined"
                                :placeholder="texts.scriptPlaceholder"
                            ></textarea>
                            <div class="script-box__tools">
                                <span class="script-box__translate-wrap" @click.stop>
                                    <button type="button" :disabled="!!scriptAssistLoading" @click="toggleTranslateMenu">
                                        <img :src="translateIcon" alt="" />
                                        <span>{{ scriptAssistLoading === 'translate' ? texts.processing : texts.translate }}</span>
                                    </button>
                                    <div v-if="translateMenuOpen" class="script-box__language-menu" @click.stop>
                                        <button
                                            v-for="item in translateLanguageOptions"
                                            :key="item.value"
                                            type="button"
                                            @click="runScriptAssist('translate', item.value)"
                                        >
                                            {{ item.label }}
                                        </button>
                                    </div>
                                </span>
                                <button type="button" :disabled="!!scriptAssistLoading" @click="runScriptAssist('copywrite')">
                                    <img :src="magicIcon" alt="" />
                                    <span>{{ scriptAssistLoading === 'copywrite' ? texts.processing : texts.aiCopy }}</span>
                                </button>
                            </div>
                            <button class="script-box__clear" type="button" :aria-label="texts.clear" @click="scriptText = ''">
                                <img :src="clearIcon" alt="" />
                            </button>
                            <div v-if="scriptMaxLength" class="script-box__count">{{ scriptText.length }}/{{ scriptMaxLength }}</div>
                        </div>
                        <div class="script-box__chips">
                            <button v-for="chip in promptChips" :key="chip" type="button" @click="appendPrompt(chip)">
                                {{ chip }}
                            </button>
                        </div>
                    </section>

                    <section class="editor-section editor-section--name">
                        <div class="editor-section__row">
                            <span class="editor-section__label">{{ texts.workTitle }}</span>
                            <div class="work-name">
                                <input v-model="workName" type="text" maxlength="32" :placeholder="texts.workPlaceholder" />
                            </div>
                        </div>
                    </section>

                    <button class="create-button" type="button" :disabled="isCreating" @click="submitAvatarCreate">
                        <span class="create-button__meta">
                            <img :src="createNowIcon" alt="" />
                            <span>{{ texts.createRate }}</span>
                        </span>
                        <span class="create-button__text">{{ isCreating ? texts.creating : texts.createNow }}</span>
                    </button>
                </div>

                <div class="avatar-content">
                    <section v-if="activeContentPanel === 'avatar'" class="avatar-heading">
                        <button
                            v-for="tab in libraryTabs"
                            :key="tab.key"
                            :class="['avatar-heading__tab', { 'is-active': activeLibraryTab === tab.key }]"
                            type="button"
                            @click="openAvatarLibrary(tab.key)"
                        >
                            {{ tab.label }}
                        </button>
                        <span class="avatar-heading__line" :style="headingLineStyle"></span>
                    </section>

                    <section v-else class="avatar-heading">
                        <button
                            v-for="tab in voiceTabs"
                            :key="tab.key"
                            :class="['avatar-heading__tab', { 'is-active': activeVoiceTab === tab.key }]"
                            type="button"
                            @click="openVoiceLibrary(tab.key)"
                        >
                            {{ tab.label }}
                        </button>
                        <span class="avatar-heading__line" :style="voiceHeadingLineStyle"></span>
                    </section>

                    <div v-if="activeContentPanel === 'voice'" class="voice-filters tools-categories">
                        <button
                            v-for="item in voiceLibraryCategoryOptions"
                            :key="item"
                            :class="{ 'is-active': activeVoiceCategory === item }"
                            type="button"
                            @click="activeVoiceCategory = item"
                        >
                            {{ item }}
                        </button>
                    </div>

                    <template v-if="activeContentPanel === 'avatar'">
                        <div class="avatar-gallery-scroll">
                            <div class="avatar-gallery">
                                <article
                                    v-if="showMineCreateCard"
                                    class="avatar-card avatar-card--create"
                                    @click="triggerCreateAvatarUpload"
                                >
                                    <div class="avatar-card--create__inner">
                                        <img :src="addIcon" alt="" />
                                        <span>{{ texts.createMineAvatar }}</span>
                                    </div>
                                </article>

                                <article
                                    v-for="item in pagedDisplayedAvatars"
                                    :key="item.id"
                                    :class="[
                                        'avatar-card',
                                        {
                                            'is-selected': selectedAvatar?.id === item.id,
                                            'is-previewing': previewingAvatarId === item.id
                                        }
                                    ]"
                                    @mouseenter="previewingAvatarId = item.id"
                                    @mouseleave="stopAvatarMotion(item.id)"
                                    @click="applyAvatarToEditor(item)"
                                >
                                    <button
                                        v-if="item.source === 'mine'"
                                        class="avatar-card__more"
                                        type="button"
                                        :aria-label="texts.avatarMenu"
                                        @click.stop="toggleAvatarCardMenu(item.id)"
                                    >
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </button>
                                    <div
                                        v-if="item.source === 'mine' && avatarCardMenuId === item.id"
                                        class="avatar-card__menu"
                                        @click.stop
                                    >
                                        <button type="button" @click="openAvatarEditModal(item)">
                                            {{ texts.editAvatar }}
                                        </button>
                                        <button type="button" @click="requestAvatarDelete(item)">
                                            {{ texts.deleteAvatar }}
                                        </button>
                                    </div>
                                    <img class="avatar-card__image" :src="item.image" :alt="getAvatarDisplayName(item)" />
                                    <span v-if="item.vip" class="avatar-card__vip">
                                        <img :src="vipBadgeIcon" alt="" />
                                        VIP
                                    </span>
                                    <div class="avatar-card__shade"></div>
                                    <span v-if="item.source !== 'mine'" class="avatar-card__motion-badge">
                                        <span class="avatar-card__motion-icon"></span>
                                    </span>
                                    <div class="avatar-card__meta">
                                        <span class="avatar-tag avatar-tag--light">
                                            <img :src="peopleIcon" alt="" />
                                            <span>{{ getAvatarDisplayName(item) }}</span>
                                        </span>
                                        <span class="avatar-tag avatar-tag--dark">
                                            <img :src="typeIcon" alt="" />
                                            <span>{{ item.topic }}</span>
                                        </span>
                                    </div>
                                </article>

                                <article
                                    v-if="!showMineCreateCard && !displayedAvatars.length"
                                    class="avatar-card avatar-card--empty"
                                    @click="activeLibraryTab === 'mine' && triggerCreateAvatarUpload()"
                                >
                                    <div class="avatar-card--empty__inner">
                                        <img v-if="activeLibraryTab === 'mine'" :src="addIcon" alt="" />
                                        <strong>{{ activeLibraryTab === 'mine' ? texts.uploadMineTitle : texts.noOfficialAvatar }}</strong>
                                        <span v-if="activeLibraryTab === 'mine'">{{ texts.uploadMineHint }}</span>
                                    </div>
                                </article>
                            </div>
                        </div>
                        <div v-if="displayedAvatars.length > avatarPageSize" class="library-pagination">
                            <ElPagination
                                v-model:current-page="avatarPage"
                                :total="displayedAvatars.length"
                                :page-size="avatarPageSize"
                                hide-on-single-page
                                layout="total, prev, pager, next, jumper"
                            />
                        </div>
                    </template>

                    <div v-else class="voice-library-panel">
                        <div class="voice-library-scroll">
                            <div class="voice-library">
                                <article
                                    v-if="showMineVoiceCreateCard"
                                    class="voice-card voice-card--create"
                                    @click="openVoiceCreateModal"
                                >
                                    <div class="voice-card--create__inner">
                                        <img :src="addIcon" alt="" />
                                        <span>{{ texts.createMineVoice }}</span>
                                    </div>
                                </article>

                                <article
                                    v-for="item in pagedDisplayedVoices"
                                    :key="item.id"
                                    :class="['voice-card', { 'is-selected': selectedVoiceCardId === item.id }]"
                                    @click="selectVoiceItem(item)"
                                >
                                    <span v-if="item.vip" class="voice-card__vip">
                                        <img :src="vipBadgeIcon" alt="" />
                                        VIP
                                    </span>
                                    <button
                                        class="voice-card__favorite"
                                        type="button"
                                        :class="{ 'is-active': item.starred }"
                                        @click.stop="toggleVoiceStar(item)"
                                    >
                                        {{ getVoiceFavoriteSymbol(item.starred) }}
                                    </button>
                                    <button class="voice-card__action" type="button" @click.stop="applyVoiceToEditor(item)">
                                        {{ texts.useVoice }}
                                    </button>
                                    <div class="voice-card__meta">
                                        <div
                                            class="voice-card__thumb"
                                            :class="{ 'is-empty': !item.cover, 'is-playing': playingVoiceId === item.id }"
                                        >
                                            <img v-if="item.cover" :src="item.cover" :alt="item.name" />
                                            <img v-else class="voice-card__thumb-icon" :src="voiceLibraryIcon" alt="" />
                                            <button
                                                class="voice-card__play"
                                                :class="{ 'is-active': playingVoiceId === item.id, 'is-loading': voicePreviewLoadingId === item.id }"
                                                type="button"
                                                :aria-label="playingVoiceId === item.id ? texts.pauseVoice : texts.playVoice"
                                                @click.stop="toggleVoicePreview(item)"
                                            >
                                                <span v-if="voicePreviewLoadingId === item.id" class="voice-card__loading-icon"></span>
                                                <span v-else-if="playingVoiceId === item.id" class="voice-card__pause-icon"></span>
                                                <span v-else class="voice-card__play-icon"></span>
                                            </button>
                                        </div>
                                        <span class="voice-card__name">{{ item.name }}</span>
                                        <audio
                                            :data-voice-audio="item.id"
                                            :src="item.previewUrl || undefined"
                                            preload="metadata"
                                            class="voice-preview-audio"
                                        ></audio>
                                    </div>
                                </article>

                                <article
                                    v-if="!showMineVoiceCreateCard && !displayedVoices.length"
                                    class="voice-card voice-card--empty"
                                    @click="activeVoiceTab === 'mine' && openVoiceCreateModal()"
                                >
                                    <div class="voice-card--create__inner">
                                        <img v-if="activeVoiceTab === 'mine'" :src="addIcon" alt="" />
                                        <span>{{ activeVoiceTab === 'mine' ? texts.createMineVoice : texts.noOfficialVoice }}</span>
                                    </div>
                                </article>
                            </div>
                        </div>
                        <div v-if="displayedVoices.length > voicePageSize" class="library-pagination library-pagination--voice">
                            <ElPagination
                                v-model:current-page="voicePage"
                                :total="displayedVoices.length"
                                :page-size="voicePageSize"
                                hide-on-single-page
                                layout="total, prev, pager, next, jumper"
                            />
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <div
            v-if="showAvatarCreateModal"
            class="avatar-create-modal-mask"
            @click.self="closeAvatarCreateModal()"
        >
            <section class="avatar-create-modal" aria-modal="true" role="dialog" @click="avatarSceneMenuOpen = false">
                <button
                    class="avatar-create-modal__close"
                    type="button"
                    :aria-label="texts.closeModal"
                    @click="closeAvatarCreateModal()"
                ></button>

                <h3 class="avatar-create-modal__title">{{ avatarCreateModalTitle }}</h3>

                <div class="avatar-create-modal__hero">
                    <div class="avatar-create-modal__preview">
                        <template v-if="pendingAvatarUpload">
                            <button
                                class="avatar-create-modal__switch"
                                type="button"
                                :aria-label="texts.replaceAsset"
                                @click.stop="triggerCreateAvatarUpload"
                            >
                                <img :src="switchUploadIcon" alt="" />
                            </button>
                            <img
                                v-if="pendingAvatarUpload.mediaType === 'image'"
                                class="avatar-create-modal__media"
                                :src="pendingAvatarUpload.url"
                                :alt="avatarCreateName || pendingAvatarUpload.fileName"
                            />
                            <video
                                v-else
                                ref="avatarModalVideoRef"
                                class="avatar-create-modal__media avatar-create-modal__media--video"
                                :src="pendingAvatarUpload.url"
                                :poster="avatarCreateCover || pendingAvatarUpload.previewImage || undefined"
                                controls
                                playsinline
                                preload="metadata"
                                @play="isAvatarModalVideoPlaying = true"
                                @pause="isAvatarModalVideoPlaying = false"
                                @ended="isAvatarModalVideoPlaying = false"
                            ></video>
                            <button
                                v-if="pendingAvatarUpload.mediaType === 'video'"
                                class="avatar-create-modal__play"
                                :class="{ 'is-hidden': isAvatarModalVideoPlaying }"
                                type="button"
                                :aria-label="isAvatarModalVideoPlaying ? texts.pauseVoice : texts.playVoice"
                                @click="toggleAvatarModalPlayback"
                            >
                                <span
                                    v-if="isAvatarModalVideoPlaying"
                                    class="avatar-create-modal__pause-icon"
                                ></span>
                                <span v-else class="avatar-create-modal__play-icon"></span>
                            </button>
                        </template>
                    </div>

                    <div class="avatar-create-modal__requirements">
                        <strong>{{ texts.videoRequirements }}</strong>
                        <p v-for="item in avatarCreateRequirements" :key="item">{{ item }}</p>
                    </div>
                </div>

                <div class="avatar-create-modal__form">
                    <div class="avatar-create-modal__fields">
                        <label class="avatar-create-modal__field">
                            <span class="avatar-create-modal__label">{{ texts.avatarName }}</span>
                            <span class="avatar-create-modal__input-shell">
                                <input
                                    v-model="avatarCreateName"
                                    type="text"
                                    :placeholder="texts.avatarNamePlaceholder"
                                />
                            </span>
                        </label>

                        <label class="avatar-create-modal__field">
                            <span class="avatar-create-modal__label">{{ texts.avatarScene }}</span>
                            <span class="avatar-create-modal__select-wrap" @click.stop>
                                <button
                                    class="avatar-create-modal__input-shell avatar-create-modal__input-shell--select"
                                    type="button"
                                    :aria-expanded="avatarSceneMenuOpen"
                                    @click="toggleAvatarSceneMenu"
                                >
                                    <span>{{ avatarCreateScene }}</span>
                                    <img :src="downIcon" alt="" />
                                </button>
                                <div v-if="avatarSceneMenuOpen" class="avatar-create-modal__menu">
                                    <button
                                        v-for="item in avatarSceneOptions"
                                        :key="item"
                                        type="button"
                                        :class="{ 'is-active': avatarCreateScene === item }"
                                        @click="setAvatarCreateScene(item)"
                                    >
                                        {{ item }}
                                    </button>
                                </div>
                            </span>
                        </label>
                    </div>

                    <label class="avatar-create-modal__cover-field">
                        <span class="avatar-create-modal__label">{{ texts.avatarCover }}</span>
                        <button class="avatar-create-modal__cover" type="button" @click="triggerAvatarCoverUpload">
                            <img v-if="avatarCreateCover" :src="avatarCreateCover" :alt="texts.avatarCover" />
                            <span v-else class="avatar-create-modal__cover-add">+</span>
                        </button>
                    </label>
                </div>

                <div class="avatar-create-modal__footer">
                    <button class="avatar-create-modal__submit" type="button" :disabled="!pendingAvatarUpload || isCreating" @click="saveAvatarCreateModal">
                        <span class="avatar-create-modal__submit-meta">
                            <img :src="createNowIcon" alt="" />
                            <span>50</span>
                        </span>
                        <span>{{ isCreating ? '保存中...' : texts.saveAvatar }}</span>
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="showAvatarDeleteModal"
            class="avatar-delete-modal-mask"
            @click.self="closeAvatarDeleteModal"
        >
            <section class="avatar-delete-modal" aria-modal="true" role="dialog">
                <button
                    class="avatar-delete-modal__close"
                    type="button"
                    :aria-label="texts.closeModal"
                    @click="closeAvatarDeleteModal"
                ></button>
                <div class="avatar-delete-modal__title">
                    <span class="avatar-delete-modal__icon">!</span>
                    <strong>{{ texts.deleteAvatarTitle }}</strong>
                </div>
                <p class="avatar-delete-modal__desc">{{ texts.deleteAvatarMessage }}</p>
                <div class="avatar-delete-modal__actions">
                    <button type="button" @click="closeAvatarDeleteModal">{{ texts.cancel }}</button>
                    <button class="is-danger" type="button" @click="confirmAvatarDelete">{{ texts.confirm }}</button>
                </div>
            </section>
        </div>

        <div
            v-if="showVoiceCreateModal"
            class="voice-create-modal-mask"
            @click.self="closeVoiceCreateModal()"
        >
            <section class="voice-create-modal" aria-modal="true" role="dialog" @click="closeVoiceCreateMenus">
                <button
                    class="voice-create-modal__close"
                    type="button"
                    :aria-label="texts.closeModal"
                    @click="closeVoiceCreateModal()"
                ></button>

                <h3 class="voice-create-modal__title">{{ texts.createVoiceTone }}</h3>

                <div class="voice-create-modal__cover-hero">
                    <span class="voice-create-modal__label">{{ texts.voiceCover }}</span>
                    <button class="voice-create-modal__cover" type="button" @click="triggerVoiceCoverUpload">
                        <img v-if="voiceCreateCover" :src="voiceCreateCover" :alt="texts.voiceCover" />
                        <span v-else class="voice-create-modal__cover-add">+</span>
                    </button>
                </div>

                <div class="voice-create-modal__method-grid">
                    <button
                        class="voice-create-modal__method-card"
                        :class="{
                            'is-recording': isVoiceCreateRecording
                        }"
                        type="button"
                        :disabled="isVoiceCreateBusy"
                        @click="handleVoiceCreateRecordAction"
                    >
                        <span class="voice-create-modal__method-eyebrow">01</span>
                        <strong>{{ voiceCreateRecordActionText }}</strong>
                        <span>
                            {{ voiceCreateRecordTip }}
                        </span>
                    </button>
                </div>

                <div class="voice-create-modal__tips">
                    <span class="voice-create-modal__tips-icon"></span>
                    <span>{{ texts.sampleGuide }}</span>
                </div>

                <div
                    class="voice-create-modal__sample-card"
                    :class="{
                        'is-recording': isVoiceCreateRecording,
                        'is-ready': isVoiceCreateSampleReady
                    }"
                >
                    <div class="voice-create-modal__sample-head">
                        <div class="voice-create-modal__sample-title-wrap">
                            <strong>{{ texts.currentSample }}</strong>
                            <span class="voice-create-modal__sample-status">{{ voiceCreateStatusText }}</span>
                        </div>
                        <button
                            v-if="voiceCreateSampleSourceText && isVoiceCreateSampleReady"
                            class="voice-create-modal__sample-badge voice-create-modal__sample-preview"
                            :class="{ 'is-playing': isVoiceCreatePreviewPlaying }"
                            type="button"
                            :disabled="!canPreviewVoiceCreate || isVoiceCreateBusy"
                            @click.stop="toggleVoiceCreatePreview"
                        >
                            <span>{{ voiceCreateSampleSourceText }}</span>
                            <span>{{ isVoiceCreatePreviewPlaying ? texts.pauseVoice : texts.previewVoice }}</span>
                        </button>
                        <span v-else-if="voiceCreateSampleSourceText" class="voice-create-modal__sample-badge">
                            {{ voiceCreateSampleSourceText }}
                        </span>
                    </div>

                    <div class="voice-create-modal__sample-main">
                        <div class="voice-create-modal__sample-name">
                            {{ voiceCreateSampleName || texts.sampleEmpty }}
                        </div>
                        <div class="voice-create-modal__sample-meta">
                            <span v-if="voiceCreateSampleDurationText">{{ voiceCreateSampleDurationText }}</span>
                            <span v-if="isVoiceCreateSampleReady">{{ texts.sampleReady }}</span>
                        </div>
                        <audio
                            ref="voiceCreatePreviewRef"
                            :src="voiceCreateRecordedUrl || pendingVoiceUpload?.url || undefined"
                            preload="metadata"
                            class="voice-create-modal__sample-audio"
                        ></audio>
                    </div>

                    <div class="voice-create-modal__script-card">
                        <div class="voice-create-modal__script">
                            {{ voiceCreateScript }}
                        </div>
                    </div>
                </div>

                <div class="voice-create-modal__form">
                    <label class="voice-create-modal__field voice-create-modal__field--name">
                        <span class="voice-create-modal__label">{{ texts.voiceName }}</span>
                        <span class="voice-create-modal__input-shell voice-create-modal__input-shell--name">
                            <input
                                v-model="voiceCreateName"
                                type="text"
                                :maxlength="voiceCreateNameMaxLength"
                                :placeholder="texts.voiceNamePlaceholder"
                            />
                        </span>
                    </label>

                    <label class="voice-create-modal__field voice-create-modal__field--settings">
                        <span class="voice-create-modal__label">{{ texts.voiceSettings }}</span>
                        <span class="voice-create-modal__setting-row" @click.stop>
                            <span class="voice-create-modal__select-wrap">
                                <button
                                    class="voice-create-modal__input-shell voice-create-modal__input-shell--select voice-create-modal__input-shell--short"
                                    type="button"
                                    :aria-expanded="voiceCreateGenderMenuOpen"
                                    @click="toggleVoiceCreateGenderMenu"
                                >
                                    <span>{{ voiceCreateGender }}</span>
                                    <img :src="downIcon" alt="" />
                                </button>
                                <div v-if="voiceCreateGenderMenuOpen" class="voice-create-modal__menu">
                                    <button
                                        v-for="item in voiceGenderOptions"
                                        :key="item"
                                        type="button"
                                        :class="{ 'is-active': voiceCreateGender === item }"
                                        @click="setVoiceCreateGender(item)"
                                    >
                                        {{ item }}
                                    </button>
                                </div>
                            </span>

                            <span class="voice-create-modal__select-wrap">
                                <button
                                    class="voice-create-modal__input-shell voice-create-modal__input-shell--select voice-create-modal__input-shell--short"
                                    type="button"
                                    :aria-expanded="voiceCreateAgeMenuOpen"
                                    @click="toggleVoiceCreateAgeMenu"
                                >
                                    <span>{{ voiceCreateAge }}</span>
                                    <img :src="downIcon" alt="" />
                                </button>
                                <div v-if="voiceCreateAgeMenuOpen" class="voice-create-modal__menu">
                                    <button
                                        v-for="item in voiceAgeOptions"
                                        :key="item"
                                        type="button"
                                        :class="{ 'is-active': voiceCreateAge === item }"
                                        @click="setVoiceCreateAge(item)"
                                    >
                                        {{ item }}
                                    </button>
                                </div>
                            </span>
                        </span>
                    </label>
                </div>

                <div class="voice-create-modal__footer">
                    <button
                        class="voice-create-modal__submit"
                        type="button"
                        :disabled="!canSaveVoiceCreate || isVoiceCreateBusy"
                        @click="saveVoiceCreateModal"
                    >
                        <span class="voice-create-modal__submit-meta">
                            <img :src="createNowIcon" alt="" />
                            <span>{{ voiceCreateCost }}</span>
                        </span>
                        <span>{{ isVoiceCreateSaving ? texts.savingVoice : texts.saveVoice }}</span>
                    </button>
                </div>
            </section>
        </div>

        <div
            v-if="voiceTrimState.visible"
            class="voice-trim-modal-mask"
            @click.self="cancelVoiceTrim"
        >
            <section class="voice-trim-modal" aria-modal="true" role="dialog">
                <button
                    class="voice-trim-modal__close"
                    type="button"
                    :aria-label="texts.closeModal"
                    @click="cancelVoiceTrim"
                ></button>
                <div class="voice-trim-modal__head">
                    <span>音频裁剪</span>
                    <strong>选择 10 秒克隆片段</strong>
                    <p>当前音频超过 10 秒，请左右拖动选择要保留的固定 10 秒范围。</p>
                </div>

                <div class="voice-trim-modal__file">
                    <strong>{{ voiceTrimState.fileName }}</strong>
                    <span>{{ voiceTrimRangeText }} / 总时长 {{ formatPreciseDuration(voiceTrimState.duration) }}</span>
                </div>

                <div class="voice-trim-modal__timeline" ref="voiceTrimTrackRef" @pointerdown="handleVoiceTrimTrackPointerDown">
                    <div class="voice-trim-modal__ticks">
                        <span>0:00</span>
                        <span>{{ formatPreciseDuration(voiceTrimState.duration) }}</span>
                    </div>
                    <div class="voice-trim-modal__track">
                        <div
                            class="voice-trim-modal__window"
                            :style="voiceTrimWindowStyle"
                            @pointerdown.stop="handleVoiceTrimWindowPointerDown"
                        >
                            <span class="voice-trim-modal__handle is-left"></span>
                            <span class="voice-trim-modal__duration">10s</span>
                            <span class="voice-trim-modal__handle is-right"></span>
                        </div>
                    </div>
                </div>

                <audio
                    ref="voiceTrimAudioRef"
                    :src="voiceTrimState.url || undefined"
                    preload="metadata"
                    controls
                    class="voice-trim-modal__audio"
                ></audio>

                <div class="voice-trim-modal__actions">
                    <button type="button" :disabled="voiceTrimState.processing" @click="cancelVoiceTrim">{{ texts.cancel }}</button>
                    <button type="button" :disabled="voiceTrimState.processing" @click="previewVoiceTrimSelection">
                        试听片段
                    </button>
                    <button class="is-primary" type="button" :disabled="voiceTrimState.processing" @click="confirmVoiceTrim">
                        {{ voiceTrimState.processing ? '裁剪中...' : '确认裁剪并上传' }}
                    </button>
                </div>
            </section>
        </div>

        <input ref="fileInputRef" type="file" class="sr-only" accept="video/*" @change="handleUpload" />
        <input
            ref="createAvatarInputRef"
            type="file"
            class="sr-only"
            accept="video/*"
            @change="handleCreateAvatarUpload"
        />
        <input
            ref="avatarCoverInputRef"
            type="file"
            class="sr-only"
            accept="image/*"
            @change="handleAvatarCoverUpload"
        />
        <input
            ref="voiceCoverInputRef"
            type="file"
            class="sr-only"
            accept="image/*"
            @change="handleVoiceCoverUpload"
        />
        <input
            ref="driverAudioInputRef"
            type="file"
            class="sr-only"
            :accept="voiceCreateAudioAccept"
            @change="handleDriverAudioUpload"
        />
        <audio ref="voicePreviewRef" preload="none" class="voice-preview-audio"></audio>
    </div>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { uploadFile, uploadImage, uploadVideo } from '@/api/app'
import {
    assistAigcDigitalHumanScript,
    estimateAigcDigitalHuman,
    generateAigcDigitalHuman,
    getAigcDigitalHumanAvatars,
    getAigcDigitalHumanConfig,
    getAigcDigitalHumanResults,
    getAigcDigitalHumanTask,
    getAigcDigitalHumanTasks,
    getAigcDigitalHumanVoices,
    previewAigcDigitalHumanVoice,
    saveAigcDigitalHumanAvatar,
    saveAigcDigitalHumanVoice
} from '@/apps/aigc_digital_human/api'
import { ElPagination } from 'element-plus'
import { usePcLoginGate } from '@/composables/usePcLoginGate'
import feedback from '@/utils/feedback'
import { useUserStore } from '@/stores/user'
import { usePcCredits } from '~/composables/usePcCredits'
import {
    avatarPageSessionKey,
    buildSidebarRouteLocation,
    resolveAvatarWorkspaceSidebar
} from '~/utils/ai-sidebar'
import type { SidebarKey } from '~/utils/ai-sidebar'
import card2 from '@/assets/images/ai-app/card-2.png'
import card3 from '@/assets/images/ai-app/card-3.png'
import card4 from '@/assets/images/ai-app/card-4.png'
import card5 from '@/assets/images/ai-app/card-5.png'
import card6 from '@/assets/images/ai-app/card-6.png'
import card7 from '@/assets/images/ai-app/card-7.png'
import card8 from '@/assets/images/ai-app/card-8.png'
import card9 from '@/assets/images/ai-app/card-9.png'
import card10 from '@/assets/images/ai-app/card-10.png'
import card11 from '@/assets/images/ai-app/card-11.png'
import card12 from '@/assets/images/ai-app/card-12.png'
import addIcon from '@/assets/images/icon/Add.svg'
import clearIcon from '@/assets/images/icon/Clear.svg'
import createNowIcon from '@/assets/images/icon/chuangz.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import historyIcon from '@/assets/images/icon/History.svg'
import voiceHistoryIcon from '@/assets/images/icon/History1.svg'
import voiceUploadIcon from '@/assets/images/icon/sahngchuan.svg'
import voiceDeleteIcon from '@/assets/images/icon/Delete-themes.svg'
import magicIcon from '@/assets/images/icon/Magic.svg'
import peopleIcon from '@/assets/images/icon/People.svg'
import translateIcon from '@/assets/images/icon/Translate.svg'
import typeIcon from '@/assets/images/icon/leixing.svg'
import vipBadgeIcon from '@/assets/images/icon/vip.svg'
import voiceLibraryIcon from '@/assets/images/icon/Waves.svg'
import avatarLibraryIcon from '@/assets/images/icon/xingxiang.svg'
import switchUploadIcon from '@/assets/images/icon/zaici.svg'

definePageMeta({ layout: 'blank' })

type LibraryTab = 'official' | 'mine'
type ContentPanel = 'avatar' | 'voice'
type PopoverKey = '' | 'share' | 'api' | 'notice'
type AvatarMediaType = 'image' | 'video'
type VoiceCreateStep = 'idle' | 'recording' | 'sample_ready' | 'saving'
type VoiceCreateSampleSource = '' | 'upload' | 'record'
type VoiceLibraryCategory = '收藏' | '全部' | '职业' | '少男' | '少女' | '中年' | '老人' | '儿童'
type ToolCategory = '\u5168\u90e8' | '\u56fe\u7247\u7f16\u8f91' | '\u7535\u5b50\u5546\u52a1' | '\u5efa\u7b51\u5ba4\u5185' | '\u4eba\u50cf\u6444\u5f71' | '\u6e38\u620f\u52a8\u6f2b' | '\u521b\u610f'

interface AvatarItem {
    id: string
    name: string
    topic: string
    image: string
    vip: boolean
    source: LibraryTab
    fileName?: string
    mediaType?: AvatarMediaType
    videoUrl?: string
    rawId?: number | string
    uploadFile?: File
}

interface VoiceItem {
    id: string
    name: string
    fileName?: string
    cover?: string
    vip: boolean
    source: LibraryTab
    starred?: boolean
    previewUrl?: string
    synthesizedPreviewUrl?: string
    gender?: string
    age?: string
    duration?: number
    providerAssetId?: string
    status?: string
    rawId?: number | string
    uploadFile?: File
    libraryCategory?: Exclude<VoiceLibraryCategory, '收藏' | '全部'>
}

interface PendingAvatarUpload {
    file: File
    remoteUri?: string
    fileName: string
    mediaType: AvatarMediaType
    url: string
    previewImage: string
    blobUrls: string[]
    coverFile?: File
    coverUrl?: string
}

interface PendingVoiceUpload {
    file: File
    fileName: string
    url: string
    blobUrls: string[]
    coverFile?: File
    coverUrl?: string
    remoteUri?: string
}

interface VoiceTrimState {
    visible: boolean
    file: File | null
    fileName: string
    url: string
    duration: number
    start: number
    processing: boolean
}

interface DriverAudioUpload {
    file: File
    fileName: string
    url: string
    duration: number
    remoteUri?: string
}

interface FeaturedToolItem {
    id: string
    title: string
    description: string
    image: string
    accent: string
    category: ToolCategory
}

interface ToolCardItem {
    id: string
    title: string
    badge: string
    category: ToolCategory
    image: string
}

interface PersistedAvatarState {
    version: number
    activeSidebar: 'avatar'
    activeContentPanel: ContentPanel
    activeLibraryTab: LibraryTab
    activeVoiceTab: LibraryTab
    toolKeyword: string
    activeToolCategory: ToolCategory
    selectedFeaturedToolId: string
    selectedToolCardId: string
}

const avatarPageStateVersion = 4

const texts = {
    uploadAvatar: '\u4e0a\u4f20\u5f62\u8c61',
    avatarLibrary: '\u5f62\u8c61\u5e93',
    historyAvatar: '\u5386\u53f2\u5f62\u8c61',
    selectVoice: '\u9009\u62e9\u58f0\u97f3',
    voiceLibrary: '\u58f0\u97f3\u5e93',
    uploadVoice: '\u4e0a\u4f20\u58f0\u97f3',
    audioDriver: '\u97f3\u9891\u9a71\u52a8',
    historyVoice: '\u5386\u53f2\u58f0\u97f3',
    scriptTitle: '\u6587\u6848\u5185\u5bb9',
    scriptPlaceholder: '\u8bf7\u8f93\u5165\u4f60\u60f3\u8ba9\u89d2\u8272\u8bf4\u8bdd\u7684\u5185\u5bb9...',
    translate: '\u7ffb\u8bd1',
    translateTarget: '\u76ee\u6807\u8bed\u8a00',
    aiCopy: 'AI\u6587\u6848',
    processing: '\u751f\u6210\u4e2d',
    clear: '\u6e05\u7a7a',
    workTitle: '\u4f5c\u54c1\u540d\u79f0',
    workPlaceholder: '\u8bf7\u8f93\u5165\u4f5c\u54c1\u540d\u79f0',
    createRate: '3/\u79d2',
    creating: '\u521b\u4f5c\u4e2d...',
    createNow: '\u7acb\u5373\u521b\u4f5c',
    createMineAvatar: '\u521b\u5efa\u6211\u7684\u5f62\u8c61',
    useVoice: '\u53bb\u521b\u4f5c',
    previewVoice: '\u8bd5\u542c',
    playVoice: '\u64ad\u653e\u58f0\u97f3',
    pauseVoice: '\u6682\u505c',
    officialVoice: '\u5b98\u65b9\u58f0\u97f3',
    myVoice: '\u6211\u7684\u58f0\u97f3',
    createMineVoice: '\u514b\u9686\u97f3\u8272',
    noOfficialAvatar: '\u6682\u65e0\u516c\u5171\u5f62\u8c61',
    noOfficialVoice: '\u6682\u65e0\u516c\u5171\u58f0\u97f3',
    createVoiceTone: '\u65b0\u5efa\u97f3\u8272',
    saveVoice: '\u4fdd\u5b58\u97f3\u8272',
    savingVoice: '\u514b\u9686\u4e2d...',
    voiceName: '\u58f0\u97f3\u540d\u79f0',
    voiceNamePlaceholder: '\u8bf7\u8f93\u5165\u540d\u79f0',
    voiceSettings: '\u58f0\u97f3\u8bbe\u7f6e',
    voiceGender: '\u6027\u522b',
    voiceAge: '\u5e74\u9f84',
    voiceCover: '\u58f0\u97f3\u5c01\u9762',
    clickToRecord: '\u70b9\u51fb\u5f00\u59cb\u5f55\u5236',
    clickToPreview: '\u70b9\u51fb\u8bd5\u542c',
    retryRecord: '\u91cd\u65b0\u5f55\u5236',
    confirmRecord: '\u786e\u8ba4\u5f55\u97f3',
    startRecord: '\u5f00\u59cb\u5f55\u5236',
    stopRecord: '\u505c\u6b62\u5f55\u5236',
    previewCreatedVoice: '\u8bd5\u542c\u5f55\u97f3',
    voiceCreateTip: '\u4e3a\u4e86\u83b7\u5f97\u66f4\u7406\u60f3\u7684\u6548\u679c\uff0c\u5b89\u9759\u7684\u73af\u5883\u4e0b\u5f55\u5236~',
    uploadAudioFile: '\u4e0a\u4f20\u97f3\u9891\u6587\u4ef6',
    recordVoiceSample: '\u5f55\u5236\u58f0\u97f3',
    currentSample: '\u5f53\u524d\u6837\u672c',
    sampleEmpty: '\u6682\u65e0\u6837\u672c\uff0c\u8bf7\u5148\u4e0a\u4f20\u97f3\u9891\u6216\u5f00\u59cb\u5f55\u97f3',
    sampleReady: '\u6837\u672c\u5df2\u5c31\u7eea',
    sampleFromUpload: '\u672c\u5730\u4e0a\u4f20',
    sampleFromRecord: '\u5f55\u97f3\u6837\u672c',
    sampleDuration: '\u65f6\u957f',
    recordingNow: '\u5f55\u97f3\u4e2d',
    readyToSaveVoice: '\u53ef\u8bd5\u542c\u6216\u76f4\u63a5\u4fdd\u5b58',
    chooseVoiceSample: '\u8bf7\u9009\u62e9\u4e0a\u4f20\u97f3\u9891\u6216\u5f00\u59cb\u5f55\u97f3',
    sampleGuide: '\u6717\u8bfb\u4e0b\u65b9\u6587\u6848\uff0c\u786e\u4fdd\u58f0\u97f3\u81ea\u7136\u3001\u6e05\u6670\u3001\u7a33\u5b9a',
    sampleReadyHint: '\u6837\u672c\u5df2\u5c31\u7eea\uff0c\u53ef\u8bd5\u542c\u6216\u76f4\u63a5\u4fdd\u5b58',
    rerecordVoice: '\u91cd\u65b0\u5f55\u5236',
    reuploadAudio: '\u91cd\u65b0\u4e0a\u4f20',
    selectedSampleCanSave: '\u6837\u672c\u5df2\u9009\u62e9\uff0c\u53ef\u76f4\u63a5\u4fdd\u5b58',
    samplePreviewUnavailable: '\u5f53\u524d\u73af\u5883\u65e0\u6cd5\u672c\u5730\u8bd5\u542c\u8be5\u97f3\u9891\uff0c\u53ef\u76f4\u63a5\u4fdd\u5b58\u97f3\u8272',
    recordUnavailable: '\u5f53\u524d\u73af\u5883\u4e0d\u652f\u6301\u5f55\u97f3\uff0c\u8bf7\u4f7f\u7528\u4e0a\u4f20\u97f3\u9891',
    recordNeedPermission: '\u70b9\u51fb\u540e\u5c06\u8bf7\u6c42\u9ea6\u514b\u98ce\u6743\u9650\uff0c\u8bf7\u9009\u62e9\u5141\u8bb8\u5f55\u97f3',
    recordNeedSecureContext: '\u6d4f\u89c8\u5668\u8981\u6c42 HTTPS \u6216 localhost \u73af\u5883\u624d\u80fd\u5f55\u97f3',
    recordApiUnavailable: '\u5f53\u524d\u6d4f\u89c8\u5668\u7f3a\u5c11\u5f55\u97f3\u80fd\u529b\uff0c\u8bf7\u66f4\u6362 Chrome / Edge \u6216\u4e0a\u4f20\u97f3\u9891',
    recordPermissionDenied: '\u672a\u6388\u6743\u9ea6\u514b\u98ce\uff0c\u8bf7\u5141\u8bb8\u5f55\u97f3\u6216\u6539\u7528\u4e0a\u4f20\u97f3\u9891',
    recordEmpty: '\u672a\u91c7\u96c6\u5230\u6709\u6548\u97f3\u9891\uff0c\u8bf7\u91cd\u8bd5',
    recordStoppedUnexpectedly: '\u5f55\u97f3\u4e2d\u65ad\uff0c\u8bf7\u91cd\u8bd5\u6216\u6539\u7528\u4e0a\u4f20\u97f3\u9891',
    uploadAudioSuccess: '\u4e0a\u4f20\u5b8c\u6210\uff0c\u8bf7\u8bd5\u542c\u540e\u4fdd\u5b58\u97f3\u8272',
    recordAudioSuccess: '\u5f55\u97f3\u5b8c\u6210\uff0c\u8bf7\u8bd5\u542c\u540e\u4fdd\u5b58\u97f3\u8272',
    unsupportedAudioFormat: '\u4ec5\u652f\u6301 mp3\u3001wav\u3001m4a\u3001aac\u3001ogg \u97f3\u9891\u6587\u4ef6',
    voiceSampleTooLong: '\u514b\u9686\u97f3\u9891\u4e0d\u80fd\u8d85\u8fc710\u79d2\uff0c\u8bf7\u91cd\u65b0\u4e0a\u4f20',
    uploadMineTitle: '\u4e0a\u4f20\u6211\u7684\u5f62\u8c61',
    uploadMineHint: '\u70b9\u51fb\u4e0a\u4f20\u540e\u5373\u53ef\u5728\u8fd9\u91cc\u7ba1\u7406\u81ea\u5b9a\u4e49\u6570\u5b57\u4eba',
    closeModal: '\u5173\u95ed\u5f39\u7a97',
    videoRequirements: '\u89c6\u9891\u8981\u6c42\uff1a',
    avatarName: '\u5f62\u8c61\u540d\u79f0',
    avatarNamePlaceholder: '\u8bf7\u8f93\u5165\u540d\u79f0',
    avatarScene: '\u5e94\u7528\u573a\u666f',
    avatarCover: '\u5f62\u8c61\u5c01\u9762',
    saveAvatar: '\u4fdd\u5b58\u5f62\u8c61',
    replaceAsset: '\u91cd\u65b0\u4e0a\u4f20\u56fe\u7247\u6216\u89c6\u9891',
    avatarMenu: '\u66f4\u591a\u64cd\u4f5c',
    editAvatar: '\u7f16\u8f91',
    deleteAvatar: '\u5220\u9664',
    editMineAvatar: '\u7f16\u8f91\u6211\u7684\u5f62\u8c61',
    deleteAvatarTitle: '\u60a8\u786e\u5b9a\u5220\u9664\u5417\uff1f',
    deleteAvatarMessage: '\u5220\u9664\u540e\uff0c\u5f62\u8c61\u5c06\u5931\u6548\uff0c\u8bf7\u60a8\u518d\u6b21\u786e\u8ba4',
    toolSearchPlaceholder: '\u8bf7\u8f93\u5165\u5de5\u5177\u5173\u952e\u8bcd',
    featuredTools: '\u7cbe\u9009\u5de5\u5177',
    allTools: '\u5168\u90e8\u5de5\u5177',
    noToolResult: '\u6682\u672a\u627e\u5230\u5339\u914d\u5de5\u5177',
    noToolResultHint: '\u8bd5\u8bd5\u66f4\u6362\u5173\u952e\u8bcd\u6216\u5207\u6362\u5206\u7c7b',
    cancel: '\u53d6\u6d88',
    confirm: '\u786e\u5b9a'
} as const

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()
const { ensurePcLogin } = usePcLoginGate()
const { remainingCredits, membershipEnabled, refreshCredits } = usePcCredits()
const fileInputRef = ref<HTMLInputElement | null>(null)
const createAvatarInputRef = ref<HTMLInputElement | null>(null)
const avatarCoverInputRef = ref<HTMLInputElement | null>(null)
const driverAudioInputRef = ref<HTMLInputElement | null>(null)
const voiceCoverInputRef = ref<HTMLInputElement | null>(null)
const voicePreviewRef = ref<HTMLAudioElement | null>(null)
const avatarModalVideoRef = ref<HTMLVideoElement | null>(null)
const avatarMainRef = ref<HTMLElement | null>(null)
const activeSidebar = ref<SidebarKey>('avatar')
const activeContentPanel = ref<ContentPanel>('avatar')
const activeLibraryTab = ref<LibraryTab>('mine')
const activeVoiceTab = ref<LibraryTab>('mine')
const activeVoiceCategory = ref<VoiceLibraryCategory>('全部')
const activePopover = ref<PopoverKey>('')
const isCreating = ref(false)
const avatarPage = ref(1)
const voicePage = ref(1)
const avatarPageSize = 8
const voicePageSize = 8
const showAvatarCreateModal = ref(false)
const showVoiceCreateModal = ref(false)
const pendingAvatarUpload = ref<PendingAvatarUpload | null>(null)
const pendingVoiceUpload = ref<PendingVoiceUpload | null>(null)
const avatarCreateName = ref('')
const avatarCreateScene = ref('\u4ea7\u54c1\u4ecb\u7ecd')
const avatarSceneMenuOpen = ref(false)
const avatarCreateCover = ref('')
const isAvatarModalVideoPlaying = ref(false)
const avatarEditingId = ref('')
const avatarCardMenuId = ref('')
const showAvatarDeleteModal = ref(false)
const avatarDeleteTargetId = ref('')
const toolKeyword = ref('')
const activeToolCategory = ref<ToolCategory>('\u5168\u90e8')
const selectedFeaturedToolId = ref('featured-tool-1')
const selectedToolCardId = ref('tool-card-1')
const voiceCreateName = ref('')
const voiceCreateGender = ref<string>(texts.voiceGender)
const voiceCreateAge = ref<string>(texts.voiceAge)
const voiceCreateCover = ref('')
const voiceCreateCoverFile = ref<File | null>(null)
const voiceCreateStep = ref<VoiceCreateStep>('idle')
const voiceCreateElapsed = ref(0)
const isVoiceCreatePreviewPlaying = ref(false)
const isVoiceCreateSaving = ref(false)
const voiceCreatePreviewRef = ref<HTMLAudioElement | null>(null)
const voicePreviewLoadingId = ref('')
const voiceCreateGenderMenuOpen = ref(false)
const voiceCreateAgeMenuOpen = ref(false)
const voiceCreateRecordedUrl = ref('')
const voiceCreateRecordedMimeType = ref('')
const voiceCreateTempUrls = ref<string[]>([])
const voiceCreateSampleSource = ref<VoiceCreateSampleSource>('')
const voiceTrimTrackRef = ref<HTMLElement | null>(null)
const voiceTrimAudioRef = ref<HTMLAudioElement | null>(null)
const voiceTrimState = ref<VoiceTrimState>({
    visible: false,
    file: null,
    fileName: '',
    url: '',
    duration: 0,
    start: 0,
    processing: false
})
const selectedVoice = ref('')
const workName = ref('')
const scriptText = ref('')
const driverAudio = ref<DriverAudioUpload | null>(null)
const translateMenuOpen = ref(false)
const createdBlobUrls = ref<string[]>([])
let voiceCreateTimer: number | null = null
let voiceCreateRecorder: MediaRecorder | null = null
let voiceCreateStream: MediaStream | null = null
let voiceCreateChunks: Blob[] = []
let voiceCreateShouldDiscard = false
let voiceCreatePreviewAudio: HTMLAudioElement | null = null
let voiceTrimDrag: { startX: number; initialStart: number } | null = null

const promptChips = ['\u77e5\u8bc6\u79d1\u666e', '\u4ea7\u54c1\u4ecb\u7ecd']
const translateLanguageOptions = [
    { label: '\u7b80\u4f53\u4e2d\u6587', value: '简体中文' },
    { label: 'English', value: 'English' },
    { label: '\u65e5\u672c\u8a9e', value: '日本語' },
    { label: '\ud55c\uad6d\uc5b4', value: '한국어' },
    { label: 'Español', value: 'Español' },
    { label: 'Français', value: 'Français' },
    { label: 'Deutsch', value: 'Deutsch' },
    { label: '\u0e44\u0e17\u0e22', value: 'ไทย' }
]
const avatarSceneOptions = ['\u4ea7\u54c1\u4ecb\u7ecd', '\u77e5\u8bc6\u79d1\u666e', '\u65b0\u95fb\u64ad\u62a5', '\u60c5\u611f\u95ee\u7b54']
const voiceGenderOptions = ['\u5973\u58f0', '\u7537\u58f0', '\u4e2d\u6027']
const voiceAgeOptions = ['\u5c11\u5e74', '\u9752\u5e74', '\u6210\u5e74', '\u719f\u9f84']
const avatarCreateRequirements = [
    '\u89c6\u9891\u65b9\u5411\uff1a\u6a2a\u5411\u6216\u7eb5\u5411',
    '\u6587\u4ef6\u683c\u5f0f\uff1aMP4\u3001MOV',
    '\u6587\u4ef6\u5927\u5c0f\uff1a\u4e0d\u8d85\u8fc7200M',
    '\u5206\u8fa8\u7387\uff1a360P-4K',
    '\u89c6\u9891\u65f6\u957f\uff1a5\u79d2-5\u5206\u949f'
]
const libraryTabs = [
    { key: 'mine' as const, label: '\u6211\u7684\u5f62\u8c61' },
    { key: 'official' as const, label: '\u5b98\u65b9\u5f62\u8c61' }
]
const voiceTabs = [
    { key: 'mine' as const, label: texts.myVoice },
    { key: 'official' as const, label: texts.officialVoice }
]
const voiceLibraryCategoryOptions: VoiceLibraryCategory[] = ['收藏', '全部', '职业', '少男', '少女', '中年', '老人', '儿童']
const voiceCreateScript = computed(() =>
    String(digitalHumanBaseConfig.value?.voice_preview_text || '\u6b22\u8fce\u4f7f\u7528 A. PART \u58f0\u97f3\u5b9e\u9a8c\u5ba4\uff0c\u8bf7\u7528\u81ea\u7136\u3001\u6e05\u6670\u3001\u7a33\u5b9a\u7684\u8bed\u901f\u8bfb\u5b8c\u8fd9\u6bb5\u6587\u6848\uff0c\u6211\u4eec\u4f1a\u4e3a\u4f60\u751f\u6210\u4e13\u5c5e\u97f3\u8272\uff0c\u5e76\u7528\u4e8e\u540e\u7eed\u6570\u5b57\u4eba\u53e3\u64ad\u521b\u4f5c\u3002')
)
const voiceCreateCost = 50
const voiceCreateNameMaxLength = 32
const voiceCreateMaxDuration = 10
const voiceCreateAudioAccept = '.mp3,.wav,.m4a,.aac,.ogg,audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/aac,audio/ogg'
const voiceCreateAllowedAudioExtensions = ['mp3', 'wav', 'm4a', 'aac', 'ogg']
const toolCategoryOptions: ToolCategory[] = [
    '\u5168\u90e8',
    '\u56fe\u7247\u7f16\u8f91',
    '\u7535\u5b50\u5546\u52a1',
    '\u5efa\u7b51\u5ba4\u5185',
    '\u4eba\u50cf\u6444\u5f71',
    '\u6e38\u620f\u52a8\u6f2b',
    '\u521b\u610f'
]
const featuredTools = ref<FeaturedToolItem[]>([
    {
        id: 'featured-tool-1',
        title: 'AI\u624b\u638c\u8ff7\u4f60\u7248',
        description: '\u4e0a\u4f20\u56fe\u7247\uff0c\u751f\u6210\u8ff7\u4f60\u81ea\u5df1',
        image: card2,
        accent: '#8b63ff',
        category: '\u521b\u610f'
    },
    {
        id: 'featured-tool-2',
        title: '\u4e00\u952e\u62a0\u56fe',
        description: '\u5feb\u901f\u4fee\u6539\u4fee\u6539\u56fe\u7247\u4efb\u610f\u7ec6\u8282',
        image: card3,
        accent: '#ff9432',
        category: '\u56fe\u7247\u7f16\u8f91'
    },
    {
        id: 'featured-tool-3',
        title: '\u56fe\u6587\u4e8c\u521b',
        description: '\u8f93\u5165\u94fe\u63a5\uff0c\u81ea\u52a8\u89e3\u6790\u5e76\u751f\u6210\u4f18\u5316\u6587\u6848\u4e0e\u56fe\u7247',
        image: card4,
        accent: '#4ecdc4',
        category: '\u7535\u5b50\u5546\u52a1'
    },
    {
        id: 'featured-tool-4',
        title: '\u4e00\u952e\u521b\u4f5c',
        description: '\u8f93\u5165\u9700\u6c42\uff0c\u4e00\u952e\u751f\u6210\u5c0f\u7ea2\u4e66\u56fe\u6587\u4e0e\u914d\u56fe\u3002',
        image: card5,
        accent: '#ff5d5d',
        category: '\u521b\u610f'
    },
    {
        id: 'featured-tool-5',
        title: '\u4e00\u952e\u8f6c\u52a8\u6f2b',
        description: '\u4ece\u56fe\u7247\u53cd\u63a8\u63d0\u793a\u8bcd',
        image: card6,
        accent: '#ff7bc3',
        category: '\u6e38\u620f\u52a8\u6f2b'
    },
    {
        id: 'featured-tool-6',
        title: '\u4e00\u952e\u7acb\u9762\u8fc1\u79fb',
        description: '\u5efa\u7b51\u8bbe\u8ba1',
        image: card7,
        accent: '#8acb59',
        category: '\u5efa\u7b51\u5ba4\u5185'
    },
    {
        id: 'featured-tool-7',
        title: '\u68ee\u7cfb\u5199\u771f',
        description: '\u7167\u7247\u8f6c\u68ee\u7cfb\u98ce\u683c\u5199\u771f',
        image: card8,
        accent: '#4db6ff',
        category: '\u4eba\u50cf\u6444\u5f71'
    },
    {
        id: 'featured-tool-8',
        title: '\u4e13\u4e1a\u6444\u5f71',
        description: '\u4e0a\u4f20\u56fe\u7247\uff0c\u751f\u6210\u4e13\u4e1a\u6444\u5f71\u98ce\u683c\u4ea7\u54c1\u56fe',
        image: card9,
        accent: '#ffd166',
        category: '\u7535\u5b50\u5546\u52a1'
    }
])
const toolCards = ref<ToolCardItem[]>([
    {
        id: 'tool-card-1',
        title: '\u4e00\u952e\u590d\u523bDAZZ\u590d\u53e4\u80f6\u7247\u6ee4\u955c',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u56fe\u7247\u7f16\u8f91',
        image: card10
    },
    {
        id: 'tool-card-2',
        title: '\u7535\u5546\u4ea7\u54c1\u80f6\u7247\u6c1b\u56f4\u611f',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u7535\u5b50\u5546\u52a1',
        image: card11
    },
    {
        id: 'tool-card-3',
        title: '\u5efa\u7b51\u7a7a\u95f4\u590d\u53e4\u80f6\u7247\u6c14\u8d28',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u5efa\u7b51\u5ba4\u5185',
        image: card12
    },
    {
        id: 'tool-card-4',
        title: '\u4eba\u50cf\u5199\u771f\u80f6\u7247\u8c03\u8272',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u4eba\u50cf\u6444\u5f71',
        image: card2
    },
    {
        id: 'tool-card-5',
        title: '\u521b\u610f\u6d77\u62a5\u590d\u53e4\u80f6\u7247\u611f',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u521b\u610f',
        image: card3
    },
    {
        id: 'tool-card-6',
        title: '\u96ea\u5c71\u573a\u666f\u80f6\u7247\u8c03\u8272',
        badge: '\u70ed\u95e8\u98ce\u666f\u6548\u679c',
        category: '\u56fe\u7247\u7f16\u8f91',
        image: card4
    },
    {
        id: 'tool-card-7',
        title: '\u54c1\u724c\u62cd\u6444\u6781\u7b80\u9ad8\u7ea7\u611f',
        badge: '\u7535\u5546\u4ea7\u54c1\u56fe',
        category: '\u7535\u5b50\u5546\u52a1',
        image: card5
    },
    {
        id: 'tool-card-8',
        title: '\u5efa\u7b51\u7acb\u9762\u6c1b\u56f4\u5feb\u901f\u66ff\u6362',
        badge: '\u5efa\u7b51\u5ba4\u5185',
        category: '\u5efa\u7b51\u5ba4\u5185',
        image: card6
    },
    {
        id: 'tool-card-9',
        title: '\u4eba\u50cf\u80f6\u7247\u5199\u771f\u5149\u5f71\u589e\u5f3a',
        badge: '\u4eba\u50cf\u5199\u771f',
        category: '\u4eba\u50cf\u6444\u5f71',
        image: card7
    },
    {
        id: 'tool-card-10',
        title: '\u6e38\u620f\u89d2\u8272\u53e4\u98ce\u8d28\u611f\u91cd\u7ed8',
        badge: '\u6e38\u620f\u52a8\u6f2b',
        category: '\u6e38\u620f\u52a8\u6f2b',
        image: card8
    },
    {
        id: 'tool-card-11',
        title: '\u521b\u610f\u6d77\u62a5\u970d\u683c\u6ee4\u955c\u8bbe\u8ba1',
        badge: '\u521b\u610f\u5de5\u5177',
        category: '\u521b\u610f',
        image: card9
    },
    {
        id: 'tool-card-12',
        title: '\u65e7\u80f6\u7247\u8d28\u611f\u4eba\u50cf\u590d\u523b',
        badge: '\u70ed\u95e8\u80f6\u7247\u6ee4\u955c',
        category: '\u4eba\u50cf\u6444\u5f71',
        image: card10
    },
    {
        id: 'tool-card-13',
        title: '\u7535\u5546\u7a7a\u95f4\u6784\u56fe\u4e00\u952e\u4f18\u5316',
        badge: '\u7535\u5546\u89c6\u89c9',
        category: '\u7535\u5b50\u5546\u52a1',
        image: card11
    },
    {
        id: 'tool-card-14',
        title: '\u6f2b\u753b\u5206\u955c DAZZ \u590d\u53e4\u8d28\u611f',
        badge: '\u6e38\u620f\u52a8\u6f2b',
        category: '\u6e38\u620f\u52a8\u6f2b',
        image: card12
    },
    {
        id: 'tool-card-15',
        title: '\u6982\u5ff5\u63d2\u753b\u68ee\u7cfb\u8272\u5f69\u8fc1\u79fb',
        badge: '\u521b\u610f\u8bbe\u8ba1',
        category: '\u521b\u610f',
        image: card2
    },
    {
        id: 'tool-card-16',
        title: '\u5efa\u7b51\u7a7a\u95f4\u7ebf\u7a3f\u6e32\u67d3\u589e\u5f3a',
        badge: '\u7ebf\u7a3f\u5f3a\u5316',
        category: '\u5efa\u7b51\u5ba4\u5185',
        image: card3
    },
    {
        id: 'tool-card-17',
        title: '\u4eba\u50cf\u5199\u771f\u80cc\u666f\u6c1b\u56f4\u91cd\u5851',
        badge: '\u5f71\u68da\u611f\u6c1b\u56f4',
        category: '\u4eba\u50cf\u6444\u5f71',
        image: card4
    },
    {
        id: 'tool-card-18',
        title: '\u5546\u54c1\u5c40\u90e8\u7ec6\u8282\u9ad8\u6e05\u4fee\u590d',
        badge: '\u5546\u54c1\u7ec6\u8282',
        category: '\u7535\u5b50\u5546\u52a1',
        image: card5
    }
])

const officialAvatars = ref<AvatarItem[]>([])
const mineAvatars = ref<AvatarItem[]>([])
const selectedAvatar = ref<AvatarItem | null>(null)
const appliedAvatarId = ref('')
const previewingAvatarId = ref('')
const officialVoices = ref<VoiceItem[]>([])
const mineVoices = ref<VoiceItem[]>([])
const selectedVoiceCardId = ref('')
const appliedVoiceId = ref('')
const playingVoiceId = ref('')
const digitalHumanConfig = ref<any>({ channels: [], defaults: { channel: 'master', quality: '1k', ratio: '9:16' } })
const digitalHumanBaseConfig = ref<any>({
    script_max_length: 200,
    voice_preview_text: '\u6b22\u8fce\u4f7f\u7528 A. PART \u58f0\u97f3\u5b9e\u9a8c\u5ba4\uff0c\u8fd9\u662f\u4e00\u6bb5\u6570\u5b57\u4eba\u97f3\u8272\u8bd5\u542c\u3002'
})
const estimateInfo = ref<any>({})
const latestTask = ref<any>(null)
const latestResult = ref<any>(null)
const scriptAssistLoading = ref<'' | 'translate' | 'copywrite'>('')
const formOptions = ref({
    channel: 'master',
    quality: '1k',
    ratio: '9:16'
})
let previewAudio: HTMLAudioElement | null = null
let previewSpeech: SpeechSynthesisUtterance | null = null
let taskPollingTimer: number | null = null

const isClientRuntime = () => typeof window !== 'undefined'
const canUseSessionStorage = () => isClientRuntime()

const findAvatarItemById = (id: string) =>
    [...mineAvatars.value, ...officialAvatars.value].find((item) => item.id === id) ?? null

const findVoiceItemById = (id: string) =>
    [...mineVoices.value, ...officialVoices.value].find((item) => item.id === id) ?? null

const getAvatarDisplayName = (item: AvatarItem) => (
    item.source === 'official' && item.name === '\u5b98\u65b9\u4e3b\u64ad' ? '\u5b98\u65b9\u5f62\u8c61' : item.name
)

const allAvatarItems = computed(() => [...mineAvatars.value, ...officialAvatars.value])
const allVoiceItems = computed(() => [...mineVoices.value, ...officialVoices.value])
const selectedRawAvatarId = computed(() => Number((appliedAvatarItem.value || selectedAvatar.value)?.rawId || 0))
const selectedRawVoiceId = computed(() => Number(appliedVoiceItem.value?.rawId || selectedVoiceCardId.value || 0))
const scriptMaxLength = computed(() => Math.max(0, Number(digitalHumanBaseConfig.value?.script_max_length || 0)))
const estimatedDuration = computed(() => {
    if (driverAudio.value?.duration) return Math.max(1, Math.ceil(driverAudio.value.duration))
    return Math.max(1, Math.ceil((scriptText.value.trim().length || 1) / 4))
})
const estimatedCost = computed(() => Number(estimateInfo.value.user_charge_points ?? 0).toFixed(2))

const pickUploadUri = (res: any) => res?.uri || res?.url || res?.path || res?.file_url || ''

const dataUrlToFile = async (dataUrl: string, fileName: string) => {
    const response = await fetch(dataUrl)
    const blob = await response.blob()
    return new File([blob], fileName, { type: blob.type || 'image/jpeg' })
}

const mapAvatarRow = (row: any): AvatarItem => {
    const mediaType: AvatarMediaType = row.media_type === 'video' ? 'video' : 'image'
    const mediaUrl = row.media_url || row.url || row.cover_url || row.cover || ''
    const cover = row.cover_url || row.cover || (mediaType === 'image' ? mediaUrl : '') || card2

    return {
        id: String(row.id),
        rawId: row.id,
        name: row.name || '\u6570\u5b57\u4eba\u5f62\u8c61',
        topic: row.scene || row.topic || (mediaType === 'video' ? '\u53ef\u5408\u6210\u89c6\u9891\u5f62\u8c61' : '\u4e0d\u53ef\u7528\u4e8e\u771f\u5b9e\u5408\u6210'),
        image: cover,
        vip: row.is_vip === 1 || row.vip === true,
        source: row.source === 'official' ? 'official' : 'mine',
        fileName: row.file_name || row.name,
        mediaType,
        videoUrl: mediaType === 'video' ? mediaUrl : undefined
    }
}

const resolveVoicePreviewUrl = (row: any) => {
    if (row.preview_audio_url) return row.preview_audio_url
    if (row.provider_asset_id && row.source !== 'official') return undefined
    return row.preview_url || row.audio_url || undefined
}

const uniqueVoiceItems = (items: VoiceItem[]) => {
    const seen = new Set<string>()
    return items.filter((item) => {
        if (seen.has(item.id)) return false
        seen.add(item.id)
        return true
    })
}

const mapVoiceRow = (row: any): VoiceItem => ({
    id: String(row.id),
    rawId: row.id,
    name: row.name || '\u6570\u4eba\u97f3\u8272',
    fileName: row.file_name || row.name,
    cover: row.cover_url || row.cover || undefined,
    vip: row.is_vip === 1 || row.vip === true,
    source: row.source === 'official' ? 'official' : 'mine',
    starred: false,
    previewUrl: resolveVoicePreviewUrl(row),
    gender: row.gender,
    age: row.age_group || row.age,
    duration: row.duration,
    providerAssetId: row.provider_asset_id,
    status: row.status,
    libraryCategory: undefined
})

const syncDefaultSelections = () => {
    if (selectedAvatar.value && !findAvatarItemById(selectedAvatar.value.id)) selectedAvatar.value = null
    if (appliedAvatarId.value && !findAvatarItemById(appliedAvatarId.value)) appliedAvatarId.value = ''

    if (selectedVoiceCardId.value && !findVoiceItemById(selectedVoiceCardId.value)) {
        selectedVoiceCardId.value = ''
        selectedVoice.value = ''
    } else {
        selectedVoice.value = findVoiceItemById(selectedVoiceCardId.value)?.name || ''
    }
    if (appliedVoiceId.value && !findVoiceItemById(appliedVoiceId.value)) appliedVoiceId.value = ''
}

const applyDigitalHumanTaskToEditor = (task: any) => {
    if (!task || typeof task !== 'object') return false

    const avatarRawId = Number(task.avatar_id || task.avatar?.id || 0)
    const voiceRawId = Number(task.voice_id || task.voice?.id || 0)
    const avatar = allAvatarItems.value.find((item) => Number(item.rawId) === avatarRawId) || null
    const voice = allVoiceItems.value.find((item) => Number(item.rawId) === voiceRawId) || null

    if (avatar) {
        selectedAvatar.value = avatar
        appliedAvatarId.value = avatar.id
    }
    if (voice) {
        selectedVoiceCardId.value = voice.id
        selectedVoice.value = voice.name
        appliedVoiceId.value = voice.id
    }

    const content = String(task.script_text || task.prompt || '').trim()
    if (content) scriptText.value = scriptMaxLength.value ? Array.from(content).slice(0, scriptMaxLength.value).join('') : content
    workName.value = String(task.title || workName.value || '').trim()
    formOptions.value.channel = task.channel || formOptions.value.channel
    formOptions.value.quality = task.quality || formOptions.value.quality
    formOptions.value.ratio = task.ratio || formOptions.value.ratio
    clearDriverAudio()
    activeSidebar.value = 'avatar'
    activeContentPanel.value = avatar ? 'voice' : 'avatar'
    activePopover.value = 'notice'
    return true
}

const loadDigitalHumanTaskForEditing = async () => {
    if (!ensureDigitalHumanLogin()) return
    const rawId = Array.isArray(route.query.edit_task_id) ? route.query.edit_task_id[0] : route.query.edit_task_id
    const taskId = Number(rawId || 0)
    if (!Number.isFinite(taskId) || taskId <= 0) return

    try {
        const detail = await getAigcDigitalHumanTask({ id: taskId })
        if (applyDigitalHumanTaskToEditor(detail)) {
            feedback.msgSuccess('\u5df2\u5e26\u56de\u6570\u5b57\u4eba\u521b\u4f5c\u53c2\u6570')
            await router.replace({ path: '/ai/avatar' })
        }
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || '\u6570\u5b57\u4eba\u521b\u4f5c\u53c2\u6570\u52a0\u8f7d\u5931\u8d25')
    }
}

const refreshDigitalHumanEstimate = async () => {
    if (!userStore.isLogin) {
        estimateInfo.value = {}
        return
    }

    try {
        estimateInfo.value = await estimateAigcDigitalHuman({
            channel: formOptions.value.channel,
            quality: formOptions.value.quality,
            ratio: formOptions.value.ratio,
            duration: estimatedDuration.value
        })
    } catch (_error) {
        estimateInfo.value = {}
    }
}

const loadDigitalHumanData = async () => {
    if (!userStore.isLogin) {
        const [config, avatarRows, voiceRows] = await Promise.all([
            getAigcDigitalHumanConfig(),
            getAigcDigitalHumanAvatars({ source: 'official' }),
            getAigcDigitalHumanVoices({ source: 'official' })
        ])

        digitalHumanConfig.value = config?.option_config || digitalHumanConfig.value
        digitalHumanBaseConfig.value = config?.base_config || digitalHumanBaseConfig.value
        const defaults = digitalHumanConfig.value.defaults || {}
        formOptions.value.channel = defaults.channel || formOptions.value.channel
        formOptions.value.quality = defaults.quality || formOptions.value.quality
        formOptions.value.ratio = defaults.ratio || formOptions.value.ratio

        officialAvatars.value = (avatarRows || []).map(mapAvatarRow).filter((item: AvatarItem) => item.source === 'official')
        mineAvatars.value = []
        officialVoices.value = uniqueVoiceItems((voiceRows || []).map(mapVoiceRow).filter((item: VoiceItem) => item.source === 'official'))
        mineVoices.value = []
        latestResult.value = null
        latestTask.value = null
        estimateInfo.value = {}
        syncDefaultSelections()
        return
    }

    const [config, avatarRows, voiceRows, resultRows, taskRows] = await Promise.all([
        getAigcDigitalHumanConfig(),
        getAigcDigitalHumanAvatars(),
        getAigcDigitalHumanVoices(),
        getAigcDigitalHumanResults(),
        getAigcDigitalHumanTasks()
    ])

    digitalHumanConfig.value = config?.option_config || digitalHumanConfig.value
    digitalHumanBaseConfig.value = config?.base_config || digitalHumanBaseConfig.value
    const defaults = digitalHumanConfig.value.defaults || {}
    formOptions.value.channel = defaults.channel || formOptions.value.channel
    formOptions.value.quality = defaults.quality || formOptions.value.quality
    formOptions.value.ratio = defaults.ratio || formOptions.value.ratio

    const avatars = (avatarRows || []).map(mapAvatarRow)
    officialAvatars.value = avatars.filter((item: AvatarItem) => item.source === 'official')
    mineAvatars.value = avatars.filter((item: AvatarItem) => item.source === 'mine')

    const voices = uniqueVoiceItems((voiceRows || []).map(mapVoiceRow))
    officialVoices.value = voices.filter((item: VoiceItem) => item.source === 'official')
    mineVoices.value = voices.filter((item: VoiceItem) => item.source === 'mine')

    latestResult.value = (resultRows || []).find((item: any) => item.video_url) || (resultRows || [])[0] || null
    latestTask.value = (taskRows || [])[0] || latestTask.value
    syncDefaultSelections()
    await refreshDigitalHumanEstimate()
}

const ensureDigitalHumanLogin = () => {
    return ensurePcLogin({ redirect: route.fullPath })
}

const getPersistedAvatarState = (): PersistedAvatarState => ({
    version: avatarPageStateVersion,
    activeSidebar: 'avatar',
    activeContentPanel: activeContentPanel.value,
    activeLibraryTab: activeLibraryTab.value,
    activeVoiceTab: activeVoiceTab.value,
    toolKeyword: toolKeyword.value,
    activeToolCategory: activeToolCategory.value,
    selectedFeaturedToolId: selectedFeaturedToolId.value,
    selectedToolCardId: selectedToolCardId.value
})

const saveAvatarPageState = () => {
    if (!canUseSessionStorage()) return

    try {
        window.sessionStorage.setItem(avatarPageSessionKey, JSON.stringify(getPersistedAvatarState()))
    } catch (_error) {
        // Ignore storage failures and keep runtime state only.
    }
}

const handlePageRefresh = () => {
    saveAvatarPageState()
}

const restoreAvatarPageState = () => {
    if (!canUseSessionStorage()) return

    try {
        const rawState = window.sessionStorage.getItem(avatarPageSessionKey)
        if (!rawState) return

        const state = JSON.parse(rawState) as Partial<PersistedAvatarState>
        if (state.version !== avatarPageStateVersion) return

        if (state.activeContentPanel === 'avatar' || state.activeContentPanel === 'voice') {
            activeContentPanel.value = state.activeContentPanel
        }
        if (state.activeLibraryTab === 'official' || state.activeLibraryTab === 'mine') {
            activeLibraryTab.value = state.activeLibraryTab
        }
        if (state.activeVoiceTab === 'official' || state.activeVoiceTab === 'mine') {
            activeVoiceTab.value = state.activeVoiceTab
        }
        if (typeof state.toolKeyword === 'string') toolKeyword.value = state.toolKeyword
        if (state.activeToolCategory && toolCategoryOptions.includes(state.activeToolCategory)) {
            activeToolCategory.value = state.activeToolCategory
        }
        if (typeof state.selectedFeaturedToolId === 'string') {
            selectedFeaturedToolId.value = state.selectedFeaturedToolId
        }
        if (typeof state.selectedToolCardId === 'string') {
            selectedToolCardId.value = state.selectedToolCardId
        }
    } catch (_error) {
        // Ignore invalid stored payloads and fall back to default state.
    }
}

const displayedAvatars = computed(() => {
    return activeLibraryTab.value === 'official' ? officialAvatars.value : mineAvatars.value
})
const pagedDisplayedAvatars = computed(() => {
    const start = (avatarPage.value - 1) * avatarPageSize
    return displayedAvatars.value.slice(start, start + avatarPageSize)
})

const showMineCreateCard = computed(() => activeLibraryTab.value === 'mine')
const appliedAvatarItem = computed(() =>
    [...mineAvatars.value, ...officialAvatars.value].find((item) => item.id === appliedAvatarId.value) ?? null
)
const avatarCreateModalTitle = computed(() =>
    avatarEditingId.value ? texts.editMineAvatar : texts.createMineAvatar
)
const resolveVoiceLibraryCategory = (item: VoiceItem): Exclude<VoiceLibraryCategory, '收藏' | '全部'> | '' => {
    if (item.libraryCategory) return item.libraryCategory

    const combinedText = `${item.name}${item.gender ?? ''}${item.age ?? ''}`

    if (/儿|童|少年/.test(combinedText)) return '儿童'
    if (/老|熟龄/.test(combinedText)) return '老人'
    if (/中|成|稳重/.test(combinedText)) return '中年'
    if (/少男|男/.test(combinedText)) return '少男'
    if (/少女|女|知性|甜美/.test(combinedText)) return '少女'
    if (/主播|旁白|解说|新闻|品宣|口播/.test(combinedText)) return '职业'

    return ''
}

const displayedVoices = computed(() => {
    const source = activeVoiceTab.value === 'official' ? officialVoices.value : mineVoices.value
    const category = activeVoiceCategory.value

    if (category === '全部') return source
    if (category === '收藏') return source.filter((item) => item.starred)

    return source.filter((item) => resolveVoiceLibraryCategory(item) === category)
})
const pagedDisplayedVoices = computed(() => {
    const start = (voicePage.value - 1) * voicePageSize
    return displayedVoices.value.slice(start, start + voicePageSize)
})
const showMineVoiceCreateCard = computed(() => activeVoiceTab.value === 'mine')
const appliedVoiceItem = computed(() =>
    [...mineVoices.value, ...officialVoices.value].find((item) => item.id === appliedVoiceId.value) ?? null
)
const isVoiceCreateRecording = computed(() => voiceCreateStep.value === 'recording')
const isVoiceCreateSampleReady = computed(() => voiceCreateStep.value === 'sample_ready')
const isVoiceCreateBusy = computed(() => isVoiceCreateSaving.value || voiceCreateStep.value === 'saving')
const voiceCreateRecordCapability = computed(() => {
    if (!isClientRuntime()) {
        return { supported: false, reason: texts.recordUnavailable }
    }

    const hostname = window.location.hostname
    const isLocalHost =
        hostname === 'localhost' ||
        hostname === '127.0.0.1' ||
        hostname === '::1' ||
        hostname.endsWith('.localhost')

    if (!window.isSecureContext && !isLocalHost) {
        return { supported: false, reason: texts.recordNeedSecureContext }
    }

    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        return { supported: false, reason: texts.recordApiUnavailable }
    }

    return { supported: true, reason: '' }
})
const canRecordVoiceCreate = computed(() => voiceCreateRecordCapability.value.supported)
const voiceCreateRecordUnavailableReason = computed(() => voiceCreateRecordCapability.value.reason)
const voiceCreateRecordTip = computed(() => {
    if (isVoiceCreateRecording.value || canRecordVoiceCreate.value) return texts.voiceCreateTip
    return texts.recordNeedPermission
})
const voiceCreateStatusText = computed(() => {
    if (voiceCreateStep.value === 'recording') {
        return `${texts.recordingNow} ${formatVoiceCreateDuration(voiceCreateElapsed.value)} / ${formatVoiceCreateDuration(voiceCreateMaxDuration)}`
    }
    if (voiceCreateStep.value === 'sample_ready') {
        return isVoiceCreatePreviewPlaying.value ? texts.pauseVoice : texts.sampleReadyHint
    }
    if (voiceCreateStep.value === 'saving') return texts.savingVoice
    return texts.chooseVoiceSample
})
const voiceCreateRecordActionText = computed(() => {
    if (voiceCreateStep.value === 'recording') return texts.stopRecord
    if (voiceCreateSampleSource.value === 'record' && voiceCreateStep.value === 'sample_ready') return texts.rerecordVoice
    return texts.recordVoiceSample
})
const voiceCreateSampleSourceText = computed(() => {
    if (voiceCreateSampleSource.value === 'upload') return texts.sampleFromUpload
    if (voiceCreateSampleSource.value === 'record') return texts.sampleFromRecord
    return ''
})
const voiceCreateSampleName = computed(() => pendingVoiceUpload.value?.fileName || '')
const voiceCreateSampleDurationText = computed(() =>
    voiceCreateElapsed.value > 0 ? `${texts.sampleDuration} ${formatVoiceCreateDuration(voiceCreateElapsed.value)}` : ''
)
const voiceTrimMaxStart = computed(() => Math.max(0, voiceTrimState.value.duration - voiceCreateMaxDuration))
const voiceTrimEnd = computed(() => Math.min(voiceTrimState.value.duration, voiceTrimState.value.start + voiceCreateMaxDuration))
const voiceTrimWindowStyle = computed(() => {
    const duration = Math.max(voiceTrimState.value.duration, voiceCreateMaxDuration)
    const left = (voiceTrimState.value.start / duration) * 100
    const width = (voiceCreateMaxDuration / duration) * 100

    return {
        left: `${Math.max(0, Math.min(100, left))}%`,
        width: `${Math.max(0, Math.min(100 - left, width))}%`
    }
})
const voiceTrimRangeText = computed(
    () => `${formatPreciseDuration(voiceTrimState.value.start)} - ${formatPreciseDuration(voiceTrimEnd.value)}`
)
const canPreviewVoiceCreate = computed(
    () => isVoiceCreateSampleReady.value && !!(voiceCreateRecordedUrl.value || pendingVoiceUpload.value?.url)
)
const canSaveVoiceCreate = computed(() => isVoiceCreateSampleReady.value && !!pendingVoiceUpload.value)
const normalizedToolKeyword = computed(() => toolKeyword.value.trim().toLowerCase())
const filteredFeaturedTools = computed(() => {
    const keyword = normalizedToolKeyword.value
    return featuredTools.value.filter((item) => {
        const matchKeyword =
            !keyword ||
            [item.title, item.description, item.category].some((field) => field.toLowerCase().includes(keyword))
        return matchKeyword
    })
})
const filteredToolCards = computed(() => {
    const keyword = normalizedToolKeyword.value
    return toolCards.value.filter((item) => {
        const matchKeyword =
            !keyword ||
            [item.title, item.badge, item.category].some((field) => field.toLowerCase().includes(keyword))
        const matchCategory =
            activeToolCategory.value === '\u5168\u90e8' || item.category === activeToolCategory.value
        return matchKeyword && matchCategory
    })
})

const backgroundStyle = computed(() => ({
    backgroundImage: 'linear-gradient(180deg, #050505 0%, #06070a 42%, #050505 100%)'
}))
const headingLineStyle = computed(() => ({
    transform: `translateX(${libraryTabs.findIndex((tab) => tab.key === activeLibraryTab.value) * 120}px)`
}))
const voiceHeadingLineStyle = computed(() => ({
    transform: `translateX(${voiceTabs.findIndex((tab) => tab.key === activeVoiceTab.value) * 120}px)`
}))
const chromePopoverContent = computed(() => ({
    share: {
        title: '\u9080\u8bf7\u597d\u53cb',
        text: '\u5206\u4eab\u6570\u5b57\u4eba\u4f5c\u54c1\u94fe\u63a5\uff0c\u53cc\u65b9\u5404\u5f97 10 \u6761\u521b\u4f5c\u989d\u5ea6\u3002'
    },
    api: {
        title: 'API \u914d\u989d',
        text: '\u5f53\u524d\u4f53\u9a8c\u989d\u5ea6 24 \u6b21\uff0c\u652f\u6301\u6570\u5b57\u4eba\u53e3\u64ad\u3001\u5f62\u8c61\u751f\u6210\u4e0e\u58f0\u97f3\u590d\u523b\u3002'
    },
    notice: {
        title: '\u6d88\u606f\u4e2d\u5fc3',
        text:
            activeSidebar.value === 'tools'
                ? '\u5de5\u5177\u53f0\u5df2\u5c31\u7eea\uff0c\u53ef\u4ee5\u901a\u8fc7\u5173\u952e\u8bcd\u641c\u7d22\u3001\u5207\u6362\u5206\u7c7b\u6216\u70b9\u51fb\u5361\u7247\u5feb\u901f\u6d4f\u89c8\u5de5\u5177\u3002'
                : activeSidebar.value === 'assets'
                  ? '\u8d44\u4ea7\u5e93\u5df2\u5c31\u7eea\uff0c\u53ef\u4ee5\u6309\u5206\u7c7b\u7b5b\u9009\u3001\u5207\u6362\u6211\u7684\u6536\u85cf\uff0c\u6216\u5f00\u542f\u6279\u91cf\u64cd\u4f5c\u7ba1\u7406\u7d20\u6750\u3002'
                : isCreating.value
                  ? `\u5df2\u52a0\u5165\u521b\u4f5c\u961f\u5217\uff0c\u5f53\u524d\u4f7f\u7528\u58f0\u97f3\uff1a${selectedVoice.value}`
                  : '\u9009\u62e9\u5b98\u65b9\u5f62\u8c61\u540e\u5373\u53ef\u5feb\u901f\u751f\u6210\u6570\u5b57\u4eba\u53e3\u64ad\u4f5c\u54c1\u3002',
        compact: true
    }
}))

watch(activeLibraryTab, () => {
    avatarPage.value = 1
    if (!selectedAvatar.value) return
    const currentPool = displayedAvatars.value
    const exists = currentPool.some((item) => item.id === selectedAvatar.value?.id)
    if (!exists) selectedAvatar.value = null
})

watch([activeVoiceTab, activeVoiceCategory], () => {
    voicePage.value = 1
    stopVoicePreview()
    stopVoiceCreatePreview()
    if (!selectedVoiceCardId.value) return
    const currentPool = displayedVoices.value
    const exists = currentPool.some((item) => item.id === selectedVoiceCardId.value)
    if (!exists) {
        selectedVoiceCardId.value = ''
        selectedVoice.value = ''
    }
})
watch(displayedAvatars, (list) => {
    const totalPages = Math.max(1, Math.ceil(list.length / avatarPageSize))
    if (avatarPage.value > totalPages) avatarPage.value = totalPages
})
watch(displayedVoices, (list) => {
    const totalPages = Math.max(1, Math.ceil(list.length / voicePageSize))
    if (voicePage.value > totalPages) voicePage.value = totalPages
})

watch([scriptText, formOptions, driverAudio], () => {
    refreshDigitalHumanEstimate()
})

watch(activeContentPanel, (panel) => {
    if (panel !== 'voice') {
        stopVoicePreview()
        stopVoiceCreatePreview()
    }
})

watch([filteredFeaturedTools, filteredToolCards], ([featured, toolCards]) => {
    if (featured.length && !featured.some((item) => item.id === selectedFeaturedToolId.value)) {
        selectedFeaturedToolId.value = featured[0].id
    }

    if (toolCards.length && !toolCards.some((item) => item.id === selectedToolCardId.value)) {
        selectedToolCardId.value = toolCards[0].id
    }
})

watch(voiceCreateName, (value) => {
    const normalized = Array.from(value).slice(0, voiceCreateNameMaxLength).join('')
    if (normalized !== value) voiceCreateName.value = normalized
})

watch(
    [
        activeContentPanel,
        activeLibraryTab,
        activeVoiceTab,
        toolKeyword,
        activeToolCategory,
        selectedFeaturedToolId,
        selectedToolCardId
    ],
    () => {
        saveAvatarPageState()
    }
)

const lockAvatarPageToWorkspaceScroll = () => {
    if (typeof window === 'undefined') return

    document.documentElement.style.overflow = 'hidden'
    document.body.style.overflow = 'hidden'
}

const unlockAvatarPageScroll = () => {
    if (typeof window === 'undefined') return

    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
}

const resetAvatarCreateForm = () => {
    selectedAvatar.value = null
    appliedAvatarId.value = ''
    selectedVoiceCardId.value = ''
    appliedVoiceId.value = ''
    selectedVoice.value = ''
    workName.value = ''
    scriptText.value = ''
    clearDriverAudio()
}

const togglePopover = (key: Exclude<PopoverKey, ''>) => {
    activePopover.value = activePopover.value === key ? '' : key
}

const goHome = () => {
    saveAvatarPageState()
    router.push('/')
}

const handleSidebar = (key: SidebarKey) => {
    saveAvatarPageState()
    if (key === activeSidebar.value) {
        activePopover.value = 'notice'
        return
    }
    router.push(buildSidebarRouteLocation(key))
}

const setActiveToolCategory = (category: ToolCategory) => {
    activeToolCategory.value = category

    const matchingToolCard = filteredToolCards.value[0]

    if (matchingToolCard) selectedToolCardId.value = matchingToolCard.id
}

const openAvatarLibrary = (tab: LibraryTab) => {
    activeContentPanel.value = 'avatar'
    activeLibraryTab.value = tab
}

const openVoiceLibrary = (tab: LibraryTab) => {
    activeContentPanel.value = 'voice'
    activeVoiceTab.value = tab
    activeVoiceCategory.value = '全部'
}

const selectFeaturedTool = (item: FeaturedToolItem) => {
    selectedFeaturedToolId.value = item.id
    activeToolCategory.value = item.category
    const matchingToolCard = toolCards.value.find((toolCard) => toolCard.category === item.category)
    if (matchingToolCard) selectedToolCardId.value = matchingToolCard.id
}

const selectToolCard = (item: ToolCardItem) => {
    selectedToolCardId.value = item.id
    activeToolCategory.value = item.category
    const matchingFeatured = featuredTools.value.find((tool) => tool.category === item.category)
    if (matchingFeatured) selectedFeaturedToolId.value = matchingFeatured.id
}

const selectAvatar = (item: AvatarItem) => {
    selectedAvatar.value = item
}

const appendPrompt = (chip: string) => {
    if (scriptText.value.includes(chip)) return
    scriptText.value = `${scriptText.value}${scriptText.value ? '\uff0c' : ''}${chip}`
}

const toggleTranslateMenu = () => {
    if (scriptAssistLoading.value) return
    translateMenuOpen.value = !translateMenuOpen.value
}

const runScriptAssist = async (action: 'translate' | 'copywrite', targetLanguage = '') => {
    if (!ensureDigitalHumanLogin()) return
    const content = scriptText.value.trim()
    if (!content) {
        feedback.msgError(action === 'translate' ? '\u8bf7\u5148\u8f93\u5165\u9700\u8981\u7ffb\u8bd1\u7684\u6587\u6848' : '\u8bf7\u5148\u8f93\u5165\u9700\u8981\u6da6\u8272\u7684\u6587\u6848')
        return
    }
    translateMenuOpen.value = false
    scriptAssistLoading.value = action
    try {
        const result = await assistAigcDigitalHumanScript({ action, content, target_language: targetLanguage })
        const assisted = String(result?.content || '').trim()
        if (!assisted) throw new Error('\u672a\u751f\u6210\u6709\u6548\u6587\u6848')
        scriptText.value = scriptMaxLength.value ? Array.from(assisted).slice(0, scriptMaxLength.value).join('') : assisted
        feedback.msgSuccess(action === 'translate' ? '\u7ffb\u8bd1\u5df2\u5b8c\u6210' : '\u6587\u6848\u5df2\u6da6\u8272')
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || (action === 'translate' ? '\u7ffb\u8bd1\u5931\u8d25' : '\u6587\u6848\u6da6\u8272\u5931\u8d25'))
    } finally {
        scriptAssistLoading.value = ''
    }
}

const getFileBaseName = (fileName: string) => fileName.replace(/\.[^/.]+$/, '')

const trackBlobUrl = (url: string) => {
    createdBlobUrls.value.push(url)
    return url
}

const revokeTrackedBlobUrl = (url: string) => {
    URL.revokeObjectURL(url)
    createdBlobUrls.value = createdBlobUrls.value.filter((item) => item !== url)
}

const formatVoiceCreateDuration = (seconds: number) => {
    const minutes = Math.floor(seconds / 60)
    const remain = seconds % 60
    return `${minutes}:${String(remain).padStart(2, '0')}`
}

const formatPreciseDuration = (seconds: number) => {
    const safeSeconds = Math.max(0, seconds)
    const minutes = Math.floor(safeSeconds / 60)
    const remain = Math.floor(safeSeconds % 60)
    return `${minutes}:${String(remain).padStart(2, '0')}`
}

const rememberVoiceCreateTempUrl = (url: string) => {
    voiceCreateTempUrls.value.push(url)
    return url
}

const releaseVoiceCreateTempUrl = (url: string) => {
    if (!url || !voiceCreateTempUrls.value.includes(url)) return
    voiceCreateTempUrls.value = voiceCreateTempUrls.value.filter((item) => item !== url)
    revokeTrackedBlobUrl(url)
}

const discardVoiceCreateTempUrls = () => {
    voiceCreateTempUrls.value.forEach((url) => revokeTrackedBlobUrl(url))
    voiceCreateTempUrls.value = []
}

const clearVoiceCreateTimer = () => {
    if (voiceCreateTimer) {
        window.clearInterval(voiceCreateTimer)
        voiceCreateTimer = null
    }
}

const stopVoiceCreateStream = () => {
    voiceCreateStream?.getTracks().forEach((track) => track.stop())
    voiceCreateStream = null
    voiceCreateRecorder = null
}

const stopVoiceCreatePreview = () => {
    if (voiceCreatePreviewAudio) {
        voiceCreatePreviewAudio.pause()
        voiceCreatePreviewAudio.currentTime = 0
        voiceCreatePreviewAudio = null
    }
    const audio = voiceCreatePreviewRef.value
    if (audio) {
        audio.pause()
        audio.currentTime = 0
    }
    isVoiceCreatePreviewPlaying.value = false
}

const getVoiceCreateMimeTypeFromFile = (file: File) => file.type.trim().toLowerCase()

const getVoiceCreateFileNameExtension = (fileName: string) => {
    const match = fileName.toLowerCase().match(/\.([a-z0-9]+)$/i)
    return match?.[1] || ''
}

const isSupportedVoiceCreateAudioFile = (file: File) => {
    const mimeType = getVoiceCreateMimeTypeFromFile(file)
    const extension = getVoiceCreateFileNameExtension(file.name)

    if (voiceCreateAllowedAudioExtensions.includes(extension)) return true
    if (!mimeType) return false

    return (
        /^audio\/(mpeg|mp3|wav|x-wav|wave|mp4|x-m4a|aac|ogg)/i.test(mimeType) ||
        /^audio\/aacp/i.test(mimeType)
    )
}

const readVoiceCreateDuration = (url: string) =>
    new Promise<number>((resolve) => {
        if (!isClientRuntime()) {
            resolve(0)
            return
        }

        const audio = new Audio()
        const clear = () => {
            audio.onloadedmetadata = null
            audio.onerror = null
        }

        audio.preload = 'metadata'
        audio.onloadedmetadata = () => {
            const duration = Number.isFinite(audio.duration) ? audio.duration : 0
            clear()
            resolve(duration > 0 ? duration : 0)
        }
        audio.onerror = () => {
            clear()
            resolve(0)
        }
        audio.src = url
        audio.load()
    })

const decodeAudioFile = async (file: File) => {
    const AudioContextCtor = window.AudioContext || (window as any).webkitAudioContext
    if (!AudioContextCtor) throw new Error('当前浏览器不支持音频裁剪')
    const context = new AudioContextCtor()
    try {
        const buffer = await file.arrayBuffer()
        return await context.decodeAudioData(buffer.slice(0))
    } finally {
        await context.close()
    }
}

const encodeWavFromChannels = (channels: Float32Array[], sampleRate: number) => {
    const channelCount = Math.max(1, Math.min(2, channels.length))
    const frameCount = channels[0]?.length || 0
    const bytesPerSample = 2
    const blockAlign = channelCount * bytesPerSample
    const buffer = new ArrayBuffer(44 + frameCount * blockAlign)
    const view = new DataView(buffer)
    const writeString = (offset: number, value: string) => {
        for (let i = 0; i < value.length; i += 1) view.setUint8(offset + i, value.charCodeAt(i))
    }

    writeString(0, 'RIFF')
    view.setUint32(4, 36 + frameCount * blockAlign, true)
    writeString(8, 'WAVE')
    writeString(12, 'fmt ')
    view.setUint32(16, 16, true)
    view.setUint16(20, 1, true)
    view.setUint16(22, channelCount, true)
    view.setUint32(24, sampleRate, true)
    view.setUint32(28, sampleRate * blockAlign, true)
    view.setUint16(32, blockAlign, true)
    view.setUint16(34, bytesPerSample * 8, true)
    writeString(36, 'data')
    view.setUint32(40, frameCount * blockAlign, true)

    let offset = 44
    for (let i = 0; i < frameCount; i += 1) {
        for (let channel = 0; channel < channelCount; channel += 1) {
            const sample = Math.max(-1, Math.min(1, channels[channel]?.[i] || 0))
            view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true)
            offset += bytesPerSample
        }
    }

    return new Blob([view], { type: 'audio/wav' })
}

const trimVoiceAudioFile = async (file: File, start: number, duration: number) => {
    const audioBuffer = await decodeAudioFile(file)
    const sampleRate = audioBuffer.sampleRate
    const startFrame = Math.max(0, Math.floor(start * sampleRate))
    const frameCount = Math.min(
        Math.floor(duration * sampleRate),
        Math.max(0, audioBuffer.length - startFrame)
    )
    if (frameCount <= 0) throw new Error('裁剪片段无有效音频')

    const channels = Array.from({ length: Math.min(2, audioBuffer.numberOfChannels) }, (_, index) =>
        audioBuffer.getChannelData(index).slice(startFrame, startFrame + frameCount)
    )
    const blob = encodeWavFromChannels(channels.length ? channels : [new Float32Array(frameCount)], sampleRate)
    const baseName = getFileBaseName(file.name) || 'voice'
    return new File([blob], `${baseName}-trim-10s.wav`, { type: 'audio/wav' })
}

const syncVoiceCreateSampleDuration = async (url: string, fallbackDuration = 0) => {
    if (fallbackDuration > 0) {
        if (fallbackDuration > voiceCreateMaxDuration) {
            feedback.msgError(texts.voiceSampleTooLong)
            clearVoiceCreateRecordedResult()
            return
        }
        voiceCreateElapsed.value = fallbackDuration
        return
    }

    const duration = await readVoiceCreateDuration(url)
    if (voiceCreateRecordedUrl.value === url && voiceCreateStep.value === 'sample_ready' && duration > 0) {
        if (duration > voiceCreateMaxDuration) {
            feedback.msgError(texts.voiceSampleTooLong)
            clearVoiceCreateRecordedResult()
            return
        }
        voiceCreateElapsed.value = duration
    }
}

const setVoiceCreateSample = (
    file: File,
    url: string,
    source: VoiceCreateSampleSource,
    options: { mimeType?: string; duration?: number } = {}
) => {
    releaseVoiceCreateTempUrl(voiceCreateRecordedUrl.value)
    pendingVoiceUpload.value = {
        file,
        fileName: file.name,
        url,
        blobUrls: [url]
    }
    voiceCreateRecordedUrl.value = url
    voiceCreateRecordedMimeType.value = options.mimeType || file.type || 'audio/wav'
    voiceCreateSampleSource.value = source
    voiceCreateElapsed.value = Math.max(0, Math.round(options.duration || 0))
    voiceCreateStep.value = 'sample_ready'
    void syncVoiceCreateSampleDuration(url, voiceCreateElapsed.value)
}

const prepareVoiceCreateUploadedSample = (
    file: File,
    objectUrl: string,
    duration: number,
    options: { showSuccess?: boolean } = {}
) => {
    const preserveFields = showVoiceCreateModal.value
    openVoiceLibrary('mine')
    stopVoicePreview()
    stopVoiceCreatePreview()

    if (preserveFields) {
        closeVoiceCreateMenus()
        clearVoiceCreateRecordedResult()
    } else {
        discardVoiceCreateTempUrls()
        resetVoiceCreateFields()
    }

    const sampleUrl = rememberVoiceCreateTempUrl(objectUrl)
    if (!preserveFields || !voiceCreateName.value.trim()) {
        voiceCreateName.value = getFileBaseName(file.name) || texts.myVoice
    }
    setVoiceCreateSample(file, sampleUrl, 'upload', { mimeType: file.type, duration })
    showVoiceCreateModal.value = true
    if (options.showSuccess !== false) feedback.msgSuccess(texts.uploadAudioSuccess)
}

const resetVoiceTrimState = () => {
    voiceTrimState.value = {
        visible: false,
        file: null,
        fileName: '',
        url: '',
        duration: 0,
        start: 0,
        processing: false
    }
}

const openVoiceTrimModal = (file: File, objectUrl: string, duration: number) => {
    stopVoicePreview()
    stopVoiceCreatePreview()
    closeVoiceCreateMenus()
    voiceTrimState.value = {
        visible: true,
        file,
        fileName: file.name,
        url: objectUrl,
        duration,
        start: 0,
        processing: false
    }
}

const cancelVoiceTrim = () => {
    stopVoiceTrimPlayback()
    const url = voiceTrimState.value.url
    resetVoiceTrimState()
    if (url) revokeTrackedBlobUrl(url)
}

const stopVoiceTrimPlayback = () => {
    const audio = voiceTrimAudioRef.value
    if (!audio) return
    audio.pause()
}

const clampVoiceTrimStart = (start: number) => Math.max(0, Math.min(voiceTrimMaxStart.value, start))

const setVoiceTrimStartFromPointer = (clientX: number) => {
    const track = voiceTrimTrackRef.value
    if (!track) return
    const rect = track.getBoundingClientRect()
    const ratio = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width))
    const centeredStart = ratio * voiceTrimState.value.duration - voiceCreateMaxDuration / 2
    voiceTrimState.value.start = clampVoiceTrimStart(centeredStart)
}

const handleVoiceTrimTrackPointerDown = (event: PointerEvent) => {
    setVoiceTrimStartFromPointer(event.clientX)
    handleVoiceTrimWindowPointerDown(event)
}

const handleVoiceTrimWindowPointerMove = (event: PointerEvent) => {
    if (!voiceTrimDrag || !voiceTrimTrackRef.value) return
    const rect = voiceTrimTrackRef.value.getBoundingClientRect()
    const deltaSeconds = ((event.clientX - voiceTrimDrag.startX) / rect.width) * voiceTrimState.value.duration
    voiceTrimState.value.start = clampVoiceTrimStart(voiceTrimDrag.initialStart + deltaSeconds)
}

const stopVoiceTrimWindowDrag = () => {
    voiceTrimDrag = null
    window.removeEventListener('pointermove', handleVoiceTrimWindowPointerMove)
    window.removeEventListener('pointerup', stopVoiceTrimWindowDrag)
    window.removeEventListener('pointercancel', stopVoiceTrimWindowDrag)
}

const handleVoiceTrimWindowPointerDown = (event: PointerEvent) => {
    voiceTrimDrag = {
        startX: event.clientX,
        initialStart: voiceTrimState.value.start
    }
    window.addEventListener('pointermove', handleVoiceTrimWindowPointerMove)
    window.addEventListener('pointerup', stopVoiceTrimWindowDrag)
    window.addEventListener('pointercancel', stopVoiceTrimWindowDrag)
}

const previewVoiceTrimSelection = async () => {
    const audio = voiceTrimAudioRef.value
    if (!audio || !voiceTrimState.value.url) return

    try {
        audio.currentTime = voiceTrimState.value.start
        await audio.play()
        const stopAt = voiceTrimEnd.value
        const stopWhenEnded = () => {
            if (audio.currentTime < stopAt) return
            audio.pause()
            audio.removeEventListener('timeupdate', stopWhenEnded)
        }
        audio.addEventListener('timeupdate', stopWhenEnded)
    } catch (_error) {
        feedback.msgError(texts.samplePreviewUnavailable)
    }
}

const confirmVoiceTrim = async () => {
    const state = voiceTrimState.value
    if (!state.file || state.processing) return

    state.processing = true
    stopVoiceTrimPlayback()
    try {
        const trimmedFile = await trimVoiceAudioFile(state.file, state.start, voiceCreateMaxDuration)
        const trimmedUrl = trackBlobUrl(URL.createObjectURL(trimmedFile))
        const originalUrl = state.url
        resetVoiceTrimState()
        if (originalUrl) revokeTrackedBlobUrl(originalUrl)
        prepareVoiceCreateUploadedSample(trimmedFile, trimmedUrl, voiceCreateMaxDuration)
    } catch (error: any) {
        feedback.msgError(error?.message || '音频裁剪失败，请更换音频后重试')
        if (voiceTrimState.value.visible) voiceTrimState.value.processing = false
    }
}

const createVoiceFromPending = async () => {
    if (!ensureDigitalHumanLogin()) return null
    if (!pendingVoiceUpload.value) {
        feedback.msgError('\u8bf7\u5f55\u5236\u6216\u4e0a\u4f20\u771f\u5b9e\u97f3\u9891\u6587\u4ef6')
        return null
    }
    if (isVoiceCreateSaving.value) return null

    const pending = pendingVoiceUpload.value
    const sampleDuration = voiceCreateElapsed.value > 0 ? Math.ceil(voiceCreateElapsed.value) : await readVoiceCreateDuration(pending.url)
    if (sampleDuration > voiceCreateMaxDuration) {
        feedback.msgError(texts.voiceSampleTooLong)
        return null
    }
    if (sampleDuration > 0) voiceCreateElapsed.value = sampleDuration
    isCreating.value = true
    isVoiceCreateSaving.value = true
    voiceCreateStep.value = 'saving'
    try {
        const uploadRes = pending.remoteUri ? { uri: pending.remoteUri } : await uploadFile({ file: pending.file })
        const audioUri = pending.remoteUri || pickUploadUri(uploadRes)
        if (!audioUri) throw new Error('\u97f3\u9891\u4e0a\u4f20\u5931\u8d25')
        let coverUri = ''
        const coverFile = pending.coverFile || voiceCreateCoverFile.value
        if (coverFile) {
            const coverUploadRes = await uploadImage({ file: coverFile })
            coverUri = pickUploadUri(coverUploadRes)
            if (!coverUri) throw new Error('\u5c01\u9762\u4e0a\u4f20\u5931\u8d25')
        }
        const row = await saveAigcDigitalHumanVoice({
            name: voiceCreateName.value.trim() || getFileBaseName(pending.fileName) || texts.myVoice,
            audio_uri: audioUri,
            cover_uri: coverUri,
            duration: sampleDuration > 0 ? sampleDuration : undefined,
            gender: voiceCreateGender.value !== texts.voiceGender ? voiceCreateGender.value : undefined,
            age_group: voiceCreateAge.value !== texts.voiceAge ? voiceCreateAge.value : undefined
        })
        const item = mapVoiceRow(row)
        if (!item.providerAssetId) item.previewUrl = item.previewUrl || pending.url
        if (!item.cover && voiceCreateCover.value) item.cover = voiceCreateCover.value
        mineVoices.value = [item, ...mineVoices.value.filter((voice) => voice.id !== item.id)]
        openVoiceLibrary('mine')
        applyVoiceToEditor(item)
        activePopover.value = 'notice'
        feedback.msgSuccess('\u97f3\u8272\u5df2\u521b\u5efa')
        return item
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '\u97f3\u8272\u521b\u5efa\u5931\u8d25')
        if (pendingVoiceUpload.value) voiceCreateStep.value = 'sample_ready'
        return null
    } finally {
        isCreating.value = false
        isVoiceCreateSaving.value = false
    }
}

const closeVoiceCreateMenus = () => {
    voiceCreateGenderMenuOpen.value = false
    voiceCreateAgeMenuOpen.value = false
}

const toggleVoiceCreateGenderMenu = () => {
    voiceCreateGenderMenuOpen.value = !voiceCreateGenderMenuOpen.value
    if (voiceCreateGenderMenuOpen.value) voiceCreateAgeMenuOpen.value = false
}

const toggleVoiceCreateAgeMenu = () => {
    voiceCreateAgeMenuOpen.value = !voiceCreateAgeMenuOpen.value
    if (voiceCreateAgeMenuOpen.value) voiceCreateGenderMenuOpen.value = false
}

const setVoiceCreateGender = (value: string) => {
    voiceCreateGender.value = value
    voiceCreateGenderMenuOpen.value = false
}

const setVoiceCreateAge = (value: string) => {
    voiceCreateAge.value = value
    voiceCreateAgeMenuOpen.value = false
}

const triggerVoiceCoverUpload = () => voiceCoverInputRef.value?.click()

const resetVoiceCreateFields = () => {
    voiceCreateName.value = ''
    voiceCreateGender.value = texts.voiceGender
    voiceCreateAge.value = texts.voiceAge
    voiceCreateCover.value = ''
    voiceCreateCoverFile.value = null
    pendingVoiceUpload.value = null
    voiceCreateStep.value = 'idle'
    voiceCreateElapsed.value = 0
    voiceCreateRecordedUrl.value = ''
    voiceCreateRecordedMimeType.value = ''
    voiceCreateSampleSource.value = ''
    closeVoiceCreateMenus()
    stopVoiceCreatePreview()
}

const clearVoiceCreateRecordedResult = () => {
    stopVoiceCreatePreview()
    releaseVoiceCreateTempUrl(voiceCreateRecordedUrl.value)
    voiceCreateRecordedUrl.value = ''
    voiceCreateRecordedMimeType.value = ''
    pendingVoiceUpload.value = null
    voiceCreateSampleSource.value = ''
    voiceCreateElapsed.value = 0
    voiceCreateStep.value = 'idle'
}

const cancelVoiceCreateRecording = () => {
    if (voiceCreateStep.value !== 'recording') return
    voiceCreateShouldDiscard = true
    clearVoiceCreateTimer()

    if (voiceCreateRecorder && voiceCreateRecorder.state !== 'inactive') {
        voiceCreateRecorder.stop()
        return
    }

    stopVoiceCreateStream()
    voiceCreateStep.value = 'idle'
    voiceCreateElapsed.value = 0
}

const closeVoiceCreateModal = (options: { preserveAssets?: boolean } = {}) => {
    showVoiceCreateModal.value = false
    cancelVoiceCreateRecording()
    stopVoiceCreatePreview()

    if (options.preserveAssets) {
        voiceCreateTempUrls.value = []
    } else {
        discardVoiceCreateTempUrls()
    }
    cancelVoiceTrim()

    resetVoiceCreateFields()
}

const openVoiceCreateModal = () => {
    openVoiceLibrary('mine')
    stopVoicePreview()
    stopVoiceCreatePreview()
    closeVoiceCreateMenus()
    resetVoiceCreateFields()
    showVoiceCreateModal.value = true
}

const handleVoiceCoverUpload = (event: Event) => {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return

    if (voiceCreateCover.value.startsWith('blob:')) {
        releaseVoiceCreateTempUrl(voiceCreateCover.value)
    }

    voiceCreateCover.value = rememberVoiceCreateTempUrl(trackBlobUrl(URL.createObjectURL(file)))
    voiceCreateCoverFile.value = file
    if (pendingVoiceUpload.value) {
        pendingVoiceUpload.value.coverFile = file
        pendingVoiceUpload.value.coverUrl = voiceCreateCover.value
    }
    input.value = ''
}

const startVoiceCreateTimer = () => {
    clearVoiceCreateTimer()
    voiceCreateTimer = window.setInterval(() => {
        voiceCreateElapsed.value += 1
        if (voiceCreateElapsed.value >= voiceCreateMaxDuration) stopVoiceCreateRecording()
    }, 1000)
}

const getVoiceCreateRecordErrorMessage = (error: unknown) => {
    const err = error as DOMException | Error | null
    if (err && 'name' in err) {
        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
            return texts.recordPermissionDenied
        }
        if (err.name === 'NotReadableError' || err.name === 'AbortError') {
            return texts.recordStoppedUnexpectedly
        }
    }
    return voiceCreateRecordUnavailableReason.value || texts.recordUnavailable
}

const beginVoiceCreateRecording = async () => {
    if (!canRecordVoiceCreate.value) {
        feedback.msgError(voiceCreateRecordUnavailableReason.value)
        return
    }

    stopVoicePreview()
    stopVoiceCreatePreview()
    closeVoiceCreateMenus()
    clearVoiceCreateRecordedResult()
    voiceCreateStep.value = 'recording'
    voiceCreateShouldDiscard = false

    try {
        voiceCreateStream = await navigator.mediaDevices.getUserMedia({ audio: true })
        voiceCreateChunks = []
        const recorder = new MediaRecorder(voiceCreateStream)
        const mimeType = recorder.mimeType

        recorder.ondataavailable = (event) => {
            if (event.data?.size) voiceCreateChunks.push(event.data)
        }

        recorder.onstop = async () => {
            clearVoiceCreateTimer()
            stopVoiceCreateStream()

            const shouldDiscard = voiceCreateShouldDiscard
            const chunks = [...voiceCreateChunks]
            voiceCreateChunks = []
            voiceCreateShouldDiscard = false

            if (shouldDiscard) {
                voiceCreateStep.value = 'idle'
                voiceCreateElapsed.value = 0
                return
            }

            const blobType = mimeType || chunks[0]?.type || 'audio/webm'
            const blob = chunks.length ? new Blob(chunks, { type: blobType }) : null

            if (blob && blob.size) {
                const name = voiceCreateName.value.trim() || `\u6211\u7684\u58f0\u97f3${mineVoices.value.length + 1}`
                try {
                    const audioFile = new File([blob], `${name}.wav`, { type: blob.type || blobType })
                    const file = await normalizeRecordedVoiceFile(audioFile)
                    const url = rememberVoiceCreateTempUrl(trackBlobUrl(URL.createObjectURL(file)))
                    const duration = Math.min(voiceCreateMaxDuration, voiceCreateElapsed.value > 0 ? voiceCreateElapsed.value : 1)
                    setVoiceCreateSample(file, url, 'record', { mimeType: file.type || 'audio/wav', duration })
                    feedback.msgSuccess(texts.recordAudioSuccess)
                } catch (error: any) {
                    clearVoiceCreateRecordedResult()
                    feedback.msgError(error?.message || '录音转换失败，请更换浏览器后重试')
                }
                return
            }

            clearVoiceCreateRecordedResult()
            feedback.msgError(texts.recordEmpty)
        }

        voiceCreateRecorder = recorder
        recorder.start()
        startVoiceCreateTimer()
    } catch (error) {
        clearVoiceCreateTimer()
        stopVoiceCreateStream()
        voiceCreateStep.value = 'idle'
        voiceCreateElapsed.value = 0
        feedback.msgError(getVoiceCreateRecordErrorMessage(error))
    }
}

const stopVoiceCreateRecording = () => {
    if (voiceCreateStep.value !== 'recording') return

    if (voiceCreateRecorder && voiceCreateRecorder.state !== 'inactive') {
        voiceCreateRecorder.stop()
        return
    }

    clearVoiceCreateTimer()
    stopVoiceCreateStream()
    voiceCreateStep.value = 'idle'
    voiceCreateElapsed.value = 0
}

const toggleVoiceCreatePreview = async () => {
    const previewUrl = voiceCreateRecordedUrl.value || pendingVoiceUpload.value?.url || ''
    if (!isVoiceCreateSampleReady.value || !previewUrl || isVoiceCreateBusy.value) return

    if (isVoiceCreatePreviewPlaying.value) {
        stopVoiceCreatePreview()
        return
    }

    stopVoicePreview()
    stopVoiceCreatePreview()

    if (isClientRuntime()) {
        const audio = voiceCreatePreviewRef.value || new Audio()
        voiceCreatePreviewAudio = audio
        isVoiceCreatePreviewPlaying.value = true
        audio.onended = () => {
            if (voiceCreatePreviewAudio === audio) voiceCreatePreviewAudio = null
            isVoiceCreatePreviewPlaying.value = false
        }
        audio.onerror = () => {
            if (voiceCreatePreviewAudio === audio) voiceCreatePreviewAudio = null
            isVoiceCreatePreviewPlaying.value = false
            feedback.msgError(texts.samplePreviewUnavailable)
        }

        try {
            audio.preload = 'auto'
            if (audio.src !== previewUrl) {
                audio.src = previewUrl
                audio.load()
            }
            audio.currentTime = 0
            await audio.play()
            return
        } catch (_error) {
            if (voiceCreatePreviewAudio === audio) voiceCreatePreviewAudio = null
            isVoiceCreatePreviewPlaying.value = false
            feedback.msgError(texts.samplePreviewUnavailable)
            return
        }
    }
}

const handleVoiceCreateRecordAction = async () => {
    if (voiceCreateStep.value === 'recording') {
        stopVoiceCreateRecording()
        return
    }

    await beginVoiceCreateRecording()
}

const normalizeRecordedVoiceFile = async (file: File) => {
    const audioBuffer = await decodeAudioFile(file)
    const channels = Array.from({ length: Math.min(2, audioBuffer.numberOfChannels) }, (_, index) =>
        audioBuffer.getChannelData(index).slice(0)
    )
    const blob = encodeWavFromChannels(channels.length ? channels : [new Float32Array(audioBuffer.length)], audioBuffer.sampleRate)
    return new File([blob], `${getFileBaseName(file.name) || 'record'}.wav`, { type: 'audio/wav' })
}

const saveVoiceCreateModal = async () => {
    if (!canSaveVoiceCreate.value || isVoiceCreateBusy.value) return

    stopVoiceCreatePreview()

    const item = await createVoiceFromPending()
    if (item) closeVoiceCreateModal({ preserveAssets: true })
}

const pauseAvatarModalPreview = () => {
    const video = avatarModalVideoRef.value
    if (video) {
        video.pause()
        video.currentTime = 0
    }
    isAvatarModalVideoPlaying.value = false
}

const resetAvatarCreateFields = () => {
    avatarCreateName.value = ''
    avatarCreateScene.value = '\u4ea7\u54c1\u4ecb\u7ecd'
    avatarSceneMenuOpen.value = false
    avatarCreateCover.value = ''
    avatarEditingId.value = ''
    pauseAvatarModalPreview()
}

const discardPendingAvatarUpload = () => {
    if (!pendingAvatarUpload.value) return
    pendingAvatarUpload.value.blobUrls.forEach((url) => revokeTrackedBlobUrl(url))
    pendingAvatarUpload.value = null
}

const closeAvatarCreateModal = (options: { preserveUpload?: boolean } = {}) => {
    showAvatarCreateModal.value = false
    pauseAvatarModalPreview()
    if (options.preserveUpload) {
        pendingAvatarUpload.value = null
    } else {
        discardPendingAvatarUpload()
    }
    resetAvatarCreateFields()
}

const createVideoThumbnail = (videoUrl: string) =>
    new Promise<string | null>((resolve) => {
        if (!isClientRuntime()) {
            resolve(null)
            return
        }

        const video = document.createElement('video')
        video.preload = 'metadata'
        video.src = videoUrl
        video.muted = true
        video.playsInline = true
        let settled = false

        const cleanup = () => {
            video.onloadeddata = null
            video.onseeked = null
            video.onerror = null
            video.pause()
            video.removeAttribute('src')
            video.load()
        }

        const finish = (value: string | null) => {
            if (settled) return
            settled = true
            cleanup()
            resolve(value)
        }

        const drawFrame = () => {
            try {
                const canvas = document.createElement('canvas')
                canvas.width = video.videoWidth || 148
                canvas.height = video.videoHeight || 234
                const context = canvas.getContext('2d')
                if (!context) {
                    finish(null)
                    return
                }
                context.drawImage(video, 0, 0, canvas.width, canvas.height)
                finish(canvas.toDataURL('image/jpeg', 0.92))
            } catch (_error) {
                finish(null)
            }
        }

        video.onloadeddata = () => {
            try {
                const seekTarget = Number.isFinite(video.duration) && video.duration > 0.15 ? 0.1 : 0
                if (seekTarget === 0) {
                    drawFrame()
                    return
                }
                video.currentTime = seekTarget
            } catch (_error) {
                drawFrame()
            }
        }

        video.onseeked = drawFrame
        video.onerror = () => finish(null)
    })

const triggerUpload = () => {
    if (!ensureDigitalHumanLogin()) return
    fileInputRef.value?.click()
}
const triggerCreateAvatarUpload = () => {
    if (!ensureDigitalHumanLogin()) return
    createAvatarInputRef.value?.click()
}
const triggerAvatarCoverUpload = () => avatarCoverInputRef.value?.click()
const triggerDriverAudioUpload = () => {
    if (!ensureDigitalHumanLogin()) return
    driverAudioInputRef.value?.click()
}
const closeAvatarCardMenu = () => {
    avatarCardMenuId.value = ''
}
const closeFloatingMenus = () => {
    closeAvatarCardMenu()
    translateMenuOpen.value = false
}
const toggleAvatarCardMenu = (avatarId: string) => {
    avatarCardMenuId.value = avatarCardMenuId.value === avatarId ? '' : avatarId
}
const toggleAvatarSceneMenu = () => {
    avatarSceneMenuOpen.value = !avatarSceneMenuOpen.value
}
const setAvatarCreateScene = (value: string) => {
    avatarCreateScene.value = value
    avatarSceneMenuOpen.value = false
}

const toggleAvatarModalPlayback = async () => {
    const video = avatarModalVideoRef.value
    if (!video) return

    if (isAvatarModalVideoPlaying.value) {
        pauseAvatarModalPreview()
        return
    }

    try {
        await video.play()
        isAvatarModalVideoPlaying.value = true
    } catch (_error) {
        isAvatarModalVideoPlaying.value = false
    }
}

const stopAvatarMotion = (avatarId?: string) => {
    if (!avatarId || previewingAvatarId.value === avatarId) previewingAvatarId.value = ''
}

const clearAppliedAvatar = () => {
    appliedAvatarId.value = ''
}

const selectVoiceItem = (item: VoiceItem) => {
    selectedVoiceCardId.value = item.id
    selectedVoice.value = item.name
}

const clearAppliedVoice = () => {
    if (appliedVoiceId.value && playingVoiceId.value === appliedVoiceId.value) {
        stopVoicePreview(appliedVoiceId.value)
    }
    appliedVoiceId.value = ''
}

const clearDriverAudio = () => {
    if (driverAudio.value?.url) revokeTrackedBlobUrl(driverAudio.value.url)
    driverAudio.value = null
}

const getAppliedVoiceDisplayName = (item: VoiceItem) => {
    const rawName = item.fileName || item.name
    const extensionIndex = rawName.lastIndexOf('.')
    const baseName = extensionIndex > 0 ? rawName.slice(0, extensionIndex) : rawName
    const extension = extensionIndex > 0 ? rawName.slice(extensionIndex) : ''
    const chars = Array.from(baseName)

    if (chars.length > 6) {
        return `${chars.slice(0, 6).join('')}...`
    }

    return `${baseName}${extension}`
}

const getVoiceFavoriteSymbol = (starred?: boolean) => (starred ? '\u2605' : '\u2606')

const toggleVoiceStar = (item: VoiceItem) => {
    item.starred = !item.starred
}

const clearVoicePreviewState = (voiceId?: string) => {
    if (!voiceId || playingVoiceId.value === voiceId) playingVoiceId.value = ''
    if (!voiceId || voicePreviewLoadingId.value === voiceId) voicePreviewLoadingId.value = ''
}

const voiceAudioElements = () =>
    isClientRuntime()
        ? Array.from(document.querySelectorAll<HTMLAudioElement>('[data-voice-audio]'))
        : []

const voiceAudioElement = (item: VoiceItem) =>
    voiceAudioElements().find((audio) => audio.dataset.voiceAudio === item.id) || voicePreviewRef.value

const stopVoicePreview = (voiceId?: string) => {
    if (previewAudio) {
        previewAudio.pause()
        previewAudio.currentTime = 0
        previewAudio = null
    }
    const audio = voicePreviewRef.value
    if (audio) {
        audio.pause()
        audio.currentTime = 0
    }
    voiceAudioElements().forEach((item) => {
        item.pause()
        item.currentTime = 0
    })

    if (isClientRuntime() && window.speechSynthesis) {
        window.speechSynthesis.cancel()
    }
    previewSpeech = null
    clearVoicePreviewState(voiceId)
}

const getVoicePreviewText = (item: VoiceItem) =>
    `\u6b22\u8fce\u4f7f\u7528 A. PART \u58f0\u97f3\u5e93\uff0c\u5f53\u524d\u8bd5\u542c\u58f0\u97f3\u4e3a${item.name}\u3002`

const getSpeechVoice = () => {
    if (!isClientRuntime() || !window.speechSynthesis) return null
    const voices = window.speechSynthesis.getVoices()
    return (
        voices.find((voice) => /zh|cmn/i.test(voice.lang) || /中|华|普通话|中文/i.test(voice.name)) ??
        voices[0] ??
        null
    )
}

const playSpeechPreview = (item: VoiceItem) => {
    if (!isClientRuntime() || !window.speechSynthesis) return
    const utterance = new SpeechSynthesisUtterance(getVoicePreviewText(item))
    const voice = getSpeechVoice()
    const voiceIndex = Math.max(
        officialVoices.value.findIndex((voiceItem) => voiceItem.id === item.id),
        0
    )
    if (voice) utterance.voice = voice
    utterance.lang = voice?.lang || 'zh-CN'
    utterance.rate = 0.96 + (voiceIndex % 4) * 0.06
    utterance.pitch = 0.94 + (voiceIndex % 3) * 0.08
    utterance.onend = () => clearVoicePreviewState(item.id)
    utterance.onerror = () => clearVoicePreviewState(item.id)
    previewSpeech = utterance
    window.speechSynthesis.speak(utterance)
}

const playAudioPreview = async (item: VoiceItem, url = item.previewUrl) => {
    if (!isClientRuntime() || !url) return false
    const audio = voiceAudioElement(item) || new Audio()
    previewAudio = audio
    audio.onended = () => {
        if (previewAudio === audio) previewAudio = null
        clearVoicePreviewState(item.id)
    }
    audio.onerror = () => {
        if (previewAudio === audio) previewAudio = null
        clearVoicePreviewState(item.id)
    }
    try {
        if (audio.src !== url) {
            audio.src = url
            audio.load()
        }
        audio.currentTime = 0
        await audio.play()
        return true
    } catch (_error) {
        if (previewAudio === audio) previewAudio = null
        clearVoicePreviewState(item.id)
        return false
    }
}

const toggleVoicePreview = async (item: VoiceItem) => {
    selectVoiceItem(item)
    if (playingVoiceId.value === item.id || voicePreviewLoadingId.value === item.id) {
        voicePreviewLoadingId.value = ''
        stopVoicePreview(item.id)
        return
    }

    stopVoiceCreatePreview()
    stopVoicePreview()
    playingVoiceId.value = item.id

    if (item.previewUrl) {
        const played = await playAudioPreview(item)
        if (played) return
        feedback.msgError('\u8bd5\u542c\u64ad\u653e\u5931\u8d25')
        return
    }

    if (item.providerAssetId && item.source !== 'official') {
        voicePreviewLoadingId.value = item.id
        try {
            const result = item.synthesizedPreviewUrl
                ? { audio_url: item.synthesizedPreviewUrl }
                : await previewAigcDigitalHumanVoice({
                      voice_id: item.rawId || item.id,
                      text: getVoicePreviewText(item)
                  })
            const audioUrl = result?.audio_url || ''
            if (!audioUrl) throw new Error('\u8bd5\u542c\u5408\u6210\u672a\u8fd4\u56de\u97f3\u9891')
            item.synthesizedPreviewUrl = audioUrl
            item.previewUrl = audioUrl
            if (playingVoiceId.value !== item.id) return
            const played = await playAudioPreview(item, audioUrl)
            if (!played) {
                feedback.msgSuccess('\u8bd5\u542c\u97f3\u9891\u5df2\u751f\u6210\uff0c\u8bf7\u518d\u70b9\u51fb\u4e00\u6b21\u64ad\u653e')
            }
        } catch (error: any) {
            if (playingVoiceId.value === item.id) clearVoicePreviewState(item.id)
            feedback.msgError(error?.msg || error?.message || error || '\u97f3\u8272\u8bd5\u542c\u5931\u8d25')
        } finally {
            if (voicePreviewLoadingId.value === item.id) voicePreviewLoadingId.value = ''
        }
        return
    }

    playSpeechPreview(item)
}

const applyVoiceToEditor = (item: VoiceItem) => {
    clearDriverAudio()
    selectVoiceItem(item)
    appliedVoiceId.value = item.id
}

const applyAvatarToEditor = (item: AvatarItem) => {
    closeAvatarCardMenu()
    selectAvatar(item)
    appliedAvatarId.value = item.id
}

const handleCreateAvatarUpload = async (event: Event) => {
    if (!ensureDigitalHumanLogin()) return
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return

    const preserveFields = showAvatarCreateModal.value
    const previousName = avatarCreateName.value
    const previousScene = avatarCreateScene.value
    const previousCover = avatarCreateCover.value
    const previousBlobUrls = pendingAvatarUpload.value?.blobUrls ?? []

    discardPendingAvatarUpload()
    if (!preserveFields) {
        resetAvatarCreateFields()
    } else {
        pauseAvatarModalPreview()
        avatarSceneMenuOpen.value = false
        if (previousBlobUrls.includes(previousCover)) avatarCreateCover.value = ''
    }

    const objectUrl = trackBlobUrl(URL.createObjectURL(file))
    const mediaType: AvatarMediaType = file.type.startsWith('video/') ? 'video' : 'image'
    const previewImage = mediaType === 'video' ? (await createVideoThumbnail(objectUrl)) || card2 : objectUrl

    pendingAvatarUpload.value = {
        file,
        fileName: file.name,
        mediaType,
        url: objectUrl,
        previewImage,
        blobUrls: [objectUrl]
    }

    avatarCreateName.value = preserveFields ? previousName : getFileBaseName(file.name) || texts.createMineAvatar
    avatarCreateScene.value = preserveFields ? previousScene : '\u4ea7\u54c1\u4ecb\u7ecd'
    avatarCreateCover.value = preserveFields && previousCover && !previousBlobUrls.includes(previousCover)
        ? previousCover
        : previewImage
    showAvatarCreateModal.value = true

    if (mediaType === 'video') {
        await nextTick()
        pauseAvatarModalPreview()
    }

    feedback.msgSuccess('上传完成，请确认信息后保存形象')

    input.value = ''
}

const handleAvatarCoverUpload = (event: Event) => {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file || !pendingAvatarUpload.value) return

    const previousCover = avatarCreateCover.value
    if (previousCover.startsWith('blob:') && previousCover !== pendingAvatarUpload.value.url) {
        pendingAvatarUpload.value.blobUrls = pendingAvatarUpload.value.blobUrls.filter((item) => item !== previousCover)
        revokeTrackedBlobUrl(previousCover)
    }

    const objectUrl = trackBlobUrl(URL.createObjectURL(file))
    pendingAvatarUpload.value.blobUrls.push(objectUrl)
    pendingAvatarUpload.value.coverFile = file
    pendingAvatarUpload.value.coverUrl = objectUrl
    avatarCreateCover.value = objectUrl
    input.value = ''
}

const handleUpload = async (event: Event) => {
    if (!ensureDigitalHumanLogin()) return
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return
    if (!file.type.startsWith('video/')) {
        feedback.msgError('\u8bf7\u4e0a\u4f20\u89c6\u9891\u5f62\u8c61')
        input.value = ''
        return
    }

    await handleCreateAvatarUpload(event)
    input.value = ''
}

const handleDriverAudioUpload = async (event: Event) => {
    if (!ensureDigitalHumanLogin()) return
    const input = event.target as HTMLInputElement
    const file = input.files?.[0]
    if (!file) return
    if (!isSupportedVoiceCreateAudioFile(file)) {
        feedback.msgError(texts.unsupportedAudioFormat)
        input.value = ''
        return
    }

    const objectUrl = trackBlobUrl(URL.createObjectURL(file))
    const duration = await readVoiceCreateDuration(objectUrl)
    clearAppliedVoice()
    selectedVoiceCardId.value = ''
    selectedVoice.value = ''
    clearDriverAudio()
    driverAudio.value = {
        file,
        fileName: file.name,
        url: objectUrl,
        duration: duration > 0 ? duration : 1
    }
    feedback.msgSuccess('\u97f3\u9891\u9a71\u52a8\u5df2\u4e0a\u4f20')
    input.value = ''
    await refreshDigitalHumanEstimate()
}

const saveAvatarCreateModal = async () => {
    if (isCreating.value) return
    if (!ensureDigitalHumanLogin()) return
    if (!pendingAvatarUpload.value) return
    if (pendingAvatarUpload.value.mediaType !== 'video') {
        feedback.msgError('\u6570\u5b57\u4eba\u5f62\u8c61\u5fc5\u987b\u4e0a\u4f20\u89c6\u9891')
        return
    }

    const pending = pendingAvatarUpload.value
    isCreating.value = true
    try {
        const uploadRes = pending.remoteUri ? { uri: pending.remoteUri } : await uploadVideo({ file: pending.file })
        const mediaUri = pending.remoteUri || pickUploadUri(uploadRes)
        if (!mediaUri) throw new Error('\u89c6\u9891\u4e0a\u4f20\u5931\u8d25')
        let coverUri = ''
        let coverFile = pending.coverFile
        if (!coverFile && pending.previewImage.startsWith('data:image/')) {
            coverFile = await dataUrlToFile(
                pending.previewImage,
                `${getFileBaseName(pending.fileName) || 'avatar-cover'}.jpg`
            )
        }
        if (coverFile) {
            const coverUploadRes = await uploadImage({ file: coverFile })
            coverUri = pickUploadUri(coverUploadRes)
            if (!coverUri) throw new Error('\u5c01\u9762\u4e0a\u4f20\u5931\u8d25')
        }

        const row = await saveAigcDigitalHumanAvatar({
            name: avatarCreateName.value.trim() || getFileBaseName(pending.fileName) || '\u6211\u7684\u5f62\u8c61',
            cover_uri: coverUri,
            media_uri: mediaUri,
            media_type: 'video',
            scene: avatarCreateScene.value
        })
        const item = mapAvatarRow(row)
        item.image = avatarCreateCover.value || pending.previewImage || item.image
        item.videoUrl = pending.url || item.videoUrl
        mineAvatars.value = [item, ...mineAvatars.value.filter((avatar) => avatar.id !== item.id)]
        openAvatarLibrary('mine')
        applyAvatarToEditor(item)
        activePopover.value = 'notice'
        feedback.msgSuccess('\u5f62\u8c61\u5df2\u521b\u5efa')
        closeAvatarCreateModal({ preserveUpload: true })
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '\u5f62\u8c61\u521b\u5efa\u5931\u8d25')
    } finally {
        isCreating.value = false
    }
}

const openAvatarEditModal = async (item: AvatarItem) => {
    closeAvatarCardMenu()
    discardPendingAvatarUpload()
    showAvatarDeleteModal.value = false
    const mediaType = item.mediaType || (item.videoUrl ? 'video' : 'image')

    pendingAvatarUpload.value = {
        file: item.uploadFile || new File([], item.fileName || `${item.name}.mp4`, { type: mediaType === 'video' ? 'video/mp4' : 'image/png' }),
        remoteUri: item.videoUrl || item.image,
        fileName: item.fileName || `${item.name}.${mediaType === 'video' ? 'mp4' : 'png'}`,
        mediaType,
        url: mediaType === 'video' ? item.videoUrl || item.image : item.image,
        previewImage: item.image,
        blobUrls: []
    }
    avatarEditingId.value = item.id
    avatarCreateName.value = item.name
    avatarCreateScene.value = avatarSceneOptions.includes(item.topic) ? item.topic : '\u4ea7\u54c1\u4ecb\u7ecd'
    avatarCreateCover.value = item.image
    avatarSceneMenuOpen.value = false
    showAvatarCreateModal.value = true

    if (mediaType === 'video') {
        await nextTick()
        pauseAvatarModalPreview()
    }
}

const requestAvatarDelete = (item: AvatarItem) => {
    closeAvatarCardMenu()
    avatarDeleteTargetId.value = item.id
    showAvatarDeleteModal.value = true
}

const closeAvatarDeleteModal = () => {
    showAvatarDeleteModal.value = false
    avatarDeleteTargetId.value = ''
}

const confirmAvatarDelete = () => {
    const targetId = avatarDeleteTargetId.value
    if (!targetId) return

    mineAvatars.value = mineAvatars.value.filter((item) => item.id !== targetId)
    if (selectedAvatar.value?.id === targetId) {
        selectedAvatar.value = mineAvatars.value[0] || officialAvatars.value[0]
    }
    if (appliedAvatarId.value === targetId) {
        appliedAvatarId.value = ''
    }
    if (avatarEditingId.value === targetId) {
        closeAvatarCreateModal()
    }

    closeAvatarDeleteModal()
}

const stopTaskPolling = () => {
    if (!taskPollingTimer) return
    window.clearInterval(taskPollingTimer)
    taskPollingTimer = null
}

const refreshLatestTask = async () => {
    const taskId = latestTask.value?.id || latestTask.value?.task_id
    if (!taskId) return
    try {
        latestTask.value = await getAigcDigitalHumanTask({ id: taskId })
        const status = latestTask.value?.status
        if (!['pending', 'running'].includes(status)) {
            stopTaskPolling()
            const results = await getAigcDigitalHumanResults()
            latestResult.value = (results || []).find((item: any) => item.video_url) || (results || [])[0] || latestResult.value
            if (status === 'success') {
                feedback.msgSuccess('\u6570\u5b57\u4eba\u89c6\u9891\u5408\u6210\u5b8c\u6210')
            } else if (status === 'failed') {
                feedback.msgError(latestTask.value?.error || '\u6570\u5b57\u4eba\u5408\u6210\u5931\u8d25')
            }
        }
    } catch (_error) {
        stopTaskPolling()
    }
}

const startTaskPolling = () => {
    if (!isClientRuntime() || taskPollingTimer) return
    taskPollingTimer = window.setInterval(refreshLatestTask, 3000)
}

const submitAvatarCreate = async () => {
    if (isCreating.value) return
    if (!ensureDigitalHumanLogin()) return
    const avatar = appliedAvatarItem.value || selectedAvatar.value
    const voice = appliedVoiceItem.value || findVoiceItemById(selectedVoiceCardId.value)
    const audioDriver = driverAudio.value

    if (!avatar?.rawId) return feedback.msgError('\u8bf7\u9009\u62e9\u89c6\u9891\u5f62\u8c61')
    if (avatar.mediaType !== 'video') return feedback.msgError('\u8bf7\u9009\u62e9\u53ef\u5408\u6210\u7684\u89c6\u9891\u5f62\u8c61')
    if (!audioDriver && !voice?.rawId) return feedback.msgError('\u8bf7\u9009\u62e9\u97f3\u8272\u6216\u4e0a\u4f20\u97f3\u9891\u9a71\u52a8')
    if (!audioDriver && !voice?.providerAssetId) return feedback.msgError('\u5f53\u524d\u97f3\u8272\u672a\u5b8c\u6210\u514b\u9686\uff0c\u65e0\u6cd5\u5408\u6210')
    if (!audioDriver && !scriptText.value.trim()) return feedback.msgError('\u8bf7\u8f93\u5165\u6587\u6848\u5185\u5bb9')

    const title = workName.value.trim() || `\u6570\u5b57\u4eba-${avatar.name}`
    const script = scriptText.value.trim()
    if (!audioDriver && scriptMaxLength.value && Array.from(script).length > scriptMaxLength.value) {
        return feedback.msgError(`\u6587\u6848\u4e0d\u80fd\u8d85\u8fc7${scriptMaxLength.value}\u4e2a\u5b57`)
    }
    isCreating.value = true
    activePopover.value = 'notice'
    try {
        let driverAudioUri = audioDriver?.remoteUri || ''
        if (audioDriver && !driverAudioUri) {
            const uploadRes = await uploadFile({ file: audioDriver.file })
            driverAudioUri = pickUploadUri(uploadRes)
            if (!driverAudioUri) throw new Error('\u97f3\u9891\u4e0a\u4f20\u5931\u8d25')
            audioDriver.remoteUri = driverAudioUri
        }
        await refreshDigitalHumanEstimate()
        const task = await generateAigcDigitalHuman({
            avatar_id: Number(avatar.rawId),
            voice_id: audioDriver ? 0 : Number(voice?.rawId || 0),
            audio_uri: driverAudioUri,
            title,
            script_text: script,
            prompt: script,
            channel: formOptions.value.channel,
            quality: formOptions.value.quality,
            ratio: formOptions.value.ratio,
            duration: estimatedDuration.value
        })
        latestTask.value = task?.task_id ? { ...task, id: task.task_id, title, progress: 5 } : task
        resetAvatarCreateForm()
        feedback.msgSuccess('\u5df2\u63d0\u4ea4\u5408\u6210\u4efb\u52a1')
        startTaskPolling()
        await router.push({
            path: '/ai/create',
            query: {
                type: 'digital_human',
                status: ''
            }
        })
    } catch (error: any) {
        feedback.msgError(error?.msg || error?.message || error || '\u5408\u6210\u4efb\u52a1\u63d0\u4ea4\u5931\u8d25')
    } finally {
        isCreating.value = false
    }
}

onMounted(() => {
    lockAvatarPageToWorkspaceScroll()
    nextTick(lockAvatarPageToWorkspaceScroll)
    const legacySidebar = resolveAvatarWorkspaceSidebar(route.query.tab)
    if (legacySidebar) {
        router.replace(buildSidebarRouteLocation(legacySidebar)).catch(() => undefined)
    }
    loadDigitalHumanData()
        .then(() => {
            restoreAvatarPageState()
            syncDefaultSelections()
            return loadDigitalHumanTaskForEditing()
        })
        .then(() => {
            if (latestTask.value && ['pending', 'running'].includes(latestTask.value.status)) startTaskPolling()
        })
        .catch((error) => {
            feedback.msgError(error?.msg || error?.message || '\u6570\u5b57\u4eba\u6570\u636e\u52a0\u8f7d\u5931\u8d25')
        })
    document.addEventListener('click', closeFloatingMenus)
    window.addEventListener('beforeunload', handlePageRefresh)
    window.addEventListener('pagehide', handlePageRefresh)
})

onBeforeUnmount(() => {
    saveAvatarPageState()
    unlockAvatarPageScroll()
    document.removeEventListener('click', closeFloatingMenus)
    window.removeEventListener('beforeunload', handlePageRefresh)
    window.removeEventListener('pagehide', handlePageRefresh)
    stopVoicePreview()
    stopTaskPolling()
    cancelVoiceCreateRecording()
    stopVoiceCreatePreview()
    stopVoiceTrimWindowDrag()
    cancelVoiceTrim()
    clearVoiceCreateTimer()
    createdBlobUrls.value.forEach((url) => URL.revokeObjectURL(url))
})
</script>

<style lang="scss" scoped>
:global(html) {
    height: 100%;
    overflow: hidden !important;
}

:global(body) {
    height: 100%;
    overflow: hidden !important;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.avatar-create-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.66);
    backdrop-filter: blur(10px);
}

.avatar-create-modal {
    position: relative;
    width: 600px;
    min-height: 535px;
    padding: 22px 20px 23px;
    border-radius: 12px;
    background: #222;
    box-sizing: border-box;
}

.avatar-create-modal__close {
    position: absolute;
    top: 16px;
    right: 20px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: transparent;
    cursor: pointer;
}

.avatar-create-modal__close::before,
.avatar-create-modal__close::after {
    content: '';
    position: absolute;
    top: 13px;
    left: 8px;
    width: 12px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
}

.avatar-create-modal__close::before {
    transform: rotate(45deg);
}

.avatar-create-modal__close::after {
    transform: rotate(-45deg);
}

.avatar-create-modal__title {
    margin: 0;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}

.avatar-create-modal__hero {
    display: flex;
    align-items: flex-start;
    gap: 21px;
    margin-top: 24px;
}

.avatar-create-modal__preview {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 382px;
    height: 234px;
    border-radius: 8px;
    background: #000;
    overflow: hidden;
}

.avatar-create-modal__switch {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 3;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.52);
    backdrop-filter: blur(8px);
    cursor: pointer;
}

.avatar-create-modal__switch img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.avatar-create-modal__media {
    width: 148px;
    height: 234px;
    object-fit: cover;
}

.avatar-create-modal__media--video {
    background: #000;
}

.avatar-create-modal__play {
    position: absolute;
    top: 50%;
    left: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 52px;
    height: 52px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.5);
    transform: translate(-50%, -50%);
    cursor: pointer;
}

.avatar-create-modal__play.is-hidden {
    opacity: 0;
    pointer-events: none;
}

.avatar-create-modal__play-icon {
    width: 0;
    height: 0;
    margin-left: 4px;
    border-top: 9px solid transparent;
    border-bottom: 9px solid transparent;
    border-left: 14px solid #fff;
}

.avatar-create-modal__pause-icon {
    position: relative;
    width: 14px;
    height: 14px;
}

.avatar-create-modal__pause-icon::before,
.avatar-create-modal__pause-icon::after {
    content: '';
    position: absolute;
    top: 0;
    width: 4px;
    height: 14px;
    border-radius: 999px;
    background: #fff;
}

.avatar-create-modal__pause-icon::before {
    left: 1px;
}

.avatar-create-modal__pause-icon::after {
    right: 1px;
}

.avatar-create-modal__requirements {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding-top: 1px;
    color: #a1a1a1;
}

.avatar-create-modal__requirements strong {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
}

.avatar-create-modal__requirements p {
    margin: 0;
    font-size: 12px;
    line-height: 1;
}

.avatar-create-modal__form {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 20px;
    margin-top: 32px;
}

.avatar-create-modal__fields {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.avatar-create-modal__field,
.avatar-create-modal__cover-field {
    display: flex;
    align-items: center;
    gap: 20px;
}

.avatar-create-modal__label {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
}

.avatar-create-modal__input-shell {
    display: flex;
    align-items: center;
    width: 268px;
    height: 40px;
    padding: 0 16px;
    border: 1px solid rgba(161, 161, 161, 0.6);
    border-radius: 8px;
    background: #222;
    box-sizing: border-box;
}

.avatar-create-modal__input-shell input,
.avatar-create-modal__input-shell button,
.avatar-create-modal__input-shell span {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.avatar-create-modal__input-shell input::placeholder {
    color: #a1a1a1;
}

.avatar-create-modal__input-count {
    width: auto !important;
    flex-shrink: 0;
    color: #a1a1a1 !important;
    font-size: 12px !important;
    line-height: 1 !important;
}

.avatar-create-modal__select-wrap {
    position: relative;
}

.avatar-create-modal__input-shell--select {
    justify-content: space-between;
    gap: 12px;
    padding: 0 16px;
    cursor: pointer;
}

.avatar-create-modal__input-shell--select img {
    width: 24px;
    height: 24px;
    object-fit: contain;
    flex-shrink: 0;
}

.avatar-create-modal__input-shell--select span {
    color: #fff;
    text-align: left;
}

.avatar-create-modal__menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    z-index: 5;
    min-width: 180px;
    padding: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(17, 17, 17, 0.96);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
}

.avatar-create-modal__menu button {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 34px;
    padding: 0 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.avatar-create-modal__menu button:hover,
.avatar-create-modal__menu button.is-active {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.avatar-create-modal__cover {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100px;
    height: 100px;
    padding: 0;
    border: 1px solid rgba(161, 161, 161, 0.6);
    border-radius: 8px;
    background: #222;
    overflow: hidden;
    cursor: pointer;
}

.avatar-create-modal__cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.avatar-create-modal__cover-add {
    width: auto !important;
    height: auto !important;
    color: #a1a1a1 !important;
    font-size: 38px !important;
    font-weight: 300;
    line-height: 1 !important;
}

.avatar-create-modal__footer {
    display: flex;
    justify-content: flex-end;
    width: 100%;
    margin-top: 40px;
}

.avatar-create-modal__submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 173px;
    height: 44px;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}

.avatar-create-modal__submit:disabled {
    opacity: 0.58;
    cursor: not-allowed;
}

.avatar-create-modal__submit-meta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.avatar-create-modal__submit-meta img {
    width: 10px;
    height: 10px;
}

.voice-create-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 42;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.66);
    backdrop-filter: blur(10px);
}

.voice-create-modal {
    position: relative;
    width: 700px;
    max-height: calc(100vh - 72px);
    overflow-y: auto;
    padding: 22px 22px 23px;
    border-radius: 12px;
    background: #222;
    box-sizing: border-box;
}

.voice-create-modal__close {
    position: absolute;
    top: 16px;
    right: 20px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: transparent;
    cursor: pointer;
}

.voice-create-modal__close::before,
.voice-create-modal__close::after {
    content: '';
    position: absolute;
    top: 13px;
    left: 8px;
    width: 12px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
}

.voice-create-modal__close::before {
    transform: rotate(45deg);
}

.voice-create-modal__close::after {
    transform: rotate(-45deg);
}

.voice-create-modal__title {
    margin: 0;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}

.voice-create-modal__cover-hero {
    display: flex;
    align-items: center;
    gap: 18px;
    min-height: 96px;
    margin-top: 18px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: #18191b;
    box-sizing: border-box;
}

.voice-create-modal__method-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
    margin-top: 12px;
}

.voice-create-modal__method-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    min-height: 104px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.08) 0%, rgba(255, 255, 255, 0.03) 100%);
    color: #fff;
    text-align: left;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease,
        opacity 0.2s ease;
}

.voice-create-modal__method-card strong {
    font-size: 16px;
    font-weight: 600;
    line-height: 1.2;
}

.voice-create-modal__method-card span {
    color: rgba(255, 255, 255, 0.72);
    font-size: 12px;
    line-height: 18px;
}

.voice-create-modal__method-card:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.2);
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.12) 0%, rgba(255, 255, 255, 0.05) 100%);
}

.voice-create-modal__method-card:disabled {
    opacity: 0.48;
    cursor: not-allowed;
    transform: none;
}

.voice-create-modal__method-eyebrow {
    color: rgba(255, 255, 255, 0.4) !important;
    font-size: 11px !important;
    letter-spacing: 0.08em;
    line-height: 1 !important;
}

.voice-create-modal__method-card.is-recording {
    border-color: rgba(255, 146, 50, 0.65);
    background: linear-gradient(180deg, rgba(255, 146, 50, 0.22) 0%, rgba(255, 146, 50, 0.08) 100%);
}

.voice-create-modal__method-card.is-disabled {
    border-style: dashed;
}

.voice-create-modal__tips {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 14px;
    color: #ff9232;
    font-size: 14px;
    line-height: 1;
}

.voice-create-modal__tips-icon {
    position: relative;
    width: 18px;
    height: 18px;
    border: 1.2px solid currentColor;
    border-radius: 999px;
    box-sizing: border-box;
}

.voice-create-modal__tips-icon::before {
    content: '';
    position: absolute;
    top: 3px;
    left: 7.6px;
    width: 2px;
    height: 7px;
    border-radius: 999px;
    background: currentColor;
}

.voice-create-modal__tips-icon::after {
    content: '';
    position: absolute;
    bottom: 3px;
    left: 7.6px;
    width: 2px;
    height: 2px;
    border-radius: 999px;
    background: currentColor;
}

.voice-create-modal__sample-card {
    margin-top: 14px;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: #161719;
}

.voice-create-modal__sample-card.is-recording {
    border-color: rgba(255, 146, 50, 0.48);
    box-shadow: inset 0 0 0 1px rgba(255, 146, 50, 0.1);
}

.voice-create-modal__sample-card.is-ready {
    border-color: rgba(72, 187, 120, 0.4);
}

.voice-create-modal__sample-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.voice-create-modal__sample-title-wrap {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.voice-create-modal__sample-title-wrap strong {
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    line-height: 1;
}

.voice-create-modal__sample-status {
    color: rgba(255, 255, 255, 0.78);
    font-size: 12px;
    line-height: 18px;
}

.voice-create-modal__sample-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 28px;
    padding: 0 10px;
    border: 0;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
    font-family: inherit;
    font-size: 12px;
    line-height: 1;
    white-space: nowrap;
}

.voice-create-modal__sample-preview {
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        opacity 0.2s ease;
}

.voice-create-modal__sample-preview::before {
    content: '';
    width: 0;
    height: 0;
    border-top: 5px solid transparent;
    border-bottom: 5px solid transparent;
    border-left: 8px solid currentColor;
}

.voice-create-modal__sample-preview.is-playing::before {
    width: 8px;
    height: 10px;
    border: 0;
    border-inline: 3px solid currentColor;
    box-sizing: border-box;
}

.voice-create-modal__sample-preview:hover {
    background: rgba(255, 255, 255, 0.16);
}

.voice-create-modal__sample-preview:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.voice-create-modal__sample-main {
    margin-top: 12px;
}

.voice-create-modal__sample-name {
    color: #fff;
    font-size: 14px;
    line-height: 22px;
    word-break: break-word;
}

.voice-create-modal__sample-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 12px;
    line-height: 18px;
}

.voice-create-modal__sample-audio {
    display: none;
}

.voice-preview-audio {
    display: none;
}

.voice-trim-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 46;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.7);
    backdrop-filter: blur(10px);
    box-sizing: border-box;
}

.voice-trim-modal {
    position: relative;
    width: min(620px, 100%);
    padding: 24px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: #202123;
    box-shadow: 0 24px 60px rgba(0, 0, 0, 0.36);
    box-sizing: border-box;
}

.voice-trim-modal__close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: transparent;
    cursor: pointer;
}

.voice-trim-modal__close::before,
.voice-trim-modal__close::after {
    content: '';
    position: absolute;
    top: 13px;
    left: 8px;
    width: 12px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
}

.voice-trim-modal__close::before {
    transform: rotate(45deg);
}

.voice-trim-modal__close::after {
    transform: rotate(-45deg);
}

.voice-trim-modal__head {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding-right: 36px;
}

.voice-trim-modal__head span {
    color: #ff9232;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
}

.voice-trim-modal__head strong {
    color: #fff;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.2;
}

.voice-trim-modal__head p {
    margin: 0;
    color: rgba(255, 255, 255, 0.68);
    font-size: 13px;
    line-height: 20px;
}

.voice-trim-modal__file {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
    padding: 12px 14px;
    border-radius: 8px;
    background: #151618;
}

.voice-trim-modal__file strong {
    min-width: 0;
    overflow: hidden;
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 20px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.voice-trim-modal__file span {
    flex-shrink: 0;
    color: rgba(255, 255, 255, 0.62);
    font-size: 12px;
    line-height: 1;
}

.voice-trim-modal__timeline {
    margin-top: 22px;
    user-select: none;
}

.voice-trim-modal__ticks {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
    color: rgba(255, 255, 255, 0.48);
    font-size: 12px;
    line-height: 1;
}

.voice-trim-modal__track {
    position: relative;
    height: 54px;
    border-radius: 8px;
    background:
        repeating-linear-gradient(
            90deg,
            rgba(255, 255, 255, 0.08) 0,
            rgba(255, 255, 255, 0.08) 1px,
            transparent 1px,
            transparent 18px
        ),
        #111214;
    cursor: pointer;
    overflow: hidden;
}

.voice-trim-modal__window {
    position: absolute;
    top: 6px;
    bottom: 6px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-width: 64px;
    border: 2px solid #ff9232;
    border-radius: 8px;
    background: rgba(255, 146, 50, 0.24);
    cursor: grab;
    box-sizing: border-box;
    touch-action: none;
}

.voice-trim-modal__window:active {
    cursor: grabbing;
}

.voice-trim-modal__handle {
    width: 10px;
    height: 26px;
    border-radius: 999px;
    background: #ff9232;
}

.voice-trim-modal__handle.is-left {
    margin-left: 7px;
}

.voice-trim-modal__handle.is-right {
    margin-right: 7px;
}

.voice-trim-modal__duration {
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    pointer-events: none;
}

.voice-trim-modal__audio {
    width: 100%;
    margin-top: 18px;
    filter: invert(1) hue-rotate(180deg);
}

.voice-trim-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 22px;
}

.voice-trim-modal__actions button {
    min-width: 96px;
    height: 38px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    background: #2c2d30;
    color: #fff;
    font-size: 14px;
    cursor: pointer;
}

.voice-trim-modal__actions button.is-primary {
    border-color: #fff;
    background: #fff;
    color: #222;
}

.voice-trim-modal__actions button:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}

.voice-create-modal__script-card {
    margin-top: 12px;
}

.voice-create-modal__script {
    min-height: 112px;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 8px;
    background: #0b0c0e;
    color: #fff;
    font-size: 14px;
    line-height: 24px;
    box-sizing: border-box;
    white-space: pre-wrap;
}

.voice-create-modal__form {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px 18px;
    margin-top: 18px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: #18191b;
}

.voice-create-modal__field {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.voice-create-modal__label {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
    flex-shrink: 0;
}

.voice-create-modal__setting-row {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.voice-create-modal__input-shell {
    display: flex;
    align-items: center;
    width: 100%;
    height: 40px;
    padding: 0 16px;
    border: 1px solid rgba(161, 161, 161, 0.6);
    border-radius: 8px;
    background: #222;
    box-sizing: border-box;
}

.voice-create-modal__input-shell--short {
    width: 116px;
}

.voice-create-modal__input-shell--name {
    width: 100%;
    min-width: 240px;
}

.voice-create-modal__input-shell input,
.voice-create-modal__input-shell button,
.voice-create-modal__input-shell span {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.voice-create-modal__input-shell input::placeholder {
    color: #a1a1a1;
}

.voice-create-modal__select-wrap {
    position: relative;
}

.voice-create-modal__input-shell--select {
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
}

.voice-create-modal__input-shell--select img {
    width: 24px;
    height: 24px;
    object-fit: contain;
    flex-shrink: 0;
}

.voice-create-modal__input-shell--select span {
    text-align: left;
}

.voice-create-modal__menu {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    z-index: 5;
    min-width: 124px;
    padding: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(17, 17, 17, 0.96);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
}

.voice-create-modal__menu button {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 34px;
    padding: 0 10px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.7);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.voice-create-modal__menu button:hover,
.voice-create-modal__menu button.is-active {
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.voice-create-modal__cover {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    padding: 0;
    border: 1px solid rgba(161, 161, 161, 0.6);
    border-radius: 8px;
    background: #222;
    overflow: hidden;
    cursor: pointer;
}

.voice-create-modal__cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.voice-create-modal__cover-add {
    width: auto !important;
    height: auto !important;
    color: #a1a1a1 !important;
    font-size: 34px !important;
    font-weight: 300;
    line-height: 1 !important;
}

.voice-create-modal__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    width: 100%;
    margin-top: 18px;
}

.voice-create-modal__submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 173px;
    height: 44px;
    border: 0;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
    cursor: pointer;
    transition:
        background 0.2s ease,
        opacity 0.2s ease;
}

.voice-create-modal__submit:disabled {
    background: #a1a1a1;
    color: #222;
    cursor: not-allowed;
}

.voice-create-modal__submit-meta {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.voice-create-modal__submit-meta img {
    width: 10px;
    height: 10px;
}

.library-pagination {
    display: flex;
    justify-content: flex-end;
    margin-top: 20px;
}

.library-pagination--voice {
    flex-shrink: 0;
    margin-top: 24px;
}

.library-pagination :deep(.el-pagination) {
    --el-pagination-bg-color: transparent;
    --el-pagination-text-color: rgba(255, 255, 255, 0.72);
    --el-pagination-button-color: rgba(255, 255, 255, 0.72);
    --el-pagination-button-disabled-color: rgba(255, 255, 255, 0.28);
    --el-pagination-hover-color: #fff;
    --el-pagination-border-radius: 999px;
}

.library-pagination :deep(.el-pager li),
.library-pagination :deep(.btn-prev),
.library-pagination :deep(.btn-next) {
    background: rgba(255, 255, 255, 0.06);
    border-radius: 999px;
}

.library-pagination :deep(.el-pager li.is-active) {
    background: #fff;
    color: #050505;
}

.avatar-delete-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 45;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0, 0, 0, 0.58);
    backdrop-filter: blur(8px);
}

.avatar-delete-modal {
    position: relative;
    width: 400px;
    padding: 24px 26px 22px;
    border-radius: 16px;
    background: #222;
    box-sizing: border-box;
}

.avatar-delete-modal__close {
    position: absolute;
    top: 18px;
    right: 22px;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.avatar-delete-modal__close::before,
.avatar-delete-modal__close::after {
    content: '';
    position: absolute;
    top: 11px;
    left: 4px;
    width: 16px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
}

.avatar-delete-modal__close::before {
    transform: rotate(45deg);
}

.avatar-delete-modal__close::after {
    transform: rotate(-45deg);
}

.avatar-delete-modal__title {
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar-delete-modal__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 18px;
    height: 18px;
    border-radius: 999px;
    background: #ff5a36;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    line-height: 1;
}

.avatar-delete-modal__title strong {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1;
}

.avatar-delete-modal__desc {
    margin: 18px 0 0 28px;
    color: rgba(255, 255, 255, 0.72);
    font-size: 14px;
    line-height: 1.6;
}

.avatar-delete-modal__actions {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 26px;
}

.avatar-delete-modal__actions button {
    min-width: 76px;
    height: 40px;
    padding: 0 18px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 12px;
    background: transparent;
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
}

.avatar-delete-modal__actions button.is-danger {
    border-color: #ff4d35;
    background: #ff4d35;
}

.avatar-page {
    position: relative;
    height: 100vh;
    min-height: 100vh;
    padding: 0 32px;
    background: #050505;
    color: #fff;
    overflow-x: hidden;
    overflow-y: hidden;
    box-sizing: border-box;
}

.avatar-page__background,
.avatar-page__noise,
.avatar-page__stars {
    position: fixed;
    inset: 0;
    pointer-events: none;
    will-change: opacity;
}

.avatar-page__background {
    background-position: center top;
    background-repeat: no-repeat;
    background-size: cover;
    opacity: 1;
}

.avatar-page__noise {
    background-image:
        radial-gradient(circle at 6% 16%, rgba(255, 255, 255, 0.65) 0 1px, transparent 1.8px),
        radial-gradient(circle at 12% 54%, rgba(255, 255, 255, 0.4) 0 1px, transparent 1.8px),
        radial-gradient(circle at 18% 32%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.6px),
        radial-gradient(circle at 26% 12%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.6px),
        radial-gradient(circle at 34% 58%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.8px),
        radial-gradient(circle at 42% 18%, rgba(255, 255, 255, 0.45) 0 1px, transparent 1.5px),
        radial-gradient(circle at 52% 10%, rgba(255, 255, 255, 0.55) 0 1px, transparent 1.5px),
        radial-gradient(circle at 61% 44%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.5px),
        radial-gradient(circle at 72% 20%, rgba(255, 255, 255, 0.48) 0 1px, transparent 1.7px),
        radial-gradient(circle at 84% 38%, rgba(255, 255, 255, 0.42) 0 1px, transparent 1.7px),
        radial-gradient(circle at 90% 14%, rgba(255, 255, 255, 0.52) 0 1px, transparent 1.7px),
        radial-gradient(circle at 96% 52%, rgba(255, 255, 255, 0.35) 0 1px, transparent 1.8px);
    opacity: 0.24;
}

.avatar-page__stars {
    opacity: 0.95;
    mix-blend-mode: screen;
}

.avatar-page__stars--near {
    background-image:
        radial-gradient(circle at 10% 22%, rgba(255, 255, 255, 0.95) 0 1.4px, transparent 2.2px),
        radial-gradient(circle at 21% 68%, rgba(255, 255, 255, 0.92) 0 1.2px, transparent 2px),
        radial-gradient(circle at 36% 36%, rgba(255, 255, 255, 0.98) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 48% 14%, rgba(255, 255, 255, 0.9) 0 1.3px, transparent 2px),
        radial-gradient(circle at 57% 60%, rgba(255, 255, 255, 0.9) 0 1.1px, transparent 1.8px),
        radial-gradient(circle at 69% 28%, rgba(255, 255, 255, 0.96) 0 1.5px, transparent 2.2px),
        radial-gradient(circle at 82% 58%, rgba(255, 255, 255, 0.94) 0 1.25px, transparent 2px),
        radial-gradient(circle at 92% 20%, rgba(255, 255, 255, 0.9) 0 1.35px, transparent 2px);
    animation: starTwinkle 4.8s ease-in-out infinite alternate;
}

.avatar-page__stars--far {
    background-image:
        radial-gradient(circle at 14% 10%, rgba(160, 203, 255, 0.8) 0 1px, transparent 1.8px),
        radial-gradient(circle at 30% 48%, rgba(255, 255, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 44% 72%, rgba(178, 193, 255, 0.72) 0 0.9px, transparent 1.5px),
        radial-gradient(circle at 60% 8%, rgba(255, 255, 255, 0.68) 0 1px, transparent 1.6px),
        radial-gradient(circle at 78% 42%, rgba(181, 220, 255, 0.75) 0 0.95px, transparent 1.6px),
        radial-gradient(circle at 88% 74%, rgba(255, 255, 255, 0.7) 0 1px, transparent 1.6px);
    animation: starTwinkle 6.2s ease-in-out infinite alternate-reverse;
}

@keyframes starTwinkle {
    0% {
        opacity: 0.28;
        transform: scale(1);
    }

    50% {
        opacity: 0.62;
        transform: scale(1.01);
    }

    100% {
        opacity: 0.95;
        transform: scale(1.02);
    }
}

.avatar-main {
    --editor-width: clamp(350px, 22vw, 403px);
    --layout-gap: clamp(16px, 1.2vw, 24px);
    --card-min-width: clamp(210px, 12.8vw, 252px);
    --category-chip-gap: 20px;
    --category-chip-radius: 4px;
    --category-chip-min-height: 32px;
    --category-chip-padding-x: 16px;
    --category-chip-text-color: rgba(255, 255, 255, 0.62);
    --category-chip-active-bg: #2c2c2c;
    --category-chip-active-color: #fff;
    position: relative;
    z-index: 1;
    height: 100vh;
    min-height: 0;
    padding: 84px 24px 28px 132px;
    overflow: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
    box-sizing: border-box;
}

.avatar-main::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.avatar-main::-webkit-scrollbar-track {
    background: transparent;
}

.avatar-main::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.avatar-main::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.avatar-main--tools,
.avatar-main--assets {
    padding-top: 84px;
    padding-bottom: 24px;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.avatar-main--assets {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.avatar-main--tools::-webkit-scrollbar,
.avatar-main--assets::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.avatar-main--tools::-webkit-scrollbar-track,
.avatar-main--assets::-webkit-scrollbar-track {
    background: transparent;
}

.avatar-main--tools::-webkit-scrollbar-thumb,
.avatar-main--assets::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.avatar-main--tools::-webkit-scrollbar-thumb:hover,
.avatar-main--assets::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tools-shell {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 28px;
    width: 100%;
    min-height: 100%;
    padding-top: 40px;
    box-sizing: border-box;
}

.tools-shell::before {
    display: none;
}

.tools-shell > * {
    position: relative;
    z-index: 1;
}

.tools-search {
    display: flex;
    align-items: center;
    gap: 12px;
    width: min(100%, 352px);
    height: 38px;
    padding: 0 16px;
    border: 0;
    border-radius: 14px;
    background: #242424;
    box-shadow: none;
    backdrop-filter: none;
    box-sizing: border-box;
}

.tools-search__icon {
    position: relative;
    width: 16px;
    height: 16px;
    flex-shrink: 0;
}

.tools-search__icon::before,
.tools-search__icon::after {
    content: '';
    position: absolute;
    box-sizing: border-box;
}

.tools-search__icon::before {
    inset: 0;
    border: 1.8px solid rgba(255, 255, 255, 0.55);
    border-radius: 50%;
}

.tools-search__icon::after {
    right: -1px;
    bottom: 0;
    width: 6px;
    height: 1.8px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.55);
    transform: rotate(45deg);
    transform-origin: center;
}

.tools-search input {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.tools-search input::placeholder {
    color: rgba(255, 255, 255, 0.46);
}

.tools-search--inline {
    width: min(100%, 320px);
    flex-shrink: 0;
}

.tools-section {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: 100%;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    box-sizing: border-box;
}

.tools-section__heading {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tools-section__heading--row {
    flex-direction: row;
    align-items: flex-end;
    justify-content: space-between;
    gap: 16px;
}

.tools-section__heading-main {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
    flex: 1;
}

.tools-section__heading h2 {
    margin: 0;
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
    letter-spacing: 0;
}

.tools-featured-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    width: 100%;
}

.tools-featured-card,
.tools-categories button,
.tools-card {
    border: 0;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease,
        color 0.2s ease;
}

.tools-featured-card {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) 148px;
    gap: 18px;
    min-height: 142px;
    padding: 14px 18px 14px 22px;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 20px;
    background: #242424;
    overflow: hidden;
    box-sizing: border-box;
}

.tools-featured-card::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(255, 255, 255, 0.015) 0%, rgba(255, 255, 255, 0) 100%);
    pointer-events: none;
}

.tools-featured-card:hover,
.tools-featured-card.is-active {
    border-color: rgba(255, 255, 255, 0.12);
    transform: translateY(-1px);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.18);
}

.tools-featured-card.is-active {
    background: #2b2b2b;
}

.tools-featured-card__copy,
.tools-featured-card__visual {
    position: relative;
    z-index: 1;
}

.tools-featured-card__copy {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 10px;
    min-width: 0;
}

.tools-featured-card__copy h3 {
    margin: 0;
    color: #fff;
    font-size: 20px;
    font-weight: 600;
    line-height: 1.15;
    letter-spacing: 0;
}

.tools-featured-card__copy p {
    margin: 0;
    color: rgba(255, 255, 255, 0.34);
    font-size: 12px;
    line-height: 1.45;
}

.tools-featured-card__visual {
    align-self: center;
    width: 148px;
    height: 114px;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: none;
}

.tools-featured-card__visual img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tools-categories {
    display: flex;
    flex-wrap: wrap;
    gap: var(--category-chip-gap);
    width: 100%;
}

.tools-categories button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: var(--category-chip-min-height);
    padding: 0 var(--category-chip-padding-x);
    border: 0;
    border-radius: var(--category-chip-radius);
    background: transparent;
    color: var(--category-chip-text-color);
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
}

.tools-categories button:hover {
    color: var(--category-chip-active-color);
}

.tools-categories button.is-active {
    background: var(--category-chip-active-bg);
    color: var(--category-chip-active-color);
    box-shadow: none;
}

.tools-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    width: 100%;
}

.tools-card {
    position: relative;
    min-height: 0;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 18px;
    background: #171717;
    overflow: hidden;
    aspect-ratio: 0.74;
    box-sizing: border-box;
}

.tools-card:hover,
.tools-card.is-active {
    border-color: rgba(255, 255, 255, 0.12);
    transform: translateY(-1px);
    box-shadow: 0 20px 36px rgba(0, 0, 0, 0.22);
}

.tools-card__image,
.tools-card__overlay {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.tools-card__image {
    object-fit: cover;
    transition:
        transform 0.35s ease,
        filter 0.35s ease;
}

.tools-card:hover .tools-card__image,
.tools-card.is-active .tools-card__image {
    transform: scale(1.04);
    filter: saturate(1.06) brightness(1.04);
}

.tools-card__overlay {
    background:
        linear-gradient(180deg, rgba(0, 0, 0, 0) 44%, rgba(0, 0, 0, 0.26) 70%, rgba(0, 0, 0, 0.82) 100%),
        linear-gradient(180deg, rgba(255, 255, 255, 0) 58%, rgba(255, 255, 255, 0.03) 100%);
}

.tools-card__content {
    position: absolute;
    inset: auto 16px 16px;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
}

.tools-card__content h3 {
    margin: 0;
    color: #fff;
    font-size: 17px;
    font-weight: 600;
    line-height: 1.4;
    text-shadow: 0 4px 16px rgba(0, 0, 0, 0.4);
}

.tools-card__tag {
    color: rgba(255, 255, 255, 0.62);
    font-size: 13px;
    line-height: 1.4;
}

.tools-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 220px;
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: 22px;
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
}

.tools-empty strong {
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
}

.tools-empty span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 14px;
    line-height: 1.6;
}

.avatar-layout {
    display: grid;
    grid-template-columns: minmax(350px, var(--editor-width)) minmax(0, 1fr);
    gap: var(--layout-gap);
    align-items: stretch;
    height: 100%;
    min-height: 0;
    overflow: hidden;
}

.avatar-editor {
    display: flex;
    flex-direction: column;
    min-height: 0;
    max-height: 100%;
    padding: 16px;
    padding-bottom: 20px;
    border-radius: 20px;
    background: #0a0a0a;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    overflow-y: auto;
    overscroll-behavior: contain;
}

.editor-section + .editor-section {
    margin-top: 20px;
}

.editor-section__head,
.editor-section__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.editor-section--name .editor-section__row {
    flex-direction: column;
    align-items: stretch;
    gap: 12px;
}

.editor-section__head {
    margin-bottom: 12px;
}

.editor-section__head--stacked {
    justify-content: flex-start;
}

.editor-section__label {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1;
    flex-shrink: 0;
}

.mini-action,
.create-button,
.script-box__tools button,
.script-box__chips button,
.editor-stage__upload,
.editor-stage__history,
.avatar-card {
    border: 0;
    cursor: pointer;
    transition: all 0.2s ease;
}

.mini-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    height: 36px;
    padding: 0 16px;
    border-radius: 10px;
    background: #222;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.mini-action img {
    width: 16px;
    height: 16px;
}

.editor-stage,
.script-box {
    width: 100%;
    border-radius: 8px;
    background: #000;
}

.editor-stage {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    height: 136px;
    border-radius: 10px;
    background: #262626;
}

.editor-stage--avatar-filled {
    padding: 0;
    overflow: hidden;
}

.editor-stage--voice-filled {
    justify-content: flex-start;
    padding: 20px 22px 18px;
}

.editor-stage__upload {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    background: transparent;
    color: #b8b8b8;
    font-size: 14px;
    line-height: 1;
}

.editor-stage__upload img {
    width: 32px;
    height: 32px;
}

.editor-stage__history {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    padding: 0 18px;
    border-radius: 10px;
    background: #343434;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.editor-stage__history img {
    width: 16px;
    height: 16px;
}

.avatar-editor-card {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    overflow: hidden;
}

.avatar-editor-card__preview {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.avatar-editor-card__preview img {
    height: 100%;
    width: auto;
    max-width: none;
    min-height: 100%;
    object-fit: cover;
    transform: scale(1.08);
}

.avatar-editor-card__actions {
    position: absolute;
    left: 50%;
    bottom: 18px;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    gap: 20px;
    height: 58px;
    padding: 0 28px;
    border-radius: 999px;
    background: rgba(16, 16, 16, 0.88);
    backdrop-filter: blur(10px);
    opacity: 0;
    transform: translate(-50%, 8px);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
    pointer-events: none;
}

.avatar-editor-card__actions button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.avatar-editor-card__actions button img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.editor-stage--avatar-filled:hover .avatar-editor-card__actions,
.editor-stage--avatar-filled:focus-within .avatar-editor-card__actions {
    opacity: 1;
    transform: translate(-50%, 0);
    pointer-events: auto;
}

.voice-editor-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    height: 100%;
}

.voice-editor-card__body {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    width: 100%;
    min-width: 0;
    flex: 1;
}

.voice-editor-card__cover {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    padding: 0;
    border: 0;
    border-radius: 999px;
    background: transparent;
    overflow: hidden;
}

.voice-editor-card__image,
.voice-editor-card__disc {
    width: 100%;
    height: 100%;
    border-radius: 999px;
}

.voice-editor-card__image {
    object-fit: cover;
}

.voice-editor-card__disc {
    background:
        radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.18) 0 18%, transparent 18% 100%),
        repeating-radial-gradient(circle at 50% 50%, #d7d7d7 0 2px, #505050 2px 4px, #e8e8e8 4px 6px, #2a2a2a 6px 8px);
    box-shadow:
        inset 0 0 0 3px rgba(0, 0, 0, 0.28),
        0 6px 12px rgba(0, 0, 0, 0.24);
}

.voice-editor-card__play {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.45);
    transform: translate(-50%, -50%);
}

.voice-editor-card__name {
    max-width: min(220px, calc(100% - 72px));
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.voice-editor-card__actions {
    display: inline-flex;
    align-items: center;
    gap: 20px;
    height: 58px;
    padding: 0 28px;
    border-radius: 999px;
    background: #101010;
    opacity: 0;
    transform: translateY(8px);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
    pointer-events: none;
}

.voice-editor-card__actions button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.voice-editor-card__actions button img {
    width: 20px;
    height: 20px;
    object-fit: contain;
}

.editor-stage--voice-filled:hover .voice-editor-card__actions,
.editor-stage--voice-filled:focus-within .voice-editor-card__actions {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}

.script-box {
    position: relative;
    height: 132px;
    padding: 14px 12px 44px;
    border: 1px solid #222;
}

.script-box textarea {
    width: 100%;
    height: 64px;
    border: 0;
    outline: none;
    resize: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    line-height: 24px;
}

.script-box textarea::placeholder,
.script-box__count {
    color: #a1a1a1;
}

.script-box__tools {
    position: absolute;
    left: 12px;
    bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.script-box__translate-wrap {
    position: relative;
    display: inline-flex;
}

.script-box__language-menu {
    position: absolute;
    left: 0;
    bottom: 38px;
    z-index: 8;
    display: grid;
    grid-template-columns: repeat(2, minmax(86px, 1fr));
    gap: 6px;
    width: 196px;
    padding: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    background: rgba(24, 24, 24, 0.98);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
    backdrop-filter: blur(12px);
}

.script-box__tools button,
.script-box__chips button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border-radius: 8px;
    background: #222;
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.script-box__language-menu button {
    justify-content: center;
    width: 100%;
    height: 30px;
    padding: 0 8px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.script-box__tools img {
    width: 18px;
    height: 18px;
}

.script-box__clear {
    position: absolute;
    right: 12px;
    bottom: 12px;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    background: transparent;
    cursor: pointer;
}

.script-box__clear img {
    width: 24px;
    height: 24px;
}

.script-box__count {
    position: absolute;
    right: 44px;
    bottom: 18px;
    font-size: 12px;
    line-height: 1;
}

.script-box__chips {
    display: none;
}

.script-box__chips button {
    font-size: 12px;
}

.editor-section--name {
    margin-top: 18px;
}

.editor-section--name .editor-section__label {
    width: auto;
}

.work-name {
    display: flex;
    align-items: center;
    width: 100%;
    height: 40px;
    padding: 0 14px;
    border: 1px solid #222;
    border-radius: 8px;
    background: #000;
}

.work-name input {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
}

.work-name input::placeholder {
    color: #a1a1a1;
}

.create-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    width: 100%;
    min-height: 64px;
    margin-top: 24px;
    padding: 0 22px;
    border-radius: 12px;
    background: #fff;
    color: #222;
    font-size: 19px;
    font-weight: 700;
    letter-spacing: 0;
    line-height: 1;
    box-shadow: 0 16px 34px rgba(255, 255, 255, 0.1);
}

.create-button:disabled {
    opacity: 0.75;
    cursor: default;
}

.create-button__meta {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 32px;
    padding: 0 12px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.08);
    font-size: 16px;
    font-weight: 700;
    line-height: 1;
}

.create-button__meta img {
    width: 16px;
    height: 16px;
}

.create-button__text {
    line-height: 1;
}

.avatar-content {
    position: relative;
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
}

.avatar-heading {
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 40px;
    width: 100%;
    min-height: 28px;
    padding: 8px 0 16px;
    background: #050505;
}

.avatar-heading__tab {
    padding: 0;
    background: transparent;
    color: #a1a1a1;
    font-size: 20px;
    font-weight: 500;
    line-height: 28px;
}

.avatar-heading__tab.is-active {
    color: #fff;
}

.avatar-heading__line {
    position: absolute;
    left: 0;
    bottom: 0;
    width: 80px;
    height: 2px;
    border-radius: 999px;
    background: #fff;
    transition: transform 0.2s ease;
}

.voice-filters {
    --category-chip-gap: 28px;
    --category-chip-radius: 10px;
    --category-chip-min-height: 38px;
    --category-chip-padding-x: 18px;
    --category-chip-text-color: rgba(255, 255, 255, 0.88);
    --category-chip-active-bg: #2c2c2c;
    --category-chip-active-color: #fff;
    position: relative;
    z-index: 1;
    flex-shrink: 0;
    padding: 24px 0 0;
    margin-bottom: -20px;
}

.avatar-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(168px, 236px));
    gap: clamp(12px, 0.9vw, 16px);
    width: 100%;
    justify-content: start;
    align-content: start;
}

.avatar-gallery-scroll {
    flex: 1 1 auto;
    min-height: 0;
    margin-top: 20px;
    padding: 20px 6px 28px 0;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.avatar-gallery-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.avatar-gallery-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.avatar-gallery-scroll::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.avatar-gallery-scroll::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.voice-library-scroll {
    flex: 1 1 auto;
    min-height: 0;
    margin-top: 20px;
    width: 100%;
    padding: 20px 0 28px;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.voice-library-panel {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
}

.voice-library-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.voice-library-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.voice-library-scroll::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.voice-library-scroll::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.voice-library {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 18px;
    width: 100%;
    justify-content: start;
    align-content: start;
}

.voice-card {
    position: relative;
    width: min(100%, 423px);
    height: 96px;
    border: 1px solid transparent;
    border-radius: 12px;
    background: #222;
    overflow: hidden;
    justify-self: start;
    cursor: pointer;
    transition: border-color 0.2s ease;
}

.voice-card.is-selected,
.voice-card:hover {
    border-color: rgba(255, 255, 255, 0.2);
}

.voice-card__vip {
    position: absolute;
    top: 0;
    right: 0;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 2px;
    height: 24px;
    padding: 0 7px;
    border-radius: 0 12px 0 12px;
    background: linear-gradient(270deg, #ffeec9 0%, #ffde97 100%);
    color: #5c4010;
    font-size: 12px;
    font-weight: 600;
}

.voice-card__vip img {
    width: 12px;
    height: 12px;
}

.voice-card__favorite {
    position: absolute;
    top: 34px;
    right: 116px;
    z-index: 2;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    background: transparent;
    color: #a1a1a1;
    font-size: 18px;
    line-height: 1;
    opacity: 0;
    transform: translateY(4px);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease,
        color 0.2s ease;
    pointer-events: none;
    cursor: pointer;
}

.voice-card__favorite.is-active {
    color: #ffbe32;
}

.voice-card__action {
    position: absolute;
    top: 32px;
    right: 12px;
    z-index: 2;
    height: 32px;
    padding: 0 16px;
    border: 0;
    border-radius: 8px;
    background: #fff;
    color: #222;
    font-size: 14px;
    font-weight: 500;
    opacity: 0;
    transform: translateY(4px);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
    pointer-events: none;
    cursor: pointer;
}

.voice-card:hover .voice-card__favorite,
.voice-card:hover .voice-card__action,
.voice-card:focus-within .voice-card__favorite,
.voice-card:focus-within .voice-card__action {
    opacity: 1;
    transform: translateY(0);
    pointer-events: auto;
}

.voice-card__meta {
    position: absolute;
    left: 12px;
    top: 12px;
    display: flex;
    align-items: center;
    gap: 20px;
    min-width: 0;
    max-width: calc(100% - 156px);
}

.voice-card__thumb {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 72px;
    height: 72px;
    border-radius: 8px;
    overflow: hidden;
    flex-shrink: 0;
    background: linear-gradient(135deg, #3b3b3b 0%, #2a2a2a 100%);
}

.voice-card__thumb::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.32);
    opacity: 0;
    transition: opacity 0.2s ease;
}

.voice-card__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: filter 0.2s ease;
}

.voice-card__thumb.is-empty {
    background: linear-gradient(135deg, #3a3a3a 0%, #2c2c2c 100%);
}

.voice-card:hover .voice-card__thumb::after,
.voice-card__thumb.is-playing::after {
    opacity: 1;
}

.voice-card:hover .voice-card__thumb img,
.voice-card__thumb.is-playing img {
    filter: brightness(0.7);
}

.voice-card__thumb-icon {
    width: 28px !important;
    height: 28px !important;
    object-fit: contain !important;
}

.voice-card__play {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.62);
    opacity: 0;
    transform: translate(-50%, -50%) scale(0.92);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease,
        background 0.2s ease;
    pointer-events: none;
    cursor: pointer;
}

.voice-card:hover .voice-card__play,
.voice-card__play.is-active,
.voice-card__play:focus-visible {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
    pointer-events: auto;
}

.voice-card__play.is-active {
    background: rgba(3, 191, 3, 0.9);
}

.voice-card__play-icon {
    width: 0;
    height: 0;
    margin-left: 3px;
    border-top: 7px solid transparent;
    border-bottom: 7px solid transparent;
    border-left: 10px solid #fff;
}

.voice-card__pause-icon {
    position: relative;
    width: 12px;
    height: 12px;
}

.voice-card__pause-icon::before,
.voice-card__pause-icon::after {
    content: '';
    position: absolute;
    top: 0;
    width: 3px;
    height: 12px;
    border-radius: 999px;
    background: #fff;
}

.voice-card__pause-icon::before {
    left: 1px;
}

.voice-card__pause-icon::after {
    right: 1px;
}

.voice-card__loading-icon {
    width: 14px;
    height: 14px;
    border: 2px solid rgba(255, 255, 255, 0.34);
    border-top-color: #fff;
    border-radius: 999px;
    animation: voice-preview-spin 0.8s linear infinite;
}

@keyframes voice-preview-spin {
    to {
        transform: rotate(360deg);
    }
}

.voice-card__name {
    min-width: 0;
    color: #fff;
    font-size: 20px;
    font-weight: 500;
    line-height: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.voice-card--create,
.voice-card--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    width: min(100%, 423px);
    min-height: 128px;
    background: #262626;
    border-color: rgba(255, 255, 255, 0.06);
    justify-self: start;
}

.voice-card--create__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    color: rgba(255, 255, 255, 0.62);
    font-size: 16px;
    font-weight: 500;
    line-height: 1.2;
    text-align: center;
}

.voice-card--create__inner img {
    width: 32px;
    height: 32px;
}

.avatar-card {
    position: relative;
    width: 100%;
    min-height: 260px;
    aspect-ratio: 3 / 4;
    overflow: hidden;
    border: 1px solid transparent;
    border-radius: 12px;
    background: #111;
}

.avatar-card__image,
.avatar-card__shade {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.avatar-card__image {
    box-sizing: border-box;
    padding: 0;
    object-fit: cover;
    background: linear-gradient(180deg, #171719 0%, #0d0d0f 100%);
    transition:
        transform 0.45s ease,
        filter 0.3s ease;
}

.avatar-card__shade {
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.02) 16%, rgba(0, 0, 0, 0.38) 100%);
    transition: background 0.3s ease;
}

.avatar-card__vip {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    gap: 2px;
    height: 24px;
    padding: 0 7px;
    border-radius: 12px 0 12px 0;
    background: linear-gradient(270deg, #ffeec9 0%, #ffde97 100%);
    color: #5c4010;
    font-size: 12px;
    font-weight: 600;
}

.avatar-card__vip img {
    width: 12px;
    height: 12px;
}

.avatar-card__more {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 4;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 3px;
    width: 44px;
    height: 44px;
    padding: 0;
    border: 0;
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.34);
    backdrop-filter: blur(8px);
    cursor: pointer;
}

.avatar-card__more span {
    width: 4px;
    height: 4px;
    border-radius: 999px;
    background: #fff;
}

.avatar-card__menu {
    position: absolute;
    top: 60px;
    right: 12px;
    z-index: 5;
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 92px;
    padding: 8px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(17, 17, 17, 0.96);
    box-shadow: 0 18px 30px rgba(0, 0, 0, 0.35);
    backdrop-filter: blur(10px);
}

.avatar-card__menu button {
    display: flex;
    align-items: center;
    width: 100%;
    min-height: 34px;
    padding: 0 12px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #fff;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.avatar-card__menu button:hover {
    background: rgba(255, 255, 255, 0.08);
}

.avatar-card__meta {
    position: absolute;
    left: 12px;
    right: 12px;
    bottom: 14px;
    z-index: 2;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 8px;
    min-width: 0;
}

.avatar-tag {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    max-width: 100%;
    height: 32px;
    box-sizing: border-box;
    padding: 4px 12px;
    border-radius: 36px;
    font-size: 14px;
    line-height: 1;
    backdrop-filter: blur(4px);
    white-space: nowrap;
    overflow: hidden;
}

.avatar-tag img {
    flex: 0 0 auto;
    width: 18px;
    height: 18px;
}

.avatar-tag span {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
}

.avatar-tag--light {
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
}

.avatar-tag--dark {
    background: #fff;
    color: #222;
}

.avatar-card.is-selected,
.avatar-card:hover {
    border-color: rgba(255, 255, 255, 0.24);
}

.avatar-card.is-previewing .avatar-card__image,
.avatar-card:hover .avatar-card__image {
    animation: avatar-card-preview 2.2s ease-in-out infinite alternate;
    filter: saturate(1.04) brightness(1.02);
}

.avatar-card.is-previewing .avatar-card__shade,
.avatar-card:hover .avatar-card__shade {
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.02) 8%, rgba(0, 0, 0, 0.16) 52%, rgba(0, 0, 0, 0.46) 100%);
}

.avatar-card__motion-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.42);
    opacity: 0;
    transform: scale(0.92);
    transition:
        opacity 0.2s ease,
        transform 0.2s ease;
    pointer-events: none;
}

.avatar-card:hover .avatar-card__motion-badge,
.avatar-card.is-previewing .avatar-card__motion-badge {
    opacity: 1;
    transform: scale(1);
}

.avatar-card__motion-icon {
    position: relative;
    width: 14px;
    height: 14px;
}

.avatar-card__motion-icon::before,
.avatar-card__motion-icon::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    margin: auto 0;
    border-radius: 999px;
    background: #fff;
}

.avatar-card__motion-icon::before {
    left: 2px;
    width: 3px;
    height: 12px;
}

.avatar-card__motion-icon::after {
    left: 8px;
    width: 3px;
    height: 8px;
}

.avatar-card--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 168px;
    min-height: 300px;
    border: 1px dashed rgba(255, 255, 255, 0.18);
    background: rgba(255, 255, 255, 0.03);
}

.avatar-card--create {
    display: flex;
    align-items: center;
    justify-content: center;
    min-width: 168px;
    min-height: 300px;
    border-color: rgba(255, 255, 255, 0.06);
    background: #262626;
}

.avatar-card--create__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    color: #8f8f8f;
    font-size: 14px;
    line-height: 1;
}

.avatar-card--create__inner img {
    width: 28px;
    height: 28px;
}

.avatar-card--empty__inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    min-width: 0;
    padding: 22px 18px;
    text-align: center;
    box-sizing: border-box;
}

.avatar-card--empty__inner img {
    width: 40px;
    height: 40px;
}

.avatar-card--empty__inner strong {
    font-size: 18px;
    line-height: 1.15;
    max-width: 5em;
    overflow-wrap: anywhere;
}

.avatar-card--empty__inner span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1.6;
    max-width: 8em;
    overflow-wrap: anywhere;
}

@keyframes avatar-card-preview {
    0% {
        transform: scale(1) translate3d(0, 0, 0);
    }

    100% {
        transform: scale(1.08) translate3d(0, -10px, 0);
    }
}

@media (max-width: 1800px) {
    .avatar-main {
        --card-min-width: clamp(205px, 15vw, 240px);
    }
}

@media (max-width: 1520px) {
    .avatar-main {
        padding-left: 120px;
    }

    .tools-featured-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .tools-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 1200px) {
    :global(html) {
        overflow: hidden !important;
    }

    :global(body) {
        overflow: hidden !important;
    }

    .avatar-page {
        height: 100vh;
        min-height: 100vh;
        padding: 0 16px;
    }

    .avatar-main {
        height: 100vh;
        min-height: 0;
        padding: 210px 16px 32px;
        overflow-x: hidden;
        overflow-y: auto;
    }

    .avatar-main--tools,
    .avatar-main--assets {
        padding-top: 210px;
        padding-bottom: 32px;
        overflow-y: auto;
    }

    .tools-shell {
        min-height: auto;
        gap: 24px;
    }

    .tools-section {
        gap: 14px;
    }

    .tools-section__heading--row {
        flex-direction: column;
        align-items: stretch;
    }

    .tools-section__heading-main {
        width: 100%;
    }

    .tools-search--inline {
        width: 100%;
    }

    .tools-featured-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .tools-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .avatar-layout {
        grid-template-columns: 1fr;
        min-height: auto;
    }

    .avatar-editor {
        min-height: auto;
    }

    .avatar-content {
        min-height: auto;
        overflow: visible;
    }

    .avatar-gallery-scroll {
        min-height: auto;
        padding-right: 0;
        overflow: visible;
    }

    .voice-library-scroll {
        min-height: auto;
        padding-right: 0;
        overflow: visible;
    }

    .avatar-heading {
        position: relative;
        padding: 0 0 12px;
        background: transparent;
    }

    .avatar-gallery {
        grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
    }

    .voice-library {
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    }
}

@media (max-width: 760px) {
    .avatar-page {
        padding-inline: 16px;
    }

    .avatar-main {
        padding-top: 148px;
    }

    .tools-search {
        width: 100%;
        height: 42px;
        padding: 0 18px;
        border-radius: 12px;
    }

    .tools-section__heading h2 {
        font-size: 18px;
    }

    .tools-categories {
        gap: var(--category-chip-gap);
    }

    .tools-featured-grid {
        grid-template-columns: 1fr;
    }

    .tools-featured-card {
        grid-template-columns: minmax(0, 1fr) 108px;
        min-height: 140px;
        padding: 14px 16px;
    }

    .tools-featured-card__copy h3 {
        font-size: 18px;
    }

    .tools-featured-card__visual {
        width: 108px;
        height: 96px;
    }

    .tools-categories button {
        padding: 0 var(--category-chip-padding-x);
        min-width: auto;
    }

    .tools-search--inline {
        width: 100%;
    }

    .tools-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .avatar-heading {
        gap: 24px;
    }

    .voice-filters {
        --category-chip-gap: 16px;
    }

    .editor-section__head,
    .editor-section__row {
        flex-direction: column;
        align-items: flex-start;
    }

    .mini-action,
    .work-name,
    .avatar-gallery,
    .voice-library {
        width: 100%;
    }

    .avatar-gallery {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .voice-library {
        grid-template-columns: 1fr;
    }

    .voice-card__meta {
        max-width: calc(100% - 136px);
    }

    .voice-card__favorite {
        right: 102px;
    }

    .voice-card__action {
        right: 10px;
        padding-inline: 12px;
    }

    .voice-create-modal {
        width: min(calc(100vw - 24px), 700px);
        min-height: auto;
    }

    .voice-trim-modal {
        padding: 20px;
    }

    .voice-trim-modal__file,
    .voice-trim-modal__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .voice-trim-modal__file span {
        flex-shrink: 1;
        line-height: 18px;
    }

    .voice-trim-modal__actions button {
        width: 100%;
    }

    .voice-create-modal__cover-hero {
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .voice-create-modal__method-grid {
        grid-template-columns: 1fr;
    }

    .voice-create-modal__sample-head {
        flex-direction: column;
    }

    .voice-create-modal__sample-badge {
        align-self: flex-start;
    }

    .voice-create-modal__form {
        grid-template-columns: 1fr;
        gap: 16px;
    }

    .voice-create-modal__field {
        flex-direction: column;
        align-items: flex-start;
    }

    .voice-create-modal__setting-row {
        width: 100%;
        gap: 12px;
    }

    .voice-create-modal__input-shell {
        width: 100%;
    }

    .voice-create-modal__input-shell--short {
        width: min(100%, 132px);
    }

    .voice-create-modal__footer {
        flex-direction: column;
        align-items: stretch;
    }

    .voice-create-modal__submit {
        width: 100%;
    }
}

@media (max-width: 820px) {
    .avatar-main {
        padding-top: 232px;
    }

    .avatar-main--tools,
    .avatar-main--assets {
        padding-top: 232px;
    }
}
</style>
