<?php
namespace app\common\service\app\aigc_short_drama;

/** A completed script needs editorial attention. Never use for provider,
 * settlement, cancellation, dispatch-yield or persistence failures. */
final class ShortDramaReviewDeferred extends \RuntimeException
{
    public function __construct(string $message, public readonly array $issues = [], public readonly ?array $plan = null, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}
