-- Add independent guides without overwriting existing platform or tenant documents.
INSERT IGNORE INTO `la_open_platform_document` (`tenant_id`,`slug`,`version`,`title`,`category`,`status`,`content`,`contract_code`,`sort`,`create_time`,`update_time`) VALUES
(0,'open-platform-model-sdk',1,'模型与 SDK 接入','开发指南','published','# 模型与 SDK 接入

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
  baseURL: process.env.OPEN_BASE_URL.replace(/\\/$/, "") + "/api/v1",
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
        print("\\nUsage:", event.usage)
```

## 超时与幂等

SDK 自动重试可能重新发送计费请求，建议关闭自动重试。需要重试时，为同一请求固定 `Idempotency-Key`，使用相同参数重放；参数改变则创建新的业务请求。使用 Python SDK 时可传入 `extra_headers={"Idempotency-Key": "your-stable-request-key"}`。

接入本地预览时，系统代理可能代理环回地址。确认请求确实到达本地服务，避免把代理错误当作模型失败。公网接入使用正常的 HTTPS 站点地址。

## 其他协议

Responses、Messages、文件上传和异步任务按照各自已发布文档接入。不要把工具 `POST /tools/{app_code}/tasks` 当作 OpenAI 聊天接口，也不要假定所有商城模型都支持相同 SDK。兼容范围、版本头和可用参数以接口参考页为准。
','',30,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),
(0,'open-platform-image-tools',1,'图片工具接入','开发指南','published','# 图片工具接入

图片工具复用用户端的业务服务、会员资格、素材和价格。实际可调用范围由本站开放配置、应用资格、已发布接口和密钥权限共同决定。

## 选择工具和选项

先读取能力目录以及该工具已发布的能力、模型、模板和价格套餐接口。模板、套餐和模型 ID 属于当前站点，不能复制其他站点的 ID。参考图数量、规格和数量限制由所选通道决定。

| 工具编码 | 主要业务输入 |
| --- | --- |
| `aigc_product_image` | `product_image`；模板模式使用 `template_id`，自定义模式使用 `scene_mode=custom` 和 `custom_scene_image` |
| `aigc_style_transfer` | `source_image`、`template_id`，按能力选择 `price_package` |
| `aigc_background_removal` | `source_image`，按能力选择价格套餐 |
| `aigc_outpaint` | `source_image`，选择包含目标尺寸与比例的价格套餐 |
| `aigc_image_translate` | `source_image`、`source_language`、`target_language`，选择价格套餐 |
| `aigc_local_redraw` | `source_image`、`mask_image`、`prompt`，选择通道及规格 |
| `aigc_photo_restore` | `source_image`、`restore_type`，选择修复类型和价格套餐 |
| `aigc_model_wear` | `model_image`、`wear_image`，选择价格套餐 |

此表说明业务输入；必填项、可用参数、套餐编码、默认值及限制以本站已发布 API 参考页为准。接口未发布或通道不接受参考图时，不能提交生成。

## 上传素材

使用 `POST /api/v1/assets` 上传素材，保存返回的 `file_id`。接口文档允许的图片字段可引用本人上传或本人已生成结果的 `file_id`，也可使用符合文档要求的公网素材地址。

私人素材按当前租户和用户校验，字段别名也不能访问其他用户的上传。公共模板通过已发布的模板操作选择；不要把后台模板的存储路径当作自己的上传素材使用。

## 估价和提交

先按生成参数调用该工具已发布的 `POST /api/v1/tools/{app_code}/estimate`，确认当前售价，再提交 `POST /api/v1/tools/{app_code}/tasks`。生成请求沿用当前用户积分账户。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_local_redraw/tasks" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-image-request-key" \\
  --data @local-redraw-input.json
```

`local-redraw-input.json` 使用当前能力目录中的通道和规格，以及本人素材：

```json
{
  "source_image": "YOUR_OWN_IMAGE_FILE_ID",
  "mask_image": "YOUR_OWN_MASK_FILE_ID",
  "prompt": "将蒙版区域的衣服改为蓝色，保留人物面部和背景",
  "channel": "YOUR_AVAILABLE_CHANNEL",
  "quality": "YOUR_AVAILABLE_QUALITY",
  "ratio": "YOUR_AVAILABLE_RATIO"
}
```

局部重绘也支持 PNG、JPEG、WebP、GIF 的 Base64 Data URI 蒙版，解码后大小不超过 20 MiB。服务端在受理前验证格式和素材归属，受理后保存蒙版并关联业务任务。权限、额度和并发拒绝不会提前上传蒙版。

## 查询与复用结果

保存响应中的 `request_id` 与 `data.task_id`。通过对应工具的 `GET /api/v1/tools/{app_code}/tasks/{task_id}` 查询结果，按返回的图片地址获取保存结果；有 `file_id` 的结果可用于下一次工具调用。工具任务 ID 不可跨工具使用。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_local_redraw/tasks/$OPEN_TASK_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

执行状态和结算状态分别记录。预占、实际成功数量及失败处理遵循工具既有规则，最终费用在调用记录和账单中核对。估价之后修改站点配置不会改变这八个工具已受理请求保存的价格、通道与业务参数。

取消操作只有在当前工具明确发布支持时才可调用。遇到提交超时、缺少用量或存储失败，先查询原请求，不要自动更换幂等键重新生成。
','',40,UNIX_TIMESTAMP(),UNIX_TIMESTAMP());
