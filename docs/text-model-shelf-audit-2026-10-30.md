# 租户1文本模型上架阻塞核查（2026-10-30）

当前本地平台91个启用文本商品，4个已上架、87个未上架。87个未上架商品均存在有效且有价格的SKU；租户1没有相关自定义价格或停用行。阻塞发生于平台SKU的sale_status=0，代码PowerMarketService同步新文本SKU时明确默认下架（opt-in），不是租户价格缺失、余额不足或模型自动故障下架。

用户已明确选择“保持停用，只验收已上架模型”。以下87个商品保持原状态，不纳入本轮模型执行验收；没有修改上架字段、价格或线上数据，不再等待临时上架授权。

|商品ID|模型显示名称|有效SKU|
|---|---|---|
|66|DeepSeek-V4-Flash|1|
|67|Qwen3.7-Max|1|
|68|Qwen3.7-Plus|1|
|69|GLM-5.2|1|
|70|MiniMax-M2.7|1|
|72|Qwen3.6-35B-A3B|1|
|73|Qwen3.6-27B|1|
|74|Qwen3.5-Flash|1|
|75|Qwen3.6-Flash|1|
|76|Hy-MT2-30B-A3B|1|
|77|HY-MT2-7B|1|
|78|MiniMax-M3|1|
|79|MiMo-V2.5-Pro|1|
|80|Kimi-K2.7-Code|1|
|82|LegalOne-8B|1|
|83|Qwen3-Coder-Next|1|
|84|GLM-5|1|
|85|MiniMax-M2.5|1|
|86|Qwen3.5-35B-A3B|1|
|87|Qwen3.5-27B|1|
|88|Qwen3.5-122B-A10B|1|
|90|GLM-5.1|1|
|91|gemma-4-26B-A4B-it|1|
|93|Kimi-K2.6|1|
|95|Qwen3-Coder-30B-A3B-Instruct|1|
|96|ERNIE-5.0-Thinking|1|
|97|DeepSeek-V3.2|1|
|98|AutoGLM-Phone-9B-Multilingual|1|
|99|KAT-Dev|1|
|100|GLM-4.7|1|
|101|MiniMax-M2.1|1|
|102|HY-MT1.5-7B|1|
|103|Sinong1.0-32B|1|
|104|MAI-UI-8B|1|
|105|Kimi-K2.5|1|
|106|PaddleOCR-VL-1.5|1|
|111|Hunyuan-MT-Chimera-7B|1|
|112|Qwen3-Next-80B-A3B-Instruct|1|
|118|Qwen3-VL-30B-A3B-Instruct|1|
|124|ERNIE-4.5-Turbo-VL|1|
|125|ERNIE-4.5-Turbo|1|
|136|QwQ-32B|1|
|137|Fin-R1|1|
|138|GLM-4-32B|1|
|139|DianJin-R1-32B|1|
|140|Qwen3-32B|1|
|142|Qwen2.5-7B-Instruct|1|
|143|DeepSeek-R1-Distill-Qwen-32B|1|
|158|GPT-5.5|1|
|159|GPT-5.4 Mini|1|
|160|GPT-5.4|1|
|177|kimi-k3|1|
|189|Qwen3.6-Plus|1|
|196|Gemini 3 Pro (openai)|1|
|197|Gemini 2.5 Pro (openai)|1|
|198|Claude Opus 4.5|1|
|199|Claude Sonnet 4.5|1|
|200|GPT 5.2|1|
|201|Gemini 3.1 Pro (openai)|1|
|202|Claude Haiku 4.5|1|
|203|Claude Opus 4.6|1|
|204|Claude Sonnet 4.6|1|
|205|GPT 5.5 (response)|1|
|206|Claude Opus 4.7|1|
|207|Claude Opus 4.8|1|
|208|Claude Fable 5|1|
|209|Claude Sonnet 5|1|
|210|Grok 4.5|1|
|211|GPT 5.6 Terra|1|
|212|GPT 5.6 Luna|1|
|213|GPT 5.6 Sol|1|
|214|Claude Opus 5|1|
|215|Grok 4.6|1|
|216|Gemini 3.7 Flash (openai)|1|
|217|Gemini 3.7 Flash|1|
|218|Gemini 3.8 Flash (openai)|1|
|219|Gemini 3.8 Flash|1|
|220|Gpt 6 Astra|1|
|221|Grok 4.7|1|
|222|GPT 6 Sol|1|
|223|GPT 6 Luna|1|
|224|DeepSeek V4.1 Flash|1|
|225|Kimi K3|1|
|226|Claude Opus 5.5|1|
|227|GPT 6.1 Sol|1|
|228|Claude Sonnet 5.5|1|
|229|Claude Haiku 5.5|1|
