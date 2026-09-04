# AI短剧前端入口

PC 用户端入口使用 `/ai/short-drama` 和 `/ai/short-drama/plan`。

剧本规划页的附件按钮调用 `script_plan/upload`，支持 TXT、MD、DOCX 文件；上传后返回纯文本，用户可继续编辑后提交 `script_plan/create`。

多集模式支持输入 2-500 集，生成结果按集提供完整剧情、主体、场景和分镜。

旧路径 `/ai/short-drama` 由 PC 全局路由中间件重定向到新路径，兼容历史链接。
