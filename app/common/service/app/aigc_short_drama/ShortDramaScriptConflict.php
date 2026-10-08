<?php
namespace app\common\service\app\aigc_short_drama;

/** Only an explicit semantic conflict may authorize a narrative repair.
 * Provider, receipt and metadata 409 errors must never rewrite a script. */
final class ShortDramaScriptConflict extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('正文与已确认剧情存在明确冲突：' . $reason, 409);
    }
}
