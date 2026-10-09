<?php
namespace app\common\service\app\aigc_short_drama;

/** A complete provider receipt explicitly reports a length stop, not a transport timeout. */
final class ShortDramaOutputLimitException extends \RuntimeException {}
