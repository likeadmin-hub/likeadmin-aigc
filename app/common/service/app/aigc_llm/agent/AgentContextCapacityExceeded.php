<?php
declare(strict_types=1);
namespace app\common\service\app\aigc_llm\agent;

/** Local context admission failed before this model request; no evidence was discarded. */
final class AgentContextCapacityExceeded extends \InvalidArgumentException {}
