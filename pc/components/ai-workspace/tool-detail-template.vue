<template>
    <section class="tool-template">
        <aside class="tool-template__sidebar">
            <div class="tool-template-panel">
                <div class="tool-template-panel__header">
                    <button class="tool-template-panel__back" type="button" @click="goBackToTools">
                        <span class="tool-template-panel__back-icon" aria-hidden="true"></span>
                        <span>返回</span>
                    </button>
                    <h1>{{ toolPageTitle }}</h1>
                    <span class="tool-template-panel__header-spacer" aria-hidden="true"></span>
                </div>

                <div class="tool-template-panel__body">
                    <section class="tool-block">
                        <div class="tool-block__head" :class="{ 'tool-block__head--inline-select': isAiFittingTool }">
                            <span>{{ currentTool.uploadTitle || texts.uploadImage }}</span>
                            <div v-if="isAiFittingTool" class="tool-select-dropdown tool-fitting-head-select" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button tool-fitting-head-select__button"
                                    type="button"
                                    :aria-expanded="aiFittingUploadCategoryMenuOpen"
                                    @click="aiFittingUploadCategoryMenuOpen = !aiFittingUploadCategoryMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getAiFittingUploadCategoryLabel(aiFittingUploadCategory) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': aiFittingUploadCategoryMenuOpen }" />
                                </button>

                                <div
                                    v-if="aiFittingUploadCategoryMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown tool-fitting-head-select__menu"
                                >
                                    <button
                                        v-for="item in aiFittingUploadCategoryOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': aiFittingUploadCategory === item.key }"
                                        type="button"
                                        @click="setAiFittingUploadCategory(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div v-if="isLocalRedrawTool" class="tool-compare-upload">
                            <div class="tool-compare-upload__item">
                                <div class="tool-compare-upload__label">原图</div>
                                <div class="tool-upload-card tool-upload-card--compact" :class="{ 'is-filled': !!originPreview }">
                                    <template v-if="originPreview">
                                        <div class="tool-local-panel">
                                            <div class="tool-local-panel__viewport">
                                                <img class="tool-local-panel__image" :src="originPreview" :alt="originName || currentTool.detailName" />
                                            </div>
                                        </div>
                                        <div class="tool-upload-card__actions">
                                            <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                                <span>图库上传</span>
                                            </button>
                                            <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                                <img :src="localUploadIcon" alt="" />
                                                <span>本地上传</span>
                                            </button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                            <img :src="addIcon" alt="" />
                                            <strong>上传原图</strong>
                                            <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                                <span>图库上传</span>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div class="tool-compare-upload__item">
                                <div class="tool-compare-upload__row">
                                    <div class="tool-compare-upload__label">蒙版</div>
                                    <button
                                        v-if="originPreview && hasMaskStroke"
                                        class="tool-mask-clear"
                                        type="button"
                                        @click="clearMaskDrawing"
                                    >
                                        清空蒙版
                                    </button>
                                </div>
                                <div class="tool-upload-card tool-upload-card--compact" :class="{ 'is-filled': !!originPreview }">
                                    <template v-if="originPreview">
                                        <div class="tool-local-panel tool-local-panel--mask-preview">
                                            <div class="tool-local-panel__viewport">
                                                <img
                                                    class="tool-local-panel__image"
                                                    :src="originPreview"
                                                    :alt="originName || currentTool.detailName"
                                                />
                                                <img
                                                    v-if="maskPreview"
                                                    class="tool-local-panel__mask-preview-image"
                                                    :src="maskPreview"
                                                    alt="蒙版预览"
                                                />
                                            </div>
                                            <button
                                                class="tool-mask-draw-button"
                                                type="button"
                                                @click="openMaskEditor"
                                            >
                                                {{ hasMaskStroke ? '编辑蒙版' : '绘制蒙版' }}
                                            </button>
                                        </div>
                                    </template>
                                    <template v-else>
                                        <div class="tool-upload-card__placeholder tool-upload-card__placeholder--subtle">
                                            <img :src="addIcon" alt="" />
                                            <strong>上传原图后绘制蒙版</strong>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <div v-else-if="isHotCloneTool" class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!hotCloneProductImages.length }">
                            <template v-if="hotCloneProductImages.length">
                                <div class="tool-suite-upload">
                                    <div class="tool-suite-upload__grid">
                                        <article
                                            v-for="(item, index) in hotCloneProductImages"
                                            :key="`${item.name}-${index}`"
                                            class="tool-suite-upload__item"
                                        >
                                            <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-商品图-${index + 1}`" />
                                            <span class="tool-suite-upload__badge">{{ index === 0 ? '主商品图' : `商品图 ${index + 1}` }}</span>
                                            <button
                                                class="tool-suite-upload__remove"
                                                type="button"
                                                @click.stop="removeHotCloneProductImage(index)"
                                            >
                                                ×
                                            </button>
                                        </article>

                                        <button
                                            v-if="hotCloneProductImages.length < maxHotCloneProductImages"
                                            class="tool-suite-upload__add"
                                            type="button"
                                            @click="triggerUpload('hot-clone-product')"
                                        >
                                            <img :src="addIcon" alt="" />
                                            <span>继续添加</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('hot-clone-product')">
                                        <span>图库上传</span>
                                    </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('hot-clone-product')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite" @click="triggerUpload('hot-clone-product')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传商品图</strong>
                                    <span>支持本地上传或从图库选择，最多 5 张商品图</span>
                                    <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('hot-clone-product')">
                                        <span>图库上传</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div v-else-if="isProductMultiAngleTool" class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!productMultiAngleImages.length }">
                            <template v-if="productMultiAngleImages.length">
                                <div class="tool-suite-upload">
                                    <div class="tool-suite-upload__grid">
                                        <article
                                            v-for="(item, index) in productMultiAngleImages"
                                            :key="`${item.name}-${index}`"
                                            class="tool-suite-upload__item"
                                        >
                                            <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-原图-${index + 1}`" />
                                            <span class="tool-suite-upload__badge">{{ index === 0 ? '主原图' : `原图 ${index + 1}` }}</span>
                                            <button
                                                class="tool-suite-upload__remove"
                                                type="button"
                                                @click.stop="removeProductMultiAngleImage(index)"
                                            >
                                                ×
                                            </button>
                                        </article>

                                        <button
                                            v-if="productMultiAngleImages.length < maxProductMultiAngleImages"
                                            class="tool-suite-upload__add"
                                            type="button"
                                            @click="triggerUpload('product-multi-angle')"
                                        >
                                            <img :src="addIcon" alt="" />
                                            <span>继续添加</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('product-multi-angle')">
                                        <span>图库上传</span>
                                    </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('product-multi-angle')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite" @click="triggerUpload('product-multi-angle')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传原图</strong>
                                    <span>支持本地上传或从图库选择，最多 10 张商品图</span>
                                    <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('product-multi-angle')">
                                        <span>图库上传</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <template v-else-if="isOneClickCleanupTool">
                            <div class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!oneClickCleanupImages.length }">
                                <template v-if="oneClickCleanupImages.length">
                                    <div class="tool-suite-upload">
                                        <div class="tool-suite-upload__grid">
                                            <article
                                                v-for="(item, index) in oneClickCleanupImages"
                                                :key="`${item.name}-${index}`"
                                                class="tool-suite-upload__item"
                                            >
                                                <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-主体图-${index + 1}`" />
                                                <span class="tool-suite-upload__badge">{{ index === 0 ? '主主体图' : `主体图 ${index + 1}` }}</span>
                                                <button
                                                    class="tool-suite-upload__remove"
                                                    type="button"
                                                    @click.stop="removeOneClickCleanupImage(index)"
                                                >
                                                    ×
                                                </button>
                                            </article>

                                            <button
                                                v-if="oneClickCleanupImages.length < maxOneClickCleanupImages"
                                                class="tool-suite-upload__add"
                                                type="button"
                                                @click="triggerUpload('cleanup-images')"
                                            >
                                                <img :src="addIcon" alt="" />
                                                <span>继续添加</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('cleanup-images')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('cleanup-images')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite tool-upload-card__placeholder--cleanup" @click="triggerUpload('cleanup-images')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传主体图</strong>
                                        <span>支持 png、jpg、jpeg 图片格式，单张图片大小不超过 5MB，最长边小于 6000px</span>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('cleanup-images')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="tool-cleanup-capacity">
                                <span class="tool-cleanup-capacity__badge">
                                    <img :src="vipBadgeIcon" alt="" />
                                    VIP
                                </span>
                                <span class="tool-cleanup-capacity__text">批处理扩容为「{{ maxOneClickCleanupImages }}张」图片</span>
                                <button class="tool-cleanup-capacity__action" type="button">扩容升级</button>
                            </div>
                        </template>

                        <div v-else-if="isProductSuiteTool" class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!productSuiteImages.length }">
                            <template v-if="productSuiteImages.length">
                                <div class="tool-suite-upload">
                                    <div class="tool-suite-upload__grid">
                                        <article
                                            v-for="(item, index) in productSuiteImages"
                                            :key="`${item.name}-${index}`"
                                            class="tool-suite-upload__item"
                                        >
                                            <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-${index + 1}`" />
                                            <span class="tool-suite-upload__badge">{{ index === 0 ? '主图' : `商品图 ${index + 1}` }}</span>
                                            <button
                                                class="tool-suite-upload__remove"
                                                type="button"
                                                @click.stop="removeProductSuiteImage(index)"
                                            >
                                                ×
                                            </button>
                                        </article>

                                        <button
                                            v-if="productSuiteImages.length < maxProductSuiteImages"
                                            class="tool-suite-upload__add"
                                            type="button"
                                            @click="triggerUpload('origin')"
                                        >
                                            <img :src="addIcon" alt="" />
                                            <span>继续添加</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                        <span>图库上传</span>
                                    </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite" @click="triggerUpload('origin')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传商品图片</strong>
                                    <span>支持本地上传或从图库选择，最多 3 张商品图</span>
                                    <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                        <span>图库上传</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <div v-else-if="isFashionLookbookTool" class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!fashionLookbookClothesImages.length }">
                            <template v-if="fashionLookbookClothesImages.length">
                                <div class="tool-suite-upload">
                                    <div class="tool-suite-upload__grid">
                                        <article
                                            v-for="(item, index) in fashionLookbookClothesImages"
                                            :key="`${item.name}-${index}`"
                                            class="tool-suite-upload__item"
                                        >
                                            <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-${index + 1}`" />
                                            <span class="tool-suite-upload__badge">{{ index === 0 ? '主服饰图' : `服饰图 ${index + 1}` }}</span>
                                            <button
                                                class="tool-suite-upload__remove"
                                                type="button"
                                                @click.stop="removeFashionLookbookClothesImage(index)"
                                            >
                                                脳
                                            </button>
                                        </article>

                                        <button
                                            v-if="fashionLookbookClothesImages.length < maxFashionLookbookClothesImages"
                                            class="tool-suite-upload__add"
                                            type="button"
                                            @click="triggerUpload('fashion-clothes')"
                                        >
                                            <img :src="addIcon" alt="" />
                                            <span>继续添加</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('fashion-clothes')">
                                        <span>图库上传</span>
                                    </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('fashion-clothes')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite" @click="triggerUpload('fashion-clothes')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传服饰图</strong>
                                    <span>支持本地上传或从图库选择，最多 6 张服饰图</span>
                                    <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('fashion-clothes')">
                                        <span>图库上传</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <template v-else-if="isShoeTryonTool">
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!shoeTryonModelPreview }">
                                <template v-if="shoeTryonModelPreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="shoeTryonModelPreview" :alt="shoeTryonModelName || `${currentTool.detailName}-模特图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('shoe-tryon-model')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('shoe-tryon-model')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('shoe-tryon-model')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传模特图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('shoe-tryon-model')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="shoeTryonModelExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in shoeTryonModelExamples"
                                        :key="`shoe-model-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': shoeTryonModelPreview === item.image }]"
                                        type="button"
                                        @click="applyShoeTryonExample('shoe-tryon-model', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="isModelWearTool">
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!modelWearModelPreview }">
                                <template v-if="modelWearModelPreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="modelWearModelPreview" :alt="modelWearModelName || `${currentTool.detailName}-模特图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('model-wear-model')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('model-wear-model')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('model-wear-model')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传模特图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('model-wear-model')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="modelWearModelExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in modelWearModelExamples"
                                        :key="`wear-model-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': modelWearModelPreview === item.image }]"
                                        type="button"
                                        @click="applyModelWearExample('model-wear-model', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        <template v-else-if="isAiFittingTool">
                            <div :class="['tool-fitting-upload-grid', { 'is-split': isAiFittingSplitGarment }]">
                                <template v-if="!isAiFittingSplitGarment">
                                    <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!originPreview }">
                                        <template v-if="originPreview">
                                            <div class="tool-local-panel tool-local-panel--outpaint">
                                                <div class="tool-local-panel__viewport">
                                                    <img class="tool-local-panel__image" :src="originPreview" :alt="originName || `${currentTool.detailName}-服装图`" />
                                                </div>
                                            </div>
                                            <div class="tool-upload-card__actions">
                                                <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                                    <span>图库上传</span>
                                                </button>
                                                <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                                    <img :src="localUploadIcon" alt="" />
                                                    <span>本地上传</span>
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                                <img :src="addIcon" alt="" />
                                                <strong>上传服装</strong>
                                                <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                                    <span>图库上传</span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <template v-else>
                                    <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!aiFittingTopPreview }">
                                        <template v-if="aiFittingTopPreview">
                                            <div class="tool-local-panel tool-local-panel--outpaint">
                                                <div class="tool-local-panel__viewport">
                                                    <img class="tool-local-panel__image" :src="aiFittingTopPreview" :alt="aiFittingTopName || `${currentTool.detailName}-上装图`" />
                                                </div>
                                            </div>
                                            <div class="tool-upload-card__actions">
                                                <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('ai-fitting-top')">
                                                    <span>图库上传</span>
                                                </button>
                                                <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('ai-fitting-top')">
                                                    <img :src="localUploadIcon" alt="" />
                                                    <span>本地上传</span>
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="tool-upload-card__placeholder tool-upload-card__placeholder--garment" @click="triggerUpload('ai-fitting-top')">
                                                <img :src="addIcon" alt="" />
                                                <strong>上传上装</strong>
                                                <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('ai-fitting-top')">
                                                    <span>图库上传</span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>

                                    <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!aiFittingBottomPreview }">
                                        <template v-if="aiFittingBottomPreview">
                                            <div class="tool-local-panel tool-local-panel--outpaint">
                                                <div class="tool-local-panel__viewport">
                                                    <img class="tool-local-panel__image" :src="aiFittingBottomPreview" :alt="aiFittingBottomName || `${currentTool.detailName}-下装图`" />
                                                </div>
                                            </div>
                                            <div class="tool-upload-card__actions">
                                                <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('ai-fitting-bottom')">
                                                    <span>图库上传</span>
                                                </button>
                                                <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('ai-fitting-bottom')">
                                                    <img :src="localUploadIcon" alt="" />
                                                    <span>本地上传</span>
                                                </button>
                                            </div>
                                        </template>
                                        <template v-else>
                                            <div class="tool-upload-card__placeholder tool-upload-card__placeholder--garment" @click="triggerUpload('ai-fitting-bottom')">
                                                <img :src="addIcon" alt="" />
                                                <strong>上传下装</strong>
                                                <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('ai-fitting-bottom')">
                                                    <span>图库上传</span>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div v-else-if="isBuyerShowTool || isSellingPointCardTool" class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!originPreview }">
                            <template v-if="originPreview">
                                <div class="tool-local-panel tool-local-panel--outpaint">
                                    <div class="tool-local-panel__viewport">
                                        <img class="tool-local-panel__image" :src="originPreview" :alt="originName || currentTool.detailName" />
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                        <span>图库上传</span>
                                    </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传商品图</strong>
                                    <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                        <span>图库上传</span>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <template v-else-if="isHairstyleTool">
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!originPreview }">
                                <template v-if="originPreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="originPreview" :alt="originName || currentTool.detailName" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传人物原图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="hairstyleOriginExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in hairstyleOriginExamples"
                                        :key="`hairstyle-origin-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': originPreview === item.image }]"
                                        type="button"
                                        @click="applyHairstyleExample('origin', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </template>

                        <div v-else-if="isOutpaintTool || isPhotoRestoreTool || isLineDrawingTool || isStyleTransferTool" class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!originPreview }">
                            <template v-if="originPreview">
                                <div class="tool-local-panel tool-local-panel--outpaint">
                                    <div class="tool-local-panel__viewport">
                                        <img class="tool-local-panel__image" :src="originPreview" :alt="originName || currentTool.detailName" />
                                    </div>
                                </div>
                                <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                            <span>图库上传</span>
                                        </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                    <img :src="addIcon" alt="" />
                                    <strong>上传图像</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                            <span>图库上传</span>
                                        </button>
                                </div>
                            </template>
                        </div>

                        <div v-else class="tool-upload-card" :class="{ 'is-filled': !!originPreview }">
                            <template v-if="originPreview">
                                <div class="tool-upload-card__stage">
                                    <div class="tool-upload-card__edge"></div>
                                    <img class="tool-upload-card__image" :src="originPreview" :alt="originName || currentTool.detailName" />
                                    <div class="tool-upload-card__edge"></div>
                                </div>
                                <div class="tool-upload-card__actions">
                                            <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('origin')">
                                                <span>图库上传</span>
                                            </button>
                                    <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('origin')">
                                        <img :src="localUploadIcon" alt="" />
                                        <span>本地上传</span>
                                    </button>
                                </div>
                            </template>
                            <template v-else>
                                <div class="tool-upload-card__placeholder" @click="triggerUpload('origin')">
                                    <img :src="addIcon" alt="" />
                                    <strong>{{ texts.uploadImage }}</strong>
                                            <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('origin')">
                                                <span>图库上传</span>
                                            </button>
                                </div>
                            </template>
                        </div>
                    </section>

                    <section v-if="isLocalRedrawTool" class="tool-block">
                        <div class="tool-block__head">
                            <span>{{ currentTool.promptTitle || '输入对图像效果的描述' }}</span>
                        </div>
                        <div class="tool-prompt-card">
                            <textarea
                                v-model="localRedrawPrompt"
                                :placeholder="currentTool.promptPlaceholder || '请输入希望在蒙版区域生成的内容描述'"
                            />
                        </div>
                    </section>

                    <section v-else-if="isOutpaintTool" class="tool-block">
                        <div class="tool-block__head">
                            <span>外补画板</span>
                        </div>
                        <div class="tool-outpaint-card">
                            <div
                                v-for="item in outpaintControls"
                                :key="item.key"
                                class="tool-outpaint-control"
                            >
                                <div class="tool-outpaint-control__label">{{ item.label }}</div>
                                <div class="tool-outpaint-control__main">
                                    <input
                                        :value="item.value"
                                        type="range"
                                        min="0"
                                        max="8192"
                                        step="1"
                                        @input="updateOutpaintValue(item.key, Number(($event.target as HTMLInputElement).value))"
                                    />
                                    <span>{{ item.value }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <template v-else-if="isPhotoRestoreTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择修复模型</span>
                            </div>
                            <div class="tool-option-grid">
                                <button
                                    v-for="item in photoRestoreModeOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', { 'is-active': photoRestoreMode === item.key }]"
                                    type="button"
                                    @click="photoRestoreMode = item.key"
                                >
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择模型</span>
                            </div>
                            <div class="tool-option-grid tool-option-grid--compact">
                                <button
                                    v-for="item in photoRestoreModelOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', { 'is-active': photoRestoreModel === item.key }]"
                                    type="button"
                                    @click="photoRestoreModel = item.key"
                                >
                                    <span v-if="item.badge" class="tool-option-card__badge">{{ item.badge }}</span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isLineDrawingTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择模型</span>
                            </div>
                            <div class="tool-option-grid tool-option-grid--compact">
                                <button
                                    v-for="item in photoRestoreModelOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', { 'is-active': lineDrawingModel === item.key }]"
                                    type="button"
                                    @click="lineDrawingModel = item.key"
                                >
                                    <span v-if="item.badge" class="tool-option-card__badge">{{ item.badge }}</span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '描述图片关键词' }}</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillImagePrompt">获取图片提示词</button>
                                    <button type="button" @click="openPromptWorkbench">提示词工作台</button>
                                </div>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--tall">
                                <textarea
                                    v-model="lineDrawingPrompt"
                                    :placeholder="currentTool.promptPlaceholder || '描述图片关键词，帮助 AI 更好地理解图片信息'"
                                />
                                <button class="tool-prompt-card__clear" type="button" @click="clearLineDrawingPrompt">清空</button>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isImageTranslateTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>原文语言</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="imageTranslateSourceMenuOpen"
                                    @click="imageTranslateSourceMenuOpen = !imageTranslateSourceMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getImageTranslateLanguageLabel(imageTranslateSourceLanguage) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': imageTranslateSourceMenuOpen }" />
                                </button>

                                <div
                                    v-if="imageTranslateSourceMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in imageTranslateSourceLanguageOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': imageTranslateSourceLanguage === item.key }"
                                        type="button"
                                        @click="setImageTranslateSourceLanguage(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>目标语言</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="imageTranslateTargetMenuOpen"
                                    @click="imageTranslateTargetMenuOpen = !imageTranslateTargetMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getImageTranslateLanguageLabel(imageTranslateTargetLanguage) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': imageTranslateTargetMenuOpen }" />
                                </button>

                                <div
                                    v-if="imageTranslateTargetMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in imageTranslateTargetLanguageOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': imageTranslateTargetLanguage === item.key }"
                                        type="button"
                                        @click="setImageTranslateTargetLanguage(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '术语补充（选填）' }}</span>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="imageTranslatePrompt"
                                    maxlength="300"
                                    :placeholder="currentTool.promptPlaceholder || '可补充品牌名、专有名词、行业术语，或说明哪些文字不需要翻译'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ imageTranslatePrompt.length }}/300</span>
                                    <button type="button" @click="imageTranslatePrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isHairstyleTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传发型参考图</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!hairstyleReferencePreview }">
                                <template v-if="hairstyleReferencePreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="hairstyleReferencePreview" :alt="hairstyleReferenceName || `${currentTool.detailName}-发型参考图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('hairstyle-reference')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('hairstyle-reference')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('hairstyle-reference')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传发型参考图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('hairstyle-reference')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="hairstyleReferenceExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in hairstyleReferenceExamples"
                                        :key="`hairstyle-reference-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': hairstyleReferencePreview === item.image }]"
                                        type="button"
                                        @click="applyHairstyleExample('hairstyle-reference', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isOneClickCleanupTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>消除选项</span>
                                <span class="tool-block__tip">{{ oneClickCleanupSelectedOptions.length }}/{{ oneClickCleanupOptions.length }}</span>
                            </div>
                            <div class="tool-cleanup-grid">
                                <button
                                    v-for="item in oneClickCleanupOptions"
                                    :key="item.key"
                                    :class="['tool-style-preset-card', 'tool-cleanup-option', { 'is-active': oneClickCleanupSelectedOptions.includes(item.key) }]"
                                    type="button"
                                    @click="toggleOneClickCleanupOption(item.key)"
                                >
                                    <div class="tool-cleanup-option__image">
                                        <img :src="item.image" :alt="item.title" />
                                    </div>
                                    <strong>{{ item.title }}</strong>
                                </button>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isStyleTransferTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>风格选择</span>
                            </div>
                            <div class="tool-option-grid tool-option-grid--compact tool-option-grid--style">
                                <button
                                    v-for="item in styleTransferOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', { 'is-active': styleTransferStyle === item.key }]"
                                    type="button"
                                    @click="styleTransferStyle = item.key"
                                >
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isProductImageTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>场景选择</span>
                            </div>
                            <div class="tool-style-toolbar">
                                <div class="tool-style-tabs">
                                    <button
                                        v-for="item in productImageStyleTabOptions"
                                        :key="item.key"
                                        :class="['tool-style-tab', { 'is-active': productImageStyleTab === item.key }]"
                                        type="button"
                                        @click="setProductImageStyleTab(item.key)"
                                    >
                                        {{ item.label }}
                                    </button>
                                </div>
                                <div v-if="productImageStyleTab !== 'custom'" class="tool-select-dropdown tool-style-toolbar__select" @click.stop>
                                    <button
                                        class="tool-select-card tool-select-card--button"
                                        type="button"
                                        :aria-expanded="productImageSceneCategoryMenuOpen"
                                        @click="productImageSceneCategoryMenuOpen = !productImageSceneCategoryMenuOpen"
                                    >
                                        <span class="tool-select-card__value">{{ productImageSceneCategoryLabel }}</span>
                                        <img :src="downIcon" alt="" :class="{ 'is-open': productImageSceneCategoryMenuOpen }" />
                                    </button>

                                    <div
                                        v-if="productImageSceneCategoryMenuOpen"
                                        class="tool-filter-menu tool-filter-menu--dropdown tool-style-toolbar__menu"
                                    >
                                        <button
                                            v-for="item in productImageSceneCategoryOptions"
                                            :key="item.key"
                                            :class="{ 'is-active': productImageSceneCategory === item.key }"
                                            type="button"
                                            @click="setProductImageSceneCategory(item.key)"
                                        >
                                            <span>{{ item.label }}</span>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="productImageStyleTab !== 'custom'" class="tool-style-preset-grid">
                                <button
                                    v-for="item in productImageVisibleStylePresets"
                                    :key="item.key"
                                    :class="['tool-style-preset-card', { 'is-active': productImageSelectedPresetKey === item.key }]"
                                    type="button"
                                    @click="selectProductImageStylePreset(item.key)"
                                >
                                    <span v-if="item.vip" class="tool-style-preset-card__vip">
                                        <img :src="vipBadgeIcon" alt="" />
                                        VIP
                                    </span>
                                    <div class="tool-style-preset-card__image">
                                        <img :src="item.image" :alt="item.title" />
                                    </div>
                                </button>
                            </div>

                            <div v-else class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!productImageCustomStylePreview }">
                                <template v-if="productImageCustomStylePreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="productImageCustomStylePreview" :alt="productImageCustomStyleName || `${currentTool.detailName}-场景图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('product-image-style')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('product-image-style')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('product-image-style')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传场景图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('product-image-style')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>生成图片尺寸</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="productImageRatioMenuOpen"
                                    @click="productImageRatioMenuOpen = !productImageRatioMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ productImageSizeDisplayLabel }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': productImageRatioMenuOpen }" />
                                </button>

                                <div
                                    v-if="productImageRatioMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productImageSizeOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': productImageSizeKey === item.key }"
                                        type="button"
                                        @click="setProductImageSize(item.key); productImageRatioMenuOpen = false"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>

                            <div class="tool-size-editor">
                                <label class="tool-size-input">
                                    <span>W:</span>
                                    <input
                                        :value="productImageWidth"
                                        inputmode="numeric"
                                        @input="onProductImageDimensionInput('width', $event)"
                                    />
                                    <em>px</em>
                                </label>
                                <label class="tool-size-input">
                                    <span>H:</span>
                                    <input
                                        :value="productImageHeight"
                                        inputmode="numeric"
                                        @input="onProductImageDimensionInput('height', $event)"
                                    />
                                    <em>px</em>
                                </label>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isFashionLookbookTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传模特图</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!fashionLookbookModelPreview }">
                                <template v-if="fashionLookbookModelPreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="fashionLookbookModelPreview" :alt="fashionLookbookModelName || `${currentTool.detailName}-模特图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                    <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('fashion-model')">
                                        <span>图库上传</span>
                                    </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('fashion-model')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('fashion-model')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传模特图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('fashion-model')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>出图比例</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="fashionLookbookRatioMenuOpen"
                                    @click="fashionLookbookRatioMenuOpen = !fashionLookbookRatioMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ fashionLookbookRatioOptions.find((item) => item.key === fashionLookbookRatio)?.label || getFashionLookbookRatioLabel(fashionLookbookRatio) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': fashionLookbookRatioMenuOpen }" />
                                </button>

                                <div
                                    v-if="fashionLookbookRatioMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in fashionLookbookRatioOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': fashionLookbookRatio === item.key }"
                                        type="button"
                                        @click="setFashionLookbookRatio(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '补充描述' }}</span>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="fashionLookbookPrompt"
                                    maxlength="300"
                                    :placeholder="currentTool.promptPlaceholder || '可补充模特气质、拍摄场景、服饰搭配方式等要求'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ fashionLookbookPrompt.length }}/300</span>
                                    <button type="button" @click="fashionLookbookPrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isShoeTryonTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传鞋图</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!shoeTryonShoePreview }">
                                <template v-if="shoeTryonShoePreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="shoeTryonShoePreview" :alt="shoeTryonShoeName || `${currentTool.detailName}-鞋图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('shoe-tryon-shoe')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('shoe-tryon-shoe')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('shoe-tryon-shoe')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传鞋图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('shoe-tryon-shoe')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="shoeTryonShoeExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in shoeTryonShoeExamples"
                                        :key="`shoe-image-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': shoeTryonShoePreview === item.image }]"
                                        type="button"
                                        @click="applyShoeTryonExample('shoe-tryon-shoe', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isModelWearTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传穿戴图</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!modelWearItemPreview }">
                                <template v-if="modelWearItemPreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="modelWearItemPreview" :alt="modelWearItemName || `${currentTool.detailName}-穿戴图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('model-wear-item')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('model-wear-item')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('model-wear-item')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传穿戴图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('model-wear-item')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="modelWearItemExamples.length" class="tool-example-strip">
                                <div class="tool-example-strip__head">
                                    <span>没有图片？试试以下图片</span>
                                </div>
                                <div class="tool-example-grid">
                                    <button
                                        v-for="item in modelWearItemExamples"
                                        :key="`wear-item-${item.id}`"
                                        :class="['tool-example-card', { 'is-active': modelWearItemPreview === item.image }]"
                                        type="button"
                                        @click="applyModelWearExample('model-wear-item', item)"
                                    >
                                        <img :src="item.image" :alt="item.name" />
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>补充描述（选填）</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillModelWearPrompt">AI 帮写</button>
                                </div>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="modelWearPrompt"
                                    maxlength="300"
                                    placeholder="可补充穿搭风格、服饰类型、镜头氛围或你希望强化的穿戴效果"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ modelWearPrompt.length }}/300</span>
                                    <button type="button" @click="modelWearPrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isAiFittingTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择模特姿势</span>
                            </div>

                            <div class="tool-fitting-mode-tabs">
                                <button
                                    v-for="item in aiFittingModeOptions"
                                    :key="item.key"
                                    :class="['tool-fitting-mode-tab', { 'is-active': aiFittingMode === item.key }]"
                                    type="button"
                                    @click="setAiFittingMode(item.key)"
                                >
                                    {{ item.label }}
                                </button>
                            </div>

                            <template v-if="aiFittingMode !== 'custom'">
                                <div class="tool-fitting-filter-grid">
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="aiFittingModelMenuOpen"
                                            @click="aiFittingModelMenuOpen = !aiFittingModelMenuOpen"
                                        >
                                            <span class="tool-select-card__value">{{ getAiFittingModelFilterLabel(aiFittingModelFilter) }}</span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': aiFittingModelMenuOpen }" />
                                        </button>

                                        <div
                                            v-if="aiFittingModelMenuOpen"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="item in aiFittingModelFilterOptions"
                                                :key="item.key"
                                                :class="{ 'is-active': aiFittingModelFilter === item.key }"
                                                type="button"
                                                @click="setAiFittingModelFilter(item.key)"
                                            >
                                                <span>{{ item.label }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="aiFittingClothesMenuOpen"
                                            @click="aiFittingClothesMenuOpen = !aiFittingClothesMenuOpen"
                                        >
                                            <span class="tool-select-card__value">{{ getAiFittingClothesFilterLabel(aiFittingClothesFilter) }}</span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': aiFittingClothesMenuOpen }" />
                                        </button>

                                        <div
                                            v-if="aiFittingClothesMenuOpen"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="item in aiFittingClothesFilterOptions"
                                                :key="item.key"
                                                :class="{ 'is-active': aiFittingClothesFilter === item.key }"
                                                type="button"
                                                @click="setAiFittingClothesFilter(item.key)"
                                            >
                                                <span>{{ item.label }}</span>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="aiFittingPoseMenuOpen"
                                            @click="aiFittingPoseMenuOpen = !aiFittingPoseMenuOpen"
                                        >
                                            <span class="tool-select-card__value">{{ getAiFittingPoseFilterLabel(aiFittingPoseFilter) }}</span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': aiFittingPoseMenuOpen }" />
                                        </button>

                                        <div
                                            v-if="aiFittingPoseMenuOpen"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="item in aiFittingPoseFilterOptions"
                                                :key="item.key"
                                                :class="{ 'is-active': aiFittingPoseFilter === item.key }"
                                                type="button"
                                                @click="setAiFittingPoseFilter(item.key)"
                                            >
                                                <span>{{ item.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-fitting-grid">
                                    <button
                                        v-for="item in aiFittingDisplayPresets"
                                        :key="item.id"
                                        :class="['tool-fitting-card', { 'is-active': isAiFittingPresetActive(item) }]"
                                        type="button"
                                        @click="selectAiFittingPreset(item)"
                                    >
                                        <span v-if="item.vip" class="tool-fitting-card__vip">
                                            <img :src="vipBadgeIcon" alt="" />
                                            VIP
                                        </span>
                                        <img :src="item.image" :alt="item.name" />
                                        <span v-if="aiFittingMode === 'group'" class="tool-fitting-card__group-badge">2-4 姿势</span>
                                    </button>
                                </div>
                            </template>

                            <div v-else class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!aiFittingCustomModelImages.length }">
                                <template v-if="aiFittingCustomModelImages.length">
                                    <div class="tool-suite-upload">
                                        <div class="tool-suite-upload__grid">
                                            <article
                                                v-for="(item, index) in aiFittingCustomModelImages"
                                                :key="`${item.name}-${index}`"
                                                class="tool-suite-upload__item"
                                            >
                                                <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-模特图-${index + 1}`" />
                                                <span class="tool-suite-upload__badge">{{ `模特图 ${index + 1}` }}</span>
                                                <button
                                                    class="tool-suite-upload__remove"
                                                    type="button"
                                                    @click.stop="removeAiFittingCustomModel(index)"
                                                >
                                                    ×
                                                </button>
                                            </article>

                                            <button
                                                v-if="aiFittingCustomModelImages.length < maxAiFittingCustomModels"
                                                class="tool-suite-upload__add"
                                                type="button"
                                                @click="triggerUpload('ai-fitting-model')"
                                            >
                                                <img :src="addIcon" alt="" />
                                                <span>继续添加</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('ai-fitting-model')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('ai-fitting-model')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('ai-fitting-model')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传模特图</strong>
                                        <span>支持本地上传或从图库选择，最多 4 张模特图</span>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('ai-fitting-model')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div class="tool-fitting-selected">
                                <div class="tool-fitting-selected__head">
                                    <span>已选模特</span>
                                    <strong>{{ aiFittingSelectionCount }}/{{ aiFittingSelectionLimit }}</strong>
                                </div>
                                <div class="tool-fitting-selected__grid">
                                    <div
                                        v-for="index in aiFittingSelectionLimit"
                                        :key="`ai-fitting-thumb-${index}`"
                                        class="tool-fitting-selected__item"
                                    >
                                        <img v-if="aiFittingSelectedThumbs[index - 1]" :src="aiFittingSelectedThumbs[index - 1]" :alt="`已选模特-${index}`" />
                                    </div>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isBuyerShowTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传参考图（可选）</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!buyerShowReferencePreview }">
                                <template v-if="buyerShowReferencePreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="buyerShowReferencePreview" :alt="buyerShowReferenceName || `${currentTool.detailName}-参考图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('buyer-show-reference')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('buyer-show-reference')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('buyer-show-reference')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传参考图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('buyer-show-reference')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>预设选择（可选）</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillBuyerShowPreset">AI 帮填</button>
                                </div>
                            </div>
                            <div class="tool-buyer-show-grid">
                                <div
                                    v-for="item in buyerShowPresetConfigs"
                                    :key="item.field"
                                    class="tool-buyer-show-field"
                                >
                                    <span class="tool-buyer-show-field__label">{{ item.label }}</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="buyerShowOpenField === item.field"
                                            @click="toggleBuyerShowFieldMenu(item.field)"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !buyerShowPresetValues[item.field] }">
                                                {{ getBuyerShowPresetDisplay(item.field) }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': buyerShowOpenField === item.field }" />
                                        </button>

                                        <div
                                            v-if="buyerShowOpenField === item.field"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in item.options"
                                                :key="option.key"
                                                :class="{ 'is-active': buyerShowPresetValues[item.field] === option.key }"
                                                type="button"
                                                @click="setBuyerShowPreset(item.field, option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '细节补充（选填）' }}</span>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="buyerShowPrompt"
                                    maxlength="500"
                                    :placeholder="currentTool.promptPlaceholder || '补充其他细节，如颜色偏好、特殊场景要求或你想强化的真实感方向'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ buyerShowPrompt.length }}/500</span>
                                    <button type="button" @click="buyerShowPrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isSellingPointCardTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传参考图（可选）</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light" :class="{ 'is-filled': !!sellingPointCardReferencePreview }">
                                <template v-if="sellingPointCardReferencePreview">
                                    <div class="tool-local-panel tool-local-panel--outpaint">
                                        <div class="tool-local-panel__viewport">
                                            <img class="tool-local-panel__image" :src="sellingPointCardReferencePreview" :alt="sellingPointCardReferenceName || `${currentTool.detailName}-参考图`" />
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('selling-point-reference')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('selling-point-reference')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder" @click="triggerUpload('selling-point-reference')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传参考图</strong>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('selling-point-reference')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>出图比例</span>
                            </div>
                            <div class="tool-option-grid">
                                <button
                                    v-for="item in sellingPointCardRatioOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', 'tool-option-card--video-ratio', { 'is-active': sellingPointCardRatio === item.key }]"
                                    type="button"
                                    @click="sellingPointCardRatio = item.key"
                                >
                                    <span :class="['tool-option-card__ratio-icon', `tool-option-card__ratio-icon--${item.key.replace(':', '-')}`]" aria-hidden="true"></span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>预设选项（可选）</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillSellingPointCardPreset">AI 帮填</button>
                                </div>
                            </div>
                            <div class="tool-buyer-show-grid">
                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">产品类型</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'productType'"
                                            @click="toggleSellingPointCardFieldMenu('productType')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.productType }">
                                                {{ getSellingPointCardFieldDisplay('productType') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'productType' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'productType'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardProductTypeOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.productType === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('productType', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">文案语言</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'language'"
                                            @click="toggleSellingPointCardFieldMenu('language')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.language }">
                                                {{ getSellingPointCardFieldDisplay('language') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'language' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'language'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardLanguageOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.language === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('language', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">核心卖点</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'coreSellingPoint'"
                                            @click="toggleSellingPointCardFieldMenu('coreSellingPoint')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.coreSellingPoint }">
                                                {{ getSellingPointCardFieldDisplay('coreSellingPoint') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'coreSellingPoint' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'coreSellingPoint'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardCoreSellingPointOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.coreSellingPoint === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('coreSellingPoint', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">排版方式</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'layoutStyle'"
                                            @click="toggleSellingPointCardFieldMenu('layoutStyle')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.layoutStyle }">
                                                {{ getSellingPointCardFieldDisplay('layoutStyle') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'layoutStyle' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'layoutStyle'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardLayoutOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.layoutStyle === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('layoutStyle', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">卖点中心</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'focus'"
                                            @click="toggleSellingPointCardFieldMenu('focus')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.focus }">
                                                {{ getSellingPointCardFieldDisplay('focus') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'focus' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'focus'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardFocusOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.focus === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('focus', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">主标题</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'mainTitle'"
                                            @click="toggleSellingPointCardFieldMenu('mainTitle')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.mainTitle }">
                                                {{ getSellingPointCardFieldDisplay('mainTitle') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'mainTitle' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'mainTitle'"
                                            class="tool-filter-menu tool-filter-menu--select"
                                        >
                                            <button
                                                v-for="option in sellingPointCardMainTitleOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.mainTitle === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('mainTitle', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">副标题</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'subTitle'"
                                            @click="toggleSellingPointCardFieldMenu('subTitle')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.subTitle }">
                                                {{ getSellingPointCardFieldDisplay('subTitle') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'subTitle' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'subTitle'"
                                            class="tool-filter-menu tool-filter-menu--select"
                                        >
                                            <button
                                                v-for="option in sellingPointCardSubTitleOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.subTitle === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('subTitle', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">辅助元素</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'supportingElement'"
                                            @click="toggleSellingPointCardFieldMenu('supportingElement')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.supportingElement }">
                                                {{ getSellingPointCardFieldDisplay('supportingElement') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'supportingElement' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'supportingElement'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardSupportingElementOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.supportingElement === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('supportingElement', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="tool-buyer-show-field">
                                    <span class="tool-buyer-show-field__label">目标市场</span>
                                    <div class="tool-select-dropdown" @click.stop>
                                        <button
                                            class="tool-select-card tool-select-card--button"
                                            type="button"
                                            :aria-expanded="sellingPointCardOpenField === 'targetMarket'"
                                            @click="toggleSellingPointCardFieldMenu('targetMarket')"
                                        >
                                            <span class="tool-select-card__value" :class="{ 'is-placeholder': !sellingPointCardPresetValues.targetMarket }">
                                                {{ getSellingPointCardFieldDisplay('targetMarket') }}
                                            </span>
                                            <img :src="downIcon" alt="" :class="{ 'is-open': sellingPointCardOpenField === 'targetMarket' }" />
                                        </button>
                                        <div
                                            v-if="sellingPointCardOpenField === 'targetMarket'"
                                            class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                        >
                                            <button
                                                v-for="option in sellingPointCardTargetMarketOptions"
                                                :key="option.key"
                                                :class="{ 'is-active': sellingPointCardPresetValues.targetMarket === option.key }"
                                                type="button"
                                                @click="setSellingPointCardSelectValue('targetMarket', option.key)"
                                            >
                                                <span>{{ option.label }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '细节补充（选填）' }}</span>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="sellingPointCardPrompt"
                                    maxlength="500"
                                    :placeholder="currentTool.promptPlaceholder || '补充其他细节，如品牌名、特殊卖点描述、画面氛围或排版要求'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ sellingPointCardPrompt.length }}/500</span>
                                    <button type="button" @click="sellingPointCardPrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isHotCloneTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>上传参考图</span>
                            </div>
                            <div class="tool-upload-card tool-upload-card--light tool-upload-card--suite" :class="{ 'is-filled': !!hotCloneReferenceImages.length }">
                                <template v-if="hotCloneReferenceImages.length">
                                    <div class="tool-suite-upload">
                                        <div class="tool-suite-upload__grid">
                                            <article
                                                v-for="(item, index) in hotCloneReferenceImages"
                                                :key="`${item.name}-${index}`"
                                                class="tool-suite-upload__item"
                                            >
                                                <img :src="item.preview" :alt="item.name || `${currentTool.detailName}-参考图-${index + 1}`" />
                                                <span class="tool-suite-upload__badge">{{ index === 0 ? '主参考图' : `参考图 ${index + 1}` }}</span>
                                                <button
                                                    class="tool-suite-upload__remove"
                                                    type="button"
                                                    @click.stop="removeHotCloneReferenceImage(index)"
                                                >
                                                    ×
                                                </button>
                                            </article>

                                            <button
                                                v-if="hotCloneReferenceImages.length < maxHotCloneReferenceImages"
                                                class="tool-suite-upload__add"
                                                type="button"
                                                @click="triggerUpload('hot-clone-reference')"
                                            >
                                                <img :src="addIcon" alt="" />
                                                <span>继续添加</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="tool-upload-card__actions">
                                        <button class="tool-upload-card__action tool-upload-card__action--gallery" type="button" @click="applyGalleryUpload('hot-clone-reference')">
                                            <span>图库上传</span>
                                        </button>
                                        <button class="tool-upload-card__action tool-upload-card__action--local" type="button" @click="triggerUpload('hot-clone-reference')">
                                            <img :src="localUploadIcon" alt="" />
                                            <span>本地上传</span>
                                        </button>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tool-upload-card__placeholder tool-upload-card__placeholder--suite" @click="triggerUpload('hot-clone-reference')">
                                        <img :src="addIcon" alt="" />
                                        <strong>上传参考图</strong>
                                        <span>支持本地上传或从图库选择，最多 10 张参考图</span>
                                        <button class="tool-upload-card__library" type="button" @click.stop="applyGalleryUpload('hot-clone-reference')">
                                            <span>图库上传</span>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>商品名称</span>
                            </div>
                            <label class="tool-input-card">
                                <input
                                    v-model="hotCloneProductName"
                                    maxlength="100"
                                    placeholder="选填，不填则由 AI 自动判断"
                                >
                                <span>{{ hotCloneProductNameCount }}/100</span>
                            </label>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '核心卖点' }}</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillHotCloneSellingPoints">AI 帮写</button>
                                </div>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="hotCloneSellingPoints"
                                    maxlength="500"
                                    :placeholder="currentTool.promptPlaceholder || '请输入产品核心卖点，或点击 AI 帮写 自动生成'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ hotCloneSellingPointsCount }}/500</span>
                                    <button type="button" @click="clearHotCloneSellingPoints">清空</button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>输出比例</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="hotCloneRatioMenuOpen"
                                    @click="hotCloneRatioMenuOpen = !hotCloneRatioMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getHotCloneRatioOptionLabel(hotCloneRatio) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': hotCloneRatioMenuOpen }" />
                                </button>

                                <div
                                    v-if="hotCloneRatioMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productSuiteRatioOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': hotCloneRatio === item.key }"
                                        type="button"
                                        @click="setHotCloneRatio(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>文案语言</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="hotCloneLanguageMenuOpen"
                                    @click="hotCloneLanguageMenuOpen = !hotCloneLanguageMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getHotCloneLanguageLabel(hotCloneLanguage) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': hotCloneLanguageMenuOpen }" />
                                </button>

                                <div
                                    v-if="hotCloneLanguageMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in hotCloneLanguageOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': hotCloneLanguage === item.key }"
                                        type="button"
                                        @click="setHotCloneLanguage(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isProductMultiAngleTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择视角（多选）</span>
                                <span class="tool-block__tip">已选 {{ productMultiAngleSelectedViews.length }} 个视角</span>
                            </div>
                            <div class="tool-module-grid tool-module-grid--triple">
                                <button
                                    v-for="item in productMultiAngleViewOptions"
                                    :key="item.key"
                                    :class="['tool-module-card', 'tool-module-card--compact', { 'is-active': isProductMultiAngleViewSelected(item.key) }]"
                                    type="button"
                                    @click="toggleProductMultiAngleView(item.key)"
                                >
                                    <span v-if="getProductMultiAngleViewOrder(item.key)" class="tool-module-card__order">
                                        {{ getProductMultiAngleViewOrder(item.key) }}
                                    </span>
                                    <strong>{{ item.title }}</strong>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '细节补充（选填）' }}</span>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="productMultiAnglePrompt"
                                    maxlength="300"
                                    :placeholder="currentTool.promptPlaceholder || '补充描述，如商品材质、场景要求、光线风格等'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ productMultiAnglePrompt.length }}/300</span>
                                    <button type="button" @click="productMultiAnglePrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isProductPromoVideoTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>视频比例</span>
                            </div>
                            <div class="tool-option-grid">
                                <button
                                    v-for="item in productPromoVideoRatioOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', 'tool-option-card--video-ratio', { 'is-active': productPromoVideoRatio === item.key }]"
                                    type="button"
                                    @click="productPromoVideoRatio = item.key"
                                >
                                    <span :class="['tool-option-card__ratio-icon', `tool-option-card__ratio-icon--${item.key.replace(':', '-')}`]" aria-hidden="true"></span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>视频时长</span>
                            </div>
                            <div class="tool-option-grid tool-option-grid--triple">
                                <button
                                    v-for="item in productPromoVideoDurationOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', 'tool-option-card--mini', { 'is-active': productPromoVideoDuration === item.key }]"
                                    type="button"
                                    @click="productPromoVideoDuration = item.key"
                                >
                                    <strong>{{ item.title }}</strong>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>视频类型</span>
                            </div>
                            <div class="tool-option-grid tool-option-grid--video-type">
                                <button
                                    v-for="item in productPromoVideoTypeOptions"
                                    :key="item.key"
                                    :class="['tool-option-card', 'tool-option-card--mini', { 'is-active': productPromoVideoType === item.key }]"
                                    type="button"
                                    @click="productPromoVideoType = item.key"
                                >
                                    <span v-if="item.badge" class="tool-option-card__badge">{{ item.badge }}</span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '描述词' }}</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillProductPromoVideoPrompt">AI 帮写</button>
                                </div>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite tool-prompt-card--video">
                                <textarea
                                    v-model="productPromoVideoPrompt"
                                    maxlength="2000"
                                    :placeholder="currentTool.promptPlaceholder || '描述产品特点、使用场景或想要的视频效果'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ productPromoVideoPrompt.length }}/2000</span>
                                    <button type="button" @click="productPromoVideoPrompt = ''">清空</button>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template v-else-if="isProductSuiteTool">
                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>平台</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="productSuitePlatformMenuOpen"
                                    @click="productSuitePlatformMenuOpen = !productSuitePlatformMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getProductSuitePlatformLabel(productSuitePlatform) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': productSuitePlatformMenuOpen }" />
                                </button>

                                <div
                                    v-if="productSuitePlatformMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productSuitePlatformOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': productSuitePlatform === item.key }"
                                        type="button"
                                        @click="setProductSuitePlatform(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>国家/地区</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="productSuiteCountryMenuOpen"
                                    @click="productSuiteCountryMenuOpen = !productSuiteCountryMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getProductSuiteCountryLabel(productSuiteCountry) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': productSuiteCountryMenuOpen }" />
                                </button>

                                <div
                                    v-if="productSuiteCountryMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productSuiteCountryOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': productSuiteCountry === item.key }"
                                        type="button"
                                        @click="setProductSuiteCountry(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>语言</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="productSuiteLanguageMenuOpen"
                                    @click="productSuiteLanguageMenuOpen = !productSuiteLanguageMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getProductSuiteLanguageLabel(productSuiteLanguage) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': productSuiteLanguageMenuOpen }" />
                                </button>

                                <div
                                    v-if="productSuiteLanguageMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productSuiteLanguageOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': productSuiteLanguage === item.key }"
                                        type="button"
                                        @click="setProductSuiteLanguage(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>图片比例</span>
                            </div>
                            <div class="tool-select-dropdown" @click.stop>
                                <button
                                    class="tool-select-card tool-select-card--button"
                                    type="button"
                                    :aria-expanded="productSuiteRatioMenuOpen"
                                    @click="productSuiteRatioMenuOpen = !productSuiteRatioMenuOpen"
                                >
                                    <span class="tool-select-card__value">{{ getProductSuiteRatioOptionLabel(productSuiteRatio) }}</span>
                                    <img :src="downIcon" alt="" :class="{ 'is-open': productSuiteRatioMenuOpen }" />
                                </button>

                                <div
                                    v-if="productSuiteRatioMenuOpen"
                                    class="tool-filter-menu tool-filter-menu--select tool-filter-menu--dropdown"
                                >
                                    <button
                                        v-for="item in productSuiteRatioOptions"
                                        :key="item.key"
                                        :class="{ 'is-active': productSuiteRatio === item.key }"
                                        type="button"
                                        @click="setProductSuiteRatio(item.key)"
                                    >
                                        <span>{{ item.label }}</span>
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>{{ currentTool.promptTitle || '核心卖点' }}</span>
                                <div class="tool-inline-actions">
                                    <button type="button" @click="fillProductSuiteSellingPoints">AI 帮写</button>
                                </div>
                            </div>
                            <div class="tool-prompt-card tool-prompt-card--suite">
                                <textarea
                                    v-model="productSuiteSellingPoints"
                                    maxlength="500"
                                    :placeholder="currentTool.promptPlaceholder || '请输入产品核心卖点，或点击 AI 帮写 自动生成'"
                                />
                                <div class="tool-prompt-card__footer">
                                    <span>{{ productSuiteSellingPointsCount }}/500</span>
                                    <button type="button" @click="clearProductSuiteSellingPoints">清空</button>
                                </div>
                            </div>
                        </section>

                        <section class="tool-block">
                            <div class="tool-block__head">
                                <span>选择模块</span>
                                <span class="tool-block__tip">已选 {{ productSuiteSelectedModules.length }} 个模块</span>
                            </div>
                            <div class="tool-module-grid">
                                <button
                                    v-for="item in productSuiteModuleOptions"
                                    :key="item.key"
                                    :class="['tool-module-card', { 'is-active': isProductSuiteModuleSelected(item.key) }]"
                                    type="button"
                                    @click="toggleProductSuiteModule(item.key)"
                                >
                                    <span v-if="getProductSuiteModuleOrder(item.key)" class="tool-module-card__order">
                                        {{ getProductSuiteModuleOrder(item.key) }}
                                    </span>
                                    <strong>{{ item.title }}</strong>
                                    <span>{{ item.description }}</span>
                                </button>
                            </div>
                        </section>
                    </template>

                    <section v-else class="tool-block">
                        <div class="tool-block__head">
                            <span>输出规格</span>
                        </div>
                        <div class="tool-resolution">
                            <button
                                v-for="item in toolDetailResolutionOptions"
                                :key="item"
                                :class="{ 'is-active': activeResolution === item }"
                                type="button"
                                @click="activeResolution = item"
                            >
                                {{ item }}
                            </button>
                        </div>
                    </section>

                    <section class="tool-block tool-block--note">
                        <div class="tool-note">
                            <strong>{{ currentTool.promptLead }}</strong>
                            <span>{{ texts.createResultHint }}</span>
                        </div>
                    </section>
                </div>

                <div class="tool-template-panel__footer">
                    <button
                        class="tool-create-button"
                        type="button"
                        :disabled="isCreateDisabled"
                        @click="handleCreate"
                    >
                        <span v-if="isCreating" class="tool-create-button__spinner" aria-hidden="true"></span>
                        <span class="tool-create-button__meta">
                            <img v-if="!isCreating" :src="createNowIcon" alt="" />
                            <span>{{ texts.createRatePrefix }} {{ currentCreateCost }} {{ texts.createRateSuffix }}</span>
                        </span>
                        <span>{{ isCreating ? texts.creating : texts.createNow }}</span>
                    </button>
                </div>
            </div>
        </aside>

        <section class="tool-template__content">
            <div class="tool-toolbar" @click.stop>
                <button
                    class="tool-filter-button"
                    type="button"
                    :aria-expanded="filterMenuOpen"
                    @click="filterMenuOpen = !filterMenuOpen"
                >
                    <span>{{ activeFilter }}</span>
                    <img :src="downIcon" alt="" :class="{ 'is-open': filterMenuOpen }" />
                </button>

                <div v-if="filterMenuOpen" class="tool-filter-menu">
                    <button
                        v-for="item in toolResultFilterOptions"
                        :key="item"
                        :class="{ 'is-active': activeFilter === item }"
                        type="button"
                        @click="setActiveFilter(item)"
                    >
                        <span>{{ item }}</span>
                        <span class="tool-filter-menu__check" aria-hidden="true"></span>
                    </button>
                </div>
            </div>

            <div class="tool-result-scroll">
                <div v-if="filteredResults.length" class="tool-result-list">
                    <article
                        v-for="item in filteredResults"
                        :key="item.id"
                        :class="['tool-result-card', { 'is-active': activeResultId === item.id }]"
                    >
                        <div class="tool-result-card__head">
                            <div class="tool-result-card__title-row">
                                <span :class="['tool-result-card__kind', `tool-result-card__kind--${item.kind}`]">
                                    {{ getToolResultKindLabel(item.kind) }}
                                </span>
                                <strong>{{ item.title }}</strong>
                                <span>{{ item.createdAt }}</span>
                                <span v-for="meta in item.meta" :key="`${item.id}-${meta}`">{{ meta }}</span>
                            </div>
                        </div>

                        <div v-if="item.prompt" class="tool-result-card__prompt">
                            <div class="tool-result-card__thumbs">
                                <img
                                    v-for="thumb in item.promptThumbs"
                                    :key="`${item.id}-${thumb}`"
                                    :src="thumb"
                                    :alt="item.title"
                                />
                            </div>
                            <p>{{ item.prompt }}</p>
                        </div>

                        <div v-if="item.kind === 'image' && item.previews?.length" class="tool-result-card__gallery">
                            <button
                                v-for="(preview, previewIndex) in item.previews"
                                :key="`${item.id}-${preview}-${previewIndex}`"
                                class="tool-result-card__gallery-item"
                                type="button"
                                @click="focusResult(item)"
                            >
                                <img :src="preview" :alt="`${item.title}-${previewIndex + 1}`" />
                            </button>
                        </div>

                        <button
                            v-else
                            :class="['tool-result-card__preview', `tool-result-card__preview--${item.kind}`]"
                            type="button"
                            @click="focusResult(item)"
                        >
                            <img :src="getPrimaryToolResultPreview(item)" :alt="item.title" />
                            <span v-if="item.kind === 'video'" class="tool-result-card__play" aria-hidden="true">
                                <span class="tool-result-card__play-icon"></span>
                            </span>
                        </button>

                        <div class="tool-result-card__actions">
                            <button type="button" @click="handleEdit(item)">
                                <img :src="editIcon" alt="" />
                                <span>{{ texts.reEdit }}</span>
                            </button>
                            <button type="button" @click="handleRegenerate(item)">
                                <img :src="regenerateIcon" alt="" />
                                <span>{{ texts.regenerate }}</span>
                            </button>
                            <button class="tool-result-card__action--delete" type="button" @click="handleDelete(item.id)">
                                <img :src="deleteIcon" alt="" />
                                <span>{{ texts.deleteAll }}</span>
                            </button>
                        </div>
                    </article>
                </div>

                <div v-else class="tool-empty">
                    <strong>{{ texts.emptyResultsTitle }}</strong>
                    <span>{{ texts.emptyResultsHint }}</span>
                </div>
            </div>
        </section>

        <input
            ref="fileInputRef"
            class="sr-only"
            type="file"
            accept="image/*"
            :multiple="isProductSuiteTool || isProductMultiAngleTool || (isOneClickCleanupTool && activeUploadField === 'cleanup-images') || (isFashionLookbookTool && activeUploadField === 'fashion-clothes') || (isHotCloneTool && ['hot-clone-product', 'hot-clone-reference'].includes(activeUploadField)) || (isAiFittingTool && activeUploadField === 'ai-fitting-model' && aiFittingMode === 'custom')"
            @change="handleUpload"
        />

        <div
            v-if="showGalleryModal"
            class="tool-gallery-modal-mask"
            @click.self="closeGalleryModal"
        >
            <section class="tool-gallery-modal" aria-modal="true" role="dialog">
                <div class="tool-gallery-modal__header">
                    <div class="tool-gallery-modal__title">
                        <strong>图库上传</strong>
                        <span>用户创作素材</span>
                    </div>
                    <button class="tool-gallery-modal__close" type="button" aria-label="关闭" @click="closeGalleryModal">
                        <span></span>
                        <span></span>
                    </button>
                </div>

                <div class="tool-gallery-modal__tabs">
                    <button
                        v-for="item in galleryCategories"
                        :key="item"
                        :class="{ 'is-active': activeGalleryCategory === item }"
                        type="button"
                        @click="activeGalleryCategory = item"
                    >
                        {{ item }}
                    </button>
                </div>

                <div class="tool-gallery-modal__grid">
                    <button
                        v-for="item in filteredGalleryMaterials"
                        :key="item.id"
                        class="tool-gallery-card"
                        type="button"
                        @click="applyHistoryMaterial(item)"
                    >
                        <img :src="item.image" :alt="item.name" />
                        <div class="tool-gallery-card__shade"></div>
                        <div class="tool-gallery-card__meta">
                            <span class="tool-gallery-card__badge">{{ item.category }}</span>
                            <strong>{{ item.name }}</strong>
                            <span>{{ item.size }}</span>
                        </div>
                    </button>

                    <div v-if="!filteredGalleryMaterials.length" class="tool-gallery-modal__empty">
                        <strong>当前分类暂无创作素材</strong>
                        <span>完成生成后，你的图片生成和 AI 工具作品会展示在这里。</span>
                    </div>
                </div>
            </section>
        </div>

        <div
            v-if="showMaskEditor"
            class="tool-mask-editor-mask"
            @click.self="closeMaskEditor"
        >
            <section class="tool-mask-editor" aria-modal="true" role="dialog">
                <div class="tool-mask-editor__toolbar">
                    <div class="tool-mask-editor__tools">
                        <button
                            :class="{ 'is-active': activeMaskTool === 'brush' }"
                            type="button"
                            @click="setMaskTool('brush')"
                        >
                            画笔
                        </button>
                        <button
                            :class="{ 'is-active': activeMaskTool === 'erase' }"
                            type="button"
                            @click="setMaskTool('erase')"
                        >
                            橡皮
                        </button>
                        <button
                            :class="{ 'is-active': activeMaskTool === 'pan' }"
                            type="button"
                            @click="setMaskTool('pan')"
                        >
                            拖拽
                        </button>
                        <button type="button" :disabled="!canUndoMask" @click="undoMask">后退</button>
                        <button type="button" :disabled="!canRedoMask" @click="redoMask">前进</button>
                    </div>

                    <div v-if="activeMaskTool === 'brush' || activeMaskTool === 'erase'" class="tool-mask-editor__size">
                        <span>{{ activeMaskTool === 'brush' ? '画笔大小' : '橡皮大小' }}</span>
                        <input
                            v-model="activeMaskSizeModel"
                            type="range"
                            min="12"
                            max="72"
                            step="1"
                        />
                    </div>

                    <div class="tool-mask-editor__zoom">
                        <button type="button" @click="zoomOutMask">-</button>
                        <span>{{ maskZoomPercent }}</span>
                        <button type="button" @click="zoomInMask">+</button>
                    </div>
                </div>

                <div class="tool-mask-editor__body">
                    <aside class="tool-mask-editor__selections">
                        <button class="tool-mask-editor__add-selection" type="button" @click="addMaskSelection">
                            新增选区
                        </button>

                        <div class="tool-mask-editor__selection-list">
                            <button
                                v-for="item in maskSelections"
                                :key="item.id"
                                :class="['tool-mask-editor__selection', { 'is-active': activeMaskSelectionId === item.id }]"
                                type="button"
                                @click="activeMaskSelectionId = item.id"
                            >
                                <span :style="{ background: item.color }"></span>
                            </button>
                        </div>
                    </aside>

                    <div ref="maskSurfaceRef" class="tool-mask-editor__stage">
                        <div
                            class="tool-mask-editor__viewport"
                            :style="maskViewportStyle"
                        >
                            <img
                                ref="maskImageRef"
                                class="tool-mask-editor__image"
                                :src="originPreview"
                                :alt="originName || currentTool.detailName"
                                @load="syncMaskCanvasSize"
                            />
                            <canvas
                                ref="maskCanvasRef"
                                :class="['tool-mask-editor__canvas', `is-${activeMaskTool}`]"
                                @pointerdown="startMaskDrawing"
                                @pointermove="drawMaskStroke"
                                @pointerup="stopMaskDrawing"
                                @pointerleave="stopMaskDrawing"
                                @pointercancel="stopMaskDrawing"
                            ></canvas>
                        </div>
                    </div>
                </div>

                <div class="tool-mask-editor__footer">
                    <button class="tool-mask-editor__ghost" type="button" @click="closeMaskEditor">取消</button>
                    <button class="tool-mask-editor__confirm" type="button" @click="confirmMaskEditor">确定</button>
                </div>
            </section>
        </div>
    </section>
</template>

<script lang="ts" setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import addIcon from '@/assets/images/icon/Add.svg'
import createNowIcon from '@/assets/images/icon/chuangz.svg'
import deleteIcon from '@/assets/images/icon/Delete-themes.svg'
import downIcon from '@/assets/images/icon/Down.svg'
import editIcon from '@/assets/images/icon/bianji.svg'
import localUploadIcon from '@/assets/images/icon/sahngchuan.svg'
import regenerateIcon from '@/assets/images/icon/zaici.svg'
import vipBadgeIcon from '@/assets/images/icon/vip.svg'
import {
    aiToolTexts,
    buildToolResultSeed,
    buildToolTimestamp,
    getPrimaryToolResultPreview,
    getToolCardById,
    getToolResolutionOutput,
    getToolResultFilterKind,
    getToolResultKindLabel,
    toolCards,
    toolDetailResolutionOptions,
    toolResultFilterOptions
} from '~/composables/use-ai-tools'
import type { ToolDetailResolution, ToolResultFilter, ToolResultItem } from '~/composables/use-ai-tools'
import { usePcLoginGate } from '@/composables/usePcLoginGate'

const props = defineProps<{
    toolId: string
}>()

type UploadField =
    | 'origin'
    | 'mask'
    | 'hairstyle-reference'
    | 'product-image-style'
    | 'fashion-clothes'
    | 'fashion-model'
    | 'hot-clone-product'
    | 'hot-clone-reference'
    | 'product-multi-angle'
    | 'shoe-tryon-model'
    | 'shoe-tryon-shoe'
    | 'model-wear-model'
    | 'model-wear-item'
    | 'cleanup-images'
    | 'ai-fitting-top'
    | 'ai-fitting-bottom'
    | 'ai-fitting-model'
    | 'buyer-show-reference'
    | 'selling-point-reference'
type UploadSource = 'gallery' | 'local'
type MaskEditorTool = 'brush' | 'erase' | 'pan'
type MaskSelection = { id: string; color: string }
type OutpaintDirection = 'left' | 'top' | 'right' | 'bottom'
type PhotoRestoreMode = 'repair' | 'colorize'
type PhotoRestoreModel = string
type ImageTranslateLanguage = 'auto' | 'zh-hans' | 'zh-hant' | 'en' | 'ja' | 'ko' | 'fr' | 'de' | 'es' | 'pt' | 'ru'
type HotCloneLanguage = 'zh' | 'en' | 'ja' | 'ko'
type ProductSuitePlatform =
    | '1688'
    | 'amazon'
    | 'taobao-tmall'
    | 'temu'
    | 'tiktok-shop'
    | 'pinduoduo'
    | 'douyin-shop'
    | 'ozon'
    | 'independent'
    | 'shopee'
    | 'alibaba-international'
    | 'aliexpress'
    | 'jd'
type ProductSuiteCountry = 'us' | 'europe' | 'china' | 'russia' | 'sea' | 'spain' | 'germany' | 'japan' | 'brazil' | 'malaysia'
type ProductSuiteLanguage = 'en' | 'zh' | 'ru' | 'es' | 'de' | 'ja' | 'zh-hant' | 'pt' | 'ms' | 'none'
type ProductSuiteRatio = '1:1' | '3:4' | '16:9' | '9:16'
type ProductImageStyleTab = 'template' | 'custom'
type ProductImageSceneCategory =
    | 'overview'
    | 'beauty'
    | 'food'
    | 'shoes'
    | 'bags'
    | 'phone'
    | 'digital'
    | 'computer'
    | 'appliance'
    | 'daily'
    | 'pet'
    | 'mother-baby'
    | 'home'
type ProductImageStylePresetKey =
    | 'static-soft-shadow'
    | 'static-leaf-light'
    | 'static-neutral-paper'
    | 'static-window-light'
    | 'static-marble-surface'
    | 'podium-beige-stage'
    | 'podium-gold-stage'
    | 'podium-multi-stage'
    | 'podium-sunset-stage'
    | 'podium-shadow-arch'
type ProductImageSizeKey = string
type FashionLookbookRatio = 'auto' | '1:1' | '3:4' | '4:3' | '4:5' | '5:4' | '2:3' | '3:2' | '16:9' | '9:16' | '21:9'
type ProductSuiteModuleKey =
    | 'hero'
    | 'core-benefit'
    | 'usage-scene'
    | 'multi-angle'
    | 'mood-scene'
    | 'detail'
    | 'compare'
    | 'size-spec'
    | 'spec-sheet'
    | 'accessories'
    | 'craft-process'
    | 'series-display'
    | 'ingredient'
    | 'usage-guide'
    | 'after-sales'
    | 'purchase-guide'
type ProductSuiteUploadItem = { preview: string; name: string; source: UploadSource }
type FashionLookbookUploadItem = { preview: string; name: string; source: UploadSource }
type HotCloneUploadItem = { preview: string; name: string; source: UploadSource }
type ProductMultiAngleUploadItem = { preview: string; name: string; source: UploadSource }
type OneClickCleanupUploadItem = { preview: string; name: string; source: UploadSource }
type AiFittingUploadItem = { preview: string; name: string; source: UploadSource }
type OneClickCleanupOptionKey = 'watermark' | 'icon' | 'sticker' | 'text'
type ProductMultiAngleViewKey = 'front' | 'side' | 'back' | 'top' | 'bottom' | 'forty-five'
type ProductPromoVideoRatio = '9:16' | '16:9'
type ProductPromoVideoDuration = '5' | '10' | '12'
type ProductPromoVideoType = 'product' | 'creative' | 'feature' | 'unboxing' | 'story'
type AiFittingUploadCategory = 'tops' | 'bottoms' | 'dress' | 'coat' | 'set'
type AiFittingMode = 'single' | 'group' | 'custom'
type AiFittingModelFilter = 'all' | 'male' | 'female' | 'child'
type AiFittingClothesFilter =
    | 'all'
    | 'sleeveless'
    | 'coat'
    | 'long-pants'
    | 'long-skirt'
    | 'short-sleeve'
    | 'short-skirt'
    | 'long-sleeve'
    | 'short-pants'
type AiFittingPoseFilter = 'all' | 'half' | 'full'
type AiFittingPreset = {
    id: string
    groupId: string
    groupLabel: string
    image: string
    name: string
    modelFilter: Exclude<AiFittingModelFilter, 'all'>
    clothesFilter: Exclude<AiFittingClothesFilter, 'all'>
    poseFilter: Exclude<AiFittingPoseFilter, 'all'>
    vip?: boolean
}
type StyleTransferStyle =
    | 'hanman'
    | 'realistic'
    | 'monet'
    | 'china-red'
    | 'cyberpunk'
    | 'doll'
    | 'anime-film'
    | 'watercolor'
type BuyerShowPresetField =
    | 'productType'
    | 'productStatus'
    | 'presentationMode'
    | 'sceneMood'
    | 'productRealism'
    | 'environmentRealism'
    | 'shootRealism'
    | 'targetMarket'
type SellingPointCardRatio = '3:4' | '9:16' | '4:3' | '16:9'
type SellingPointCardLanguage =
    | 'none'
    | 'zh-hans'
    | 'zh-hant'
    | 'en'
    | 'zh-en'
    | 'ru'
    | 'ja'
    | 'ko'
    | 'fr'
    | 'es'
    | 'pt'
    | 'ar'
    | 'th'
    | 'nl'
    | 'tr'
type SellingPointCardCoreSellingPoint = 'single-copy' | 'paired-copy' | 'main-with-side-copy' | 'multi-copy'
type SellingPointCardProductType =
    | 'clothing'
    | 't-shirt'
    | 'backpack'
    | 'shoes'
    | 'small-appliance'
    | 'tv'
    | 'cosmetics'
    | 'perfume'
    | 'fruit'
    | 'drink'
    | 'car'
    | 'container'
    | 'bluetooth-headset'
    | 'phone'
    | 'luggage'
    | 'stationery'
    | 'machinery'
    | 'sofa'
    | 'necklace'
    | 'toy'
    | 'yoga-wear'
    | 'fitness-equipment'
    | 'laptop'
    | 'figure'
 type SellingPointCardLayoutStyle =
    | 'product-center-copy-sides'
    | 'product-scene-copy-scattered'
    | 'product-top-copy-bottom'
    | 'product-bottom-copy-top'
    | 'left-product-right-copy'
    | 'left-copy-right-product'
    | 'product-zoom-copy-around'
type SellingPointCardFocus = 'material' | 'craft' | 'function' | 'performance' | 'design'
type SellingPointCardSupportingElement = 'arrow' | 'icon' | 'frame' | 'data' | 'line' | 'color-block'
type SellingPointCardTitleMode = 'none' | 'auto'
type SellingPointCardSelectField =
    | 'productType'
    | 'language'
    | 'coreSellingPoint'
    | 'layoutStyle'
    | 'focus'
    | 'mainTitle'
    | 'subTitle'
    | 'supportingElement'
    | 'targetMarket'
type SellingPointCardPresetValues = {
    productType: SellingPointCardProductType | ''
    language: SellingPointCardLanguage | ''
    coreSellingPoint: SellingPointCardCoreSellingPoint | ''
    layoutStyle: SellingPointCardLayoutStyle | ''
    focus: SellingPointCardFocus | ''
    mainTitle: SellingPointCardTitleMode | ''
    subTitle: SellingPointCardTitleMode | ''
    supportingElement: SellingPointCardSupportingElement | ''
    targetMarket: ProductSuiteCountry | ''
}

const router = useRouter()
const route = useRoute()
const { ensurePcLogin } = usePcLoginGate()
const texts = aiToolTexts
const galleryCategories = ['图片生成', 'AI工具'] as const
const currentTool = computed(() => getToolCardById(props.toolId))
const toolPageTitle = computed(() => currentTool.value.title)
const isLocalRedrawTool = computed(() => currentTool.value.toolMode === 'local-redraw')
const isOutpaintTool = computed(() => currentTool.value.toolMode === 'outpaint')
const isPhotoRestoreTool = computed(() => currentTool.value.toolMode === 'photo-restore')
const isLineDrawingTool = computed(() => currentTool.value.toolMode === 'line-drawing')
const isHairstyleTool = computed(() => currentTool.value.toolMode === 'hairstyle-change')
const isImageTranslateTool = computed(() => currentTool.value.toolMode === 'image-translate')
const isBackgroundRemovalTool = computed(() => currentTool.value.toolMode === 'background-removal')
const isOneClickCleanupTool = computed(() => currentTool.value.toolMode === 'one-click-cleanup')
const isStyleTransferTool = computed(() => currentTool.value.toolMode === 'style-transfer')
const isProductImageTool = computed(() => currentTool.value.toolMode === 'product-image')
const isFashionLookbookTool = computed(() => currentTool.value.toolMode === 'fashion-lookbook')
const isProductSuiteTool = computed(() => currentTool.value.toolMode === 'product-suite')
const isHotCloneTool = computed(() => currentTool.value.toolMode === 'hot-clone')
const isProductMultiAngleTool = computed(() => currentTool.value.toolMode === 'product-multi-angle')
const isProductPromoVideoTool = computed(() => currentTool.value.toolMode === 'product-promo-video')
const isShoeTryonTool = computed(() => currentTool.value.toolMode === 'shoe-tryon')
const isModelWearTool = computed(() => currentTool.value.toolMode === 'model-wear')
const isAiFittingTool = computed(() => currentTool.value.toolMode === 'ai-fitting')
const isBuyerShowTool = computed(() => currentTool.value.toolMode === 'buyer-show')
const isSellingPointCardTool = computed(() => currentTool.value.toolMode === 'selling-point-card')
const buyerShowPresetFieldOrder: BuyerShowPresetField[] = [
    'productType',
    'productStatus',
    'presentationMode',
    'sceneMood',
    'productRealism',
    'environmentRealism',
    'shootRealism',
    'targetMarket'
]
const buyerShowPresetFieldLabels: Record<BuyerShowPresetField, string> = {
    productType: '产品类型',
    productStatus: '产品状态',
    presentationMode: '呈现方式',
    sceneMood: '场景氛围',
    productRealism: '产品真实感',
    environmentRealism: '环境真实感',
    shootRealism: '拍摄真实感',
    targetMarket: '目标市场'
}
const buyerShowPresetPlaceholders: Record<BuyerShowPresetField, string> = {
    productType: '请选择产品类型',
    productStatus: '请选择产品状态',
    presentationMode: '请选择呈现方式',
    sceneMood: '请选择场景氛围',
    productRealism: '请选择产品真实感',
    environmentRealism: '请选择环境真实感',
    shootRealism: '请选择拍摄真实感',
    targetMarket: '请选择目标市场'
}
const buyerShowPresetOptions: Record<BuyerShowPresetField, Array<{ key: string; label: string }>> = {
    productType: [
        { key: 'clothing', label: '服装' },
        { key: 't-shirt', label: 'T恤' },
        { key: 'backpack', label: '背包' },
        { key: 'shoes', label: '鞋子' },
        { key: 'small-appliance', label: '小家电' },
        { key: 'tv', label: '电视' },
        { key: 'cosmetics', label: '化妆品' },
        { key: 'perfume', label: '香水' },
        { key: 'fruit', label: '水果' },
        { key: 'drink', label: '饮料' },
        { key: 'car', label: '汽车' },
        { key: 'container', label: '集装箱' },
        { key: 'bluetooth-headset', label: '蓝牙耳机' },
        { key: 'phone', label: '手机' },
        { key: 'luggage', label: '行李箱' },
        { key: 'stationery', label: '文具' },
        { key: 'machinery', label: '机械设备' },
        { key: 'sofa', label: '沙发' },
        { key: 'necklace', label: '项链' },
        { key: 'toy', label: '玩具' },
        { key: 'yoga-wear', label: '瑜伽服' },
        { key: 'fitness-equipment', label: '健身器材' },
        { key: 'laptop', label: '笔记本电脑' },
        { key: 'figure', label: '手办' }
    ],
    productStatus: [
        { key: 'full-delivery', label: '完整快递箱' },
        { key: 'product-accessories-display', label: '产品与配件自然陈列' },
        { key: 'new-unsealed', label: '新品未拆封' },
        { key: 'natural-placement', label: '产品自然摆放场景' },
        { key: 'installation', label: '安装场景' },
        { key: 'in-use', label: '使用状态' },
        { key: 'wearing', label: '穿戴状态' },
        { key: 'long-term-use', label: '长期使用状态' }
    ],
    presentationMode: [
        { key: 'main-display', label: '主体展示' },
        { key: 'detail-closeup', label: '细节局部拍摄' },
        { key: 'natural-placement', label: '自然摆放' },
        { key: 'handheld', label: '手持拍摄' },
        { key: 'wearing', label: '穿戴拍摄' },
        { key: 'mirror-selfie', label: '对镜自拍' },
        { key: 'use-shot', label: '使用中拍摄' },
        { key: 'size-compare', label: '日常物品大小对比' }
    ],
    sceneMood: [
        { key: 'none', label: '无场景' },
        { key: 'home', label: '居家场景' },
        { key: 'blurred-closeup', label: '局部或模糊场景' },
        { key: 'car', label: '车内场景' },
        { key: 'sports', label: '移动运动场景' },
        { key: 'outdoor', label: '日常外出场景' },
        { key: 'holiday', label: '节日场景' }
    ],
    productRealism: [
        { key: 'package-wrinkle', label: '包装与产品褶皱' },
        { key: 'long-term-wear', label: '长期使用磨损' },
        { key: 'real-usage', label: '使用中的真实环境' }
    ],
    environmentRealism: [
        { key: 'cluttered', label: '杂乱环境' },
        { key: 'pet', label: '宠物偶然入镜' },
        { key: 'partial-person', label: '人物局部入镜' },
        { key: 'casual-placement', label: '临时摆放随意感' },
        { key: 'person-no-makeup', label: '人物素颜' },
        { key: 'daily-outfit', label: '人物日常穿搭' }
    ],
    shootRealism: [
        { key: 'casual-shot', label: '随手拍摄无美感' },
        { key: 'low-resolution', label: '较低像素' },
        { key: 'motion-blur', label: '手抖模糊' },
        { key: 'backlight', label: '反光逆光' },
        { key: 'mirror-selfie', label: '对镜自拍手持自拍' }
    ],
    targetMarket: [
        { key: 'us', label: '美国' },
        { key: 'europe', label: '欧洲' },
        { key: 'china', label: '中国' },
        { key: 'russia', label: '俄罗斯' },
        { key: 'sea', label: '东南亚' },
        { key: 'spain', label: '西班牙' },
        { key: 'germany', label: '德国' },
        { key: 'japan', label: '日本' },
        { key: 'brazil', label: '巴西' },
        { key: 'malaysia', label: '马来西亚' }
    ]
}
const activeResolution = ref<ToolDetailResolution>('高清2k')
const activeFilter = ref<ToolResultFilter>('全部')
const activeResultId = ref('')
const filterMenuOpen = ref(false)
const productSuitePlatformMenuOpen = ref(false)
const productSuiteCountryMenuOpen = ref(false)
const productSuiteLanguageMenuOpen = ref(false)
const productSuiteRatioMenuOpen = ref(false)
const productImageRatioMenuOpen = ref(false)
const productImageSceneCategoryMenuOpen = ref(false)
const fashionLookbookRatioMenuOpen = ref(false)
const hotCloneLanguageMenuOpen = ref(false)
const hotCloneRatioMenuOpen = ref(false)
const imageTranslateSourceMenuOpen = ref(false)
const imageTranslateTargetMenuOpen = ref(false)
const aiFittingUploadCategoryMenuOpen = ref(false)
const aiFittingModelMenuOpen = ref(false)
const aiFittingClothesMenuOpen = ref(false)
const aiFittingPoseMenuOpen = ref(false)
const showGalleryModal = ref(false)
const showMaskEditor = ref(false)
const activeGalleryCategory = ref<(typeof galleryCategories)[number]>('图片生成')
const activeUploadField = ref<UploadField>('origin')
const isCreating = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const maskCanvasRef = ref<HTMLCanvasElement | null>(null)
const maskSurfaceRef = ref<HTMLElement | null>(null)
const maskImageRef = ref<HTMLImageElement | null>(null)
const originPreview = ref('')
const originName = ref('')
const originSource = ref<UploadSource>('gallery')
const hairstyleReferencePreview = ref('')
const hairstyleReferenceName = ref('')
const hairstyleReferenceSource = ref<UploadSource>('gallery')
const maskPreview = ref('')
const maskName = ref('')
const maskSource = ref<UploadSource>('gallery')
const hasMaskStroke = ref(false)
const isMaskPointerDown = ref(false)
const activeMaskTool = ref<MaskEditorTool>('brush')
const activeMaskSelectionId = ref('mask-selection-1')
const maskEditorScale = ref(1)
const maskEditorOffset = ref({ x: 0, y: 0 })
const maskBrushSize = ref(28)
const maskEraserSize = ref(36)
const localRedrawPrompt = ref('')
const uploadBlobUrls = ref<string[]>([])
const createTimer = ref<ReturnType<typeof setTimeout> | null>(null)
const lastMaskPoint = ref<{ x: number; y: number } | null>(null)
const maskPanStart = ref<{ x: number; y: number; offsetX: number; offsetY: number } | null>(null)
const maskHistory = ref<string[]>([''])
const maskHistoryIndex = ref(0)
const maskEditorInitialSnapshot = ref('')
const maskSelections = ref<MaskSelection[]>([
    { id: 'mask-selection-1', color: '#19D7B2' }
])
const outpaintValues = ref({
    left: 200,
    top: 200,
    right: 200,
    bottom: 200
})
const photoRestoreMode = ref<PhotoRestoreMode>('repair')
const photoRestoreModel = ref<PhotoRestoreModel>('')
const restoreModels = ref<{ id?: number; code: string; name: string; icon?: string; description: string; channel?: string; base_cost?: number; badge?: string }[]>([])
const lineDrawingModel = ref<PhotoRestoreModel>('nano')
const lineDrawingPrompt = ref('')
const imageTranslateSourceLanguage = ref<ImageTranslateLanguage>('auto')
const imageTranslateTargetLanguage = ref<Exclude<ImageTranslateLanguage, 'auto'>>('en')
const imageTranslatePrompt = ref('')
const styleTransferStyle = ref<StyleTransferStyle>('hanman')
const productImageStyleTab = ref<ProductImageStyleTab>('template')
const productImageSceneCategory = ref<ProductImageSceneCategory>('overview')
const productImageSelectedPresetKey = ref<ProductImageStylePresetKey>('static-soft-shadow')
const productImageCustomStylePreview = ref('')
const productImageCustomStyleName = ref('')
const productImageCustomStyleSource = ref<UploadSource>('gallery')
const productImageSizeKey = ref<ProductImageSizeKey>('1:1')
const productImageWidth = ref('800')
const productImageHeight = ref('800')
const maxFashionLookbookClothesImages = 6
const fashionLookbookClothesImages = ref<FashionLookbookUploadItem[]>([])
const fashionLookbookModelPreview = ref('')
const fashionLookbookModelName = ref('')
const fashionLookbookModelSource = ref<UploadSource>('gallery')
const fashionLookbookRatio = ref<FashionLookbookRatio>('3:4')
const fashionLookbookPrompt = ref('')
const maxProductSuiteImages = 3
const maxAiFittingCustomModels = 4
const defaultProductSuiteModules: ProductSuiteModuleKey[] = ['core-benefit', 'usage-scene', 'multi-angle', 'mood-scene', 'detail', 'hero']
const productSuiteImages = ref<ProductSuiteUploadItem[]>([])
const productSuitePlatform = ref<ProductSuitePlatform>('amazon')
const productSuiteCountry = ref<ProductSuiteCountry>('us')
const productSuiteLanguage = ref<ProductSuiteLanguage>('en')
const productSuiteRatio = ref<ProductSuiteRatio>('1:1')
const productSuiteSellingPoints = ref('')
const productSuiteSelectedModules = ref<ProductSuiteModuleKey[]>([...defaultProductSuiteModules])
const maxHotCloneProductImages = 5
const maxHotCloneReferenceImages = 10
const hotCloneProductImages = ref<HotCloneUploadItem[]>([])
const hotCloneReferenceImages = ref<HotCloneUploadItem[]>([])
const hotCloneProductName = ref('')
const hotCloneSellingPoints = ref('')
const hotCloneLanguage = ref<HotCloneLanguage>('zh')
const hotCloneRatio = ref<ProductSuiteRatio>('3:4')
const maxProductMultiAngleImages = 10
const defaultProductMultiAngleViews: ProductMultiAngleViewKey[] = ['front', 'side']
const productMultiAngleImages = ref<ProductMultiAngleUploadItem[]>([])
const productMultiAngleSelectedViews = ref<ProductMultiAngleViewKey[]>([...defaultProductMultiAngleViews])
const productMultiAnglePrompt = ref('')
const maxOneClickCleanupImages = 30
const oneClickCleanupImages = ref<OneClickCleanupUploadItem[]>([])
const oneClickCleanupSelectedOptions = ref<OneClickCleanupOptionKey[]>(['watermark', 'icon', 'sticker', 'text'])
const productPromoVideoRatio = ref<ProductPromoVideoRatio>('9:16')
const productPromoVideoDuration = ref<ProductPromoVideoDuration>('5')
const productPromoVideoType = ref<ProductPromoVideoType>('product')
const productPromoVideoPrompt = ref('')
const shoeTryonModelPreview = ref('')
const shoeTryonModelName = ref('')
const shoeTryonModelSource = ref<UploadSource>('gallery')
const shoeTryonShoePreview = ref('')
const shoeTryonShoeName = ref('')
const shoeTryonShoeSource = ref<UploadSource>('gallery')
const shoeTryonPrompt = ref('')
const modelWearModelPreview = ref('')
const modelWearModelName = ref('')
const modelWearModelSource = ref<UploadSource>('gallery')
const modelWearItemPreview = ref('')
const modelWearItemName = ref('')
const modelWearItemSource = ref<UploadSource>('gallery')
const modelWearPrompt = ref('')
const aiFittingUploadCategory = ref<AiFittingUploadCategory>('tops')
const aiFittingMode = ref<AiFittingMode>('single')
const aiFittingModelFilter = ref<AiFittingModelFilter>('all')
const aiFittingClothesFilter = ref<AiFittingClothesFilter>('all')
const aiFittingPoseFilter = ref<AiFittingPoseFilter>('all')
const aiFittingTopPreview = ref('')
const aiFittingTopName = ref('')
const aiFittingTopSource = ref<UploadSource>('gallery')
const aiFittingBottomPreview = ref('')
const aiFittingBottomName = ref('')
const aiFittingBottomSource = ref<UploadSource>('gallery')
const aiFittingSelectedPresetIds = ref<string[]>([])
const aiFittingSelectedGroupId = ref('')
const aiFittingCustomModelImages = ref<AiFittingUploadItem[]>([])
const aiFittingCustomModelPreview = computed(() => aiFittingCustomModelImages.value[0]?.preview || '')
const aiFittingCustomModelName = computed(() => aiFittingCustomModelImages.value[0]?.name || '')
const aiFittingCustomModelSource = computed<UploadSource>(() => aiFittingCustomModelImages.value[0]?.source || 'gallery')
const buyerShowReferencePreview = ref('')
const buyerShowReferenceName = ref('')
const buyerShowReferenceSource = ref<UploadSource>('gallery')
const buyerShowPrompt = ref('')
const buyerShowOpenField = ref<BuyerShowPresetField | ''>('')
const buyerShowPresetValues = ref<Record<BuyerShowPresetField, string>>(createEmptyBuyerShowPresetValues())
const sellingPointCardReferencePreview = ref('')
const sellingPointCardReferenceName = ref('')
const sellingPointCardReferenceSource = ref<UploadSource>('gallery')
const sellingPointCardRatio = ref<SellingPointCardRatio>('3:4')
const sellingPointCardOpenField = ref<SellingPointCardSelectField | ''>('')
const sellingPointCardPrompt = ref('')
const sellingPointCardPresetValues = ref<SellingPointCardPresetValues>(createEmptySellingPointCardPresetValues())

const buildInitialResultMap = () => Object.fromEntries(
    toolCards.map((item, index) => [item.id, buildToolResultSeed(item, index)])
) as Record<string, ToolResultItem[]>

const resultMap = ref<Record<string, ToolResultItem[]>>(buildInitialResultMap())
const galleryMaterials = computed(() => Object.values(resultMap.value)
    .flat()
    .filter((item) => item.kind === 'image' || item.kind === 'tool')
    .map((item, index) => ({
        id: `${item.id}-material-${index}`,
        name: `${item.title}-${index + 1}.png`,
        image: getPrimaryToolResultPreview(item),
        size: item.meta[0] || '2048×2048',
        category: item.kind === 'image' ? '图片生成' : 'AI工具'
    })))

const filteredGalleryMaterials = computed(() => galleryMaterials.value.filter((item) => item.category === activeGalleryCategory.value))
const shoeTryonExampleMaterials = computed(() => galleryMaterials.value
    .filter((item) => item.category === '图片生成')
    .slice(0, 8))
const shoeTryonModelExamples = computed(() => shoeTryonExampleMaterials.value.slice(0, 4))
const shoeTryonShoeExamples = computed(() => {
    const shoeExamples = shoeTryonExampleMaterials.value.slice(4, 8)
    return shoeExamples.length ? shoeExamples : shoeTryonExampleMaterials.value.slice(0, 4)
})
const modelWearExampleMaterials = computed(() => galleryMaterials.value
    .filter((item) => item.category === '图片生成')
    .slice(2, 10))
const modelWearModelExamples = computed(() => modelWearExampleMaterials.value.slice(0, 4))
const modelWearItemExamples = computed(() => {
    const itemExamples = modelWearExampleMaterials.value.slice(4, 8)
    return itemExamples.length ? itemExamples : modelWearExampleMaterials.value.slice(0, 4)
})
const hairstyleExampleMaterials = computed(() => galleryMaterials.value
    .filter((item) => item.category === '图片生成')
    .slice(0, 10))
const hairstyleOriginExamples = computed(() => hairstyleExampleMaterials.value.slice(0, 4))
const hairstyleReferenceExamples = computed(() => {
    const referenceExamples = hairstyleExampleMaterials.value.slice(4, 8)
    return referenceExamples.length ? referenceExamples : hairstyleExampleMaterials.value.slice(0, 4)
})
const aiFittingUploadCategoryOptions = [
    { key: 'tops' as const, label: '上装' },
    { key: 'bottoms' as const, label: '下装' },
    { key: 'set' as const, label: '上下装' },
    { key: 'dress' as const, label: '连体衣' }
]
const aiFittingModeOptions = [
    { key: 'single' as const, label: '单图' },
    { key: 'group' as const, label: '组图' },
    { key: 'custom' as const, label: '自定义' }
]
const aiFittingModelFilterOptions = [
    { key: 'all' as const, label: '全部模特' },
    { key: 'male' as const, label: '男' },
    { key: 'female' as const, label: '女' },
    { key: 'child' as const, label: '儿童' }
]
const aiFittingClothesFilterOptions = [
    { key: 'all' as const, label: '全部服饰' },
    { key: 'sleeveless' as const, label: '无袖' },
    { key: 'coat' as const, label: '外套' },
    { key: 'long-pants' as const, label: '长裤' },
    { key: 'long-skirt' as const, label: '长裙' },
    { key: 'short-sleeve' as const, label: '短袖' },
    { key: 'short-skirt' as const, label: '短裙' },
    { key: 'long-sleeve' as const, label: '长袖' },
    { key: 'short-pants' as const, label: '短裤' }
]
const aiFittingPoseFilterOptions = [
    { key: 'all' as const, label: '全部姿势' },
    { key: 'half' as const, label: '半身' },
    { key: 'full' as const, label: '全身' }
]
const aiFittingPresets = computed<AiFittingPreset[]>(() => ([
    {
        id: 'ai-fitting-male-urban-1',
        groupId: 'male-urban',
        groupLabel: '都市男模',
        name: '都市男模-1',
        image: 'https://images.unsplash.com/photo-1515886657613-9f3515b0c78f?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'male',
        clothesFilter: 'coat',
        poseFilter: 'full',
        vip: true
    },
    {
        id: 'ai-fitting-male-urban-2',
        groupId: 'male-urban',
        groupLabel: '都市男模',
        name: '都市男模-2',
        image: 'https://images.unsplash.com/photo-1507679799987-c73779587ccf?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'male',
        clothesFilter: 'coat',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-male-casual-1',
        groupId: 'male-casual',
        groupLabel: '休闲男模',
        name: '休闲男模-1',
        image: 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'male',
        clothesFilter: 'short-sleeve',
        poseFilter: 'half',
        vip: true
    },
    {
        id: 'ai-fitting-male-casual-2',
        groupId: 'male-casual',
        groupLabel: '休闲男模',
        name: '休闲男模-2',
        image: 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'male',
        clothesFilter: 'long-sleeve',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-female-city-1',
        groupId: 'female-city',
        groupLabel: '都市女模',
        name: '都市女模-1',
        image: 'https://images.unsplash.com/photo-1496747611176-843222e1e57c?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'long-skirt',
        poseFilter: 'full',
        vip: true
    },
    {
        id: 'ai-fitting-female-city-2',
        groupId: 'female-city',
        groupLabel: '都市女模',
        name: '都市女模-2',
        image: 'https://images.unsplash.com/photo-1483985988355-763728e1935b?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'coat',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-female-city-3',
        groupId: 'female-city',
        groupLabel: '都市女模',
        name: '都市女模-3',
        image: 'https://images.unsplash.com/photo-1492106087820-71f1a00d2b11?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'coat',
        poseFilter: 'half',
        vip: true
    },
    {
        id: 'ai-fitting-female-city-4',
        groupId: 'female-city',
        groupLabel: '都市女模',
        name: '都市女模-4',
        image: 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'long-sleeve',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-female-curvy-1',
        groupId: 'female-curvy',
        groupLabel: '曲线女模',
        name: '曲线女模-1',
        image: 'https://images.unsplash.com/photo-1542295669297-4d352b042bca?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'long-sleeve',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-female-curvy-2',
        groupId: 'female-curvy',
        groupLabel: '曲线女模',
        name: '曲线女模-2',
        image: 'https://images.unsplash.com/photo-1509631179647-0177331693ae?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'short-skirt',
        poseFilter: 'full',
        vip: true
    },
    {
        id: 'ai-fitting-female-beach-1',
        groupId: 'female-beach',
        groupLabel: '海边女模',
        name: '海边女模-1',
        image: 'https://images.unsplash.com/photo-1503342217505-b0a15ec3261c?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'sleeveless',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-female-beach-2',
        groupId: 'female-beach',
        groupLabel: '海边女模',
        name: '海边女模-2',
        image: 'https://images.unsplash.com/photo-1495385794356-15371f348c31?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'female',
        clothesFilter: 'sleeveless',
        poseFilter: 'full'
    },
    {
        id: 'ai-fitting-child-1',
        groupId: 'child-play',
        groupLabel: '儿童模特',
        name: '儿童模特-1',
        image: 'https://images.unsplash.com/photo-1519345182560-3f2917c472ef?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'child',
        clothesFilter: 'short-sleeve',
        poseFilter: 'half'
    },
    {
        id: 'ai-fitting-child-2',
        groupId: 'child-play',
        groupLabel: '儿童模特',
        name: '儿童模特-2',
        image: 'https://images.unsplash.com/photo-1519238263530-99bdd11df2ea?auto=format&fit=crop&w=900&q=80',
        modelFilter: 'child',
        clothesFilter: 'short-pants',
        poseFilter: 'full',
        vip: true
    }
]))
const aiFittingVisiblePresets = computed(() => aiFittingPresets.value.filter((item) => {
    if (aiFittingModelFilter.value !== 'all' && item.modelFilter !== aiFittingModelFilter.value) return false
    if (aiFittingClothesFilter.value !== 'all' && item.clothesFilter !== aiFittingClothesFilter.value) return false
    if (aiFittingPoseFilter.value !== 'all' && item.poseFilter !== aiFittingPoseFilter.value) return false
    return true
}))
const aiFittingDisplayPresets = computed(() => {
    if (aiFittingMode.value !== 'group') return aiFittingVisiblePresets.value

    return Array.from(new Map(
        aiFittingVisiblePresets.value.map((item) => [item.groupId, item])
    ).values())
})
const aiFittingSelectedPresets = computed(() => aiFittingSelectedPresetIds.value
    .map((id) => aiFittingPresets.value.find((item) => item.id === id))
    .filter(Boolean) as AiFittingPreset[])
const aiFittingSelectionLimit = computed(() => (
    aiFittingMode.value === 'custom'
        ? maxAiFittingCustomModels
        : aiFittingMode.value === 'group' ? 4 : 1
))
const aiFittingSelectionCount = computed(() => (
    aiFittingMode.value === 'custom'
        ? aiFittingCustomModelImages.value.length
        : aiFittingSelectedPresetIds.value.length
))
const aiFittingSelectedThumbs = computed(() => (
    aiFittingMode.value === 'custom'
        ? aiFittingCustomModelImages.value.map((item) => item.preview)
        : aiFittingSelectedPresets.value.map((item) => item.image)
))
const isAiFittingSplitGarment = computed(() => aiFittingUploadCategory.value === 'set')
const aiFittingGarmentThumbs = computed(() => (
    isAiFittingSplitGarment.value
        ? [aiFittingTopPreview.value, aiFittingBottomPreview.value].filter(Boolean)
        : [originPreview.value].filter(Boolean)
))
const aiFittingPrimaryGarmentPreview = computed(() => (
    isAiFittingSplitGarment.value
        ? (aiFittingTopPreview.value || aiFittingBottomPreview.value || '')
        : originPreview.value
))
const aiFittingPrimaryGarmentName = computed(() => (
    isAiFittingSplitGarment.value
        ? (aiFittingTopName.value || aiFittingBottomName.value || '')
        : originName.value
))
const oneClickCleanupOptionSummary = computed(() => oneClickCleanupOptions
    .filter((item) => oneClickCleanupSelectedOptions.value.includes(item.key))
    .map((item) => item.title)
)
const buyerShowPresetConfigs = computed(() => buyerShowPresetFieldOrder.map((field) => ({
    field,
    label: buyerShowPresetFieldLabels[field],
    placeholder: buyerShowPresetPlaceholders[field],
    options: buyerShowPresetOptions[field]
})))

const currentResults = computed(() => resultMap.value[currentTool.value.id] ?? [])
const selectedPhotoRestoreModel = computed(() => (
    restoreModels.value.find((item) => item.code === photoRestoreModel.value)
))
const currentCreateCost = computed(() => {
    if (isPhotoRestoreTool.value) {
        const modelCost = Number(selectedPhotoRestoreModel.value?.base_cost)
        if (Number.isFinite(modelCost)) {
            return String(modelCost)
        }
    }
    return currentTool.value.createdCost
})
const isCreateDisabled = computed(() => {
    if (isCreating.value) return true
    if (isProductImageTool.value) {
        return (
            !originPreview.value
            || (productImageStyleTab.value === 'custom' && !productImageCustomStylePreview.value)
            || !Number(productImageWidth.value)
            || !Number(productImageHeight.value)
        )
    }
    if (isFashionLookbookTool.value) return !fashionLookbookClothesImages.value.length
    if (isHotCloneTool.value) {
        return !hotCloneProductImages.value.length || !hotCloneReferenceImages.value.length || !hotCloneSellingPoints.value.trim()
    }
    if (isProductMultiAngleTool.value) {
        return !productMultiAngleImages.value.length || !productMultiAngleSelectedViews.value.length
    }
    if (isShoeTryonTool.value) {
        return !shoeTryonModelPreview.value || !shoeTryonShoePreview.value
    }
    if (isModelWearTool.value) {
        return !modelWearModelPreview.value || !modelWearItemPreview.value
    }
    if (isImageTranslateTool.value) {
        return !originPreview.value || !imageTranslateTargetLanguage.value
    }
    if (isBackgroundRemovalTool.value) {
        return !originPreview.value
    }
    if (isOneClickCleanupTool.value) {
        return !oneClickCleanupImages.value.length || !oneClickCleanupSelectedOptions.value.length
    }
    if (isAiFittingTool.value) {
        const missingGarment = isAiFittingSplitGarment.value
            ? (!aiFittingTopPreview.value || !aiFittingBottomPreview.value)
            : !originPreview.value
        return missingGarment || (
            aiFittingMode.value === 'custom'
                ? !aiFittingCustomModelImages.value.length
                : !aiFittingSelectedPresetIds.value.length
        )
    }
    if (isBuyerShowTool.value) {
        return !originPreview.value
    }
    if (isSellingPointCardTool.value) {
        return !originPreview.value
    }
    if (!originPreview.value) return true
    if (isProductPromoVideoTool.value) return false
    if (isProductSuiteTool.value) {
        return !productSuiteSellingPoints.value.trim() || !productSuiteSelectedModules.value.length
    }
    if (isLineDrawingTool.value) return !lineDrawingPrompt.value.trim()
    if (isHairstyleTool.value) return !originPreview.value || !hairstyleReferencePreview.value
    if (!isLocalRedrawTool.value) return false
    return !hasMaskStroke.value || !localRedrawPrompt.value.trim()
})
const canUndoMask = computed(() => maskHistoryIndex.value > 0)
const canRedoMask = computed(() => maskHistoryIndex.value < maskHistory.value.length - 1)
const maskZoomPercent = computed(() => `${Math.round(maskEditorScale.value * 100)}%`)
const maskViewportStyle = computed(() => ({
    transform: `translate(calc(-50% + ${maskEditorOffset.value.x}px), calc(-50% + ${maskEditorOffset.value.y}px)) scale(${maskEditorScale.value})`
}))
const activeMaskSelection = computed(() => (
    maskSelections.value.find((item) => item.id === activeMaskSelectionId.value) ?? maskSelections.value[0]
))
const activeMaskColor = computed(() => activeMaskSelection.value?.color || '#19D7B2')
const activeMaskSize = computed(() => (activeMaskTool.value === 'erase' ? maskEraserSize.value : maskBrushSize.value))
const activeMaskSizeModel = computed({
    get: () => String(activeMaskSize.value),
    set: (value: string) => {
        const nextValue = Number(value)
        if (Number.isNaN(nextValue)) return
        if (activeMaskTool.value === 'erase') {
            maskEraserSize.value = nextValue
            return
        }
        maskBrushSize.value = nextValue
    }
})
const outpaintControls = computed(() => ([
    { key: 'left', label: '左', value: outpaintValues.value.left },
    { key: 'top', label: '上', value: outpaintValues.value.top },
    { key: 'right', label: '右', value: outpaintValues.value.right },
    { key: 'bottom', label: '下', value: outpaintValues.value.bottom }
]))
const photoRestoreModeOptions = computed(() => ([
    {
        key: 'repair' as const,
        title: '照片修复',
        description: '解决老照片的破损、模糊、噪点等问题'
    },
    {
        key: 'colorize' as const,
        title: '照片上色',
        description: '一键上色，精准还原场景与原色彩'
    }
]))
const photoRestoreModelOptions = computed(() => {
    if (restoreModels.value.length > 0) {
        return restoreModels.value.map((m) => ({
            key: m.code,
            title: m.name,
            description: m.description,
            badge: m.badge || ''
        }))
    }
    return [
        { key: 'general', title: '通用模型', description: '生成速度快，效果稳定', badge: '' },
        { key: 'nano', title: 'Nano模型', description: '生成速度快，效果极佳', badge: 'NEW' }
    ]
})
const imageTranslateSourceLanguageOptions = [
    { key: 'auto' as const, label: '自动识别' },
    { key: 'zh-hans' as const, label: '简体中文' },
    { key: 'zh-hant' as const, label: '繁体中文' },
    { key: 'en' as const, label: '英文' },
    { key: 'ja' as const, label: '日文' },
    { key: 'ko' as const, label: '韩文' },
    { key: 'fr' as const, label: '法文' },
    { key: 'de' as const, label: '德文' },
    { key: 'es' as const, label: '西班牙文' },
    { key: 'pt' as const, label: '葡萄牙文' },
    { key: 'ru' as const, label: '俄文' }
]
const imageTranslateTargetLanguageOptions = imageTranslateSourceLanguageOptions.filter((item) => item.key !== 'auto')
const oneClickCleanupOptions = [
    {
        key: 'watermark' as const,
        title: '消除水印',
        image: 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?auto=format&fit=crop&w=900&q=80'
    },
    {
        key: 'icon' as const,
        title: '消除Icon',
        image: 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=900&q=80'
    },
    {
        key: 'sticker' as const,
        title: '消除牛皮癣',
        image: 'https://images.unsplash.com/photo-1524504388940-b1c1722653e1?auto=format&fit=crop&w=900&q=80'
    },
    {
        key: 'text' as const,
        title: '消除文字',
        image: 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=900&q=80'
    }
]
const styleTransferOptions = computed(() => ([
    {
        key: 'hanman' as const,
        title: '精致韩漫',
        description: '保留人物结构，转换为清爽明亮的韩漫画风。'
    },
    {
        key: 'realistic' as const,
        title: '写实增强',
        description: '强调皮肤、服饰和光影质感，让画面更接近摄影成片。'
    },
    {
        key: 'monet' as const,
        title: '莫奈花园',
        description: '用柔和笔触和色块重组背景，呈现油画式氛围。'
    },
    {
        key: 'china-red' as const,
        title: '中国红',
        description: '增强东方红金配色与节庆氛围，突出视觉张力。'
    },
    {
        key: 'cyberpunk' as const,
        title: '赛博机械',
        description: '加入霓虹和机械细节，让主体更具未来科技感。'
    },
    {
        key: 'doll' as const,
        title: '玩偶手办',
        description: '转换为玩偶质感和立体塑形，适合角色风格化。'
    },
    {
        key: 'anime-film' as const,
        title: '动画电影',
        description: '强化镜头感、层次和电影氛围，适合角色主视觉。'
    },
    {
        key: 'watercolor' as const,
        title: '国风水墨',
        description: '以留白、水墨和轻纹理重绘画面，突出东方笔意。'
    }
]))
const productImageStyleTabOptions = [
    { key: 'template' as const, label: '场景模板' },
    { key: 'custom' as const, label: '自定义场景' }
]
const productImageSceneCategoryOptions = [
    { key: 'overview' as const, label: '目录总览' },
    { key: 'beauty' as const, label: '美容个护' },
    { key: 'food' as const, label: '食品饮品' },
    { key: 'shoes' as const, label: '鞋子' },
    { key: 'bags' as const, label: '箱包' },
    { key: 'phone' as const, label: '手机' },
    { key: 'digital' as const, label: '数码产品' },
    { key: 'computer' as const, label: '电脑&周边' },
    { key: 'appliance' as const, label: '家用电器' },
    { key: 'daily' as const, label: '生活百货' },
    { key: 'pet' as const, label: '宠物用品' },
    { key: 'mother-baby' as const, label: '母婴亲子' },
    { key: 'home' as const, label: '家居家装' }
]
const productImageStylePresets = computed(() => ([
    {
        key: 'static-soft-shadow' as const,
        tab: 'template' as const,
        title: '柔光白底',
        image: 'https://unsplash.com/photos/7XAYt9xX73s/download?force=true&w=640',
        categories: ['beauty', 'bags', 'phone', 'digital', 'daily'] as ProductImageSceneCategory[]
    },
    {
        key: 'static-leaf-light' as const,
        tab: 'template' as const,
        title: '叶影留白',
        image: 'https://unsplash.com/photos/QeYnt0Zsz7M/download?force=true&w=640',
        categories: ['beauty', 'food', 'pet'] as ProductImageSceneCategory[],
        vip: true
    },
    {
        key: 'static-neutral-paper' as const,
        tab: 'template' as const,
        title: '纸感静物',
        image: 'https://unsplash.com/photos/-s6awWWQgUY/download?force=true&w=640',
        categories: ['food', 'daily', 'mother-baby'] as ProductImageSceneCategory[]
    },
    {
        key: 'static-window-light' as const,
        tab: 'template' as const,
        title: '窗光桌面',
        image: 'https://images.unsplash.com/photo-1517705008128-361805f42e86?auto=format&fit=crop&w=640&q=80',
        categories: ['computer', 'home', 'appliance'] as ProductImageSceneCategory[],
        vip: true
    },
    {
        key: 'static-marble-surface' as const,
        tab: 'template' as const,
        title: '石纹台面',
        image: 'https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=640&q=80',
        categories: ['beauty', 'phone', 'digital', 'bags'] as ProductImageSceneCategory[]
    },
    {
        key: 'podium-beige-stage' as const,
        tab: 'template' as const,
        title: '柔米展台',
        image: 'https://unsplash.com/photos/MK7gTkCBAnU/download?force=true&w=640',
        categories: ['shoes', 'bags', 'beauty'] as ProductImageSceneCategory[],
        vip: true
    },
    {
        key: 'podium-gold-stage' as const,
        tab: 'template' as const,
        title: '暖金展台',
        image: 'https://unsplash.com/photos/dlEzfHZm5PE/download?force=true&w=640',
        categories: ['beauty', 'shoes', 'bags', 'phone'] as ProductImageSceneCategory[]
    },
    {
        key: 'podium-multi-stage' as const,
        tab: 'template' as const,
        title: '多层展台',
        image: 'https://unsplash.com/photos/XMQrrz88O1o/download?force=true&w=640',
        categories: ['digital', 'phone', 'computer', 'appliance'] as ProductImageSceneCategory[],
        vip: true
    },
    {
        key: 'podium-sunset-stage' as const,
        tab: 'template' as const,
        title: '暖橙橱窗',
        image: 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=640&q=80',
        categories: ['food', 'pet', 'daily'] as ProductImageSceneCategory[]
    },
    {
        key: 'podium-shadow-arch' as const,
        tab: 'template' as const,
        title: '拱影展台',
        image: 'https://images.unsplash.com/photo-1519741497674-611481863552?auto=format&fit=crop&w=640&q=80',
        categories: ['home', 'mother-baby', 'appliance'] as ProductImageSceneCategory[]
    }
]))
const productImageSceneCategoryLabel = computed(() => (
    productImageSceneCategoryOptions.find((item) => item.key === productImageSceneCategory.value)?.label || '目录总览'
))
const productImageVisibleStylePresets = computed(() => productImageStylePresets.value.filter((item) => {
    if (item.tab !== productImageStyleTab.value) return false
    if (item.tab !== 'template') return true
    if (productImageSceneCategory.value === 'overview') return true
    return item.categories?.includes(productImageSceneCategory.value)
}))
const productImageSelectedPreset = computed(() => (
    productImageStylePresets.value.find((item) => item.key === productImageSelectedPresetKey.value)
))
const productImageReferencePreview = computed(() => (
    productImageStyleTab.value === 'custom'
        ? productImageCustomStylePreview.value
        : (productImageSelectedPreset.value?.image || '')
))
const productImageSizeOptions = [
    { key: 'custom', label: '自定义', width: 800, height: 800 },
    { key: '1:1', label: '1:1(800*800)', width: 800, height: 800 },
    { key: '2:3', label: '2:3(800*1200)', width: 800, height: 1200 },
    { key: '3:2', label: '3:2(1200*800)', width: 1200, height: 800 },
    { key: '3:4', label: '3:4(750*1000)', width: 750, height: 1000 },
    { key: '4:3', label: '4:3(1000*750)', width: 1000, height: 750 },
    { key: '9:16', label: '9:16(900*1600)', width: 900, height: 1600 },
    { key: '16:9', label: '16:9(1600*900)', width: 1600, height: 900 },
    { key: 'taobao-jd-pdd', label: '淘宝/京东/拼多多(800*800)', width: 800, height: 800 },
    { key: 'xianyu', label: '闲鱼(800*800)', width: 800, height: 800 },
    { key: 'meituan', label: '美团(800*800)', width: 800, height: 800 },
    { key: 'xiaohongshu', label: '小红书(1242*1660)', width: 1242, height: 1660 },
    { key: 'ebay', label: 'Ebay(1600*1600)', width: 1600, height: 1600 },
    { key: 'amazon', label: 'Amazon(2000*2000)', width: 2000, height: 2000 },
    { key: 'aliexpress-temu', label: 'AliExpress/Temu(800*800)', width: 800, height: 800 },
    { key: 'lazada-shopee', label: 'Lazada/Shopee(1080*1080)', width: 1080, height: 1080 },
    { key: 'poshmark', label: 'Poshmark(1080*1080)', width: 1080, height: 1080 },
    { key: 'depop', label: 'Depop(1280*1280)', width: 1280, height: 1280 },
    { key: 'shopify', label: 'Shopify(2048*2048)', width: 2048, height: 2048 },
    { key: 'mercado-libre', label: 'Mercado Libre(1200*1200)', width: 1200, height: 1200 },
    { key: 'vinted', label: 'Vinted(800*600)', width: 800, height: 600 },
    { key: 'mercari', label: 'Mercari(1080*1080)', width: 1080, height: 1080 }
] as const
const productImageSelectedSizeOption = computed(() => (
    productImageSizeOptions.find((item) => item.key === productImageSizeKey.value)
))
const productImageSizeDisplayLabel = computed(() => {
    const matchedOption = productImageSelectedSizeOption.value
    if (matchedOption && matchedOption.key !== 'custom') return matchedOption.label
    return productImageSizeKey.value === 'custom'
        ? `自定义(${productImageWidth.value || 0}*${productImageHeight.value || 0})`
        : '自定义'
})
const fashionLookbookRatioOptions = computed(() => ([
    { key: 'auto' as const, label: '根据模特图自适应' },
    { key: '1:1' as const, label: '1:1 (2048×2048)' },
    { key: '3:4' as const, label: '3:4 (1536×2048)' },
    { key: '4:3' as const, label: '4:3 (2304×1728)' },
    { key: '4:5' as const, label: '4:5 (1840×2304)' },
    { key: '5:4' as const, label: '5:4 (2304×1840)' },
    { key: '2:3' as const, label: '2:3 (1536×2304)' },
    { key: '3:2' as const, label: '3:2 (2304×1536)' },
    { key: '16:9' as const, label: '16:9 (2848×1600)' },
    { key: '9:16' as const, label: '9:16 (1600×2848)' },
    { key: '21:9' as const, label: '21:9 (2940×1260)' }
]))
const productSuitePlatformOptions = computed(() => ([
    { key: '1688' as const, label: '1688' },
    { key: 'amazon' as const, label: 'Amazon' },
    { key: 'taobao-tmall' as const, label: '淘宝天猫' },
    { key: 'temu' as const, label: 'Temu' },
    { key: 'tiktok-shop' as const, label: 'TikTok Shop' },
    { key: 'pinduoduo' as const, label: '拼多多' },
    { key: 'douyin-shop' as const, label: '抖音电商' },
    { key: 'ozon' as const, label: 'OZON' },
    { key: 'independent' as const, label: '独立站' },
    { key: 'shopee' as const, label: 'Shopee' },
    { key: 'alibaba-international' as const, label: '阿里国际站' },
    { key: 'aliexpress' as const, label: '速卖通' },
    { key: 'jd' as const, label: '京东' }
]))
const productSuiteCountryOptions = computed(() => ([
    { key: 'us' as const, label: '美国' },
    { key: 'europe' as const, label: '欧洲' },
    { key: 'china' as const, label: '中国' },
    { key: 'russia' as const, label: '俄罗斯' },
    { key: 'sea' as const, label: '东南亚' },
    { key: 'spain' as const, label: '西班牙' },
    { key: 'germany' as const, label: '德国' },
    { key: 'japan' as const, label: '日本' },
    { key: 'brazil' as const, label: '巴西' },
    { key: 'malaysia' as const, label: '马来西亚' }
]))
const productSuiteLanguageOptions = computed(() => {
    const optionMap: Record<ProductSuiteCountry, Array<{ key: ProductSuiteLanguage; label: string }>> = {
        us: [
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'es', label: '西班牙语' },
            { key: 'none', label: '无文字' }
        ],
        europe: [
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'ru', label: '俄语' },
            { key: 'es', label: '西班牙语' },
            { key: 'de', label: '德语' },
            { key: 'zh-hant', label: '繁体中文' },
            { key: 'pt', label: '葡萄牙语' },
            { key: 'none', label: '无文字' }
        ],
        china: [
            { key: 'zh', label: '中文' },
            { key: 'zh-hant', label: '繁体中文' },
            { key: 'en', label: '英语' },
            { key: 'none', label: '无文字' }
        ],
        russia: [
            { key: 'ru', label: '俄语' },
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'none', label: '无文字' }
        ],
        sea: [
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'zh-hant', label: '繁体中文' },
            { key: 'ms', label: '马来语' },
            { key: 'none', label: '无文字' }
        ],
        spain: [
            { key: 'es', label: '西班牙语' },
            { key: 'en', label: '英语' },
            { key: 'pt', label: '葡萄牙语' },
            { key: 'none', label: '无文字' }
        ],
        germany: [
            { key: 'de', label: '德语' },
            { key: 'en', label: '英语' },
            { key: 'ru', label: '俄语' },
            { key: 'none', label: '无文字' }
        ],
        japan: [
            { key: 'ja', label: '日语' },
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'zh-hant', label: '繁体中文' },
            { key: 'none', label: '无文字' }
        ],
        brazil: [
            { key: 'pt', label: '葡萄牙语' },
            { key: 'en', label: '英语' },
            { key: 'es', label: '西班牙语' },
            { key: 'none', label: '无文字' }
        ],
        malaysia: [
            { key: 'ms', label: '马来语' },
            { key: 'en', label: '英语' },
            { key: 'zh', label: '中文' },
            { key: 'zh-hant', label: '繁体中文' },
            { key: 'none', label: '无文字' }
        ]
    }

    return optionMap[productSuiteCountry.value]
})
const productSuiteRatioOptions = computed(() => ([
    { key: '1:1' as const, label: '1:1 (2048×2048)' },
    { key: '3:4' as const, label: '3:4 (1728×2304)' },
    { key: '16:9' as const, label: '16:9 (2848×1600)' },
    { key: '9:16' as const, label: '9:16 (1600×2848)' }
]))
const hotCloneLanguageOptions = computed(() => ([
    { key: 'zh' as const, label: '中文' },
    { key: 'en' as const, label: '英文' },
    { key: 'ja' as const, label: '日文' },
    { key: 'ko' as const, label: '韩文' }
]))
const productSuiteModuleOptions = computed(() => ([
    { key: 'hero' as const, title: '首屏主视觉', description: '吸引眼球的首屏大图，展示产品第一印象。' },
    { key: 'core-benefit' as const, title: '核心卖点图', description: '突出产品核心优势与购买理由。' },
    { key: 'usage-scene' as const, title: '使用场景图', description: '展示产品真实使用场景与代入感。' },
    { key: 'multi-angle' as const, title: '多角度图', description: '全方位展示产品外观、结构与细节。' },
    { key: 'mood-scene' as const, title: '场景氛围图', description: '营造品牌调性与使用氛围感。' },
    { key: 'detail' as const, title: '商品细节图', description: '放大展示材质、做工与质感细节。' },
    { key: 'compare' as const, title: '对比图', description: '展示使用前后或不同方案的差异。' },
    { key: 'size-spec' as const, title: '尺寸规格图', description: '清晰传达尺寸、规格和参数信息。' },
    { key: 'spec-sheet' as const, title: '详细规格参数表', description: '清晰呈现产品核心参数信息。' },
    { key: 'accessories' as const, title: '配件/赠品图', description: '明确购买收货全套物品。' },
    { key: 'craft-process' as const, title: '工艺制作图', description: '展示产品制作工艺流程。' },
    { key: 'series-display' as const, title: '系列展示图', description: '展示多款式/多SKU系列内容。' },
    { key: 'ingredient' as const, title: '商品成分图', description: '展示产品成分或材质。' },
    { key: 'usage-guide' as const, title: '使用建议图', description: '展示正确使用方法与注意事项。' },
    { key: 'after-sales' as const, title: '售后保障图', description: '展示品牌售后承诺内容。' },
    { key: 'purchase-guide' as const, title: '购买引导图', description: '引导用户下单购买。' }
]))
const productMultiAngleViewOptions = computed(() => ([
    { key: 'front' as const, title: '正面' },
    { key: 'side' as const, title: '侧面' },
    { key: 'back' as const, title: '背面' },
    { key: 'top' as const, title: '俯视' },
    { key: 'bottom' as const, title: '仰视' },
    { key: 'forty-five' as const, title: '45度角' }
]))
const productPromoVideoRatioOptions = computed(() => ([
    {
        key: '9:16' as const,
        title: '9:16',
        description: '适合竖版短视频投流与信息流场景'
    },
    {
        key: '16:9' as const,
        title: '16:9',
        description: '适合横版传播、详情页与落地页场景'
    }
]))
const productPromoVideoDurationOptions = computed(() => ([
    { key: '5' as const, title: '5秒' },
    { key: '10' as const, title: '10秒' },
    { key: '12' as const, title: '12秒' }
]))
const productPromoVideoTypeOptions = computed(() => ([
    {
        key: 'product' as const,
        title: '产品宣传',
        description: '聚焦产品亮点与购买吸引力',
        badge: '推荐'
    },
    {
        key: 'creative' as const,
        title: '创意应用',
        description: '更强调创意镜头和视觉记忆点'
    },
    {
        key: 'feature' as const,
        title: '功能展示',
        description: '突出功能卖点和使用方式'
    },
    {
        key: 'unboxing' as const,
        title: '开箱体验',
        description: '呈现拆箱过程与上手体验'
    },
    {
        key: 'story' as const,
        title: '场景故事',
        description: '结合人物和场景讲清使用价值'
    }
]))
const sellingPointCardRatioOptions = computed(() => ([
    { key: '3:4' as const, title: '3:4', description: '适合商品首图与详情主视觉' },
    { key: '9:16' as const, title: '9:16', description: '适合移动端长图与信息流' },
    { key: '4:3' as const, title: '4:3', description: '适合图文结合与内容展示' },
    { key: '16:9' as const, title: '16:9', description: '适合横版横幅与落地页场景' }
]))
const sellingPointCardSelectConfigs = computed(() => ([
    {
        field: 'productType' as const,
        label: sellingPointCardSelectFieldLabels.productType,
        options: sellingPointCardProductTypeOptions
    },
    {
        field: 'language' as const,
        label: sellingPointCardSelectFieldLabels.language,
        options: sellingPointCardLanguageOptions
    },
    {
        field: 'coreSellingPoint' as const,
        label: sellingPointCardSelectFieldLabels.coreSellingPoint,
        options: sellingPointCardCoreSellingPointOptions
    },
    {
        field: 'layoutStyle' as const,
        label: sellingPointCardSelectFieldLabels.layoutStyle,
        options: sellingPointCardLayoutOptions
    },
    {
        field: 'focus' as const,
        label: sellingPointCardSelectFieldLabels.focus,
        options: sellingPointCardFocusOptions
    },
    {
        field: 'mainTitle' as const,
        label: sellingPointCardSelectFieldLabels.mainTitle,
        options: sellingPointCardMainTitleOptions
    },
    {
        field: 'subTitle' as const,
        label: sellingPointCardSelectFieldLabels.subTitle,
        options: sellingPointCardSubTitleOptions
    },
    {
        field: 'supportingElement' as const,
        label: sellingPointCardSelectFieldLabels.supportingElement,
        options: sellingPointCardSupportingElementOptions
    },
    {
        field: 'targetMarket' as const,
        label: sellingPointCardSelectFieldLabels.targetMarket,
        options: sellingPointCardTargetMarketOptions
    }
]))
const hotCloneProductNameCount = computed(() => hotCloneProductName.value.length)
const hotCloneSellingPointsCount = computed(() => hotCloneSellingPoints.value.length)
const productSuiteSellingPointsCount = computed(() => productSuiteSellingPoints.value.length)
const productSuiteModuleOrderMap = computed(() => Object.fromEntries(
    productSuiteSelectedModules.value.map((key, index) => [key, index + 1])
) as Record<ProductSuiteModuleKey, number>)
const productMultiAngleViewOrderMap = computed(() => Object.fromEntries(
    productMultiAngleSelectedViews.value.map((key, index) => [key, index + 1])
) as Record<ProductMultiAngleViewKey, number>)
const filteredResults = computed(() => {
    const filter = activeFilter.value
    if (filter === '全部') return currentResults.value
    return currentResults.value.filter((item) => item.kind === getToolResultFilterKind(filter))
})

const setUploadFieldValue = (field: UploadField, payload: { preview: string; name: string; source: UploadSource }) => {
    if (field === 'mask') {
        maskPreview.value = payload.preview
        maskName.value = payload.name
        maskSource.value = payload.source
        hasMaskStroke.value = !!payload.preview
        return
    }

    if (field === 'fashion-model') {
        fashionLookbookModelPreview.value = payload.preview
        fashionLookbookModelName.value = payload.name
        fashionLookbookModelSource.value = payload.source
        return
    }

    if (field === 'product-image-style') {
        productImageCustomStylePreview.value = payload.preview
        productImageCustomStyleName.value = payload.name
        productImageCustomStyleSource.value = payload.source
        return
    }

    if (field === 'fashion-clothes') {
        appendFashionLookbookClothesImages([payload])
        return
    }

    if (field === 'hot-clone-product') {
        appendHotCloneProductImages([payload])
        return
    }

    if (field === 'hot-clone-reference') {
        appendHotCloneReferenceImages([payload])
        return
    }

    if (field === 'product-multi-angle') {
        appendProductMultiAngleImages([payload])
        return
    }

    if (field === 'cleanup-images') {
        appendOneClickCleanupImages([payload])
        return
    }

    if (field === 'shoe-tryon-model') {
        shoeTryonModelPreview.value = payload.preview
        shoeTryonModelName.value = payload.name
        shoeTryonModelSource.value = payload.source
        return
    }

    if (field === 'shoe-tryon-shoe') {
        shoeTryonShoePreview.value = payload.preview
        shoeTryonShoeName.value = payload.name
        shoeTryonShoeSource.value = payload.source
        return
    }

    if (field === 'model-wear-model') {
        modelWearModelPreview.value = payload.preview
        modelWearModelName.value = payload.name
        modelWearModelSource.value = payload.source
        return
    }

    if (field === 'model-wear-item') {
        modelWearItemPreview.value = payload.preview
        modelWearItemName.value = payload.name
        modelWearItemSource.value = payload.source
        return
    }

    if (field === 'ai-fitting-top') {
        aiFittingTopPreview.value = payload.preview
        aiFittingTopName.value = payload.name
        aiFittingTopSource.value = payload.source
        return
    }

    if (field === 'ai-fitting-bottom') {
        aiFittingBottomPreview.value = payload.preview
        aiFittingBottomName.value = payload.name
        aiFittingBottomSource.value = payload.source
        return
    }

    if (field === 'ai-fitting-model') {
        appendAiFittingCustomModels([payload])
        return
    }

    if (field === 'hairstyle-reference') {
        hairstyleReferencePreview.value = payload.preview
        hairstyleReferenceName.value = payload.name
        hairstyleReferenceSource.value = payload.source
        return
    }

    if (field === 'buyer-show-reference') {
        buyerShowReferencePreview.value = payload.preview
        buyerShowReferenceName.value = payload.name
        buyerShowReferenceSource.value = payload.source
        return
    }

    if (field === 'selling-point-reference') {
        sellingPointCardReferencePreview.value = payload.preview
        sellingPointCardReferenceName.value = payload.name
        sellingPointCardReferenceSource.value = payload.source
        return
    }

    originPreview.value = payload.preview
    originName.value = payload.name
    originSource.value = payload.source
    if (isLocalRedrawTool.value) {
        clearMaskDrawing()
        nextTick(() => syncMaskCanvasSize())
    }
}

const applyGalleryUpload = (field: UploadField = 'origin') => {
    activeUploadField.value = field
    activeGalleryCategory.value = '图片生成'
    showGalleryModal.value = true
}

const clearUploads = () => {
    originPreview.value = ''
    originName.value = ''
    originSource.value = 'gallery'
    hairstyleReferencePreview.value = ''
    hairstyleReferenceName.value = ''
    hairstyleReferenceSource.value = 'gallery'
    maskPreview.value = ''
    maskName.value = ''
    maskSource.value = 'gallery'
    hasMaskStroke.value = false
    isMaskPointerDown.value = false
    lastMaskPoint.value = null
    showMaskEditor.value = false
    activeMaskTool.value = 'brush'
    maskEditorScale.value = 1
    maskEditorOffset.value = { x: 0, y: 0 }
    maskPanStart.value = null
    maskHistory.value = ['']
    maskHistoryIndex.value = 0
    maskEditorInitialSnapshot.value = ''
    maskBrushSize.value = 28
    maskEraserSize.value = 36
    maskSelections.value = [{ id: 'mask-selection-1', color: '#19D7B2' }]
    activeMaskSelectionId.value = 'mask-selection-1'
    outpaintValues.value = {
        left: 200,
        top: 200,
        right: 200,
        bottom: 200
    }
    photoRestoreMode.value = 'repair'
    photoRestoreModel.value = restoreModels.value.length > 0 ? restoreModels.value[0].code : ''
    lineDrawingModel.value = 'nano'
    lineDrawingPrompt.value = ''
    imageTranslateSourceLanguage.value = 'auto'
    imageTranslateTargetLanguage.value = 'en'
    imageTranslatePrompt.value = ''
    styleTransferStyle.value = 'hanman'
    productImageStyleTab.value = 'template'
    productImageSceneCategory.value = 'overview'
    productImageSelectedPresetKey.value = 'static-soft-shadow'
    productImageCustomStylePreview.value = ''
    productImageCustomStyleName.value = ''
    productImageCustomStyleSource.value = 'gallery'
    productImageSizeKey.value = '1:1'
    productImageWidth.value = '800'
    productImageHeight.value = '800'
    fashionLookbookClothesImages.value = []
    fashionLookbookModelPreview.value = ''
    fashionLookbookModelName.value = ''
    fashionLookbookModelSource.value = 'gallery'
    fashionLookbookRatio.value = '3:4'
    fashionLookbookPrompt.value = ''
    hotCloneProductImages.value = []
    hotCloneReferenceImages.value = []
    hotCloneProductName.value = ''
    hotCloneSellingPoints.value = ''
    hotCloneLanguage.value = 'zh'
    hotCloneRatio.value = '3:4'
    productMultiAngleImages.value = []
    productMultiAngleSelectedViews.value = [...defaultProductMultiAngleViews]
    productMultiAnglePrompt.value = ''
    oneClickCleanupImages.value = []
    oneClickCleanupSelectedOptions.value = ['watermark', 'icon', 'sticker', 'text']
    productPromoVideoRatio.value = '9:16'
    productPromoVideoDuration.value = '5'
    productPromoVideoType.value = 'product'
    productPromoVideoPrompt.value = ''
    shoeTryonModelPreview.value = ''
    shoeTryonModelName.value = ''
    shoeTryonModelSource.value = 'gallery'
    shoeTryonShoePreview.value = ''
    shoeTryonShoeName.value = ''
    shoeTryonShoeSource.value = 'gallery'
    shoeTryonPrompt.value = ''
    modelWearModelPreview.value = ''
    modelWearModelName.value = ''
    modelWearModelSource.value = 'gallery'
    modelWearItemPreview.value = ''
    modelWearItemName.value = ''
    modelWearItemSource.value = 'gallery'
    modelWearPrompt.value = ''
    aiFittingUploadCategory.value = 'tops'
    aiFittingMode.value = 'single'
    aiFittingModelFilter.value = 'all'
    aiFittingClothesFilter.value = 'all'
    aiFittingPoseFilter.value = 'all'
    aiFittingTopPreview.value = ''
    aiFittingTopName.value = ''
    aiFittingTopSource.value = 'gallery'
    aiFittingBottomPreview.value = ''
    aiFittingBottomName.value = ''
    aiFittingBottomSource.value = 'gallery'
    aiFittingSelectedPresetIds.value = []
    aiFittingSelectedGroupId.value = ''
    aiFittingCustomModelImages.value = []
    buyerShowReferencePreview.value = ''
    buyerShowReferenceName.value = ''
    buyerShowReferenceSource.value = 'gallery'
    buyerShowPrompt.value = ''
    buyerShowOpenField.value = ''
    buyerShowPresetValues.value = createEmptyBuyerShowPresetValues()
    sellingPointCardReferencePreview.value = ''
    sellingPointCardReferenceName.value = ''
    sellingPointCardReferenceSource.value = 'gallery'
    sellingPointCardRatio.value = '3:4'
    sellingPointCardOpenField.value = ''
    sellingPointCardPrompt.value = ''
    sellingPointCardPresetValues.value = createEmptySellingPointCardPresetValues()
    productSuiteImages.value = []
    productSuitePlatform.value = 'amazon'
    productSuiteCountry.value = 'us'
    productSuiteLanguage.value = 'en'
    productSuiteRatio.value = '1:1'
    productSuiteSellingPoints.value = ''
    productSuiteSelectedModules.value = [...defaultProductSuiteModules]
}

watch(() => props.toolId, () => {
    activeResolution.value = toolDetailResolutionOptions[0]
    activeFilter.value = '全部'
    activeResultId.value = ''
    filterMenuOpen.value = false
    productSuitePlatformMenuOpen.value = false
    productSuiteCountryMenuOpen.value = false
    productSuiteLanguageMenuOpen.value = false
    productSuiteRatioMenuOpen.value = false
    productImageSceneCategoryMenuOpen.value = false
    fashionLookbookRatioMenuOpen.value = false
    hotCloneLanguageMenuOpen.value = false
    hotCloneRatioMenuOpen.value = false
    imageTranslateSourceMenuOpen.value = false
    imageTranslateTargetMenuOpen.value = false
    aiFittingUploadCategoryMenuOpen.value = false
    aiFittingModelMenuOpen.value = false
    aiFittingClothesMenuOpen.value = false
    aiFittingPoseMenuOpen.value = false
    buyerShowOpenField.value = ''
    isCreating.value = false
    if (createTimer.value) {
        clearTimeout(createTimer.value)
        createTimer.value = null
    }
    clearUploads()
    localRedrawPrompt.value = ''
}, { immediate: true })

watch(isPhotoRestoreTool, async (active) => {
    if (!active || restoreModels.value.length > 0) return
    try {
        const data = await $request.get({ url: '/aigc.ai/restoreModels' })
        if (Array.isArray(data) && data.length > 0) {
            restoreModels.value = data
            if (!photoRestoreModel.value) {
                photoRestoreModel.value = data[0].code
            }
        }
    } catch {
        // fallback to hardcoded options
    }
}, { immediate: true })

watch(originPreview, async () => {
    if (!isLocalRedrawTool.value) return
    await nextTick()
    syncMaskCanvasSize()
})

watch(productSuiteCountry, () => {
    const languageKeys = productSuiteLanguageOptions.value.map((item) => item.key)
    if (languageKeys.includes(productSuiteLanguage.value)) return
    productSuiteLanguage.value = languageKeys[0] || 'en'
}, { immediate: true })

const prependResult = (result: ToolResultItem) => {
    resultMap.value = {
        ...resultMap.value,
        [currentTool.value.id]: [result, ...(resultMap.value[currentTool.value.id] ?? [])]
    }
}

const updateOutpaintValue = (key: OutpaintDirection, value: number) => {
    outpaintValues.value = {
        ...outpaintValues.value,
        [key]: Math.max(0, Math.min(8192, Number.isNaN(value) ? 0 : value))
    }
}

const getPhotoRestoreModeLabel = (mode: PhotoRestoreMode) => ({
    repair: '照片修复',
    colorize: '照片上色'
}[mode])

const getPhotoRestoreModelLabel = (model: PhotoRestoreModel) => {
    const dynamic = photoRestoreModelOptions.value.find((m) => m.key === model)
    if (dynamic) return dynamic.title
    return ({ general: '通用模型', nano: 'Nano模型' } as Record<string, string>)[model] || model
}

const getLineDrawingModelLabel = (model: PhotoRestoreModel) => ({
    general: '通用模型',
    nano: 'Nano模型'
}[model])

const getImageTranslateLanguageLabel = (language: ImageTranslateLanguage) => ({
    auto: '自动识别',
    'zh-hans': '简体中文',
    'zh-hant': '繁体中文',
    en: '英文',
    ja: '日文',
    ko: '韩文',
    fr: '法文',
    de: '德文',
    es: '西班牙文',
    pt: '葡萄牙文',
    ru: '俄文'
}[language])

const getStyleTransferLabel = (style: StyleTransferStyle) => ({
    hanman: '精致韩漫',
    realistic: '写实增强',
    monet: '莫奈花园',
    'china-red': '中国红',
    cyberpunk: '赛博机械',
    doll: '玩偶手办',
    'anime-film': '动画电影',
    watercolor: '国风水墨'
}[style])

const getFashionLookbookRatioLabel = (ratio: FashionLookbookRatio) => ({
    auto: '根据模特图自适应',
    '1:1': '1:1',
    '3:4': '3:4',
    '4:3': '4:3',
    '4:5': '4:5',
    '5:4': '5:4',
    '2:3': '2:3',
    '3:2': '3:2',
    '16:9': '16:9',
    '9:16': '9:16',
    '21:9': '21:9'
}[ratio])

const getHotCloneLanguageLabel = (language: HotCloneLanguage) => ({
    zh: '中文',
    en: '英文',
    ja: '日文',
    ko: '韩文'
}[language])

const getProductSuitePlatformLabel = (platform: ProductSuitePlatform) => ({
    '1688': '1688',
    amazon: 'Amazon',
    'taobao-tmall': '淘宝天猫',
    temu: 'Temu',
    'tiktok-shop': 'TikTok Shop',
    pinduoduo: '拼多多',
    'douyin-shop': '抖音电商',
    ozon: 'OZON',
    independent: '独立站',
    shopee: 'Shopee',
    'alibaba-international': '阿里国际站',
    aliexpress: '速卖通',
    jd: '京东'
}[platform])

const getProductSuiteCountryLabel = (country: ProductSuiteCountry) => ({
    us: '美国',
    europe: '欧洲',
    china: '中国',
    russia: '俄罗斯',
    sea: '东南亚',
    spain: '西班牙',
    germany: '德国',
    japan: '日本',
    brazil: '巴西',
    malaysia: '马来西亚'
}[country])

const getProductSuiteLanguageLabel = (language: ProductSuiteLanguage) => ({
    en: '英语',
    zh: '中文',
    ru: '俄语',
    es: '西班牙语',
    de: '德语',
    ja: '日语',
    'zh-hant': '繁体中文',
    pt: '葡萄牙语',
    ms: '马来语',
    none: '无文字'
}[language])

const getBuyerShowPresetLabel = (field: BuyerShowPresetField, value: string) => (
    buyerShowPresetOptions[field].find((item) => item.key === value)?.label || ''
)

const getBuyerShowPresetDisplay = (field: BuyerShowPresetField) => (
    getBuyerShowPresetLabel(field, buyerShowPresetValues.value[field]) || buyerShowPresetPlaceholders[field]
)

function createEmptyBuyerShowPresetValues(): Record<BuyerShowPresetField, string> {
    return {
        productType: '',
        productStatus: '',
        presentationMode: '',
        sceneMood: '',
        productRealism: '',
        environmentRealism: '',
        shootRealism: '',
        targetMarket: ''
    }
}

const buyerShowRecognitionRules: Record<BuyerShowPresetField, Array<{ key: string; keywords: string[] }>> = {
    productType: [
        { key: 't-shirt', keywords: ['t恤', 'tee', 't-shirt', 'tshirt'] },
        { key: 'backpack', keywords: ['背包', 'backpack', 'bagpack', 'rucksack'] },
        { key: 'shoes', keywords: ['鞋', '鞋子', 'shoe', 'sneaker', 'boot', 'loafer', 'heels'] },
        { key: 'small-appliance', keywords: ['小家电', '电器', 'appliance', 'kettle', 'blender', 'dryer'] },
        { key: 'tv', keywords: ['电视', 'tv', 'television'] },
        { key: 'cosmetics', keywords: ['化妆品', '彩妆', '护肤', 'makeup', 'cosmetic', 'skincare'] },
        { key: 'perfume', keywords: ['香水', 'perfume', 'fragrance', 'cologne'] },
        { key: 'fruit', keywords: ['水果', 'fruit', 'apple', 'orange', 'banana', 'grape'] },
        { key: 'drink', keywords: ['饮料', 'drink', 'beverage', 'juice', 'soda', 'coffee', 'tea'] },
        { key: 'car', keywords: ['汽车', 'car', 'auto', 'vehicle'] },
        { key: 'container', keywords: ['集装箱', '货柜', 'container', 'cargo'] },
        { key: 'bluetooth-headset', keywords: ['蓝牙耳机', '耳机', 'headset', 'earbud', 'earphone', 'bluetooth'] },
        { key: 'phone', keywords: ['手机', 'phone', 'iphone', 'android', 'smartphone'] },
        { key: 'luggage', keywords: ['行李箱', '拉杆箱', 'luggage', 'suitcase'] },
        { key: 'stationery', keywords: ['文具', 'stationery', 'pen', 'pencil', 'notebook'] },
        { key: 'machinery', keywords: ['机械设备', '机械', 'machine', 'machinery', 'equipment'] },
        { key: 'sofa', keywords: ['沙发', 'sofa', 'couch'] },
        { key: 'necklace', keywords: ['项链', 'necklace', 'pendant'] },
        { key: 'toy', keywords: ['玩具', 'toy', 'doll', 'lego'] },
        { key: 'yoga-wear', keywords: ['瑜伽服', 'yoga', 'legging', 'sports bra'] },
        { key: 'fitness-equipment', keywords: ['健身器材', '哑铃', 'fitness', 'dumbbell', 'workout'] },
        { key: 'laptop', keywords: ['笔记本电脑', '笔记本', 'laptop', 'notebook computer', 'macbook'] },
        { key: 'figure', keywords: ['手办', 'figure', 'figurine'] },
        { key: 'clothing', keywords: ['服装', '衣服', 'clothing', 'apparel', 'dress', 'jacket', 'coat', 'hoodie'] }
    ],
    productStatus: [
        { key: 'full-delivery', keywords: ['快递箱', '包装箱', 'carton', 'package box', 'shipping box', 'parcel'] },
        { key: 'product-accessories-display', keywords: ['配件', '套装', 'accessory', 'kit', 'bundle'] },
        { key: 'new-unsealed', keywords: ['未拆', '全新', 'sealed', 'unopened', 'new in box'] },
        { key: 'installation', keywords: ['安装', '组装', 'install', 'assembly', 'mounted'] },
        { key: 'wearing', keywords: ['穿戴', '上身', '试穿', 'wear', 'worn', 'try-on'] },
        { key: 'long-term-use', keywords: ['磨损', '使用痕迹', 'scratch', 'worn-out'] },
        { key: 'in-use', keywords: ['使用中', '使用', 'in use', 'using', 'demo'] },
        { key: 'natural-placement', keywords: ['摆放', '陈列', 'flatlay', 'display', 'placed'] }
    ],
    presentationMode: [
        { key: 'detail-closeup', keywords: ['细节', '局部', 'closeup', 'close-up', 'macro', 'detail'] },
        { key: 'handheld', keywords: ['手持', 'handheld', 'holding in hand'] },
        { key: 'wearing', keywords: ['穿戴', '上身', '试穿', 'wear', 'model'] },
        { key: 'mirror-selfie', keywords: ['自拍', '对镜', 'selfie', 'mirror'] },
        { key: 'use-shot', keywords: ['使用中', '演示', 'demo', 'using'] },
        { key: 'size-compare', keywords: ['对比', '大小', 'compare', 'comparison', 'scale'] },
        { key: 'natural-placement', keywords: ['摆放', '陈列', 'flatlay', 'display', 'placed'] },
        { key: 'main-display', keywords: ['主体', '主图', 'hero', 'main visual', 'main shot'] }
    ],
    sceneMood: [
        { key: 'holiday', keywords: ['节日', '圣诞', '新年', 'holiday', 'christmas', 'festival'] },
        { key: 'car', keywords: ['车内', '车里', 'car interior', 'inside car'] },
        { key: 'sports', keywords: ['运动', '健身房', '跑步', 'gym', 'sport', 'running'] },
        { key: 'home', keywords: ['居家', '客厅', '卧室', 'home', 'living room', 'bedroom'] },
        { key: 'outdoor', keywords: ['外出', '户外', '街头', 'street', 'outdoor', 'travel'] },
        { key: 'blurred-closeup', keywords: ['模糊', '虚化', 'blur', 'bokeh'] },
        { key: 'none', keywords: ['白底', '纯色', '无场景', 'studio', 'plain background', 'isolated'] }
    ],
    productRealism: [
        { key: 'package-wrinkle', keywords: ['褶皱', '折痕', 'wrinkle', 'fold'] },
        { key: 'long-term-wear', keywords: ['磨损', '旧', 'used', 'worn'] },
        { key: 'real-usage', keywords: ['真实环境', '生活化', 'real scene', 'real environment'] }
    ],
    environmentRealism: [
        { key: 'cluttered', keywords: ['杂乱', '凌乱', 'messy', 'cluttered'] },
        { key: 'pet', keywords: ['宠物', '猫', '狗', 'pet', 'cat', 'dog'] },
        { key: 'partial-person', keywords: ['人物局部', '手部', '人手', 'hand', 'arm', 'partial person'] },
        { key: 'casual-placement', keywords: ['随意摆放', '随手放', 'casual placement', 'casual'] },
        { key: 'person-no-makeup', keywords: ['素颜', 'no makeup', 'bare face'] },
        { key: 'daily-outfit', keywords: ['日常穿搭', '日常服饰', 'daily outfit', 'casual outfit'] }
    ],
    shootRealism: [
        { key: 'casual-shot', keywords: ['随手拍', '抓拍', 'snapshot', 'casual shot', 'phone shot'] },
        { key: 'low-resolution', keywords: ['低像素', '低清', 'low resolution', 'low-res'] },
        { key: 'motion-blur', keywords: ['手抖', '模糊', 'motion blur', 'blur'] },
        { key: 'backlight', keywords: ['逆光', '反光', 'backlight', 'glare', 'reflection'] },
        { key: 'mirror-selfie', keywords: ['自拍', '对镜', 'selfie', 'mirror'] }
    ],
    targetMarket: [
        { key: 'us', keywords: ['美国', 'us', 'usa', 'american'] },
        { key: 'europe', keywords: ['欧洲', 'eu', 'europe', 'european'] },
        { key: 'china', keywords: ['中国', 'cn', 'china', 'chinese'] },
        { key: 'russia', keywords: ['俄罗斯', 'russia', 'russian'] },
        { key: 'sea', keywords: ['东南亚', 'sea', 'southeast asia', 'asean'] },
        { key: 'spain', keywords: ['西班牙', 'spain', 'spanish'] },
        { key: 'germany', keywords: ['德国', 'germany', 'german'] },
        { key: 'japan', keywords: ['日本', 'japan', 'japanese'] },
        { key: 'brazil', keywords: ['巴西', 'brazil', 'brazilian'] },
        { key: 'malaysia', keywords: ['马来西亚', 'malaysia', 'malay'] }
    ]
}

const findBuyerShowRecognitionValue = (field: BuyerShowPresetField, source: string) => {
    const normalizedSource = source.toLowerCase()
    const matchedRule = buyerShowRecognitionRules[field].find((rule) => (
        rule.keywords.some((keyword) => normalizedSource.includes(keyword))
    ))

    return matchedRule?.key || ''
}

function createEmptySellingPointCardPresetValues(): SellingPointCardPresetValues {
    return {
        productType: '' as SellingPointCardProductType | '',
        language: '' as SellingPointCardLanguage | '',
        coreSellingPoint: '' as SellingPointCardCoreSellingPoint | '',
        layoutStyle: '' as SellingPointCardLayoutStyle | '',
        focus: '' as SellingPointCardFocus | '',
        mainTitle: '' as SellingPointCardTitleMode | '',
        subTitle: '' as SellingPointCardTitleMode | '',
        supportingElement: '' as SellingPointCardSupportingElement | '',
        targetMarket: '' as ProductSuiteCountry | ''
    }
}

const sellingPointCardSelectFieldLabels: Record<SellingPointCardSelectField, string> = {
    productType: '产品类型',
    language: '文案语言',
    coreSellingPoint: '核心卖点',
    layoutStyle: '排版方式',
    focus: '卖点中心',
    mainTitle: '主标题',
    subTitle: '副标题',
    supportingElement: '辅助元素',
    targetMarket: '目标市场'
}

const sellingPointCardSelectFieldPlaceholders: Record<SellingPointCardSelectField, string> = {
    productType: '请选择产品类型',
    language: '请选择文案语言',
    coreSellingPoint: '请选择核心卖点',
    layoutStyle: '请选择排版方式',
    focus: '请选择卖点中心',
    mainTitle: '请选择主标题',
    subTitle: '请选择副标题',
    supportingElement: '请选择辅助元素',
    targetMarket: '请选择目标市场'
}

const sellingPointCardProductTypeOptions = [
    { key: 'clothing' as const, label: '服装' },
    { key: 't-shirt' as const, label: 'T恤' },
    { key: 'backpack' as const, label: '背包' },
    { key: 'shoes' as const, label: '鞋子' },
    { key: 'small-appliance' as const, label: '小家电' },
    { key: 'tv' as const, label: '电视' },
    { key: 'cosmetics' as const, label: '化妆品' },
    { key: 'perfume' as const, label: '香水' },
    { key: 'fruit' as const, label: '水果' },
    { key: 'drink' as const, label: '饮料' },
    { key: 'car' as const, label: '汽车' },
    { key: 'container' as const, label: '集装箱' },
    { key: 'bluetooth-headset' as const, label: '蓝牙耳机' },
    { key: 'phone' as const, label: '手机' },
    { key: 'luggage' as const, label: '行李箱' },
    { key: 'stationery' as const, label: '文具' },
    { key: 'machinery' as const, label: '机械设备' },
    { key: 'sofa' as const, label: '沙发' },
    { key: 'necklace' as const, label: '项链' },
    { key: 'toy' as const, label: '玩具' },
    { key: 'yoga-wear' as const, label: '瑜伽服' },
    { key: 'fitness-equipment' as const, label: '健身器材' },
    { key: 'laptop' as const, label: '笔记本电脑' },
    { key: 'figure' as const, label: '手办' }
] satisfies Array<{ key: SellingPointCardProductType; label: string }>

const sellingPointCardLanguageOptions = [
    { key: 'none' as const, label: '无文案' },
    { key: 'zh-hans' as const, label: '简体中文' },
    { key: 'zh-hant' as const, label: '繁体中文' },
    { key: 'en' as const, label: '英文' },
    { key: 'zh-en' as const, label: '中英文混排' },
    { key: 'ru' as const, label: '俄语' },
    { key: 'ja' as const, label: '日语' },
    { key: 'ko' as const, label: '韩语' },
    { key: 'fr' as const, label: '法语' },
    { key: 'es' as const, label: '西班牙语' },
    { key: 'pt' as const, label: '葡萄牙语' },
    { key: 'ar' as const, label: '阿拉伯语' },
    { key: 'th' as const, label: '泰语' },
    { key: 'nl' as const, label: '荷兰语' },
    { key: 'tr' as const, label: '土耳其语' }
] satisfies Array<{ key: SellingPointCardLanguage; label: string }>

const sellingPointCardCoreSellingPointOptions = [
    { key: 'single-copy' as const, label: '单一核心卖点文案展示' },
    { key: 'paired-copy' as const, label: '生成两个核心卖点文案对称展示' },
    { key: 'main-with-side-copy' as const, label: '生成主卖点搭配2-3个辅助点' },
    { key: 'multi-copy' as const, label: '生成多个核心卖点文案展示' }
] satisfies Array<{ key: SellingPointCardCoreSellingPoint; label: string }>

const sellingPointCardLayoutOptions = [
    { key: 'product-center-copy-sides' as const, label: '产品居中展示卖点两侧分布' },
    { key: 'product-scene-copy-scattered' as const, label: '产品场景化展示卖点分散排版' },
    { key: 'product-top-copy-bottom' as const, label: '产品展示在上卖点相关在下' },
    { key: 'product-bottom-copy-top' as const, label: '产品展示在下卖点相关在上' },
    { key: 'left-product-right-copy' as const, label: '左侧展示产品右侧展示卖点' },
    { key: 'left-copy-right-product' as const, label: '左侧展示卖点右侧展示产品' },
    { key: 'product-zoom-copy-around' as const, label: '产品缩略展示卖点环绕展示' }
] satisfies Array<{ key: SellingPointCardLayoutStyle; label: string }>

const sellingPointCardFocusOptions = [
    { key: 'material' as const, label: '材质优势' },
    { key: 'craft' as const, label: '工艺精度' },
    { key: 'function' as const, label: '功能特性' },
    { key: 'performance' as const, label: '性能表现' },
    { key: 'design' as const, label: '设计亮点' }
] satisfies Array<{ key: SellingPointCardFocus; label: string }>

const sellingPointCardMainTitleOptions = [
    { key: 'none' as const, label: '无标题' },
    { key: 'auto' as const, label: '自动生成主标题' }
] satisfies Array<{ key: SellingPointCardTitleMode; label: string }>

const sellingPointCardSubTitleOptions = [
    { key: 'none' as const, label: '无标题' },
    { key: 'auto' as const, label: '自动生成副标题' }
] satisfies Array<{ key: SellingPointCardTitleMode; label: string }>

const sellingPointCardSupportingElementOptions = [
    { key: 'arrow' as const, label: '箭头辅助' },
    { key: 'icon' as const, label: '图标辅助' },
    { key: 'frame' as const, label: '强调框辅助' },
    { key: 'data' as const, label: '数据辅助' },
    { key: 'line' as const, label: '线条辅助' },
    { key: 'color-block' as const, label: '色块辅助' }
] satisfies Array<{ key: SellingPointCardSupportingElement; label: string }>

const sellingPointCardTargetMarketOptions = [
    { key: 'us' as const, label: '美国' },
    { key: 'europe' as const, label: '欧洲' },
    { key: 'china' as const, label: '中国' },
    { key: 'russia' as const, label: '俄罗斯' },
    { key: 'sea' as const, label: '东南亚' },
    { key: 'spain' as const, label: '西班牙' },
    { key: 'germany' as const, label: '德国' },
    { key: 'japan' as const, label: '日本' },
    { key: 'brazil' as const, label: '巴西' },
    { key: 'malaysia' as const, label: '马来西亚' }
] satisfies Array<{ key: ProductSuiteCountry; label: string }>

const sellingPointCardFeatureRules: Array<{ keywords: string[]; title: string; focus: SellingPointCardFocus }> = [
    { keywords: ['防水', '防泼水', 'waterproof'], title: '防水防泼更安心', focus: 'function' },
    { keywords: ['便携', '轻便', 'portable', 'lightweight'], title: '轻便随行更省心', focus: 'design' },
    { keywords: ['大容量', '收纳', 'capacity', 'storage'], title: '大容量收纳更从容', focus: 'performance' },
    { keywords: ['静音', 'silent', 'low noise'], title: '静音运行更舒适', focus: 'performance' },
    { keywords: ['材质', '皮革', '金属', 'fabric', 'leather', 'metal'], title: '质感材质更高级', focus: 'material' },
    { keywords: ['颜值', '外观', 'design', 'look'], title: '高颜值外观更吸睛', focus: 'design' }
]

const sellingPointCardLanguageByMarket: Partial<Record<ProductSuiteCountry, SellingPointCardLanguage>> = {
    us: 'en',
    europe: 'en',
    china: 'zh-hans',
    russia: 'ru',
    sea: 'th',
    spain: 'es',
    germany: 'en',
    japan: 'ja',
    brazil: 'pt',
    malaysia: 'en'
}

const getSellingPointCardSelectOptions = (field: SellingPointCardSelectField) => {
    if (field === 'productType') return sellingPointCardProductTypeOptions
    if (field === 'language') return sellingPointCardLanguageOptions
    if (field === 'coreSellingPoint') return sellingPointCardCoreSellingPointOptions
    if (field === 'layoutStyle') return sellingPointCardLayoutOptions
    if (field === 'focus') return sellingPointCardFocusOptions
    if (field === 'mainTitle') return sellingPointCardMainTitleOptions
    if (field === 'subTitle') return sellingPointCardSubTitleOptions
    if (field === 'supportingElement') return sellingPointCardSupportingElementOptions
    return sellingPointCardTargetMarketOptions
}

const getSellingPointCardSelectLabel = (field: SellingPointCardSelectField, value: string) => (
    getSellingPointCardSelectOptions(field).find((item) => item.key === value)?.label || ''
)

const extractMeaningfulImageBaseName = (name: string) => {
    const baseName = name.replace(/\.[^.]+$/, '').trim()
    const cleaned = baseName
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()

    if (!cleaned) return ''
    if (/^(img|image|photo|dsc|wechatimg)[\s_-]*\d+$/i.test(cleaned)) return ''
    return cleaned
}

const getProductSuiteRatioLabel = (ratio: ProductSuiteRatio) => ({
    '1:1': '1:1',
    '3:4': '3:4',
    '16:9': '16:9',
    '9:16': '9:16'
}[ratio])

const getProductSuiteRatioOptionLabel = (ratio: ProductSuiteRatio) => (
    productSuiteRatioOptions.value.find((item) => item.key === ratio)?.label || getProductSuiteRatioLabel(ratio)
)

const getHotCloneRatioOptionLabel = (ratio: ProductSuiteRatio) => (
    productSuiteRatioOptions.value.find((item) => item.key === ratio)?.label || getProductSuiteRatioLabel(ratio)
)

const getProductImageStyleTabLabel = (tab: ProductImageStyleTab) => ({
    template: '场景模板',
    custom: '自定义场景'
}[tab])

const getProductImageStylePresetLabel = (key: ProductImageStylePresetKey | '') => (
    productImageStylePresets.value.find((item) => item.key === key)?.title || ''
)

const getProductImageSizeLabel = (key: ProductImageSizeKey) => (
    productImageSizeOptions.find((item) => item.key === key)?.label || '自定义'
)

const getProductSuiteModuleLabel = (moduleKey: ProductSuiteModuleKey) => ({
    hero: '首屏主视觉',
    'core-benefit': '核心卖点图',
    'usage-scene': '使用场景图',
    'multi-angle': '多角度图',
    'mood-scene': '场景氛围图',
    detail: '商品细节图',
    compare: '对比图',
    'size-spec': '尺寸规格图',
    'spec-sheet': '详细规格参数表',
    accessories: '配件/赠品图',
    'craft-process': '工艺制作图',
    'series-display': '系列展示图',
    ingredient: '商品成分图',
    'usage-guide': '使用建议图',
    'after-sales': '售后保障图',
    'purchase-guide': '购买引导图'
}[moduleKey])

const getProductMultiAngleViewLabel = (viewKey: ProductMultiAngleViewKey) => ({
    front: '正面',
    side: '侧面',
    back: '背面',
    top: '俯视',
    bottom: '仰视',
    'forty-five': '45度角'
}[viewKey])

const getProductPromoVideoRatioLabel = (ratio: ProductPromoVideoRatio) => ({
    '9:16': '9:16',
    '16:9': '16:9'
}[ratio])

const getProductPromoVideoDurationLabel = (duration: ProductPromoVideoDuration) => ({
    '5': '5秒',
    '10': '10秒',
    '12': '12秒'
}[duration])

const getProductPromoVideoTypeLabel = (type: ProductPromoVideoType) => ({
    product: '产品宣传',
    creative: '创意应用',
    feature: '功能展示',
    unboxing: '开箱体验',
    story: '场景故事'
}[type])

const getAiFittingUploadCategoryLabel = (category: AiFittingUploadCategory) => ({
    tops: '上装',
    bottoms: '下装',
    dress: '连体衣',
    coat: '外套',
    set: '上下装'
}[category])

const getAiFittingModeLabel = (mode: AiFittingMode) => ({
    single: '单图',
    group: '组图',
    custom: '自定义'
}[mode])

const getAiFittingModelFilterLabel = (filter: AiFittingModelFilter) => ({
    all: '全部模特',
    male: '男',
    female: '女',
    child: '儿童'
}[filter])

const getAiFittingClothesFilterLabel = (filter: AiFittingClothesFilter) => ({
    all: '全部服饰',
    sleeveless: '无袖',
    coat: '外套',
    'long-pants': '长裤',
    'long-skirt': '长裙',
    'short-sleeve': '短袖',
    'short-skirt': '短裙',
    'long-sleeve': '长袖',
    'short-pants': '短裤'
}[filter])

const getAiFittingPoseFilterLabel = (filter: AiFittingPoseFilter) => ({
    all: '全部姿势',
    half: '半身',
    full: '全身'
}[filter])

const setAiFittingUploadCategory = (category: AiFittingUploadCategory) => {
    const previousCategory = aiFittingUploadCategory.value
    aiFittingUploadCategory.value = category
    aiFittingUploadCategoryMenuOpen.value = false

    if (category === 'set') {
        if (!aiFittingTopPreview.value && originPreview.value) {
            aiFittingTopPreview.value = originPreview.value
            aiFittingTopName.value = originName.value
            aiFittingTopSource.value = originSource.value
        }
        return
    }

    if (previousCategory === 'set' && !originPreview.value) {
        originPreview.value = aiFittingTopPreview.value || aiFittingBottomPreview.value || ''
        originName.value = aiFittingTopName.value || aiFittingBottomName.value || ''
        originSource.value = aiFittingTopPreview.value ? aiFittingTopSource.value : aiFittingBottomSource.value
    }
}

const getAiFittingSelectionSummary = () => {
    if (aiFittingMode.value === 'custom') {
        return aiFittingCustomModelImages.value.length ? `${aiFittingCustomModelImages.value.length}个自定义模特` : '未选择模特'
    }
    return aiFittingMode.value === 'group'
        ? `${aiFittingSelectedPresetIds.value.length}个姿势`
        : `${aiFittingSelectedPresetIds.value.length}个模特`
}

const shuffleItems = <T,>(items: T[]) => items
    .slice()
    .sort(() => Math.random() - 0.5)

const syncAiFittingSelection = () => {
    if (aiFittingMode.value === 'custom') return

    if (aiFittingMode.value === 'group') {
        const visibleGroupIds = new Set(aiFittingDisplayPresets.value.map((item) => item.groupId))
        if (!visibleGroupIds.has(aiFittingSelectedGroupId.value)) {
            aiFittingSelectedGroupId.value = ''
            aiFittingSelectedPresetIds.value = []
        }
        return
    }

    const visiblePresetIds = new Set(aiFittingDisplayPresets.value.map((item) => item.id))
    aiFittingSelectedPresetIds.value = aiFittingSelectedPresetIds.value.filter((id) => visiblePresetIds.has(id)).slice(0, 1)
    if (!aiFittingSelectedPresetIds.value.length) {
        aiFittingSelectedGroupId.value = ''
    }
}

const pickAiFittingGroupPresets = (groupId: string) => {
    const visibleMatches = aiFittingVisiblePresets.value.filter((item) => item.groupId === groupId)
    const groupMatches = visibleMatches.length
        ? visibleMatches
        : aiFittingPresets.value.filter((item) => item.groupId === groupId)
    const maxCount = Math.min(4, groupMatches.length)
    const minCount = Math.min(2, maxCount)
    const targetCount = maxCount <= minCount
        ? maxCount
        : (minCount + Math.floor(Math.random() * (maxCount - minCount + 1)))

    return shuffleItems(groupMatches).slice(0, targetCount).map((item) => item.id)
}

const setAiFittingMode = (mode: AiFittingMode) => {
    aiFittingMode.value = mode
    if (mode === 'custom') {
        aiFittingModelMenuOpen.value = false
        aiFittingClothesMenuOpen.value = false
        aiFittingPoseMenuOpen.value = false
        return
    }

    if (mode === 'single' && aiFittingSelectedPresetIds.value.length > 1) {
        aiFittingSelectedPresetIds.value = aiFittingSelectedPresetIds.value.slice(0, 1)
    }

    syncAiFittingSelection()
}

const selectAiFittingPreset = (item: AiFittingPreset) => {
    if (aiFittingMode.value === 'group') {
        aiFittingSelectedGroupId.value = item.groupId
        aiFittingSelectedPresetIds.value = pickAiFittingGroupPresets(item.groupId)
        return
    }

    aiFittingSelectedGroupId.value = item.groupId
    aiFittingSelectedPresetIds.value = [item.id]
}

const isAiFittingPresetActive = (item: AiFittingPreset) => (
    aiFittingMode.value === 'group'
        ? aiFittingSelectedGroupId.value === item.groupId && aiFittingSelectedPresetIds.value.length > 0
        : aiFittingSelectedPresetIds.value.includes(item.id)
)

const syncProductImageVisiblePreset = () => {
    const visiblePresets = productImageVisibleStylePresets.value
    if (!visiblePresets.some((item) => item.key === productImageSelectedPresetKey.value)) {
        productImageSelectedPresetKey.value = visiblePresets[0]?.key || 'static-soft-shadow'
    }
}

const setProductImageStyleTab = (tab: ProductImageStyleTab) => {
    productImageStyleTab.value = tab
    if (tab === 'custom') {
        productImageSceneCategoryMenuOpen.value = false
        return
    }

    syncProductImageVisiblePreset()
}

const selectProductImageStylePreset = (key: ProductImageStylePresetKey) => {
    const preset = productImageStylePresets.value.find((item) => item.key === key)
    if (!preset) return
    productImageStyleTab.value = preset.tab
    productImageSelectedPresetKey.value = preset.key
}

const setProductImageSceneCategory = (category: ProductImageSceneCategory) => {
    productImageSceneCategory.value = category
    productImageSceneCategoryMenuOpen.value = false
    if (productImageStyleTab.value !== 'custom') {
        syncProductImageVisiblePreset()
    }
}

const setAiFittingModelFilter = (filter: AiFittingModelFilter) => {
    aiFittingModelFilter.value = filter
    aiFittingModelMenuOpen.value = false
    syncAiFittingSelection()
}

const setAiFittingClothesFilter = (filter: AiFittingClothesFilter) => {
    aiFittingClothesFilter.value = filter
    aiFittingClothesMenuOpen.value = false
    syncAiFittingSelection()
}

const setAiFittingPoseFilter = (filter: AiFittingPoseFilter) => {
    aiFittingPoseFilter.value = filter
    aiFittingPoseMenuOpen.value = false
    syncAiFittingSelection()
}

const setProductImageSize = (key: ProductImageSizeKey) => {
    productImageSizeKey.value = key
    if (key === 'custom') return

    const option = productImageSizeOptions.find((item) => item.key === key)
    if (!option) return
    productImageWidth.value = String(option.width)
    productImageHeight.value = String(option.height)
}

const syncProductImageSizeByDimension = () => {
    const matchedOption = productImageSizeOptions.find((item) => (
        item.key !== 'custom'
        && String(item.width) === productImageWidth.value
        && String(item.height) === productImageHeight.value
    ))

    productImageSizeKey.value = matchedOption?.key || 'custom'
}

const handleProductImageDimensionInput = (field: 'width' | 'height', value: string) => {
    const normalized = value.replace(/[^\d]/g, '').slice(0, 4)
    if (field === 'width') {
        productImageWidth.value = normalized
    } else {
        productImageHeight.value = normalized
    }
    syncProductImageSizeByDimension()
}

const onProductImageDimensionInput = (field: 'width' | 'height', event: Event) => {
    handleProductImageDimensionInput(field, (event.target as HTMLInputElement)?.value || '')
}

const buildProductImagePrompt = () => {
    const presetLabel = productImageStyleTab.value === 'custom'
        ? '自定义场景'
        : (getProductImageStylePresetLabel(productImageSelectedPresetKey.value) || getProductImageStyleTabLabel(productImageStyleTab.value))
    const basePrompt = productImageStyleTab.value === 'custom'
        ? '保持商品主体结构、材质和颜色信息完整，参考自定义上传的场景图片重组背景氛围与布光层次，输出更适合电商展示的商品图。'
        : `保持商品主体结构、材质和颜色信息完整，围绕${presetLabel}场景重组背景与布光，输出更适合电商展示的商品图。`

    return `${basePrompt} 输出尺寸为 ${productImageWidth.value || 0}×${productImageHeight.value || 0}。`
}

const buildAiFittingPrompt = () => {
    const modeLabel = getAiFittingModeLabel(aiFittingMode.value)
    const clothingLabel = isAiFittingSplitGarment.value ? '上装与下装' : getAiFittingUploadCategoryLabel(aiFittingUploadCategory.value)
    const modelLabel = aiFittingMode.value === 'custom'
        ? (aiFittingCustomModelImages.value.length > 1
            ? `${aiFittingCustomModelImages.value.length}张自定义模特图`
            : (aiFittingCustomModelName.value || '自定义模特图'))
        : (
            aiFittingMode.value === 'group'
                ? (aiFittingSelectedPresets.value[0]?.groupLabel || '组图模特')
                : (aiFittingSelectedPresets.value[0]?.groupLabel || aiFittingSelectedPresets.value[0]?.name || '单图模特')
        )
    const poseLabel = getAiFittingPoseFilterLabel(aiFittingPoseFilter.value)
    const selectionLabel = getAiFittingSelectionSummary()

    return [
        `围绕上传的${clothingLabel}生成 ${modeLabel} AI试衣效果，`,
        `匹配${modelLabel}并重点参考${poseLabel}姿势表达，`,
        isAiFittingSplitGarment.value
            ? '需要同时保留上装与下装的版型结构、材质细节、层次关系和画面光线自然一致，'
            : '保持人物体型比例、服装版型结构、材质细节和画面光线自然一致，',
        `输出适合电商展示与穿搭预览的试衣结果，当前选择为${selectionLabel}。`
    ].join('')
}

const buildImageTranslatePrompt = () => {
    const sourceLabel = getImageTranslateLanguageLabel(imageTranslateSourceLanguage.value)
    const targetLabel = getImageTranslateLanguageLabel(imageTranslateTargetLanguage.value)
    const note = imageTranslatePrompt.value.trim()

    return [
        `识别图片中的${sourceLabel === '自动识别' ? '原始' : sourceLabel}文字内容，并翻译为${targetLabel}，`,
        '尽量保留原图的版式结构、信息层级、重点标题和局部标注位置，',
        note ? `补充要求：${note}。` : '输出更自然、准确、适合直接展示的图片翻译结果。'
    ].join('')
}

const buildHairstylePrompt = () => {
    const imageLabel = originName.value || '人物原图'
    const referenceLabel = hairstyleReferenceName.value || '发型参考图'

    return [
        `基于${imageLabel}中的人物主体，参考${referenceLabel}中的发型轮廓、长度、卷度、刘海与发色表现，`,
        '仅调整头发区域，尽量保持人物五官、脸型、肤色、姿态和画面光线自然一致，',
        '输出真实自然、适合预览与内容创作场景的 AI 换发型结果。'
    ].join('')
}

const buildBackgroundRemovalPrompt = () => (
    '识别上传图片中的主体并移除背景区域，尽量保留主体边缘、透明材质、发丝和高光等细节，输出适合后续设计、排版和商品展示的去背景结果。'
)

const buildOneClickCleanupPrompt = () => {
    const optionLabels = oneClickCleanupOptionSummary.value.length
        ? oneClickCleanupOptionSummary.value.join('、')
        : '干扰内容'

    return `针对上传的${oneClickCleanupImages.value.length}张主体图执行一键消除，重点清理${optionLabels}，尽量保留主体结构、材质细节、边缘过渡和画面完整度，输出更干净的结果图。`
}

const normalizeProductSuiteImages = (images: ProductSuiteUploadItem[]) => {
    const nextImages = images.slice(0, maxProductSuiteImages)
    productSuiteImages.value = nextImages
    originPreview.value = nextImages[0]?.preview || ''
    originName.value = nextImages[0]?.name || ''
    originSource.value = nextImages[0]?.source || 'gallery'
}

const appendProductSuiteImages = (images: ProductSuiteUploadItem[]) => {
    if (!images.length) return
    normalizeProductSuiteImages([...productSuiteImages.value, ...images])
}

const normalizeAiFittingCustomModels = (images: AiFittingUploadItem[]) => {
    aiFittingCustomModelImages.value = images.slice(0, maxAiFittingCustomModels)
}

const appendAiFittingCustomModels = (images: AiFittingUploadItem[]) => {
    if (!images.length) return
    normalizeAiFittingCustomModels([...aiFittingCustomModelImages.value, ...images])
}

const removeAiFittingCustomModel = (index: number) => {
    normalizeAiFittingCustomModels(aiFittingCustomModelImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const normalizeFashionLookbookClothesImages = (images: FashionLookbookUploadItem[]) => {
    fashionLookbookClothesImages.value = images.slice(0, maxFashionLookbookClothesImages)
}

const appendFashionLookbookClothesImages = (images: FashionLookbookUploadItem[]) => {
    if (!images.length) return
    normalizeFashionLookbookClothesImages([...fashionLookbookClothesImages.value, ...images])
}

const normalizeHotCloneProductImages = (images: HotCloneUploadItem[]) => {
    hotCloneProductImages.value = images.slice(0, maxHotCloneProductImages)
}

const appendHotCloneProductImages = (images: HotCloneUploadItem[]) => {
    if (!images.length) return
    normalizeHotCloneProductImages([...hotCloneProductImages.value, ...images])
}

const removeHotCloneProductImage = (index: number) => {
    normalizeHotCloneProductImages(hotCloneProductImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const normalizeHotCloneReferenceImages = (images: HotCloneUploadItem[]) => {
    hotCloneReferenceImages.value = images.slice(0, maxHotCloneReferenceImages)
}

const appendHotCloneReferenceImages = (images: HotCloneUploadItem[]) => {
    if (!images.length) return
    normalizeHotCloneReferenceImages([...hotCloneReferenceImages.value, ...images])
}

const normalizeProductMultiAngleImages = (images: ProductMultiAngleUploadItem[]) => {
    const nextImages = images.slice(0, maxProductMultiAngleImages)
    productMultiAngleImages.value = nextImages
    originPreview.value = nextImages[0]?.preview || ''
    originName.value = nextImages[0]?.name || ''
    originSource.value = nextImages[0]?.source || 'gallery'
}

const appendProductMultiAngleImages = (images: ProductMultiAngleUploadItem[]) => {
    if (!images.length) return
    normalizeProductMultiAngleImages([...productMultiAngleImages.value, ...images])
}

const removeHotCloneReferenceImage = (index: number) => {
    normalizeHotCloneReferenceImages(hotCloneReferenceImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const removeFashionLookbookClothesImage = (index: number) => {
    normalizeFashionLookbookClothesImages(
        fashionLookbookClothesImages.value.filter((_, currentIndex) => currentIndex !== index)
    )
}

const removeProductSuiteImage = (index: number) => {
    normalizeProductSuiteImages(productSuiteImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const removeProductMultiAngleImage = (index: number) => {
    normalizeProductMultiAngleImages(productMultiAngleImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const applyShoeTryonExample = (field: 'shoe-tryon-model' | 'shoe-tryon-shoe', item: { image: string; name: string }) => {
    setUploadFieldValue(field, {
        preview: item.image,
        name: item.name,
        source: 'gallery'
    })
}

const applyHairstyleExample = (field: 'origin' | 'hairstyle-reference', item: { image: string; name: string }) => {
    setUploadFieldValue(field, {
        preview: item.image,
        name: item.name,
        source: 'gallery'
    })
}

const applyModelWearExample = (field: 'model-wear-model' | 'model-wear-item', item: { image: string; name: string }) => {
    setUploadFieldValue(field, {
        preview: item.image,
        name: item.name,
        source: 'gallery'
    })
}

const isProductSuiteModuleSelected = (moduleKey: ProductSuiteModuleKey) => (
    productSuiteSelectedModules.value.includes(moduleKey)
)

const getProductSuiteModuleOrder = (moduleKey: ProductSuiteModuleKey) => (
    productSuiteModuleOrderMap.value[moduleKey] || 0
)

const isProductMultiAngleViewSelected = (viewKey: ProductMultiAngleViewKey) => (
    productMultiAngleSelectedViews.value.includes(viewKey)
)

const getProductMultiAngleViewOrder = (viewKey: ProductMultiAngleViewKey) => (
    productMultiAngleViewOrderMap.value[viewKey] || 0
)

const toggleProductSuiteModule = (moduleKey: ProductSuiteModuleKey) => {
    if (isProductSuiteModuleSelected(moduleKey)) {
        productSuiteSelectedModules.value = productSuiteSelectedModules.value.filter((item) => item !== moduleKey)
        return
    }

    productSuiteSelectedModules.value = [...productSuiteSelectedModules.value, moduleKey]
}

const toggleProductMultiAngleView = (viewKey: ProductMultiAngleViewKey) => {
    if (isProductMultiAngleViewSelected(viewKey)) {
        productMultiAngleSelectedViews.value = productMultiAngleSelectedViews.value.filter((item) => item !== viewKey)
        return
    }

    productMultiAngleSelectedViews.value = [...productMultiAngleSelectedViews.value, viewKey]
}

const toggleBuyerShowFieldMenu = (field: BuyerShowPresetField) => {
    buyerShowOpenField.value = buyerShowOpenField.value === field ? '' : field
}

const setBuyerShowPreset = (field: BuyerShowPresetField, value: string) => {
    buyerShowPresetValues.value = {
        ...buyerShowPresetValues.value,
        [field]: value
    }
    buyerShowOpenField.value = ''
}

const fillProductPromoVideoPrompt = () => {
    productPromoVideoPrompt.value = [
        '围绕当前产品图片内容，',
        `输出 ${getProductPromoVideoRatioLabel(productPromoVideoRatio.value)} 比例、${getProductPromoVideoDurationLabel(productPromoVideoDuration.value)} 的${getProductPromoVideoTypeLabel(productPromoVideoType.value)}视频，`,
        '突出产品核心卖点、使用场景、材质细节与镜头节奏感，',
        '整体画面更适合电商传播与转化。'
    ].join('')
}

const fillShoeTryonPrompt = () => {
    shoeTryonPrompt.value = [
        '保持模特下半身姿态、服饰风格与画面光线自然一致，',
        '将上传鞋款真实匹配到人物脚部穿着效果中，',
        '突出鞋型轮廓、鞋面材质和整体穿搭协调感，',
        '输出适合电商展示与种草内容使用的模特试鞋效果图。'
    ].join('')
}

const fillModelWearPrompt = () => {
    modelWearPrompt.value = [
        '保持模特姿态、身形比例与画面光线自然一致，',
        '将上传穿戴元素真实融合到人物穿搭效果中，',
        '突出服饰版型、材质细节和整体搭配协调感，',
        '输出适合电商展示与种草内容使用的模特穿戴效果图。'
    ].join('')
}

const fillBuyerShowPreset = () => {
    const recognitionSource = [originName.value, buyerShowReferenceName.value]
        .filter(Boolean)
        .join(' ')
        .trim()

    if (!recognitionSource) {
        buyerShowOpenField.value = ''
        return
    }

    const nextValues = { ...buyerShowPresetValues.value }

    buyerShowPresetFieldOrder.forEach((field) => {
        const recognizedValue = findBuyerShowRecognitionValue(field, recognitionSource)
        if (recognizedValue) {
            nextValues[field] = recognizedValue
        }
    })

    buyerShowPresetValues.value = nextValues
    buyerShowOpenField.value = ''
}

const toggleSellingPointCardFieldMenu = (field: SellingPointCardSelectField) => {
    sellingPointCardOpenField.value = sellingPointCardOpenField.value === field ? '' : field
}

const setSellingPointCardSelectValue = (field: SellingPointCardSelectField, value: string) => {
    sellingPointCardPresetValues.value = {
        ...sellingPointCardPresetValues.value,
        [field]: value
    }
    sellingPointCardOpenField.value = ''
}

const getSellingPointCardFieldDisplay = (field: SellingPointCardSelectField) => (
    getSellingPointCardSelectLabel(field, sellingPointCardPresetValues.value[field]) || sellingPointCardSelectFieldPlaceholders[field]
)

const getSellingPointCardRatioLabel = (value: SellingPointCardRatio) => (
    sellingPointCardRatioOptions.value.find((item) => item.key === value)?.title || value
)

const buildSellingPointCardPrompt = () => {
    const preset = sellingPointCardPresetValues.value
    const subject = extractMeaningfulImageBaseName(originName.value) || '当前商品'
    const segments = [`围绕${subject}生成更适合电商展示的卖点图，`]

    if (preset.productType) {
        segments.push(`产品类型为${getSellingPointCardSelectLabel('productType', preset.productType)}，`)
    }

    if (preset.coreSellingPoint) {
        segments.push(`核心卖点表达方式为${getSellingPointCardSelectLabel('coreSellingPoint', preset.coreSellingPoint)}，`)
    }

    if (preset.focus) {
        segments.push(`画面重心聚焦${getSellingPointCardSelectLabel('focus', preset.focus)}，`)
    }

    if (preset.layoutStyle) {
        segments.push(`版式采用${getSellingPointCardSelectLabel('layoutStyle', preset.layoutStyle)}，`)
    }

    if (preset.supportingElement) {
        segments.push(`辅以${getSellingPointCardSelectLabel('supportingElement', preset.supportingElement)}强化信息表达，`)
    }

    if (preset.targetMarket) {
        segments.push(`适配${getSellingPointCardSelectLabel('targetMarket', preset.targetMarket)}市场，`)
    }

    if (preset.language) {
        segments.push(`文案语言使用${getSellingPointCardSelectLabel('language', preset.language)}，`)
    }

    if (preset.mainTitle === 'auto') {
        segments.push('主标题自动生成，')
    } else if (preset.mainTitle === 'none') {
        segments.push('不展示主标题，')
    }

    if (preset.subTitle === 'auto') {
        segments.push('副标题自动生成，')
    } else if (preset.subTitle === 'none') {
        segments.push('不展示副标题，')
    }

    if (sellingPointCardReferencePreview.value) {
        segments.push('参考上传参考图的信息组织与表达节奏，')
    }

    if (sellingPointCardPrompt.value.trim()) {
        segments.push(sellingPointCardPrompt.value.trim())
    } else {
        segments.push('整体画面保持卖点清晰、信息层级明确，适合详情页主视觉和推广素材场景。')
    }

    return segments.join('')
}

const fillSellingPointCardPreset = () => {
    const recognitionSource = [originName.value, sellingPointCardReferenceName.value]
        .filter(Boolean)
        .join(' ')
        .trim()

    if (!recognitionSource) {
        sellingPointCardOpenField.value = ''
        return
    }

    const nextValues = { ...sellingPointCardPresetValues.value }
    const normalizedSource = recognitionSource.toLowerCase()

    const productType = findBuyerShowRecognitionValue('productType', recognitionSource) as SellingPointCardProductType | ''
    if (productType) nextValues.productType = productType

    const targetMarket = findBuyerShowRecognitionValue('targetMarket', recognitionSource) as ProductSuiteCountry | ''
    if (targetMarket) {
        nextValues.targetMarket = targetMarket
        if (!nextValues.language && sellingPointCardLanguageByMarket[targetMarket]) {
            nextValues.language = sellingPointCardLanguageByMarket[targetMarket] || ''
        }
    }

    const featureRule = sellingPointCardFeatureRules.find((item) => item.keywords.some((keyword) => normalizedSource.includes(keyword)))
    if (featureRule) {
        nextValues.coreSellingPoint = 'single-copy'
        nextValues.focus = featureRule.focus
    }

    const cleanedName = extractMeaningfulImageBaseName(originName.value || sellingPointCardReferenceName.value)
    if (cleanedName) {
        if (!nextValues.mainTitle) {
            nextValues.mainTitle = 'auto'
        }
        if (!nextValues.subTitle) {
            nextValues.subTitle = 'auto'
        }
        if (!nextValues.coreSellingPoint && cleanedName !== originName.value) {
            nextValues.coreSellingPoint = 'single-copy'
        }
    }

    if (sellingPointCardReferencePreview.value && !nextValues.layoutStyle) {
        nextValues.layoutStyle = 'product-scene-copy-scattered'
    }

    sellingPointCardPresetValues.value = nextValues
    sellingPointCardOpenField.value = ''
}

const fillProductSuiteSellingPoints = () => {
    const moduleLabels = productSuiteSelectedModules.value
        .slice(0, 4)
        .map((item) => getProductSuiteModuleLabel(item))
        .join('、')

    productSuiteSellingPoints.value = [
        `围绕 ${getProductSuitePlatformLabel(productSuitePlatform.value)} ${getProductSuiteCountryLabel(productSuiteCountry.value)} 站点的${getProductSuiteLanguageLabel(productSuiteLanguage.value)}用户，`,
        '突出产品的核心功能、材质质感、使用收益和购买决策理由，',
        `输出适合 ${getProductSuiteRatioLabel(productSuiteRatio.value)} 比例的商品套图策划，`,
        moduleLabels ? `重点覆盖 ${moduleLabels} 等模块。` : '重点覆盖商品转化链路中的关键展示模块。'
    ].join('')
}

const fillHotCloneSellingPoints = () => {
    hotCloneSellingPoints.value = [
        hotCloneProductName.value.trim() ? `围绕 ${hotCloneProductName.value.trim()}，` : '围绕当前商品，',
        `参考上传的 ${hotCloneReferenceImages.value.length || 0} 张爆款参考图的构图、镜头和信息编排方式，`,
        '突出商品核心卖点、差异化优势、使用收益与购买理由，',
        `输出适合 ${getHotCloneLanguageLabel(hotCloneLanguage.value)}语境和 ${getProductSuiteRatioLabel(hotCloneRatio.value)} 比例的复刻视觉结果。`
    ].join('')
}

const clearHotCloneSellingPoints = () => {
    hotCloneSellingPoints.value = ''
}

const clearProductSuiteSellingPoints = () => {
    productSuiteSellingPoints.value = ''
}

const setProductSuitePlatform = (platform: ProductSuitePlatform) => {
    productSuitePlatform.value = platform
    productSuitePlatformMenuOpen.value = false
}

const setProductSuiteCountry = (country: ProductSuiteCountry) => {
    productSuiteCountry.value = country
    productSuiteCountryMenuOpen.value = false
}

const setProductSuiteLanguage = (language: ProductSuiteLanguage) => {
    productSuiteLanguage.value = language
    productSuiteLanguageMenuOpen.value = false
}

const setProductSuiteRatio = (ratio: ProductSuiteRatio) => {
    productSuiteRatio.value = ratio
    productSuiteRatioMenuOpen.value = false
}

const setFashionLookbookRatio = (ratio: FashionLookbookRatio) => {
    fashionLookbookRatio.value = ratio
    fashionLookbookRatioMenuOpen.value = false
}

const setHotCloneLanguage = (language: HotCloneLanguage) => {
    hotCloneLanguage.value = language
    hotCloneLanguageMenuOpen.value = false
}

const setHotCloneRatio = (ratio: ProductSuiteRatio) => {
    hotCloneRatio.value = ratio
    hotCloneRatioMenuOpen.value = false
}

const setImageTranslateSourceLanguage = (language: ImageTranslateLanguage) => {
    imageTranslateSourceLanguage.value = language
    imageTranslateSourceMenuOpen.value = false
}

const setImageTranslateTargetLanguage = (language: Exclude<ImageTranslateLanguage, 'auto'>) => {
    imageTranslateTargetLanguage.value = language
    imageTranslateTargetMenuOpen.value = false
}

const normalizeOneClickCleanupImages = (images: OneClickCleanupUploadItem[]) => {
    const nextImages = images.slice(0, maxOneClickCleanupImages)
    oneClickCleanupImages.value = nextImages
    originPreview.value = nextImages[0]?.preview || ''
    originName.value = nextImages[0]?.name || ''
    originSource.value = nextImages[0]?.source || 'gallery'
}

const appendOneClickCleanupImages = (images: OneClickCleanupUploadItem[]) => {
    if (!images.length) return
    normalizeOneClickCleanupImages([...oneClickCleanupImages.value, ...images])
}

const removeOneClickCleanupImage = (index: number) => {
    if (index < 0 || index >= oneClickCleanupImages.value.length) return
    normalizeOneClickCleanupImages(oneClickCleanupImages.value.filter((_, currentIndex) => currentIndex !== index))
}

const toggleOneClickCleanupOption = (key: OneClickCleanupOptionKey) => {
    oneClickCleanupSelectedOptions.value = oneClickCleanupSelectedOptions.value.includes(key)
        ? oneClickCleanupSelectedOptions.value.filter((item) => item !== key)
        : [...oneClickCleanupSelectedOptions.value, key]
}

const fillImagePrompt = () => {
    lineDrawingPrompt.value = '保留主体轮廓与关键结构，弱化复杂背景，输出更干净清晰的线稿效果。'
}

const openPromptWorkbench = () => {
    lineDrawingPrompt.value = '突出人物五官、服装褶皱与外轮廓线条，保持结构准确，生成适合后续上色的细致线稿。'
}

const clearLineDrawingPrompt = () => {
    lineDrawingPrompt.value = ''
}

const triggerUpload = (field: UploadField = 'origin') => {
    activeUploadField.value = field
    fileInputRef.value?.click()
}

const handleUpload = (event: Event) => {
    const input = event.target as HTMLInputElement
    const files = Array.from(input.files || [])

    if (!files.length) return

    if (isProductSuiteTool.value && activeUploadField.value === 'origin') {
        const nextImages = files
            .slice(0, Math.max(0, maxProductSuiteImages - productSuiteImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendProductSuiteImages(nextImages)
        input.value = ''
        return
    }

    if (isFashionLookbookTool.value && activeUploadField.value === 'fashion-clothes') {
        const nextImages = files
            .slice(0, Math.max(0, maxFashionLookbookClothesImages - fashionLookbookClothesImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendFashionLookbookClothesImages(nextImages)
        input.value = ''
        return
    }

    if (isHotCloneTool.value && activeUploadField.value === 'hot-clone-product') {
        const nextImages = files
            .slice(0, Math.max(0, maxHotCloneProductImages - hotCloneProductImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendHotCloneProductImages(nextImages)
        input.value = ''
        return
    }

    if (isHotCloneTool.value && activeUploadField.value === 'hot-clone-reference') {
        const nextImages = files
            .slice(0, Math.max(0, maxHotCloneReferenceImages - hotCloneReferenceImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendHotCloneReferenceImages(nextImages)
        input.value = ''
        return
    }

    if (isProductMultiAngleTool.value && activeUploadField.value === 'product-multi-angle') {
        const nextImages = files
            .slice(0, Math.max(0, maxProductMultiAngleImages - productMultiAngleImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendProductMultiAngleImages(nextImages)
        input.value = ''
        return
    }

    if (isOneClickCleanupTool.value && activeUploadField.value === 'cleanup-images') {
        const nextImages = files
            .slice(0, Math.max(0, maxOneClickCleanupImages - oneClickCleanupImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendOneClickCleanupImages(nextImages)
        input.value = ''
        return
    }

    if (isShoeTryonTool.value && ['shoe-tryon-model', 'shoe-tryon-shoe'].includes(activeUploadField.value)) {
        const file = files[0]
        const objectUrl = URL.createObjectURL(file)
        uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
        setUploadFieldValue(activeUploadField.value, {
            preview: objectUrl,
            name: file.name,
            source: 'local'
        })
        input.value = ''
        return
    }

    if (isModelWearTool.value && ['model-wear-model', 'model-wear-item'].includes(activeUploadField.value)) {
        const file = files[0]
        const objectUrl = URL.createObjectURL(file)
        uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
        setUploadFieldValue(activeUploadField.value, {
            preview: objectUrl,
            name: file.name,
            source: 'local'
        })
        input.value = ''
        return
    }

    if (isBuyerShowTool.value && activeUploadField.value === 'buyer-show-reference') {
        const file = files[0]
        const objectUrl = URL.createObjectURL(file)
        uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
        setUploadFieldValue('buyer-show-reference', {
            preview: objectUrl,
            name: file.name,
            source: 'local'
        })
        input.value = ''
        return
    }

    if (isSellingPointCardTool.value && activeUploadField.value === 'selling-point-reference') {
        const file = files[0]
        const objectUrl = URL.createObjectURL(file)
        uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
        setUploadFieldValue('selling-point-reference', {
            preview: objectUrl,
            name: file.name,
            source: 'local'
        })
        input.value = ''
        return
    }

    if (isAiFittingTool.value && activeUploadField.value === 'ai-fitting-model' && aiFittingMode.value === 'custom') {
        const nextImages = files
            .slice(0, Math.max(0, maxAiFittingCustomModels - aiFittingCustomModelImages.value.length))
            .map((file) => {
                const objectUrl = URL.createObjectURL(file)
                uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
                return {
                    preview: objectUrl,
                    name: file.name,
                    source: 'local' as const
                }
            })

        appendAiFittingCustomModels(nextImages)
        input.value = ''
        return
    }

    const file = files[0]
    const objectUrl = URL.createObjectURL(file)
    uploadBlobUrls.value = [...uploadBlobUrls.value, objectUrl]
    setUploadFieldValue(activeUploadField.value, {
        preview: objectUrl,
        name: file.name,
        source: 'local'
    })
    input.value = ''
}

const submitPhotoRestore = async () => {
    isCreating.value = true
    try {
        let imageUrl = originPreview.value
        if (originSource.value === 'local' && originPreview.value.startsWith('blob:')) {
            const blob = await fetch(originPreview.value).then(r => r.blob())
            const file = new File([blob], originName.value || 'photo.png', { type: blob.type })
            const uploadRes = await ($request as any).uploadFile({ url: '/upload/image' }, { file, name: 'file' })
            imageUrl = uploadRes?.url || uploadRes?.uri || originPreview.value
        }

        const createRes = await ($request as any).post({
            url: '/aigc.ai/create',
            params: {
                app_id: 23,
                urls: [imageUrl],
                repairMode: photoRestoreMode.value,
                modelCode: photoRestoreModel.value,
                channel: restoreModels.value.find(m => m.code === photoRestoreModel.value)?.channel || 'xAIYI'
            }
        })

        const jobId = createRes?.id || createRes?.job_id
        if (!jobId) {
            isCreating.value = false
            return
        }

        let finalResult: any = createRes
        const maxPolls = 60
        for (let i = 0; i < maxPolls; i++) {
            await new Promise(r => setTimeout(r, 3000))
            const detail = await ($request as any).post({ url: '/aigc.ai/syncJob', params: { job_id: jobId } })
            if (detail?.status === 2) {
                finalResult = detail
                break
            }
            if (detail?.status === 3) {
                isCreating.value = false
                return
            }
        }

        const resultUrls: string[] = []
        const payload = finalResult?.result_payload
        if (typeof payload === 'string') {
            try {
                const parsed = JSON.parse(payload)
                if (Array.isArray(parsed)) parsed.forEach((item: any) => item?.url && resultUrls.push(item.url))
                else if (parsed?.results) parsed.results.forEach((item: any) => item?.url && resultUrls.push(item.url))
            } catch { /* ignore */ }
        } else if (Array.isArray(payload)) {
            payload.forEach((item: any) => item?.url && resultUrls.push(item.url))
        } else if (payload?.results) {
            payload.results.forEach((item: any) => item?.url && resultUrls.push(item.url))
        }

        const resultPreview = resultUrls[0] || originPreview.value
        const result: ToolResultItem = {
            id: `photo-restore-${jobId}-${Date.now()}`,
            kind: 'tool',
            title: toolPageTitle.value,
            createdAt: buildToolTimestamp(),
            meta: [
                getPhotoRestoreModeLabel(photoRestoreMode.value),
                getPhotoRestoreModelLabel(photoRestoreModel.value)
            ],
            preview: resultPreview,
            prompt: `${getPhotoRestoreModeLabel(photoRestoreMode.value)} - ${getPhotoRestoreModelLabel(photoRestoreModel.value)}`,
            promptThumbs: [originPreview.value],
            settings: {
                repairMode: photoRestoreMode.value,
                modelVariant: photoRestoreModel.value,
                modelCode: photoRestoreModel.value
            }
        }
        prependResult(result)
        activeResultId.value = result.id
    } catch (e) {
        console.error('Photo restore submit failed:', e)
    } finally {
        isCreating.value = false
    }
}

const handleCreate = () => {
    if (isCreateDisabled.value) return
    if (!ensurePcLogin({ redirect: route.fullPath })) return

    if (isPhotoRestoreTool.value) {
        submitPhotoRestore()
        return
    }

    isCreating.value = true
    if (createTimer.value) clearTimeout(createTimer.value)

    createTimer.value = setTimeout(() => {
        const generateKind = currentTool.value.generateKind ?? 'tool'
        const compositePreview = isLocalRedrawTool.value ? buildMaskCompositePreview() : ''
        const fashionLookbookPreview = fashionLookbookModelPreview.value || fashionLookbookClothesImages.value[0]?.preview || ''
        const hotClonePreview = hotCloneProductImages.value[0]?.preview || hotCloneReferenceImages.value[0]?.preview || ''
        const productMultiAnglePreview = productMultiAngleImages.value[0]?.preview || ''
        const oneClickCleanupPreview = oneClickCleanupImages.value[0]?.preview || ''
        const shoeTryonPreview = shoeTryonModelPreview.value || shoeTryonShoePreview.value || ''
        const modelWearPreview = modelWearModelPreview.value || modelWearItemPreview.value || ''
        const aiFittingPreview = aiFittingMode.value === 'custom'
            ? aiFittingCustomModelPreview.value
            : (aiFittingSelectedPresets.value[0]?.image || '')
        const buyerShowPreview = originPreview.value || buyerShowReferencePreview.value || ''
        const sellingPointCardPreview = originPreview.value || sellingPointCardReferencePreview.value || ''
        const sellingPointCardPromptText = buildSellingPointCardPrompt()
        const hairstylePromptText = buildHairstylePrompt()
        const imageTranslatePromptText = buildImageTranslatePrompt()
        const backgroundRemovalPromptText = buildBackgroundRemovalPrompt()
        const oneClickCleanupPromptText = buildOneClickCleanupPrompt()
        const preview = compositePreview || fashionLookbookPreview || hotClonePreview || productMultiAnglePreview || oneClickCleanupPreview || shoeTryonPreview || modelWearPreview || aiFittingPreview || buyerShowPreview || sellingPointCardPreview || originPreview.value || maskPreview.value
        const promptThumbs = isLocalRedrawTool.value
            ? [originPreview.value, preview].filter(Boolean)
            : isProductImageTool.value
                ? [
                    originPreview.value,
                    productImageReferencePreview.value
                ].filter(Boolean)
            : isImageTranslateTool.value
                ? [originPreview.value].filter(Boolean)
            : isHairstyleTool.value
                ? [originPreview.value, hairstyleReferencePreview.value].filter(Boolean)
            : isOneClickCleanupTool.value
                ? oneClickCleanupImages.value.map((item) => item.preview).filter(Boolean)
            : isFashionLookbookTool.value
                ? [
                    fashionLookbookModelPreview.value,
                    ...fashionLookbookClothesImages.value.map((item) => item.preview)
                ].filter(Boolean)
            : isHotCloneTool.value
                ? [
                    ...hotCloneProductImages.value.map((item) => item.preview),
                    ...hotCloneReferenceImages.value.map((item) => item.preview)
                ].filter(Boolean)
            : isProductPromoVideoTool.value
                ? [originPreview.value].filter(Boolean)
            : isProductMultiAngleTool.value
                ? productMultiAngleImages.value.map((item) => item.preview).filter(Boolean)
            : isShoeTryonTool.value
                ? [shoeTryonModelPreview.value, shoeTryonShoePreview.value].filter(Boolean)
            : isModelWearTool.value
                ? [modelWearModelPreview.value, modelWearItemPreview.value].filter(Boolean)
            : isAiFittingTool.value
                ? [
                    ...aiFittingGarmentThumbs.value,
                    ...(aiFittingMode.value === 'custom'
                        ? aiFittingCustomModelImages.value.map((item) => item.preview)
                        : aiFittingSelectedPresets.value.map((item) => item.image))
                ].filter(Boolean)
            : isBuyerShowTool.value
                ? [originPreview.value, buyerShowReferencePreview.value].filter(Boolean)
            : isSellingPointCardTool.value
                ? [originPreview.value, sellingPointCardReferencePreview.value].filter(Boolean)
            : isProductSuiteTool.value
                ? productSuiteImages.value.map((item) => item.preview).filter(Boolean)
            : undefined
        const outpaintMeta = [
            `左 ${outpaintValues.value.left}`,
            `上 ${outpaintValues.value.top}`,
            `右 ${outpaintValues.value.right}`,
            `下 ${outpaintValues.value.bottom}`
        ]
        const photoRestoreMeta = [
            getPhotoRestoreModeLabel(photoRestoreMode.value),
            getPhotoRestoreModelLabel(photoRestoreModel.value)
        ]
        const lineDrawingMeta = [
            getLineDrawingModelLabel(lineDrawingModel.value),
            '线稿生成'
        ]
        const imageTranslateMeta = [
            getImageTranslateLanguageLabel(imageTranslateSourceLanguage.value),
            getImageTranslateLanguageLabel(imageTranslateTargetLanguage.value),
            '图片翻译'
        ]
        const hairstyleMeta = ['人物原图', '参考发型', 'AI换发型']
        const backgroundRemovalMeta = ['透明背景', '主体保留', '图片去背景']
        const oneClickCleanupMeta = [
            `${oneClickCleanupImages.value.length}张主体图`,
            `${oneClickCleanupSelectedOptions.value.length}项内容`,
            oneClickCleanupOptionSummary.value.join('、') || '智能清理'
        ]
        const styleTransferMeta = [
            getStyleTransferLabel(styleTransferStyle.value),
            '风格转换'
        ]
        const productImageMeta = [
            getProductImageStyleTabLabel(productImageStyleTab.value),
            productImageStyleTab.value === 'custom'
                ? '自定义场景'
                : (getProductImageStylePresetLabel(productImageSelectedPresetKey.value) || '默认风格'),
            productImageSizeDisplayLabel.value
        ]
        const fashionLookbookMeta = [
            getFashionLookbookRatioLabel(fashionLookbookRatio.value),
            `${fashionLookbookClothesImages.value.length}张服饰图`,
            fashionLookbookModelPreview.value ? '含模特图' : '仅服饰图'
        ]
        const hotCloneMeta = [
            getProductSuiteRatioLabel(hotCloneRatio.value),
            getHotCloneLanguageLabel(hotCloneLanguage.value),
            `${hotCloneProductImages.value.length}张商品图`,
            `${hotCloneReferenceImages.value.length}张参考图`
        ]
        const productPromoVideoMeta = [
            getProductPromoVideoRatioLabel(productPromoVideoRatio.value),
            getProductPromoVideoDurationLabel(productPromoVideoDuration.value),
            getProductPromoVideoTypeLabel(productPromoVideoType.value)
        ]
        const productMultiAngleMeta = [
            `${productMultiAngleSelectedViews.value.length}个视角`,
            `${productMultiAngleImages.value.length}张原图`,
            productMultiAngleSelectedViews.value.map((item) => getProductMultiAngleViewLabel(item)).join('、')
        ]
        const shoeTryonMeta = ['模特图', '鞋图', '试穿效果']
        const modelWearMeta = ['模特图', '穿戴图', '穿戴效果']
        const aiFittingMeta = [
            getAiFittingModeLabel(aiFittingMode.value),
            getAiFittingUploadCategoryLabel(aiFittingUploadCategory.value),
            getAiFittingSelectionSummary()
        ]
        const buyerShowMeta = [
            getBuyerShowPresetLabel('targetMarket', buyerShowPresetValues.value.targetMarket) || '未指定市场',
            buyerShowReferencePreview.value ? '含参考图' : '无参考图',
            '买家秀'
        ]
        const sellingPointCardMeta = [
            getSellingPointCardRatioLabel(sellingPointCardRatio.value),
            getSellingPointCardSelectLabel('targetMarket', sellingPointCardPresetValues.value.targetMarket) || '未指定市场'
        ]
        const productSuiteMeta = [
            getProductSuitePlatformLabel(productSuitePlatform.value),
            getProductSuiteCountryLabel(productSuiteCountry.value),
            getProductSuiteRatioLabel(productSuiteRatio.value),
            `${productSuiteSelectedModules.value.length}个模块`
        ]
        const result: ToolResultItem = {
            id: `${currentTool.value.id}-generated-${Date.now()}`,
            kind: generateKind,
            title: generateKind === 'tool' ? toolPageTitle.value : currentTool.value.detailName,
            createdAt: buildToolTimestamp(),
            meta: isFashionLookbookTool.value
                ? fashionLookbookMeta
                : isProductImageTool.value
                ? productImageMeta
                : isHotCloneTool.value
                ? hotCloneMeta
                : isProductPromoVideoTool.value
                ? productPromoVideoMeta
            : isImageTranslateTool.value
                ? imageTranslateMeta
            : isHairstyleTool.value
                ? hairstyleMeta
            : isBackgroundRemovalTool.value
                ? backgroundRemovalMeta
            : isOneClickCleanupTool.value
                ? oneClickCleanupMeta
            : isProductMultiAngleTool.value
                ? productMultiAngleMeta
            : isShoeTryonTool.value
                ? shoeTryonMeta
            : isModelWearTool.value
                ? modelWearMeta
            : isAiFittingTool.value
                ? aiFittingMeta
            : isBuyerShowTool.value
                ? buyerShowMeta
            : isSellingPointCardTool.value
                ? sellingPointCardMeta
            : isProductSuiteTool.value
                ? productSuiteMeta
                : generateKind === 'tool'
                ? [getToolResolutionOutput(activeResolution.value)]
                : isOutpaintTool.value
                    ? outpaintMeta
                : isPhotoRestoreTool.value
                    ? photoRestoreMeta
                : isLineDrawingTool.value
                    ? lineDrawingMeta
                : isStyleTransferTool.value
                    ? styleTransferMeta
                : isLocalRedrawTool.value
                    ? [
                        getToolResolutionOutput(activeResolution.value),
                        originSource.value === 'local' ? '原图-本地上传' : '原图-图库上传',
                        '蒙版-手绘'
                    ]
                    : [getToolResolutionOutput(activeResolution.value), '图片生成'],
            preview,
            previews: generateKind === 'image' ? [preview] : undefined,
            prompt: isLocalRedrawTool.value
                ? localRedrawPrompt.value.trim()
                : isProductImageTool.value
                    ? buildProductImagePrompt()
                : isHairstyleTool.value
                    ? hairstylePromptText
                : isImageTranslateTool.value
                    ? imageTranslatePromptText
                : isBackgroundRemovalTool.value
                    ? backgroundRemovalPromptText
                : isOneClickCleanupTool.value
                    ? oneClickCleanupPromptText
                : isFashionLookbookTool.value
                    ? fashionLookbookPrompt.value.trim()
                : isHotCloneTool.value
                    ? hotCloneSellingPoints.value.trim()
                : isProductPromoVideoTool.value
                    ? productPromoVideoPrompt.value.trim()
                : isProductMultiAngleTool.value
                    ? productMultiAnglePrompt.value.trim()
                : isShoeTryonTool.value
                    ? shoeTryonPrompt.value.trim()
                : isModelWearTool.value
                    ? modelWearPrompt.value.trim()
                : isAiFittingTool.value
                    ? buildAiFittingPrompt()
                : isBuyerShowTool.value
                    ? buyerShowPrompt.value.trim()
                : isSellingPointCardTool.value
                    ? sellingPointCardPromptText
                : isProductSuiteTool.value
                    ? productSuiteSellingPoints.value.trim()
                : isLineDrawingTool.value
                    ? lineDrawingPrompt.value.trim()
                    : undefined,
            promptThumbs: promptThumbs?.length ? promptThumbs : undefined,
            maskOverlay: isLocalRedrawTool.value ? maskPreview.value : undefined,
            settings: isOutpaintTool.value
                ? { ...outpaintValues.value }
                : isPhotoRestoreTool.value
                    ? {
                        repairMode: photoRestoreMode.value,
                        modelVariant: photoRestoreModel.value,
                        modelCode: photoRestoreModel.value
                    }
                    : isLineDrawingTool.value
                        ? {
                            modelVariant: lineDrawingModel.value,
                            promptText: lineDrawingPrompt.value.trim()
                        }
                    : isHairstyleTool.value
                        ? {
                            imagePreview: originPreview.value,
                            imageName: originName.value,
                            imageSource: originSource.value,
                            referencePreview: hairstyleReferencePreview.value,
                            referenceName: hairstyleReferenceName.value,
                            referenceSource: hairstyleReferenceSource.value
                        }
                    : isImageTranslateTool.value
                        ? {
                            imagePreview: originPreview.value,
                            imageName: originName.value,
                            imageSource: originSource.value,
                            sourceLanguage: imageTranslateSourceLanguage.value,
                            targetLanguage: imageTranslateTargetLanguage.value,
                            promptText: imageTranslatePrompt.value.trim()
                        }
                    : isBackgroundRemovalTool.value
                        ? {
                            imagePreview: originPreview.value,
                            imageName: originName.value,
                            imageSource: originSource.value
                        }
                    : isOneClickCleanupTool.value
                        ? {
                            imagePreviews: oneClickCleanupImages.value.map((item) => item.preview),
                            imageNames: oneClickCleanupImages.value.map((item) => item.name),
                            imageSources: oneClickCleanupImages.value.map((item) => item.source),
                            optionKeys: oneClickCleanupSelectedOptions.value
                        }
                    : isProductImageTool.value
                        ? {
                            productPreview: originPreview.value,
                            productName: originName.value,
                            productSource: originSource.value,
                            styleTab: productImageStyleTab.value,
                            sceneCategory: productImageSceneCategory.value,
                            presetKey: productImageSelectedPresetKey.value,
                            presetTitle: getProductImageStylePresetLabel(productImageSelectedPresetKey.value),
                            customStylePreview: productImageCustomStylePreview.value,
                            customStyleName: productImageCustomStyleName.value,
                            customStyleSource: productImageCustomStyleSource.value,
                            sizeKey: productImageSizeKey.value,
                            sizeWidth: productImageWidth.value,
                            sizeHeight: productImageHeight.value
                        }
                    : isFashionLookbookTool.value
                        ? {
                            ratioKey: fashionLookbookRatio.value,
                            modelPreview: fashionLookbookModelPreview.value,
                            modelName: fashionLookbookModelName.value,
                            modelSource: fashionLookbookModelSource.value,
                            clothesPreviews: fashionLookbookClothesImages.value.map((item) => item.preview),
                            clothesNames: fashionLookbookClothesImages.value.map((item) => item.name),
                            promptText: fashionLookbookPrompt.value.trim()
                        }
                    : isHotCloneTool.value
                        ? {
                            ratioKey: hotCloneRatio.value,
                            languageKey: hotCloneLanguage.value,
                            productNameText: hotCloneProductName.value.trim(),
                            productPreviews: hotCloneProductImages.value.map((item) => item.preview),
                            productNames: hotCloneProductImages.value.map((item) => item.name),
                            referencePreviews: hotCloneReferenceImages.value.map((item) => item.preview),
                            referenceNames: hotCloneReferenceImages.value.map((item) => item.name),
                            promptText: hotCloneSellingPoints.value.trim()
                        }
                    : isProductPromoVideoTool.value
                        ? {
                            ratioKey: productPromoVideoRatio.value,
                            durationKey: productPromoVideoDuration.value,
                            typeKey: productPromoVideoType.value,
                            promptText: productPromoVideoPrompt.value.trim()
                        }
                    : isProductMultiAngleTool.value
                        ? {
                            viewKeys: productMultiAngleSelectedViews.value.join('|'),
                            imagePreviews: productMultiAngleImages.value.map((item) => item.preview),
                            imageNames: productMultiAngleImages.value.map((item) => item.name),
                            promptText: productMultiAnglePrompt.value.trim()
                        }
                    : isShoeTryonTool.value
                        ? {
                            modelPreview: shoeTryonModelPreview.value,
                            modelName: shoeTryonModelName.value,
                            modelSource: shoeTryonModelSource.value,
                            shoePreview: shoeTryonShoePreview.value,
                            shoeName: shoeTryonShoeName.value,
                            shoeSource: shoeTryonShoeSource.value,
                            promptText: shoeTryonPrompt.value.trim()
                        }
                    : isModelWearTool.value
                        ? {
                            modelPreview: modelWearModelPreview.value,
                            modelName: modelWearModelName.value,
                            modelSource: modelWearModelSource.value,
                            wearPreview: modelWearItemPreview.value,
                            wearName: modelWearItemName.value,
                            wearSource: modelWearItemSource.value,
                            promptText: modelWearPrompt.value.trim()
                        }
                    : isAiFittingTool.value
                        ? {
                            clothingPreview: aiFittingPrimaryGarmentPreview.value,
                            clothingName: aiFittingPrimaryGarmentName.value,
                            clothingSource: isAiFittingSplitGarment.value
                                ? (aiFittingTopPreview.value ? aiFittingTopSource.value : aiFittingBottomSource.value)
                                : originSource.value,
                            clothingCategory: aiFittingUploadCategory.value,
                            topPreview: aiFittingTopPreview.value,
                            topName: aiFittingTopName.value,
                            topSource: aiFittingTopSource.value,
                            bottomPreview: aiFittingBottomPreview.value,
                            bottomName: aiFittingBottomName.value,
                            bottomSource: aiFittingBottomSource.value,
                            modeKey: aiFittingMode.value,
                            modelFilter: aiFittingModelFilter.value,
                            clothesFilter: aiFittingClothesFilter.value,
                            poseFilter: aiFittingPoseFilter.value,
                            selectedPresetIds: aiFittingSelectedPresetIds.value,
                            selectedGroupId: aiFittingSelectedGroupId.value,
                            customModelPreview: aiFittingCustomModelPreview.value,
                            customModelName: aiFittingCustomModelName.value,
                            customModelSource: aiFittingCustomModelSource.value,
                            customModelPreviews: aiFittingCustomModelImages.value.map((item) => item.preview),
                            customModelNames: aiFittingCustomModelImages.value.map((item) => item.name),
                            customModelSources: aiFittingCustomModelImages.value.map((item) => item.source),
                            promptText: buildAiFittingPrompt()
                        }
                    : isBuyerShowTool.value
                        ? {
                            productPreview: originPreview.value,
                            productName: originName.value,
                            productSource: originSource.value,
                            referencePreview: buyerShowReferencePreview.value,
                            referenceName: buyerShowReferenceName.value,
                            referenceSource: buyerShowReferenceSource.value,
                            productType: buyerShowPresetValues.value.productType,
                            productStatus: buyerShowPresetValues.value.productStatus,
                            presentationMode: buyerShowPresetValues.value.presentationMode,
                            sceneMood: buyerShowPresetValues.value.sceneMood,
                            productRealism: buyerShowPresetValues.value.productRealism,
                            environmentRealism: buyerShowPresetValues.value.environmentRealism,
                            shootRealism: buyerShowPresetValues.value.shootRealism,
                            targetMarket: buyerShowPresetValues.value.targetMarket,
                            promptText: buyerShowPrompt.value.trim()
                        }
                    : isSellingPointCardTool.value
                        ? {
                            productPreview: originPreview.value,
                            productName: originName.value,
                            productSource: originSource.value,
                            referencePreview: sellingPointCardReferencePreview.value,
                            referenceName: sellingPointCardReferenceName.value,
                            referenceSource: sellingPointCardReferenceSource.value,
                            ratioKey: sellingPointCardRatio.value,
                            productType: sellingPointCardPresetValues.value.productType,
                            language: sellingPointCardPresetValues.value.language,
                            coreSellingPoint: sellingPointCardPresetValues.value.coreSellingPoint,
                            layoutStyle: sellingPointCardPresetValues.value.layoutStyle,
                            focus: sellingPointCardPresetValues.value.focus,
                            mainTitle: sellingPointCardPresetValues.value.mainTitle,
                            subTitle: sellingPointCardPresetValues.value.subTitle,
                            supportingElement: sellingPointCardPresetValues.value.supportingElement,
                            targetMarket: sellingPointCardPresetValues.value.targetMarket,
                            promptText: sellingPointCardPrompt.value.trim()
                        }
                    : isProductSuiteTool.value
                        ? {
                            platformKey: productSuitePlatform.value,
                            countryKey: productSuiteCountry.value,
                            languageKey: productSuiteLanguage.value,
                            ratioKey: productSuiteRatio.value,
                            moduleKeys: productSuiteSelectedModules.value.join('|')
                        }
                    : isStyleTransferTool.value
                        ? {
                            styleKey: styleTransferStyle.value
                        }
                    : undefined
        }

        prependResult(result)
        activeResultId.value = result.id
        isCreating.value = false
        createTimer.value = null
    }, 900)
}

const focusResult = (item: ToolResultItem) => {
    activeResultId.value = item.id
}

const handleEdit = (item: ToolResultItem) => {
    activeResultId.value = item.id

    if (isLocalRedrawTool.value) {
        const [originThumb] = item.promptThumbs ?? []
        originPreview.value = originThumb || getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-原图.png`
        originSource.value = 'gallery'
        maskPreview.value = item.maskOverlay || ''
        maskName.value = item.maskOverlay ? `${item.title}-蒙版.png` : ''
        maskSource.value = 'gallery'
        hasMaskStroke.value = !!item.maskOverlay
        resetMaskHistory(item.maskOverlay || '')
        localRedrawPrompt.value = item.prompt ?? ''
        nextTick(() => syncMaskCanvasSize())
        return
    }

    if (isOutpaintTool.value) {
        originPreview.value = getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-原图.png`
        originSource.value = 'gallery'
        outpaintValues.value = {
            left: Number(item.settings?.left ?? 200),
            top: Number(item.settings?.top ?? 200),
            right: Number(item.settings?.right ?? 200),
            bottom: Number(item.settings?.bottom ?? 200)
        }
        return
    }

    if (isPhotoRestoreTool.value) {
        originPreview.value = getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-原图.png`
        originSource.value = 'gallery'
        photoRestoreMode.value = (item.settings?.repairMode as PhotoRestoreMode) || 'repair'
        photoRestoreModel.value = (item.settings?.modelCode as string) || (item.settings?.modelVariant as string) || (restoreModels.value[0]?.code ?? 'nano')
        return
    }

    if (isLineDrawingTool.value) {
        originPreview.value = getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-原图.png`
        originSource.value = 'gallery'
        lineDrawingModel.value = (item.settings?.modelVariant as PhotoRestoreModel) || 'nano'
        lineDrawingPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isHairstyleTool.value) {
        originPreview.value = String(item.settings?.imagePreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.imageName || (originPreview.value ? `${item.title}-人物原图.png` : ''))
        originSource.value = (item.settings?.imageSource as UploadSource) || 'gallery'
        hairstyleReferencePreview.value = String(item.settings?.referencePreview || item.promptThumbs?.[1] || '')
        hairstyleReferenceName.value = String(item.settings?.referenceName || (hairstyleReferencePreview.value ? `${item.title}-发型参考图.png` : ''))
        hairstyleReferenceSource.value = (item.settings?.referenceSource as UploadSource) || 'gallery'
        return
    }

    if (isImageTranslateTool.value) {
        originPreview.value = String(item.settings?.imagePreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.imageName || (originPreview.value ? `${item.title}-原图.png` : ''))
        originSource.value = (item.settings?.imageSource as UploadSource) || 'gallery'
        imageTranslateSourceLanguage.value = (item.settings?.sourceLanguage as ImageTranslateLanguage) || 'auto'
        imageTranslateTargetLanguage.value = (item.settings?.targetLanguage as Exclude<ImageTranslateLanguage, 'auto'>) || 'en'
        imageTranslatePrompt.value = String(item.settings?.promptText || '')
        return
    }

    if (isBackgroundRemovalTool.value) {
        originPreview.value = String(item.settings?.imagePreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.imageName || (originPreview.value ? `${item.title}-原图.png` : ''))
        originSource.value = (item.settings?.imageSource as UploadSource) || 'gallery'
        return
    }

    if (isOneClickCleanupTool.value) {
        const imagePreviewSetting = item.settings?.imagePreviews
        const imageNameSetting = item.settings?.imageNames
        const imageSourceSetting = item.settings?.imageSources
        const previews = Array.isArray(imagePreviewSetting)
            ? imagePreviewSetting
            : item.promptThumbs?.length ? item.promptThumbs : [getPrimaryToolResultPreview(item)]
        const names = Array.isArray(imageNameSetting) ? imageNameSetting : []
        const sources = Array.isArray(imageSourceSetting) ? imageSourceSetting : []

        normalizeOneClickCleanupImages(previews
            .filter(Boolean)
            .map((preview, index) => ({
                preview,
                name: String(names[index] || `${item.title}-主体图-${index + 1}.png`),
                source: (sources[index] as UploadSource) || 'gallery'
            })))

        const optionKeys = Array.isArray(item.settings?.optionKeys)
            ? item.settings.optionKeys.filter(Boolean) as OneClickCleanupOptionKey[]
            : []
        oneClickCleanupSelectedOptions.value = optionKeys.length ? optionKeys : ['watermark', 'icon', 'sticker', 'text']
        return
    }

    if (isProductImageTool.value) {
        originPreview.value = String(item.settings?.productPreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.productName || (originPreview.value ? `${item.title}-商品图.png` : ''))
        originSource.value = (item.settings?.productSource as UploadSource) || 'gallery'
        productImageStyleTab.value = (item.settings?.styleTab as ProductImageStyleTab) || 'template'
        productImageSceneCategory.value = (item.settings?.sceneCategory as ProductImageSceneCategory) || 'overview'
        productImageSelectedPresetKey.value = (item.settings?.presetKey as ProductImageStylePresetKey) || 'static-soft-shadow'
        productImageCustomStylePreview.value = String(item.settings?.customStylePreview || '')
        productImageCustomStyleName.value = String(item.settings?.customStyleName || (productImageCustomStylePreview.value ? `${item.title}-场景图.png` : ''))
        productImageCustomStyleSource.value = (item.settings?.customStyleSource as UploadSource) || 'gallery'
        productImageSizeKey.value = String(item.settings?.sizeKey || '1:1')
        productImageWidth.value = String(item.settings?.sizeWidth || '800')
        productImageHeight.value = String(item.settings?.sizeHeight || '800')

        if (productImageStyleTab.value !== 'custom') {
            setProductImageStyleTab(productImageStyleTab.value)
        }
        syncProductImageSizeByDimension()
        return
    }

    if (isFashionLookbookTool.value) {
        const clothesPreviewSetting = item.settings?.clothesPreviews
        const clothesNameSetting = item.settings?.clothesNames
        const clothesPreviews = Array.isArray(clothesPreviewSetting)
            ? clothesPreviewSetting
            : item.promptThumbs?.filter((preview) => preview !== item.settings?.modelPreview) || []
        const clothesNames = Array.isArray(clothesNameSetting)
            ? clothesNameSetting
            : []

        normalizeFashionLookbookClothesImages(clothesPreviews
            .filter(Boolean)
            .map((preview, index) => ({
                preview,
                name: String(clothesNames[index] || `${item.title}-服饰图${index + 1}.png`),
                source: 'gallery' as const
            })))

        fashionLookbookModelPreview.value = String(item.settings?.modelPreview || '')
        fashionLookbookModelName.value = String(item.settings?.modelName || (fashionLookbookModelPreview.value ? `${item.title}-模特图.png` : ''))
        fashionLookbookModelSource.value = (item.settings?.modelSource as UploadSource) || 'gallery'
        fashionLookbookRatio.value = (item.settings?.ratioKey as FashionLookbookRatio) || '3:4'
        fashionLookbookPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isHotCloneTool.value) {
        const productPreviewSetting = item.settings?.productPreviews
        const productNameSetting = item.settings?.productNames
        const referencePreviewSetting = item.settings?.referencePreviews
        const referenceNameSetting = item.settings?.referenceNames
        const productPreviews = Array.isArray(productPreviewSetting) ? productPreviewSetting : []
        const productNames = Array.isArray(productNameSetting) ? productNameSetting : []
        const referencePreviews = Array.isArray(referencePreviewSetting) ? referencePreviewSetting : []
        const referenceNames = Array.isArray(referenceNameSetting) ? referenceNameSetting : []

        normalizeHotCloneProductImages(productPreviews
            .filter(Boolean)
            .map((preview, index) => ({
                preview,
                name: String(productNames[index] || `${item.title}-商品图${index + 1}.png`),
                source: 'gallery' as const
            })))

        normalizeHotCloneReferenceImages(referencePreviews
            .filter(Boolean)
            .map((preview, index) => ({
                preview,
                name: String(referenceNames[index] || `${item.title}-参考图${index + 1}.png`),
                source: 'gallery' as const
            })))

        hotCloneProductName.value = String(item.settings?.productNameText || '')
        hotCloneSellingPoints.value = String(item.settings?.promptText || item.prompt || '')
        hotCloneRatio.value = (item.settings?.ratioKey as ProductSuiteRatio) || '3:4'
        hotCloneLanguage.value = (item.settings?.languageKey as HotCloneLanguage) || 'zh'
        return
    }

    if (isProductPromoVideoTool.value) {
        originPreview.value = item.promptThumbs?.[0] || getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-产品图.png`
        originSource.value = 'gallery'
        productPromoVideoRatio.value = (item.settings?.ratioKey as ProductPromoVideoRatio) || '9:16'
        productPromoVideoDuration.value = (item.settings?.durationKey as ProductPromoVideoDuration) || '5'
        productPromoVideoType.value = (item.settings?.typeKey as ProductPromoVideoType) || 'product'
        productPromoVideoPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isProductMultiAngleTool.value) {
        const imagePreviewSetting = item.settings?.imagePreviews
        const imageNameSetting = item.settings?.imageNames
        const previews = Array.isArray(imagePreviewSetting)
            ? imagePreviewSetting
            : item.promptThumbs || [getPrimaryToolResultPreview(item)]
        const names = Array.isArray(imageNameSetting) ? imageNameSetting : []

        normalizeProductMultiAngleImages(previews
            .filter(Boolean)
            .map((preview, index) => ({
                preview,
                name: String(names[index] || `${item.title}-原图${index + 1}.png`),
                source: 'gallery' as const
            })))

        productMultiAngleSelectedViews.value = String(item.settings?.viewKeys || '')
            .split('|')
            .filter(Boolean) as ProductMultiAngleViewKey[]
        if (!productMultiAngleSelectedViews.value.length) {
            productMultiAngleSelectedViews.value = [...defaultProductMultiAngleViews]
        }
        productMultiAnglePrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isShoeTryonTool.value) {
        shoeTryonModelPreview.value = String(item.settings?.modelPreview || item.promptThumbs?.[0] || '')
        shoeTryonModelName.value = String(item.settings?.modelName || (shoeTryonModelPreview.value ? `${item.title}-模特图.png` : ''))
        shoeTryonModelSource.value = (item.settings?.modelSource as UploadSource) || 'gallery'
        shoeTryonShoePreview.value = String(item.settings?.shoePreview || item.promptThumbs?.[1] || '')
        shoeTryonShoeName.value = String(item.settings?.shoeName || (shoeTryonShoePreview.value ? `${item.title}-鞋图.png` : ''))
        shoeTryonShoeSource.value = (item.settings?.shoeSource as UploadSource) || 'gallery'
        shoeTryonPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isModelWearTool.value) {
        modelWearModelPreview.value = String(item.settings?.modelPreview || item.promptThumbs?.[0] || '')
        modelWearModelName.value = String(item.settings?.modelName || (modelWearModelPreview.value ? `${item.title}-模特图.png` : ''))
        modelWearModelSource.value = (item.settings?.modelSource as UploadSource) || 'gallery'
        modelWearItemPreview.value = String(item.settings?.wearPreview || item.promptThumbs?.[1] || '')
        modelWearItemName.value = String(item.settings?.wearName || (modelWearItemPreview.value ? `${item.title}-穿戴图.png` : ''))
        modelWearItemSource.value = (item.settings?.wearSource as UploadSource) || 'gallery'
        modelWearPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isAiFittingTool.value) {
        originPreview.value = String(item.settings?.clothingPreview || item.promptThumbs?.[0] || '')
        originName.value = String(item.settings?.clothingName || (originPreview.value ? `${item.title}-服装图.png` : ''))
        originSource.value = (item.settings?.clothingSource as UploadSource) || 'gallery'
        aiFittingUploadCategory.value = (item.settings?.clothingCategory as AiFittingUploadCategory) || 'tops'
        aiFittingTopPreview.value = String(item.settings?.topPreview || (aiFittingUploadCategory.value === 'set' ? originPreview.value : ''))
        aiFittingTopName.value = String(item.settings?.topName || (aiFittingTopPreview.value ? `${item.title}-上装图.png` : ''))
        aiFittingTopSource.value = (item.settings?.topSource as UploadSource) || (aiFittingTopPreview.value ? originSource.value : 'gallery')
        aiFittingBottomPreview.value = String(item.settings?.bottomPreview || (aiFittingUploadCategory.value === 'set' ? item.promptThumbs?.[1] || '' : ''))
        aiFittingBottomName.value = String(item.settings?.bottomName || (aiFittingBottomPreview.value ? `${item.title}-下装图.png` : ''))
        aiFittingBottomSource.value = (item.settings?.bottomSource as UploadSource) || 'gallery'
        aiFittingMode.value = (item.settings?.modeKey as AiFittingMode) || 'single'
        aiFittingModelFilter.value = (item.settings?.modelFilter as AiFittingModelFilter) || 'all'
        aiFittingClothesFilter.value = (item.settings?.clothesFilter as AiFittingClothesFilter) || 'all'
        aiFittingPoseFilter.value = (item.settings?.poseFilter as AiFittingPoseFilter) || 'all'
        aiFittingSelectedPresetIds.value = Array.isArray(item.settings?.selectedPresetIds)
            ? item.settings?.selectedPresetIds.filter(Boolean)
            : []
        aiFittingSelectedGroupId.value = String(item.settings?.selectedGroupId || '')
        const customModelPreviews = Array.isArray(item.settings?.customModelPreviews)
            ? item.settings?.customModelPreviews.filter(Boolean)
            : []
        const customModelNames = Array.isArray(item.settings?.customModelNames)
            ? item.settings?.customModelNames
            : []
        const customModelSources = Array.isArray(item.settings?.customModelSources)
            ? item.settings?.customModelSources
            : []
        const fallbackCustomPreview = String(item.settings?.customModelPreview || '')
        const fallbackCustomThumbs = Array.isArray(item.promptThumbs) ? item.promptThumbs.slice(1).filter(Boolean) : []
        const mergedCustomPreviews = customModelPreviews.length
            ? customModelPreviews
            : fallbackCustomPreview
                ? [fallbackCustomPreview]
                : fallbackCustomThumbs
        normalizeAiFittingCustomModels(mergedCustomPreviews.map((preview, index) => ({
            preview,
            name: String(customModelNames[index] || item.settings?.customModelName || `${item.title}-模特图-${index + 1}.png`),
            source: (customModelSources[index] as UploadSource) || (item.settings?.customModelSource as UploadSource) || 'gallery'
        })))
        syncAiFittingSelection()
        return
    }

    if (isBuyerShowTool.value) {
        originPreview.value = String(item.settings?.productPreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.productName || (originPreview.value ? `${item.title}-商品图.png` : ''))
        originSource.value = (item.settings?.productSource as UploadSource) || 'gallery'
        buyerShowReferencePreview.value = String(item.settings?.referencePreview || item.promptThumbs?.[1] || '')
        buyerShowReferenceName.value = String(item.settings?.referenceName || (buyerShowReferencePreview.value ? `${item.title}-参考图.png` : ''))
        buyerShowReferenceSource.value = (item.settings?.referenceSource as UploadSource) || 'gallery'
        buyerShowPresetValues.value = {
            productType: String(item.settings?.productType || ''),
            productStatus: String(item.settings?.productStatus || ''),
            presentationMode: String(item.settings?.presentationMode || ''),
            sceneMood: String(item.settings?.sceneMood || ''),
            productRealism: String(item.settings?.productRealism || ''),
            environmentRealism: String(item.settings?.environmentRealism || ''),
            shootRealism: String(item.settings?.shootRealism || ''),
            targetMarket: String(item.settings?.targetMarket || '')
        }
        buyerShowPrompt.value = String(item.settings?.promptText || item.prompt || '')
        return
    }

    if (isSellingPointCardTool.value) {
        originPreview.value = String(item.settings?.productPreview || item.promptThumbs?.[0] || getPrimaryToolResultPreview(item) || '')
        originName.value = String(item.settings?.productName || (originPreview.value ? `${item.title}-商品图.png` : ''))
        originSource.value = (item.settings?.productSource as UploadSource) || 'gallery'
        sellingPointCardReferencePreview.value = String(item.settings?.referencePreview || item.promptThumbs?.[1] || '')
        sellingPointCardReferenceName.value = String(item.settings?.referenceName || (sellingPointCardReferencePreview.value ? `${item.title}-参考图.png` : ''))
        sellingPointCardReferenceSource.value = (item.settings?.referenceSource as UploadSource) || 'gallery'
        sellingPointCardRatio.value = (item.settings?.ratioKey as SellingPointCardRatio) || '3:4'
        sellingPointCardPresetValues.value = {
            productType: String(item.settings?.productType || '') as SellingPointCardProductType | '',
            language: String(item.settings?.language || '') as SellingPointCardLanguage | '',
            coreSellingPoint: String(item.settings?.coreSellingPoint || '') as SellingPointCardCoreSellingPoint | '',
            layoutStyle: String(item.settings?.layoutStyle || '') as SellingPointCardLayoutStyle | '',
            focus: String(item.settings?.focus || '') as SellingPointCardFocus | '',
            mainTitle: String(item.settings?.mainTitle || '') as SellingPointCardTitleMode | '',
            subTitle: String(item.settings?.subTitle || '') as SellingPointCardTitleMode | '',
            supportingElement: String(item.settings?.supportingElement || '') as SellingPointCardSupportingElement | '',
            targetMarket: String(item.settings?.targetMarket || '') as ProductSuiteCountry | ''
        }
        sellingPointCardPrompt.value = String(item.settings?.promptText || '')
        return
    }

    if (isProductSuiteTool.value) {
        const previews = item.promptThumbs?.length ? item.promptThumbs : [getPrimaryToolResultPreview(item)]
        normalizeProductSuiteImages(previews.filter(Boolean).map((preview, index) => ({
            preview,
            name: `${item.title}-商品图-${index + 1}.png`,
            source: 'gallery' as const
        })))
        productSuitePlatform.value = (item.settings?.platformKey as ProductSuitePlatform) || 'amazon'
        productSuiteCountry.value = (item.settings?.countryKey as ProductSuiteCountry) || 'us'
        productSuiteLanguage.value = (item.settings?.languageKey as ProductSuiteLanguage) || 'en'
        productSuiteRatio.value = (item.settings?.ratioKey as ProductSuiteRatio) || '1:1'
        productSuiteSellingPoints.value = String(item.prompt || '')
        productSuiteSelectedModules.value = String(item.settings?.moduleKeys || '')
            .split('|')
            .filter(Boolean) as ProductSuiteModuleKey[]
        if (!productSuiteSelectedModules.value.length) {
            productSuiteSelectedModules.value = [...defaultProductSuiteModules]
        }
        return
    }

    if (isStyleTransferTool.value) {
        originPreview.value = getPrimaryToolResultPreview(item)
        originName.value = `${item.title}-原图.png`
        originSource.value = 'gallery'
        styleTransferStyle.value = (item.settings?.styleKey as StyleTransferStyle) || 'hanman'
        return
    }

    originPreview.value = getPrimaryToolResultPreview(item)
    originName.value = `${item.title}.png`
    originSource.value = 'gallery'
}

const handleRegenerate = (item: ToolResultItem) => {
    const fashionLookbookPreview = fashionLookbookModelPreview.value || fashionLookbookClothesImages.value[0]?.preview || ''
    const hotClonePreview = hotCloneProductImages.value[0]?.preview || hotCloneReferenceImages.value[0]?.preview || ''
    const productMultiAnglePreview = productMultiAngleImages.value[0]?.preview || ''
    const oneClickCleanupPreview = oneClickCleanupImages.value[0]?.preview || ''
    const shoeTryonPreview = shoeTryonModelPreview.value || shoeTryonShoePreview.value || ''
    const modelWearPreview = modelWearModelPreview.value || modelWearItemPreview.value || ''
    const aiFittingPreview = aiFittingMode.value === 'custom'
        ? aiFittingCustomModelPreview.value
        : (aiFittingSelectedPresets.value[0]?.image || '')
    const buyerShowPreview = originPreview.value || buyerShowReferencePreview.value || ''
    const sellingPointCardPreview = originPreview.value || sellingPointCardReferencePreview.value || ''
    const sellingPointCardPromptText = buildSellingPointCardPrompt()
    const productImagePromptText = buildProductImagePrompt()
    const hairstylePromptText = buildHairstylePrompt()
    const imageTranslatePromptText = buildImageTranslatePrompt()
    const backgroundRemovalPromptText = buildBackgroundRemovalPrompt()
    const oneClickCleanupPromptText = buildOneClickCleanupPrompt()
    const aiFittingPromptText = buildAiFittingPrompt()
    const regenerated: ToolResultItem = {
        ...item,
        id: `${item.id}-regen-${Date.now()}`,
        createdAt: buildToolTimestamp(),
        preview: isLocalRedrawTool.value
            ? (buildMaskCompositePreview() || originPreview.value || getPrimaryToolResultPreview(item))
            : (fashionLookbookPreview || hotClonePreview || productMultiAnglePreview || oneClickCleanupPreview || shoeTryonPreview || modelWearPreview || aiFittingPreview || buyerShowPreview || sellingPointCardPreview || originPreview.value || getPrimaryToolResultPreview(item)),
        previews: item.kind === 'image'
            ? [
                isLocalRedrawTool.value
                    ? (buildMaskCompositePreview() || originPreview.value || getPrimaryToolResultPreview(item))
                    : (fashionLookbookPreview || hotClonePreview || productMultiAnglePreview || oneClickCleanupPreview || shoeTryonPreview || modelWearPreview || aiFittingPreview || buyerShowPreview || sellingPointCardPreview || originPreview.value || getPrimaryToolResultPreview(item))
            ]
            : item.previews,
        prompt: isLocalRedrawTool.value
            ? (localRedrawPrompt.value.trim() || item.prompt)
            : isProductImageTool.value
                ? (productImagePromptText || item.prompt)
            : isHairstyleTool.value
                ? (hairstylePromptText || item.prompt)
            : isImageTranslateTool.value
                ? (imageTranslatePromptText || item.prompt)
            : isBackgroundRemovalTool.value
                ? (backgroundRemovalPromptText || item.prompt)
            : isOneClickCleanupTool.value
                ? (oneClickCleanupPromptText || item.prompt)
            : isFashionLookbookTool.value
                ? (fashionLookbookPrompt.value.trim() || item.prompt)
            : isHotCloneTool.value
                ? (hotCloneSellingPoints.value.trim() || item.prompt)
            : isProductPromoVideoTool.value
                ? (productPromoVideoPrompt.value.trim() || item.prompt)
            : isProductMultiAngleTool.value
                ? (productMultiAnglePrompt.value.trim() || item.prompt)
            : isShoeTryonTool.value
                ? (shoeTryonPrompt.value.trim() || item.prompt)
            : isModelWearTool.value
                ? (modelWearPrompt.value.trim() || item.prompt)
            : isBuyerShowTool.value
                ? (buyerShowPrompt.value.trim() || item.prompt)
            : isProductSuiteTool.value
                ? (productSuiteSellingPoints.value.trim() || item.prompt)
                : item.prompt,
        promptThumbs: isLocalRedrawTool.value
            ? [
                originPreview.value || item.promptThumbs?.[0],
                buildMaskCompositePreview() || item.promptThumbs?.[1]
            ].filter(Boolean)
            : isProductImageTool.value
                ? [originPreview.value, productImageReferencePreview.value].filter(Boolean)
            : isHairstyleTool.value
                ? [originPreview.value, hairstyleReferencePreview.value].filter(Boolean)
            : isImageTranslateTool.value
                ? [originPreview.value].filter(Boolean)
            : isBackgroundRemovalTool.value
                ? [originPreview.value].filter(Boolean)
            : isOneClickCleanupTool.value
                ? oneClickCleanupImages.value.map((entry) => entry.preview).filter(Boolean)
            : isFashionLookbookTool.value
                ? [
                    fashionLookbookModelPreview.value,
                    ...fashionLookbookClothesImages.value.map((entry) => entry.preview)
                ].filter(Boolean)
            : isHotCloneTool.value
                ? [
                    ...hotCloneProductImages.value.map((entry) => entry.preview),
                    ...hotCloneReferenceImages.value.map((entry) => entry.preview)
                ].filter(Boolean)
            : isProductPromoVideoTool.value
                ? [originPreview.value].filter(Boolean)
            : isProductMultiAngleTool.value
                ? productMultiAngleImages.value.map((entry) => entry.preview).filter(Boolean)
            : isShoeTryonTool.value
                ? [shoeTryonModelPreview.value, shoeTryonShoePreview.value].filter(Boolean)
            : isModelWearTool.value
                ? [modelWearModelPreview.value, modelWearItemPreview.value].filter(Boolean)
            : isAiFittingTool.value
                ? [
                    ...aiFittingGarmentThumbs.value,
                    ...(aiFittingMode.value === 'custom'
                        ? aiFittingCustomModelImages.value.map((entry) => entry.preview)
                        : aiFittingSelectedPresets.value.map((entry) => entry.image))
                ].filter(Boolean)
            : isBuyerShowTool.value
                ? [originPreview.value, buyerShowReferencePreview.value].filter(Boolean)
            : isSellingPointCardTool.value
                ? [originPreview.value, sellingPointCardReferencePreview.value].filter(Boolean)
            : isProductSuiteTool.value
                ? productSuiteImages.value.map((entry) => entry.preview).filter(Boolean)
            : item.promptThumbs,
        maskOverlay: isLocalRedrawTool.value ? (maskPreview.value || item.maskOverlay) : item.maskOverlay,
        settings: isOutpaintTool.value
            ? { ...outpaintValues.value }
            : isPhotoRestoreTool.value
                ? {
                    repairMode: photoRestoreMode.value,
                    modelVariant: photoRestoreModel.value,
                    modelCode: photoRestoreModel.value
                }
                : isLineDrawingTool.value
                    ? {
                        modelVariant: lineDrawingModel.value,
                        promptText: lineDrawingPrompt.value.trim()
                    }
                : isHairstyleTool.value
                    ? {
                        imagePreview: originPreview.value,
                        imageName: originName.value,
                        imageSource: originSource.value,
                        referencePreview: hairstyleReferencePreview.value,
                        referenceName: hairstyleReferenceName.value,
                        referenceSource: hairstyleReferenceSource.value
                    }
                : isImageTranslateTool.value
                    ? {
                        imagePreview: originPreview.value,
                        imageName: originName.value,
                        imageSource: originSource.value,
                        sourceLanguage: imageTranslateSourceLanguage.value,
                        targetLanguage: imageTranslateTargetLanguage.value,
                        promptText: imageTranslatePrompt.value.trim()
                    }
                : isBackgroundRemovalTool.value
                    ? {
                        imagePreview: originPreview.value,
                        imageName: originName.value,
                        imageSource: originSource.value
                    }
                : isOneClickCleanupTool.value
                    ? {
                        imagePreviews: oneClickCleanupImages.value.map((entry) => entry.preview),
                        imageNames: oneClickCleanupImages.value.map((entry) => entry.name),
                        imageSources: oneClickCleanupImages.value.map((entry) => entry.source),
                        optionKeys: oneClickCleanupSelectedOptions.value
                    }
                : isProductImageTool.value
                    ? {
                        productPreview: originPreview.value,
                        productName: originName.value,
                        productSource: originSource.value,
                        styleTab: productImageStyleTab.value,
                        sceneCategory: productImageSceneCategory.value,
                        presetKey: productImageSelectedPresetKey.value,
                        presetTitle: getProductImageStylePresetLabel(productImageSelectedPresetKey.value),
                        customStylePreview: productImageCustomStylePreview.value,
                        customStyleName: productImageCustomStyleName.value,
                        customStyleSource: productImageCustomStyleSource.value,
                        sizeKey: productImageSizeKey.value,
                        sizeWidth: productImageWidth.value,
                        sizeHeight: productImageHeight.value
                    }
                : isFashionLookbookTool.value
                    ? {
                        ratioKey: fashionLookbookRatio.value,
                        modelPreview: fashionLookbookModelPreview.value,
                        modelName: fashionLookbookModelName.value,
                        modelSource: fashionLookbookModelSource.value,
                        clothesPreviews: fashionLookbookClothesImages.value.map((entry) => entry.preview),
                        clothesNames: fashionLookbookClothesImages.value.map((entry) => entry.name),
                        promptText: fashionLookbookPrompt.value.trim()
                    }
                : isHotCloneTool.value
                    ? {
                        ratioKey: hotCloneRatio.value,
                        languageKey: hotCloneLanguage.value,
                        productNameText: hotCloneProductName.value.trim(),
                        productPreviews: hotCloneProductImages.value.map((entry) => entry.preview),
                        productNames: hotCloneProductImages.value.map((entry) => entry.name),
                        referencePreviews: hotCloneReferenceImages.value.map((entry) => entry.preview),
                        referenceNames: hotCloneReferenceImages.value.map((entry) => entry.name),
                        promptText: hotCloneSellingPoints.value.trim()
                    }
                : isProductPromoVideoTool.value
                    ? {
                        ratioKey: productPromoVideoRatio.value,
                        durationKey: productPromoVideoDuration.value,
                        typeKey: productPromoVideoType.value,
                        promptText: productPromoVideoPrompt.value.trim()
                    }
                : isProductMultiAngleTool.value
                    ? {
                        viewKeys: productMultiAngleSelectedViews.value.join('|'),
                        imagePreviews: productMultiAngleImages.value.map((entry) => entry.preview),
                        imageNames: productMultiAngleImages.value.map((entry) => entry.name),
                        promptText: productMultiAnglePrompt.value.trim()
                    }
                : isShoeTryonTool.value
                    ? {
                        modelPreview: shoeTryonModelPreview.value,
                        modelName: shoeTryonModelName.value,
                        modelSource: shoeTryonModelSource.value,
                        shoePreview: shoeTryonShoePreview.value,
                        shoeName: shoeTryonShoeName.value,
                        shoeSource: shoeTryonShoeSource.value,
                        promptText: shoeTryonPrompt.value.trim()
                    }
                : isModelWearTool.value
                    ? {
                        modelPreview: modelWearModelPreview.value,
                        modelName: modelWearModelName.value,
                        modelSource: modelWearModelSource.value,
                        wearPreview: modelWearItemPreview.value,
                        wearName: modelWearItemName.value,
                        wearSource: modelWearItemSource.value,
                        promptText: modelWearPrompt.value.trim()
                    }
                : isAiFittingTool.value
                    ? {
                        clothingPreview: aiFittingPrimaryGarmentPreview.value,
                        clothingName: aiFittingPrimaryGarmentName.value,
                        clothingSource: isAiFittingSplitGarment.value
                            ? (aiFittingTopPreview.value ? aiFittingTopSource.value : aiFittingBottomSource.value)
                            : originSource.value,
                        clothingCategory: aiFittingUploadCategory.value,
                        topPreview: aiFittingTopPreview.value,
                        topName: aiFittingTopName.value,
                        topSource: aiFittingTopSource.value,
                        bottomPreview: aiFittingBottomPreview.value,
                        bottomName: aiFittingBottomName.value,
                        bottomSource: aiFittingBottomSource.value,
                        modeKey: aiFittingMode.value,
                        modelFilter: aiFittingModelFilter.value,
                        clothesFilter: aiFittingClothesFilter.value,
                        poseFilter: aiFittingPoseFilter.value,
                        selectedPresetIds: aiFittingSelectedPresetIds.value,
                        selectedGroupId: aiFittingSelectedGroupId.value,
                        customModelPreview: aiFittingCustomModelPreview.value,
                        customModelName: aiFittingCustomModelName.value,
                        customModelSource: aiFittingCustomModelSource.value,
                        customModelPreviews: aiFittingCustomModelImages.value.map((entry) => entry.preview),
                        customModelNames: aiFittingCustomModelImages.value.map((entry) => entry.name),
                        customModelSources: aiFittingCustomModelImages.value.map((entry) => entry.source),
                        promptText: aiFittingPromptText
                    }
                : isBuyerShowTool.value
                    ? {
                        productPreview: originPreview.value,
                        productName: originName.value,
                        productSource: originSource.value,
                        referencePreview: buyerShowReferencePreview.value,
                        referenceName: buyerShowReferenceName.value,
                        referenceSource: buyerShowReferenceSource.value,
                        productType: buyerShowPresetValues.value.productType,
                        productStatus: buyerShowPresetValues.value.productStatus,
                        presentationMode: buyerShowPresetValues.value.presentationMode,
                        sceneMood: buyerShowPresetValues.value.sceneMood,
                        productRealism: buyerShowPresetValues.value.productRealism,
                        environmentRealism: buyerShowPresetValues.value.environmentRealism,
                        shootRealism: buyerShowPresetValues.value.shootRealism,
                        targetMarket: buyerShowPresetValues.value.targetMarket,
                        promptText: buyerShowPrompt.value.trim()
                    }
                : isSellingPointCardTool.value
                    ? {
                        productPreview: originPreview.value,
                        productName: originName.value,
                        productSource: originSource.value,
                        referencePreview: sellingPointCardReferencePreview.value,
                        referenceName: sellingPointCardReferenceName.value,
                        referenceSource: sellingPointCardReferenceSource.value,
                        ratioKey: sellingPointCardRatio.value,
                        productType: sellingPointCardPresetValues.value.productType,
                        language: sellingPointCardPresetValues.value.language,
                        coreSellingPoint: sellingPointCardPresetValues.value.coreSellingPoint,
                        layoutStyle: sellingPointCardPresetValues.value.layoutStyle,
                        focus: sellingPointCardPresetValues.value.focus,
                        mainTitle: sellingPointCardPresetValues.value.mainTitle,
                        subTitle: sellingPointCardPresetValues.value.subTitle,
                        supportingElement: sellingPointCardPresetValues.value.supportingElement,
                        targetMarket: sellingPointCardPresetValues.value.targetMarket,
                        promptText: sellingPointCardPrompt.value.trim()
                    }
                : isProductSuiteTool.value
                    ? {
                        platformKey: productSuitePlatform.value,
                        countryKey: productSuiteCountry.value,
                        languageKey: productSuiteLanguage.value,
                        ratioKey: productSuiteRatio.value,
                        moduleKeys: productSuiteSelectedModules.value.join('|')
                    }
                : isStyleTransferTool.value
                    ? {
                        styleKey: styleTransferStyle.value
                    }
                : item.settings,
        meta: isFashionLookbookTool.value
            ? [
                getFashionLookbookRatioLabel(fashionLookbookRatio.value),
                `${fashionLookbookClothesImages.value.length}张服饰图`,
                fashionLookbookModelPreview.value ? '含模特图' : '仅服饰图'
            ]
            : isProductImageTool.value
            ? [
                getProductImageStyleTabLabel(productImageStyleTab.value),
                productImageStyleTab.value === 'custom'
                    ? '自定义场景'
                    : (getProductImageStylePresetLabel(productImageSelectedPresetKey.value) || '默认风格'),
                productImageSizeDisplayLabel.value
            ]
            : isHotCloneTool.value
            ? [
                getProductSuiteRatioLabel(hotCloneRatio.value),
                getHotCloneLanguageLabel(hotCloneLanguage.value),
                `${hotCloneProductImages.value.length}张商品图`,
                `${hotCloneReferenceImages.value.length}张参考图`
            ]
            : isProductPromoVideoTool.value
            ? [
                getProductPromoVideoRatioLabel(productPromoVideoRatio.value),
                getProductPromoVideoDurationLabel(productPromoVideoDuration.value),
                getProductPromoVideoTypeLabel(productPromoVideoType.value)
            ]
            : isImageTranslateTool.value
            ? [
                getImageTranslateLanguageLabel(imageTranslateSourceLanguage.value),
                getImageTranslateLanguageLabel(imageTranslateTargetLanguage.value),
                '图片翻译'
            ]
            : isHairstyleTool.value
            ? [
                '人物原图',
                '参考发型',
                'AI换发型'
            ]
            : isBackgroundRemovalTool.value
            ? ['透明背景', '主体保留', '图片去背景']
            : isOneClickCleanupTool.value
            ? [
                `${oneClickCleanupImages.value.length}张主体图`,
                `${oneClickCleanupSelectedOptions.value.length}项内容`,
                oneClickCleanupOptionSummary.value.join('、') || '智能清理'
            ]
            : isProductMultiAngleTool.value
            ? [
                `${productMultiAngleSelectedViews.value.length}个视角`,
                `${productMultiAngleImages.value.length}张原图`,
                productMultiAngleSelectedViews.value.map((entry) => getProductMultiAngleViewLabel(entry)).join('、')
            ]
            : isShoeTryonTool.value
            ? ['模特图', '鞋图', '试穿效果']
            : isModelWearTool.value
            ? ['模特图', '穿戴图', '穿戴效果']
            : isAiFittingTool.value
            ? [
                getAiFittingModeLabel(aiFittingMode.value),
                getAiFittingUploadCategoryLabel(aiFittingUploadCategory.value),
                getAiFittingSelectionSummary()
            ]
            : isBuyerShowTool.value
            ? [
                getBuyerShowPresetLabel('targetMarket', buyerShowPresetValues.value.targetMarket) || '未指定市场',
                buyerShowReferencePreview.value ? '含参考图' : '无参考图',
                '买家秀'
            ]
            : isSellingPointCardTool.value
            ? [
                getSellingPointCardRatioLabel(sellingPointCardRatio.value),
                getSellingPointCardSelectLabel('targetMarket', sellingPointCardPresetValues.value.targetMarket) || '未指定市场'
            ]
            : isProductSuiteTool.value
            ? [
                getProductSuitePlatformLabel(productSuitePlatform.value),
                getProductSuiteCountryLabel(productSuiteCountry.value),
                getProductSuiteRatioLabel(productSuiteRatio.value),
                `${productSuiteSelectedModules.value.length}个模块`
            ]
            : item.kind === 'tool'
                ? [getToolResolutionOutput(activeResolution.value)]
                : [...item.meta]
    }

    if (isOutpaintTool.value) {
        regenerated.meta = [
            `左 ${outpaintValues.value.left}`,
            `上 ${outpaintValues.value.top}`,
            `右 ${outpaintValues.value.right}`,
            `下 ${outpaintValues.value.bottom}`
        ]
    } else if (isPhotoRestoreTool.value) {
        regenerated.meta = [
            getPhotoRestoreModeLabel(photoRestoreMode.value),
            getPhotoRestoreModelLabel(photoRestoreModel.value)
        ]
    } else if (isStyleTransferTool.value) {
        regenerated.meta = [
            getStyleTransferLabel(styleTransferStyle.value),
            '风格转换'
        ]
    } else if (isProductImageTool.value) {
        regenerated.meta = [
            getProductImageStyleTabLabel(productImageStyleTab.value),
            productImageStyleTab.value === 'custom'
                ? '自定义场景'
                : (getProductImageStylePresetLabel(productImageSelectedPresetKey.value) || '默认风格'),
            productImageSizeDisplayLabel.value
        ]
    } else if (isFashionLookbookTool.value) {
        regenerated.meta = [
            getFashionLookbookRatioLabel(fashionLookbookRatio.value),
            `${fashionLookbookClothesImages.value.length}张服饰图`,
            fashionLookbookModelPreview.value ? '含模特图' : '仅服饰图'
        ]
    } else if (isHotCloneTool.value) {
        regenerated.meta = [
            getProductSuiteRatioLabel(hotCloneRatio.value),
            getHotCloneLanguageLabel(hotCloneLanguage.value),
            `${hotCloneProductImages.value.length}张商品图`,
            `${hotCloneReferenceImages.value.length}张参考图`
        ]
    } else if (isProductPromoVideoTool.value) {
        regenerated.meta = [
            getProductPromoVideoRatioLabel(productPromoVideoRatio.value),
            getProductPromoVideoDurationLabel(productPromoVideoDuration.value),
            getProductPromoVideoTypeLabel(productPromoVideoType.value)
        ]
    } else if (isImageTranslateTool.value) {
        regenerated.meta = [
            getImageTranslateLanguageLabel(imageTranslateSourceLanguage.value),
            getImageTranslateLanguageLabel(imageTranslateTargetLanguage.value),
            '图片翻译'
        ]
    } else if (isHairstyleTool.value) {
        regenerated.meta = [
            '人物原图',
            '参考发型',
            'AI换发型'
        ]
    } else if (isBackgroundRemovalTool.value) {
        regenerated.meta = ['透明背景', '主体保留', '图片去背景']
    } else if (isOneClickCleanupTool.value) {
        regenerated.meta = [
            `${oneClickCleanupImages.value.length}张主体图`,
            `${oneClickCleanupSelectedOptions.value.length}项内容`,
            oneClickCleanupOptionSummary.value.join('、') || '智能清理'
        ]
    } else if (isProductMultiAngleTool.value) {
        regenerated.meta = [
            `${productMultiAngleSelectedViews.value.length}个视角`,
            `${productMultiAngleImages.value.length}张原图`,
            productMultiAngleSelectedViews.value.map((entry) => getProductMultiAngleViewLabel(entry)).join('、')
        ]
    } else if (isAiFittingTool.value) {
        regenerated.meta = [
            getAiFittingModeLabel(aiFittingMode.value),
            getAiFittingUploadCategoryLabel(aiFittingUploadCategory.value),
            getAiFittingSelectionSummary()
        ]
    } else if (isProductSuiteTool.value) {
        regenerated.meta = [
            getProductSuitePlatformLabel(productSuitePlatform.value),
            getProductSuiteCountryLabel(productSuiteCountry.value),
            getProductSuiteRatioLabel(productSuiteRatio.value),
            `${productSuiteSelectedModules.value.length}个模块`
        ]
    } else if (isSellingPointCardTool.value) {
        regenerated.meta = [
            getSellingPointCardRatioLabel(sellingPointCardRatio.value),
            getSellingPointCardSelectLabel('targetMarket', sellingPointCardPresetValues.value.targetMarket) || '未指定市场'
        ]
    } else if (isLineDrawingTool.value) {
        regenerated.meta = [
            getLineDrawingModelLabel(lineDrawingModel.value),
            '线稿生成'
        ]
    }

    if (isLineDrawingTool.value) {
        regenerated.prompt = lineDrawingPrompt.value.trim() || item.prompt
    }

    if (isProductImageTool.value) {
        regenerated.prompt = productImagePromptText || item.prompt
    }

    if (isImageTranslateTool.value) {
        regenerated.prompt = imageTranslatePromptText || item.prompt
    }

    if (isHairstyleTool.value) {
        regenerated.prompt = hairstylePromptText || item.prompt
    }

    if (isBackgroundRemovalTool.value) {
        regenerated.prompt = backgroundRemovalPromptText || item.prompt
    }

    if (isOneClickCleanupTool.value) {
        regenerated.prompt = oneClickCleanupPromptText || item.prompt
    }

    if (isFashionLookbookTool.value) {
        regenerated.prompt = fashionLookbookPrompt.value.trim() || item.prompt
    }

    if (isHotCloneTool.value) {
        regenerated.prompt = hotCloneSellingPoints.value.trim() || item.prompt
    }

    if (isProductPromoVideoTool.value) {
        regenerated.prompt = productPromoVideoPrompt.value.trim() || item.prompt
    }

    if (isProductMultiAngleTool.value) {
        regenerated.prompt = productMultiAnglePrompt.value.trim() || item.prompt
    }

    if (isAiFittingTool.value) {
        regenerated.prompt = aiFittingPromptText || item.prompt
    }

    if (isProductSuiteTool.value) {
        regenerated.prompt = productSuiteSellingPoints.value.trim() || item.prompt
    }

    if (isBuyerShowTool.value) {
        regenerated.prompt = buyerShowPrompt.value.trim() || item.prompt
    }

    if (isSellingPointCardTool.value) {
        regenerated.prompt = sellingPointCardPromptText || item.prompt
    }

    prependResult(regenerated)
    activeResultId.value = regenerated.id
}

const handleDelete = (itemId: string) => {
    resultMap.value = {
        ...resultMap.value,
        [currentTool.value.id]: currentResults.value.filter((item) => item.id !== itemId)
    }

    if (activeResultId.value === itemId) {
        activeResultId.value = ''
    }
}

const setActiveFilter = (filter: ToolResultFilter) => {
    activeFilter.value = filter
    filterMenuOpen.value = false
}

const closeGalleryModal = () => {
    showGalleryModal.value = false
}

const applyHistoryMaterial = (item: { name: string; image: string }) => {
    if (isProductSuiteTool.value && activeUploadField.value === 'origin') {
        appendProductSuiteImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isAiFittingTool.value && activeUploadField.value === 'ai-fitting-model' && aiFittingMode.value === 'custom') {
        appendAiFittingCustomModels([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isFashionLookbookTool.value && activeUploadField.value === 'fashion-clothes') {
        appendFashionLookbookClothesImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isHotCloneTool.value && activeUploadField.value === 'hot-clone-product') {
        appendHotCloneProductImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isHotCloneTool.value && activeUploadField.value === 'hot-clone-reference') {
        appendHotCloneReferenceImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isProductMultiAngleTool.value && activeUploadField.value === 'product-multi-angle') {
        appendProductMultiAngleImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isOneClickCleanupTool.value && activeUploadField.value === 'cleanup-images') {
        appendOneClickCleanupImages([{
            preview: item.image,
            name: item.name,
            source: 'gallery'
        }])
        showGalleryModal.value = false
        return
    }

    if (isBuyerShowTool.value && activeUploadField.value === 'buyer-show-reference') {
        setUploadFieldValue('buyer-show-reference', {
            preview: item.image,
            name: item.name,
            source: 'gallery'
        })
        showGalleryModal.value = false
        return
    }

    if (isSellingPointCardTool.value && activeUploadField.value === 'selling-point-reference') {
        setUploadFieldValue('selling-point-reference', {
            preview: item.image,
            name: item.name,
            source: 'gallery'
        })
        showGalleryModal.value = false
        return
    }

    setUploadFieldValue(activeUploadField.value, {
        preview: item.image,
        name: item.name,
        source: 'gallery'
    })
    showGalleryModal.value = false
}

const maskColorPool = ['#19D7B2', '#FF7A59', '#5C8BFF', '#F5C451', '#C86BFF', '#FF5FA2', '#45C7FF', '#7DE05A']

const ensureMaskSelection = () => {
    if (maskSelections.value.length) {
        if (!maskSelections.value.some((item) => item.id === activeMaskSelectionId.value)) {
            activeMaskSelectionId.value = maskSelections.value[0].id
        }
        return
    }

    const fallback = { id: 'mask-selection-1', color: '#19D7B2' }
    maskSelections.value = [fallback]
    activeMaskSelectionId.value = fallback.id
}

const getRandomMaskColor = () => {
    const usedColors = new Set(maskSelections.value.map((item) => item.color.toLowerCase()))
    const candidate = maskColorPool.find((color) => !usedColors.has(color.toLowerCase()))
    if (candidate) return candidate

    let attempts = 0
    while (attempts < 24) {
        const hue = Math.floor(Math.random() * 360)
        const color = `hsl(${hue} 82% 62%)`
        if (!usedColors.has(color.toLowerCase())) return color
        attempts += 1
    }

    return `hsl(${(maskSelections.value.length * 47) % 360} 82% 62%)`
}

const addMaskSelection = () => {
    const id = `mask-selection-${Date.now()}`
    const color = getRandomMaskColor()
    maskSelections.value = [...maskSelections.value, { id, color }]
    activeMaskSelectionId.value = id
}

const getMaskCanvasContext = () => {
    const canvas = maskCanvasRef.value
    if (!canvas) return null
    return canvas.getContext('2d')
}

const restoreMaskCanvas = (ctx: CanvasRenderingContext2D, width: number, height: number, snapshot = maskPreview.value) => {
    ctx.clearRect(0, 0, width, height)
    if (!snapshot) return

    const image = new Image()
    image.onload = () => {
        ctx.clearRect(0, 0, width, height)
        ctx.drawImage(image, 0, 0, width, height)
    }
    image.src = snapshot
}

const resetMaskHistory = (snapshot = '') => {
    maskHistory.value = [snapshot]
    maskHistoryIndex.value = 0
}

const pushMaskHistory = (snapshot: string) => {
    const currentSnapshot = maskHistory.value[maskHistoryIndex.value]
    if (currentSnapshot === snapshot) return

    maskHistory.value = [...maskHistory.value.slice(0, maskHistoryIndex.value + 1), snapshot]
    maskHistoryIndex.value = maskHistory.value.length - 1
}

const applyMaskSnapshot = (snapshot = '') => {
    maskPreview.value = snapshot
    maskName.value = snapshot ? `${currentTool.value.detailName}-蒙版.png` : ''
    maskSource.value = snapshot ? 'local' : 'gallery'
    hasMaskStroke.value = !!snapshot

    const canvas = maskCanvasRef.value
    const ctx = getMaskCanvasContext()
    if (!canvas || !ctx) return

    restoreMaskCanvas(ctx, canvas.clientWidth, canvas.clientHeight, snapshot)
}

const syncMaskCanvasSize = async () => {
    await nextTick()

    const canvas = maskCanvasRef.value
    const image = maskImageRef.value
    if (!canvas || !image) return

    const width = image.clientWidth
    const height = image.clientHeight
    if (!width || !height) return

    const dpr = window.devicePixelRatio || 1
    canvas.width = Math.max(1, Math.round(width * dpr))
    canvas.height = Math.max(1, Math.round(height * dpr))
    canvas.style.width = `${width}px`
    canvas.style.height = `${height}px`

    const ctx = getMaskCanvasContext()
    if (!ctx) return

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    ctx.lineCap = 'round'
    ctx.lineJoin = 'round'
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.88)'
    ctx.lineWidth = 26
    restoreMaskCanvas(ctx, width, height)
}

const getMaskPoint = (event: PointerEvent) => {
    const canvas = maskCanvasRef.value
    if (!canvas) return null

    const rect = canvas.getBoundingClientRect()
    return {
        x: event.clientX - rect.left,
        y: event.clientY - rect.top
    }
}

const saveMaskDrawing = () => {
    const canvas = maskCanvasRef.value
    if (!canvas) return

    maskPreview.value = canvas.toDataURL('image/png')
    maskName.value = `${currentTool.value.detailName}-蒙版.png`
    maskSource.value = 'local'
    hasMaskStroke.value = true
}

const openMaskEditor = async () => {
    if (!originPreview.value) return

    ensureMaskSelection()
    showMaskEditor.value = true
    activeMaskTool.value = 'brush'
    maskEditorInitialSnapshot.value = maskPreview.value || ''
    maskEditorScale.value = 1
    maskEditorOffset.value = { x: 0, y: 0 }
    maskPanStart.value = null
    resetMaskHistory(maskPreview.value || '')
    await nextTick()
    syncMaskCanvasSize()
}

const closeMaskEditor = () => {
    applyMaskSnapshot(maskEditorInitialSnapshot.value || '')
    showMaskEditor.value = false
    isMaskPointerDown.value = false
    maskPanStart.value = null
    lastMaskPoint.value = null
}

const confirmMaskEditor = () => {
    saveMaskDrawing()
    pushMaskHistory(maskPreview.value)
    maskEditorInitialSnapshot.value = maskPreview.value
    showMaskEditor.value = false
}

const setMaskTool = (tool: MaskEditorTool) => {
    activeMaskTool.value = tool
}

const zoomInMask = () => {
    maskEditorScale.value = Math.min(3, Number((maskEditorScale.value + 0.1).toFixed(2)))
}

const zoomOutMask = () => {
    maskEditorScale.value = Math.max(0.4, Number((maskEditorScale.value - 0.1).toFixed(2)))
}

const undoMask = () => {
    if (!canUndoMask.value) return
    maskHistoryIndex.value -= 1
    applyMaskSnapshot(maskHistory.value[maskHistoryIndex.value] || '')
}

const redoMask = () => {
    if (!canRedoMask.value) return
    maskHistoryIndex.value += 1
    applyMaskSnapshot(maskHistory.value[maskHistoryIndex.value] || '')
}

const startMaskDrawing = (event: PointerEvent) => {
    if (!showMaskEditor.value || !originPreview.value) return

    if (activeMaskTool.value === 'pan') {
        maskPanStart.value = {
            x: event.clientX,
            y: event.clientY,
            offsetX: maskEditorOffset.value.x,
            offsetY: maskEditorOffset.value.y
        }
        return
    }

    if (!originPreview.value) return

    const canvas = maskCanvasRef.value
    const ctx = getMaskCanvasContext()
    const point = getMaskPoint(event)
    if (!canvas || !ctx || !point) return

    ctx.globalCompositeOperation = activeMaskTool.value === 'erase' ? 'destination-out' : 'source-over'
    ctx.strokeStyle = activeMaskTool.value === 'erase' ? 'rgba(0, 0, 0, 1)' : activeMaskColor.value
    ctx.lineWidth = activeMaskSize.value
    isMaskPointerDown.value = true
    lastMaskPoint.value = point
    canvas.setPointerCapture?.(event.pointerId)
    ctx.beginPath()
    ctx.moveTo(point.x, point.y)
    ctx.lineTo(point.x, point.y)
    ctx.stroke()
    saveMaskDrawing()
}

const drawMaskStroke = (event: PointerEvent) => {
    if (!showMaskEditor.value) return

    if (activeMaskTool.value === 'pan' && maskPanStart.value) {
        maskEditorOffset.value = {
            x: maskPanStart.value.offsetX + (event.clientX - maskPanStart.value.x),
            y: maskPanStart.value.offsetY + (event.clientY - maskPanStart.value.y)
        }
        return
    }

    if (!isMaskPointerDown.value) return

    const ctx = getMaskCanvasContext()
    const point = getMaskPoint(event)
    if (!ctx || !point || !lastMaskPoint.value) return

    ctx.globalCompositeOperation = activeMaskTool.value === 'erase' ? 'destination-out' : 'source-over'
    ctx.strokeStyle = activeMaskTool.value === 'erase' ? 'rgba(0, 0, 0, 1)' : activeMaskColor.value
    ctx.lineWidth = activeMaskSize.value
    ctx.beginPath()
    ctx.moveTo(lastMaskPoint.value.x, lastMaskPoint.value.y)
    ctx.lineTo(point.x, point.y)
    ctx.stroke()
    lastMaskPoint.value = point
    saveMaskDrawing()
}

const stopMaskDrawing = (event?: PointerEvent) => {
    if (activeMaskTool.value === 'pan') {
        maskPanStart.value = null
        return
    }

    if (!isMaskPointerDown.value) return

    isMaskPointerDown.value = false
    lastMaskPoint.value = null
    const canvas = maskCanvasRef.value
    if (canvas && event) {
        canvas.releasePointerCapture?.(event.pointerId)
    }
    saveMaskDrawing()
    pushMaskHistory(maskPreview.value)
}

const clearMaskDrawing = () => {
    maskPreview.value = ''
    maskName.value = ''
    maskSource.value = 'gallery'
    hasMaskStroke.value = false
    isMaskPointerDown.value = false
    lastMaskPoint.value = null
    maskPanStart.value = null
    resetMaskHistory('')

    const canvas = maskCanvasRef.value
    const ctx = getMaskCanvasContext()
    if (!canvas || !ctx) return
    ctx.clearRect(0, 0, canvas.clientWidth, canvas.clientHeight)
}

const buildMaskCompositePreview = () => {
    const image = maskImageRef.value
    const canvas = maskCanvasRef.value
    if (!image || !canvas) return originPreview.value

    const width = image.clientWidth
    const height = image.clientHeight
    if (!width || !height) return originPreview.value

    const output = document.createElement('canvas')
    output.width = width
    output.height = height
    const ctx = output.getContext('2d')
    if (!ctx) return originPreview.value

    ctx.drawImage(image, 0, 0, width, height)
    ctx.drawImage(canvas, 0, 0, width, height)
    return output.toDataURL('image/png')
}

const goBackToTools = () => {
    router.push('/ai/tools')
}

const closeFloatingMenus = () => {
    filterMenuOpen.value = false
    productSuitePlatformMenuOpen.value = false
    productSuiteCountryMenuOpen.value = false
    productSuiteLanguageMenuOpen.value = false
    productSuiteRatioMenuOpen.value = false
    productImageRatioMenuOpen.value = false
    productImageSceneCategoryMenuOpen.value = false
    fashionLookbookRatioMenuOpen.value = false
    hotCloneLanguageMenuOpen.value = false
    hotCloneRatioMenuOpen.value = false
    imageTranslateSourceMenuOpen.value = false
    imageTranslateTargetMenuOpen.value = false
    aiFittingUploadCategoryMenuOpen.value = false
    aiFittingModelMenuOpen.value = false
    aiFittingClothesMenuOpen.value = false
    aiFittingPoseMenuOpen.value = false
    buyerShowOpenField.value = ''
    sellingPointCardOpenField.value = ''
}

onMounted(() => {
    document.addEventListener('click', closeFloatingMenus)
    window.addEventListener('resize', syncMaskCanvasSize)
})

onBeforeUnmount(() => {
    document.removeEventListener('click', closeFloatingMenus)
    window.removeEventListener('resize', syncMaskCanvasSize)
    if (createTimer.value) clearTimeout(createTimer.value)
    uploadBlobUrls.value.forEach((url) => URL.revokeObjectURL(url))
})
</script>

<style lang="scss" scoped>
.tool-template {
    display: grid;
    grid-template-columns: minmax(320px, 412px) minmax(0, 1fr);
    align-items: stretch;
    gap: 16px;
    width: 100%;
    height: 100%;
    min-height: 0;
    padding-top: 38px;
    box-sizing: border-box;
}

.tool-template__sidebar,
.tool-template__content {
    min-width: 0;
    min-height: 0;
}

.tool-template__sidebar {
    height: 100%;
}

.tool-template-panel,
.tool-template__content {
    position: relative;
    height: 100%;
    min-height: 0;
    border: 1px solid rgba(255, 255, 255, 0.05);
    border-radius: 24px;
    background: rgba(9, 9, 9, 0.96);
    box-shadow:
        0 30px 50px rgba(0, 0, 0, 0.32),
        inset 0 1px 0 rgba(255, 255, 255, 0.02);
    overflow: hidden;
    box-sizing: border-box;
}

.tool-template-panel {
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    height: 100%;
    min-height: 0;
    overflow: hidden;
}

.tool-template-panel__header {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    min-height: 82px;
    padding: 0 24px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    gap: 12px;
}

.tool-template-panel__header h1 {
    margin: 0;
    color: #fff;
    font-size: 18px;
    font-weight: 600;
    line-height: 1.2;
    text-align: center;
}

.tool-template-panel__back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    justify-self: start;
    min-height: 36px;
    padding: 0 12px;
    border: 0;
    border-radius: 10px;
    background: #171717;
    color: rgba(255, 255, 255, 0.78);
    font-size: 14px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        transform 0.2s ease;
}

.tool-template-panel__back:hover {
    background: #202020;
    color: #fff;
    transform: translateY(-1px);
}

.tool-template-panel__back-icon {
    width: 8px;
    height: 8px;
    border-bottom: 1.5px solid currentColor;
    border-left: 1.5px solid currentColor;
    transform: rotate(45deg);
}

.tool-template-panel__header-spacer {
    width: 68px;
    justify-self: end;
}

.tool-template-panel__body {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding: 28px 18px 28px;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scroll-padding-bottom: 124px;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.tool-template-panel__body::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.tool-template-panel__body::-webkit-scrollbar-track {
    background: transparent;
}

.tool-template-panel__body::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.tool-template-panel__body::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tool-template-panel__footer {
    position: sticky;
    bottom: 0;
    z-index: 2;
    flex-shrink: 0;
    padding: 20px 18px 18px;
    border-top: 1px solid rgba(255, 255, 255, 0.06);
    background: rgba(9, 9, 9, 0.98);
    box-shadow: 0 -12px 30px rgba(0, 0, 0, 0.22);
}

.tool-block {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.tool-compare-upload {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.tool-compare-upload__item {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tool-compare-upload__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.tool-compare-upload__label {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1;
}

.tool-mask-clear {
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.56);
    font-size: 12px;
    cursor: pointer;
    transition: color 0.2s ease;
}

.tool-mask-clear:hover {
    color: #fff;
}

.tool-block__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    line-height: 1;
}

.tool-block__head--inline-select {
    justify-content: space-between;
    gap: 12px;
}

.tool-block__tip {
    color: rgba(255, 255, 255, 0.44);
    font-size: 12px;
    font-weight: 400;
}

.tool-block__subhead {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1.5;
}

.tool-upload-card__placeholder,
.tool-upload-card__library,
.tool-upload-card__action,
.tool-resolution button,
.tool-create-button,
.tool-filter-button,
.tool-filter-menu button,
.tool-result-card__preview,
.tool-result-card__gallery-item,
.tool-result-card__actions button {
    border: 0;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease,
        color 0.2s ease,
        opacity 0.2s ease;
}

.tool-upload-card {
    position: relative;
    min-height: 232px;
    padding: 0;
    border-radius: 10px;
    background: #262626;
    overflow: hidden;
}

.tool-upload-card--compact {
    min-height: 184px;
}

.tool-upload-card--light {
    background: rgba(255, 255, 255, 0.03);
}

.tool-upload-card--suite {
    min-height: 216px;
}

.tool-upload-card__placeholder--suite {
    gap: 14px;
}

.tool-upload-card__placeholder--suite span {
    max-width: 360px;
    color: rgba(255, 255, 255, 0.38);
    font-size: 14px;
    line-height: 1.7;
    text-align: center;
}

.tool-upload-card__placeholder--cleanup {
    min-height: 216px;
}

.tool-upload-card__placeholder--cleanup span {
    max-width: 320px;
    font-size: 13px;
    line-height: 1.8;
}

.tool-local-panel {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 184px;
    padding: 0 18px;
    box-sizing: border-box;
}

.tool-local-panel__viewport {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    max-width: 100%;
    height: 100%;
}

.tool-local-panel__image {
    display: block;
    width: auto;
    height: 100%;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.tool-local-panel--mask-preview .tool-local-panel__viewport {
    overflow: hidden;
}

.tool-local-panel--outpaint {
    height: 232px;
}

.tool-local-panel__mask-preview-image {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
    opacity: 0.92;
}

.tool-mask-draw-button {
    position: absolute;
    inset: 0;
    z-index: 2;
    width: 120px;
    height: 40px;
    margin: auto;
    border: 1px solid rgba(255, 255, 255, 0.5);
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.34);
    color: #fff;
    font-size: 14px;
    cursor: pointer;
    backdrop-filter: blur(4px);
    transition:
        background 0.2s ease,
        border-color 0.2s ease,
        transform 0.2s ease;
}

.tool-mask-draw-button:hover {
    background: rgba(0, 0, 0, 0.48);
    border-color: rgba(255, 255, 255, 0.72);
}

.tool-suite-upload {
    padding: 16px;
}

.tool-suite-upload__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
}

.tool-suite-upload__item,
.tool-suite-upload__add {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    aspect-ratio: 1;
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.04);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.05);
}

.tool-suite-upload__item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-suite-upload__badge {
    position: absolute;
    left: 8px;
    bottom: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.58);
    color: #fff;
    font-size: 11px;
    line-height: 1;
}

.tool-suite-upload__remove {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 24px;
    height: 24px;
    border: 0;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.58);
    color: #fff;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
}

.tool-suite-upload__add {
    flex-direction: column;
    gap: 10px;
    border: 1px dashed rgba(255, 255, 255, 0.16);
    background: rgba(255, 255, 255, 0.02);
    color: rgba(255, 255, 255, 0.68);
    font-size: 13px;
}

.tool-suite-upload__add img {
    width: 40px;
    height: 40px;
}

.tool-suite-upload__add:hover,
.tool-suite-upload__remove:hover {
    transform: translateY(-1px);
}

.tool-example-strip {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 12px;
}

.tool-example-strip__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1;
}

.tool-example-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.tool-example-card {
    position: relative;
    display: block;
    width: 100%;
    aspect-ratio: 1;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.tool-example-card:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.14);
    box-shadow: 0 14px 24px rgba(0, 0, 0, 0.18);
}

.tool-example-card.is-active {
    border-color: #fff;
    box-shadow: 0 0 0 1px #fff;
}

.tool-example-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-fitting-head-select {
    position: relative;
    flex: 0 0 auto;
}

.tool-fitting-head-select__button {
    min-height: auto;
    padding: 6px 10px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    background: transparent;
    box-shadow: none;
    justify-content: flex-end;
    gap: 8px;
    transition:
        border-color 0.2s ease,
        color 0.2s ease;
}

.tool-fitting-head-select__button .tool-select-card__value {
    padding-left: 0;
    color: #fff;
    font-size: 15px;
    font-weight: 500;
    line-height: 1;
}

.tool-fitting-head-select__button img {
    width: 14px;
    height: 14px;
    flex: 0 0 14px;
}

.tool-fitting-head-select__button:hover {
    background: transparent;
    box-shadow: none;
    transform: none;
    border-color: rgba(255, 255, 255, 0.24);
}

.tool-fitting-head-select__menu {
    top: calc(100% + 8px);
    left: 0;
    width: 118px;
}

.tool-fitting-upload-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 12px;
}

.tool-fitting-upload-grid.is-split {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.tool-upload-card__placeholder--garment {
    min-height: 192px;
    padding: 20px 20px 28px;
    gap: 16px;
}

.tool-fitting-mode-tabs {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: center;
    gap: 4px;
    padding: 4px;
    margin-top: 2px;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.06);
    min-height: 48px;
    width: 100%;
    margin-bottom: 2px;
}

.tool-fitting-mode-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 40px;
    padding: 0 12px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: rgba(255, 255, 255, 0.76);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        box-shadow 0.2s ease;
}

.tool-fitting-mode-tab:hover {
    color: #fff;
}

.tool-fitting-mode-tab.is-active {
    background: #fff;
    color: #111;
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.12);
}

.tool-fitting-filter-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin-top: 0;
    margin-bottom: 2px;
}

.tool-fitting-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    margin-top: 0;
}

.tool-fitting-card {
    position: relative;
    display: block;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.tool-fitting-card:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.16);
}

.tool-fitting-card.is-active {
    border-color: #fff;
    box-shadow: 0 0 0 1px #fff;
}

.tool-fitting-card > img {
    display: block;
    width: 100%;
    aspect-ratio: 3 / 4;
    object-fit: cover;
}

.tool-fitting-card__vip {
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

.tool-fitting-card__vip img {
    width: 12px;
    height: 12px;
}

.tool-fitting-card__group-badge {
    position: absolute;
    right: 10px;
    bottom: 10px;
    z-index: 2;
    padding: 0 8px;
    min-height: 22px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.64);
    color: #fff;
    font-size: 12px;
    line-height: 22px;
}

.tool-fitting-selected {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 16px;
    padding: 14px;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.02);
}

.tool-fitting-selected__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    color: rgba(255, 255, 255, 0.72);
    font-size: 13px;
    line-height: 1;
}

.tool-fitting-selected__head strong {
    color: #fff;
    font-size: 14px;
    font-weight: 600;
}

.tool-fitting-selected__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
}

.tool-fitting-selected__item {
    aspect-ratio: 1;
    border: 1px dashed rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
}

.tool-fitting-selected__item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-style-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.tool-style-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    flex: 1;
    min-width: 0;
}

.tool-style-toolbar__select {
    width: 132px;
    flex: 0 0 132px;
}

.tool-style-toolbar__select .tool-select-card {
    min-height: 38px;
    padding: 0 4px 0 0;
    border: 0;
    border-radius: 0;
    background: transparent;
    box-shadow: none;
    justify-content: flex-end;
}

.tool-style-toolbar__select .tool-select-card__value {
    padding-left: 10px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: #fff;
}

.tool-style-toolbar__select .tool-select-card--button img {
    width: 14px;
    height: 14px;
}

.tool-style-toolbar__select .tool-select-card:hover {
    border: 0;
    background: transparent;
}

.tool-style-toolbar__menu {
    right: 0;
    left: auto;
    width: 148px;
}

.tool-style-tab {
    min-height: 38px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    color: rgba(255, 255, 255, 0.72);
    font-size: 14px;
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        color 0.2s ease;
}

.tool-style-tab:hover {
    border-color: rgba(255, 255, 255, 0.16);
}

.tool-style-tab.is-active {
    border-color: #fff;
    background: rgba(255, 255, 255, 0.08);
    color: #fff;
}

.tool-style-preset-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.tool-cleanup-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.tool-cleanup-capacity {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 54px;
    padding: 0 14px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
}

.tool-cleanup-capacity__badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 24px;
    padding: 0 8px;
    border-radius: 999px;
    background: linear-gradient(270deg, #ffeec9 0%, #ffde97 100%);
    color: #5c4010;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    flex-shrink: 0;
}

.tool-cleanup-capacity__badge img {
    width: 12px;
    height: 12px;
}

.tool-cleanup-capacity__text {
    flex: 1;
    min-width: 0;
    color: rgba(255, 255, 255, 0.78);
    font-size: 14px;
    line-height: 1.5;
}

.tool-cleanup-capacity__action {
    flex-shrink: 0;
    min-width: 84px;
    height: 34px;
    padding: 0 14px;
    border: 0;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.tool-cleanup-capacity__action:hover {
    background: rgba(255, 255, 255, 0.16);
    transform: translateY(-1px);
}

.tool-cleanup-option {
    padding: 6px;
    gap: 10px;
}

.tool-cleanup-option__image {
    aspect-ratio: 4 / 3;
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.03);
}

.tool-cleanup-option__image img {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-cleanup-option strong {
    padding: 0 6px 4px;
    font-size: 15px;
    text-align: center;
}

.tool-style-preset-card {
    position: relative;
    display: block;
    padding: 6px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.03);
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease,
        background 0.2s ease;
}

.tool-style-preset-card:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.16);
}

.tool-style-preset-card.is-active {
    border-color: #fff;
    background: rgba(255, 255, 255, 0.06);
    box-shadow: 0 0 0 1px #fff;
}

.tool-style-preset-card__vip {
    position: absolute;
    top: 6px;
    left: 6px;
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

.tool-style-preset-card__vip img {
    width: 12px;
    height: 12px;
}

.tool-style-preset-card__image {
    aspect-ratio: 4 / 5;
    border-radius: 12px;
    overflow: hidden;
    background: rgba(255, 255, 255, 0.03);
}

.tool-style-preset-card__image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-size-editor {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.tool-size-input {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr) 42px;
    align-items: center;
    min-height: 48px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    overflow: hidden;
}

.tool-size-input span,
.tool-size-input em {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: rgba(255, 255, 255, 0.76);
    font-size: 14px;
    font-style: normal;
    background: rgba(255, 255, 255, 0.02);
}

.tool-size-input span {
    border-right: 1px solid rgba(255, 255, 255, 0.08);
}

.tool-size-input em {
    border-left: 1px solid rgba(255, 255, 255, 0.08);
}

.tool-size-input input {
    width: 100%;
    min-width: 0;
    padding: 0 12px;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 16px;
    line-height: 1;
}

.tool-mask-editor-mask {
    position: fixed;
    inset: 0;
    z-index: 32;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.62);
    backdrop-filter: blur(10px);
}

.tool-mask-editor {
    display: flex;
    flex-direction: column;
    gap: 18px;
    width: min(1080px, calc(100vw - 48px));
    height: min(820px, calc(100vh - 48px));
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 24px;
    background: rgba(17, 17, 17, 0.98);
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.38);
    box-sizing: border-box;
}

.tool-mask-editor__toolbar,
.tool-mask-editor__footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
}

.tool-mask-editor__tools,
.tool-mask-editor__size,
.tool-mask-editor__zoom {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.tool-mask-editor__tools button,
.tool-mask-editor__size,
.tool-mask-editor__zoom button,
.tool-mask-editor__ghost,
.tool-mask-editor__confirm {
    min-height: 38px;
    padding: 0 16px;
    border: 0;
    border-radius: 10px;
    font-size: 14px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease,
        opacity 0.2s ease,
        transform 0.2s ease;
}

.tool-mask-editor__tools button,
.tool-mask-editor__size,
.tool-mask-editor__zoom {
    background: #1a1a1a;
}

.tool-mask-editor__tools button {
    color: rgba(255, 255, 255, 0.74);
}

.tool-mask-editor__tools button.is-active {
    background: #fff;
    color: #111;
}

.tool-mask-editor__tools button:disabled,
.tool-mask-editor__zoom button:disabled {
    cursor: not-allowed;
    opacity: 0.38;
}

.tool-mask-editor__zoom {
    padding: 0 8px;
}

.tool-mask-editor__zoom button {
    min-width: 34px;
    padding: 0;
    background: transparent;
    color: #fff;
}

.tool-mask-editor__zoom span {
    min-width: 56px;
    color: #fff;
    font-size: 14px;
    text-align: center;
}

.tool-mask-editor__size {
    flex: 1;
    justify-content: center;
    min-width: 180px;
    padding: 0 16px;
    border-radius: 10px;
}

.tool-mask-editor__size span {
    color: rgba(255, 255, 255, 0.74);
    font-size: 13px;
    white-space: nowrap;
}

.tool-mask-editor__size input {
    flex: 1;
    accent-color: #fff;
}

.tool-mask-editor__body {
    display: grid;
    grid-template-columns: 96px minmax(0, 1fr);
    gap: 16px;
    flex: 1;
    min-height: 0;
}

.tool-mask-editor__selections {
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 14px 12px;
    border-radius: 18px;
    background: #151515;
}

.tool-mask-editor__add-selection {
    min-height: 36px;
    padding: 0 10px;
    border: 0;
    border-radius: 10px;
    background: #242424;
    color: #fff;
    font-size: 13px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        transform 0.2s ease;
}

.tool-mask-editor__add-selection:hover {
    background: #2d2d2d;
    transform: translateY(-1px);
}

.tool-mask-editor__selection-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.tool-mask-editor__selection-list::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.tool-mask-editor__selection-list::-webkit-scrollbar-track {
    background: transparent;
}

.tool-mask-editor__selection-list::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.tool-mask-editor__selection-list::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tool-mask-editor__selection {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 44px;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        transform 0.2s ease;
}

.tool-mask-editor__selection span {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.12);
}

.tool-mask-editor__selection.is-active {
    border-color: rgba(255, 255, 255, 0.26);
    background: rgba(255, 255, 255, 0.06);
}

.tool-mask-editor__selection:hover {
    transform: translateY(-1px);
}

.tool-mask-editor__stage {
    position: relative;
    min-height: 0;
    overflow: hidden;
    border-radius: 18px;
    background:
        linear-gradient(0deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.03)),
        #101010;
}

.tool-mask-editor__viewport {
    position: absolute;
    top: 50%;
    left: 50%;
    display: inline-block;
    transform-origin: center;
    will-change: transform;
}

.tool-mask-editor__image {
    display: block;
    width: auto;
    height: auto;
    max-width: min(100%, 920px);
    max-height: min(100%, 620px);
    object-fit: contain;
    user-select: none;
    pointer-events: none;
}

.tool-mask-editor__canvas {
    position: absolute;
    inset: 0;
    touch-action: none;
}

.tool-mask-editor__canvas.is-brush {
    cursor: crosshair;
}

.tool-mask-editor__canvas.is-erase {
    cursor: cell;
}

.tool-mask-editor__canvas.is-pan {
    cursor: grab;
}

.tool-mask-editor__ghost {
    background: #242424;
    color: rgba(255, 255, 255, 0.8);
}

.tool-mask-editor__confirm {
    min-width: 180px;
    background: #fff;
    color: #111;
    font-weight: 600;
}

.tool-mask-editor__ghost:hover,
.tool-mask-editor__confirm:hover {
    transform: translateY(-1px);
}

.tool-upload-card__stage {
    display: grid;
    grid-template-columns: minmax(44px, 1fr) 122px minmax(44px, 1fr);
    align-items: stretch;
    width: 100%;
    min-height: 232px;
}

.tool-upload-card--compact .tool-upload-card__stage {
    min-height: 184px;
}

.tool-upload-card__edge {
    background: linear-gradient(180deg, #303030 0%, #252525 100%);
}

.tool-upload-card__image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.tool-upload-card__placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 18px;
    width: 100%;
    min-height: 232px;
    padding: 20px 28px 34px;
    color: rgba(255, 255, 255, 0.6);
    text-align: center;
    border: 0;
    background: transparent;
    cursor: pointer;
    box-sizing: border-box;
}

.tool-upload-card--compact .tool-upload-card__placeholder {
    min-height: 196px;
}

.tool-upload-card__placeholder img {
    width: 40px;
    height: 40px;
}

.tool-upload-card__placeholder strong {
    color: rgba(255, 255, 255, 0.68);
    font-size: 16px;
    font-weight: 500;
    line-height: 1.3;
}

.tool-upload-card__placeholder--subtle {
    cursor: default;
}

.tool-upload-card__library {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 52px;
    padding: 0 26px;
    border: 0;
    border-radius: 16px;
    background: #343434;
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1;
    opacity: 1;
}

.tool-upload-card__library:hover {
    background: #3c3c3c;
}

.tool-upload-card__actions {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 20px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}

.tool-upload-card__action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-width: 120px;
    min-height: 46px;
    padding: 0 20px;
    border-radius: 14px;
    background: rgba(26, 26, 26, 0.88);
    color: #fff;
    font-size: 15px;
    font-weight: 500;
    line-height: 1;
    backdrop-filter: blur(8px);
    opacity: 1;
}

.tool-upload-card__action:hover {
    background: rgba(0, 0, 0, 0.72);
}

.tool-upload-card__action img {
    width: 18px;
    height: 18px;
    object-fit: contain;
}

.tool-upload-card__action--local img {
    width: 18px;
    height: 18px;
}

.tool-upload-card.is-filled:hover .tool-upload-card__actions {
    opacity: 1;
    pointer-events: auto;
}

.tool-upload-card__library,
.tool-upload-card__action--gallery {
    color: #fff;
}

.tool-upload-card__library span,
.tool-upload-card__action--gallery span {
    color: #fff;
}

.tool-resolution {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.tool-resolution button {
    min-height: 38px;
    border-radius: 8px;
    background: #252525;
    color: rgba(255, 255, 255, 0.78);
    font-size: 14px;
    font-weight: 500;
}

.tool-resolution button.is-active {
    background: #fff;
    color: #191919;
}

.tool-block--note {
    margin-top: 4px;
}

.tool-prompt-card {
    display: flex;
    min-height: 180px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
    box-sizing: border-box;
}

.tool-prompt-card textarea {
    width: 100%;
    min-height: 152px;
    padding: 0;
    border: 0;
    outline: none;
    resize: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    line-height: 1.8;
}

.tool-prompt-card textarea::placeholder {
    color: rgba(255, 255, 255, 0.34);
}

.tool-prompt-card--suite {
    position: relative;
    min-height: 188px;
    padding-bottom: 44px;
}

.tool-prompt-card--video {
    min-height: 216px;
}

.tool-prompt-card--video textarea {
    min-height: 176px;
}

.tool-prompt-card__footer {
    position: absolute;
    right: 16px;
    bottom: 14px;
    left: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    color: rgba(255, 255, 255, 0.42);
    font-size: 13px;
    line-height: 1;
}

.tool-prompt-card__footer button {
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    cursor: pointer;
    transition: color 0.2s ease;
}

.tool-prompt-card__footer button:hover {
    color: #fff;
}

.tool-input-card {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
    min-height: 52px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    box-sizing: border-box;
}

.tool-input-card input {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
}

.tool-input-card input::placeholder {
    color: rgba(255, 255, 255, 0.34);
}

.tool-input-card span {
    color: rgba(255, 255, 255, 0.42);
    font-size: 13px;
    line-height: 1;
}

.tool-outpaint-card {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
}

.tool-outpaint-control {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.tool-outpaint-control__label {
    color: rgba(255, 255, 255, 0.68);
    font-size: 14px;
    line-height: 1;
}

.tool-outpaint-control__main {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 14px;
}

.tool-outpaint-control__main input {
    width: 100%;
    accent-color: #fff;
}

.tool-outpaint-control__main span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 64px;
    min-height: 36px;
    padding: 0 12px;
    border-radius: 10px;
    background: #191919;
    color: #fff;
    font-size: 13px;
    line-height: 1;
    box-sizing: border-box;
}

.tool-option-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.tool-option-grid--triple {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.tool-select-card {
    position: relative;
    display: flex;
    align-items: center;
    min-height: 52px;
    padding: 0 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.03);
    box-sizing: border-box;
}

.tool-select-dropdown {
    position: relative;
}

.tool-select-card--button {
    width: 100%;
    justify-content: space-between;
    cursor: pointer;
}

.tool-select-card__value {
    color: #fff;
    font-size: 14px;
    line-height: 1;
}

.tool-select-card__value.is-placeholder {
    color: rgba(255, 255, 255, 0.34);
}

.tool-select-card--button img {
    width: 16px;
    height: 16px;
    transition: transform 0.2s ease;
}

.tool-select-card--button img.is-open {
    transform: rotate(180deg);
}

.tool-select-card select {
    width: 100%;
    border: 0;
    outline: none;
    background: transparent;
    color: #fff;
    font-size: 14px;
    appearance: none;
    cursor: pointer;
}

.tool-select-card__icon {
    position: absolute;
    top: 50%;
    right: 16px;
    width: 8px;
    height: 8px;
    border-right: 1.5px solid rgba(255, 255, 255, 0.54);
    border-bottom: 1.5px solid rgba(255, 255, 255, 0.54);
    transform: translateY(-70%) rotate(45deg);
    pointer-events: none;
}

.tool-buyer-show-grid {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tool-buyer-show-field {
    display: grid;
    grid-template-columns: 92px minmax(0, 1fr);
    align-items: center;
    gap: 12px;
}

.tool-buyer-show-field__label {
    color: #fff;
    font-size: 14px;
    line-height: 1.4;
}

.tool-option-grid--compact .tool-option-card {
    gap: 6px;
    min-height: 92px;
    padding: 14px 16px;
    justify-content: center;
}

.tool-option-grid--compact .tool-option-card__badge {
    top: 10px;
    right: 10px;
}

.tool-option-card--mini {
    min-height: 78px;
    justify-content: center;
}

.tool-option-card--mini strong {
    font-size: 15px;
}

.tool-option-card--video-ratio {
    display: grid;
    grid-template-rows: 52px 24px 52px;
    justify-items: center;
    align-items: center;
    align-content: stretch;
    gap: 0;
    min-height: 104px;
    padding: 14px 16px 12px;
    text-align: center;
}

.tool-option-card--video-ratio strong {
    display: block;
    width: 100%;
    margin: 0;
    text-align: center;
    line-height: 24px;
}

.tool-option-card--video-ratio > span:last-child {
    display: block;
    width: 100%;
    max-width: 132px;
    margin: 0 auto;
    text-align: center;
    line-height: 1.5;
}

.tool-option-card__ratio-icon {
    position: relative;
    display: grid;
    place-items: center;
    width: 52px;
    height: 52px;
    align-self: center;
    justify-self: center;
}

.tool-option-card__ratio-icon::after {
    content: '';
    display: block;
    border: 2px dashed rgba(255, 255, 255, 0.58);
    border-radius: 8px;
}

.tool-option-card__ratio-icon--9-16::after {
    width: 28px;
    height: 44px;
}

.tool-option-card__ratio-icon--3-4::after {
    width: 32px;
    height: 42px;
}

.tool-option-card__ratio-icon--4-3::after {
    width: 42px;
    height: 32px;
}

.tool-option-card__ratio-icon--16-9::after {
    width: 44px;
    height: 28px;
}

.tool-option-grid--triple .tool-option-card--mini {
    align-items: center;
    min-height: 64px;
    padding: 10px 14px;
    text-align: center;
}

.tool-option-grid--triple .tool-option-card--mini strong {
    width: 100%;
    text-align: center;
}

.tool-option-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 8px;
    min-height: 116px;
    padding: 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.02);
    text-align: left;
    box-sizing: border-box;
}

.tool-option-card strong {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
    line-height: 1.3;
}

.tool-option-card span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 13px;
    line-height: 1.6;
}

.tool-option-card.is-active {
    border-color: rgba(255, 255, 255, 0.24);
    background: rgba(255, 255, 255, 0.06);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.04);
}

.tool-option-card__badge {
    position: absolute;
    top: 12px;
    right: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    background: #ff7a45;
    color: #fff !important;
    font-size: 11px !important;
    line-height: 1;
}

.tool-module-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.tool-module-grid--triple {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.tool-module-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 120px;
    padding: 18px 18px 16px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.035) 0%, rgba(255, 255, 255, 0.018) 100%),
        #111;
    color: rgba(255, 255, 255, 0.74);
    text-align: left;
    box-sizing: border-box;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.02),
        0 10px 26px rgba(0, 0, 0, 0.2);
    transition:
        border-color 0.2s ease,
        background 0.2s ease,
        box-shadow 0.2s ease;
}

.tool-module-card strong {
    color: #fff;
    max-width: calc(100% - 30px);
    font-size: 17px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: 0.01em;
}

.tool-module-card > span:last-child {
    color: rgba(255, 255, 255, 0.6);
    font-size: 13px;
    line-height: 1.7;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.tool-module-card.is-active {
    border-color: rgba(255, 255, 255, 0.2);
    background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.07) 0%, rgba(255, 255, 255, 0.03) 100%),
        #141414;
    box-shadow:
        inset 0 0 0 1px rgba(255, 255, 255, 0.04),
        inset 0 1px 0 rgba(255, 255, 255, 0.06),
        0 14px 34px rgba(0, 0, 0, 0.28);
}

.tool-module-card--compact {
    justify-content: center;
    min-height: 76px;
    padding: 16px 14px 14px;
    gap: 0;
}

.tool-module-card--compact strong {
    max-width: 100%;
    font-size: 15px;
    line-height: 1.2;
}

.tool-module-card--compact > span:last-child {
    display: none;
}

.tool-module-card__order {
    position: absolute;
    top: 14px;
    right: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 24px;
    padding: 0 7px;
    border: 1px solid rgba(255, 255, 255, 0.14);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.06);
    color: rgba(255, 255, 255, 0.86);
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
}

.tool-inline-actions {
    display: inline-flex;
    align-items: center;
    gap: 10px;
}

.tool-inline-actions button {
    min-height: 32px;
    padding: 0 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 999px;
    background: transparent;
    color: rgba(255, 255, 255, 0.78);
    font-size: 12px;
    cursor: pointer;
    transition:
        border-color 0.2s ease,
        color 0.2s ease,
        background 0.2s ease;
}

.tool-inline-actions button:hover {
    border-color: rgba(255, 255, 255, 0.22);
    background: rgba(255, 255, 255, 0.04);
    color: #fff;
}

.tool-prompt-card--tall {
    position: relative;
    min-height: 220px;
    padding-bottom: 44px;
}

.tool-prompt-card--tall textarea {
    min-height: 172px;
}

.tool-prompt-card__clear {
    position: absolute;
    right: 16px;
    bottom: 14px;
    padding: 0;
    border: 0;
    background: transparent;
    color: rgba(255, 255, 255, 0.62);
    font-size: 14px;
    cursor: pointer;
}

.tool-prompt-card__clear:hover {
    color: #fff;
}

.tool-note {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.02);
}

.tool-note strong {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.5;
}

.tool-note span {
    color: rgba(255, 255, 255, 0.5);
    font-size: 12px;
    line-height: 1.5;
}

.tool-create-button {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    width: 100%;
    min-height: 42px;
    border-radius: 8px;
    background: #fff;
    color: #191919;
    font-size: 16px;
    font-weight: 600;
}

.tool-create-button:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

.tool-create-button:not(:disabled):hover {
    transform: translateY(-1px);
}

.tool-create-button__meta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 15px;
    font-weight: 500;
}

.tool-create-button__meta img {
    width: 15px;
    height: 15px;
}

.tool-create-button__spinner {
    width: 15px;
    height: 15px;
    border: 2px solid rgba(0, 0, 0, 0.18);
    border-top-color: #111;
    border-radius: 50%;
    animation: tool-spin 0.7s linear infinite;
}

.tool-template__content {
    display: flex;
    flex-direction: column;
    min-height: 0;
    padding: 18px 18px 24px;
    overflow: hidden;
}

.tool-toolbar {
    position: relative;
    z-index: 2;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    margin-bottom: 18px;
    padding-bottom: 2px;
    background: rgba(9, 9, 9, 0.96);
}

.tool-filter-button {
    display: inline-flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 116px;
    min-height: 38px;
    padding: 0 14px;
    border-radius: 10px;
    background: #222;
    color: #fff;
    font-size: 14px;
}

.tool-filter-button img {
    width: 16px;
    height: 16px;
    transition: transform 0.2s ease;
}

.tool-filter-button img.is-open {
    transform: rotate(180deg);
}

.tool-filter-menu {
    position: absolute;
    top: calc(100% + 12px);
    left: 0;
    z-index: 3;
    display: flex;
    flex-direction: column;
    gap: 6px;
    width: 128px;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    background: #171717;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.tool-filter-menu--select {
    width: 100%;
}

.tool-filter-menu--dropdown {
    max-height: 332px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.tool-filter-menu--dropdown::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.tool-filter-menu--dropdown::-webkit-scrollbar-track {
    background: transparent;
}

.tool-filter-menu--dropdown::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.tool-filter-menu--dropdown::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tool-filter-menu button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 34px;
    padding: 0 10px;
    border-radius: 8px;
    background: transparent;
    color: #fff;
    font-size: 14px;
}

.tool-filter-menu button:hover,
.tool-filter-menu button.is-active {
    background: #252525;
}

.tool-filter-menu__check {
    width: 14px;
    height: 14px;
    opacity: 0;
}

.tool-filter-menu button.is-active .tool-filter-menu__check {
    position: relative;
    opacity: 1;
}

.tool-filter-menu button.is-active .tool-filter-menu__check::before {
    content: '';
    position: absolute;
    inset: 0;
    width: 8px;
    height: 5px;
    margin: auto;
    border-right: 1.5px solid #fff;
    border-bottom: 1.5px solid #fff;
    transform: rotate(45deg) translate(-1px, -1px);
}

.tool-result-list {
    display: flex;
    flex-direction: column;
    gap: 48px;
}

.tool-result-scroll {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.tool-result-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.tool-result-scroll::-webkit-scrollbar-track {
    background: transparent;
}

.tool-result-scroll::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.tool-result-scroll::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tool-result-card {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tool-result-card__title-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
}

.tool-result-card__title-row strong {
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}

.tool-result-card__title-row span:not(.tool-result-card__kind) {
    color: #9d9d9d;
    font-size: 14px;
    line-height: 1;
}

.tool-result-card__kind {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 28px;
    padding: 0 12px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
}

.tool-result-card__kind--video {
    background: rgba(255, 123, 0, 0.14);
    color: #ff8f28;
}

.tool-result-card__kind--image {
    background: rgba(113, 80, 255, 0.18);
    color: #9b82ff;
}

.tool-result-card__kind--tool {
    background: rgba(244, 164, 36, 0.14);
    color: #f4b247;
}

.tool-result-card__prompt {
    position: relative;
    min-height: 72px;
    padding: 12px 16px 12px 108px;
    border-radius: 12px;
    background: #141414;
    box-sizing: border-box;
}

.tool-result-card__thumbs {
    position: absolute;
    top: 12px;
    left: 12px;
    width: 80px;
    height: 60px;
}

.tool-result-card__thumbs img {
    position: absolute;
    top: 0;
    width: 48px;
    height: 60px;
    border-radius: 6px;
    object-fit: cover;
}

.tool-result-card__thumbs img:first-child {
    left: 0;
    z-index: 1;
}

.tool-result-card__thumbs img:last-child {
    left: 30px;
}

.tool-result-card__prompt p {
    display: -webkit-box;
    margin: 0;
    overflow: hidden;
    color: #fff;
    font-size: 14px;
    line-height: 1.7;
    text-overflow: ellipsis;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.tool-result-card__gallery {
    display: grid;
    grid-template-columns: repeat(4, 176px);
    gap: 12px;
    width: max-content;
    max-width: 100%;
}

.tool-result-card__gallery-item {
    width: 176px;
    height: 250px;
    border-radius: 14px;
    background: transparent;
    overflow: hidden;
}

.tool-result-card__gallery-item img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.tool-result-card__gallery-item:hover img {
    transform: scale(1.02);
}

.tool-result-card__preview {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border-radius: 14px;
    background: transparent;
    overflow: hidden;
    box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.04) inset;
}

.tool-result-card__preview--video {
    width: 268px;
    height: 152px;
}

.tool-result-card__preview--image,
.tool-result-card__preview--tool {
    width: 176px;
    height: 250px;
}

.tool-result-card__preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
}

.tool-result-card.is-active .tool-result-card__preview,
.tool-result-card__preview:hover {
    box-shadow:
        0 0 0 1px rgba(255, 255, 255, 0.12) inset,
        0 18px 32px rgba(0, 0, 0, 0.24);
}

.tool-result-card.is-active .tool-result-card__preview img,
.tool-result-card__preview:hover img {
    transform: scale(1.02);
}

.tool-result-card__play {
    position: absolute;
    inset: 0;
    width: 54px;
    height: 54px;
    margin: auto;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.tool-result-card__play-icon {
    position: absolute;
    inset: 0;
    width: 0;
    height: 0;
    margin: auto;
    transform: translateX(2px);
    border-top: 8px solid transparent;
    border-bottom: 8px solid transparent;
    border-left: 13px solid #fff;
}

.tool-result-card__actions {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
}

.tool-result-card__actions button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 0;
    background: transparent;
    color: #a1a1a1;
    font-size: 14px;
}

.tool-result-card__actions button:hover {
    color: #fff;
}

.tool-result-card__actions .tool-result-card__action--delete,
.tool-result-card__actions .tool-result-card__action--delete:hover {
    color: #a1a1a1;
}

.tool-result-card__actions img {
    width: 20px;
    height: 20px;
}

.tool-result-card__actions .tool-result-card__action--delete img {
    opacity: 0.63;
}

.tool-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 420px;
    border: 1px dashed rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
}

.tool-empty strong {
    color: #fff;
    font-size: 18px;
    font-weight: 600;
}

.tool-empty span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 14px;
    line-height: 1.6;
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

.tool-gallery-modal-mask {
    position: fixed;
    inset: 0;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(0, 0, 0, 0.56);
    backdrop-filter: blur(8px);
}

.tool-gallery-modal {
    width: min(920px, calc(100vw - 48px));
    height: min(760px, calc(100vh - 48px));
    padding: 22px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 22px;
    background: #111;
    box-shadow: 0 30px 80px rgba(0, 0, 0, 0.4);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-sizing: border-box;
}

.tool-gallery-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-shrink: 0;
    gap: 20px;
    margin-bottom: 20px;
}

.tool-gallery-modal__title {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tool-gallery-modal__title strong {
    color: #fff;
    font-size: 22px;
    font-weight: 600;
    line-height: 1.2;
}

.tool-gallery-modal__title span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 14px;
}

.tool-gallery-modal__close {
    position: relative;
    width: 36px;
    height: 36px;
    padding: 0;
    border: 0;
    border-radius: 10px;
    background: #1f1f1f;
    cursor: pointer;
}

.tool-gallery-modal__close span {
    position: absolute;
    inset: 0;
    width: 16px;
    height: 1.5px;
    margin: auto;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.tool-gallery-modal__close span:first-child {
    transform: rotate(45deg);
}

.tool-gallery-modal__close span:last-child {
    transform: rotate(-45deg);
}

.tool-gallery-modal__grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    grid-auto-rows: 238px;
    flex: 1;
    min-height: 0;
    gap: 16px;
    align-content: start;
    overflow-y: auto;
    overflow-x: hidden;
    overscroll-behavior: contain;
    scrollbar-width: thin;
    scrollbar-color: #242424 transparent;
}

.tool-gallery-modal__grid::-webkit-scrollbar {
    width: 6px;
    height: 6px;
    background: transparent;
}

.tool-gallery-modal__grid::-webkit-scrollbar-track {
    background: transparent;
}

.tool-gallery-modal__grid::-webkit-scrollbar-thumb {
    border-radius: 999px;
    background: #242424;
}

.tool-gallery-modal__grid::-webkit-scrollbar-thumb:hover {
    background: #242424;
}

.tool-gallery-modal__tabs {
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
    gap: 10px;
    margin-bottom: 18px;
}

.tool-gallery-modal__tabs button {
    min-height: 34px;
    padding: 0 16px;
    border: 0;
    border-radius: 999px;
    background: #1e1e1e;
    color: rgba(255, 255, 255, 0.66);
    font-size: 14px;
    cursor: pointer;
    transition:
        background 0.2s ease,
        color 0.2s ease;
}

.tool-gallery-modal__tabs button.is-active {
    background: #fff;
    color: #111;
}

.tool-gallery-card {
    position: relative;
    display: block;
    width: 100%;
    height: 100%;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.04);
    border-radius: 16px;
    background: #1a1a1a;
    overflow: hidden;
    cursor: pointer;
    transition:
        transform 0.2s ease,
        border-color 0.2s ease,
        box-shadow 0.2s ease;
}

.tool-gallery-card:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 255, 255, 0.14);
    box-shadow: 0 18px 28px rgba(0, 0, 0, 0.22);
}

.tool-gallery-card img,
.tool-gallery-card__shade {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}

.tool-gallery-card img {
    object-fit: cover;
}

.tool-gallery-card__shade {
    background: linear-gradient(180deg, rgba(0, 0, 0, 0) 46%, rgba(0, 0, 0, 0.78) 100%);
}

.tool-gallery-card__meta {
    position: absolute;
    right: 12px;
    bottom: 12px;
    left: 12px;
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
    align-items: flex-start;
}

.tool-gallery-card__badge {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 0 8px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.42);
    color: rgba(255, 255, 255, 0.84);
    font-size: 11px;
    line-height: 1;
}

.tool-gallery-card__meta strong {
    color: #fff;
    font-size: 14px;
    font-weight: 500;
    line-height: 1.4;
}

.tool-gallery-card__meta span {
    color: rgba(255, 255, 255, 0.58);
    font-size: 12px;
}

.tool-gallery-modal__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 220px;
    grid-column: 1 / -1;
    border: 1px dashed rgba(255, 255, 255, 0.08);
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.02);
    text-align: center;
}

.tool-gallery-modal__empty strong {
    color: #fff;
    font-size: 16px;
    font-weight: 600;
}

.tool-gallery-modal__empty span {
    color: rgba(255, 255, 255, 0.54);
    font-size: 13px;
    line-height: 1.6;
}

@keyframes tool-spin {
    to {
        transform: rotate(360deg);
    }
}

@media (max-width: 1520px) {
    .tool-result-card__gallery {
        grid-template-columns: repeat(2, 176px);
    }
}

@media (max-width: 1200px) {
    .tool-template {
        grid-template-columns: 1fr;
        height: auto;
    }

    .tool-template__sidebar {
        position: static;
    }

    .tool-template-panel,
    .tool-template__content {
        min-height: auto;
        height: auto;
    }

    .tool-template__content {
        overflow: visible;
    }

    .tool-result-scroll {
        overflow: visible;
    }

    .tool-template-panel__body {
        scroll-padding-bottom: 24px;
    }

    .tool-template-panel__footer {
        position: relative;
        bottom: auto;
    }
}

@media (max-width: 820px) {
    .tool-template-panel__body,
    .tool-template-panel__footer,
    .tool-template__content {
        padding-left: 16px;
        padding-right: 16px;
    }

    .tool-upload-card__stage {
        grid-template-columns: minmax(30px, 1fr) 122px minmax(30px, 1fr);
    }

    .tool-result-card__gallery {
        grid-template-columns: repeat(2, minmax(0, 176px));
        width: 100%;
    }

    .tool-option-grid {
        grid-template-columns: 1fr;
    }

    .tool-module-grid {
        grid-template-columns: 1fr;
    }

    .tool-suite-upload__grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .tool-inline-actions {
        flex-wrap: wrap;
        justify-content: flex-end;
    }
}

@media (max-width: 680px) {
    .tool-template {
        padding-top: 28px;
    }

    .tool-template-panel__header {
        min-height: 70px;
        padding: 0 16px;
    }

    .tool-template-panel__header h1 {
        font-size: 16px;
    }

    .tool-template-panel__back {
        min-height: 32px;
        padding: 0 10px;
        font-size: 13px;
    }

    .tool-template-panel__header-spacer {
        width: 56px;
    }

    .tool-upload-card__meta {
        right: 12px;
        left: 12px;
    }

    .tool-result-card__prompt {
        padding: 88px 14px 14px;
    }

    .tool-result-card__thumbs {
        top: 14px;
        left: 14px;
    }

    .tool-result-card__gallery {
        grid-template-columns: 1fr;
    }

    .tool-result-card__gallery-item {
        width: 100%;
        max-width: 176px;
    }

    .tool-gallery-modal-mask {
        padding: 16px;
    }

    .tool-gallery-modal {
        width: 100%;
        height: calc(100vh - 32px);
        padding: 18px 16px;
    }

    .tool-gallery-modal__title strong {
        font-size: 18px;
    }

    .tool-gallery-modal__grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        grid-auto-rows: 224px;
    }

    .tool-mask-editor-mask {
        padding: 16px;
    }

    .tool-mask-editor {
        width: 100%;
        height: calc(100vh - 32px);
        padding: 18px 16px;
    }

    .tool-mask-editor__toolbar,
    .tool-mask-editor__footer {
        flex-direction: column;
        align-items: stretch;
    }

    .tool-mask-editor__tools,
    .tool-mask-editor__size,
    .tool-mask-editor__zoom {
        flex-wrap: wrap;
        justify-content: center;
    }

    .tool-mask-editor__body {
        grid-template-columns: 1fr;
    }

    .tool-mask-editor__selections {
        flex-direction: row;
        align-items: center;
        overflow-x: auto;
    }

    .tool-mask-editor__selection-list {
        flex-direction: row;
    }

    .tool-mask-editor__selection {
        width: 44px;
        min-width: 44px;
    }

    .tool-mask-editor__confirm,
    .tool-mask-editor__ghost {
        width: 100%;
    }
}
</style>
