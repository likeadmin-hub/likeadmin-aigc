<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Safe, fixed-category validation failure; never includes model or user text. */
final class ConversationWorkflowValidationException extends RuntimeException
{
    private string $category;

    public function __construct(string $category)
    {
        parent::__construct('INVALID_AGENT_ACTION');
        $this->category=$category;
    }

    public function category(): string
    {
        return $this->category;
    }
}
