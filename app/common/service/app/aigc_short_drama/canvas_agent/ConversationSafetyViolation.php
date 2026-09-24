<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_short_drama\canvas_agent;

/** A safe, public-facing moderation rejection. Its message deliberately does
 * not disclose a matched tenant rule. */
final class ConversationSafetyViolation extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('CONTENT_BLOCKED');
    }
}
