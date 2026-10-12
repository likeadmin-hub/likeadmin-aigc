# 图片工具接入

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
curl "$OPEN_BASE_URL/api/v1/tools/aigc_local_redraw/tasks" \
  -H "Authorization: Bearer $OPEN_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: your-stable-image-request-key" \
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
curl "$OPEN_BASE_URL/api/v1/tools/aigc_local_redraw/tasks/$OPEN_TASK_ID" \
  -H "Authorization: Bearer $OPEN_API_KEY"
```

执行状态和结算状态分别记录。预占、实际成功数量及失败处理遵循工具既有规则，最终费用在调用记录和账单中核对。估价之后修改站点配置不会改变这八个工具已受理请求保存的价格、通道与业务参数。

取消操作只有在当前工具明确发布支持时才可调用。遇到提交超时、缺少用量或存储失败，先查询原请求，不要自动更换幂等键重新生成。
