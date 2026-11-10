# 媒体程序定位与云音频时长

`MediaBinaryService` 统一用于音频时长、市场媒体校验、数字人音频检测和微视频媒体处理。无需依赖登录终端的 shell 配置。

定位顺序：模块显式配置、`FFPROBE_BINARY` / `FFMPEG_BINARY`（也支持 `[FFPROBE] BINARY` / `[FFMPEG] BINARY`）、项目 `runtime/bin`、服务进程 PATH、默认 FFmpeg 真实文件同目录、常用安装路径、宝塔版本目录。默认 FFmpeg 为符号链接时，会跟随链接找到同版本 ffprobe；优先复用客户已安装的程序。

宝塔常见布局：`/usr/bin/ffmpeg -> /www/server/ffmpeg/ffmpeg-6.1/ffmpeg`，即使没有 `/usr/bin/ffprobe`，也可发现同目录的 ffprobe。PHP-FPM 的 PATH 可能不同于 SSH，验收必须使用网站运行用户和实际 PHP-FPM。

音频上传在临时文件移动前测量；已有云音频按文件保存时的存储快照读取，并支持私有存储临时签名。云文件不会被同 key 的本地残留文件覆盖。无法确认时长的输入会被拒绝，客户端 duration 不作为实际测量依据。远程读取最多 50 MiB / 25 秒；本地 ffprobe 有容器/协议白名单、15 秒和输出大小限制。

历史零时长修复（使用站点 PHP 8.2）：

```sh
php scripts/repair-audio-durations.php --tenant=2
php scripts/repair-audio-durations.php --tenant=2 --apply
```

默认只读；省略 tenant 扫描所有租户，每表每次默认 100 条，可用 `--limit=1000` 调整。只处理有效音频资产和音色记录，不更改任务、消费、钱包或已有正数时长。音色支持 audio_uri 和 preview_audio_uri 回退。apply 先在非公开 `runtime/audio-duration-repair/` 保存权限 0600 的快照，再比较原始元数据更新，防止覆盖并发更换的文件。未能测量的记录保持原值，重复执行只处理尚未恢复的记录。
