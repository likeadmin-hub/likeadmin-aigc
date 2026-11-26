# 深度复刻处理节点

分析与导出使用项目的 `depth-replication:worker` 队列。运行 PHP worker 的节点需安装 FFmpeg、FFprobe、Python 3.11、Video Depth Anything Small 和本目录的 Python 依赖。默认 CPU 使用 196 像素短边推理，CUDA 使用 322 像素。首版处理 15 秒以内、最多 900 帧的整景视频，输出沿用原片显示尺寸与时间轴。调光基于长边最多 448 像素的工作帧，AAC 等兼容音轨直接复用。

在处理节点部署一次；下面的命令从后端仓库根目录运行。权重与虚拟环境位于应用源码之外。

```sh
install -d /opt/likeadmin-depth
python3.11 -m venv /opt/likeadmin-depth/venv
/opt/likeadmin-depth/venv/bin/pip install -r app/apps/aigc_depth_replication/worker/requirements.txt
git clone https://github.com/DepthAnything/Video-Depth-Anything.git /opt/likeadmin-depth/model
git -C /opt/likeadmin-depth/model checkout --detach 4f5ae23172ba60fd7bc11ef671cca678842c7072
curl --fail --location https://huggingface.co/depth-anything/Video-Depth-Anything-Small/resolve/main/video_depth_anything_vits.pth -o /opt/likeadmin-depth/small.pth
echo '13379300b739e659f076a59d52e9801bd8d38c541a7e71f73bbca4dcfb013609  /opt/likeadmin-depth/small.pth' | sha256sum -c -
```

`DEPTH_MODEL_HOME` 可指定包含 `model/`、`small.pth` 和 `venv/` 的目录，`DEPTH_PYTHON` 可单独指定解释器。模型加载时只读取本地权重。安装应用后由项目的 AI worker 入口启动消费者。PHP-FPM 与消费者应共享 `runtime/depth_replication/` 并具有缓存读权限。例如在 Linux 上预建该目录，指定 PHP-FPM 的组，目录模式设为 `2770`。分析缓存保留七天，导出文件由租户素材存储管理。
