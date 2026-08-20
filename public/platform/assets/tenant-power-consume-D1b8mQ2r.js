import { D as ElInput, F as ElFormItem, g as ElButton, G as ElForm, r as ElCard, J as ElTableColumn, K as ElTable, P as ElDescriptionsItem, Q as ElDescriptions, A as ElDialog } from "./vendor-element-plus-BZpdRwuY.js";
import { _ as Pagination } from "./index.vue_vue_type_script_setup_true_lang-H8tcUH0p.js";
import { d as defineComponent, j as h, g as onMounted } from "./vendor-_vue_runtime-core-Df2LCT_I.js";
import { n as reactive, r as ref } from "./vendor-_vue_reactivity-CQZkEpgR.js";
import { u as usePaging } from "./usePaging-D7Jg4EjD.js";
import { r as request } from "./index-DH0WGi9f.js";

const fetchTenantConsume = (params) => request.get({
  url: "/tenant.power_consume/lists",
  params,
}, { ignoreCancelToken: true });

const text = (value, fallback = "-") => value === null || value === undefined || value === "" ? fallback : String(value);
const field = (label, value, width = "180px", placeholder = "") => h(ElFormItem, { label }, {
  default: () => [h(ElInput, {
    modelValue: value.get(),
    "onUpdate:modelValue": value.set,
    clearable: true,
    style: { width },
    placeholder,
    onKeyup: (event) => event.key === "Enter" && value.submit(),
  })],
});

export default defineComponent({
  name: "platformTenantPowerConsume",
  setup() {
    const params = reactive({
      tenant_info: "",
      keyword: "",
      app_code: "",
      task_id: "",
      source_sn: "",
      log_sn: "",
      min_amount: "",
      max_amount: "",
      start_time: "",
      end_time: "",
    });
    const detailVisible = ref(false);
    const detail = ref(null);
    const { pager, getLists, resetPage, resetParams } = usePaging({
      fetchFun: fetchTenantConsume,
      params,
      firstLoading: true,
    });
    const model = (key) => ({
      get: () => params[key],
      set: (value) => { params[key] = value; },
      submit: resetPage,
    });
    const showDetail = (row) => {
      detail.value = row;
      detailVisible.value = true;
    };
    const summary = () => pager.extend?.summary || {};
    const unit = () => pager.extend?.point_unit || "算力";

    onMounted(getLists);

    const stat = (label, amount, note, primary = false, suffix = unit()) => h("div", {
      class: primary ? "flex-1 border border-primary rounded px-5 py-4 bg-primary-light-9" : "flex-1 border border-br rounded px-5 py-4",
      style: { minWidth: "220px" },
    }, [
      h("div", { class: "text-tx-secondary text-sm mb-2" }, label),
      h("strong", { class: "text-2xl text-tx-primary" }, `${text(amount, "0.00")}${suffix ? ` ${suffix}` : ""}`),
      h("div", { class: "text-xs text-tx-secondary mt-2" }, note),
    ]);

    const table = () => h(ElCard, { class: "!border-none mt-4", shadow: "never" }, {
      default: () => [
        h(ElTable, { data: pager.lists, size: "large" }, {
          default: () => [
            h(ElTableColumn, {
              label: "序号",
              type: "index",
              index: (index) => (Number(pager.page) - 1) * Number(pager.size) + index + 1,
              width: "80",
              fixed: "left",
            }),
            h(ElTableColumn, { label: "消耗时间", prop: "create_time_text", minWidth: "170" }),
            h(ElTableColumn, { label: "租户", minWidth: "180" }, {
              default: ({ row }) => h("div", {}, [
                h("div", { class: "font-medium" }, text(row.tenant_name || row.tenant_sn)),
                h("div", { class: "text-xs text-tx-secondary mt-1" }, text(row.tenant_sn)),
              ]),
            }),
            h(ElTableColumn, { label: "应用/任务", minWidth: "210" }, {
              default: ({ row }) => h("div", {}, [
                h("div", {}, text(row.app_name)),
                h("div", { class: "text-xs text-tx-secondary mt-1" }, [
                  row.app_code ? row.app_code : "",
                  row.extra?.task_id ? `  任务ID: ${row.extra.task_id}` : "",
                ]),
              ]),
            }),
            h(ElTableColumn, { label: "来源", minWidth: "220" }, {
              default: ({ row }) => h("div", {}, [
                h("div", {}, text(row.remark)),
                h("div", { class: "text-xs text-tx-secondary mt-1" }, text(row.source_sn)),
              ]),
            }),
            h(ElTableColumn, { label: `租户成本(${unit()})`, minWidth: "145" }, {
              default: ({ row }) => h("span", { class: "text-error font-medium" }, text(row.change_amount_text)),
            }),
            h(ElTableColumn, { label: "扣减后余额", prop: "left_amount_text", minWidth: "145" }),
            h(ElTableColumn, { label: `C端扣费(${unit()})`, minWidth: "135" }, {
              default: ({ row }) => h("span", {}, text(row.user_change_amount_text)),
            }),
            h(ElTableColumn, { label: "成本来源", prop: "price_source_text", minWidth: "155" }),
            h(ElTableColumn, { label: "流水编号", prop: "sn", minWidth: "190" }),
            h(ElTableColumn, { label: "操作", width: "80", fixed: "right" }, {
              default: ({ row }) => h(ElButton, { type: "primary", link: true, onClick: () => showDetail(row) }, { default: () => "详情" }),
            }),
          ],
        }),
        h("div", { class: "flex justify-end mt-4" }, [
          h(Pagination, {
            modelValue: pager,
            "onUpdate:modelValue": (value) => Object.assign(pager, value),
            onChange: getLists,
          }),
        ]),
      ],
    });

    const dialog = () => h(ElDialog, {
      modelValue: detailVisible.value,
      "onUpdate:modelValue": (value) => { detailVisible.value = value; },
      title: "租户算力消耗详情",
      width: "760px",
    }, {
      default: () => detail.value ? [
        h("div", { class: "flex gap-3 mb-5" }, [
          stat("租户成本", detail.value.change_amount_text, "按总平台给租户配置的成本规则扣减", true, ""),
          stat("C端用户扣费", detail.value.user_change_amount_text || "-", "仅用于核对，不参与租户成本核算", false, ""),
        ]),
        h(ElDescriptions, { column: 2, border: true }, {
          default: () => (detail.value.source_detail_items || []).map((item) => h(ElDescriptionsItem, {
            key: item.label,
            label: item.label,
          }, { default: () => text(item.value) })),
        }),
      ] : [],
      footer: () => h(ElButton, { type: "primary", onClick: () => { detailVisible.value = false; } }, { default: () => "关闭" }),
    });

    return () => h("div", {}, [
      h(ElCard, { class: "!border-none", shadow: "never" }, {
        header: () => h("div", { class: "flex items-center justify-between" }, [
          h("div", {}, [
            h("div", { class: "text-lg font-medium" }, "租户算力消耗明细"),
            h("div", { class: "text-sm text-tx-secondary mt-1" }, "记录总平台按租户成本规则核算的每一笔算力扣减"),
          ]),
          h(ElButton, { type: "primary", onClick: resetPage, loading: pager.loading }, { default: () => "刷新" }),
        ]),
        default: () => [
          h("div", { class: "flex flex-wrap gap-3 mb-5" }, [
            stat("累计租户成本", summary().total_amount, `${summary().total_count || 0} 笔`, true),
            stat("今日租户成本", summary().today_amount, `${summary().today_count || 0} 笔`),
            stat("产生消耗的租户", summary().tenant_count, "有成本消耗记录", false, "个"),
          ]),
          h(ElForm, { model: params, inline: true }, {
            default: () => [
              field("租户", model("tenant_info"), "210px", "租户名称/编号/手机号/域名"),
              field("关键字", model("keyword"), "220px", "流水/来源/备注/用户"),
              field("应用编码", model("app_code"), "165px", "如 aigc_image"),
              field("任务ID", model("task_id"), "130px", "任务ID"),
              field("来源单号", model("source_sn"), "190px", "来源单号"),
              field("流水编号", model("log_sn"), "190px", "流水编号"),
              h(ElFormItem, { label: "消耗范围" }, {
                default: () => h("div", { class: "flex items-center gap-2", style: { width: "230px" } }, [
                  h(ElInput, { modelValue: params.min_amount, "onUpdate:modelValue": (v) => { params.min_amount = v; }, placeholder: "最小", clearable: true }),
                  h("span", {}, "-"),
                  h(ElInput, { modelValue: params.max_amount, "onUpdate:modelValue": (v) => { params.max_amount = v; }, placeholder: "最大", clearable: true }),
                ]),
              }),
              field("开始时间", model("start_time"), "165px", "YYYY-MM-DD"),
              field("结束时间", model("end_time"), "165px", "YYYY-MM-DD"),
              h(ElFormItem, {}, {
                default: () => [
                  h(ElButton, { type: "primary", onClick: resetPage }, { default: () => "查询" }),
                  h(ElButton, { onClick: resetParams }, { default: () => "重置" }),
                ],
              }),
            ],
          }),
        ],
      }),
      table(),
      dialog(),
    ]);
  },
});
