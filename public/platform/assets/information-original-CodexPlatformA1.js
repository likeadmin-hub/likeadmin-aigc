import { _ as FooterBtns } from "./index-gzTQtgfA.js";
import {
  D as ElInput,
  F as ElFormItem,
  r as ElCard,
  G as ElForm,
  g as ElButton
} from "./vendor-element-plus-BZpdRwuY.js";
import { _ as MaterialPicker } from "./picker-CaCIrFNj.js";
import { a as getWebsite, b as setWebsite } from "./website-CFwkauZ0.js";
import { u as useAppStore } from "./index-DH0WGi9f.js";
import {
  d as defineComponent,
  h,
  K as withDirectives,
  $ as resolveDirective
} from "./vendor-_vue_runtime-core-Df2LCT_I.js";
import { r as ref, n as reactive } from "./vendor-_vue_reactivity-CQZkEpgR.js";
import "./vendor-_vue_shared-Bz5GOffk.js";
import "./vendor-_vue_runtime-dom-DlmquEGq.js";
import "./vendor-lodash-es-R-kYiKr_.js";
import "./vendor-async-validator-DKvM95Vc.js";
import "./vendor-_element-plus_icons-vue-BvDs9mMA.js";
import "./vendor-dayjs-SXAs-Ox6.js";
import "./vendor-balanced-match-mNcR6oh4.js";
import "./vendor-_ctrl_tinycolor-r5W6hzzQ.js";
import "./vendor-normalize-wheel-es-B6fDCfyv.js";
import "./vendor-_popperjs_core-D9SI2xQl.js";
import "./index-D5gjpuym.js";
import "./index-J-I6P1Sv.js";
import "./index.vue_vue_type_script_setup_true_lang-H8tcUH0p.js";
import "./index-B4q66Eoh.js";
import "./vendor-_vueuse_core-CxwTizjQ.js";
import "./vendor-_vueuse_shared-CRTL9u47.js";
import "./usePaging-D7Jg4EjD.js";
import "./vendor-vue3-video-play-BwvMDasD.js";
import "./vendor-vuedraggable-uK4RHlaJ.js";
import "./vendor-vue-SGSxVfJ-.js";
import "./vendor-sortablejs-1OKMIj5G.js";
import "./vendor-nprogress-CW2cLFt9.js";
import "./vendor-vue-router-BM_Mix3u.js";
import "./vendor-pinia-BnQZpaZJ.js";
import "./vendor-axios-C-n2IhIP.js";
import "./vendor-lodash-BQwu6iy4.js";
import "./vendor-css-color-function-B0yO34nQ.js";
import "./vendor-color-c-33OWuN.js";
import "./vendor-clone-CuIhj1wH.js";
import "./vendor-color-convert-D5tZdzG8.js";
import "./vendor-color-name-BQ5IbGbl.js";
import "./vendor-color-string-V9XmO5jg.js";
import "./vendor-ms-CzQ2E3wO.js";
import "./vendor-vue-clipboard3-GJXSbADw.js";
import "./vendor-clipboard-DFQUW9Zs.js";
import "./vendor-echarts-Bq-pfw57.js";
import "./vendor-tslib-BDyQ-Jie.js";
import "./vendor-zrender-DycNMATP.js";
import "./vendor-highlight_js-1XUejKht.js";
import "./vendor-_highlightjs_vue-plugin-gY1QV4Xb.js";

const emptyCopyrightRow = () => ({ key: "", value: "" });

const normalizeCopyrightRow = (row) => {
  if (typeof row === "string") {
    return { key: row.trim(), value: "" };
  }
  if (!row || typeof row !== "object") {
    return emptyCopyrightRow();
  }
  return {
    key: String(row.key ?? "").trim(),
    value: String(row.value ?? "").trim()
  };
};

const normalizeCopyrightConfig = (value, keepEmptyRow = true) => {
  let source = value;
  if (typeof source === "string") {
    try {
      source = JSON.parse(source);
    } catch (error) {
      source = [{ key: source, value: "" }];
    }
  }

  const rows = (Array.isArray(source) ? source : source ? [source] : [])
    .map(normalizeCopyrightRow)
    .filter((row) => row.key !== "" || row.value !== "");
  const singleRow = rows.slice(0, 1);

  return singleRow.length || !keepEmptyRow ? singleRow : [emptyCopyrightRow()];
};

const defaultWebsiteData = () => ({
  name: "",
  web_favicon: "",
  web_logo_light: "",
  web_logo_dark: "",
  login_image: "",
  point_unit: "算力",
  copyright_config: [emptyCopyrightRow()]
});

const normalizeWebsiteData = (data = {}) => {
  const defaults = defaultWebsiteData();
  return {
    ...defaults,
    ...data,
    point_unit: String(data.point_unit ?? defaults.point_unit).trim() || defaults.point_unit,
    copyright_config: normalizeCopyrightConfig(data.copyright_config)
  };
};

const formRules = {
  name: [{ required: true, message: "请输入网站名称", trigger: ["blur"] }],
  web_favicon: [{ required: true, message: "请选择网站图标", trigger: ["change"] }],
  web_logo_light: [{ required: true, message: "请选择亮色主题LOGO", trigger: ["change"] }],
  web_logo_dark: [{ required: true, message: "请选择暗色主题LOGO", trigger: ["change"] }],
  login_image: [{ required: true, message: "请选择登录页配图", trigger: ["change"] }]
};

const WebInformation = defineComponent({
  name: "webInformation",
  setup() {
    const formRef = ref();
    const appStore = useAppStore();
    const formData = reactive(defaultWebsiteData());

    const loadWebsite = async () => {
      Object.assign(formData, normalizeWebsiteData(await getWebsite()));
    };

    const ensureCopyrightRows = () => {
      if (!Array.isArray(formData.copyright_config)) {
        formData.copyright_config = normalizeCopyrightConfig(formData.copyright_config);
      }
      return formData.copyright_config;
    };

    const saveWebsite = async () => {
      await formRef.value?.validate();
      const copyrightConfig = normalizeCopyrightConfig(formData.copyright_config, false);
      await setWebsite({
        ...formData,
        copyright_config: copyrightConfig
      });
      formData.copyright_config = copyrightConfig.length ? copyrightConfig : [emptyCopyrightRow()];
      appStore.getConfig();
      await loadWebsite();
    };

    const input = (target, field, props = {}, trim = false) =>
      h(ElInput, {
        modelValue: target[field] ?? "",
        "onUpdate:modelValue": (value) => {
          target[field] = trim ? String(value).trim() : value;
        },
        ...props
      });

    const imagePicker = (field) =>
      h(MaterialPicker, {
        modelValue: formData[field],
        "onUpdate:modelValue": (value) => {
          formData[field] = value;
        },
        limit: 1
      });

    const formItem = (label, children, props = {}) =>
      h(ElFormItem, { label, ...props }, { default: () => children });

    const renderCopyrightRow = () => {
      const row = ensureCopyrightRows()[0];
      return h("div", { class: "py-4 px-4 mb-4 bg-fill-lighter" }, [
        formItem("显示名称", [
          h("div", { class: "w-80" }, [
            input(row, "key", { placeholder: "请输入名称" })
          ])
        ]),
        formItem("跳转链接", [
          h("div", { class: "w-80" }, [
            input(row, "value", { placeholder: "请输入链接，例如：http://www.beian.gov.cn" }),
            h("div", { class: "form-tips" }, "跳转链接不设置，则不跳转")
          ])
        ])
      ]);
    };

    loadWebsite();

    return () => {
      const perms = resolveDirective("perms");
      return h("div", { class: "website-information", style: { paddingBottom: "96px" } }, [
        h(
          ElForm,
          {
            ref: formRef,
            rules: formRules,
            class: "ls-form",
            model: formData,
            "label-width": "120px",
            "scroll-to-error": true
          },
          {
            default: () => [
              h(
                ElCard,
                { shadow: "never", class: "!border-none" },
                {
                  default: () => [
                    h("div", { class: "text-xl font-medium mb-[20px]" }, "后台设置"),
                    formItem(
                      "平台名称",
                      [
                        h("div", { class: "w-80" }, [
                          input(
                            formData,
                            "name",
                            {
                              placeholder: "请输入网站名称",
                              maxlength: "30",
                              "show-word-limit": true
                            },
                            true
                          )
                        ])
                      ],
                      { prop: "name" }
                    ),
                    formItem(
                      "平台网站图标",
                      [
                        h("div", null, [
                          imagePicker("web_favicon"),
                          h("div", { class: "form-tips" }, "建议尺寸：100*100像素，支持jpg，jpeg，png格式")
                        ])
                      ],
                      { prop: "web_favicon", required: true }
                    ),
                    formItem(
                      "亮色主题LOGO",
                      [
                        h("div", null, [
                          imagePicker("web_logo_light"),
                          h(
                            "div",
                            { class: "form-tips" },
                            "建议尺寸：100*100像素，支持jpg，jpeg，png格式，将在后台亮色主题下显示"
                          )
                        ])
                      ],
                      { prop: "web_logo_light", required: true }
                    ),
                    formItem(
                      "暗色主题LOGO",
                      [
                        h("div", null, [
                          imagePicker("web_logo_dark"),
                          h(
                            "div",
                            { class: "form-tips" },
                            "建议尺寸：100*100像素，支持jpg，jpeg，png格式，将在后台暗色主题下显示"
                          )
                        ])
                      ],
                      { prop: "web_logo_dark", required: true }
                    ),
                    formItem(
                      "登录页配图",
                      [
                        h("div", null, [
                          imagePicker("login_image"),
                          h("div", { class: "form-tips" }, "建议尺寸：100*100像素，支持jpg，jpeg，png格式")
                        ])
                      ],
                      { prop: "login_image", required: true }
                    ),
                    formItem(
                      "计费单位名称",
                      [
                        h("div", { class: "w-80" }, [
                          input(
                            formData,
                            "point_unit",
                            {
                              placeholder: "请输入计费单位名称",
                              maxlength: "12",
                              "show-word-limit": true
                            },
                            true
                          ),
                          h("div", { class: "form-tips" }, "用于平台端显示租户余额、充值和消耗单位，默认算力")
                        ])
                      ],
                      { prop: "point_unit" }
                    ),
                    h("div", { class: "text-xl font-medium mt-[32px] mb-[16px]" }, "底部版权信息"),
                    renderCopyrightRow()
                  ]
                }
              )
            ]
          }
        ),
        withDirectives(
          h(
            FooterBtns,
            null,
            {
              default: () => [
                h(
                  ElButton,
                  { type: "primary", onClick: saveWebsite },
                  { default: () => "保存" }
                )
              ]
            }
          ),
          [[perms, ["setting.web.web_setting/setWebsite"]]]
        )
      ]);
    };
  }
});

export { WebInformation as default };
