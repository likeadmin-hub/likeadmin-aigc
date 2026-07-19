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
    public array $tools;
    public $toolChoice;
    public array $responseFormat;
    public bool $stream;

    public function __construct(
        int $tenantId,
        int $userId,
        int $sessionId,
        string $systemPrompt = '',
        string $channelCode = 'dashscope_compatible',
        string $modelCode = 'qwen3_6_plus',
        array $messages = [],
        array $modelConfig = [],
        array $channelConfig = [],
        array $tools = [],
        $toolChoice = null,
        array $responseFormat = [],
        bool $stream = true
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
        $this->tools = $tools;
        $this->toolChoice = $toolChoice;
        $this->responseFormat = $responseFormat;
        $this->stream = $stream;
    }
}
