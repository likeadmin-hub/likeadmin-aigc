<?php

namespace app\common\service\app\aigc_canvas\agent\prompt;

/** A user-safe, structured rejection before an image provider is charged. */
final class PromptSubmissionException extends \InvalidArgumentException
{
    private array $diagnostic;

    public function __construct(string $message, array $diagnostic = [])
    {
        parent::__construct($message);
        $this->diagnostic = array_merge([
            'stage' => 'preflight',
            'provider_error_code' => 'prompt_submission_rejected',
            'provider_error_message' => $message,
            'provider_request_id' => '',
            'submitted_prompt_hash' => '',
        ], $diagnostic);
    }

    public function diagnostic(): array
    {
        return $this->diagnostic;
    }
}
