# 快速开始

开放调用使用当前站点账户的会员资格与积分。站点管理员需先开通开放调用，并发布对应接口。可调用范围以控制台的能力目录为准。

## 创建 API Key

在 [API 密钥](/user/api-keys) 创建密钥，配置权限、有效期、请求限制和消费上限。完整密钥只展示一次，复制后保存在服务端环境变量。密钥不得放入公开仓库、网页前端代码或日志。

## 接入地址

Base URL 为当前站点的地址，业务入口为 `/api/v1`。商城接口保留已发布上游协议的路径、参数和响应；更换 Base URL 和 API Key 后即可接入该协议。只有能力目录明确标注兼容的接口才能使用对应上游 SDK。

```bash
export OPEN_BASE_URL="https://your-site.example"
export OPEN_API_KEY="your-api-key"
curl "$OPEN_BASE_URL/api/v1/tools" \
  -H "Authorization: Bearer $OPEN_API_KEY"
```

## 选择并调用能力

在 [能力目录](/user/developer/catalog) 选择可用且已发布的接口。查看模型、模板、输入素材、参数限制和价格，再使用对应 API 参考页的请求示例。工具的能力查询和估价是独立操作，收费情况以各操作声明为准。

[在线调试](/user/developer/playground) 使用你的密钥、会员资格和积分；生成请求会真实扣款。密钥只在当前页面内存中使用。

## 追踪结果和账单

每次开放调用返回 `X-Request-Id`。工具 JSON 响应同时包含 `request_id`。在 [调用记录](/user/developer/requests) 使用该 ID 排查请求，在 [异步任务](/user/developer/tasks) 查看任务结果，在 [用量与账单](/user/developer/usage) 核对实际费用。
