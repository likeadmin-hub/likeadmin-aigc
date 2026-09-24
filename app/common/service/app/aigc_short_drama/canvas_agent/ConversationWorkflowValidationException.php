<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

use RuntimeException;

/** Safe, fixed-category validation failure; never includes model or user text. */
final class ConversationWorkflowValidationException extends RuntimeException
{
    public function __construct(private readonly string $category)
    {
        parent::__construct('INVALID_AGENT_ACTION');
    }

    public function category(): string
    {
        return $this->category;
    }
}
