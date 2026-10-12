-- Default guides are global and immutable; existing tenant or platform content is never overwritten.
INSERT IGNORE INTO `la_open_platform_document` (`tenant_id`,`slug`,`version`,`title`,`category`,`status`,`content`,`contract_code`,`sort`,`create_time`,`update_time`) VALUES
(0,'open-platform-quickstart',1,'快速开始','开发指南','published','# 快速开始

开放调用使用当前站点账户的会员资格与积分。站点管理员需先开通开放调用，并发布对应接口。可调用范围以控制台的能力目录为准。

## 创建 API Key

在 [API 密钥](/user/api-keys) 创建密钥，配置权限、有效期、请求限制和消费上限。完整密钥只展示一次，复制后保存在服务端环境变量。密钥不得放入公开仓库、网页前端代码或日志。

## 接入地址

Base URL 为当前站点的地址，业务入口为 `/api/v1`。商城接口保留已发布上游协议的路径、参数和响应；更换 Base URL 和 API Key 后即可接入该协议。只有能力目录明确标注兼容的接口才能使用对应上游 SDK。

```bash
export OPEN_BASE_URL="https://your-site.example"
export OPEN_API_KEY="your-api-key"
curl "$OPEN_BASE_URL/api/v1/tools" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

## 选择并调用能力

在 [能力目录](/user/developer/catalog) 选择可用且已发布的接口。查看模型、模板、输入素材、参数限制和价格，再使用对应 API 参考页的请求示例。工具的能力查询和估价是独立操作，收费情况以各操作声明为准。

[在线调试](/user/developer/playground) 使用你的密钥、会员资格和积分；生成请求会真实扣款。密钥只在当前页面内存中使用。

## 追踪结果和账单

每次开放调用返回 `X-Request-Id`。工具 JSON 响应同时包含 `request_id`。在 [调用记录](/user/developer/requests) 使用该 ID 排查请求，在 [异步任务](/user/developer/tasks) 查看任务结果，在 [用量与账单](/user/developer/usage) 核对实际费用。
','',0,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'open-platform-auth-limits',1,'鉴权、权限和限额','开发指南','published','# 鉴权、权限和限额

## 请求鉴权

```http
Authorization: Bearer YOUR_API_KEY
```

密钥固定绑定创建者和本站租户。请求不能指定其他用户、租户、执行器、上游地址、渠道密钥或价格。

实际权限取平台开放范围、租户开通、应用安装与上架、用户会员资格和密钥范围的交集。密钥不建立独立钱包。多个密钥共用当前账户积分和账户并发保障。

## 密钥管理

支持命名、编辑权限、有效期、IP 白名单、消费上限、禁用、撤销和轮换。撤销后的密钥不能再鉴权。轮换后使用新密钥仍可访问本人历史任务，但新密钥必须具有相应能力权限。

权限范围留空表示继承当前可用权限；填写工具编码限制到对应工具，填写商城产品 ID 限制到对应产品，`market` 表示继承可用商城权限。IP 白名单支持单个 IPv4、IPv6 和 CIDR 网段。

## 请求限制

请求频率按租户和密钥统计；查询、错误请求及幂等重放也计入请求频率。密钥限制只能在租户和平台限制内生效。计费调用另外受账户及租户并发、余额、每日和每月消费限额限制。

缓存服务异常不会取消限额保护。遇到限流时等待后再查询或重放原请求，不要使用新幂等键重复提交。

## 错误与排查

| 状态码 | 含义 | 处理方式 |
| --- | --- | --- |
| 400 | 参数或业务提交被拒绝 | 检查当前接口文档及模型资格 |
| 401 | 密钥无效、过期、禁用或撤销 | 检查密钥状态 |
| 402 | 积分、消费额度或结算边界不足 | 查看余额、限额及调用记录 |
| 403 | 租户、应用、会员或权限不可用 | 联系本站管理员或检查权限 |
| 404 | 接口、任务或素材不可访问 | 确认能力路径与资源归属 |
| 409 | 幂等冲突或原请求仍处理中 | 查询原请求，勿另建键重发 |
| 429 | 频率或并发限制 | 等待后重试原请求 |
| 502 / 503 | 上游或平台暂不可用 | 先核对受理状态及请求 ID |

商城鉴权和限额错误遵循对应已发布协议的错误结构；工具错误返回 `request_id` 和 `error`。
','',10,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'open-platform-tasks-billing',1,'任务、幂等和计费','开发指南','published','# 任务、幂等和计费

## 幂等提交

计费提交建议携带 `Idempotency-Key`，长度不超过 200 个字符。作用域为同一租户、用户、方法与接口路径。相同键和相同参数返回原结果；参数或上传内容改变时返回冲突。

提交超时或断流不代表上游未受理。查询原请求或使用原幂等键重放，不能自动更换幂等键重发。上游不支持幂等且受理状态未知的请求不会自动重新提交。

## 工具任务

异步提交返回工具任务 ID。查询、列表、取消和结果接口属于该工具的命名空间。工具任务 ID 不能用于其他工具。商城保留上游业务任务 ID。

执行状态与结算状态分别记录。`pending_confirmation` 表示受理状态待确认；`pending_usage` 表示缺少可信用量；`settlement_failed` 表示结果已确认但结算需要核对。上述状态不能作为重新生成或退款依据。

组合工具保留父子任务。部分模块失败或未提交时，已受理任务继续跟踪，成功结果与相应费用保留，不会自动重新提交整个组合。

## 价格与结算

外部调用沿用用户端有效售价、会员优惠和已实现抵扣规则。费用预估按当前用户和参数计算，实际账单按受理时价格快照及可信用量结算。租户成本不出现在用户估价或账单中。

预占积分尚未确定为最终费用。按实际成功数量、时长或 Token 用量结算后，差额按对应业务规则处理。缺少可信 usage 时不使用本地估算结算。查询、列表、取消和素材操作逐项声明是否收费。

## 历史访问与恢复

租户关闭新增调用或密钥被撤销不终止已受理任务的后台跟踪、结果转存和结算。历史查询须使用当前有效且有权限的密钥。Worker 恢复不依赖用户打开页面或主动查询。

退款、补偿及异常结算以积分流水和消费记录为依据。历史未结算请求不会自动补扣。
','',20,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'open-platform-assets-webhooks',1,'素材和客户回调','开发指南','published','# 素材和客户回调

## 上传素材

上传使用当前密钥，接口为 `POST /api/v1/assets`，请求类型为 `multipart/form-data`。字段 `file` 是二进制文件，`type` 为 `image`、`video`、`audio` 或 `file`。

```bash
curl "$OPEN_BASE_URL/api/v1/assets" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -F "type=image" -F "file=@product.png"
```

响应返回 `file_id`、URL、名称、类型和大小。使用 `GET /api/v1/assets/{file_id}` 查看素材，使用 `GET /api/v1/assets/{file_id}/download` 获取下载地址。工具素材字段可以使用本人上传返回的 `file_id` 或 URL，具体数量与格式以接口契约为准。

素材上传沿用本站存储引擎、目录、域名及大小限制。不能访问其他账户或租户的私有素材。外部素材地址必须满足公网访问与目标地址校验。

## 订阅客户通知

提交时通过请求头设置通知，不要在原协议请求体中加入回调字段：

```http
X-Open-Callback-URL: https://your-service.example/hooks/ai
X-Open-Callback-Secret: YOUR_RANDOM_SECRET_AT_LEAST_32_CHARACTERS
```

回调 URL 使用公网 HTTPS，签名密钥长度为 32 至 256 个字符。本站需已启用客户回调功能。任务本地状态确认后才发送通知。

通知 JSON 包含 `event_id`、`request_id`、`type` 和 `data`；终态事件类型为 `task.completed`。接收端应依据任务和计费状态判断成功或失败。

## 验证签名

请求头包含 `X-Open-Event-Id`、`X-Open-Timestamp` 和 `X-Open-Signature`。签名是 `HMAC-SHA256(secret, timestamp + "." + 原始请求体)` 的十六进制小写字符串。必须使用未经重新序列化的请求体计算，并使用恒定时间比较。

```python
import hashlib, hmac, time

def verify_webhook(headers, raw_body, secret):
    timestamp = headers[''X-Open-Timestamp'']
    if abs(time.time() - int(timestamp)) > 300:
        return False
    expected = hmac.new(secret.encode(), timestamp.encode() + b''.'' + raw_body,
                        hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, headers[''X-Open-Signature''])
```

## 去重与重试

接收端验证签名后按 `event_id` 幂等处理，快速返回 2xx。投递失败后依次间隔 1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时重试。耗尽后进入失败列表，可在控制台查看投递记录并手动重放。重放沿用原事件 ID。
','',30,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
