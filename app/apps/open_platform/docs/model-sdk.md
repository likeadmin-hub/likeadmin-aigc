# 模型与 SDK 接入

先在本站能力目录确认接口已发布、模型可调用，并查看该接口支持的协议。商城协议接口保持已发布上游的方法、路径、参数、状态码、响应字段和流式事件。工具接口使用独立的工具任务协议。

## OpenAI 兼容接口

对明确标注 OpenAI 兼容的聊天接口，将 SDK 的 `base_url` 设置为本站地址加 `/api/v1`，使用本站 API Key。模型编码从当前接口文档或能力目录获取。

```python
import os
from openai import OpenAI

client = OpenAI(
    api_key=os.environ["OPEN_API_KEY"],
    base_url=os.environ["OPEN_BASE_URL"].rstrip("/") + "/api/v1",
    max_retries=0,
)
response = client.chat.completions.create(
    model=os.environ["OPEN_MODEL"],
    messages=[{"role": "user", "content": "Reply OK."}],
    max_tokens=16,
)
print(response.choices[0].message.content)
print(response.usage)
```

```javascript
import OpenAI from "openai";

const client = new OpenAI({
  apiKey: process.env.OPEN_API_KEY,
  baseURL: process.env.OPEN_BASE_URL.replace(/\/$/, "") + "/api/v1",
  maxRetries: 0,
});
const response = await client.chat.completions.create({
  model: process.env.OPEN_MODEL,
  messages: [{ role: "user", content: "Reply OK." }],
  max_tokens: 16,
});
console.log(response.choices[0].message.content);
```

## 流式输出

只有文档声明支持流式及用量事件的接口才可使用下面的参数。流结束后核对最终用量和账单；断流不表示上游未执行。

```python
stream = client.chat.completions.create(
    model=os.environ["OPEN_MODEL"],
    messages=[{"role": "user", "content": "Reply OK."}],
    max_tokens=16,
    stream=True,
    stream_options={"include_usage": True},
)
for event in stream:
    for choice in event.choices:
        print(choice.delta.content or "", end="", flush=True)
    if event.usage is not None:
        print("\nUsage:", event.usage)
```

## 超时与幂等

SDK 自动重试可能重新发送计费请求，建议关闭自动重试。需要重试时，为同一请求固定 `Idempotency-Key`，使用相同参数重放；参数改变则创建新的业务请求。使用 Python SDK 时可传入 `extra_headers={"Idempotency-Key": "your-stable-request-key"}`。

接入本地预览时，系统代理可能代理环回地址。确认请求确实到达本地服务，避免把代理错误当作模型失败。公网接入使用正常的 HTTPS 站点地址。

## 其他协议

Responses、Messages、文件上传和异步任务按照各自已发布文档接入。不要把工具 `POST /tools/{app_code}/tasks` 当作 OpenAI 聊天接口，也不要假定所有商城模型都支持相同 SDK。兼容范围、版本头和可用参数以接口参考页为准。
