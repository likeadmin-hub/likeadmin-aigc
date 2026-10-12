-- Publish corrected built-in guide only when the previous version is the exact seed.
-- Tenant documents and custom platform versions remain unchanged.
INSERT IGNORE INTO `la_open_platform_document` (`tenant_id`,`slug`,`version`,`title`,`category`,`status`,`content`,`contract_code`,`sort`,`create_time`,`update_time`)
SELECT 0,'open-platform-music-tools',2,'音乐工具接入','开发指南','published','# 音乐工具接入

音乐工具的生成、辅助处理和导出使用当前用户积分账户与本站售价。先确认能力目录中接口已发布、本站应用已开通、密钥权限允许调用；目录中未开放的能力不能通过修改路径调用。

## 文本辅助操作

| 能力 | 已注册路径 | 输入 | 成功结果 |
| --- | --- | --- | --- |
| 作词 | `POST /api/v1/tools/aigc_music/lyrics/index` | `prompt` | `lyrics`、可选 `title` |
| 歌词融合 | `POST /api/v1/tools/aigc_music/mashuplyrics/index` | `lyrics_a`、`lyrics_b` | `lyrics`、可选 `title` |
| 风格增强 | `POST /api/v1/tools/aigc_music/style/index` | `prompt` | `style` |

路径注册不代表本站已经开放。调用前查看该接口的发布状态、价格和具体参数限制。辅助操作按各自价格计费，不沿用整首音乐生成的价格。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/lyrics/index" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-lyrics-request-key" \\
  --data ''{"prompt":"Write four short English lines about sunrise."}''
```

同步完成时返回 `request_id`，以及 `data.task_id`、`data.status=success`、`data.billing_status=settled` 和实际业务结果。暂未完成时保留任务 ID，通过任务查询获取结果，不要自动再次创建。

## 音乐作品和归属

通过已发布的 `GET /api/v1/tools/aigc_music/results` 获取自己的音乐作品列表。后续处理使用该作品的本地数字 `id` 作为 `result_id`。作品 ID、任务 ID 和上传素材的 `file_id` 是不同资源，不可混用，也不可复制其他用户或站点的资源 ID。

生成音乐使用 `POST /api/v1/tools/aigc_music/tasks`，按对应 API 参考页选择模型、通道、歌词、参考素材和规格。声音档案的本地管理不等同于真实声音克隆；实际可调用能力以已发布接口为准。

## 时间轴查询

`POST /api/v1/tools/aigc_music/export/timing` 输入本人 `result_id`，返回 `timing` 中可用的对齐词、分段和波形数据。该操作须配置为免费查询；纯器乐或上游缺少歌词时，对齐结果可能为空。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/export/timing" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-timing-request-key" \\
  --data @music-result-input.json
```

`music-result-input.json` 使用本人作品 ID：

```json
{"result_id": 123}
```

## 导出与人声提取

WAV、MIDI、MP4 和人声提取分别注册在 `POST /api/v1/tools/aigc_music/export/{type}`，输入本人 `result_id`。人声提取可按接口参考页指定 `vocal_start`、`vocal_end`，结束时间必须晚于开始时间。

WAV、MIDI 和人声提取为异步处理；MP4 按当前接口契约处理同步结果，但保存或校验未完成时仍保留本地任务。异步受理成功不代表导出或结算已经完成。价格按对应操作在受理时固定，失败、查询异常及存储失败在任务和账单中分别展示。

完成的文件导出返回 `export_id`、`file_id`、`file_url` 和结果存储信息。服务端验证并保存文件后才结算，损坏文件不会作为成功结果计费。MP4 服务须具备可用的视频校验能力，未配置时不能提交。

人声提取成功返回 `vox_audio_id`，这是已处理的人声引用；它不承诺提供音频下载文件，也不等同于声音克隆。

## 查询、下载与异常处理

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/tasks/$OPEN_TASK_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl --request GET --dump-header - --output /dev/null "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID/download" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

任务和素材查询只允许访问本人资源，查询不重复收取生成或导出费用。下载入口验证归属后重定向到保存的文件地址，临时或私有存储地址按其有效期访问。

保留原始 `request_id`、任务 ID 和幂等键。超时、暂时查询失败、结果保存失败时继续跟踪原任务；同一幂等键与相同参数重放返回原受理响应，最终状态通过任务查询获取。当前音乐辅助操作不支持取消已经提交的上游处理。密钥撤销或本站关闭新增调用后，已受理任务仍由服务端跟踪和结算。
','',50,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
FROM `la_open_platform_document` original
WHERE original.tenant_id=0 AND original.slug='open-platform-music-tools' AND original.version=1 AND original.status='published' AND BINARY original.content=BINARY '# 音乐工具接入

音乐工具的生成、辅助处理和导出使用当前用户积分账户与本站售价。先确认能力目录中接口已发布、本站应用已开通、密钥权限允许调用；目录中未开放的能力不能通过修改路径调用。

## 文本辅助操作

| 能力 | 已注册路径 | 输入 | 成功结果 |
| --- | --- | --- | --- |
| 作词 | `POST /api/v1/tools/aigc_music/lyrics/index` | `prompt` | `lyrics`、可选 `title` |
| 歌词融合 | `POST /api/v1/tools/aigc_music/mashuplyrics/index` | `lyrics_a`、`lyrics_b` | `lyrics`、可选 `title` |
| 风格增强 | `POST /api/v1/tools/aigc_music/style/index` | `prompt` | `style` |

路径注册不代表本站已经开放。调用前查看该接口的发布状态、价格和具体参数限制。辅助操作按各自价格计费，不沿用整首音乐生成的价格。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/lyrics/index" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-lyrics-request-key" \\
  --data ''{"prompt":"Write four short English lines about sunrise."}''
```

同步完成时返回 `request_id`，以及 `data.task_id`、`data.status=success`、`data.billing_status=settled` 和实际业务结果。暂未完成时保留任务 ID，通过任务查询获取结果，不要自动再次创建。

## 音乐作品和归属

通过已发布的 `GET /api/v1/tools/aigc_music/results` 获取自己的音乐作品列表。后续处理使用该作品的本地数字 `id` 作为 `result_id`。作品 ID、任务 ID 和上传素材的 `file_id` 是不同资源，不可混用，也不可复制其他用户或站点的资源 ID。

生成音乐使用 `POST /api/v1/tools/aigc_music/tasks`，按对应 API 参考页选择模型、通道、歌词、参考素材和规格。声音档案的本地管理不等同于真实声音克隆；实际可调用能力以已发布接口为准。

## 时间轴查询

`POST /api/v1/tools/aigc_music/export/timing` 输入本人 `result_id`，返回 `timing` 中可用的对齐词、分段和波形数据。该操作须配置为免费查询；纯器乐或上游缺少歌词时，对齐结果可能为空。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/export/timing" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-timing-request-key" \\
  --data @music-result-input.json
```

`music-result-input.json` 使用本人作品 ID：

```json
{"result_id": 123}
```

## 导出与人声提取

WAV、MIDI、MP4 和人声提取分别注册在 `POST /api/v1/tools/aigc_music/export/{type}`，输入本人 `result_id`。人声提取可按接口参考页指定 `vocal_start`、`vocal_end`，结束时间必须晚于开始时间。

WAV、MIDI 和人声提取为异步处理；MP4 按当前接口契约处理同步结果，但保存或校验未完成时仍保留本地任务。异步受理成功不代表导出或结算已经完成。价格按对应操作在受理时固定，失败、查询异常及存储失败在任务和账单中分别展示。

完成的文件导出返回 `export_id`、`file_id`、`file_url` 和结果存储信息。服务端验证并保存文件后才结算，损坏文件不会作为成功结果计费。MP4 服务须具备可用的视频校验能力，未配置时不能提交。

人声提取成功返回 `vox_audio_id`，这是已处理的人声引用；它不承诺提供音频下载文件，也不等同于声音克隆。

## 查询、下载与异常处理

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/tasks/$OPEN_TASK_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl -I "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID/download" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

任务和素材查询只允许访问本人资源，查询不重复收取生成或导出费用。下载入口验证归属后重定向到保存的文件地址，临时或私有存储地址按其有效期访问。

保留原始 `request_id`、任务 ID 和幂等键。超时、暂时查询失败、结果保存失败时继续跟踪原任务；同一幂等键与相同参数重放返回原受理响应，最终状态通过任务查询获取。当前音乐辅助操作不支持取消已经提交的上游处理。密钥撤销或本站关闭新增调用后，已受理任务仍由服务端跟踪和结算。
'
AND NOT EXISTS (SELECT 1 FROM (SELECT tenant_id,slug,version FROM `la_open_platform_document`) existing WHERE existing.tenant_id=0 AND existing.slug='open-platform-music-tools' AND existing.version>1);
UPDATE `la_open_platform_document` original
SET original.status='archived',original.update_time=UNIX_TIMESTAMP()
WHERE original.tenant_id=0 AND original.slug='open-platform-music-tools' AND original.version=1 AND original.status='published' AND BINARY original.content=BINARY '# 音乐工具接入

音乐工具的生成、辅助处理和导出使用当前用户积分账户与本站售价。先确认能力目录中接口已发布、本站应用已开通、密钥权限允许调用；目录中未开放的能力不能通过修改路径调用。

## 文本辅助操作

| 能力 | 已注册路径 | 输入 | 成功结果 |
| --- | --- | --- | --- |
| 作词 | `POST /api/v1/tools/aigc_music/lyrics/index` | `prompt` | `lyrics`、可选 `title` |
| 歌词融合 | `POST /api/v1/tools/aigc_music/mashuplyrics/index` | `lyrics_a`、`lyrics_b` | `lyrics`、可选 `title` |
| 风格增强 | `POST /api/v1/tools/aigc_music/style/index` | `prompt` | `style` |

路径注册不代表本站已经开放。调用前查看该接口的发布状态、价格和具体参数限制。辅助操作按各自价格计费，不沿用整首音乐生成的价格。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/lyrics/index" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-lyrics-request-key" \\
  --data ''{"prompt":"Write four short English lines about sunrise."}''
```

同步完成时返回 `request_id`，以及 `data.task_id`、`data.status=success`、`data.billing_status=settled` 和实际业务结果。暂未完成时保留任务 ID，通过任务查询获取结果，不要自动再次创建。

## 音乐作品和归属

通过已发布的 `GET /api/v1/tools/aigc_music/results` 获取自己的音乐作品列表。后续处理使用该作品的本地数字 `id` 作为 `result_id`。作品 ID、任务 ID 和上传素材的 `file_id` 是不同资源，不可混用，也不可复制其他用户或站点的资源 ID。

生成音乐使用 `POST /api/v1/tools/aigc_music/tasks`，按对应 API 参考页选择模型、通道、歌词、参考素材和规格。声音档案的本地管理不等同于真实声音克隆；实际可调用能力以已发布接口为准。

## 时间轴查询

`POST /api/v1/tools/aigc_music/export/timing` 输入本人 `result_id`，返回 `timing` 中可用的对齐词、分段和波形数据。该操作须配置为免费查询；纯器乐或上游缺少歌词时，对齐结果可能为空。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/export/timing" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-timing-request-key" \\
  --data @music-result-input.json
```

`music-result-input.json` 使用本人作品 ID：

```json
{"result_id": 123}
```

## 导出与人声提取

WAV、MIDI、MP4 和人声提取分别注册在 `POST /api/v1/tools/aigc_music/export/{type}`，输入本人 `result_id`。人声提取可按接口参考页指定 `vocal_start`、`vocal_end`，结束时间必须晚于开始时间。

WAV、MIDI 和人声提取为异步处理；MP4 按当前接口契约处理同步结果，但保存或校验未完成时仍保留本地任务。异步受理成功不代表导出或结算已经完成。价格按对应操作在受理时固定，失败、查询异常及存储失败在任务和账单中分别展示。

完成的文件导出返回 `export_id`、`file_id`、`file_url` 和结果存储信息。服务端验证并保存文件后才结算，损坏文件不会作为成功结果计费。MP4 服务须具备可用的视频校验能力，未配置时不能提交。

人声提取成功返回 `vox_audio_id`，这是已处理的人声引用；它不承诺提供音频下载文件，也不等同于声音克隆。

## 查询、下载与异常处理

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/tasks/$OPEN_TASK_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl -I "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID/download" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

任务和素材查询只允许访问本人资源，查询不重复收取生成或导出费用。下载入口验证归属后重定向到保存的文件地址，临时或私有存储地址按其有效期访问。

保留原始 `request_id`、任务 ID 和幂等键。超时、暂时查询失败、结果保存失败时继续跟踪原任务；同一幂等键与相同参数重放返回原受理响应，最终状态通过任务查询获取。当前音乐辅助操作不支持取消已经提交的上游处理。密钥撤销或本站关闭新增调用后，已受理任务仍由服务端跟踪和结算。
'
AND EXISTS (SELECT 1 FROM (SELECT tenant_id,slug,version,status,content FROM `la_open_platform_document` LIMIT 18446744073709551615) replacement WHERE replacement.tenant_id=0 AND replacement.slug=original.slug AND replacement.version=2 AND replacement.status='published' AND BINARY replacement.content=BINARY '# 音乐工具接入

音乐工具的生成、辅助处理和导出使用当前用户积分账户与本站售价。先确认能力目录中接口已发布、本站应用已开通、密钥权限允许调用；目录中未开放的能力不能通过修改路径调用。

## 文本辅助操作

| 能力 | 已注册路径 | 输入 | 成功结果 |
| --- | --- | --- | --- |
| 作词 | `POST /api/v1/tools/aigc_music/lyrics/index` | `prompt` | `lyrics`、可选 `title` |
| 歌词融合 | `POST /api/v1/tools/aigc_music/mashuplyrics/index` | `lyrics_a`、`lyrics_b` | `lyrics`、可选 `title` |
| 风格增强 | `POST /api/v1/tools/aigc_music/style/index` | `prompt` | `style` |

路径注册不代表本站已经开放。调用前查看该接口的发布状态、价格和具体参数限制。辅助操作按各自价格计费，不沿用整首音乐生成的价格。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/lyrics/index" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-lyrics-request-key" \\
  --data ''{"prompt":"Write four short English lines about sunrise."}''
```

同步完成时返回 `request_id`，以及 `data.task_id`、`data.status=success`、`data.billing_status=settled` 和实际业务结果。暂未完成时保留任务 ID，通过任务查询获取结果，不要自动再次创建。

## 音乐作品和归属

通过已发布的 `GET /api/v1/tools/aigc_music/results` 获取自己的音乐作品列表。后续处理使用该作品的本地数字 `id` 作为 `result_id`。作品 ID、任务 ID 和上传素材的 `file_id` 是不同资源，不可混用，也不可复制其他用户或站点的资源 ID。

生成音乐使用 `POST /api/v1/tools/aigc_music/tasks`，按对应 API 参考页选择模型、通道、歌词、参考素材和规格。声音档案的本地管理不等同于真实声音克隆；实际可调用能力以已发布接口为准。

## 时间轴查询

`POST /api/v1/tools/aigc_music/export/timing` 输入本人 `result_id`，返回 `timing` 中可用的对齐词、分段和波形数据。该操作须配置为免费查询；纯器乐或上游缺少歌词时，对齐结果可能为空。

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/export/timing" \\
  -H "Authorization: Bearer $OPEN_API_KEY" \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: your-stable-timing-request-key" \\
  --data @music-result-input.json
```

`music-result-input.json` 使用本人作品 ID：

```json
{"result_id": 123}
```

## 导出与人声提取

WAV、MIDI、MP4 和人声提取分别注册在 `POST /api/v1/tools/aigc_music/export/{type}`，输入本人 `result_id`。人声提取可按接口参考页指定 `vocal_start`、`vocal_end`，结束时间必须晚于开始时间。

WAV、MIDI 和人声提取为异步处理；MP4 按当前接口契约处理同步结果，但保存或校验未完成时仍保留本地任务。异步受理成功不代表导出或结算已经完成。价格按对应操作在受理时固定，失败、查询异常及存储失败在任务和账单中分别展示。

完成的文件导出返回 `export_id`、`file_id`、`file_url` 和结果存储信息。服务端验证并保存文件后才结算，损坏文件不会作为成功结果计费。MP4 服务须具备可用的视频校验能力，未配置时不能提交。

人声提取成功返回 `vox_audio_id`，这是已处理的人声引用；它不承诺提供音频下载文件，也不等同于声音克隆。

## 查询、下载与异常处理

```bash
curl "$OPEN_BASE_URL/api/v1/tools/aigc_music/tasks/$OPEN_TASK_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID" \\
  -H "Authorization: Bearer $OPEN_API_KEY"

curl --request GET --dump-header - --output /dev/null "$OPEN_BASE_URL/api/v1/assets/$OPEN_FILE_ID/download" \\
  -H "Authorization: Bearer $OPEN_API_KEY"
```

任务和素材查询只允许访问本人资源，查询不重复收取生成或导出费用。下载入口验证归属后重定向到保存的文件地址，临时或私有存储地址按其有效期访问。

保留原始 `request_id`、任务 ID 和幂等键。超时、暂时查询失败、结果保存失败时继续跟踪原任务；同一幂等键与相同参数重放返回原受理响应，最终状态通过任务查询获取。当前音乐辅助操作不支持取消已经提交的上游处理。密钥撤销或本站关闭新增调用后，已受理任务仍由服务端跟踪和结算。
');
