<?php

namespace app\common\service\app\aigc_llm;

class AigcLlmGenerateRequest
{
    public int $tenantId;
    public int $userId;
    public int $sessionId;
    public string $systemPrompt;
    public string $channelCode;
    public string $modelCode;
    public array $messages;
    public array $modelConfig;
    public array $channelConfig;

    public function __construct(
        int $tenantId,
        int $userId,
        int $sessionId,
        string $systemPrompt = '',
        string $channelCode = 'dashscope_compatible',
        string $modelCode = 'qwen3_6_plus',
        array $messages = [],
        array $modelConfig = [],
        array $channelConfig = []
    ) {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->sessionId = $sessionId;
        $this->systemPrompt = $systemPrompt;
        $this->channelCode = $channelCode;
        $this->modelCode = $modelCode;
        $this->messages = $messages;
        $this->modelConfig = $modelConfig;
        $this->channelConfig = $channelConfig;
    }
}
