# 素材和客户回调

## 上传素材

上传使用当前密钥，接口为 `POST /api/v1/assets`，请求类型为 `multipart/form-data`。字段 `file` 是二进制文件，`type` 为 `image`、`video`、`audio` 或 `file`。

```bash
curl "$OPEN_BASE_URL/api/v1/assets" \
  -H "Authorization: Bearer $OPEN_API_KEY" \
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
    timestamp = headers['X-Open-Timestamp']
    if abs(time.time() - int(timestamp)) > 300:
        return False
    expected = hmac.new(secret.encode(), timestamp.encode() + b'.' + raw_body,
                        hashlib.sha256).hexdigest()
    return hmac.compare_digest(expected, headers['X-Open-Signature'])
```

## 去重与重试

接收端验证签名后按 `event_id` 幂等处理，快速返回 2xx。投递失败后依次间隔 1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时重试。耗尽后进入失败列表，可在控制台查看投递记录并手动重放。重放沿用原事件 ID。
